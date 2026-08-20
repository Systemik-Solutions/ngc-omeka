<?php

namespace App\Distribution;

use App\Helper;
use GuzzleHttp\Client;

/**
 * Downloads and unpacks distribution code.
 *
 * This class is filesystem and HTTP only. It must never touch App\Omeka or any Omeka S class: it
 * runs while the code on disk is being replaced, and PHP cannot reload a class once it is declared,
 * so anything loaded here would keep running for the rest of the process. Callers wrap it in
 * Omeka::lockBootstrap() to make an accidental bootstrap fail loudly.
 *
 * Every method throws on failure and leaves the installation as close to its previous state as it
 * can, so a caller can report one component and carry on with the next.
 */
class CodeUpdater
{
    /**
     * Directories inside public/ that a core update leaves alone.
     *
     * These hold the installation's own state - its configuration, uploaded files, logs, and the
     * modules and themes that are updated separately - rather than core code.
     */
    private const CORE_KEEP_DIRS = ['config', 'files', 'modules', 'themes', 'logs'];

    private string $publicDir;

    private Client $client;

    /** @var callable|null */
    private $log;

    /**
     * @param string $rootDir The repository root.
     * @param callable|null $log Optional progress reporter, called with a single message string.
     */
    public function __construct(string $rootDir, ?callable $log = null)
    {
        $this->publicDir = $rootDir . '/public';
        $this->client = new Client();
        $this->log = $log;
    }

    /**
     * Download and unpack the Omeka S core.
     *
     * Everything in public/ except the directories in CORE_KEEP_DIRS is removed and replaced. There
     * is deliberately no backup of the replaced code, so unlike a module or a theme this cannot be
     * rolled back if the extraction fails. The commands warn about that before they call here.
     *
     * @param array $coreInfo The manifest core entry, with "url" and "version".
     *
     * @throws \RuntimeException if the download or the extraction fails.
     */
    public function updateCore(array $coreInfo): void
    {
        $version = $coreInfo['version'] ?? 'unknown';
        $this->report("Downloading Omeka S {$version}...");
        $tmpZip = $this->download($coreInfo['url'], 'omeka_core_');

        $this->report('Extracting the package...');

        // Clear out the old core.
        foreach (scandir($this->publicDir) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, self::CORE_KEEP_DIRS)) {
                continue;
            }
            $fullPath = $this->publicDir . '/' . $item;
            if (is_dir($fullPath)) {
                Helper::rrmdir($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        // The release zip wraps everything in a single top-level directory, so unpack it to a staging
        // directory and move the contents up rather than extracting in place.
        $tempDir = $this->publicDir . '/update_temp';
        if (is_dir($tempDir)) {
            Helper::rrmdir($tempDir);
        }
        try {
            Helper::extractZipTopLevelDir($tmpZip, $tempDir);
        } catch (\Exception $e) {
            unlink($tmpZip);
            if (is_dir($tempDir)) {
                Helper::rrmdir($tempDir);
            }
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        foreach (scandir($tempDir) as $item) {
            if ($item === '.' || $item === '..' || in_array($item, self::CORE_KEEP_DIRS)) {
                continue;
            }
            rename($tempDir . '/' . $item, $this->publicDir . '/' . $item);
        }

        unlink($tmpZip);
        Helper::rrmdir($tempDir);

        $this->report('Core update has been unpacked successfully.');
    }

    /**
     * Download and unpack a module.
     *
     * @param string $id The module ID, i.e. its directory name.
     * @param array $moduleInfo The manifest module entry, with "url" and "version".
     *
     * @throws \RuntimeException if the download or the extraction fails.
     */
    public function updateModule(string $id, array $moduleInfo): void
    {
        $this->updatePackage('module', $id, $moduleInfo, $this->publicDir . '/modules');
    }

    /**
     * Download and unpack a theme.
     *
     * @param string $id The theme ID, i.e. its directory name.
     * @param array $themeInfo The manifest theme entry, with "url" and "version".
     *
     * @throws \RuntimeException if the download or the extraction fails.
     */
    public function updateTheme(string $id, array $themeInfo): void
    {
        $this->updatePackage('theme', $id, $themeInfo, $this->publicDir . '/themes');
    }

    /**
     * Download and unpack a module or a theme.
     *
     * Modules and themes differ only in which directory they live in: both ship as a zip containing a
     * single directory named after the component. The existing directory is renamed aside first and
     * restored if the extraction fails, so a bad zip leaves the component as it was.
     *
     * @param string $label What to call the component in messages.
     * @param string $id The component ID, i.e. its directory name.
     * @param array $info The manifest entry, with "url" and "version".
     * @param string $parentDir The directory the component lives in.
     *
     * @throws \RuntimeException if the download or the extraction fails.
     */
    private function updatePackage(string $label, string $id, array $info, string $parentDir): void
    {
        $version = $info['version'] ?? 'unknown';
        $this->report("Downloading {$label}: {$id}({$version})...");
        $tmpZip = $this->download($info['url'], 'omeka_' . $label . '_');

        $this->report("Extracting {$label} {$id}...");
        $targetDir = $parentDir . '/' . $id;
        $backupDir = null;
        if (is_dir($targetDir)) {
            $backupDir = $targetDir . '_bkp';
            if (is_dir($backupDir)) {
                Helper::rrmdir($backupDir);
            }
            rename($targetDir, $backupDir);
        }

        try {
            Helper::extractZip($tmpZip, $parentDir);
        } catch (\Exception $e) {
            unlink($tmpZip);
            // Roll back to whatever was there before.
            if (is_dir($targetDir)) {
                Helper::rrmdir($targetDir);
            }
            if ($backupDir !== null && is_dir($backupDir)) {
                rename($backupDir, $targetDir);
            }
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        unlink($tmpZip);
        if ($backupDir !== null && is_dir($backupDir)) {
            Helper::rrmdir($backupDir);
        }

        $this->report(ucfirst($label) . " {$id} update has been unpacked successfully.");
    }

    /**
     * Download a zip to a temporary file.
     *
     * @return string Path to the downloaded file.
     *
     * @throws \RuntimeException if the download fails.
     */
    private function download(string $url, string $prefix): string
    {
        $tmpZip = tempnam(sys_get_temp_dir(), $prefix) . '.zip';
        try {
            $this->client->request('GET', $url, ['sink' => $tmpZip]);
        } catch (\Exception $e) {
            if (file_exists($tmpZip)) {
                unlink($tmpZip);
            }
            throw new \RuntimeException('Download failed: ' . $e->getMessage(), 0, $e);
        }
        return $tmpZip;
    }

    /**
     * Report progress to the caller, if it asked for it.
     */
    private function report(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }
}
