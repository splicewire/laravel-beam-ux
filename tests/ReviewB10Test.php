<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Schema\SchemaVersionRegistering;
use Splicewire\Beam\Ux\Compile\CompilationFailed;
use Splicewire\Beam\Ux\Format\UxFormat;
use Splicewire\Beam\Ux\Intake\IntakeForms;
use Splicewire\Beam\Ux\Manifest\ProfileLegality;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Tests\Bindings\SchemaBindingImpactTest;

/**
 * localization 23A: range review B10's four omissions (U1-U4), from review-r1's probes (red at d00b46d).
 * Each route calls the real helper, listener or failure factory; nothing throws a new enum directly. Under en-XA
 * the message is marked and carries its code; under English it is byte-identical to the literal it replaced.
 */
class ReviewB10Test extends TestCase
{
    private function pseudo(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);
    }

    /** U3: the real schema-registration listener, over SchemaBindingImpactTest's fixture. */
    private function aliasRoute(): void
    {
        $fixture = new SchemaBindingImpactTest('test_new_cross_stem_alias_collision_refuses_with_published_page_identity_even_with_confirmation');
        $old = (new \ReflectionMethod($fixture, 'old'))->invoke($fixture);
        $registry = (new \ReflectionMethod($fixture, 'registry'))->invoke($fixture);
        $guard = (new \ReflectionMethod($fixture, 'guard'))->invoke($fixture);
        $schema = $old;
        $schema['$id'] = 'https://tenant.test/schemas/new/articles/1';
        Route::post('/review-b10/alias', fn () => $guard(new SchemaVersionRegistering($schema, [], $registry, 'tenant')));
    }

    public function test_review_schema_alias_refusal_is_marked_and_coded(): void
    {
        $this->aliasRoute();
        $english = $this->postJson('/review-b10/alias')->assertStatus(422);
        $this->assertSame(['Rebind the published page [Published Page / page-a] before this registration changes its Collection alias or makes it ambiguous [articles].'], $english->json('errors')['schema.aliases']);
        $this->assertSame(['schema_alias_rebind_required'], $english->json('codes')['schema.aliases']);

        $this->pseudo();
        $r = $this->postJson('/review-b10/alias')->assertStatus(422);
        $this->assertTrue(PseudoLocale::isMarked($r->json('errors')['schema.aliases'][0]), $r->json('errors')['schema.aliases'][0]);
        $this->assertSame(['schema_alias_rebind_required'], $r->json('codes')['schema.aliases']);
    }

    public function test_review_profile_legality_response_is_marked_and_coded(): void
    {
        Route::post('/review-b10/profile', fn () => app(ProfileLegality::class)->assert([['kind' => 'opaque']], ['x-admits-code' => false]));
        $english = $this->postJson('/review-b10/profile')->assertStatus(422);
        $this->assertSame(['node at [0] carries an opaque node (verbatim code); this profile does not admit code (ux-0227 d1)'], $english->json('errors.body'));

        $this->pseudo();
        $r = $this->postJson('/review-b10/profile')->assertStatus(422);
        $this->assertTrue(PseudoLocale::isMarked($r->json('errors.body.0')), $r->json('errors.body.0'));
        $this->assertSame(['profile_opaque_node'], $r->json('codes.body'));
    }

    public function test_an_identified_node_keeps_its_id_as_a_parameter_and_each_refusal_its_code_in_order(): void
    {
        $body = [['kind' => 'block', 'id' => 'b1', 'name' => 'Card', 'props' => [['name' => 'x', 'kind' => 'expression', 'value' => 'go()']], 'children' => []]];
        Route::post('/review-b10/identified', fn () => app(ProfileLegality::class)->assert($body, ['x-admits-code' => false, 'nodes' => []]));
        $english = $this->postJson('/review-b10/identified')->assertStatus(422);
        $this->assertSame([
            "node #b1 at [0] carries the expression prop 'x' (code); this profile does not admit code (ux-0227 d1)",
            "node #b1 at [0] uses the unknown block 'Card', which is not in this profile",
        ], $english->json('errors.body'));
        $this->assertSame(['profile_expression_prop_identified', 'profile_unknown_block_identified'], $english->json('codes.body'));

        $this->pseudo();
        foreach ($this->postJson('/review-b10/identified')->assertStatus(422)->json('errors.body') as $message) {
            $this->assertTrue(PseudoLocale::isMarked($message), $message);
            $this->assertStringContainsString('#b1', $message);
        }
    }

    public function test_review_invalid_intake_form_response_is_marked_and_coded(): void
    {
        config(['beam.core.intake.enabled' => false]);
        Route::post('/review-b10/intake', fn () => app(IntakeForms::class)->assertBody([
            ['kind' => 'block', 'name' => 'IntakeForm', 'id' => 'example', 'props' => []],
            ['kind' => 'block', 'name' => 'IntakeForm', 'props' => []],
        ]));
        $english = $this->postJson('/review-b10/intake')->assertStatus(422);
        $this->assertSame([
            'node #example at [0] posts to [], which is not a public intake schema on this host',
            'node at [1] posts to [], which is not a public intake schema on this host',
        ], $english->json('errors.body'));
        $this->assertSame(['intake_form_target_not_public_identified', 'intake_form_target_not_public'], $english->json('codes.body'));

        $this->pseudo();
        $r = $this->postJson('/review-b10/intake')->assertStatus(422);
        $this->assertTrue(PseudoLocale::isMarked($r->json('errors.body.0')), $r->json('errors.body.0'));
        $this->assertNotEmpty($r->json('codes.body'));
    }

    /**
     * U4: the wrapper is keyed at EMIT (`display()`, which EntryPublication's compileError and ReleaseReadiness's
     * compile Item now render). The exception's own message stays the raw declared English, for logs, per coord's
     * 02:58Z ruling; review-r1's probe read getMessage(), so this reads the emit instead.
     */
    public function test_review_compilation_failure_wrapper_is_keyed_at_emit(): void
    {
        $entry = new BeamUxEntry;
        $entry->slug = 'example';
        $entry->format = UxFormat::Tsx;
        $failure = CompilationFailed::for($entry, 'engine detail');
        $unsupported = CompilationFailed::unsupported($entry);
        $this->assertSame('Compiling entry [example] (format: tsx) failed: engine detail', $failure->getMessage());
        $this->assertSame('Compiling entry [example] (format: tsx) failed: engine detail', $failure->display());
        $this->assertSame("No compile strategy for entry [example]'s format [tsx]. Register one on the bound Splicewire\\Beam\\Ux\\Compile\\EntryBodyCompiler, or give the entry a format the default Node compiler handles.", $unsupported->display());

        Route::get('/review-b10/compile', fn () => response()->json(['compileError' => $failure->display(), 'unsupported' => $unsupported->display()]));
        $this->pseudo();
        $response = $this->getJson('/review-b10/compile')->assertOk();
        foreach (['compileError', 'unsupported'] as $field) {
            $this->assertTrue(PseudoLocale::isMarked($response->json($field)), $response->json($field));
        }
        $this->assertStringContainsString('engine detail', $response->json('compileError'), 'the engine reason is data, never translated');
        $this->assertSame('Compiling entry [example] (format: tsx) failed: engine detail', $failure->getMessage(), 'the log message stays raw');
    }
}
