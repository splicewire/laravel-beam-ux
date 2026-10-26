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
 * @property string $origin author|publish|release|takedown|record
 */
class BeamUxRedirect extends Model
{
    use HasUuids;

    public const KINDS = ['page', 'record', 'path', 'address'];

    public const MATCHES = ['exact', 'prefix'];

    /**
     * Who left it (ADR-0236 d1): an author, a publish outside any Release (EP-31 ruling 1), a Release's move, a
     * Take-down's target, or a Record's URL change.
     */
    public const ORIGINS = ['author', 'publish', 'release', 'takedown', 'record'];

    /** The causer of the writes {@see causedBy()} wraps, when it isn't the signed-in user (a Release's publisher). */
    private static ?Model $causer = null;

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

    /**
     * Runs writes whose activity names this causer: a publish runs for its publisher, who may not be the signed-in user
     * (a scheduled Release has none).
     *
     * @template T
     *
     * @param  \Closure(): T  $writes
     * @return T
     */
    public static function causedBy(?Model $actor, \Closure $writes): mixed
    {
        [$previous, self::$causer] = [self::$causer, $actor];
        try {
            return $writes();
        } finally {
            self::$causer = $previous;
        }
    }

    /** ADR-0225 d8: an activity row for every write, with its causer, on the Redirect's connection. */
    public function audit(string $event, array $properties = []): void
    {
        $logger = activity('beam-redirect')->performedOn($this)->event($event)
            ->withProperties($properties + ['path' => $this->path, 'match' => $this->match, 'targetKind' => $this->target_kind,
                'origin' => $this->origin, 'originRef' => $this->origin_ref])
            ->tap(fn ($activity) => $activity->setConnection($this->getConnectionName()));
        $actor = self::$causer ?? auth()->user();
        $actor instanceof Model ? $logger->causedBy($actor) : $logger->causedByAnonymous();
        $logger->log('Redirect '.$event);
    }
}
