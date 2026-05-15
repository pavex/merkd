<?php

/**
 * Build configuration value object.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder
 */

namespace Merkd\Builder;

use Merkd\Builder\Image\ImageProcessor;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\ImageType;


final class Config
{

    public const DEFAULT_LANG = 'en';
    public const DEFAULT_DB = 'db/merkd.sqlite';
    public const DEFAULT_CONTENT_DIR = 'content';
    public const DEFAULT_PUBLIC_DIR = 'public';
    public const DEFAULT_ASSET_DIR = 'assets';
    public const DEFAULT_BASE_URL = '/';

    public readonly string $root_dir;
    public readonly string $db_path;
    public readonly string $content_dir;
    public readonly string $public_dir;
    public readonly string $asset_dir;
    public readonly string $base_url;
    public readonly string $default_lang;
    public readonly int $jpg_quality;
    public readonly int $avif_quality;
    public readonly array $image_sizes;

    private readonly bool $base_url_derived;


    public function __construct(array $cfg, string $root)
    {
        $this->root_dir = $root;

        $this->default_lang = $cfg['lang'] ?? self::DEFAULT_LANG;
        $this->db_path = $this->resolve($root, $cfg['db'] ?? self::DEFAULT_DB);
        $this->content_dir = $this->resolve($root, $cfg['content_dir'] ?? self::DEFAULT_CONTENT_DIR);
        $this->public_dir = $this->resolve($root, $cfg['public_dir'] ?? self::DEFAULT_PUBLIC_DIR);
        $this->asset_dir = trim($cfg['asset_dir'] ?? self::DEFAULT_ASSET_DIR, '/\\');

        $this->jpg_quality = (int) ($cfg['jpg_quality'] ?? ImageProcessor::DEFAULT_JPG_QUALITY);
        $this->avif_quality = (int) ($cfg['avif_quality'] ?? ImageProcessor::DEFAULT_AVIF_QUALITY);
        $this->image_sizes = $cfg['image_sizes'] ?? ImageProcessor::DEFAULT_SIZES;

        if (isset($cfg['base_url'])) {
            $this->base_url = $cfg['base_url'];
            $this->base_url_derived = false;
        }
        else {
            $this->base_url = $this->deriveBaseUrl($this->public_dir, $this->asset_dir);
            $this->base_url_derived = true;
        }
    }


    /** Returns absolute path to the source asset directory inside content_dir. */
    public function getContentAssetDir(): string
    {
        return FileSystem::joinPaths($this->content_dir, $this->asset_dir);
    }


    /** Returns absolute path to the public asset output directory. */
    public function getPublicAssetDir(): string
    {
        return FileSystem::joinPaths($this->public_dir, $this->asset_dir);
    }


    /** Returns normalized base URL for assets (e.g. /assets/). */
    public function getBaseUrl(): string
    {
        $path = trim(FileSystem::unixSlashes($this->base_url), '/');
        return $path === '' ? '/' : '/' . $path . '/';
    }


    /**
     * Returns true if the public asset dir is safe to wipe during --reset.
     *
     * Unsafe when asset_dir is empty — that would wipe the entire public_dir.
     */
    public function isAssetDirSafe(): bool
    {
        return $this->asset_dir !== '';
    }


    /** Returns true if AVIF image format is supported by the current GD build. */
    public function isAvifSupported(): bool
    {
        return Image::isTypeSupported(ImageType::AVIF);
    }


    /** Returns true when base_url was not set explicitly and was derived. */
    public function isBaseUrlDerived(): bool
    {
        return $this->base_url_derived;
    }


    private function deriveBaseUrl(string $public_dir, string $asset_dir): string
    {
        $normalized = FileSystem::unixSlashes($public_dir);
        $pos = strrpos($normalized, '/public');

        if ($pos !== false) {
            $after = trim(substr($normalized, $pos + strlen('/public')), '/');
            $parts = array_filter([$after, $asset_dir]);
            $url = implode('/', $parts);
            return $url === '' ? '/' : '/' . $url . '/';
        }

        return $asset_dir !== '' ? '/' . $asset_dir . '/' : self::DEFAULT_BASE_URL;
    }


    private function resolve(string $root, string $path): string
    {
        if (FileSystem::isAbsolute($path)) {
            return FileSystem::normalizePath($path);
        }
        return FileSystem::joinPaths($root, $path);
    }


}
