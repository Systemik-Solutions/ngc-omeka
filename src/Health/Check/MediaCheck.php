<?php

namespace App\Health\Check;

use App\Health\Check;
use App\Health\Probe\DbProbe;
use App\Health\Probe\HttpProbe;
use App\Health\Result;

/**
 * Checks that stored files and their derivatives are actually being served.
 *
 * Paths are derived rather than configured: Omeka S stores originals at
 * files/original/<storage_id>.<extension> and the three derivatives at files/<type>/<storage_id>.jpg,
 * which is what the thumbnail_display_urls in an API payload resolve to.
 *
 * Requests are HEAD, so size has to come from the Content-Length header. A HEAD response has no
 * body, and measuring one would report zero bytes for every healthy file.
 */
class MediaCheck implements Check
{
    private const ID = 'media';

    private const DERIVATIVES = ['large', 'medium', 'square'];

    public function __construct(
        private ?DbProbe $dbProbe,
        private ?HttpProbe $httpProbe,
        private int $samples,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Media files';
    }

    public function requires(): array
    {
        return ['db', 'http'];
    }

    public function run(): array
    {
        $media = $this->dbProbe->sampleMedia($this->samples);

        // INFO, not SKIP. SKIP means a required probe was not configured; both probes are configured
        // here, the check ran, and it found the instance has no files. Reporting instance state as
        // SKIP would give that status two meanings and make the documented one untrue.
        if ($media === []) {
            return [Result::info(self::ID, 'Media files: no stored files to sample', [
                'The media table has no rows with a storage_id.',
            ])];
        }

        $checked = 0;
        $missing = [];
        $empty = [];

        foreach ($media as $row) {
            foreach ($this->pathsFor($row) as $path) {
                $checked++;
                $result = $this->httpProbe->head($path);

                if ($result->isTransportError()) {
                    $missing[] = sprintf('media %d: %s (%s)', $row['id'], $path, (string) $result->error);
                    continue;
                }
                if ($result->statusCode !== 200) {
                    $missing[] = sprintf('media %d: %s returned HTTP %d', $row['id'], $path, $result->statusCode);
                    continue;
                }

                $length = $result->contentLength();
                if ($length === null || $length === 0) {
                    $empty[] = sprintf('media %d: %s returned 200 with no content length', $row['id'], $path);
                }
            }
        }

        $results = [];

        if ($missing !== []) {
            $results[] = Result::fail(
                self::ID,
                sprintf('Media files: %d of %d sampled file(s) are not being served', count($missing), $checked),
                $missing
            );
        } else {
            $results[] = Result::pass(
                self::ID,
                sprintf('Media files: %d file(s) across %d media record(s) reachable', $checked, count($media))
            );
        }

        if ($empty !== []) {
            $results[] = Result::warn(
                self::ID,
                sprintf('Media files: %d file(s) responded 200 but declared no size', count($empty)),
                $empty
            );
        }

        return $results;
    }

    /**
     * The URLs Omeka S serves a media record's files from.
     *
     * @param array{id: int, storage_id: string, extension: ?string, has_original: bool, has_thumbnails: bool} $row
     * @return string[]
     */
    private function pathsFor(array $row): array
    {
        $paths = [];

        if ($row['has_original']) {
            $name = $row['storage_id'] . ($row['extension'] !== null && $row['extension'] !== '' ? '.' . $row['extension'] : '');
            $paths[] = '/files/original/' . $name;
        }

        if ($row['has_thumbnails']) {
            foreach (self::DERIVATIVES as $type) {
                $paths[] = '/files/' . $type . '/' . $row['storage_id'] . '.jpg';
            }
        }

        return $paths;
    }
}
