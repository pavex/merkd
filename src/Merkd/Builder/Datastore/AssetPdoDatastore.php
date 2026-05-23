<?php

/**
 * PDO-backed datastore for assets.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Datastore
 */

namespace Merkd\Builder\Datastore;

use Merkd\Datastore\Dataset\AssetDataset;
use Merkd\Record\AssetRecord;


final class AssetPdoDatastore extends PdoDatastore implements AssetDatastoreInterface
{


    public function findByRelativeUrl(string $relative_url): ?AssetDataset
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM assets WHERE relative_url = :url LIMIT 1'
        );
        $statement->execute([':url' => $relative_url]);

        if ($row = $statement->fetch()) {
            return AssetDataset::fromResult($row);
        }
        return null;
    }


    public function insert(AssetRecord $asset): void
    {
        $statement = $this->pdo->prepare('
            INSERT INTO assets
                (relative_url, public_path, alt, title, width, height, mime_type, hash, is_deleted, referenced_by, metadata)
            VALUES
                (:relative_url, :public_path, :alt, :title, :width, :height, :mime_type, :hash, 0, :referenced_by, :metadata)
        ');
        $statement->execute($this->bindCore($asset) + [':referenced_by' => '[]']);
    }


    public function update(AssetRecord $asset): void
    {
        $statement = $this->pdo->prepare('
            UPDATE assets SET
                public_path = :public_path,
                alt = :alt,
                title = :title,
                width = :width,
                height = :height,
                mime_type = :mime_type,
                hash = :hash,
                is_deleted = 0,
                metadata = :metadata
            WHERE relative_url = :relative_url
        ');
        $statement->execute($this->bindCore($asset));
    }


    public function restoreDeleted(string $relative_url): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE assets SET is_deleted = 0 WHERE relative_url = :url'
        );
        $statement->execute([':url' => $relative_url]);
    }


    public function insertDocumentAssetBinding(string $slug, string $lang, string $asset_url): void
    {
        $statement = $this->pdo->prepare('
            INSERT OR IGNORE INTO document_assets (document_slug, document_lang, asset_url)
            VALUES (:slug, :lang, :asset_url)
        ');
        $statement->execute([':slug' => $slug, ':lang' => $lang, ':asset_url' => $asset_url]);
    }


    public function markOrphanAssetsDeleted(): void
    {
        $this->pdo->exec("
            UPDATE assets SET is_deleted = 1
            WHERE relative_url NOT IN (
                SELECT da.asset_url
                FROM document_assets da
                JOIN documents d
                    ON d.slug = da.document_slug AND d.lang = da.document_lang
                WHERE d.is_deleted = 0
            )
        ");
    }


    public function truncate(): void
    {
        $this->pdo->exec('DELETE FROM document_assets');
        $this->pdo->exec('DELETE FROM assets');
    }


    private function bindCore(AssetRecord $asset): array
    {
        return [
            ':relative_url' => $asset->relative_url,
            ':public_path' => $asset->public_path,
            ':alt' => $asset->alt,
            ':title' => $asset->title,
            ':width' => (int) $asset->width,
            ':height' => (int) $asset->height,
            ':mime_type' => $asset->mime_type,
            ':hash' => $asset->hash,
            ':metadata' => json_encode($asset->metadata),
        ];
    }


}
