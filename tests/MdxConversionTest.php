<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Splicewire\Beam\Mdx\Legal\LegalComponentsSeeder;
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
        Gate::before(fn ($user = null) => true);
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
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
        $this->lens = new ConversionLens;
        $this->app->instance(MdxLens::class, $this->lens);
        $this->root = sys_get_temp_dir().'/mdx-stored-'.Str::random(8);
        @mkdir($this->root.'/fragments', 0777, true);
        file_put_contents($this->root.'/fragments/reading-disclaimer.mdx', "---\ntitle: Reading disclaimer\n---\n\nRead this first.\n");
        $this->lens->register('Read this first.', [$this->p('Read this first.')]);
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
        $this->holdBlocks();

        $report = app(StoredMdxConversion::class)->convert($this->root, $this->author, write: true);

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

        $report = app(StoredMdxConversion::class)->convert($this->root, $this->author, write: true);

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

        $report = app(StoredMdxConversion::class)->convert($this->root, $this->author, write: true);

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

        $report = app(StoredMdxConversion::class)->convert($this->root, $this->author, write: true);

        $this->assertSame([], $report['pages']);
        $this->assertStringContainsString("isn't published", $report['leftOut']['notes']);
        $this->assertNull($report['release']);
        $this->assertSame(1, BeamUxEntry::query()->where('component_name', 'Notice')->count(), 'no second Notice is made');
    }

    public function test_the_command_needs_blocks_on_and_a_dry_run_writes_nothing(): void
    {
        $this->sourcePage('about', 'About', "<Content name=\"fragments/legal-engine\" />\n", [$this->content('fragments/legal-engine')]);

        $this->artisan('splicewire:beam:ux:convert-mdx', ['--path' => $this->root, '--dry-run' => true])->assertExitCode(1);
        $this->holdBlocks();
        $this->artisan('splicewire:beam:ux:convert-mdx', ['--path' => $this->root, '--dry-run' => true])
            ->expectsOutputToContain('Would convert 1 stored MDX page(s)')
            ->expectsOutputToContain('page about')
            ->assertExitCode(0);

        $this->assertSame(0, BeamUxRelease::query()->count());
    }

    /** The host turns `holds_blocks` on before it converts: the codecs are chosen afresh, as in the command's own process. */
    private function holdBlocks(): void
    {
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(\Splicewire\Beam\Ux\Codec\CodecRegistry::class);
    }

    private function sourcePage(string $slug, string $title, string $body, array $doc, string $frontmatter = '', array $meta = []): BeamUxEntry
    {
        $this->lens->register($body, $doc, $meta);
        $seeder = new class
        {
            use SeedsEntries;

            public function run(string $slug, string $source, string $title): ?BeamUxEntry
            {
                return $this->seedPage($slug, $source, ['title' => $title, 'realm' => 'site', 'parent_id' => BeamUxEntry::rootFor()->id,
                    'segment' => $slug], UxFormat::Mdx, origin: 'package:acme/site');
            }
        };

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
