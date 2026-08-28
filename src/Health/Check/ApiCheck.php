<?php

namespace App\Health\Check;

use App\Distribution\Inspector;
use App\Health\Check;
use App\Health\Probe\DbProbe;
use App\Health\Probe\HttpProbe;
use App\Health\Probe\HttpResult;
use App\Health\Result;

/**
 * Checks that the API answers, and that what it says matches the instance.
 *
 * Two of these assertions are free, because Omeka S puts the answers in response headers.
 * Omeka-S-Version reports the core version of the code the *web server* is running, which is not
 * necessarily the code the CLI just updated - a stale opcache, a wrong document root or an update
 * applied to the wrong tree all show up here and nowhere else. Omeka-S-Total-Results reports how
 * many items the API can see.
 *
 * The item count is compared against public items only. An anonymous API request sees nothing else,
 * so comparing it against the total would fail on every instance that has a private item.
 */
class ApiCheck implements Check
{
    private const ID = 'api';

    public function __construct(
        private ?HttpProbe $httpProbe,
        private Inspector $inspector,
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
        return 'API';
    }

    public function requires(): array
    {
        return ['http'];
    }

    public function run(): array
    {
        $results = [];

        $items = $this->httpProbe->get('/api/items?limit=1');
        $results[] = $this->checkEndpoint($items, '/api/items');

        if ($items->isTransportError() || $items->statusCode !== 200) {
            // Nothing below can be trusted if the endpoint did not answer.
            return $results;
        }

        $results[] = $this->checkVersionHeader($items);

        $countResult = $this->checkItemCount($items);
        if ($countResult !== null) {
            $results[] = $countResult;
        }

        $templates = $this->httpProbe->get('/api/resource_templates?limit=1');
        $results[] = $this->checkEndpoint($templates, '/api/resource_templates');

        return $results;
    }

    private function checkEndpoint(HttpResult $result, string $label): Result
    {
        if ($result->isTransportError()) {
            return Result::fail(self::ID, sprintf('API %s: unreachable', $label), [
                (string) $result->error,
            ], $result->elapsedMs);
        }

        if ($result->statusCode !== 200) {
            return Result::fail(
                self::ID,
                sprintf('API %s: HTTP %d', $label, $result->statusCode),
                ['Expected 200.'],
                $result->elapsedMs
            );
        }

        $decoded = json_decode((string) $result->body, true);
        if (!is_array($decoded)) {
            return Result::fail(
                self::ID,
                sprintf('API %s: the response is not a JSON array', $label),
                ['Content-Type: ' . ($result->contentType() ?? 'none')],
                $result->elapsedMs
            );
        }

        if ($result->elapsedMs > $this->slowMs) {
            return Result::warn(
                self::ID,
                sprintf('API %s: 200, slower than %dms', $label, $this->slowMs),
                [],
                $result->elapsedMs
            );
        }

        return Result::pass(self::ID, sprintf('API %s: 200', $label), [], $result->elapsedMs);
    }

    private function checkVersionHeader(HttpResult $result): Result
    {
        $served = $result->header('Omeka-S-Version');
        $onDisk = $this->inspector->getCoreVersion();

        if ($served === null) {
            return Result::warn(self::ID, 'API version header: not present in the response', [
                'Expected an Omeka-S-Version header.',
            ]);
        }

        if ($onDisk === null) {
            return Result::info(self::ID, sprintf('API version header: %s (no core on disk to compare)', $served));
        }

        if ($served !== $onDisk) {
            return Result::fail(
                self::ID,
                sprintf('API version header: the server is running %s but the code on disk is %s', $served, $onDisk),
                [
                    'The web server is not serving the code this command just inspected.',
                    'Check the document root, and restart PHP-FPM or Apache to clear a stale opcache.',
                ]
            );
        }

        return Result::pass(self::ID, sprintf('API version header: %s, matches the code on disk', $served));
    }

    private function checkItemCount(HttpResult $result): ?Result
    {
        $header = $result->header('Omeka-S-Total-Results');
        if ($header === null) {
            return Result::warn(self::ID, 'API item count: no Omeka-S-Total-Results header in the response');
        }

        if ($this->dbProbe === null) {
            return Result::info(self::ID, sprintf('API item count: %s (no database to compare against)', $header));
        }

        $reported = (int) $header;
        $expected = $this->dbProbe->publicItemCount();

        if ($reported !== $expected) {
            return Result::fail(
                self::ID,
                sprintf('API item count: the API reports %d public item(s), the database has %d', $reported, $expected),
                ['The API and the database disagree about the same data.']
            );
        }

        return Result::pass(self::ID, sprintf('API item count: %d public item(s), matches the database', $reported));
    }
}
