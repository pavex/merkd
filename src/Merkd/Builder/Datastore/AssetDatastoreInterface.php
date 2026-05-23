<?php

/**
 * Interface for the asset datastore.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Datastore
 */

namespace Merkd\Builder\Datastore;

use Merkd\Datastore\Dataset\AssetDataset;
use Merkd\Record\AssetRecord;


interface AssetDatastoreInterface
{

    /** Finds an existing asset record by its relative URL. */
    public function findByRelativeUrl(string $relative_url): ?AssetDataset;

    /** Inserts a new asset record (is_deleted = 0). */
    public function insert(AssetRecord $asset): void;

    /** Updates an existing asset record, setting is_deleted = 0. */
    public function update(AssetRecord $asset): void;

    /** Restores a single asset to is_deleted = 0 — used when asset is unchanged (hash match). */
    public function restoreDeleted(string $relative_url): void;

    /** Inserts a document→asset relationship (INSERT OR IGNORE). */
    public function insertDocumentAssetBinding(string $slug, string $lang, string $asset_url): void;

    /**
     * Marks assets as is_deleted = 1 when they have no reference
     * from any active (is_deleted = 0) document.
     * Called at the end of each build.
     */
    public function markOrphanAssetsDeleted(): void;

    /** Physically deletes all rows — used by --reset before full rebuild. */
    public function truncate(): void;

}
