<?php

/**
 * Result of a single image processing run.
 *
 * Returned by ImageProcessor::process() — contains everything needed
 * to assemble an AssetDataset in the caller (Build::processImage).
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Image
 */

namespace Merkd\Builder\Image;

use Pavex\Utils\Record;


final class ImageResult extends Record
{

    public string $relative_url;
    public string $public_path;
    public string $mime_type;
    public int $width;
    public int $height;
    public int $hash;
    /** @var string[] Actually generated format variants e.g. ['jpg', 'avif']. */
    public array $variants;
    /** @var int[] Actually generated size widths e.g. [400, 800, 1600]. */
    public array $sizes;

}
