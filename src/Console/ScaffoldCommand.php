<?php

namespace Splicewire\Beam\Ux\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * `php artisan splicewire:beam:ux-scaffold {slug} {--namespace=} {--type=}` — mint an EMPTY authoring
 * entry row when no on-disk body exists (F02).
 *
 * The generic residual of the retired app-local `puck-seed`: for a `page` authored on disk,
 * `register-from-disk` + `sync-from-disk` (ticket 08) already create + hydrate the entry from its
 * git-tracked `.tsx`. What disk-authoring does NOT cover is minting an entry that has NO file yet — a
 * `template` (no route, so nothing to register from disk) or a first-load bootstrap row. This command is
 * exactly that thin verb: create the {@see BeamUxEntry} row (idempotent) so the slug is authorable
 * in-browser via `?beam_entry=<slug>`; the body is composed in the editor, not seeded from a PHP array.
 *
 * Namespace defaults to `config('beam.ux.namespace')` (the disk-grouping coordinate, ADR-0165 — NOT the
 * URL); type defaults to `template`. Idempotent: a re-run leaves an existing row untouched.
 *
 * The command name mirrors the package tree (ADR-0167): vendor `splicewire` · tier `beam` ·
 * package-path `ux` · verb `scaffold`.
 */
class ScaffoldCommand extends Command
{
    protected $signature = 'splicewire:beam:ux-scaffold
        {slug : The entry slug to mint}
        {--namespace= : Disk-grouping namespace (default config beam.ux.namespace)}
        {--type=template : The Ux type (layout|template|page|component)}';

    protected $description = 'Mint an empty beam-ux entry row when no on-disk body exists (a template / bootstrap row), authorable in-browser.';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $namespace = $this->namespace();

        $type = UxType::tryFrom((string) $this->option('type'));
        if ($type === null) {
            $this->components->error(sprintf(
                'Unknown --type [%s]; expected one of: %s.',
                $this->option('type'),
                implode(', ', array_map(fn (UxType $t) => $t->value, UxType::cases())),
            ));

            return self::FAILURE;
        }

        $existing = BeamUxEntry::query()
            ->where('namespace', $namespace)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            $this->components->info("Entry [{$namespace}].{$slug} already exists (type={$existing->type?->value}) — left untouched.");

            return self::SUCCESS;
        }

        $entry = new BeamUxEntry;
        $entry->slug = $slug;
        $entry->namespace = $namespace;
        $entry->type = $type;
        $entry->save();

        $this->components->info("Scaffolded [{$namespace}].{$slug} (type={$type->value}) — author it in-browser via ?beam_entry={$slug}.");

        return self::SUCCESS;
    }

    /** The disk-grouping namespace — the `--namespace` option, else `config('beam.ux.namespace')`. */
    private function namespace(): string
    {
        $option = $this->option('namespace');

        return is_string($option) && $option !== '' ? $option : (string) config('beam.ux.namespace', '');
    }
}
