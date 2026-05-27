# AGENT.md — pavex/merkd

Instructions for AI agents working with the `pavex/merkd` package.

---

## Package identity

- **Name:** `pavex/merkd`
- **Namespace:** `Merkd\Builder\`
- **Role:** Markdown-based CMS — parses `.md` files, processes images, writes SQLite database
- **Components:** Includes `Merkd\Builder` (this repo) and `Merkd\Client` (via `pavex/merkd-client` dependency)

---

## Entry points

### CLI

#### Installation & Check
```bash
php vendor/bin/merkd-install
```
Verifies directories, installs SQLite schema, and checks PHP extensions (`pdo_sqlite`, `gd`, `fileinfo`).

#### Build pipeline
```bash
php vendor/bin/merkd-build --build [--reset] [--db path] [--content path] [--public path] [--lang code]
```
The binary locates `config.php` by walking up from the current directory. CLI options override config values.
Use `--reset` (or `-r`) to wipe the asset directory and truncate the database before rebuilding.

### Programmatic (Builder)

```php
use Merkd\Builder\Config;
use Merkd\Builder\BuildContainer;
use Merkd\Builder\Build;

$cfg = require 'config.php';
$config = new Config($cfg['merkd'] ?? [], __DIR__);
$container = new BuildContainer($config);

$build = new Build($container);
$build->setOutput(fn(string $line) => print($line . PHP_EOL));
$result = $build->run();         // normal build
$result = $build->run(true);     // reset & rebuild
```

`$result` is a `BuildResult` with `int $added`, `int $updated`, `int $skipped` properties.

---

## Class map

| Class | Role | Notes |
|---|---|---|
| `Merkd\Builder\Build` | Orchestrator | Handles build lifecycle, soft-deletes, and orchestration |
| `Merkd\Builder\BuildContainer` | Composition root | Lazy-instantiates all dependencies |
| `Merkd\Builder\Config` | Value object | Holds all resolved paths and settings |
| `Merkd\Builder\Content\ContentBuilder` | Scans content dir, writes documents to DB | Uses `MerkdParsedown` for MD → HTML |
| `Merkd\Builder\Content\FileParser` | Parses single `.md` file | YAML front-matter + Markdown → SourceDataset |
| `Merkd\Builder\Image\ImageProcessor` | Generates JPG/AVIF variants via GD | Managed by `Build` via `assetCallback` |
| `Merkd\Builder\Result\BuildResult` | Added/updated/skipped counters | Merged from content + image builds |
| `Merkd\Builder\Datastore\BuildPdoDatastore` | SQLite backend for documents | |
| `Merkd\Builder\Datastore\AssetPdoDatastore` | SQLite backend for assets | |

**Instantiation rule:** Only `Config`, `BuildContainer`, and `Build` should be instantiated in application code for the builder. Everything else is wired by `BuildContainer`.

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
public_dir      string   — absolute path to public output root
asset_dir       string   — subdirectory for assets inside public_dir (e.g. "assets")
base_url        string   — URL prefix for image src/srcset (raw, use getBaseUrl())
default_lang    string   — default language code
jpg_quality     int      — 0–100
avif_quality    int      — 0–100
image_sizes     array    — pixel widths, e.g. [400, 800, 1600]
```

Methods:

```php
getBaseUrl(): string         // normalized, always starts and ends with /
getPublicAssetDir(): string  // absolute path to public_dir/asset_dir
getContentAssetDir(): string // absolute path to content_dir/asset_dir
isAvifSupported(): bool      // true when GD has libavif
isAssetDirSafe(): bool       // true if asset_dir is not empty (safe for wipe)
isBaseUrlDerived(): bool     // true when base_url was not set explicitly
```

**Config key mapping** (from `config.php` → Config property):

| config.php key | Config property |
|---|---|
| `db` | `db_path` |
| `content_dir` | `content_dir` |
| `public_dir` | `public_dir` |
| `asset_dir` | `asset_dir` |
| `base_url` | `base_url` |
| `lang` | `default_lang` |
| `jpg_quality` | `jpg_quality` |
| `avif_quality` | `avif_quality` |
| `image_sizes` | `image_sizes` |

---

## Build pipeline flow

```
Build::run()
  ├── markAllDeleted()                documents: is_deleted = 1
  ├── (--reset) wipe asset dir + truncate tables
  ├── ContentBuilder::build()
  │     ├── Scans content_dir recursively for *.md files
  │     ├── For each file: FileParser::parse() → SourceDataset
  │     │     ├── Hash check for images (restore if unchanged)
  │     │     └── ImageProcessor::process() for new/changed images
  │     └── BuildPdoDatastore::upsert() → is_deleted = 0
  │
  └── markOrphanAssetsDeleted()       assets with no document binding: is_deleted = 1
```

Documents are always upserted (no hash skip for records). Images use CRC32 hash of raw content for change detection.

---

## FileParser — front-matter keys

`Merkd\Builder\Content\FileParser` maps YAML front-matter to `SourceDataset` fields.

Known keys (handled explicitly):

```
slug, lang, title, locale, perex, tags, image, published, author, hidden, translations
```

Any unknown key is collected into `attributes` JSON field. Access in client: `$post->getAttribute('key', default)`

### Slug resolution

- Explicit `slug:` in front-matter → used as-is
- No `slug:` → filename without extension (e.g. `my-post.md` → `my-post`)

### Tags format

YAML array preferred, semicolon-separated string also supported. Stored internally as semicolon-separated.

---

## Image processing details

`ImageProcessor` generates variants using PHP GD:

- **Input:** JPG, JPEG, PNG, WebP (no GIF)
- **Output:** `{name}_{size}px.jpg`, `{name}_{size}px.avif`, `{name}.jpg`, `{name}.avif`
- **Location:** `public_dir/asset_dir/images/...`
- **ShrinkOnly:** never upscales below source size
- **AVIF:** automatically skipped if GD lacks support; client uses JPG fallback

---

## BuildDatastoreInterface

For custom storage backends, implement `BuildDatastoreInterface` and swap via a `BuildContainer` subclass.

---

## Output callback

`Build` and both sub-builders support a progress callback via `setOutput()`. Used for CLI output.

---

## Coding rules (this project)

Follow `.project/claude/skills/php-style.md`. Key points:

- No constructor property promotion — always declare properties explicitly
- `snake_case` for local variables and parameters, `camelCase` for object variables
- Explicit visibility on all properties and methods
- Airy braces: blank line after `{` (if props follow) or two blank lines (if methods follow). One blank line before `}`.
- Mandatory class header with `@author pavex@ines.cz`
- Comments in English

---

## What NOT to do

- Do not instantiate `ContentBuilder` or `ImageBuilder` directly — use `BuildContainer`
- Do not bypass `BuildContainer` — it handles lazy wiring and ensures shared datastore instances
- Do not read `$config->base_url` directly for URL generation — always use `$config->getBaseUrl()`
- Do not add GIF to supported formats — animated GIFs lose animation silently
- Do not run `Build::run()` without verifying `content_dir` and `db_path` exist (or use `merkd-install`)
- Do not manually delete files from the asset directory; use `--reset` instead

---

## Dependency overview

```
pavex/merkd
├── pavex/utils          (Record base class, FileSystem, Html)
├── pavex/getopt         (CLI option parsing)
├── pavex/merkd-client   (Database read-side, included as dependency)
├── erusev/parsedown     (Markdown → HTML)
└── symfony/yaml         (YAML front-matter parsing)
```

---

## Integration checklist

1. Run `php vendor/bin/merkd-install` to setup environment and database
2. Configure `asset_dir` if you want a custom output subdirectory
3. Use `composer merkd -- --build` for regular updates
4. For CI: use `--reset` only when you want a clean slate; normal build is faster for content updates

