<?php

/**
 * Parses a single Markdown file with YAML front-matter into a SourceRecord.
 *
 * Known front-matter keys are mapped to explicit SourceRecord fields.
 * Any unrecognised keys are collected into the `attributes` JSON field.
 *
 * Set the asset callback via setAssetCallback() before calling parse().
 * The callback is invoked for every local image encountered — front-matter
 * hero image first, then inline body images via MerkdParsedown.
 *
 * Asset callback signature: function(string $src, string $slug, string $lang): ?ImageResult
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Content
 */

namespace Merkd\Builder\Content;

use Merkd\Builder\Config;
use Merkd\Builder\Record\SourceRecord;
use Nette\IOException;
use Nette\Utils\FileSystem;
use Symfony\Component\Yaml\Yaml;


final class FileParser
{

    const KNOWN_KEYS = [
        'slug', 'lang', 'title', 'locale', 'perex',
        'tags', 'image', 'published', 'author',
        'hidden', 'translations',
    ];

    private Config $config;
    private string $default_lang = 'en';

    /** @var callable|null function(string $src, string $slug, string $lang): ?ImageResult */
    private $assetCallback = null;


    public function __construct(Config $config)
    {
        $this->config = $config;
    }


    public function setDefaultLang(string $lang): void
    {
        $this->default_lang = $lang;
    }


    /** @param callable|null $callback function(string $src, string $slug, string $lang): ?ImageResult */
    public function setAssetCallback(?callable $callback): void
    {
        $this->assetCallback = $callback;
    }


    /** Parses a .md file and returns a populated SourceRecord, or null on failure. */
    public function parse(string $file_path): ?SourceRecord
    {
        try {
            $raw = FileSystem::read($file_path);
        }
        catch (IOException) {
            return null;
        }

        [$frontMatterRaw, $contentMd] = $this->split($raw);
        $frontMatterRaw = str_replace("\r\n", "\n", $frontMatterRaw);
        $meta = $frontMatterRaw ? Yaml::parse($frontMatterRaw) : [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $slug = $this->resolveSlug($meta, $file_path);
        $lang = $meta['lang'] ?? $this->default_lang;
        $image = $meta['image'] ?? '';

        // Front-matter hero image — process before body so asset exists in DB first.
        if ($this->assetCallback !== null && $image !== '') {
            ($this->assetCallback)($image, $slug, $lang);
        }

        $parsedown = new MerkdParsedown($this->config->getBaseUrl(), $this->config->image_sizes);
        $parsedown->setAssetCallback($this->assetCallback);
        $parsedown->setDocumentContext($slug, $lang);
        $contentHtml = $parsedown->text($contentMd);

        $record = new SourceRecord();
        $record->slug = $slug;
        $record->lang = $lang;
        $record->title = $meta['title'] ?? '';
        $record->locale = $meta['locale'] ?? '';
        $record->perex = $meta['perex'] ?? '';
        $record->tags = $this->resolveTags($meta);
        $record->image = $image;
        $record->asset_relative_url = ltrim($image, '/\\');
        $record->published = $this->resolvePublished($meta, $file_path);
        $record->author = $meta['author'] ?? '';
        $record->is_hidden = (bool) ($meta['hidden'] ?? false);
        $record->content_md = $contentMd;
        $record->content_html = $contentHtml;
        $record->mtime = (int) filemtime($file_path);
        $record->hash = crc32($raw);
        $record->translations = isset($meta['translations']) ? json_encode($meta['translations']) : '[]';
        $record->attributes = $this->resolveAttributes($meta);

        return $record;
    }


    private function split(string $raw): array
    {
        $raw = ltrim($raw);
        if (!str_starts_with($raw, '---')) {
            return ['', $raw];
        }

        $end = strpos($raw, "\n---", 3);
        if ($end === false) {
            return ['', $raw];
        }

        return [
            trim(substr($raw, 3, $end - 3)),
            ltrim(substr($raw, $end + 4), "\n\r"),
        ];
    }


    private function resolveSlug(array $meta, string $file_path): string
    {
        if (!empty($meta['slug'])) {
            return (string) $meta['slug'];
        }
        return pathinfo($file_path, PATHINFO_FILENAME);
    }


    private function resolveTags(array $meta): string
    {
        if (!isset($meta['tags'])) {
            return '';
        }
        return is_array($meta['tags']) ? implode(';', $meta['tags']) : (string) $meta['tags'];
    }


    private function resolvePublished(array $meta, string $file_path): string
    {
        if (!empty($meta['published'])) {
            return (string) $meta['published'];
        }
        return date('Y-m-d H:i:s', filemtime($file_path));
    }


    private function resolveAttributes(array $meta): string
    {
        $attributes = [];
        foreach ($meta as $key => $value) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                $attributes[$key] = $value;
            }
        }
        return $attributes ? json_encode($attributes) : '{}';
    }


}
