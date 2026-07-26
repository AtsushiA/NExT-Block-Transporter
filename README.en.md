# NExT Block Transporter

**English** | [日本語](README.md)

A WordPress plugin that packages Gutenberg blocks together with their images so they can be carried over to another site.

## Overview

When you copy & paste a block on the Gutenberg editor, only the image path is included — the actual image file is not. As a result, the images do not appear when you paste the block into another site.

Like Illustrator's "Package" feature, this plugin exports the markup of the selected blocks together with the actual referenced image files into a single ZIP file (`manifest.json` + `media/`).

On the receiving site, simply uploading that package file automatically performs the following sequence:

1. Re-uploads the images to the media library
2. Rewrites the image URLs and attachment IDs in the block markup to the new ones
3. Restores the blocks into the post being edited

## Requirements

| Item | Requirement |
| --- | --- |
| WordPress | 6.4 or later (verified with 6.7) |
| PHP | 7.4 or later |

## Installation

1. Place the plugin files in `wp-content/plugins/next-block-transporter`
2. Activate NExT Block Transporter from "Plugins" in the admin dashboard
3. Use it from the top-right menu of the post editor, or from the added sidebar

## Usage

### Export (source site)

1. Select the blocks you want to carry over in the post editor
2. Click "Export selected blocks" in the "NExT Block Transporter" sidebar
3. The package file (ZIP) is downloaded

### Import (receiving site)

1. Open the "NExT Block Transporter" sidebar in the post editor
2. Select the package file (`.zip` / `.nxbt`) in the "Import" panel
3. The images are re-registered to the media library, and the blocks are inserted into the post being edited

## Specification

For details on the package format, REST API, and internal processing, see [SPEC.en.md](SPEC.en.md).

## Development

The coding conventions follow the WordPress Coding Standards.
See [phpcs.xml.dist](phpcs.xml.dist) for the configuration.

```sh
composer install
composer run phpcs
```

### Running the PHPUnit tests

The setup uses the WordPress core test suite ([wp-phpunit/wp-phpunit](https://github.com/wp-phpunit/wp-phpunit)) via Composer,
so neither wp-env (Docker) nor `svn` is required. All you need is:

1. A dedicated, empty MySQL database for the tests (separate from any other database).
2. Run `vendor/bin/phpunit` (or `composer run phpunit`) with the DB connection details passed as environment variables.

```sh
composer install
WP_TESTS_DB_NAME=nbt_phpunit_test \
WP_TESTS_DB_USER=root \
WP_TESTS_DB_PASSWORD=root \
WP_TESTS_DB_HOST=127.0.0.1 \
composer run phpunit
```

| Environment variable | Description | Default when omitted |
| --- | --- | --- |
| `WP_TESTS_DB_NAME` | Test database name | `nbt_phpunit_test` |
| `WP_TESTS_DB_USER` | DB user | `root` |
| `WP_TESTS_DB_PASSWORD` | DB password | `root` |
| `WP_TESTS_DB_HOST` | DB host (`host:port` or `host:/path/to/socket.sock` are both accepted) | `127.0.0.1` |
| `WP_TESTS_ABSPATH` | Path to the WordPress core (ABSPATH) used for the tests | Derived automatically relative to this plugin's own path (assumes development happens under `wp-content/plugins/`) |

If you're developing this plugin inside an already-working WordPress environment (e.g. Local by Flywheel),
`WP_TESTS_ABSPATH` doesn't need to be set — that WordPress core is used automatically.
CI instead points `WP_TESTS_ABSPATH` at a separately downloaded WordPress core (see [.github/workflows/ci.yml](.github/workflows/ci.yml)).

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

## Changelog

### 0.3.0

- [ Bug Fix ] Fixed an issue where importing a package with many media items or large files would time out and fail on slow or resource-constrained servers

### 0.2.0

- [ Feature ] Recursively collect nested blocks and background images of gallery / cover / media-text blocks
- [ Feature ] Carry over the original file name to imported media
- [ Change ] Export the original image instead of a scaled-down version; the resolution is specified by the block settings
- [ Fix ] Fix a bug where selected blocks could not be exported
- [ Security ] Add countermeasures against path traversal / zip-slip / extension spoofing, and protection of temporary files
- [ Other ] Add an uninstall process that removes temporary files and cron on plugin deletion

### 0.1.0

- Initial skeleton created
