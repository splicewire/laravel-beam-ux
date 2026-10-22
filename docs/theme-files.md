# Deliberate local Theme reconciliation

Files recording addresses a fixed historical Release member. Its current-file panel instead compares
that entry’s **current published pin** with bounded local bytes. Git status and timestamps do not
choose the state. Existing receipt manifests stay immutable; Commit now still records a whole Release.

The read-only `ReleaseFilesData` schema binds the reusable Beam `FilesComparisonPanel` through
Frame’s existing FormResolver. Hosts supply `fileEditorHref(entryId)` in Beam Inertia configuration
for their own editor URL. No generic CRUD write or manual UUID/hash form is introduced.

## Supported import

`inspect-file` is GET with no input. `take-file-draft` and `restore-missing-file` are POST operations
on `beam-ux-release-files/{memberId}` mounted by `EditorRoutes::mount` inside the host’s authenticated,
CSRF-protected group. Every operation repeats existing all-member Release admission, verified central
residency/no active or unknown tenancy, plus addressed entry edit permission. Restore also needs
publish permission. A direct publisher without edit permission cannot inspect local source.

Import supports the exact complete canonical Theme/CSS representation emitted by CssBodyCodec.
It requires UTF-8, at most 4 MiB, byte-exact encode/decode roundtrip, identical full leaf-key set and
namespace preservation, and full canvas/shell/site schema validation. Only existing supported hex
color leaves change. Font, responsive and extension leaves must remain unchanged. Canonical empty
is explicitly outside key-preserving import; malformed/no-root/duplicate/foreign CSS is invalid.
Other codecs, clearing tokens, batch reconciliation and arbitrary CSS parsing remain unsupported.

The confirmed input captures working head, current pin, file existence/SHA256 and server-derived
codec/disk/placement fingerprint. No body/path/actor can be submitted. Under the entry transaction,
head AND pin and placement and file snapshot must still match; file bytes are reread after validation.
The existing draftFromBase records the complete body. Import never writes the mirror, publishes,
changes a receipt or commits. Explicit Reload obtains new tokens after conflict; no automatic rebase.
SQLite reserves its writer using a no-op on the addressed row before reading preconditions because
SQLite ignores FOR UPDATE. Other supported connections use the ordinary entry row lock. This shares
publication’s database writer serialization and does not add a second Git lock order.

## Missing-only Restore

Restore decodes the current published snapshot, never the working draft. It checks head/pin/placement
and exact inspected absence, then uses exclusive `fopen(x)` creation, flush/fsync and bounded readback.
If any file appears, creation fails without replacement. A fresh explicit inspection of identical bytes
can return unchanged; a different existing file returns conflict with preserve/move guidance.
Partial writes, failed durability/readback and ambiguous results preserve the created file. Inspect
and preserve or move it deliberately before a fresh inspection/retry; the API never deletes it.
Draft/history/versions/pin/Release/receipt/Git remain unchanged. Cooperating publication shares the
entry transaction; an external editor modifying bytes AFTER exclusive creation remains outside this
no-clobber promise. There is no native external-editor CAS or overwrite/backup engine.

Paths are recomputed from placement/local disk configuration; symlink, case alias, escape, .git,
submodule and nonregular targets are refused. Reading/importing works without a Git executable.
Authentication and entry admission happen before returning any local path/hash/source preview.
Previews are limited to 20,000 bytes per side and explicitly marked truncated; validation still uses
the complete bounded representation. Successful draft import must use normal independent review and
fixed-version Release Publish before readers, mirror and the local Git recording advance.

## SQLite contention

Publication and recorder completion reserve the SQLite writer before their pin reads, in addition
to reconciliation. Ordinary row locks and global entry order remain on other drivers. An existing
outer workflow transaction may already have read before that reservation; this does not universally
eliminate SQLite busy conflicts. The authenticated direct Publish operation returns retryable409
only for SQLite busy/locked statements after its own complete rollback, before its transaction body
completed. It says nothing was published and asks for reload/explicit retry. Unrelated database
faults and commit/after-commit failures are not relabeled; no transaction or Git callback is replayed.
