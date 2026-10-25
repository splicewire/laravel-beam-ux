# Record Pages

A Record Page is an ordinary page with a parameter segment and a versioned Collection declaration. Its Collection must be explicitly offered by the page's Manifest Profile. The URL field must be a declared unique string property (`x-beam-unique: true`) included in the Collection Public Read projection; values used as URL pieces contain letters, numbers, underscores or hyphens and begin with a letter or number.

Run the published beam-ux migration `add_record_page_to_beam_ux_entries_table` in the same central or tenant connection as the entries. Like unique Collection writes, saving a Record Page requires a registered database schema anchor for that Collection on the entry connection, so concurrent declarations can serialize against one row. Existing pages stay unchanged. The published column holds the served declaration; draft settings stay in the entry's existing version history.

Send these settings through the normal entry save-draft operation, with the current base version and canvas body:

```json
{
  "title": "Post template",
  "parentId": "<posts-page-id>",
  "segment": "{slug}",
  "recordPage": {
    "collection": "posts",
    "field": "slug",
    "titleField": "title",
    "descriptionField": "summary",
    "socialImageField": "cover"
  }
}
```

`collection` names the offered Collection, not a resource class or a caller-supplied address. `field` names its unique string property. The three optional metadata fields name declared string properties; an omitted one retains the page's metadata. A save refuses an undeclared/non-unique field, an inconsistent segment, a second parameter child under one parent, or another Record Page for the same Collection in the site. Publishing rechecks the declaration and moves it with the page's pin.

For a public read of `/posts/hello`, literal children win. Otherwise the Collection is read through the visitor's existing resource read guard, tenant context and projection. An absent Record and a Record that visitor may not read have the same 404. No author credentials or browser-supplied Collection/filter replace that read. The page payload contains the projected Record as `record`, including its computed `url`; Field Bindings outside a Repeat use that Record (`fields.title`, for example). A resource Repeat's `url` points to its Collection's Record Page. The sitemap expands only guest-readable Records and omits literal collisions and the parameter placeholder.

The packaged SiteEntry passes the Record to its compiled body and renders its title, description and social image. Record responses are private/no-store. A host overriding SiteEntry must pass `record` into EntryBody's `bodyProps` too. Existing compiled bodies must be refreshed to receive root Record bindings. File mirrors preserve the declaration as a canonical JSON scalar in the `recordPage` frontmatter field. Redirects for renamed Records are a separate EP-30 capability.
