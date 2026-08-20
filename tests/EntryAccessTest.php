<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\EntryAccessResolver;
use Splicewire\Beam\Ux\Access\Right;
use Splicewire\Beam\Ux\Access\TokenAccessGate;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Doctor\BeamUxAccessAudit;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * ADR-0212 — entry access is two conjunctive rights on the row, `traverse` and `access`. These are the
 * cases the ADR named as the point of the design, not incidental coverage:
 *
 *  - an `auth`-gated entry is ABSENT from the sitemap and RENDERABLE to a logged-in reader — the case
 *    that justified an actor-aware third port over widening the two actor-free ones;
 *  - a child declaring wider access than its parent is INERT, and the doctor names it;
 *  - `traverse` denied + `access` open ⇒ the UNLISTED PAGE (200 by direct URL, absent from nav) — what
 *    two rights buy over one;
 *  - a denied node's ENTIRE SUBTREE vanishes from nav, not just the node;
 *  - an unknown token DENIES (fail-closed), never admits;
 *  - a gate pass reflects a revoked grant on the NEXT REQUEST — no stale-authorization window.
 */
class EntryAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();

        // The importer cases write a body through the resolved StorageDriver; swap in the same
        // recording fake the disk-batch test uses, so the particle write path (beam-core's own
        // concern) stays out of a test about access tokens.
        $this->app->extend(
            \Splicewire\Beam\Ux\Storage\StorageDriverResolver::class,
            fn () => (new \Splicewire\Beam\Ux\Storage\StorageDriverResolver)
                ->register(\Splicewire\Beam\Ux\Storage\StorageDriverResolver::DEFAULT, new RecordingDriver),
        );
    }

    public function test_an_auth_gated_entry_is_absent_from_the_sitemap_and_renderable_to_a_logged_in_reader(): void
    {
        // The whole reason for three ports over two. The sitemap gates take NO actor and answer
        // "is this crawlable by anyone?" — for an auth-gated page the honest answer is no. The access
        // gate takes one and answers "may THIS reader see it?" — for a logged-in reader, yes. A single
        // caller-independent boolean cannot hold both answers at once.
        $guide = $this->entry('members-guide', segment: '/members', access: ['auth']);

        $anonymous = $this->app->make(EntryAccessResolver::class);

        $this->assertFalse($anonymous->canRender(null, [$guide]), 'a crawler must not reach an auth-gated entry');
        $this->assertTrue($anonymous->canRender($this->actor(), [$guide]), 'a logged-in reader must');

        // And the actor-free crawlability gates are untouched by the access declaration — they still
        // report the row publishable/public, because that is a different question (ADR-0212 §1).
        $this->assertTrue($this->app->make(\Splicewire\Beam\Ux\Sitemap\EntryPublishGate::class)->isPublished($guide));
    }

    public function test_traverse_denied_with_access_open_is_an_unlisted_page(): void
    {
        // The case two rights buy that one cannot express: hand a customer a direct link to one guide
        // without that guide appearing in anyone's sidebar.
        $unlisted = $this->entry('beta-guide', segment: '/beta', traverse: ['root']);

        $this->assertTrue(
            $this->app->make(EntryAccessResolver::class)->canRender(null, [$unlisted]),
            'access is undeclared, so the body stays readable by direct URL',
        );

        $this->assertCount(
            0,
            $this->app->make(NavProjector::class)->project('site')->items,
            'traverse governs listing, so the page is absent from nav',
        );
    }

    public function test_a_denied_node_prunes_its_entire_subtree_from_nav(): void
    {
        $internal = $this->entry('internal', segment: '/internal', traverse: ['root']);
        $child = $this->entry('runbook', segment: 'runbook', parent: $internal);
        $this->entry('deep', segment: 'deep', parent: $child);
        $this->entry('public', segment: '/public');

        // Lifting the orphans to the grandparent would be incoherent: a child's URL is composed from
        // its parent's segment chain, so a lifted child has no reachable URL to link to.
        $items = $this->app->make(NavProjector::class)->project('site')->items;

        $this->assertCount(1, $items);
        $this->assertSame('/public', $items[0]->href);
    }

    public function test_a_child_declaring_wider_access_than_its_parent_is_inert_and_the_doctor_names_it(): void
    {
        // The ancestor constraint a descendant is measured against is `traverse` — a parent's `access`
        // gates only its OWN body and is irrelevant to a child (§3).
        $section = $this->entry('handbook', segment: '/handbook', traverse: ['root']);
        $child = $this->entry('policies', segment: 'policies', parent: $section, access: ['root', 'auth']);

        // Accepted, per §3 — a coherent Unix-shaped declaration is not an error. But `auth` is
        // unreachable: conjunction discards it, exactly as a 644 file inside a 700 directory.
        $resolver = $this->app->make(EntryAccessResolver::class);
        $this->assertFalse($resolver->canRender($this->actor(), [$section, $child]),
            "the parent's traverse constraint still governs — the widened token grants nothing");
        $this->assertTrue($resolver->canRender($this->actor(roles: ['Root']), [$section, $child]));

        $findings = $this->app->make(BeamUxAccessAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame('warn', $findings[0]->status->value ?? 'warn');
        $this->assertStringContainsString('inert', strtolower($findings[0]->detail));
        $this->assertStringContainsString('auth', $findings[0]->detail);
    }

    public function test_an_unknown_token_denies_rather_than_admits(): void
    {
        // A typo is a LOCKOUT, which is loud and self-correcting — never a leak. Under any-of, a token
        // nothing satisfies simply never matches.
        $typo = $this->entry('oops', segment: '/oops', access: ['athu']);

        $resolver = $this->app->make(EntryAccessResolver::class);

        $this->assertFalse($resolver->canRender(null, [$typo]));
        $this->assertFalse($resolver->canRender($this->actor(), [$typo]));
        $this->assertFalse($resolver->canRender($this->actor(roles: ['Root']), [$typo]));

        // And the standing doctor check surfaces it rather than leaving it to be discovered by a 404.
        $findings = $this->app->make(BeamUxAccessAudit::class)->run();
        $this->assertStringContainsString('athu', $findings[0]->detail);
    }

    public function test_a_revoked_grant_bites_on_the_next_request_with_no_stale_authorization_window(): void
    {
        $this->entry('members', segment: '/members', access: ['auth'], traverse: ['auth']);

        $projector = $this->app->make(NavProjector::class);
        $reader = $this->actor();

        $this->assertCount(1, $projector->project('site', $reader)->items);

        // The "revocation": the same reader, no longer authenticated. The gate pass runs live per
        // call — only the user-INDEPENDENT enumerated tree is cacheable (ADR-0122's split, preserved),
        // so nothing carries the previous answer forward.
        $this->assertCount(0, $projector->project('site', null)->items);
        $this->assertCount(1, $projector->project('site', $reader)->items);
    }

    public function test_a_null_column_inherits_and_a_declared_but_empty_list_denies(): void
    {
        // The load-bearing distinction: null is NO DECLARATION (inheritance falls out of conjunction),
        // `[]` is a declared-but-empty any-of list, which satisfies nothing — secure-by-omission.
        $open = $this->entry('open', segment: '/open');
        $sealed = $this->entry('sealed', segment: '/sealed', access: []);

        $resolver = $this->app->make(EntryAccessResolver::class);

        $this->assertNull($open->tokensFor(Right::Access));
        $this->assertSame([], $sealed->tokensFor(Right::Access));

        $this->assertTrue($resolver->canRender(null, [$open]));
        $this->assertFalse($resolver->canRender($this->actor(roles: ['Root']), [$sealed]));
    }

    public function test_nav_gates_the_publish_marking_it_previously_ignored(): void
    {
        // NavProjector applied ZERO gates before ADR-0212 — not even the publish one — so a draft
        // title leaked into every sidebar.
        $this->entry('shipped', segment: '/shipped');
        $draft = $this->entry('unshipped', segment: '/unshipped');
        $draft->forceFill(['workflow_marking' => 'draft'])->save();

        $items = $this->app->make(NavProjector::class)->project('site')->items;

        $this->assertCount(1, $items);
        $this->assertSame('/shipped', $items[0]->href);
    }

    public function test_the_importer_hard_errors_on_a_token_the_bound_gate_does_not_know(): void
    {
        $root = sys_get_temp_dir().'/beam-ux-access-'.uniqid();
        mkdir($root.'/page', recursive: true);
        file_put_contents($root.'/page/secret.mdx', "---\nsegment: /secret\naccess: athu\n---\n\n# Secret\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/athu/');

        try {
            $this->app->make(RegisterEntriesFromDisk::class)->scan($root);
        } finally {
            @unlink($root.'/page/secret.mdx');
            @rmdir($root.'/page');
            @rmdir($root);
        }
    }

    public function test_the_importer_mirrors_both_rights_from_frontmatter_in_either_spelling(): void
    {
        $root = sys_get_temp_dir().'/beam-ux-access-'.uniqid();
        mkdir($root.'/page', recursive: true);
        file_put_contents(
            $root.'/page/handbook.mdx',
            "---\nsegment: /handbook\naccess: root, docs.view\ntraverse: [auth]\n---\n\n# Handbook\n",
        );

        try {
            $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($root);

            $this->assertCount(1, $result['created']);
            $entry = $result['created'][0];
            $this->assertSame(['root', 'docs.view'], $entry->tokensFor(Right::Access));
            $this->assertSame(['auth'], $entry->tokensFor(Right::Traverse));
        } finally {
            @unlink($root.'/page/handbook.mdx');
            @rmdir($root.'/page');
            @rmdir($root);
        }
    }

    public function test_the_default_gate_degrades_headlessly_when_no_rbac_package_is_present(): void
    {
        // beam-ux must stay installable on its own, so `root` and permission tokens DENY rather than
        // fatal on an actor that implements neither `hasRole` nor Authorizable. `auth` keeps working
        // on Laravel's own contracts alone.
        $bare = new class implements Authenticatable
        {
            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return 1;
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return '';
            }

            public function setRememberToken($value) {}

            public function getRememberTokenName()
            {
                return '';
            }
        };

        $gate = new TokenAccessGate;

        $this->assertTrue($gate->allows($bare, $this->entry('a', access: ['auth']), Right::Access));
        $this->assertFalse($gate->allows($bare, $this->entry('b', access: ['root']), Right::Access));
        $this->assertFalse($gate->allows($bare, $this->entry('c', access: ['docs.view']), Right::Access));
    }

    public function test_knows_recognises_the_sentinels_and_the_permission_shape_but_not_a_bare_typo(): void
    {
        $gate = new TokenAccessGate(extraTokens: ['house-token']);

        $this->assertTrue($gate->knows('auth'));
        $this->assertTrue($gate->knows('root'));
        $this->assertTrue($gate->knows('docs.view'));
        $this->assertTrue($gate->knows('house-token'));
        $this->assertFalse($gate->knows('athu'));
    }

    /** A gate that cannot enumerate opts out of validation wholesale, per ADR-0212 §5. */
    public function test_a_gate_that_cannot_enumerate_opts_out_of_import_validation(): void
    {
        $this->app->instance(EntryAccessGate::class, new class implements EntryAccessGate
        {
            public function allows(?Authenticatable $actor, BeamUxEntry $entry, Right $right): bool
            {
                return true;
            }

            public function knows(string $token): bool
            {
                return true;
            }
        });

        $root = sys_get_temp_dir().'/beam-ux-access-'.uniqid();
        mkdir($root.'/page', recursive: true);
        file_put_contents($root.'/page/exotic.mdx', "---\nsegment: /exotic\naccess: whatever\n---\n\n# Exotic\n");

        try {
            $result = $this->app->make(RegisterEntriesFromDisk::class)->scan($root);
            $this->assertCount(1, $result['created']);
        } finally {
            @unlink($root.'/page/exotic.mdx');
            @rmdir($root.'/page');
            @rmdir($root);
        }
    }

    /**
     * @param  list<string>|null  $traverse
     * @param  list<string>|null  $access
     */
    private function entry(
        string $slug,
        ?string $segment = null,
        ?BeamUxEntry $parent = null,
        ?array $traverse = null,
        ?array $access = null,
    ): BeamUxEntry {
        return BeamUxEntry::create(array_filter([
            'slug' => $slug,
            'type' => UxType::Page,
            'segment' => $segment,
            'parent_id' => $parent?->getKey(),
            'traverse' => $traverse,
            'access' => $access,
        ], fn ($value) => $value !== null));
    }

    /** A minimal actor: authenticated, role-aware, and permission-aware. */
    private function actor(array $roles = [], array $permissions = []): Authenticatable
    {
        return new class($roles, $permissions) implements Authenticatable, Authorizable
        {
            public function __construct(private array $roles, private array $permissions) {}

            public function hasRole(string $role): bool
            {
                return in_array($role, $this->roles, true);
            }

            public function can($abilities, $arguments = []): bool
            {
                return in_array($abilities, $this->permissions, true);
            }

            public function cant($abilities, $arguments = []): bool
            {
                return ! $this->can($abilities, $arguments);
            }

            public function cannot($abilities, $arguments = []): bool
            {
                return $this->cant($abilities, $arguments);
            }

            public function getAuthIdentifierName()
            {
                return 'id';
            }

            public function getAuthIdentifier()
            {
                return 1;
            }

            public function getAuthPasswordName()
            {
                return 'password';
            }

            public function getAuthPassword()
            {
                return '';
            }

            public function getRememberToken()
            {
                return '';
            }

            public function setRememberToken($value) {}

            public function getRememberTokenName()
            {
                return '';
            }
        };
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->string('schema_ref')->nullable()->index();
            $table->boolean('schema_is_draft')->default(false)->index();
            $table->string('facade_ref')->nullable();
            $table->string('type')->index();
            $table->string('format')->default('tsx')->index();
            $table->string('body_style')->nullable();
            $table->string('namespace')->nullable()->index();
            $table->string('placement_ref')->nullable();
            $table->string('driver_ref')->nullable();
            $table->string('residency_mode')->default('context-following')->index();
            $table->string('realm')->default('site')->index();
            $table->json('realms')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('segment')->nullable();
            $table->integer('nav_order')->nullable();
            // ADR-0212's two rights.
            $table->json('traverse')->nullable();
            $table->json('access')->nullable();
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }
}
