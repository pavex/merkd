<?php

/**
 * Interface for the document build datastore.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Datastore
 */

namespace Merkd\Builder\Datastore;

use Merkd\Builder\Record\SourceRecord;


interface BuildDatastoreInterface
{

    /** Inserts a new document row (is_deleted = 0). */
    public function insert(SourceRecord $source): void;

    /** Inserts a minimal hidden stub to satisfy FK constraints before assets are linked. */
    public function insertStub(string $slug, string $lang): void;

    /** Updates an existing document row, setting is_deleted = 0. */
    public function update(SourceRecord $source): void;

    /**
     * Inserts or updates a document row based on whether it already exists.
     * Sets is_deleted = 0 in both cases.
     *
     * @return string 'added' or 'updated'
     */
    public function upsert(SourceRecord $source): string;

    /** Marks all documents as deleted — called at the start of each build. */
    public function markAllDeleted(): void;

    /** Physically deletes all rows — used by --reset before full rebuild. */
    public function truncate(): void;

}
