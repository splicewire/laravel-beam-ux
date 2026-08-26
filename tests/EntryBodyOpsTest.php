<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyData;
use Splicewire\Beam\Ux\Data\BeamUxEntryBodyInputData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\EntryBodySaveOp;
use Splicewire\Beam\Ux\Particle\EntryBodyShowOp;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowShowOp;
use Splicewire\Beam\Ux\Workflow\EntryWorkflowTransitionOp;

/**
 * The entry-body transport as two particle operations (ADR-0214, beam-docs-satellite ticket 30).
 *
 * `handle()`/`respond()` are called directly rather than through `ParticleOperationController` — the
 * lighter tier `EntryWorkflowOpsTest` established, proving the op's own logic instead of re-testing
 * generic controller plumbing. What is asserted through the ATTRIBUTE instead is everything the
 * controller reads off the declaration and this test cannot otherwise reach: the kind, the shape slots,
 * and the authorization plane.
 *
 * The one thing this file deliberately does NOT re-prove is slug disambiguation. That was
 * `BeamUxEntryBodyControllerTest`'s whole subject and it is now unrepresentable: an operation is
 * addressed by `{id}` (ADR-0214 §2), so there is no ambiguous lookup left to tiebreak.
 */
class EntryBodyOpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    public function test_show_returns_the_declared_envelope_for_an_entry_with_no_particle_yet(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);

        $envelope = EntryBodyShowOp::handle($entry, new Request, actor: null);

        $this->assertInstanceOf(BeamUxEntryBodyData::class, $envelope);
        $this->assertSame((string) $entry->id, $envelope->id);
        $this->assertSame('about', $envelope->slug);
        $this->assertSame('page', $envelope->type);
        $this->assertSame([], $envelope->body);
        $this->assertNull($envelope->compileError);
    }

    public function test_a_theme_entry_falls_back_to_the_theme_schemas_when_it_declares_no_schema_ref(): void
    {
        // The one non-trivial branch of the schema resolution the controller carried: a `theme` entry's
        // body is a `{canvas,site}` token object, not a component's inferred props, so it has no
        // `schema_ref` and would otherwise seed the SchemaForm with nothing.
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'namespace' => 'theme']);

        $envelope = EntryBodyShowOp::handle($theme, new Request, actor: null);

        $this->assertIsArray($envelope->schema);
        $this->assertSame('Theme', $envelope->schema['title']);
        $this->assertSame(ThemeSchemas::canvas(), $envelope->schema['properties']['canvas']);
        $this->assertSame(ThemeSchemas::site(), $envelope->schema['properties']['site']);
    }

    public function test_an_inline_schema_ref_is_surfaced_by_value_over_the_theme_fallback(): void
    {
        $entry = BeamUxEntry::create([
            'slug' => 'hero',
            'type' => 'component',
            'schema_ref' => json_encode(['type' => 'object', 'title' => 'Hero']),
        ]);

        $this->assertSame('Hero', EntryBodyShowOp::handle($entry, new Request, actor: null)->schema['title']);
    }

    public function test_save_persists_the_body_binds_a_first_write_particle_id_and_reads_it_back(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);
        $this->assertNull($entry->particle_id);

        $request = Request::create('/beam-ux-entries/'.$entry->id.'/op/save-body', 'POST', [
            'body' => ['kind' => 'doc', 'children' => [['kind' => 'text', 'value' => 'hello']]],
        ]);

        $payload = EntryBodySaveOp::handle($entry, $request, actor: null);

        // The first-write binding is the step that makes the NEXT read find anything — an entry created
        // by `ScaffoldCommand` / `RegisterEntriesFromDisk` / a test has no particle until a save mints one.
        $this->assertNotSame('', $payload['key']);
        $this->assertSame($payload['key'], (string) $entry->fresh()->particle_id);

        $envelope = EntryBodySaveOp::respond($payload, $entry);

        $this->assertInstanceOf(BeamUxEntryBodyData::class, $envelope);
        $this->assertSame('hello', $envelope->body['children'][0]['value']);
        $this->assertSame((string) $entry->id, $envelope->id);
    }

    public function test_a_second_save_writes_to_the_bound_particle_rather_than_minting_another(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);

        $first = EntryBodySaveOp::handle($entry, $this->saveRequest($entry, ['v' => 1]), actor: null);
        $second = EntryBodySaveOp::handle($entry->fresh(), $this->saveRequest($entry, ['v' => 2]), actor: null);

        $this->assertSame($first['key'], $second['key']);
        $this->assertSame(2, EntryBodySaveOp::respond($second, $entry->fresh())->body['v']);
    }

    public function test_the_read_op_round_trips_what_the_write_op_saved(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'about', 'type' => 'page', 'namespace' => null]);

        EntryBodySaveOp::handle($entry, $this->saveRequest($entry, ['v' => 7]), actor: null);

        $this->assertSame(7, EntryBodyShowOp::handle($entry->fresh(), new Request, actor: null)->body['v']);
    }

    public function test_the_declared_input_rejects_a_payload_that_is_not_a_body(): void
    {
        // The endpoint this replaces read `(array) $request->input('body', [])`, so a client that sent
        // `{ "bodyy": … }` got a silent save of an empty document. The declaration is what turns that
        // into a 422 — and `ParticleOperationController::validateInput()` runs it before `handle()`.
        $this->expectException(ValidationException::class);

        BeamUxEntryBodyInputData::validate(['bodyy' => ['nope']]);
    }

    public function test_an_empty_body_is_accepted_because_clearing_a_document_is_a_legitimate_edit(): void
    {
        // `present`, not `required` — `required` rejects `[]`, and an author emptying a page is normal.
        $this->assertSame([], BeamUxEntryBodyInputData::validateAndCreate(['body' => []])->body);
    }

    public function test_both_operations_declare_their_kind_shapes_and_authorization_plane(): void
    {
        $show = $this->attributeOn(EntryBodyShowOp::class);
        $save = $this->attributeOn(EntryBodySaveOp::class);

        $this->assertSame('beam-ux-entry', $show->resource);
        $this->assertSame('body', $show->name);
        $this->assertSame(OperationKind::Read, $show->kind);
        $this->assertSame(BeamUxEntryBodyData::class, $show->output);
        // A GET operation takes no request body, and `false` is the DECLARED "accepts nothing" state —
        // distinct from `null`, which only means nobody has decided yet.
        $this->assertFalse($show->input);

        $this->assertSame('save-body', $save->name);
        $this->assertSame(OperationKind::Write, $save->kind);
        $this->assertSame(BeamUxEntryBodyInputData::class, $save->input);
        $this->assertSame(BeamUxEntryBodyData::class, $save->output);

        foreach ([$show, $save] as $operation) {
            $this->assertSame('ux.author', $operation->ability);
            // `ux.author` is an ENTITLEMENT key. `false` routes the check to the subject-free
            // entitlement plane; leaving it null hands the loaded entry to `AbilityResolver` and asks a
            // per-action question with a token that has no per-action meaning
            // (particle-operation-surface ticket 08).
            $this->assertFalse($operation->abilityModel);
        }
    }

    public function test_the_package_registers_all_four_of_its_operations_without_a_host_naming_a_class(): void
    {
        // ADR-0214 §5. Before this, a beam-ux operation reached the registry only because a HOST listed
        // its FQCN in `beam.core.particle.classes` — the inverse of ADR-0210's "beam-core never learns a
        // consumer's name". The testbench app names none of them, so anything found here was registered
        // by the package's own `packageBooted()`.
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $this->assertEmpty(config('beam.core.particle.classes', []));

        foreach (['body', 'save-body', 'workflow', 'transition'] as $name) {
            $this->assertNotNull(
                $registry->find('beam-ux-entry', $name),
                "beam-ux did not register its own `beam-ux-entry.{$name}` operation.",
            );
        }
    }

    public function test_the_registered_operations_are_the_declaring_classes_themselves(): void
    {
        // Guards against the registration list drifting into a second, hand-built reader of the
        // attributes: what registers must be the annotated classes, not a copy of what they say.
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $this->assertSame(
            BeamUxEntryBodyData::class,
            $registry->find('beam-ux-entry', 'body')->output,
        );
        $this->assertSame(
            EntryWorkflowTransitionOp::class,
            $this->attributeClassFor($registry, 'transition'),
        );
        $this->assertSame(
            EntryWorkflowShowOp::class,
            $this->attributeClassFor($registry, 'workflow'),
        );
    }

    /** @param array<string, mixed> $body */
    private function saveRequest(BeamUxEntry $entry, array $body): Request
    {
        return Request::create('/beam-ux-entries/'.$entry->id.'/op/save-body', 'POST', ['body' => $body]);
    }

    private function attributeOn(string $class): ParticleOp
    {
        return (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();
    }

    /**
     * The declaring class behind a registered operation, read back off the registry rather than
     * assumed — `handle` is a closure over the op class, so the round trip is what proves the
     * registration used the real declaration site.
     */
    private function attributeClassFor(ParticleOperationRegistry $registry, string $name): string
    {
        $operation = $registry->find('beam-ux-entry', $name);

        foreach ([EntryWorkflowShowOp::class, EntryWorkflowTransitionOp::class, EntryBodyShowOp::class, EntryBodySaveOp::class] as $class) {
            if ($this->attributeOn($class)->name === $operation->name) {
                return $class;
            }
        }

        return '';
    }

    private function createTables(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('schema_ref')->nullable()->index();
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
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });
    }
}
