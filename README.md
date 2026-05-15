# Merkd build - file driven CMS (beta)

**Merkd Build is the compiler half of the Merkd CMS stack.** It takes a directory of Markdown files, processes them through a typed pipeline, generates responsive image variants in JPG and AVIF, and writes everything into a SQLite database — ready for the `pavex/merkd` client to serve.

You run it once to bootstrap, and then only when content changes. The build is fast, deterministic, and safe to re-run at any time.

## What it does

Content pipelines for file-driven CMS systems usually involve one of two bad tradeoffs: either you parse Markdown on every request (slow, fragile, wasteful) or you hand-roll a half-baked build script that breaks the moment a file is renamed. Merkd Build is neither.

**The build pipeline:**

1. Walks the content directory and collects all `.md` files
2. Parses YAML front-matter into typed `SourceRecord` objects
3. Renders Markdown body to HTML via an extended Parsedown — inline images are intercepted and replaced with responsive `<picture>` elements at parse time
4. For every local image referenced (hero image + inline body images): resolves it relative to the `.md` file, generates sized variants (400/800/1600px by default) in JPG and AVIF, and records the output metadata
5. Persists documents and assets to SQLite, maintaining foreign key integrity throughout
6. Tracks which assets each document references via a proper m:n join table

**What you get:**

- **Hash-based change detection** — files that haven't changed since the last build are skipped. Only new or modified content is processed.
- **Soft delete — nothing is lost** — removing a `.md` file does not delete database records. It marks them `is_deleted = 1`. The client filters them out. You control when data is permanently removed with `--reset`.
- **Responsive images, automatically** — source images are processed into multiple sizes and formats. The rendered HTML uses `<picture>` with correct `srcset` entries. No image handling needed in templates.
- **AVIF + JPG with graceful fallback** — if GD is compiled with libavif, both formats are generated. If not, only JPG is produced and the build continues with a warning. No configuration change needed.
- **Atomic FK integrity** — documents and their assets are written in the correct order. The pipeline never leaves the database in an inconsistent state, even if interrupted partway through.
- **Three build modes** — normal (hash check, skip unchanged), force (rewrite all), reset (wipe and rebuild from scratch) — covering every workflow from day-to-day editing to full deployment resets.
- **Path-relative images** — image paths in `.md` files resolve relative to the file that references them, not to a global content root. Move a file and its images stay correct.
- **Custom attributes** — any unknown front-matter key is preserved as JSON in the `attributes` column. No schema changes needed to extend the content model.

## How it fits together

```
content/*.md  --→  merkd-build (CLI)  --→  merkd.sqlite  --→  pavex/merkd  --→  templates
(your files)       (this package)          (database)         (client)
```

This package is the **write side**. It reads `.md` files and writes to the database. The `pavex/merkd` client is the read side — it never touches the filesystem.

## Requirements

- PHP 8.1+
- Extensions: `pdo_sqlite`, `gd`, `fileinfo`
- GD with AVIF support (optional — JPG fallback is used if unavailable)
- [pavex/merkd](https://github.com/pavex/merkd)
- [pavex/utils](https://github.com/pavex/utils)
- [pavex/getopt](https://github.com/pavex/getopt)

## Installation

```bash
composer require pavex/merkd-build
```

## Quick start

```bash
# 1. Check environment and install database
php vendor/bin/merkd-install

# 2. Build content
composer merkd -- --build

# 3. Force rebuild (ignore hash check)
composer merkd -- --build --force

# 4. Full reset — wipe assets directory, truncate DB, rebuild everything
composer merkd -- --build --reset
```

Add convenience scripts to `composer.json`:

```json
{
    "scripts": {
        "merkd": "@php vendor/bin/merkd-build",
        "merkd-install": "@php vendor/bin/merkd-install"
    }
}
```

## Configuration

Create or edit `config.php` in your project root:

```php
<?php

return [
    'merkd' => [
        'lang'         => 'en',            // default language
        'db'           => 'db/merkd.sqlite',
        'content_dir'  => 'content',
        'public_dir'   => 'public',
        'asset_dir'    => 'assets',        // asset subdirectory inside public_dir (default: assets)
        'base_url'     => '/assets/',      // public URL prefix — derived automatically if omitted

        // Image processing (all optional)
        'jpg_quality'  => 85,              // 0–100
        'avif_quality' => 60,              // 0–100
        'image_sizes'  => [400, 800, 1600], // generated widths in px
    ],
];
```

All paths are relative to the project root (directory containing `config.php`). Absolute paths are also accepted.

### Directory structure

```
project/
├── config.php
├── content/
│   ├── my-article.md
│   └── images/
│       └── photo.jpg          ← source image (path relative to the .md file)
├── db/
│   └── merkd.sqlite
└── public/
    └── assets/                ← builder-owned directory, safe to wipe on --reset
        └── images/
            ├── photo_400px.jpg
            ├── photo_400px.avif
            ├── photo_800px.jpg
            ├── photo_800px.avif
            ├── photo_1600px.jpg
            ├── photo_1600px.avif
            ├── photo.jpg       ← default fallback at max configured size
            └── photo.avif
```

## Markdown format

```markdown
---
slug: my-article
title: My Article
lang: en
published: "2026-05-15 10:00"
author: Jane Doe
tags: php;web
perex: A short excerpt shown in listings.
image: images/photo.jpg
hidden: false
---

# My Article

Content goes here. Inline images work too — they are converted to <picture> automatically:

![Alt text](images/other.jpg)
```

### Front-matter fields

| Field | Required | Description |
|---|---|---|
| `slug` | no | URL identifier — derived from filename if omitted |
| `title` | yes | Document title |
| `lang` | no | Language code — uses config default if omitted |
| `published` | no | Publication datetime — uses file mtime if omitted |
| `author` | no | Author name |
| `tags` | no | Semicolon-separated list or YAML array |
| `perex` | no | Short excerpt |
| `image` | no | Hero image path, relative to the `.md` file |
| `hidden` | no | `true` to hide from listings |
| `locale` | no | Full locale string (e.g. `en_US`) |
| `translations` | no | Map of language codes to slugs |

Any unrecognised key is stored in the `attributes` JSON column and accessible via `$post->getAttribute('key')`.

## Image processing

Images are resolved **relative to the `.md` file** that references them — both hero images (front-matter `image:`) and inline body images (`![alt](path)`).

For each source image the builder generates:

- `{name}_{width}px.jpg` and `{name}_{width}px.avif` for each configured size
- `{name}.jpg` and `{name}.avif` as the default fallback at `max(image_sizes)`

Output is written to `public_dir/asset_dir/` preserving the subdirectory structure from the relative path.

In the rendered HTML, `<img>` tags are intercepted by `MerkdParsedown` during parsing and replaced inline with `<picture>` elements — `<source>` tags for each available variant and size, with a `<img>` fallback.

**Supported source formats:** JPG, JPEG, PNG, WebP
**Not supported:** GIF — only the first frame would be processed; animated GIFs would lose animation silently.

## Build modes

### Normal build

```bash
php vendor/bin/merkd-build --build
```

- Marks all existing records `is_deleted = 1` at the start
- Processes only files whose content hash has changed
- Restores each processed record to `is_deleted = 0`
- Files removed from disk remain `is_deleted = 1` — invisible to the client, still recoverable
- Never deletes any files from disk

### Force build

```bash
php vendor/bin/merkd-build --build --force
```

Same as normal, but skips hash comparison — every document and asset is rewritten. Use this after changing image quality settings or sizes, or when you suspect a partial build left stale data.

### Reset

```bash
php vendor/bin/merkd-build --build --reset
```

- Wipes all contents of `public/asset_dir/` — **the directory itself is preserved** so filesystem permissions are not lost
- Truncates `documents`, `assets`, and `document_assets` tables
- Performs a full force rebuild from scratch

Only the builder-owned asset directory is touched. The rest of `public/` (index.php, CSS, JS) is never modified.

> Requires `asset_dir` to be non-empty. If empty, the wipe step is skipped with a warning.

## CLI options

```
Options:
  -b, --build             Run the full build pipeline (required)
  -f, --force             Force rebuild, skip hash check
  -r, --reset             Wipe asset dir + truncate DB, then force rebuild
  -d, --db <path>         Override db path from config.php
  -c, --content <path>    Override content_dir from config.php
  -p, --public <path>     Override public_dir from config.php
  -l, --lang <lang>       Override default lang from config.php
  -h, --help              Show help
```

## merkd-install

Verifies the environment and installs the database if it does not exist:

```bash
php vendor/bin/merkd-install
```

1. **Directories** — verifies `content`, `db`, and `public_dir` exist and are writable; creates them if missing
2. **Database** — installs from `vendor/pavex/merkd-build/db/schema.sql` if not present
3. **Extensions** — checks `pdo_sqlite`, `gd`, `fileinfo`
4. **Image formats** — reports JPEG, PNG, WebP, AVIF support in GD

Run once after installation, and again after any schema changes.

## Programmatic usage

```php
<?php

require 'vendor/autoload.php';

use Merkd\Builder\Config;
use Merkd\Builder\BuildContainer;
use Merkd\Builder\Build;

$cfg = require 'config.php';
$config = new Config($cfg['merkd'] ?? [], __DIR__);
$container = new BuildContainer($config);

$build = new Build($container);
$build->setOutput(fn(string $msg) => print($msg . PHP_EOL));

$build->run();               // normal build
$build->run(force: true);    // force — skip hash check
$build->run(reset: true);    // reset — wipe + full rebuild (implies force)
```

## Soft delete

The builder never physically removes records from the database:

- **Build start** — `UPDATE documents SET is_deleted = 1` and same for assets
- **Per file** — each processed document and its assets get `is_deleted = 0`
- **After build** — records with `is_deleted = 1` are content that no longer exists on disk
- **Client** — all queries in `pavex/merkd` include `AND is_deleted = 0`
- **Cleanup** — use `--reset` to physically truncate tables and reclaim space

This avoids filesystem permission problems, preserves history between builds, and makes accidental deletions recoverable.

## Architecture

```
Build::run()
    ├── markAllDeleted()              mark all records is_deleted = 1
    ├── (--reset) clearDirContents() + truncate()
    └── foreach .md file
            └── ContentBuilder::build(file_path, force)
                    ├── FileParser::parse()
                    │       ├── YAML front-matter  →  SourceRecord
                    │       ├── assetCallback(hero image src)
                    │       └── MerkdParsedown::text(body)
                    │               └── inlineImage() → assetCallback(inline src)
                    │
                    ├── assetCallback(src, slug, lang)
                    │       ├── insertStub()                    FK → documents satisfied
                    │       ├── ImageProcessor::process()       generate JPG + AVIF variants
                    │       ├── assetDatastore::insert/update() FK → assets satisfied
                    │       └── insertDocumentAssetBinding()    m:n link
                    │
                    └── buildDatastore::insert/update()         persist document, is_deleted = 0
```

## Database schema

Schema is at `vendor/pavex/merkd-build/db/schema.sql`, installed by `merkd-install`.

Key design decisions:

- `documents.asset_relative_url` is nullable — `NULL` = no hero image, avoids FK violation during stub insertion
- `ON DELETE SET NULL` on the asset FK — if an asset is removed, the document stays intact
- `document_assets` m:n table — tracks all asset references (hero + inline) per document, replaces the legacy `referenced_by` JSON array
- `is_deleted` indexed on both tables — efficient filtering without physical deletes
- SQLite WAL mode — safe for concurrent reads while the builder writes

## License

MIT — © 2026 Pavel Macháček
