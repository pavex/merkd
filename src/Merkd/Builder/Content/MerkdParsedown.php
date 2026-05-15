<?php

/**
 * Parsedown subclass for Merkd content pipeline.
 *
 * Overrides inlineImage() to replace <img> with a responsive <picture> element
 * and invoke an asset callback for each local image encountered during parsing.
 *
 * External URLs (http / https / protocol-relative) are passed through unchanged.
 *
 * Asset callback signature: function(string $src, string $slug, string $lang): ?ImageResult
 *   Returns ImageResult on success (used to build <picture>), null on failure (keeps <img>).
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Content
 */

namespace Merkd\Builder\Content;

use Merkd\Builder\Image\ImageResult;
use Parsedown;
use Pavex\Utils\Html;


final class MerkdParsedown extends Parsedown
{

    private string $base_url;
    private array $sizes;

    /** @var callable|null function(string $src, string $slug, string $lang): ?ImageResult */
    private $assetCallback = null;

    private string $current_slug = '';
    private string $current_lang = '';


    public function __construct(string $base_url, array $sizes)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->sizes = $sizes;
    }


    /** @param callable|null $callback function(string $src, string $slug, string $lang): ?ImageResult */
    public function setAssetCallback(?callable $callback): void
    {
        $this->assetCallback = $callback;
    }


    /** Sets the document context for the current parse — needed by the asset callback. */
    public function setDocumentContext(string $slug, string $lang): void
    {
        $this->current_slug = $slug;
        $this->current_lang = $lang;
    }


    protected function inlineImage($Excerpt): ?array
    {
        $inline = parent::inlineImage($Excerpt);

        if ($inline === null) {
            return null;
        }

        $src = $inline['element']['attributes']['src'] ?? '';

        if ($this->isExternal($src)) {
            return $inline;
        }

        $alt = $inline['element']['attributes']['alt'] ?? '';
        $title = $inline['element']['attributes']['title'] ?? '';

        $imageResult = $this->assetCallback !== null
            ? ($this->assetCallback)($src, $this->current_slug, $this->current_lang)
            : null;

        if ($imageResult === null) {
            // Processing failed or no callback — keep original <img>.
            return $inline;
        }

        $inline['element'] = [
            'rawHtml' => (string) $this->buildPicture($imageResult, $alt, $title),
            'autobreak' => true,
        ];

        return $inline;
    }


    private function isExternal(string $src): bool
    {
        return str_starts_with($src, 'http://')
            || str_starts_with($src, 'https://')
            || str_starts_with($src, '//');
    }


    private function buildPicture(ImageResult $imageResult, string $alt, string $title): Html
    {
        $name = pathinfo($imageResult->relative_url, PATHINFO_FILENAME);
        $pub = rtrim($imageResult->public_path, '/') . '/';

        $sources = [];
        if (in_array('avif', $imageResult->variants, true)) {
            $srcset = [];
            foreach ($imageResult->sizes as $width) {
                $srcset[] = "{$pub}{$name}_{$width}px.avif {$width}w";
            }
            $sources[] = ['srcset' => implode(', ', $srcset), 'type' => 'image/avif'];
        }
        if (in_array('jpg', $imageResult->variants, true)) {
            $srcset = [];
            foreach ($imageResult->sizes as $width) {
                $srcset[] = "{$pub}{$name}_{$width}px.jpg {$width}w";
            }
            $sources[] = ['srcset' => implode(', ', $srcset), 'type' => 'image/jpeg'];
        }

        $img = ['src' => $pub . $name . '.jpg', 'alt' => $alt];
        if ($title !== '') {
            $img['title'] = $title;
        }

        return Html::picture($sources, $img);
    }


}
