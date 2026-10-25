<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Ux\Redirect\RedirectPaths;

/**
 * A Redirect (ADR-0236 d1): one site's old path to a target, not versioned, carrying its origin. Every write records an
 * activity row with its causer (ADR-0225 d8); Publish's prune records its own.
 *
 * @property string $id
 * @property string $realm
 * @property string $path
 * @property string $match exact|prefix
 * @property string $target_kind page|record|path|address
 */
class BeamUxRedirect extends Model
{
    use HasUuids;

    public const KINDS = ['page', 'record', 'path', 'address'];

    public const MATCHES = ['exact', 'prefix'];

    protected $table = 'beam_ux_redirects';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['permanent' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::created(fn (self $redirect) => $redirect->audit('created'));
        static::updated(fn (self $redirect) => $redirect->audit('updated'));
        static::deleted(fn (self $redirect) => $redirect->audit('deleted'));
    }

    /** The path as a guest types it: one leading slash, no trailing one, no query or fragment. */
    protected function path(): Attribute
    {
        return Attribute::make(set: fn (mixed $value) => RedirectPaths::normalize((string) $value) ?? (string) $value);
    }

    /** ADR-0225 d8: an activity row for every write, with its causer, on the Redirect's connection. */
    public function audit(string $event, array $properties = []): void
    {
        $logger = activity('beam-redirect')->performedOn($this)->event($event)
            ->withProperties($properties + ['path' => $this->path, 'match' => $this->match, 'targetKind' => $this->target_kind])
            ->tap(fn ($activity) => $activity->setConnection($this->getConnectionName()));
        $actor = auth()->user();
        $actor instanceof Model ? $logger->causedBy($actor) : $logger->causedByAnonymous();
        $logger->log('Redirect '.$event);
    }
}
