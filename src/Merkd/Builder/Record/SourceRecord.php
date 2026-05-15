<?php

/**
 * Base record for a parsed Markdown source file.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Record
 */

namespace Merkd\Builder\Record;

use Pavex\Utils\Record;


class SourceRecord extends Record
{

    public string $slug;
    public string $lang;
    public string $title;
    public string $locale;
    public string $perex;
    public string $tags;
    /** Raw front-matter value — not persisted, used by assetCallback. */
    public string $image;
    public string $asset_relative_url;
    public string $published;
    public string $author;
    public bool $is_hidden;
    public bool $is_deleted = false;
    public string $content_md;
    public string $content_html;
    public int $mtime;
    public int $hash;
    public string $translations;
    public string $attributes;

}
