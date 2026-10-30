<?php

namespace Splicewire\Beam\Ux\Sync;

use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\Reasserter;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Sync\Contracts\SyncLineageResolver;

/**
 * EP-36: a {@see BeamUxEntry} as a Scaffold Pack's `beam-ux-entry` content (tower ADR-0008 d3; ADR-0227 d2), through
 * laravel-beam-sync's `Syncable` contract.
 *
 * - **Only what is published travels** (EP-36 D2): the payload carries the body the publication pin points at, never a
 *   draft or an unreleased working row, so the source operator's in-progress edits never reach a new tenant.
 * - **The copy is a `pack:<slug>` row** born published with its `asserted_hash` ({@see Reasserter::seedBody()}), and a
 *   re-apply goes through the same path: it rewrites a copy only while it is pristine, never an edited one, and never
 *   its placement.
 * - **Page settings travel with the first copy only.** A re-apply re-asserts what `asserted_hash` covers, the title and
 *   body (ADR-0215), so it never overwrites settings the tenant has since changed.
 * - **Its dependencies are its parent page and the authored components its published body uses**, so they land first;
 *   the parent is remapped to the target's row through the sync's lineage resolver.
 */
trait SyncsAsPackCopy
{
    /** The sync config key carrying the applying pack's slug (tower's ScaffoldPackApplier sets it). */
    public const PACK_SLUG_CONFIG = 'beam_ux.pack_slug';

    /** Row attributes a copy carries beside its body: identity, presentation and page settings. Never placement. */
    private const SYNCED_ATTRIBUTES = [
        'title', 'format', 'component_kind', 'segment', 'nav_order', 'nav_group', 'layout', 'template', 'traverse',
        'seo_description', 'seo_social_image', 'seo_page_type', 'seo_published_date',
    ];

    public function toSyncPayload(): array
    {
        // Published means what the site means by it: the host's bound EntryPublishGate (a workflow-managed entry is
        // public only at the published marking, so a take-down that returns it to draft while leaving the pin withholds
        // it; an unmanaged entry is public) and a publication pin that points at a version (build-qa EP-36 reviews).
        if (! app(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class)->isPublished($this)) {
            throw new \LogicException("Entry [{$this->getKey()}] is not published; a pack copies only what is published.");
        }
        $body = app(EntryPublication::class)->publishedBodyOf($this);
        if ($body === null) {
            throw new \LogicException("Entry [{$this->getKey()}] has no published version; a pack copies only what is published.");
        }

        $attributes = [];
        foreach (self::SYNCED_ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $this->getAttributes())) {
                $attributes[$attribute] = $this->getAttribute($attribute);
            }
        }

        return [
            'id' => (string) $this->getKey(),
            'coordinate' => ['namespace' => $this->namespace, 'slug' => $this->slug, 'realm' => $this->realm, 'type' => $this->getRawOriginal('type') ?? $this->getAttribute('type')],
            'attributes' => $attributes,
            'parent_id' => $this->parent_id === null ? null : (string) $this->parent_id,
            'body' => $body,
        ];
    }

    public function syncHash(): string
    {
        return hash('sha256', json_encode($this->toSyncPayload(), JSON_THROW_ON_ERROR));
    }

    /** @return array<class-string, list<string>> */
    public function syncDependencies(): array
    {
        $candidates = [];
        if ($this->parent_id !== null && ($parent = static::query()->find($this->parent_id)) !== null) {
            $candidates[] = $parent;
        }
        $body = app(EntryPublication::class)->publishedBodyOf($this) ?? [];
        $used = app(AuthoredComponents::class)->names($body);
        if ($used !== []) {
            foreach (app(AuthoredComponents::class)->entries($this) as $component) {
                if (in_array($component->getAttribute('published_component_name'), $used, true)) {
                    $candidates[] = $component;
                }
            }
        }
        // The sync engine follows these, so only what would itself travel may be one: an unpublished parent or component
        // would refuse to serialize and fail the whole pack apply (EP-36 slice 3 review). A withheld parent leaves the
        // copy's parent null through the lineage resolver.
        $ids = [];
        foreach ($candidates as $candidate) {
            if (self::isPublishedForPack($candidate)) {
                $ids[] = (string) $candidate->getKey();
            }
        }

        return $ids === [] ? [] : [static::class => array_values(array_unique($ids))];
    }

    /** Published as the site means it (the bound EntryPublishGate) and pinned to a version: what a pack may copy. */
    private static function isPublishedForPack(BeamUxEntry $entry): bool
    {
        return app(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class)->isPublished($entry)
            && app(EntryPublication::class)->publishedBodyOf($entry) !== null;
    }

    public static function fromSyncPayload(array $data, array $config = []): static
    {
        $slug = $config[self::PACK_SLUG_CONFIG] ?? null;
        if (! is_string($slug) || $slug === '') {
            throw new \InvalidArgumentException('A beam-ux-entry copy needs the applying pack\'s slug ['.self::PACK_SLUG_CONFIG.'].');
        }

        $attributes = $data['attributes'] ?? [];
        if (($data['parent_id'] ?? null) !== null) {
            $resolver = $config[SyncLineageResolver::CONFIG_KEY] ?? null;
            $attributes['parent_id'] = $resolver instanceof SyncLineageResolver
                ? $resolver->resolveTargetId(static::class, (string) $data['parent_id'])
                : null;
        }

        $isNew = ! static::query()->where($data['coordinate'])->exists();
        /** @var static $entry */
        $entry = app(Reasserter::class)->seedBody($data['coordinate'], $attributes, $data['body'], Provenance::pack($slug));
        if ($isNew) {
            // seedBody fills only mass-assignable attributes; the page settings are set as the editor sets them.
            $entry->forceFill(array_diff_key($attributes, ['title' => true]))->save();
        }

        return $entry->refresh();
    }
}
