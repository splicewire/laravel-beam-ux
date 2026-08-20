<?php

namespace Splicewire\Beam\Ux\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\Right;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The standing operator check on ADR-0212's two rights. Both findings name a condition that is
 * **silently useless** rather than broken — nothing throws, nothing 500s, and the row looks fine in
 * isolation — which is exactly the class of problem a doctor check exists for.
 *
 *  1. **Unknown token.** A token the bound {@see EntryAccessGate} does not `know()`. At runtime this
 *     already fails closed for free (under any-of, a token nothing satisfies never matches), so it is
 *     a lockout rather than a leak — but a lockout nobody has hit yet is invisible.
 *     {@see RegisterEntriesFromDisk} hard-errors on one at import; this catches rows authored through
 *     any other path, and rows that predate a gate re-binding that narrowed the vocabulary.
 *  2. **Inert declaration.** An entry whose declared tokens grant nothing beyond what its ancestors
 *     already impose — precisely a `644` file inside a `700` directory, whose permissive mode is
 *     unreachable rather than honoured. ADR-0212 §3 chose to ACCEPT and report these rather than
 *     reject them, because rejecting would make a perfectly coherent Unix-shaped declaration an error.
 *     Reporting is the other half of that bargain; without it, "a subtree can only narrow" is a rule
 *     authors discover by having their intent quietly ignored.
 *
 * Inertness is judged **structurally**, not by simulating an actor: a declaration is inert when an
 * ancestor constrains the chain and the entry's own list adds a token that constraint does not contain
 * — a widening conjunction will discard. Tokens are opaque here (ADR-0092), so set comparison is the
 * honest limit of what this package can know; a gate whose tokens imply one another (a role hierarchy)
 * may widen without this noticing, which is a false negative and never a false alarm.
 */
class BeamUxAccessAudit implements DoctorAudit
{
    public function __construct(private EntryAccessGate $gate) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $check = 'entry access tokens (traverse/access)';

        if (! Schema::hasTable('beam_ux_entries')) {
            return [Finding::warn($check, 'beam_ux_entries is absent — publish + migrate beam-ux before auditing entry access.')];
        }

        $columns = array_values(array_filter(
            Right::cases(),
            fn (Right $right) => Schema::hasColumn('beam_ux_entries', $right->column()),
        ));

        if ($columns === []) {
            return [Finding::warn(
                $check,
                'neither the `traverse` nor the `access` column exists — this host predates ADR-0212; '.
                're-publish and migrate beam-ux to gate entries at all.',
            )];
        }

        /** @var array<string, BeamUxEntry> $byId */
        $byId = BeamUxEntry::query()
            ->withTrashed()
            ->get()
            ->keyBy(fn (BeamUxEntry $entry) => (string) $entry->getKey())
            ->all();

        $unknown = [];
        $inert = [];

        foreach ($byId as $entry) {
            foreach ($columns as $right) {
                $tokens = $entry->tokensFor($right);

                if ($tokens === null) {
                    continue;
                }

                foreach ($tokens as $token) {
                    if (! $this->gate->knows($token)) {
                        $unknown[] = "{$this->label($entry)} · {$right->value}: [{$token}]";
                    }
                }

                if ($tokens !== [] && ($widened = $this->widensBeyondAncestors($entry, $tokens, $byId)) !== []) {
                    $inert[] = "{$this->label($entry)} · {$right->value}: [".implode(', ', $widened).']';
                }
            }
        }

        $findings = [];

        if ($unknown !== []) {
            $findings[] = Finding::fail($check, 'entries carry tokens the bound '.$this->gate::class.
                ' does not recognise — each one fails closed, so the page is locked out rather than leaked: '.
                implode('; ', $unknown).'.');
        }

        if ($inert !== []) {
            $findings[] = Finding::warn($check, 'inert declarations — these grant nothing beyond the '.
                'constraint an ancestor already imposes, because a subtree can only ever NARROW '.
                '(ADR-0212 §3). Re-parent the entry, or widen the ancestor: '.implode('; ', $inert).'.');
        }

        return $findings !== [] ? $findings : [Finding::pass(
            $check,
            'every declared traverse/access token is recognised by the bound gate, and no declaration is inert.',
        )];
    }

    /**
     * The tokens this entry declares that no constraining ancestor admits — the widening conjunction
     * will discard. Empty when no ancestor constrains the chain at all (the entry is then the first
     * constraint on it, which is a NARROWING and entirely valid).
     *
     * **The inherited constraint is the ancestors' `traverse`, for BOTH rights.** That is not a
     * shortcut: ADR-0212 §3 is explicit that an ancestor's `access` is irrelevant to a descendant — a
     * parent's `access` gates only its own body. What a reader must clear to reach a descendant at all
     * is the `traverse` chain, so the traverse conjunction is the ceiling that any token on a
     * descendant — `traverse` or `access` — is measured against.
     *
     * @param  list<string>  $tokens
     * @param  array<string, BeamUxEntry>  $byId
     * @return list<string>
     */
    private function widensBeyondAncestors(BeamUxEntry $entry, array $tokens, array $byId): array
    {
        $constraint = null;
        $seen = [];
        $parentId = $entry->parent_id === null ? null : (string) $entry->parent_id;

        // Walk up to the root, intersecting every ancestor's declaration for the same right. `$seen`
        // is the cycle guard: `parent_id` is a plain uuid, not a DB-constrained FK, so a malformed
        // tree must not hang the doctor.
        while ($parentId !== null && ! isset($seen[$parentId]) && isset($byId[$parentId])) {
            $seen[$parentId] = true;
            $ancestor = $byId[$parentId];

            $declared = $ancestor->tokensFor(Right::Traverse);
            if ($declared !== null) {
                $constraint = $constraint === null
                    ? $declared
                    : array_values(array_intersect($constraint, $declared));
            }

            $parentId = $ancestor->parent_id === null ? null : (string) $ancestor->parent_id;
        }

        if ($constraint === null) {
            return [];
        }

        return array_values(array_diff($tokens, $constraint));
    }

    private function label(BeamUxEntry $entry): string
    {
        return (string) ($entry->title ?? $entry->slug ?? $entry->getKey());
    }
}
