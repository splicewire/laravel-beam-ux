# Register selected disk bodies

Use repeated `--only` values when registering new files inside an existing content tree. Keep the full scan root: narrowing it changes the inferred namespaces. Paths are exact, case-sensitive, root-relative body filenames. Absolute paths, traversal, globs, duplicates, symlinks, unknown/non-body paths and ambiguous body envelopes refuse the complete selection before writes.

For example, from a host root:

```sh
XDEBUG_MODE=off herd php artisan splicewire:beam:ux:register-from-disk resources/js/content \
  --type=page \
  --only=beam/docs/laravel/page/adoption.mdx \
  --only=beam/docs/laravel/page/backings.mdx \
  --dry-run
```

Selected dry-run emits the importer's native JSON plan: exact source SHA256, envelope, inferred and actual parent coordinates/IDs, containment fields, composed path and ancestry. A composed path is placement information, not proof of public reachability or access. Review every parent and the complete selected set. A parent newly included in the same selection has a coordinate but no ID until creation. A matched existing entry is reported `skip-existing`, with its actual placement, and is never reasserted, baselined, reparented or published by selected registration.

The plan has a `sha256`. To bind a later apply to that source/selection/placement snapshot, repeat the same root, `--type`, `--under` (if used) and `--only` arguments, omit `--dry-run`, and add `--expect-plan=<the actual sha256 from that dry-run>`. The hash is optional for compatibility; do not substitute a placeholder. A changed source, set, existing target or resolved placement refuses before the first import. A successful apply makes its original plan stale because its targets now exist; dry-run again before retrying. This hash is a measurement binding, not authorization or a credential.

Selected application captures all selected source bytes before writing; it imports those exact bytes through the existing body codec, particle store, version/publication and compiler. It does not lock external editors or provide cross-process database serialization. Changes racing after the measured preflight are outside a filesystem CAS guarantee. Registration retains the existing per-entry publication/compile behavior: a compile diagnostic can leave a registered entry and nonzero exit; inspect the failure rather than treating it as success or retrying with force.

Selected new routable pages refuse occupied composed addresses in their realm and collisions within the selection. Do not import an alternate namespace envelope for an already occupied landing such as `/beam/docs`. Exclude that file; an existing body's update belongs to the authenticated expected-version draft/review/publication path. This command does not bypass that credential boundary.

Without `--only`, the existing whole-tree scan, dry-run output and reassertion/baseline behavior are unchanged. The selected-address guard does not impose new global policy on an unfiltered scan. No implicit filter, watcher, seed, parent repair or existing-body overwrite is added.
