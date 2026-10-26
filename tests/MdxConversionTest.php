<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Auth\User;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Compile\CompileEntryBody;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Compile\NodeEntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry as Entry;
use Splicewire\Beam\Mdx\Legal\LegalComponentsSeeder;
use Splicewire\Beam\Mdx\Legal\LegalTexts;
use Splicewire\Beam\Mdx\Lens\MdxLens;
use Splicewire\Beam\Mdx\MdxBody;
use Splicewire\Beam\Ux\Components\AuthoredComponents;
use Splicewire\Beam\Ux\Convert\StoredMdxConversion;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Models\BeamUxRelease;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Release\ReleaseService;
use Splicewire\Beam\Ux\Seed\SeedsEntries;

/**
 * ADR-0233 d8, d9 (EP-27 slice 3; rulings 1, 4): a host's stored MDX pages convert once into one open Release. Each
 * page that holds source gets a version holding blocks, its fragments placed as components; a host fragment a page
 * places becomes its own Authored Component in the same Release; a pending draft is converted on top; a page that would
 * change is left out and reported. The Release is previewed and published by an author, never by the command.
 */
class MdxConversionTest extends AuthoredComponentNativeTestCase
{
    private string $root;

    private ConversionLens $lens;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();
        $this->runBeamWorkflowsMigrations();
        foreach (['create_beam_ux_releases_table', 'add_origin_to_beam_ux_releases_table'] as $stub) {
            (require dirname(__DIR__)."/database/migrations/shared/{$stub}.php.stub")->up();
        }
        // An authenticated author may do anything here; nobody signed in may do nothing beyond what the package's own
        // rules allow. The command runs with no one signed in, so each conversion runs that way too ({@see convertSignedOut()}).
        Gate::before(fn ($user = null) => $user !== null ? true : null);
        Gate::define('create', fn ($user = null) => $user !== null);
        Gate::define('update', fn ($user = null) => $user !== null);
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
        // The fixture is written as the author; each conversion then runs with nobody signed in ({@see convertSignedOut()}).
        $this->actingAs($this->author);
        $this->lens = new ConversionLens;
        $this->app->instance(MdxLens::class, $this->lens);
        $this->root = sys_get_temp_dir().'/mdx-stored-'.Str::random(8);
        @mkdir($this->root.'/fragments', 0777, true);
        file_put_contents($this->root.'/fragments/reading-disclaimer.mdx', "---\ntitle: Reading disclaimer\n---\n\nRead this first.\n");
        $this->lens->register('Read this first.', [$this->p('Read this first.')]);
        // The host's copy of a shipped legal text, saying what the shipped component says.
        file_put_contents($this->root.'/fragments/legal-engine.mdx', "---\ntitle: Platform\n---\n\n".$this->engine()."\n");
        $this->lens->register($this->engine(), [$this->p($this->engine())]);
        (new LegalComponentsSeeder)->run();
    }

    protected function tearDown(): void
    {
        // Scratch fixtures under the system temp dir, made by this test: moved aside, never deleted.
        if (is_dir($this->root)) {
            rename($this->root, $this->root.'.done');
        }
        parent::tearDown();
    }

    public function test_stored_pages_convert_into_one_open_release_with_the_components_they_place(): void
    {
        $about = $this->sourcePage('about', 'About', "export const meta = { navGroup: 'Company' }\n\nIntro.\n\n<Content name=\"fragments/legal-engine\" />\n\n<Content name=\"fragments/reading-disclaimer\" />\n",
            [$this->p('Intro.'), $this->content('fragments/legal-engine'), $this->content('fragments/reading-disclaimer')],
            "nav_order: 3\n", ['meta' => "export const meta = { navGroup: 'Company' }", 'metaData' => ['navGroup' => 'Company']]);
        $pinned = $about->published_version;
        // A setting the page serves from its columns that its frontmatter doesn't name.
        $about->forceFill(['seo_description' => 'Who we are, briefly.'])->save();
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame(['about'], $report['pages']);
        $this->assertSame(['ReadingDisclaimer' => 'fragments/reading-disclaimer'], $report['components']);
        $release = BeamUxRelease::query()->findOrFail($report['release']);
        $this->assertSame('draft', $release->status, 'left open, for an author to preview and publish');
        $component = BeamUxEntry::query()->where('component_name', 'ReadingDisclaimer')->orWhere('slug', 'reading-disclaimer')->firstOrFail();
        $this->assertEqualsCanonicalizing([$about->id, $component->id], $release->members()->pluck('entry_id')->all());
        $this->assertSame($pinned, $about->fresh()->published_version, 'nothing is published by the conversion');

        app(ReleaseService::class)->transition($release, 'publish', $this->author);
        $published = $about->fresh();
        $body = app(\Rushing\Versioning\Contracts\VersionStore::class)->version($published->particle, $published->published_version)->snapshot['payload'];
        $this->assertSame(['p', 'LegalEngine', 'ReadingDisclaimer'], array_column($body, 'name'), 'published: the blocks, fragments as components');
        $this->assertSame('ReadingDisclaimer', $component->fresh()->getAttribute('published_component_name'));
        $this->assertSame([3, 'Company'], [$published->nav_order, $published->nav_group], 'its settings: the frontmatter, and its static meta folded in as a file\'s is');
        // Publish sets every setting from the version, so what the header doesn't name stays as the page served it.
        $this->assertSame(['about', 'Who we are, briefly.', 'About'], [$published->segment, $published->seo_description, $published->title]);
    }

    public function test_a_pending_draft_converts_on_top_and_a_page_that_would_change_is_left_out(): void
    {
        $notes = $this->sourcePage('notes', 'Notes', "Published words.\n", [$this->p('Published words.')]);
        $pub = app(EntryPublication::class);
        $pub->recordDraft($notes->fresh(), MdxBody::encode("---\ntitle: Notes\n---\n\nDraft words.\n"));
        $this->lens->register('Draft words.', [$this->p('Draft words.')]);
        $this->sourcePage('lossy', 'Lossy', "Lose me.\n", [$this->p('Lose me.')]);
        $this->lens->lossy = 'Lose me.';
        $canvas = $this->sourcePage('canvas', 'Canvas', "Old words.\n", [$this->p('Old words.')]);
        $this->holdBlocks();
        $pub->recordDraft($canvas->fresh(), [$this->p('Canvas words.')]);
        $canvasHead = $pub->state($canvas->fresh())->headVersion;

        $report = $this->convertSignedOut();

        $this->assertSame(['notes'], $report['pages']);
        $this->assertStringContainsString('would change', $report['leftOut']['lossy']);
        // A draft already holding blocks is an author's canvas work: converting the published version on top would bury it.
        $this->assertStringContainsString('already holds blocks', $report['leftOut']['canvas']);
        $this->assertSame($canvasHead, $pub->state($canvas->fresh())->headVersion);
        $member = BeamUxRelease::query()->findOrFail($report['release'])->members()->where('entry_id', $notes->id)->value('version_id');
        $state = $pub->state($notes->fresh());
        $this->assertNotSame($member, $state->headVersion, 'the draft is converted on top of the converted published version');
        $this->assertSame('Draft words.', app(AuthoredComponents::class)->working($notes->fresh())[0]['children'][0]['value'] ?? null);
    }

    public function test_a_same_named_component_in_another_realm_never_stands_in_for_the_fragment(): void
    {
        // Review of 31d62e3d: delivery resolves a tag only within the page's realm, so another realm's component can't serve it.
        $elsewhere = new class
        {
            use SeedsEntries;

            public function run(array $body): ?BeamUxEntry
            {
                return $this->seedComponent('app-reading-disclaimer', $body, ['realm' => 'app'], origin: 'package:acme/app');
            }
        };
        $elsewhere->run([$this->p('Another realm.') + ['component' => ['name' => 'ReadingDisclaimer', 'props' => []]]]);
        $about = $this->sourcePage('about', 'About', "<Content name=\"fragments/reading-disclaimer\" />\n", [$this->content('fragments/reading-disclaimer')]);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame(['ReadingDisclaimer' => 'fragments/reading-disclaimer'], $report['components'], 'made in the page\'s realm');
        $made = BeamUxEntry::query()->where('realm', 'site')->where('slug', 'reading-disclaimer')->firstOrFail();
        $this->assertEqualsCanonicalizing([$about->id, $made->id], BeamUxRelease::query()->findOrFail($report['release'])->members()->pluck('entry_id')->all());
    }

    public function test_an_unpublished_component_of_that_name_leaves_the_page_out(): void
    {
        // Review of 31d62e3d: a draft resolves only in a Preview that holds it, so it can't silently answer for the fragment.
        file_put_contents($this->root.'/fragments/notice.mdx', "Notice text.\n");
        $this->lens->register('Notice text.', [$this->p('Notice text.')]);
        $draft = BeamUxEntry::create(['slug' => 'notice-draft', 'title' => 'Notice', 'type' => 'component', 'format' => 'tsx',
            'component_kind' => 'authored', 'realm' => 'site']);
        app(EntryPublication::class)->recordDraft($draft, [$this->p('Unfinished.') + ['component' => ['name' => 'Notice', 'props' => []]]]);
        $this->sourcePage('notes', 'Notes', "<Content name=\"fragments/notice\" />\n", [$this->content('fragments/notice')]);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame([], $report['pages']);
        $this->assertStringContainsString("isn't published", $report['leftOut']['notes']);
        $this->assertNull($report['release']);
        $this->assertSame(1, BeamUxEntry::query()->where('component_name', 'Notice')->count(), 'no second Notice is made');
    }

    public function test_two_fragments_that_would_become_one_tag_leave_their_pages_out(): void
    {
        // Review of 5e36ce2a: both paths become <LegalNote />, and only one could be made, so a page would place the wrong one.
        @mkdir($this->root.'/partials', 0777, true);
        file_put_contents($this->root.'/fragments/legal-note.mdx', "One note.\n");
        file_put_contents($this->root.'/partials/legal_note.mdx', "Another note.\n");
        $this->lens->register('One note.', [$this->p('One note.')]);
        $this->lens->register('Another note.', [$this->p('Another note.')]);
        $this->sourcePage('first', 'First', "<Content name=\"fragments/legal-note\" />\n", [$this->content('fragments/legal-note')]);
        $this->sourcePage('second', 'Second', "<Content name=\"partials/legal_note\" />\n", [$this->content('partials/legal_note')]);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame([], $report['pages']);
        $this->assertSame([], $report['components']);
        $this->assertStringContainsString('would also become <LegalNote />', $report['leftOut']['first']);
        $this->assertStringContainsString('would also become <LegalNote />', $report['leftOut']['second']);
        $this->assertSame(0, BeamUxEntry::query()->where('component_name', 'LegalNote')->count());
    }

    public function test_a_legal_text_is_never_made_from_the_host_s_own_copy(): void
    {
        // d8: the four ship once from laravel-beam-mdx. Without them installed, the page waits; no host LegalEngine is made.
        BeamUxEntry::query()->where('namespace', 'beam-mdx')->update(['realm' => 'elsewhere']);
        file_put_contents($this->root.'/fragments/legal-engine.mdx', "This site runs on the Splicewire platform.\n");
        $this->lens->register('This site runs on the Splicewire platform.', [$this->p('This site runs on the Splicewire platform.')]);
        $this->sourcePage('terms', 'Terms', "<Content name=\"fragments/legal-engine\" />\n", [$this->content('fragments/legal-engine')]);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertStringContainsString('seed laravel-beam-mdx', $report['leftOut']['terms']);
        $this->assertSame(0, BeamUxEntry::query()->where('realm', 'site')->where('component_name', 'LegalEngine')->count());
    }

    public function test_a_page_whose_fragment_shows_other_text_than_its_component_is_left_out(): void
    {
        // Review of 34327e06 (ruling 1): the Release delivers the realm's published LegalEngine, so a copy that drifted from it can't become it.
        @mkdir($this->root.'/partials', 0777, true);
        file_put_contents($this->root.'/partials/legal-engine.mdx', "Our platform, our own words.\n");
        $this->lens->register('Our platform, our own words.', [$this->p('Our platform, our own words.')]);
        $this->sourcePage('same', 'Same', "<Content name=\"fragments/legal-engine\" />\n", [$this->content('fragments/legal-engine')]);
        $this->sourcePage('drifted', 'Drifted', "<Content name=\"partials/legal-engine\" />\n", [$this->content('partials/legal-engine')]);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame(['same'], $report['pages'], 'the copy that says what the shipped component says converts');
        $this->assertStringContainsString("doesn't render what partials/legal-engine renders", $report['leftOut']['drifted']);
    }

    public function test_a_published_component_with_the_same_words_but_other_marks_is_not_reused(): void
    {
        // Review of 2b8c3154 (ruling 1 is blocks-read equal): the fragment emphasizes a word the published component doesn't.
        $published = new class
        {
            use SeedsEntries;

            public function run(array $body): ?BeamUxEntry
            {
                return $this->seedComponent('reading-disclaimer', $body, [], origin: 'package:acme/site');
            }
        };
        $published->run([$this->p('Read this first.') + ['component' => ['name' => 'ReadingDisclaimer', 'props' => []]]]);
        file_put_contents($this->root.'/fragments/reading-disclaimer.mdx', "Read this *first*.\n");
        $this->lens->register('Read this *first*.', [['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'dynamic' => false, 'children' => [
            ['kind' => 'text', 'value' => 'Read this '],
            ['kind' => 'block', 'name' => 'em', 'isComponent' => false, 'props' => [], 'dynamic' => false, 'children' => [['kind' => 'text', 'value' => 'first']]],
            ['kind' => 'text', 'value' => '.'],
        ]]]);
        $this->sourcePage('notes', 'Notes', "<Content name=\"fragments/reading-disclaimer\" />\n", [$this->content('fragments/reading-disclaimer')]);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame([], $report['pages']);
        $this->assertStringContainsString("doesn't render what fragments/reading-disclaimer renders", $report['leftOut']['notes']);
    }

    public function test_a_page_the_write_refuses_is_left_out_and_the_rest_convert(): void
    {
        // Found on a fresh starter: a stored page placing a code component a blocks body can't place there (there,
        // laravel-beam-docs' <SiteNav />, since declared by EP-42 S1). The write refused it and the whole conversion
        // stopped halfway, with a component already made. <HostWidget /> is declared nowhere.
        $nav = $this->sourcePage('nav', 'Nav', "<HostWidget />\n\n<Content name=\"fragments/reading-disclaimer\" />\n",
            [['kind' => 'block', 'name' => 'HostWidget', 'isComponent' => true, 'props' => [], 'children' => [], 'dynamic' => false], $this->content('fragments/reading-disclaimer')]);
        $head = app(EntryPublication::class)->state($nav)->headVersion;
        $this->sourcePage('notes', 'Notes', "Published words.\n", [$this->p('Published words.')]);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame(['notes'], $report['pages']);
        $this->assertStringContainsString('unavailable in this realm', $report['leftOut']['nav'] ?? '');
        $this->assertSame($head, app(EntryPublication::class)->state($nav->fresh())->headVersion, 'nothing is written for a page the write refuses');
        $this->assertSame([], $report['components'], 'its only page was refused, so its component is not made');
        $this->assertSame(0, BeamUxEntry::query()->where('slug', 'reading-disclaimer')->count());
        $this->assertSame([BeamUxEntry::query()->where('slug', 'notes')->value('id')],
            BeamUxRelease::query()->findOrFail($report['release'])->members()->pluck('entry_id')->all());
    }

    public function test_a_pristine_package_page_is_the_package_s_to_convert_and_an_edited_one_converts(): void
    {
        // orch-lead's ruling (EP-27 receipt): converting a page its package still asserts would make it a site edit and stop
        // re-assertion, so it is left out; one the site already edited is the site's, and converts.
        $this->sourcePage('shipped', 'Shipped', "Shipped words.\n", [$this->p('Shipped words.')], origin: 'package:acme/docs');
        $edited = $this->sourcePage('edited', 'Edited', "Shipped too.\n", [$this->p('Shipped too.')], origin: 'package:acme/docs');
        $this->lens->register('Our words.', [$this->p('Our words.')]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($edited->fresh(), $pub->recordDraft($edited->fresh(), MdxBody::encode("---\ntitle: Edited\n---\n\nOur words.\n"))->headVersion);
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $this->assertSame(['edited'], $report['pages']);
        $this->assertStringContainsString('the package owns it', $report['leftOut']['shipped']);
    }

    /**
     * orch-lead's ruling (EP-42 H2 receipt, 2026-10-26): a host's disk page converts from the same source its file is
     * rewritten from, so the two agree by construction. Its asserted hash is re-stamped over the converted body, the value
     * pristine-ness and re-assert compare, when that body prints byte-equal to the file's body (the separator line after
     * its header aside) and no draft is pending. Otherwise the doctor reported all 32 of the flagship's pages diverged,
     * and a later edit to a file never flowed.
     */
    public function test_a_converted_disk_page_agrees_with_its_file_so_a_later_edit_to_the_file_flows(): void
    {
        $page = $this->diskPage('Disk words.');
        $this->holdBlocks();

        $report = $this->convertSignedOut();

        $reasserter = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class);
        $this->assertTrue($reasserter->isPristine($page->fresh()), 'converted: it agrees with its file');
        app(ReleaseService::class)->transition(BeamUxRelease::query()->findOrFail($report['release']), 'publish', $this->author);
        $this->assertTrue($reasserter->isPristine($page->fresh()), 'and once published');

        $this->lens->register('Edited on disk.', [$this->p('Edited on disk.')]);
        file_put_contents($this->root.'/docs/guide.mdx', "---\ntitle: Guide\n---\n\nEdited on disk.\n");
        $this->assertTrue($reasserter->reassert($page->fresh(), 'Guide', (string) file_get_contents($this->root.'/docs/guide.mdx'), 'disk:docs/guide.mdx', [$this->p('Edited on disk.')]));
        $this->assertSame('Edited on disk.', $this->publishedOf($page)[0]['children'][0]['value']);
    }

    public function test_an_author_edited_converted_page_stays_diverged_and_keeps_its_words(): void
    {
        $page = $this->diskPage('Disk words.');
        $this->holdBlocks();
        $report = $this->convertSignedOut();
        app(ReleaseService::class)->transition(BeamUxRelease::query()->findOrFail($report['release']), 'publish', $this->author);

        $this->lens->register('Our words.', [$this->p('Our words.')]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->p('Our words.')])->headVersion);

        $reasserter = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class);
        $this->assertFalse($reasserter->isPristine($page->fresh()), 'an author\'s edit is real');
        $this->assertFalse($reasserter->reassert($page->fresh(), 'Guide', (string) file_get_contents($this->root.'/docs/guide.mdx'), 'disk:docs/guide.mdx', [$this->p('Disk words.')]));
        $this->assertSame('Our words.', $this->publishedOf($page)[0]['children'][0]['value']);
    }

    public function test_a_disk_page_with_a_pending_draft_is_not_restamped(): void
    {
        $page = $this->diskPage('Disk words.');
        $this->lens->register('Draft words.', [$this->p('Draft words.')]);
        app(EntryPublication::class)->recordDraft($page->fresh(), MdxBody::encode("---\ntitle: Guide\n---\n\nDraft words.\n"));
        $this->holdBlocks();

        $this->convertSignedOut();

        $this->assertFalse(app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->isPristine($page->fresh()), 'the pending draft is the working body');
    }

    public function test_a_disk_page_whose_file_cannot_be_read_is_not_restamped(): void
    {
        // review-r1: a read that fails is not an empty file. Byte-equality is the precondition, so nothing read, nothing stamped.
        $page = $this->diskPage('Disk words.');
        chmod($this->root.'/docs/guide.mdx', 0);
        $this->holdBlocks();
        try {
            $report = $this->convertSignedOut();
        } finally {
            chmod($this->root.'/docs/guide.mdx', 0644);
        }

        $this->assertSame(['guide'], $report['pages'], 'the page still converts');
        $this->assertFalse(app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->isPristine($page->fresh()));
    }

    /**
     * EP-42 GO 46 (the flagship's docs.build.capabilities): a row still holding what its file last asserted, whose file
     * has since moved on without a re-assert. The conversion converts the STORED body, so the converted body differs
     * from the file, and the byte-equality re-stamp declined. That left the row's hash naming its old source form, so it
     * read as edited and a re-assert could never bring the newer file. Converting is a change of form, not an edit, so a
     * row that was pristine stays pristine over its converted body, and the next re-assert brings the file.
     */
    public function test_a_pristine_page_whose_file_moved_on_stays_pristine_and_the_next_reassert_brings_the_file(): void
    {
        $page = $this->diskPage('Old words.');
        $this->lens->register('New words.', [$this->p('New words.')]);
        file_put_contents($this->root.'/docs/guide.mdx', "---\ntitle: Guide\n---\n\nNew words.\n");
        $this->holdBlocks();

        $report = $this->convertSignedOut();
        app(ReleaseService::class)->transition(BeamUxRelease::query()->findOrFail($report['release']), 'publish', $this->author);

        $reasserter = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class);
        $this->assertSame('Old words.', $this->publishedOf($page)[0]['children'][0]['value'], 'it converts the words it held');
        $this->assertTrue($reasserter->isPristine($page->fresh()), 'still what its file last asserted, in its new form');
        $this->assertTrue($reasserter->reassert($page->fresh(), 'Guide', (string) file_get_contents($this->root.'/docs/guide.mdx'), 'disk:docs/guide.mdx', [$this->p('New words.')]));
        $this->assertSame('New words.', $this->publishedOf($page)[0]['children'][0]['value'], 'and the next re-assert brings the file');
    }

    /** The same, for a row an author had already edited before the conversion: its words are theirs, and stay. */
    public function test_a_page_edited_before_the_conversion_stays_diverged_and_keeps_its_words(): void
    {
        $page = $this->diskPage('Disk words.');
        $this->lens->register('Our words.', [$this->p('Our words.')]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), MdxBody::encode("---\ntitle: Guide\n---\n\nOur words.\n"))->headVersion);
        $this->holdBlocks();

        $report = $this->convertSignedOut();
        app(ReleaseService::class)->transition(BeamUxRelease::query()->findOrFail($report['release']), 'publish', $this->author);

        $reasserter = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class);
        $this->assertSame('Our words.', $this->publishedOf($page)[0]['children'][0]['value']);
        $this->assertFalse($reasserter->isPristine($page->fresh()), 'an author\'s edit is real');
        $this->assertFalse($reasserter->reassert($page->fresh(), 'Guide', (string) file_get_contents($this->root.'/docs/guide.mdx'), 'disk:docs/guide.mdx', [$this->p('Disk words.')]));
        $this->assertSame('Our words.', $this->publishedOf($page)[0]['children'][0]['value']);
    }

    /**
     * EP-42 GO 46 follow-up: a page converted before the carry-through (the flagship's docs.build.capabilities) still
     * hashes to the source form it held, so it reads as edited and a re-assert can never bring its file. `--restamp`
     * re-stamps such a page over its converted body when both hold: the version before its conversion is exactly what its
     * origin asserted, and every version since is the conversion's own. Anything else is an edit, and stays.
     */
    public function test_restamp_restamps_a_page_a_conversion_left_unstamped_and_the_next_reassert_brings_the_file(): void
    {
        $page = $this->unstampedConversion('Old words.', 'New words.');
        $reasserter = app(\Splicewire\Beam\Ux\Provenance\Reasserter::class);
        $this->assertFalse($reasserter->isPristine($page->fresh()), 'as a conversion before the carry-through left it');
        $stamp = (string) $page->fresh()->asserted_hash;

        $this->artisan('splicewire:beam:ux:convert-mdx', ['--restamp' => true, '--dry-run' => true])
            ->expectsOutputToContain('Would re-stamp 1 page(s)')
            ->expectsOutputToContain('page guide')
            ->assertExitCode(0);
        $this->assertSame($stamp, (string) $page->fresh()->asserted_hash, 'a dry run writes nothing');

        $this->artisan('splicewire:beam:ux:convert-mdx', ['--restamp' => true])
            ->expectsOutputToContain('Re-stamped 1 page(s)')
            ->assertExitCode(0);
        $this->assertTrue($reasserter->isPristine($page->fresh()));
        $this->assertTrue($reasserter->reassert($page->fresh(), 'Guide', (string) file_get_contents($this->root.'/docs/guide.mdx'), 'disk:docs/guide.mdx', [$this->p('New words.')]));
        $this->assertSame('New words.', $this->publishedOf($page)[0]['children'][0]['value'], 'the next re-assert brings the file');
    }

    public function test_restamp_leaves_a_page_edited_after_its_conversion(): void
    {
        $page = $this->unstampedConversion('Old words.', 'New words.');
        $this->lens->register('Our words.', [$this->p('Our words.')]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [$this->p('Our words.')])->headVersion);
        $stamp = (string) $page->fresh()->asserted_hash;

        $this->artisan('splicewire:beam:ux:convert-mdx', ['--restamp' => true])
            ->expectsOutputToContain('Re-stamped 0 page(s)')
            ->expectsOutputToContain('left as it is: guide: edited since its conversion')
            ->assertExitCode(0);
        $this->assertSame($stamp, (string) $page->fresh()->asserted_hash);
        $this->assertSame('Our words.', $this->publishedOf($page)[0]['children'][0]['value']);
    }

    public function test_restamp_leaves_a_page_that_was_not_what_its_origin_asserted_before_its_conversion(): void
    {
        $page = $this->diskPage('Disk words.');
        $this->lens->register('Our words.', [$this->p('Our words.')]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), MdxBody::encode("---\ntitle: Guide\n---\n\nOur words.\n"))->headVersion);
        $this->holdBlocks();
        $report = $this->convertSignedOut();
        app(ReleaseService::class)->transition(BeamUxRelease::query()->findOrFail($report['release']), 'publish', $this->author);
        $stamp = (string) $page->fresh()->asserted_hash;

        $this->artisan('splicewire:beam:ux:convert-mdx', ['--restamp' => true])
            ->expectsOutputToContain('Re-stamped 0 page(s)')
            ->expectsOutputToContain('left as it is: guide: before its conversion it was not what its origin asserted')
            ->assertExitCode(0);
        $this->assertSame($stamp, (string) $page->fresh()->asserted_hash);
        $this->assertFalse(app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->isPristine($page->fresh()));
    }

    /**
     * A disk page holding `$held`, whose file has since moved on to `$file`, converted and published the way a conversion
     * before the carry-through left it: its hash still names the source it held.
     */
    private function unstampedConversion(string $held, string $file): BeamUxEntry
    {
        $page = $this->diskPage($held);
        $asserted = (string) $page->fresh()->asserted_hash;
        $this->lens->register($file, [$this->p($file)]);
        file_put_contents($this->root.'/docs/guide.mdx', "---\ntitle: Guide\n---\n\n{$file}\n");
        $this->holdBlocks();
        $report = $this->convertSignedOut();
        app(ReleaseService::class)->transition(BeamUxRelease::query()->findOrFail($report['release']), 'publish', $this->author);
        $page->fresh()->forceFill(['asserted_hash' => $asserted])->save();

        return $page->fresh();
    }

    /** A host's page registered from `docs/guide.mdx`, and that file as the files half leaves it: its header, then the body. */
    private function diskPage(string $words): BeamUxEntry
    {
        $page = $this->sourcePage('guide', 'Guide', "{$words}\n", [$this->p($words)], origin: 'disk:docs/guide.mdx');
        @mkdir($this->root.'/docs', 0777, true);
        file_put_contents($this->root.'/docs/guide.mdx', "---\ntitle: Guide\n---\n\n{$words}\n");

        return $page;
    }

    private function publishedOf(BeamUxEntry $page): array
    {
        $fresh = $page->fresh();

        return app(\Rushing\Versioning\Contracts\VersionStore::class)->version($fresh->particle, $fresh->published_version)->snapshot['payload'];
    }

    public function test_the_command_needs_blocks_on_and_a_dry_run_writes_nothing(): void
    {
        $this->sourcePage('about', 'About', "<Content name=\"fragments/legal-engine\" />\n", [$this->content('fragments/legal-engine')]);

        Auth::forgetUser();
        $this->artisan('splicewire:beam:ux:convert-mdx', ['--path' => $this->root, '--dry-run' => true])->assertExitCode(1);
        $this->holdBlocks();
        // A dry run rehearses the writes, and a Release records who made it, so it names its author as the real run does.
        $this->artisan('splicewire:beam:ux:convert-mdx', ['--path' => $this->root, '--dry-run' => true])
            ->expectsOutputToContain('Name the author')
            ->assertExitCode(1);
        $this->artisan('splicewire:beam:ux:convert-mdx', ['--path' => $this->root, '--dry-run' => true, '--author' => 7])
            ->expectsOutputToContain('Would convert 1 stored MDX page(s)')
            ->expectsOutputToContain('page about')
            ->assertExitCode(0);

        $this->assertSame(0, BeamUxRelease::query()->count());
    }

    /**
     * EP-42 (ticket 00, 2026-10-26 07:10Z; orch-lead 15:07Z): the owner decides an adoption on the dry run's report (R2),
     * so it must say what the real run will do. On the flagship it reported 32 pages and the write then refused one: the
     * dry run stopped before the writes, and only the write checks a body against the page's profile.
     */
    public function test_a_dry_run_reports_the_page_the_write_would_refuse(): void
    {
        $nav = $this->sourcePage('nav', 'Nav', "<HostWidget />\n", [$this->hostWidget()]);
        $head = app(EntryPublication::class)->state($nav)->headVersion;
        $this->sourcePage('notes', 'Notes', "Published words.\n", [$this->p('Published words.')]);
        $this->holdBlocks();

        $report = $this->convertDry();

        $this->assertSame(['notes'], $report['pages']);
        $this->assertStringContainsString('the write would refuse it', $report['leftOut']['nav'] ?? '');
        $this->assertStringContainsString('unavailable in this realm', $report['leftOut']['nav'] ?? '');
        $this->assertNull($report['release']);
        $this->assertSame(0, BeamUxRelease::query()->count(), 'a dry run writes nothing');
        $this->assertSame($head, app(EntryPublication::class)->state($nav->fresh())->headVersion);
    }

    public function test_a_dry_run_reports_what_the_real_run_then_does(): void
    {
        $this->sourcePage('nav', 'Nav', "<HostWidget />\n", [$this->hostWidget()]);
        $this->sourcePage('about', 'About', "Intro.\n\n<Content name=\"fragments/reading-disclaimer\" />\n",
            [$this->p('Intro.'), $this->content('fragments/reading-disclaimer')]);
        $this->sourcePage('notes', 'Notes', "Published words.\n", [$this->p('Published words.')]);
        $this->holdBlocks();

        $dry = $this->convertDry();
        $real = $this->convertSignedOut();

        $this->assertSame($real['pages'], $dry['pages']);
        $this->assertSame($real['components'], $dry['components']);
        $this->assertSame(array_keys($real['leftOut']), array_keys($dry['leftOut']));
        $this->assertSame(str_replace('the write refused it', 'the write would refuse it', $real['leftOut']['nav']), $dry['leftOut']['nav']);
    }

    /**
     * The real run makes a host fragment's component in the same transaction, before the page that places it is written,
     * so that page's profile already holds the component. A dry run that only called the write's checks would refuse it.
     */
    public function test_a_dry_run_does_not_refuse_a_page_for_the_component_it_would_make(): void
    {
        $this->sourcePage('about', 'About', "Intro.\n\n<Content name=\"fragments/reading-disclaimer\" />\n",
            [$this->p('Intro.'), $this->content('fragments/reading-disclaimer')]);
        $this->holdBlocks();

        $report = $this->convertDry();

        $this->assertSame(['about'], $report['pages']);
        $this->assertSame(['ReadingDisclaimer' => 'fragments/reading-disclaimer'], $report['components']);
        $this->assertSame(0, Entry::query()->where('slug', 'reading-disclaimer')->count(), 'made in the rehearsal, rolled back');
    }

    /**
     * A member's artifact is compiled as it joins the Release, and a compile failure isn't a ValidationException: it
     * stopped the whole real run half way (after the files half had written). It leaves that page out, like a refusal.
     */
    public function test_a_page_whose_body_will_not_compile_is_left_out_and_a_dry_run_says_so(): void
    {
        $this->sourcePage('broken', 'Broken', "Uncompilable.\n", [$this->p('Uncompilable.')]);
        $this->sourcePage('notes', 'Notes', "Published words.\n", [$this->p('Published words.')]);
        $this->holdBlocks();
        $this->app->singleton(EntryBodyCompiler::class, fn () => new RefusingCompiler(
            new NodeEntryBodyCompiler(workingDirectory: __DIR__.'/Fixtures/entry-body-host'), 'Uncompilable.'));
        $this->app->forgetInstance(CompileEntryBody::class);

        $dry = $this->convertDry();
        $real = $this->convertSignedOut();

        foreach (['dry' => $dry, 'real' => $real] as $run => $report) {
            $this->assertSame(['notes'], $report['pages'], $run);
            $this->assertStringContainsString("doesn't compile", $report['leftOut']['broken'] ?? '', $run);
        }
    }

    /**
     * orch-lead 15:07Z: the rehearsal contains every effect outside the database. A host's own reactions to an entry write
     * (a queued job, a mail, a notification, a cache write, a broadcast) and beam-ux's mirror and artifact files never
     * happen on a dry run. The real run below proves each probe is live.
     */
    public function test_a_dry_run_fires_nothing_outside_the_database(): void
    {
        $this->sourcePage('about', 'About', "Intro.\n\n<Content name=\"fragments/reading-disclaimer\" />\n",
            [$this->p('Intro.'), $this->content('fragments/reading-disclaimer')]);
        $this->holdBlocks();
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Entry::saved(function (): void {
            dispatch(new RehearsalProbeJob);
            Mail::to('probe@example.test')->send(new RehearsalProbeMail);
            Notification::route('mail', 'probe@example.test')->notify(new RehearsalProbeNotification);
            Cache::put('rehearsal-probe', true);
            broadcast(new RehearsalProbeBroadcast);
        });
        $versions = (new \Rushing\Versioning\Models\Version)->getTable();
        $rows = fn () => [Entry::query()->count(), BeamUxRelease::query()->count(), DB::table($versions)->count()];
        $files = fn () => [Storage::disk('component-mirror')->allFiles(), Storage::disk('component-artifacts')->allFiles()];
        [$rowsBefore, $filesBefore] = [$rows(), $files()];

        $report = $this->convertDry();

        $this->assertSame(['about'], $report['pages'], 'the rehearsal ran the writes');
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertFalse(Cache::has('rehearsal-probe'));
        $this->assertSame($rowsBefore, $rows(), 'no row survives the rehearsal');
        $this->assertSame($filesBefore, $files(), 'no mirror or artifact file is written');

        $this->convertSignedOut();

        Bus::assertDispatched(RehearsalProbeJob::class);
        Bus::assertDispatched(BroadcastEvent::class);
        Mail::assertSent(RehearsalProbeMail::class);
        Notification::assertSentOnDemand(RehearsalProbeNotification::class);
        $this->assertTrue(Cache::has('rehearsal-probe'));
        $this->assertNotSame($filesBefore[1], $files()[1], 'the real run compiles its artifacts');
    }

    /**
     * orch-lead 15:07Z: a page's file is written only once its stored page has converted. The files half ran first, so
     * a page the stored write refused kept a reprinted file over a row still holding source. The converted page is still
     * re-stamped against its file: the text the file is about to be written as.
     */
    public function test_the_command_writes_a_page_s_file_only_once_its_stored_page_converts(): void
    {
        $guide = $this->diskSource('guide', 'Guide', 'docs/guide.mdx', 'Disk words.', [$this->p('Disk words.')]);
        $this->diskSource('nav', 'Nav', 'docs/nav.mdx', '<HostWidget />', [$this->hostWidget()]);
        $navBefore = file_get_contents($this->root.'/docs/nav.mdx');
        $this->holdBlocks();

        Auth::forgetUser();
        $this->artisan('splicewire:beam:ux:convert-mdx', ['--path' => $this->root, '--author' => 7])
            ->expectsOutputToContain('left as it is: docs/nav.mdx: its stored page was left out')
            ->assertExitCode(0);
        $this->actingAs($this->author);

        $this->assertSame($navBefore, file_get_contents($this->root.'/docs/nav.mdx'), 'a refused page keeps its file');
        $this->assertSame("---\ntitle: Guide\n---\n\nDisk words.\n", file_get_contents($this->root.'/docs/guide.mdx'));
        $this->assertTrue(app(\Splicewire\Beam\Ux\Provenance\Reasserter::class)->isPristine($guide->fresh()), 're-stamped against the file it is written as');
    }

    private function engine(): string
    {
        return LegalTexts::all()['legal-engine']['text'];
    }

    /** The conversion as the command runs it: nobody signed in, so its writes must act as the author it is handed. */
    private function convertSignedOut(): array
    {
        Auth::forgetUser();
        try {
            return app(StoredMdxConversion::class)->convert($this->root, $this->author, write: true);
        } finally {
            $this->actingAs($this->author);
        }
    }

    /** The dry run as the command runs it: nobody signed in, rehearsing as the author it is handed. */
    private function convertDry(): array
    {
        Auth::forgetUser();
        try {
            return app(StoredMdxConversion::class)->convert($this->root, $this->author, write: false);
        } finally {
            $this->actingAs($this->author);
        }
    }

    /** A code component declared nowhere, so the write refuses a body that places it (the fresh starter's <SiteNav />). */
    private function hostWidget(): array
    {
        return ['kind' => 'block', 'name' => 'HostWidget', 'isComponent' => true, 'props' => [], 'children' => [], 'dynamic' => false];
    }

    /** A host's page registered from `$relative`, whose file is not yet canonical (one blank line too many), so the files half rewrites it. */
    private function diskSource(string $slug, string $title, string $relative, string $words, array $doc): Entry
    {
        $page = $this->sourcePage($slug, $title, "{$words}\n", $doc, origin: "disk:{$relative}");
        @mkdir(dirname($this->root.'/'.$relative), 0777, true);
        file_put_contents($this->root.'/'.$relative, "---\ntitle: {$title}\n---\n\n\n{$words}\n");

        return $page;
    }

    /** The host turns `holds_blocks` on before it converts: the codecs are chosen afresh, as in the command's own process. */
    private function holdBlocks(): void
    {
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Codec\CodecRegistry::class);
    }

    private function sourcePage(string $slug, string $title, string $body, array $doc, string $frontmatter = '', array $meta = [], string $origin = 'cms'): BeamUxEntry
    {
        $this->lens->register($body, $doc, $meta);
        $seeder = new class
        {
            use SeedsEntries;

            public function run(string $slug, string $source, string $title): ?BeamUxEntry
            {
                return $this->seedPage($slug, $source, ['title' => $title, 'realm' => 'site', 'parent_id' => BeamUxEntry::rootFor()->id,
                    'segment' => $slug], UxFormat::Mdx, origin: $this->origin);
            }

            public string $origin = 'cms';
        };
        // A host's own page unless the test names a package: a pristine package page is the package's to convert.
        $seeder->origin = $origin;

        return $seeder->run($slug, "---\ntitle: {$title}\n{$frontmatter}---\n\n{$body}", $title)->fresh();
    }

    private function content(string $name): array
    {
        return ['kind' => 'block', 'name' => 'Content', 'isComponent' => true, 'props' => [['name' => 'name', 'kind' => 'string', 'value' => $name]], 'children' => [], 'dynamic' => false];
    }

    private function p(string $text): array
    {
        return ['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => $text]], 'dynamic' => false];
    }
}

/** A compiler that refuses any body holding `$marker`, as a real one refuses a body it can't build, and compiles the rest. */
final class RefusingCompiler implements EntryBodyCompiler
{
    public function __construct(private EntryBodyCompiler $compiler, private string $marker) {}

    public function compile(BeamUxEntry $entry, string $source): string
    {
        if (str_contains($source, $this->marker)) {
            throw CompilationFailed::for($entry, "it holds {$this->marker}");
        }

        return $this->compiler->compile($entry, $source);
    }

    public function handles(BeamUxEntry $entry): bool
    {
        return $this->compiler->handles($entry);
    }
}

/** A host's queued reaction to an entry write. */
final class RehearsalProbeJob implements ShouldQueue
{
    public function handle(): void {}
}

final class RehearsalProbeMail extends Mailable
{
    public function build(): self
    {
        return $this->subject('probe')->html('probe');
    }
}

final class RehearsalProbeNotification extends BaseNotification
{
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }
}

final class RehearsalProbeBroadcast implements ShouldBroadcastNow
{
    public function broadcastOn(): array
    {
        return [new Channel('probe')];
    }
}

/** An in-memory MDX Lens: registered bodies read as given (whitespace aside), and whatever it prints reads back exactly. */
final class ConversionLens implements MdxLens
{
    /** @var array<string, array{doc: array, meta: ?string, metaData: ?array}> */
    private array $reads = [];

    public ?string $lossy = null;

    /** @param  array{meta?: string, metaData?: array}  $meta  the `export const meta` the body holds, and its static value */
    public function register(string $body, array $doc, array $meta = []): void
    {
        $this->reads[trim($body)] = ['doc' => $doc, 'meta' => $meta['meta'] ?? null, 'metaData' => $meta['metaData'] ?? null];
    }

    public function read(string $body, array $context = []): array
    {
        return $this->reads[trim($body)] ?? throw new \RuntimeException('ConversionLens: unregistered '.json_encode($body));
    }

    /** Plain MDX the real compiler accepts: a paragraph's text, a component's tag. It reads back what it printed. */
    public function print(array $doc): string
    {
        if ($this->lossy !== null) {
            $doc = array_values(array_filter($doc, fn ($node) => ($node['children'][0]['value'] ?? null) !== $this->lossy));
        }
        $printed = implode("\n\n", array_map(fn (array $node) => ($node['isComponent'] ?? false) ? "<{$node['name']} />" : (string) ($node['children'][0]['value'] ?? ''), $doc))."\n";
        $this->reads[trim($printed)] = ['doc' => $doc, 'meta' => null, 'metaData' => null];

        return $printed;
    }
}
