<?php

/**
 * Converts a source image to multiple sized variants using Nette\Utils\Image.
 *
 * Returns an ImageResult with processing metadata — the caller is responsible
 * for assembling and persisting the AssetDataset.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Image
 */

namespace Merkd\Builder\Image;

use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\ImageColor;
use Nette\Utils\ImageType;


final class ImageProcessor
{

    const DEFAULT_JPG_QUALITY = 85;
    const DEFAULT_AVIF_QUALITY = 60;
    const DEFAULT_SIZES = [400, 800, 1600];

    public string $output_dir;
    public string $public_path = '/';
    public int $jpg_quality = self::DEFAULT_JPG_QUALITY;
    public int $avif_quality = self::DEFAULT_AVIF_QUALITY;
    public array $sizes = self::DEFAULT_SIZES;


    /**
     * Processes a single image file — converts and saves multiple sizes in JPG + AVIF.
     *
     * For each entry in $sizes a pair of files is written:
     *   {slug}_{width}px.jpg / {slug}_{width}px.avif
     *
     * Additionally a default fallback is saved at max($sizes):
     *   {slug}.jpg / {slug}.avif
     *
     * Image::ShrinkOnly ensures images smaller than a target width are not upscaled.
     *
     * @param callable|null $log function(string $message): void
     */
    public function process(string $file_path, string $sub_dir = '', ?callable $log = null): ?ImageResult
    {
        if (!is_file($file_path) || !is_readable($file_path)) {
            return null;
        }

        try {
            $type = null;
            $sourceImage = Image::fromFile($file_path, $type);
        }
        catch (\Throwable) {
            return null;
        }

        $slug = pathinfo($file_path, PATHINFO_FILENAME);
        $hash = (int) hexdec(hash_file('crc32b', $file_path));
        $mimeType = Image::typeToMimeType($type);
        $origW = $sourceImage->getWidth();
        $origH = $sourceImage->getHeight();
        $avifSupported = Image::isTypeSupported(ImageType::AVIF);

        $targetDir = rtrim(FileSystem::unixSlashes($this->output_dir), '/');
        $targetPublic = trim(FileSystem::unixSlashes($this->public_path), '/');
        $targetPublic = $targetPublic === '' ? '/' : '/' . $targetPublic . '/';

        if ($sub_dir !== '' && $sub_dir !== '.') {
            $subDir = FileSystem::unixSlashes($sub_dir);
            $targetDir = FileSystem::joinPaths($targetDir, $subDir);
            $targetPublic .= trim($subDir, '/') . '/';
        }

        FileSystem::createDir($targetDir);

        $variants = [];
        $generatedSizes = [];

        foreach ($this->sizes as $width) {
            $resized = clone $sourceImage;
            $resized->resize($width, null, Image::ShrinkOnly);
            $basePath = FileSystem::joinPaths($targetDir, "{$slug}_{$width}px");

            $savedJpg = $this->saveJpg($resized, "{$basePath}.jpg");
            $savedAvif = $avifSupported && $this->saveAvif($resized, "{$basePath}.avif");

            if ($log) {
                $log("   {$slug}_{$width}px.jpg  " . ($savedJpg ? 'OK' : 'ERROR'));
                $log("   {$slug}_{$width}px.avif " . ($savedAvif ? 'OK' : 'skip'));
            }

            if ($savedJpg && !in_array('jpg', $variants, true)) $variants[] = 'jpg';
            if ($savedAvif && !in_array('avif', $variants, true)) $variants[] = 'avif';
            $generatedSizes[] = $width;
        }

        $default = clone $sourceImage;
        $default->resize(max($this->sizes), null, Image::ShrinkOnly);
        $defaultBase = FileSystem::joinPaths($targetDir, $slug);

        $savedJpg = $this->saveJpg($default, "{$defaultBase}.jpg");
        $savedAvif = $avifSupported && $this->saveAvif($default, "{$defaultBase}.avif");

        if ($log) {
            $log("   {$slug}.jpg  " . ($savedJpg ? 'OK' : 'ERROR') . '  (default)');
            $log("   {$slug}.avif " . ($savedAvif ? 'OK' : 'skip') . '  (default)');
        }

        if ($savedJpg && !in_array('jpg', $variants, true)) $variants[] = 'jpg';
        if ($savedAvif && !in_array('avif', $variants, true)) $variants[] = 'avif';

        $result = new ImageResult();
        $result->relative_url = '';
        $result->public_path = $targetPublic;
        $result->mime_type = $mimeType;
        $result->width = $origW;
        $result->height = $origH;
        $result->hash = $hash;
        $result->variants = $variants;
        $result->sizes = $generatedSizes;

        return $result;
    }


    private function saveJpg(Image $image, string $path): bool
    {
        try {
            $flat = Image::fromBlank($image->getWidth(), $image->getHeight(), ImageColor::rgb(255, 255, 255));
            $flat->place($image, 0, 0);
            $flat->save($path, $this->jpg_quality, ImageType::JPEG);
            return true;
        }
        catch (\Throwable) {
            return false;
        }
    }


    private function saveAvif(Image $image, string $path): bool
    {
        try {
            $image->save($path, $this->avif_quality, ImageType::AVIF);
            return true;
        }
        catch (\Throwable) {
            return false;
        }
    }


}
