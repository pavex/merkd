<?php

/**
 * Build-layer dataset for a document row from the database.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Datastore\Dataset
 */

namespace Merkd\Builder\Datastore\Dataset;

use Merkd\Builder\Record\SourceRecord;


final class SourceDataset extends SourceRecord
{

    /** Creates SourceDataset from a PDO result row. */
    public static function fromResult(array $row): static
    {
        return static::fromAssocArray($row, function(string $k, mixed $v): mixed {
            return match ($k) {
                'is_hidden' => (bool) ($v ?? false),
                'mtime', 'hash' => (int) ($v ?? 0),
                default => $v ?? '',
            };
        });
    }


    /** Casts a parsed SourceRecord into a SourceDataset for datastore operations. */
    public static function fromRecord(SourceRecord $record): static
    {
        return static::fromObject($record);
    }


}
