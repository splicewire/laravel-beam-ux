# Entry images

A host can offer an entry-scoped raster image library by installing `splicewire/laravel-beam-media`, registering its provider and publishing/running its `beam-media-migrations`. Configure the media library's storage disk, public URL and `media-library.media_model` before providers boot (the Beam Media model is `Splicewire\Beam\Media\Models\Media`). Beam UX suggests this package; it does not require it in consuming hosts. Without the provider, the operations and picker are absent.

The editor's body envelope exposes `mediaLibrary: true` only when the actor has `ux.author` and the addressed Entry's `update` policy admits them. The same checks run on every library read and upload. The existing particle mount can expose:

- `GET /beam-ux-entries/{id}/media` → `{data: {items: [{id, name, url}]}}`
- `POST /beam-ux-entries/{id}/upload-image`, multipart `file` → `{data: {id, name, url}}`

Uploads accept JPEG, PNG, GIF, WebP or AVIF bytes up to 8 MiB. Stored names are UUIDs with extensions derived from detected MIME; the client filename is display text only. A library returns at most 50 images. Soft deletion retains the images for Restore; force deletion removes their records and files. SVG, HTML, URL imports and client-selected owner/morph/disk coordinates are refused. Files attach to the addressed Entry in the `entry-images` collection through the optional `beam_ux_entry_image_owner` adapter token (the base Entry morph mapping stays unchanged); one Entry's picker never lists another Entry's files. Uploading stores media, without saving a draft or publishing a page.

The default Beam Inertia adapter supplies session-authenticated transport and CSRF. An injected `UxBuilderClient` can instead implement `listImages(id)` and `uploadImage(id, file)` using the generated `EntryImageLibraryData` and `EntryImageData` shapes. Both are optional; missing methods keep the picker absent. Standalone canvas hosts may supply entry-bound `CanvasConfig.mediaLibrary` callbacks after their server authorizes the same Entry.

Choosing an image edits the Local Session's intrinsic `img.src`, Page settings `socialImage`, or an Authored Component prop whose declared interface type is `image`. Component schemas carry `x-component-prop-type: image`; a generic URI field does not imply an image picker. Existing attributes and settings survive the choice. Save and Publish continue through the ordinary versioned publication path.

The public choice types are generated from the PHP Data classes:

```sh
XDEBUG_MODE=off herd php scripts/export-entry-images.php > entry-images.generated.ts
```

Copy that output to the JS package's `beam-ux/src/generated/entry-images.ts` when changing the owning DTOs. The package fixture test checks generation without rewriting the fixture.
