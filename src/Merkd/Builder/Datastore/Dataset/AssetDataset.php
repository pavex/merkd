<?php

/**
 * Build-layer dataset for an asset row from the database.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Datastore\Dataset
 */

namespace Merkd\Builder\Datastore\Dataset;

use Merkd\Builder\Record\AssetRecord;


final class AssetDataset extends AssetRecord
{

    /** Creates AssetDataset from a PDO result row. */
    public static function fromResult(array $row): static
    {
        return static::fromAssocArray($row, function(string $k, mixed $v): mixed {
            return match ($k) {
                'width', 'height', 'hash' => (int) ($v ?? 0),
                'referenced_by', 'metadata' => is_string($v ?? []) ? (json_decode($v, true) ?? []) : (array) ($v ?? []),
                default => $v ?? '',
            };
        });
    }

}
