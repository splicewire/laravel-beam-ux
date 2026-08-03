<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A lightweight stand-in for a host's bound `BeamTag` model in the beam-ux test harness — carrying only
 * the tiny static API the beam-taxonomy `HasTags` concern calls (`findOrCreate` / `convertToTags`),
 * without pulling Scout/Sluggable into the harness. Named `Tag` (not `FixtureTag`) so the `taggable` morph
 * derives the real `tag_id` pivot key, exactly as the concrete `BeamTag` does. Mirrors beam-taxonomy's own
 * fixture approach; the wiring under test is beam-ux's morph, not the tag model's search/slug behaviour.
 */
class Tag extends Model
{
    use HasUuids;

    protected $table = 'tags';

    protected $guarded = [];

    public static function findOrCreate($values, $type = null)
    {
        $tags = collect($values)->map(function ($value) use ($type) {
            if ($value instanceof self) {
                return $value;
            }
            $name = is_array($value) ? ($value['name'] ?? null) : $value;

            return static::firstOrCreate(
                ['name' => $name, 'type' => $type],
                ['slug' => Str::slug((string) $name)],
            );
        });

        return is_string($values) ? $tags->first() : $tags;
    }

    public static function convertToTags($values, $type = null)
    {
        if ($values instanceof self) {
            $values = [$values];
        }

        return collect($values)->map(function ($value) use ($type) {
            if ($value instanceof self) {
                return $value;
            }

            return static::where('type', $type)->where('name', $value)->first();
        });
    }
}
