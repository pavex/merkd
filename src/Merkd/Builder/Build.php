<?php

/**
 * Central entry point — orchestrates the full build pipeline.
 *
 * Every .md file is always fully parsed and upserted — no hash-based skip for documents.
 * Asset hash check is retained (ImageProcessor is expensive).
 *
 * Build modes:
 *   Normal / --force:
 *     - marks all documents is_deleted = 1 at start
 *     - parses and upserts every document (sets is_deleted = 0)
 *     - assets: hash check, restores is_deleted = 0 if unchanged, reprocesses if changed
 *     - at end: marks orphan assets is_deleted = 1
 *     (--force is now equivalent to normal — retained for CLI compatibility)
 *
 *   --reset:
 *     - wipes public asset directory contents (dir preserved — permissions kept)
 *     - truncates DB tables
 *     - then runs full build
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder
 */

namespace Merkd\Builder;

use Merkd\Builder\Result\BuildResult;
use Nette\IOException;
use Nette\Utils\FileSystem;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;


final class Build extends AbstractBuilder
{

    private BuildContainer $container;


    public function __construct(BuildContainer $container)
    {
        $this->container = $container;
    }


    public function run(bool $reset = false): BuildResult
    {
        $buildResult = new BuildResult();
        $config = $this->container->getConfig();

        $this->log('Build:');
        $this->log('  mode:       ' . ($reset ? 'Reset' : 'Normal'));
        $this->log('  db:         ' . $config->db_path);
        $this->log('  content:    ' . $config->content_dir);
        $this->log('  asset_dir:  ' . $config->asset_dir);
        $this->log('  public_dir: ' . $config->public_dir);
        $this->log('  base_url:   ' . $config->getBaseUrl());

        if ($config->isBaseUrlDerived()) {
            $this->log('-> warning: base_url not set in config.php — derived as "' . $config->getBaseUrl() . '".');
        }

        if ($reset) {
            if ($config->isAssetDirSafe()) {
                $this->log("\nReset: clearing public asset directory contents...");
                $this->clearDirContents($config->getPublicAssetDir());
                $this->log('-> done.');
            }
            else {
                $this->log("\nReset: -> warning: asset_dir is empty — skipping directory wipe.");
            }

            $this->log('Reset: truncating DB...');
            $this->container->getBuildDatastore()->truncate();
            $this->container->getAssetDatastore()->truncate();
            $this->log('-> done.');
        }

        if (!extension_loaded('gd')) {
            $this->log('-> warning: GD extension not loaded — images skipped.');
        }
        elseif (!$config->isAvifSupported()) {
            $this->log('-> warning: GD compiled without libavif — only JPG variants will be generated.');
        }

        try {
            FileSystem::createDir($config->getPublicAssetDir());
        }
        catch (IOException $e) {
            $this->log('ERROR: ' . $e->getMessage());
            return $buildResult;
        }

        // Mark all documents as deleted — build will restore processed ones to is_deleted = 0.
        $this->container->getBuildDatastore()->markAllDeleted();

        $files = $this->collectFiles($config->content_dir);

        if (empty($files)) {
            $this->log("\nNo .md files found in: " . $config->content_dir);
            return $buildResult;
        }

        $this->log("\nBuild content:");

        $contentBuilder = $this->container->getContentBuilder();
        $contentBuilder->setOutput($this->getOutputCallback());
        $contentBuilder->reset();

        foreach ($files as $file_path) {
            $buildResult->merge($contentBuilder->build($file_path));
        }

        // Mark assets that have no active document reference as deleted.
        $this->container->getAssetDatastore()->markOrphanAssetsDeleted();

        $this->log(sprintf("\nDone — added: %d, updated: %d, skipped: %d",
            $buildResult->added, $buildResult->updated, $buildResult->skipped
        ));

        return $buildResult;
    }


    /**
     * Removes all contents of a directory but keeps the directory itself.
     * The directory is preserved — filesystem permissions are not lost on --reset.
     */
    private function clearDirContents(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                @unlink($item->getRealPath());
            }
            elseif ($item->isDir()) {
                @rmdir($item->getRealPath());
            }
        }
    }


    private function collectFiles(string $content_dir): array
    {
        $content_dir = rtrim($content_dir, '/\\');
        $files = [];

        if (!is_dir($content_dir)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($content_dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'md') {
                $files[] = $file->getRealPath();
            }
        }

        sort($files);
        return $files;
    }


}
