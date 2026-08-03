<?php

namespace Splicewire\Beam\Ux\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A first-class rooted **containment tree** owned by a site/realm (beamux-entry-charter S3, ADR-0165) —
 * the org spine for public content. Each {@see BeamUxEntry} belongs to one sitemap via `sitemap_id`, and
 * the public URL of every entry is inherited by composing its `segment` DOWN the tree from this sitemap's
 * root. This is PAID `splicewire/*` (ADR-0092): the `site` realm + Sitemap + containment + URL
 * inheritance on the entry are the engine; only the `laravel-data-nav` `NavTree` projection is free-tier.
 *
 * **Default: one auto-provisioned per site** ({@see forRealm()} find-or-create). The `BeamUxEntry`'s
 * `sitemap_id` FK defaults to that one (via {@see BeamUxEntry::defaultSitemapId()}). **Multiplicity**
 * (many sitemaps per site, per-tenant sitemaps) is DEFERRED — the door is held open by the shape (a
 * table, a `realm` key, an `is_default` marker), not by a built feature. The single-default invariant is
 * upheld here in {@see forRealm()}, not by a DB unique that would slam the door on non-default rows.
 *
 * `sitemap.xml` is a PROJECTION of the published/public subtree of a Sitemap, not the Sitemap itself.
 */
class Sitemap extends Model
{
    use HasUuids;

    protected $table = 'sitemaps';

    /** The public content realm a sitemap roots under by default (the `site` realm, ADR-0165). */
    public const REALM_SITE = 'site';

    protected $fillable = [
        'realm',
        'name',
        'is_default',
    ];

    protected $attributes = [
        'realm' => self::REALM_SITE,
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** The entries belonging to this sitemap (its containment tree's nodes). */
    public function entries(): HasMany
    {
        return $this->hasMany(BeamUxEntry::class, 'sitemap_id');
    }

    /**
     * The default sitemap for a realm — **auto-provisioned on first ask** (find-or-create), so a fresh
     * install always has exactly one default per site without a seeder. This upholds the single-default
     * invariant in code (ADR-0165 "one auto-provisioned per site by default"); multiplicity stays
     * deferred. Idempotent: the second call returns the same row.
     */
    public static function forRealm(string $realm = self::REALM_SITE): self
    {
        return static::query()->firstOrCreate(
            ['realm' => $realm, 'is_default' => true],
            ['name' => 'Default sitemap'],
        );
    }
}
