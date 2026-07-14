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
phpcs
```

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

## Changelog

### 0.2.0

- [ Feature ] Recursively collect nested blocks and background images of gallery / cover / media-text blocks
- [ Feature ] Carry over the original file name to imported media
- [ Change ] Export the original image instead of a scaled-down version; the resolution is specified by the block settings
- [ Fix ] Fix a bug where selected blocks could not be exported
- [ Security ] Add countermeasures against path traversal / zip-slip / extension spoofing, and protection of temporary files
- [ Other ] Add an uninstall process that removes temporary files and cron on plugin deletion

### 0.1.0

- Initial skeleton created
