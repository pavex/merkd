-- ============================================================
-- Merkd SQLite schema
-- Used by merkd-install to initialize a fresh database.
-- ============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS "documents" (
    "slug"               TEXT NOT NULL,
    "lang"               TEXT NOT NULL DEFAULT 'en',
    "title"              TEXT NOT NULL DEFAULT '',
    "locale"             TEXT NOT NULL DEFAULT '',
    "perex"              TEXT NOT NULL DEFAULT '',
    "tags"               TEXT NOT NULL DEFAULT '',
    "asset_relative_url" TEXT NULL DEFAULT NULL,
    "published"          TEXT NOT NULL,
    "author"             TEXT NOT NULL DEFAULT '',
    "is_hidden"          INTEGER NOT NULL DEFAULT 0,
    "is_deleted"         INTEGER NOT NULL DEFAULT 0,
    "content_md"         TEXT NOT NULL DEFAULT '',
    "content_html"       TEXT NOT NULL DEFAULT '',
    "mtime"              INTEGER NOT NULL DEFAULT 0,
    "hash"               INTEGER NOT NULL DEFAULT 0,
    "translations"       TEXT NOT NULL DEFAULT '[]',
    "attributes"         TEXT NOT NULL DEFAULT '{}',
    "created_at"         TEXT NOT NULL DEFAULT (datetime('now')),
    "updated_at"         TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY ("slug", "lang"),
    FOREIGN KEY ("asset_relative_url")
        REFERENCES "assets" ("relative_url")
        ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS "idx_documents_lang"      ON "documents" ("lang");
CREATE INDEX IF NOT EXISTS "idx_documents_published" ON "documents" ("published");
CREATE INDEX IF NOT EXISTS "idx_documents_hidden"    ON "documents" ("is_hidden");
CREATE INDEX IF NOT EXISTS "idx_documents_deleted"   ON "documents" ("is_deleted");
CREATE INDEX IF NOT EXISTS "idx_documents_tags"      ON "documents" ("tags");

CREATE TABLE IF NOT EXISTS "assets" (
    "relative_url"  TEXT NOT NULL,
    "public_path"   TEXT NOT NULL DEFAULT '',
    "alt"           TEXT NOT NULL DEFAULT '',
    "title"         TEXT NOT NULL DEFAULT '',
    "width"         INTEGER NOT NULL DEFAULT 0,
    "height"        INTEGER NOT NULL DEFAULT 0,
    "mime_type"     TEXT NOT NULL DEFAULT '',
    "hash"          INTEGER NOT NULL DEFAULT 0,
    "is_deleted"    INTEGER NOT NULL DEFAULT 0,
    "referenced_by" TEXT NOT NULL DEFAULT '[]',
    "metadata"      TEXT NOT NULL DEFAULT '{}',
    "created_at"    TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY ("relative_url")
);

CREATE INDEX IF NOT EXISTS "idx_assets_deleted" ON "assets" ("is_deleted");

CREATE TABLE IF NOT EXISTS "document_assets" (
    "document_slug" TEXT NOT NULL,
    "document_lang" TEXT NOT NULL,
    "asset_url"     TEXT NOT NULL,
    PRIMARY KEY ("document_slug", "document_lang", "asset_url"),
    FOREIGN KEY ("document_slug", "document_lang")
        REFERENCES "documents" ("slug", "lang")
        ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY ("asset_url")
        REFERENCES "assets" ("relative_url")
        ON UPDATE CASCADE ON DELETE CASCADE
);
