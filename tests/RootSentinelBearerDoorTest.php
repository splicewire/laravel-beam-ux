<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * credential-scope 07, review cs07 #2: the `root` access sentinel (TokenAccessGate) through a real HTTP door, the
 * editor's `component-capabilities` operation (ComponentAuthoring::capabilities refuses an entry the actor may not read),
 * mounted behind `auth:sanctum` with real Sanctum bearers whose abilities beam-accounts' TokenAbilitiesScopeResolver reads.
 * A component whose `access` is `['root']`: a Root bearer without the right's strict name is refused, one carrying
 * `beam-ux-entry.access` is admitted, and a non-Root principal is refused whatever the token carries. The sentinel answers
 * a bool, so the refusal is the op's own 403, not the coded scope refusal.
 */
class RootSentinelBearerDoorTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->register(\Laravel\Sanctum\SanctumServiceProvider::class);
        $this->app->singleton(CredentialScopeResolver::class, TokenAbilitiesScopeResolver::class);
        foreach (['cs07_sentinel_users' => fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->boolean('root')->default(false), $t->timestamps()],
            'personal_access_tokens' => fn (Blueprint $t) => [$t->id(), $t->morphs('tokenable'), $t->string('name'), $t->string('token', 64)->unique(),
                $t->text('abilities')->nullable(), $t->timestamp('last_used_at')->nullable(), $t->timestamp('expires_at')->nullable(), $t->timestamps()]] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $columns);
            }
        }
        Gate::define('ux.site.author', fn ($actor) => true);
        Gate::define('entitlement:ux.author', fn ($actor) => true);
        Gate::policy(BeamUxEntry::class, Cs07SentinelEntryPolicy::class);
        Route::middleware('auth:sanctum')->group(fn () => \Splicewire\Beam\Facades\Particle::ops('component-entries', 'beam-ux-entry', ['component-capabilities']));
    }

    public function test_a_root_only_component_is_read_by_a_scoped_root_bearer_only_with_the_rights_strict_name(): void
    {
        $siteRoot = BeamUxEntry::rootFor('site');
        $entry = BeamUxEntry::create(['slug' => 'ops-card', 'type' => 'component', 'realm' => 'site', 'parent_id' => $siteRoot->id,
            'format' => 'tsx', 'component_kind' => 'authored', 'access' => ['root']]);
        app(\Splicewire\Beam\Ux\Publish\EntryPublication::class)->write($entry, [['kind' => 'block', 'name' => 'section', 'isComponent' => false,
            'dynamic' => false, 'props' => [], 'children' => [], 'component' => ['name' => 'OpsCard', 'props' => []]]]);
        $root = Cs07SentinelUser::create(['name' => 'Root', 'root' => true]);
        $member = Cs07SentinelUser::create(['name' => 'Member', 'root' => false]);
        $url = '/component-entries/'.$entry->id.'/component-capabilities';
        $bearer = function (Cs07SentinelUser $user, array $abilities) {
            Auth::forgetGuards();

            return $this->withoutToken()->withToken($user->createToken('cs07', $abilities)->plainTextToken);
        };
        $access = PermissionNamer::assemble(BeamUxEntry::class, 'access');

        $bearer($root, ['entity.view'])->getJson($url)->assertForbidden();
        $bearer($root, [$access])->getJson($url)->assertOk();
        $bearer($root, ['*'])->getJson($url)->assertOk();
        $bearer($member, [$access])->getJson($url)->assertForbidden();
    }
}

class Cs07SentinelUser extends User
{
    use HasApiTokens;

    protected $table = 'cs07_sentinel_users';

    protected $guarded = [];

    /** The configured root role (`beam.ux.access.root_role`, `Root` by default), as a host's RBAC answers it. */
    public function hasRole(string $role): bool
    {
        return $role === 'Root' && (bool) $this->root;
    }

    public function getAttribute($key)
    {
        return in_array($key, ['author', 'edit'], true) ? true : parent::getAttribute($key);
    }
}

/** Every entry verb open for the principal, so only the sentinel and the token decide (as ComponentAuthoringTest's fixture). */
class Cs07SentinelEntryPolicy
{
    public function view($actor, ?BeamUxEntry $entry = null): bool
    {
        return true;
    }

    public function update($actor, BeamUxEntry $entry): bool
    {
        return true;
    }

    public function create($actor, ?BeamUxEntry $entry = null): bool
    {
        return true;
    }
}
