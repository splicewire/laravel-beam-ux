<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Models\BeamUxReleaseMember;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\Kinds\EntryMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseAccess;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseMemberKind;
use Splicewire\Beam\Ux\Release\Kinds\ReleaseMemberKindRegistry;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Workflows\Control\TransitionContext;

/**
 * config-sections 04 step 2 (ADR-0243): a Release member has a kind, and the kind — not ReleaseService — owns its
 * subject's lookup, authorization, admission, moved check, publish and rollback ref. `entry` is beam-ux's; a probe kind
 * here stands in for the next one (beam-config's override, ticket 08) to prove the Release drives any kind it holds.
 */
class ReleaseMemberKindTest extends AuthoredComponentNativeTestCase
{
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        Gate::before(fn () => true);
        \Illuminate\Support\Facades\Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author']);
        $this->author = User::query()->findOrFail(7);
        ProbeMemberKind::$calls = [];
    }

    public function test_the_registry_is_in_the_index_conforms_and_seeds_entry(): void
    {
        $kinds = app(ReleaseMemberKindRegistry::class);

        $this->assertTrue(app(RegistryIndex::class)->has('beam.ux.release-member-kinds'));
        $this->assertSame($kinds, app(RegistryIndex::class)->resolve('beam.ux.release-member-kinds'));
        $this->assertInstanceOf(Registry::class, $kinds);
        $this->assertSame(['entry'], $kinds->kinds());
        $this->assertInstanceOf(EntryMemberKind::class, $kinds->for('entry'));
        $this->assertContains('beam.ux.release-member-kinds.entry', array_map('strval', $kinds->keys()));
    }

    public function test_a_taken_kind_is_never_replaced(): void
    {
        $this->expectException(\Throwable::class);

        app(ReleaseMemberKindRegistry::class)->register(new EntryMemberKind);
    }

    public function test_a_release_publishes_every_kind_it_holds_each_through_its_own_kind(): void
    {
        app(ReleaseMemberKindRegistry::class)->register(new ProbeMemberKind);
        $page = $this->draftPage('about');
        $service = app(ReleaseService::class);
        $release = $service->create($page, 'Mixed', $this->author);
        $service->putMember($release, $page->id, $this->saveDraft($page), $this->author);
        $this->probeMember($release, 'mail.from', 'v2', 'v1');
        ProbeMemberKind::$calls = [];

        $service->transition($release, 'publish', $this->author);

        $this->assertSame('published', $release->fresh()->status);
        $this->assertNotNull($page->fresh()->published_version, 'the entry member published through EntryMemberKind');
        $this->assertSame(1, $release->members()->count(), 'members() still reads entries alone');
        $this->assertSame(2, $release->allMembers()->count());
        $this->assertSame([
            'lock', 'subject:mail.from', 'authorize:Publish', 'moved:mail.from', 'publishable:mail.from', 'publish:mail.from=v2',
            // After commit, the recording authorizes every member of the published Release.
            'subject:mail.from', 'authorize:Publish',
        ], ProbeMemberKind::$calls);
    }

    public function test_a_rollback_selects_what_each_kind_says(): void
    {
        app(ReleaseMemberKindRegistry::class)->register(new ProbeMemberKind);
        $page = $this->draftPage('about');
        $live = $this->saveDraft($page);
        app(EntryPublication::class)->publishVersion($page->fresh(), $live);
        $service = app(ReleaseService::class);
        $release = $service->create($page, 'Mixed', $this->author);
        $service->putMember($release, $page->id, $this->saveDraft($page), $this->author);
        $this->probeMember($release, 'mail.from', 'v2', 'v1');
        $service->transition($release, 'publish', $this->author);
        ProbeMemberKind::$calls = [];

        $rollback = $service->rollback($release->fresh(), $this->author);

        $probe = $rollback->allMembers()->where('member_kind', 'probe')->firstOrFail();
        $this->assertSame(['mail.from', 'v1', null], [$probe->ref(), $probe->version_id, $probe->entry_id]);
        $this->assertSame('observed-mail.from', $probe->observed_pin);
        $this->assertContains('admit:mail.from=v1', ProbeMemberKind::$calls);
        $this->assertSame($live, $rollback->members()->firstOrFail()->version_id, 'and the entry member rolls back to its prior pin, as before');
    }

    public function test_a_member_of_a_kind_no_package_provides_stops_the_release(): void
    {
        $page = $this->draftPage('about');
        $service = app(ReleaseService::class);
        $release = $service->create($page, 'Orphan', $this->author);
        $service->putMember($release, $page->id, $this->saveDraft($page), $this->author);
        $this->probeMember($release, 'mail.from', 'v2', 'v1');

        try {
            $service->transition($release, 'publish', $this->author);
            $this->fail('A Release holding a member of an unregistered kind advanced.');
        } catch (ValidationException $refused) {
            $this->assertStringContainsString('`probe`', $refused->errors()['release'][0]);
        }
        $this->assertNull($page->fresh()->published_version, 'nothing published');
    }

    private function probeMember(BeamUxRelease $release, string $ref, string $version, string $pin): void
    {
        (new BeamUxReleaseMember)->forceFill([
            'release_id' => $release->id, 'member_kind' => 'probe', 'member_ref' => $ref, 'version_id' => $version, 'observed_pin' => $pin,
        ])->save();
    }

    private function saveDraft(BeamUxEntry $page): string
    {
        return (string) app(EntryPublication::class)->recordDraft($page->fresh(), [['kind' => 'block', 'name' => 'div', 'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]])->headVersion;
    }

    private function draftPage(string $segment): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $segment.'-'.Str::random(4), 'title' => ucfirst($segment), 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => $segment]);
    }
}

/** A second kind whose subject is its ref; it records what the Release asked of it. */
class ProbeMemberKind implements ReleaseMemberKind
{
    /** @var list<string> */
    public static array $calls = [];

    public function kind(): string
    {
        return 'probe';
    }

    public function lock(BeamUxRelease $release): void
    {
        self::$calls[] = 'lock';
    }

    public function subject(BeamUxRelease $release, string $ref): object
    {
        self::$calls[] = "subject:{$ref}";

        return (object) ['ref' => $ref];
    }

    public function authorize(BeamUxRelease $release, object $subject, Authenticatable $actor, ReleaseAccess $access): void
    {
        self::$calls[] = "authorize:{$access->name}";
    }

    public function admit(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void
    {
        self::$calls[] = "admit:{$subject->ref}={$member->version_id}";
    }

    public function columns(object $subject): array
    {
        return ['observed_pin' => 'observed-'.$subject->ref];
    }

    public function moved(object $subject, BeamUxReleaseMember $member): bool
    {
        self::$calls[] = "moved:{$subject->ref}";

        return false;
    }

    public function assertPublishable(BeamUxRelease $release, object $subject, BeamUxReleaseMember $member): void
    {
        self::$calls[] = "publishable:{$subject->ref}";
    }

    public function publish(BeamUxRelease $release, Collection $members, Authenticatable $actor, TransitionContext $context): void
    {
        foreach ($members as $member) {
            self::$calls[] = "publish:{$member->ref()}={$member->version_id}";
        }
    }

    public function rollbackRef(BeamUxReleaseMember $member): ?string
    {
        return $member->observed_pin;
    }
}
