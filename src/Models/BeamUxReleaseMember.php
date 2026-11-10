<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One member of a Release. `member_kind` names the kind (ADR-0243); `member_ref` is that kind's own address for its
 * subject. An `entry` member also carries `entry_id` (equal to its `member_ref`), which every entry reader keys on.
 */
class BeamUxReleaseMember extends Model
{
    use HasUuids;

    public const KIND_ENTRY = 'entry';

    protected $table = 'beam_ux_release_members';

    protected $guarded = ['*'];

    /**
     * This member's kind; `entry` when the column is absent from the row (a writer that predates kinds).
     */
    public function kind(): string
    {
        return (string) ($this->getAttribute('member_kind') ?? self::KIND_ENTRY);
    }

    /**
     * The kind's address for this member's subject. `member_ref` is nullable on purpose: a row written by code that
     * predates member kinds carries only `entry_id`, and an entry's address IS its `entry_id`, so it reads as that.
     */
    public function ref(): string
    {
        return (string) ($this->getAttribute('member_ref') ?? $this->getAttribute('entry_id'));
    }
}
