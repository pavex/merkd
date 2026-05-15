<?php

/**
 * Processes a single .md file — parses content and persists to DB.
 *
 * Owns the asset callback: for every local image encountered during parsing,
 * it ensures the document stub exists in DB first (FK), processes the asset,
 * then links document→asset — satisfying both FK constraints on document_assets.
 *
 * Image paths in MD are relative to the MD file location.
 * Generated variants are written to public_dir/asset_dir/ + relative_url.
 *
 * Build::run() collects files and calls build() per document.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Content
 */

namespace Merkd\Builder\Content;

use Merkd\Builder\AbstractBuilder;
use Merkd\Builder\Config;
use Merkd\Builder\Datastore\AssetDatastoreInterface;
use Merkd\Builder\Datastore\BuildDatastoreInterface;
use Merkd\Builder\Datastore\Dataset\SourceDataset;
use Merkd\Builder\Image\ImageProcessor;
use Merkd\Builder\Image\ImageResult;
use Merkd\Builder\Result\BuildResult;
use Merkd\Datastore\Dataset\AssetDataset;
use Merkd\Record\AssetRecord;


final class ContentBuilder extends AbstractBuilder
{

    private Config $config;
    private BuildDatastoreInterface $buildDatastore;
    private AssetDatastoreInterface $assetDatastore;

    /** @var array<string, true> Asset paths processed in the current build run (shared across documents). */
    private array $processedAssets = [];


    public function __construct(
        BuildDatastoreInterface $buildDatastore,
        AssetDatastoreInterface $assetDatastore,
        Config $config
    ) {
        $this->config = $config;
        $this->buildDatastore = $buildDatastore;
        $this->assetDatastore = $assetDatastore;
    }


    /** Resets per-run state — called by Build::run() at the start of each build. */
    public function reset(): void
    {
        $this->processedAssets = [];
    }


    /**
     * Parses and persists a single .md file.
     *
     * @param bool $force When true, skips hash comparison.
     */
    public function build(string $file_path, bool $force = false): BuildResult
    {
        $buildResult = new BuildResult();

        // Asset callback captures the MD file's directory — images are resolved relative to it.
        $md_dir = dirname($file_path);

        $parser = new FileParser($this->config);
        $parser->setDefaultLang($this->config->default_lang);
        $parser->setAssetCallback(fn(string $src, string $slug, string $lang) =>
            $this->assetCallback($src, $slug, $lang, $md_dir)
        );

        $source = $parser->parse($file_path);

        if ($source === null) {
            $this->log('-> error: ' . basename($file_path) . ' (unreadable)');
            $buildResult->skipped++;
            return $buildResult;
        }

        $existing = $this->buildDatastore->findBySlugLang($source->slug, $source->lang);

        if (!$force && $existing !== null && $existing->hash === $source->hash) {
            $this->log('-> skipped: ' . $source->slug . ' [' . $source->lang . '] (unchanged)');
            $buildResult->skipped++;
            return $buildResult;
        }

        $dataset = SourceDataset::fromRecord($source);

        if ($existing !== null) {
            $this->buildDatastore->update($dataset);
            $this->log('-> updated: ' . $source->slug . ' [' . $source->lang . ']');
            $buildResult->updated++;
        }
        else {
            $this->buildDatastore->insert($dataset);
            $this->log('-> added: ' . $source->slug . ' [' . $source->lang . ']');
            $buildResult->added++;
        }

        return $buildResult;
    }


    /**
     * Asset callback — invoked by FileParser for every local image src during parsing.
     *
     * Order enforced to satisfy document_assets FK constraints:
     *   1. insertStub()                  — document row exists (FK → documents)
     *   2. insert/update asset           — asset row exists   (FK → assets)
     *   3. insertDocumentAssetBinding()  — both FKs satisfied
     *
     * Source file is resolved relative to the MD file directory ($md_dir).
     * Output is written to public_dir/asset_dir/ preserving the relative_url structure.
     *
     * @return ImageResult|null ImageResult on success (used by MerkdParsedown for <picture>), null on error.
     */
    private function assetCallback(string $src, string $slug, string $lang, string $md_dir): ?ImageResult
    {
        $relative_path = ltrim($src, '/\\');
        $relativeUrl = str_replace('\\', '/', $relative_path);

        // 1. Ensure document stub exists (FK on document_assets → documents).
        $this->buildDatastore->insertStub($slug, $lang);

        // Deduplicate — if already processed this run, asset is in DB, safe to link.
        if (isset($this->processedAssets[$relative_path])) {
            $existing = $this->assetDatastore->findByRelativeUrl($relativeUrl);
            if ($existing !== null) {
                $this->assetDatastore->insertDocumentAssetBinding($slug, $lang, $relativeUrl);
                return $this->imageResultFromAsset($existing);
            }
            return null;
        }
        $this->processedAssets[$relative_path] = true;

        // Resolve source file relative to the MD file's directory.
        $filePath = $md_dir . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);

        if (!is_file($filePath)) {
            $this->log('-> error: ' . $relative_path . ' (not found relative to ' . $md_dir . ')');
            return null;
        }

        $existing = $this->assetDatastore->findByRelativeUrl($relativeUrl);
        $hash = (int) hexdec(hash_file('crc32b', $filePath));

        if ($existing !== null && $existing->hash === $hash) {
            $this->log('-> skipped asset: ' . $relative_path . ' (unchanged)');
            // 2+3. Asset already in DB — link.
            $this->assetDatastore->insertDocumentAssetBinding($slug, $lang, $relativeUrl);
            return $this->imageResultFromAsset($existing);
        }

        // 2. Process and persist asset before linking.
        $imageResult = $this->processImage($filePath, $relative_path);

        if ($imageResult === null) {
            return null;
        }

        $assetRecord = $this->assetFromResult($imageResult, $relativeUrl);

        if ($existing !== null) {
            $this->assetDatastore->update($assetRecord);
            $this->log('-> updated asset: ' . $relative_path);
        }
        else {
            $this->assetDatastore->insert($assetRecord);
            $this->log('-> added asset: ' . $relative_path);
        }

        // 3. Both stub and asset exist — safe to link.
        $this->assetDatastore->insertDocumentAssetBinding($slug, $lang, $relativeUrl);

        return $imageResult;
    }


    private function processImage(string $filePath, string $relative_path): ?ImageResult
    {
        $subDir = pathinfo($relative_path, PATHINFO_DIRNAME);

        $imageProcessor = new ImageProcessor();
        $imageProcessor->output_dir = $this->config->getPublicAssetDir();
        $imageProcessor->public_path = $this->config->getBaseUrl();
        $imageProcessor->jpg_quality = $this->config->jpg_quality;
        $imageProcessor->avif_quality = $this->config->avif_quality;
        $imageProcessor->sizes = $this->config->image_sizes;

        $imageResult = $imageProcessor->process(
            $filePath,
            $subDir === '.' ? '' : $subDir,
            $this->getOutputCallback()
        );

        if ($imageResult === null) {
            $this->log('-> error: ' . $relative_path . ' (processing failed)');
            return null;
        }

        $imageResult->relative_url = str_replace('\\', '/', $relative_path);
        return $imageResult;
    }


    private function assetFromResult(ImageResult $imageResult, string $relativeUrl): AssetRecord
    {
        $asset = AssetDataset::fromObject($imageResult);
        $asset->relative_url = $relativeUrl;
        $asset->metadata = [
            'sizes' => $imageResult->sizes,
            'variants' => $imageResult->variants,
        ];
        return $asset;
    }


    private function imageResultFromAsset(AssetDataset $asset): ImageResult
    {
        $result = new ImageResult();
        $result->relative_url = $asset->relative_url;
        $result->public_path = $asset->public_path;
        $result->mime_type = $asset->mime_type;
        $result->width = $asset->width;
        $result->height = $asset->height;
        $result->hash = $asset->hash;
        $result->variants = $asset->metadata['variants'] ?? [];
        $result->sizes = $asset->metadata['sizes'] ?? [];
        return $result;
    }


}
