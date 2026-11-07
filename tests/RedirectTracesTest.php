<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Ux\Data\RedirectTraceData;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Particle\RedirectTracesOp;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Redirect\RedirectTraces;

/**
 * owner-review-20261102 research 37 (37-T1): the redirect trace read the Site map's "Needs attention" and "Old addresses"
 * draw from. Each Redirect is walked once, as a guest, by the renderer's own {@see \Splicewire\Beam\Ux\Redirect\RedirectResolver::follow()};
 * a loop, a walk past the step bound, a dead end and a gone target need attention; an answered chain is information
 * only (D-37-g (b): the server walks it and answers in one response, ADR-0236 d9).
 */
class RedirectTracesTest extends AuthoredComponentNativeTestCase
{
    private BeamUxEntry $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/shared/create_beam_ux_redirects_table.php.stub')->up();
        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);
        $this->pricing = BeamUxEntry::create(['slug' => 'pricing', 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => BeamUxEntry::rootFor()->id, 'segment' => 'pricing']);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($this->pricing->fresh(), $pub->recordDraft($this->pricing->fresh(), [['kind' => 'block', 'name' => 'div',
            'isComponent' => false, 'dynamic' => false, 'props' => [], 'children' => []]])->headVersion);
    }

    public function test_it_classifies_answered_chains_loops_too_long_walks_dead_ends_and_gone_targets(): void
    {
        $this->redirect('/plans', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->redirect('/old-plans', 'path', ['target_path' => '/plans']);
        $this->redirect('/loop-a', 'path', ['target_path' => '/loop-b']);
        $this->redirect('/loop-b', 'path', ['target_path' => '/loop-a']);
        for ($i = 0; $i < 6; $i++) {
            $this->redirect("/hop-{$i}", 'path', ['target_path' => '/hop-'.($i + 1)]);
        }
        $this->redirect('/hop-6', 'page', ['target_entry_id' => $this->pricing->id]);
        $this->redirect('/to-nowhere', 'page', ['target_entry_id' => (string) Str::uuid()]);
        $this->redirect('/pricing', 'path', ['target_path' => '/elsewhere']);

        $traces = collect(app(RedirectTraces::class)->all())->keyBy('path');

        $this->assertSame(['answered', 1, false], $this->shape($traces['/plans']));
        $this->assertSame('/pricing', $traces['/plans']->to);
        $this->assertSame($this->pricing->id, $traces['/plans']->pageId, 'an answered walk names the page it lands on');
        $this->assertSame(['answered', 2, false], $this->shape($traces['/old-plans']), 'a chain that answers is information, not attention');
        $this->assertSame($this->pricing->id, $traces['/old-plans']->pageId);
        $this->assertSame(['/old-plans', '/plans'], $traces['/old-plans']->steps);
        $this->assertSame(['loop', 2, true], $this->shape($traces['/loop-a']));
        $this->assertSame(['too-long', 5, true], $this->shape($traces['/hop-0']));
        $this->assertSame(['answered', 1, false], $this->shape($traces['/hop-6']));
        $this->assertSame(['gone', 1, true], $this->shape($traces['/to-nowhere']));
        $this->assertNull($traces['/to-nowhere']->pageId, 'a gone target names no page, so the map lists it as a loose redirect');
        $this->assertSame(['shadowed', 0, false], $this->shape($traces['/pricing']), 'a page answers it, so it never runs; the doctor names it');
    }

    public function test_the_op_is_a_declared_subject_free_read_on_the_redirects_resource(): void
    {
        $operation = collect(app(ParticleOperationRegistry::class)->forResource('beam-ux-redirect'))
            ->first(fn ($op) => $op->name === 'traces');

        $this->assertNotNull($operation, 'beam-ux-redirect declares a traces read');
        $this->assertSame(\Splicewire\Beam\Ux\Data\RedirectTracesData::class, $operation->output);

        $this->redirect('/plans', 'page', ['target_entry_id' => $this->pricing->id]);
        $out = RedirectTracesOp::handle(null, Request::create('/'), null);
        $this->assertCount(1, $out->traces);
        $this->assertInstanceOf(RedirectTraceData::class, $out->traces[0]);
    }

    /** @return array{string, int, bool} */
    private function shape(RedirectTraceData $trace): array
    {
        return [$trace->end, $trace->hops, $trace->attention];
    }

    private function redirect(string $path, string $kind, array $columns): void
    {
        DB::table('beam_ux_redirects')->insert($columns + [
            'id' => (string) Str::uuid(), 'realm' => 'site', 'path' => $path, 'match' => 'exact', 'target_kind' => $kind,
            'permanent' => true, 'origin' => 'author', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
