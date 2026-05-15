<?php

/**
 * Build result — returned by Build::run().
 *
 * Single set of counters for the entire pipeline (content + images).
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Result
 */

namespace Merkd\Builder\Result;

use Pavex\Utils\Record;


final class BuildResult extends Record
{

    public int $added = 0;
    public int $updated = 0;
    public int $skipped = 0;


    /** Returns total number of processed items. */
    public function total(): int
    {
        return $this->added + $this->updated + $this->skipped;
    }


    /** Returns true if any items were added or updated. */
    public function hasChanges(): bool
    {
        return $this->added > 0 || $this->updated > 0;
    }


    /** Merges counters from another BuildResult into this one. */
    public function merge(self $other): void
    {
        $this->added += $other->added;
        $this->updated += $other->updated;
        $this->skipped += $other->skipped;
    }


}
