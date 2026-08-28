<?php

namespace App\Health\Check;

use App\Health\Check;
use App\Health\Probe\DbProbe;
use App\Health\Probe\HttpProbe;
use App\Health\Result;

/**
 * Requests the pages that exist on every Omeka S instance.
 *
 * Only pages that need no configuration to find: the four fixed routes, and the home and item
 * browse page of each public site, both derived from the site table. Project-specific pages are
 * deliberately out - discovering them would mean configuration this checker is meant to avoid.
 *
 * The item browse page earns its place by exercising the item adapter, the site theme and
 * pagination together, which is the combination a module upgrade is most likely to break.
 *
 * Response time warns and never fails. It depends on the network and on how much work a page
 * legitimately does, so it is guidance rather than a threshold anyone should gate on.
 */
class PageCheck implements Check
{
    private const ID = 'pages';

    /**
     * Paths every instance has, with the statuses each may legitimately return.
     *
     * /admin is expected to end at the login page once redirects are followed.
     *
     * Investigated 2026-08-28: does Omeka serve HTTP 200 while it needs a migration? Confirmed
     * live: yes, and reproducing that state needs *both* mutations together, not either alone.
     * `setting.version` behind the code by itself self-heals - `MvcListeners::redirectToMigration()`
     * finds `needsVersionUpdate()` true but `needsMigration()` false (it checks the `migration`
     * table, unaffected by the setting), takes its "nothing to do" branch, silently rewrites
     * `setting.version` back to the code version, and serves the page normally. A missing
     * `migration` row by itself is also not enough - the same listener returns immediately at the
     * `needsVersionUpdate()` check before it ever looks at the migration table. Only with the
     * version behind *and* a migration row missing does Omeka actually redirect: 302 to
     * `/maintenance` for non-admin routes, to `/migrate` for admin routes, and every one of `/`,
     * `/login`, `/admin` and `/s/<slug>` was observed to resolve (after HttpProbe follows the
     * redirect) to HTTP 200 serving the maintenance page. A status-only check would pass a fully
     * dead instance on every path, so checkPage() looks at where the request ended up.
     */
    private const FIXED_PATHS = [
        '/' => [200],
        '/login' => [200],
        '/admin' => [200],
        '/api' => [200],
    ];

    /**
     * The routes Omeka S diverts to when it will not serve the instance.
     *
     * `MvcListeners::redirectToMigration()` sends non-admin routes to /maintenance and admin routes
     * to /migrate; the administrator-facing maintenance mode setting uses the same /maintenance
     * route. Landing on either is the signal, whichever of those put us there.
     */
    private const DIVERTED_PATHS = ['/maintenance', '/migrate'];

    public function __construct(
        private ?HttpProbe $httpProbe,
        private ?DbProbe $dbProbe,
        private int $slowMs,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Critical pages';
    }

    public function requires(): array
    {
        return ['http'];
    }

    public function run(): array
    {
        $results = [];

        foreach (self::FIXED_PATHS as $path => $expected) {
            $results[] = $this->checkPage($path, $expected);
        }

        if ($this->dbProbe === null) {
            $results[] = Result::info(self::ID, 'Site pages: not checked, no database to read the site list from');
            return $results;
        }

        $slugs = $this->dbProbe->publicSiteSlugs();
        if ($slugs === []) {
            $results[] = Result::info(self::ID, 'Site pages: no public sites');
            return $results;
        }

        foreach ($slugs as $slug) {
            $results[] = $this->checkPage('/s/' . $slug, [200]);
            $results[] = $this->checkPage('/s/' . $slug . '/item', [200]);
        }

        return $results;
    }

    /**
     * @param int[] $expected The statuses that count as healthy for this path.
     */
    private function checkPage(string $path, array $expected): Result
    {
        $result = $this->httpProbe->get($path);

        if ($result->isTransportError()) {
            return Result::fail(self::ID, sprintf('Page %s: unreachable', $path), [
                (string) $result->error,
            ], $result->elapsedMs);
        }

        if (!in_array($result->statusCode, $expected, true)) {
            return Result::fail(
                self::ID,
                sprintf('Page %s: HTTP %d', $path, $result->statusCode),
                [sprintf('Expected %s.', implode(' or ', $expected))],
                $result->elapsedMs
            );
        }

        // Confirmed live 2026-08-28: with setting.version behind the code *and* a migration row
        // missing (either alone is insufficient - the version-only case self-heals, see the
        // FIXED_PATHS doc comment above), Omeka 302s every route to /maintenance or /migrate and
        // then serves that page at HTTP 200. Where the request ended up is the primary test, for
        // two reasons. It is locale-independent: both view scripts wrap their text in $translate(),
        // so a body-string marker recognises an English instance and silently degrades to a
        // status-only check - which is worthless here - on any other. And it is immune to page
        // content: a home page carrying an announcement about scheduled maintenance would otherwise
        // fail every route on this instance.
        if ($this->isDiverted($result->finalPath())) {
            return $this->divertedFailure($path, $result->finalPath(), $result->elapsedMs);
        }

        // Secondary signal only, for the hypothetical configuration that renders the maintenance
        // page in place instead of redirecting to it. The phrase is verbatim in both
        // application/view/omeka/maintenance/index.phtml and .../migrate/index.phtml, in English.
        if ($result->body !== null && stripos($result->body, 'down for maintenance') !== false) {
            return $this->divertedFailure($path, null, $result->elapsedMs);
        }

        if ($result->elapsedMs > $this->slowMs) {
            return Result::warn(
                self::ID,
                sprintf('Page %s: %d, slower than %dms', $path, $result->statusCode, $this->slowMs),
                ['Guidance only - this depends on the network and on how much work the page does.'],
                $result->elapsedMs
            );
        }

        return Result::pass(self::ID, sprintf('Page %s: %d', $path, $result->statusCode), [], $result->elapsedMs);
    }

    /**
     * Whether a request ended on one of Omeka's diverted routes.
     *
     * Null means no redirect was followed, which is the healthy case for every path here except
     * /admin, whose redirect ends at /login.
     *
     * The suffix test carries instances served from a subdirectory, where the redirect target is
     * /<base>/maintenance rather than /maintenance.
     */
    private function isDiverted(?string $finalPath): bool
    {
        if ($finalPath === null) {
            return false;
        }
        $normalised = rtrim($finalPath, '/');
        foreach (self::DIVERTED_PATHS as $route) {
            if ($normalised === $route || str_ends_with($normalised, $route)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The failure for a page that served the maintenance or upgrade page instead of the site.
     *
     * Deliberately cause-neutral. The same route is served for a pending migration and for an
     * administrator-enabled maintenance mode, and nothing observable from outside tells the two
     * apart, so the message reports what happened and offers both explanations.
     *
     * @param string|null $finalPath Where the request was redirected to, when it was redirected.
     */
    private function divertedFailure(string $path, ?string $finalPath, int $elapsedMs): Result
    {
        $detail = $finalPath === null
            ? ['The page body is the maintenance or upgrade page.']
            : [sprintf('Redirected to %s.', $finalPath)];
        $detail[] = 'Omeka S returns HTTP 200 in this state, so the status code alone does not catch it.';
        $detail[] = 'Either the database needs migrating (run "php console update:db") or the site is in maintenance mode.';

        return Result::fail(
            self::ID,
            sprintf('Page %s: serving a maintenance or upgrade page, not the site', $path),
            $detail,
            $elapsedMs
        );
    }
}
