<?php

/**
 * Abstract base record for an image asset.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Record
 */

namespace Merkd\Builder\Record;

use Pavex\Utils\Record;


abstract class AssetRecord extends Record
{

    public string $relative_url;
    public string $public_path;
    public string $alt = '';
    public string $title = '';
    public int $width;
    public int $height;
    public string $mime_type;
    public int $hash;
    public bool $is_deleted = false;
    public array $metadata = [];

}
