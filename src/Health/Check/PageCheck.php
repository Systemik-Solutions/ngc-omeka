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
     * redirect) to HTTP 200 serving the maintenance page - `<title>My Omeka S Site</title>`,
     * `<h2>Site under maintenance</h2>`. A status-only check would pass a fully dead instance on
     * every path. The phrase "down for maintenance" is verbatim in both
     * `omeka/maintenance/index.phtml` ("This site is down for maintenance...") and
     * `omeka/migrate/index.phtml` ("...down for maintenance until you click the button below."),
     * so checkPage() matches it in the body to catch what the status code alone would miss.
     */
    private const FIXED_PATHS = [
        '/' => [200],
        '/login' => [200],
        '/admin' => [200],
        '/api' => [200],
    ];

    public function __construct(
        private HttpProbe $httpProbe,
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
        // FIXED_PATHS doc comment above), Omeka serves its maintenance page at HTTP 200 for /,
        // /login, /admin and every site route alike. The phrase below is verbatim in both
        // application/view/omeka/maintenance/index.phtml and .../migrate/index.phtml, so it
        // catches both the public maintenance redirect and the admin migrate redirect. It cannot
        // distinguish a pending migration from an administrator deliberately enabling maintenance
        // mode - both render the same page - so the message reports what was observed rather than
        // asserting a cause it cannot tell apart.
        if ($result->body !== null && stripos($result->body, 'down for maintenance') !== false) {
            return Result::fail(
                self::ID,
                sprintf('Page %s: serving a maintenance or upgrade page, not the site', $path),
                [
                    'Omeka S returns HTTP 200 in this state, so the status code alone does not catch it.',
                    'Either the database needs migrating (run "php console update:db") or the site is in maintenance mode.',
                ],
                $result->elapsedMs
            );
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
}
