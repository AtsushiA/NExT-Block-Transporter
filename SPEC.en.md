# NExT Block Transporter Specification

**English** | [日本語](SPEC.md)

## 1. Background & Purpose

When you copy a block on the Gutenberg editor and paste it into the editor of another site, the `src` of an image block is pasted as the absolute URL of the source site. Because the actual image (the file itself) is never copied, the image does not appear on the paste destination.

Following the same idea as Illustrator's "Package" feature (which gathers a document together with its used fonts and linked images into a single folder), this plugin bundles the block markup and the actual referenced images into a single file, making it possible to move blocks across sites.

## 2. Overall Flow

```
[Source site]                              [Receiving site]
Select blocks in the editor
   ↓
Run "Export"
   ↓
serialize() the selected blocks
   ↓
POST to REST /export
   ↓
Detect & collect images on the server
   ↓
Zip up manifest.json + media/
   ↓
Download the ZIP -------------------→ Upload the ZIP file
                                          ↓
                                     POST to REST /import/start (extract ZIP, read manifest)
                                          ↓
                                     POST to REST /import/process-next, repeatedly
                                     (re-register images to the media library one at a time)
                                          ↓
                                     POST to REST /import/finish
                                     (replace URLs/IDs in the block markup and finalize it)
                                          ↓
                                     Turn into blocks with wp.blocks.rawHandler()
                                          ↓
                                     Insert into the post being edited
```

Import is split into three requests because processing a package with a large number of media items or large files in a single request can exceed `max_execution_time` or `memory_limit` on a slow or resource-constrained server, causing the request to terminate midway (see chapter 4 for details).

## 3. Package File Format

The extension is `.zip` (it may internally identify as `.nxbt`, but the actual body is a ZIP).

```
package.zip
├── manifest.json
└── media/
    ├── media-0.jpg
    ├── media-1.png
    └── ...
```

- The media storage folder is `media/`. Currently only images are detected, but to bundle PDFs, videos, audio, etc. in the future, `media/` is used instead of `images/`.

### manifest.json schema

```json
{
  "format_version": "1.0",
  "plugin": "next-block-transporter",
  "plugin_version": "0.2.0",
  "created_at": "2026-07-12T12:00:00+00:00",
  "source_site": "https://source-site.example.com",
  "block_markup": "<!-- wp:image {\"id\":123,...} --><figure>...<img src=\"https://source-site.example.com/wp-content/uploads/2026/07/photo.jpg\" class=\"wp-image-123\"/></figure><!-- /wp:image -->",
  "media": [
    {
      "index": 0,
      "original_url": "https://source-site.example.com/wp-content/uploads/2026/07/photo-1024x683.jpg",
      "original_filename": "photo.jpg",
      "archive_path": "media/media-0.jpg",
      "attachment_id": 123,
      "size_slug": "large",
      "resolved": true
    }
  ]
}
```

- `block_markup`: Holds, as-is, the raw markup produced by `wp.blocks.serialize()` of the selected blocks. On import, it is parsed with `parse_blocks()`, the following are rewritten to the values of the new attachments, and it is rebuilt with `serialize_blocks()` (innerBlocks and nested attributes are processed recursively):
  - `id` / `mediaId` / `ids` in the block attributes (comment JSON)
  - Media URLs and `wp-image-{id}` classes in the HTML
- The reason for rewriting even the IDs in the comment JSON is to prevent accidents where an old attachment ID that was used by a different media on the destination gets swapped for an unrelated image through editor operations (image resizing, etc.).
- The replacement of `wp-image-{id}` is done with a boundary check that ensures no digit follows immediately, preventing an incorrect replacement where the old ID matches part of a different ID (e.g., old ID `12` matching `wp-image-123`).
- `media[]` has one entry per URL reference in the markup. The actual file exported is the **original image of the attachment** (`wp_get_original_image_path()`; `get_attached_file()` for non-images), even when the reference is a scaled-down URL such as a thumbnail. When the same attachment is referenced in multiple sizes, the actual file is stored in the ZIP only once and the `archive_path` is shared (the import side also de-duplicates media registration to once).
- `media[].original_filename`: The actual file name on the export source. Used as the registered file name and media title on import (passed through `sanitize_file_name()` since it is external input). When missing (old-format package), it falls back to the sequential file name of `archive_path`.
- `media[].size_slug`: The slug of the registered image size that `original_url` pointed to (determined by matching the attachment metadata's `sizes[].file` against the file name in the URL; `full` if there is no match or it is the original size). Because the import side regenerates each size when registering the original image, the URL in the markup is replaced with the result of `wp_get_attachment_image_url( new_id, size_slug )`. If the corresponding size does not exist, or for non-images, it falls back to the full-size URL. The `sizeSlug` block attribute is kept as-is, so it stays consistent with the editor's "Resolution" setting.
- `media[].resolved`: `false` when the file itself could not be resolved on the export source. In that case the import side keeps the original URL as-is (i.e., the media is missing but the block structure is not broken).
- `media[].attachment_id`: The attachment ID (the media's post ID) on the export source. If the import destination has **an attachment with the same ID whose original image file name matches `original_filename`**, that existing attachment is reused instead of registering a new one (preventing duplicate media when migrating between sites of the same lineage, such as staging → production). The ID alone is not trusted because the ID of media added later on the source may be used by an unrelated post or a different image on the destination. The file name is compared using the basename of `wp_get_original_image_path()` (`get_attached_file()` for non-images), the same as on export. If there is no ID or it does not match, the media is registered as new as before.
- Compatibility: Packages from development before the folder rename (`images` key) are accepted as a fallback on import.

## 4. REST API

### POST `/wp-json/next-block-transporter/v1/export`

| Parameter | Type | Description |
|---|---|---|
| block_markup | string | Serialized HTML of the selected blocks |

Response:
```json
{ "download_url": "https://.../wp-content/uploads/nbt-tmp/xxx.zip", "filename": "xxx.zip" }
```

### Import API (split into three requests)

Processing a package with a large number of media items or large files in a single request
(extracting the ZIP + sideloading every media item + regenerating image sizes) can exceed
`max_execution_time` or `memory_limit` on a slow or resource-constrained server, causing the
request to terminate midway so the response is never valid JSON and the import fails (this is
easy to miss in a fast local environment). To avoid this, import is split into three requests:
"extract", "register media (one at a time)", and "finalize the markup". Each request also
relaxes the time/memory limits via `NBT_FS::raise_processing_limits()`.

Session state (the extraction path, the queue of pending media, the rewrite maps, etc.) is kept
server-side in a state file directly under the extraction folder (`.nbt-import-state.json`,
not publicly listable) and carried across calls via `session_id`. The `session_id` is the
hard-to-guess token portion of the extraction folder name (validated as alphanumeric-only via
`NBT_Import::SESSION_ID_PATTERN`).

#### POST `/wp-json/next-block-transporter/v1/import/start`

`multipart/form-data`, field name `package` (the ZIP file). Extracts the ZIP and reads
manifest.json to start a session. Media is not registered yet.

Response:
```json
{ "session_id": "xxxxxxxxxxxxxxxxxxxx", "total": 3 }
```

#### POST `/wp-json/next-block-transporter/v1/import/process-next`

Registers exactly one unprocessed media item from the session to the media library. The caller
(`assets/js/editor.js`) calls this repeatedly until `remaining` reaches `0` (if `total` is `0`
there is no media, so it is never called and the flow proceeds straight to finish).

Parameters: `{ "session_id": "..." }`

Response:
```json
{
  "remaining": 2,
  "item": { "original_url": "...", "new_url": "...", "new_attachment_id": 456, "reused": false }
}
```

`reused` is `true` when existing media on the import destination was reused instead of registering new media.

#### POST `/wp-json/next-block-transporter/v1/import/finish`

Call after all media has been registered (once `remaining` reaches `0`) to finalize and return
the rewritten block markup. The session's temporary folder is deleted here. Calling this while
media is still pending returns an error (`nbt_session_incomplete`).

Parameters: `{ "session_id": "..." }`

Response:
```json
{
  "block_markup": "the rewritten block markup",
  "imported_media": [
    { "original_url": "...", "new_url": "...", "new_attachment_id": 456, "reused": false }
  ]
}
```

Permissions: All of the above require the `edit_posts` capability (`current_user_can`).

## 4.5 Security Policy

The package (ZIP) and manifest are treated as **untrusted input** brought in from outside.

- **File resolution on export**: When resolving the actual file from a URL in the markup, it verifies that the real path stays within the uploads directory (`NBT_FS::within_dir()`), so that `../` etc. cannot point outside uploads (to `wp-config.php`, etc.).
- **ZIP extraction (zip-slip / zip-bomb countermeasures)**: Before extracting all entries at once with `extractTo()`, it inspects every entry and rejects entry names containing an absolute path, `../`, or a drive letter, as well as paths pointing outside the extraction destination. It also caps the number of entries (max 2000) and the total size after extraction (max 500MB).
- **The `archive_path` in the manifest**: As external input, it is verified to stay within the extraction destination before being read (so path traversal cannot make it copy an arbitrary file into the media library).
- **Extension-spoofing countermeasure**: On media registration, it checks the consistency between the file's actual content and the extension with `wp_check_filetype_and_ext()`, and accepts only formats allowed by `get_allowed_mime_types()`.
- **Protection of temporary files**: `uploads/nbt-tmp/` is generated with hard-to-guess file names (20-character token), directory listing is disabled with `index.php`, and files exceeding the TTL (1 hour) are deleted by cron and at generation time.
  * Note: since the download URL is on a public directory, a third party who knows the URL can retrieve it within the TTL. When handling highly confidential packages, consider changing to authenticated streaming delivery (future task).
- **Validation of the import session ID**: The `session_id` accepted by `/import/process-next` and `/import/finish` is validated as alphanumeric-only (`NBT_Import::SESSION_ID_PATTERN`), and the resulting real path is double-checked with `NBT_FS::within_dir()` to confirm it stays inside the temporary working folder before being treated as the extraction folder (preventing normalization bypasses into someone else's hard-to-guess session).

## 5. Not Yet Supported / Future Tasks (TODO)

- [x] Recursive detection of cases where images are nested inside `background-image` or innerBlocks, such as gallery / cover / media-text blocks
      — On export, build a tree with `parse_blocks()` and recursively search innerBlocks. In addition to `<img>`, collect from `background-image:url(...)` and the `url`/`mediaUrl` attributes of cover/media-text etc. (limited to core blocks that carry the media itself). Duplicate URLs are de-duplicated.
- [ ] Extending detection/collection to non-image media types such as video, audio, and PDF
      (the package format side already supports it via the `media/` folder and `media` key)
- [x] Periodic cleanup (cron) of temporary ZIPs and extraction folders (`wp-content/uploads/nbt-tmp/`)
      — Daily cron + cleanup of expired items (TTL 1 hour) at generation time. Listing disabled with `index.php`.
- [x] Timeout/memory measures for large numbers of images and large files
      — Import is now split into per-media-item registration steps (`/import/start` →
      `/import/process-next` ×N → `/import/finish`), so a single request only ever does the
      heavy work (sideload + image size regeneration) for one file. Both export and import also
      relax time/memory limits via `NBT_FS::raise_processing_limits()`.
      * Note: ZIP compression on the export side (`ZipArchive::close()`) is still a single
      one-shot operation within one request, so streaming export remains a future task for
      extremely large/numerous selections.
- [x] Preventing duplicate registration with existing media on duplicate import within the same site
      — Existing media whose attachment ID (post ID) and original image file name both match is reused.
      Note: an image whose content was replaced without changing its file name will resolve to the existing one (hash comparison remains a future consideration).
- [ ] Reviewing the wording for edge cases in the export UI, such as "selected blocks are empty" and "0 images"
- [ ] Final decision on whether to brand with the custom `.nxbt` extension

## 6. Naming / Branding Notes

- Plugin name: **NExT Block Transporter**
- During consideration, candidates such as "NExT Block Package" and "NExT Block Package Transporter" were also raised, but "NExT Block Transporter" was decided on to avoid slug length and redundancy of meaning.
- Plugin slug: `next-block-transporter`
- Function/class prefix: `NBT_` / `nbt_`
