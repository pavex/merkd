<?php

/**
 * PDO-backed datastore for document publishing.
 *
 * @author    pavex@ines.cz
 * @copyright 2026 Pavel Macháček
 * @license   MIT
 * @package   Merkd\Builder\Datastore
 */

namespace Merkd\Builder\Datastore;

use Merkd\Builder\Datastore\Dataset\SourceDataset;
use Merkd\Builder\Record\SourceRecord;


final class BuildPdoDatastore extends PdoDatastore implements BuildDatastoreInterface
{


    public function findBySlugLang(string $slug, string $lang): ?SourceDataset
    {
        $statement = $this->pdo->prepare(
            'SELECT slug, lang, hash FROM documents WHERE slug = :slug AND lang = :lang LIMIT 1'
        );
        $statement->execute([':slug' => $slug, ':lang' => $lang]);

        if ($row = $statement->fetch()) {
            return SourceDataset::fromResult($row);
        }
        return null;
    }


    public function insert(SourceRecord $source): void
    {
        $statement = $this->pdo->prepare('
            INSERT INTO documents
                (slug, lang, title, locale, perex, tags, asset_relative_url, published, author,
                 is_hidden, is_deleted, content_md, content_html, mtime, hash, translations, attributes)
            VALUES
                (:slug, :lang, :title, :locale, :perex, :tags, :asset_relative_url, :published, :author,
                 :is_hidden, 0, :content_md, :content_html, :mtime, :hash, :translations, :attributes)
        ');
        $statement->execute($this->bind($source));
    }


    /**
     * Inserts a minimal hidden stub so document_assets FK → documents is satisfied
     * before assets are processed and linked.
     *
     * asset_relative_url = NULL — avoids FK check against assets table.
     * INSERT OR IGNORE — safe if document already exists.
     */
    public function insertStub(string $slug, string $lang): void
    {
        $statement = $this->pdo->prepare("
            INSERT OR IGNORE INTO documents
                (slug, lang, title, locale, perex, tags, asset_relative_url, published,
                 author, is_hidden, is_deleted, content_md, content_html, mtime, hash, translations, attributes)
            VALUES
                (:slug, :lang, '', '', '', '', NULL, datetime('now'),
                 '', 1, 1, '', '', 0, 0, '[]', '{}')
        ");
        $statement->execute([':slug' => $slug, ':lang' => $lang]);
    }


    public function update(SourceRecord $source): void
    {
        $statement = $this->pdo->prepare("
            UPDATE documents SET
                title = :title,
                locale = :locale,
                perex = :perex,
                tags = :tags,
                asset_relative_url = :asset_relative_url,
                published = :published,
                author = :author,
                is_hidden = :is_hidden,
                is_deleted = 0,
                content_md = :content_md,
                content_html = :content_html,
                mtime = :mtime,
                hash = :hash,
                translations = :translations,
                attributes = :attributes,
                updated_at = datetime('now')
            WHERE slug = :slug AND lang = :lang
        ");
        $statement->execute($this->bind($source));
    }


    public function markAllDeleted(): void
    {
        $this->pdo->exec('UPDATE documents SET is_deleted = 1');
    }


    public function truncate(): void
    {
        $this->pdo->exec('DELETE FROM document_assets');
        $this->pdo->exec('DELETE FROM documents');
    }


    private function bind(SourceRecord $source): array
    {
        return [
            ':slug' => $source->slug,
            ':lang' => $source->lang,
            ':title' => $source->title,
            ':locale' => $source->locale,
            ':perex' => $source->perex,
            ':tags' => $source->tags,
            ':asset_relative_url' => $source->asset_relative_url !== '' ? $source->asset_relative_url : null,
            ':published' => $source->published,
            ':author' => $source->author,
            ':is_hidden' => (int) $source->is_hidden,
            ':content_md' => $source->content_md,
            ':content_html' => $source->content_html,
            ':mtime' => $source->mtime,
            ':hash' => $source->hash,
            ':translations' => $source->translations,
            ':attributes' => $source->attributes,
        ];
    }


}
