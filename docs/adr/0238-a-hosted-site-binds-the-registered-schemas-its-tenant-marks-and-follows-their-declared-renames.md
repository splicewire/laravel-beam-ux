# ADR-0238 — A Hosted site binds the registered schemas its tenant marks, and follows their declared renames

Status: **accepted**. Decided in editor-parity ticket 18, 2026-10-16, under the owner's standing instruction of that day
to accept every recommended answer. There was no live exchange; the ticket records each question, its options and the
answer taken.
Date: 2026-10-16
Builds on: **ADR-0229** (a block binds to a Collection; the Manifest Profile lists Collections), **ADR-0228** decisions 7
and 8 (a profile per realm; a save refuses only what it changes), **ADR-0233** decision 4 (a parent declares its
children's Page Fields), **ADR-0232** (Releases hold entries, and a version holds what a guest sees), **ADR-0227** (a
Hosted site holds blocks, not code), `splicewire/laravel-beam docs/adr/0235-a-registered-schema-types-a-records-fields-and-never-declares-a-resource.md`
(the resource, its vocabulary, its Public Read and the keywords).
Amends: ADR-0233 decision 4 (a Hosted site's parent names a registered schema). `splicewire/tower`
`docs/adr/0008-a-tenants-site-is-a-hosted-site-served-from-its-own-schema-on-its-own-origin.md` decision 9 (the Site
section seats Collections). Answers ADR-0229 decision 1's open clause on registered schemas. Each carries a note pointing
here.
Companions: beam JS `CONTEXT.md` (**Collection**, **Public Read** and **Page Field** amended).

## Context

ADR-0229 made a Collection whatever the Manifest Profile offers for binding, an entry's child pages or a particle
resource, and left registered schemas to editor-parity ticket 18. ADR-0233 decision 4 left a Hosted site's Page Fields to
the same ticket. A Hosted site's author writes no code (ADR-0227 decision 1), so neither a resource nor a Page Fields
Data class is theirs to declare.

`splicewire/laravel-beam` ADR-0235 settles the data side. A registered schema never declares a resource; it types a
Record's fields. A Collection is a registered schema its author marks with `x-beam-collection`, and one declared resource
serves every Collection's Records, with the vocabulary, Public Read, uniqueness and relations its latest version
declares.

What this ADR needs from the registry, measured 2026-10-16:

- **Versions are write-once,** and a rename is declared on the new version, as `x-migrate-from` on a top-level property
  (`schemastud/laravel-data-schemas` `src/Migration/Rungs/DeclaredMappingRung.php:13-17`). A dropped field is removed
  from a Record when it migrates (`src/Migration/Rungs/StructuralRung.php:12-17`).
- **The registry's surface is the tenant System page:** a raw JSON editor with a live form preview, under `require.admin`
  on the API and `schemas.manage` on the tab (`~/Herd/splicewire-app/ui/src/features/schema-registry/SchemaRegistryPage.tsx:1-11`,
  `routes/tenant.php:563-567`).
- **The tenant Site section seats Entries, Theme and Releases,** under `site.edit` (`splicewire/tower` ADR-0008
  decisions 7 and 9).
- **No central registry holds a schema** at the flagship, the beam site or audiostud.
- **A Scaffold Pack copies registered schemas only under `content-schema/`.** Tenant-authored schemas "must never leak
  across a pack copy" (`splicewire/tower` `src/Support/ScaffoldPackContentServiceProvider.php:43-55`).

## Decision

1. **A Hosted-site profile offers its tenant's marked Collections.** Each appears at its latest version, with its label,
   its fields as JSON Schema, its vocabulary, and whether it declares a Public Read. They sit beside the tenant's
   child-page Collections and the resources the host offers. The central profile offers none: the central site and the
   satellites declare their Collections in code, and a central registry surface is deferred.
2. **A binding names a registered Collection by its Schema Stem,** without a version, so a Repeat, a Field Binding or a
   Record Page follows the Collection across versions.
3. **A Hosted site's parent page names one of its tenant's registered schemas for its children's Page Fields,** as a
   central parent names a Data class (ADR-0233 decision 4). The parent's page settings choose it, and the parent's
   version holds the choice. The schema's fields are the child-page Collection's Record fields and vocabulary, the fields
   each child's page settings shows, and what the save checks. It needs no Collection mark: the child pages are the
   Collection.
4. **A binding follows the renames a version declares.**
   - **Renames.** Wherever a field is named, a name a later version renamed with `x-migrate-from` reads the renamed
     field. That covers a Field Binding, a text node's field path, a Data Condition's rule, a saved query's filter and
     sort, and a Page Field value held in an older version. The canvas shows the current name, and the page's next save
     writes it.
   - **Drops.** A field a later version dropped reads as empty (ADR-0229 decision 3). The canvas marks the node and the
     doctor counts it, under ADR-0228 decision 8's rule that an untouched node is reported, not refused.
   - **Before registering.** A version that drops fields a published page binds lists those pages before it is frozen,
     and the author confirms.
   - **The one refusal.** Registering a version is refused when it drops, or stops marking unique, the field a published
     Record Page binds by, because every Record's URL would stop answering. The page rebinds first.
5. **A Record Page over a registered Collection binds by the Record's id, or by a field its schema marks unique**
   (`x-beam-unique`). ADR-0229 decision 8 applies as for any Collection.
6. **The tenant Site section gains Collections.**
   - **Defining a Collection.** A field editor lists the Collection's fields (name, type, required, public, filterable,
     sortable, unique, relation) and registers the next version on each save. The System page stays the registry's raw
     view, with every schema, version and migration count.
   - **Its Records.** Each Collection lists its Records in Frame's list, with its declared filter panel, and opens each in
     Frame's form, which is the Collection's latest schema.
   - **Who.** Creating, editing and deleting Records needs `site.edit`, as the site does. Changing a Collection's fields
     needs `schemas.manage`, as registering any schema does. Both default to Admin.
   - **Saving.** A Record is live when it is saved, like any resource row. A draft is a status field that the
     Collection's Public Read rule tests. Scheduled publishing is deferred, and Records aren't in a Release (ADR-0232).
7. **A starter site doesn't carry its registered Collections yet.** A copied binding or Page Fields choice that names a
   schema the target lacks renders its empty state (ADR-0229 decision 9), or shows the fields as undeclared (ADR-0233
   decision 3), and is reported. Editor-parity ticket 20 decides how a Scaffold Pack carries them.
   *Answered 2026-10-16 by `splicewire/tower docs/adr/0009-a-starter-sites-schemas-travel-re-minted-on-each-new-tenants-own-origin-with-their-sample-records.md`:
   a pack carries every registered schema a copied entry names, re-minted on the new tenant's own origin with every
   reference rewritten, and a carried Collection's Records. This decision's empty state and report stay the behaviour
   for anything a pack holds back.*

## Consequences

- **ADR-0229 decision 1's open clause is answered:** a registered schema's Records are a Collection when its author marks
  it, on a Hosted site.
- **ADR-0233 decision 4 is amended:** a Hosted site's parent names a registered schema.
- **`splicewire/tower` ADR-0008 decision 9 is amended:** the Site section seats Collections beside Entries, Theme and
  Releases.
- **The canvas reads registered Collections like any other** (ADR-0229 decision 10): live Records, read as the author, a
  warning when a public page's Collection declares no Public Read, and a Record picker on a Record Page.
- **A Preview reads Records as they are now** (ADR-0232), a registered Collection's included. A field renamed since a
  Release's versions were saved reads through decision 4.
- **The save check reads the latest version.** A Field Binding, a Data Condition or a saved query that names a field the
  latest version neither declares nor renamed is refused when the save adds or changes that node (ADR-0228 decision 8).
- **New work in beam JS** is the field editor's Collection screens if they live in the shared kit, and the canvas's marks
  for renamed and dropped fields. The binding inspector reads registered Collections from the profile with no change of
  its own.
- **What a Record's URL change leaves behind is editor-parity ticket 19's.** A registered Collection's Records are written
  through `ParticleWriter`, as any particle is, so they emit the same write signal.

## Considered and not taken

- **Every registered schema offered as a Collection.** Intake forms and generation contracts would fill the binding
  picker.
- **A Page Fields list held inline on the parent page.** It would travel with the entry in a pack copy, but it is a
  second way to declare fields at runtime, with no versions, no declared renames and no vocabulary.
- **A fixed set of Page Fields per Hosted site.** It can't carry an essay's topic.
- **Refusing any version that renames or drops a bound field.** Every schema change would wait on every page author, the
  coupling ADR-0228 decision 8 rejected for profiles.
- **Following nothing.** A rename the schema itself declares would break a live page.
- **Records edited on the System page.** It is the registry's raw view, behind the registry's gate, away from the site.
- **Records edited only in the canvas.** A Collection with no page yet would have no editor.
- **Starter sites forbidden from binding registered Collections.** The operator's starter site would lack what every
  tenant gets.
