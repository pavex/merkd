# AGENT.md — pavex/merkd-build

Instructions for AI agents working with the `pavex/merkd-build` package.

---

## Package identity

- **Name:** `pavex/merkd-build`
- **Namespace:** `Merkd\Builder\`
- **Role:** CLI build pipeline — parses `.md` files, processes images, writes SQLite database
- **Companion:** `pavex/merkd` reads the database at runtime; this package writes it

---

## Entry points

### CLI (primary)

```bash
php vendor/bin/merkd-build --build [--force] [--db path] [--content path] [--public path] [--lang code]
```

The binary locates `config.php` by walking up from the current directory. CLI options override config values.

### Programmatic (secondary)

```php
use Merkd\Builder\Config;
use Merkd\Builder\BuildContainer;
use Merkd\Builder\Build;

$cfg = require 'config.php';
$config = new Config($cfg['merkd'] ?? [], __DIR__);
$container = new BuildContainer($config);

$build = new Build($container);
$build->setOutput(fn(string $line) => print($line . PHP_EOL));
$result = $build->run();         // incremental
$result = $build->run(true);     // force rebuild
```

`$result` is a `BuildResult` with `int $added`, `int $updated`, `int $skipped` properties.

---

## Class map

| Class | Role | Notes |
|---|---|---|
| `Merkd\Builder\Build` | Orchestrator | Calls ContentBuilder then ImageBuilder |
| `Merkd\Builder\BuildContainer` | Composition root | Lazy-instantiates all dependencies |
| `Merkd\Builder\Config` | Value object | Holds all resolved paths and settings |
| `Merkd\Builder\Content\ContentBuilder` | Scans content dir, writes documents to DB | |
| `Merkd\Builder\Content\FileParser` | Parses single `.md` file | YAML front-matter + Markdown → SourceDataset |
| `Merkd\Builder\Image\ImageBuilder` | Processes images collected by ContentBuilder | |
| `Merkd\Builder\Image\ImageProcessor` | Generates JPG/AVIF variants via GD | |
| `Merkd\Builder\Result\BuildResult` | Added/updated/skipped counters | Merged from content + image builds |
| `Merkd\Builder\Datastore\BuildPdoDatastore` | SQLite backend for documents | |
| `Merkd\Builder\Datastore\AssetPdoDatastore` | SQLite backend for assets | |

**Instantiation rule:** Only `Config`, `BuildContainer`, and `Build` should be instantiated in application code. Everything else is wired by `BuildContainer`.

---

## Config object

`Merkd\Builder\Config` is a readonly value object. Constructed from an array + project root:

```php
new Config(array $cfg, string $root)
```

Public properties:

```
root_dir        string   — absolute project root
db_path         string   — absolute path to SQLite file
content_dir     string   — absolute path to content directory
public_dir      string   — absolute path to public assets output
base_url        string   — URL prefix for image src/srcset (raw, use getBaseUrl())
default_lang    string   — default language code
jpg_quality     int      — 0–100
avif_quality    int      — 0–100
image_sizes     array    — pixel widths, e.g. [400, 800, 1600]
```

Methods:

```php
getBaseUrl(): string         // normalized, always starts and ends with /
getPublicDir(): string       // normalized, unix slashes, no trailing /
isAvifSupported(): bool      // true when GD has libavif
isBaseUrlDerived(): bool     // true when base_url was not set explicitly in config
```

**Config key mapping** (from `config.php` → Config property):

| config.php key | Config property |
|---|---|
| `db` | `db_path` |
| `content_dir` | `content_dir` |
| `public_dir` | `public_dir` |
| `base_url` | `base_url` |
| `lang` | `default_lang` |
| `jpg_quality` | `jpg_quality` |
| `avif_quality` | `avif_quality` |
| `image_sizes` | `image_sizes` |

---

## Build pipeline flow

```
Build::run()
  ├── ContentBuilder::build($force)
  │     ├── Scans content_dir recursively for *.md files
  │     ├── For each file: FileParser::parse() → SourceDataset
  │     ├── Hash comparison (skip if unchanged, unless $force)
  │     ├── BuildPdoDatastore::upsert() → documents table
  │     └── Collects all image paths referenced by content
  │
  └── ImageBuilder::build($collected_images, $force)
        ├── For each image path: check if already processed (hash)
        ├── ImageProcessor::process() → generates JPG + AVIF variants
        └── AssetPdoDatastore::upsert() → assets table
```

Change detection uses CRC32 hash of raw file content. `--force` bypasses this entirely.

---

## FileParser — front-matter keys

`Merkd\Builder\Content\FileParser` maps YAML front-matter to `SourceDataset` fields.

Known keys (handled explicitly):

```
slug, lang, title, locale, perex, tags, image, published, author, hidden, translations
```

Any unknown key is collected into `attributes` JSON field. Example:

```yaml
---
title: My Post
reading-time: 5      # → stored as attributes['reading-time']
hide-image: true     # → stored as attributes['hide-image']
---
```

Access in client: `$post->getAttribute('reading-time', 0)`

### Slug resolution

- Explicit `slug:` in front-matter → used as-is
- No `slug:` → filename without extension (e.g. `my-post.md` → `my-post`)

### Tags format

YAML array preferred:

```yaml
tags:
  - design
  - philosophy
```

Stored internally as semicolon-separated string. The client parses this back into `array<string>`.

---

## Image processing details

`ImageProcessor` generates variants using PHP GD:

- **Input:** JPG, JPEG, PNG, WebP (no GIF — only static first frame, animation lost)
- **Output:** `{name}_{size}px.jpg`, `{name}_{size}px.avif`, `{name}.jpg`, `{name}.avif`
- **ShrinkOnly:** never upscales below source size
- **Alpha:** preserved in AVIF, flattened to white in JPG
- **AVIF:** only when `extension_loaded('gd')` and `Image::isTypeSupported(ImageType::AVIF)` — automatically skipped otherwise

Image variants and sizes are stored in `assets.metadata` as:

```json
{"sizes": [400, 800, 1600], "variants": ["jpg", "avif"]}
```

This is what `AssetDataset::hasVariant()` and `Html::image()` read.

---

## BuildDatastoreInterface

When writing a custom storage backend:

```php
use Merkd\Builder\Datastore\BuildDatastoreInterface;
use Merkd\Builder\Datastore\Dataset\SourceDataset;

interface BuildDatastoreInterface
{
    public function findDocument(string $slug, string $lang): ?SourceDataset;
    public function upsertDocument(SourceDataset $dataset): string; // 'added'|'updated'|'skipped'
}
```

Swap via `BuildContainer` subclass:

```php
class MyBuildContainer extends BuildContainer
{
    public function getBuildDatastore(): BuildDatastoreInterface
    {
        return new MyBuildDatastore($this->getConfig()->db_path);
    }
}
```

---

## Output callback

`Build` and both sub-builders support a progress callback:

```php
$build->setOutput(function (string $line): void {
    echo $line . "\n";
});
```

Set it before calling `run()`. Used for CLI output. Safe to omit — output is silently dropped when no callback is set.

---

## Coding rules (this project)

Follow `.project/claude/skills/php-style.md`. Key points:

- No constructor property promotion — always declare properties explicitly
- `snake_case` for local variables and parameters, `camelCase` for object variables
- Explicit visibility on all properties and methods
- No column alignment
- Comments in English

---

## What NOT to do

- Do not instantiate `ContentBuilder` or `ImageBuilder` directly — use `BuildContainer`
- Do not bypass `BuildContainer` — it handles lazy wiring and ensures shared datastore instances
- Do not read `$config->base_url` directly for URL generation — always use `$config->getBaseUrl()`
- Do not add GIF to supported formats — animated GIFs lose animation silently
- Do not write to the `documents` or `assets` tables outside of the datastore classes
- Do not run `Build::run()` without verifying `content_dir` and `db_path` exist first (the bin script does this; programmatic usage must replicate it)

---

## Dependency overview

```
pavex/merkd-build
├── pavex/utils          (Record base class, FileSystem, Html)
├── pavex/getopt         (CLI option parsing)
├── erusev/parsedown     (Markdown → HTML)
└── symfony/yaml         (YAML front-matter parsing)
```

Image processing uses PHP's built-in GD extension — no additional image library is required.

---

## Integration checklist

When integrating the build pipeline into a new project:

1. Ensure `db/schema.sql` from `pavex/merkd` has been run against the database
2. Verify `content_dir` exists before calling `Build::run()`
3. Set `base_url` explicitly in `config.php` — derived value may be wrong for non-standard layouts
4. Register `php vendor/bin/merkd-build --build` in the project's publish/deploy script
5. For CI: use `--force` only on full deploys; incremental is faster for content-only updates
