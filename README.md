# pavex/merkd

**Merkd is a lightweight, file-driven CMS for PHP applications.** Write content in Markdown, run one command, and your content is compiled into a SQLite database with responsive image variants — ready to serve at full speed.

`pavex/merkd` is the main package. It includes the content builder, image processor, CLI tools, and the SQLite client (`pavex/merkd-client`). Install this one package and you have everything.

```bash
composer require pavex/merkd
```

**What it does:**

1. Walks your content directory and parses `.md` files with YAML front-matter
2. Renders Markdown to HTML — inline images are intercepted and replaced with responsive `<picture>` elements at parse time
3. Generates JPG and AVIF image variants in multiple sizes
4. Persists documents and assets to SQLite with full referential integrity
5. Tracks soft-deleted content — nothing is lost when you remove a file

**What you get from the client side:**

- Typed PHP objects (`Post`, `AssetRecord`) — no magic arrays
- Responsive `<picture>` HTML from a single method call
- Multi-language support built in
- Custom front-matter attributes without schema changes

## How it fits together

```
content/*.md  ──→  merkd (CLI builder)  ──→  merkd.sqlite  ──→  merkd-client  ──→  templates
  (your files)      (this package)            (database)         (included)
```

The builder is the write side. The client (`pavex/merkd-client`, included as a dependency) is the read side — it never writes to the database or touches the filesystem.

## Requirements

- PHP 8.1+
- Extensions: `pdo_sqlite`, `gd`, `fileinfo`
- GD with AVIF support (optional — JPG fallback is used if unavailable)
- [pavex/utils](https://github.com/pavex/utils)
- [pavex/getopt](https://github.com/pavex/getopt)

## Installation

```bash
composer require pavex/merkd
```

Then run the install script to verify the environment and create the database:

```bash
php vendor/bin/merkd-install
# or via composer script:
composer merkd-install
```

## Quick start

```bash
# Install database and check environment
php vendor/bin/merkd-install

# Build all content
composer merkd -- --build

# Reset — wipe generated assets and rebuild from scratch
composer merkd -- --build --reset
```

Add to `composer.json`:

```json
{
    "scripts": {
        "merkd": "@php vendor/bin/merkd-build",
        "merkd-install": "@php vendor/bin/merkd-install"
    }
}
```

## Configuration

Create `config.php` in your project root:

```php
<?php

return [
    'merkd' => [
        'lang'         => 'en',
        'db'           => 'db/merkd.sqlite',
        'content_dir'  => 'content',
        'public_dir'   => 'public',
        'asset_dir'    => 'assets',        // subdirectory inside public_dir (default: assets)
        'base_url'     => '/assets/',      // derived automatically if omitted

        'jpg_quality'  => 85,
        'avif_quality' => 60,
        'image_sizes'  => [400, 800, 1600],
    ],
];
```

## Directory structure

```
project/
├── config.php
├── content/
│   ├── my-article.md
│   └── images/
│       └── photo.jpg          ← source image (relative to the .md file)
├── db/
│   └── merkd.sqlite
└── public/
    └── assets/                ← builder-owned, safe to wipe on --reset
        └── images/
            ├── photo_400px.jpg
            ├── photo_400px.avif
            ├── photo_800px.jpg
            ├── photo_800px.avif
            ├── photo_1600px.jpg
            ├── photo_1600px.avif
            ├── photo.jpg
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

Content here. Inline images are converted to <picture> automatically:

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

Any unrecognised key is stored in `attributes` (JSON) and accessible via `$post->getAttribute('key')`.

## Build modes

### Normal build

```bash
php vendor/bin/merkd-build --build
```

- Marks all documents `is_deleted = 1` at start
- Parses and upserts every `.md` file — no hash-based skip for documents
- Assets: hash check retained — unchanged images are not reprocessed
- After all files: orphan assets marked `is_deleted = 1`
- Never deletes files from disk

### Reset

```bash
php vendor/bin/merkd-build --build --reset
# or equivalently:
php vendor/bin/merkd-build --build --force
```

- Wipes contents of `public/assets/` (the directory itself is preserved — permissions are kept)
- Truncates `documents`, `assets`, and `document_assets` tables
- Performs a full rebuild from scratch

> Requires `asset_dir` to be non-empty. If empty, the wipe step is skipped with a warning.

## CLI options

```
Options:
  -b, --build             Run the full build pipeline (required)
  -r, --reset             Wipe asset dir + truncate DB, then rebuild
  -f, --force             Alias for --reset (retained for compatibility)
  -d, --db <path>         Override db path from config.php
  -c, --content <path>    Override content_dir from config.php
  -p, --public <path>     Override public_dir from config.php
  -l, --lang <lang>       Override default lang from config.php
  -h, --help              Show help
```

## merkd-install

```bash
php vendor/bin/merkd-install
```

1. Verifies directories (`content`, `db`, `public_dir`) — creates if missing
2. Installs `merkd.sqlite` from the bundled schema if not present
3. Checks PHP extensions: `pdo_sqlite`, `gd`, `fileinfo`
4. Reports image format support: JPEG, PNG, WebP, AVIF

## Using the client

```php
use Merkd\Client;

$client = new Client('db/merkd.sqlite', default_lang: 'en');

$posts = $client->getPosts(limit: 10, offset: 0, lang: 'en');
$total = $client->countPosts(lang: 'en');
$post  = $client->getPost(slug: 'my-article', lang: 'en');

echo $post->title;
echo $post->content_html;
echo $post->published->format('Y-m-d');
echo $post->getUrl();

// Hero image
if ($post->image !== null) {
    echo \Merkd\Utils\Html::image($post->image);
}
```

See [pavex/merkd-client](https://github.com/pavex/merkd-client) for full client documentation.

## Soft delete

The builder never physically removes records from the database:

- **Build start** — all documents marked `is_deleted = 1`
- **Per document** — upserted with `is_deleted = 0`
- **Per asset (skip)** — `restoreDeleted()` sets `is_deleted = 0` without reprocessing
- **Build end** — `markOrphanAssetsDeleted()` marks assets with no active document reference
- **Client** — all queries filter `AND is_deleted = 0`
- **Cleanup** — use `--reset` to physically truncate and rebuild

## Architecture

```
Build::run()
    ├── markAllDeleted()              documents: is_deleted = 1
    ├── (--reset) clearDirContents() + truncate()
    └── foreach .md file
            └── ContentBuilder::build(file_path)
                    ├── FileParser::parse()
                    │       ├── YAML front-matter  →  SourceRecord
                    │       ├── assetCallback(hero image)
                    │       └── MerkdParsedown::text(body)
                    │               └── inlineImage() → assetCallback(inline images)
                    │
                    ├── assetCallback(src, slug, lang)
                    │       ├── insertStub()                FK → documents satisfied
                    │       ├── hash check
                    │       │     unchanged → restoreDeleted()
                    │       │     changed   → ImageProcessor::process() → insert/update
                    │       └── insertDocumentAssetBinding()
                    │
                    └── buildDatastore::upsert()            is_deleted = 0
    └── markOrphanAssetsDeleted()     orphan assets: is_deleted = 1
```

## License

MIT — © 2026 Pavel Macháček
