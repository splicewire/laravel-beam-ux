<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Str;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Ux\Doctor\BeamUxHiddenParentAudit;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Publish\EntryPublication;
use Splicewire\Beam\Ux\Sitemap\EntryPublishGate;

/**
 * ADR-0236 d8 and Consequences (EP-31 slice 4): a hidden page hides the pages under it, so a structural page left
 * unpublished takes its published subtree off the site without a word. The doctor names each hidden page with published
 * pages under it, so it is found before readers notice.
 */
class HiddenParentAuditTest extends AuthoredComponentNativeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Gate::define('create', fn ($user = null) => true);
        \Illuminate\Support\Facades\Gate::define('update', fn ($user = null) => true);
        $this->app->bind(EntryPublishGate::class, fn () => new class implements EntryPublishGate
        {
            public function isPublished(BeamUxEntry $entry): bool
            {
                return $entry->workflow_marking !== 'review';
            }
        });
    }

    public function test_it_names_a_hidden_page_with_published_pages_under_it(): void
    {
        $guides = $this->livePage('guides');
        $start = $this->livePage('start', $guides);
        $this->livePage('deeper', $start);
        $empty = $this->livePage('empty');
        $this->assertSame(DoctorStatus::Pass, $this->audit()->status);

        $guides->forceFill(['workflow_marking' => 'review'])->save();
        $empty->forceFill(['workflow_marking' => 'review'])->save();
        $finding = $this->audit();

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('/guides (2 published pages under it)', $finding->detail);
        $this->assertStringNotContainsString('/empty', $finding->detail, 'a hidden page with nothing under it hides nothing else');

        $guides->forceFill(['workflow_marking' => null])->save();
        $this->assertSame(DoctorStatus::Pass, $this->audit()->status, 'publishing it again brings the subtree back');
    }

    private function audit(): \Rushing\Doctor\Finding
    {
        return app(BeamUxHiddenParentAudit::class)->run()[0];
    }

    private function livePage(string $segment, ?BeamUxEntry $parent = null): BeamUxEntry
    {
        $page = BeamUxEntry::create(['slug' => $segment.'-'.Str::random(4), 'type' => 'page', 'format' => 'tsx', 'realm' => 'site',
            'parent_id' => ($parent ?? BeamUxEntry::rootFor())->id, 'segment' => $segment]);
        $pub = app(EntryPublication::class);
        $pub->publishVersion($page->fresh(), $pub->recordDraft($page->fresh(), [['kind' => 'block', 'name' => 'div', 'isComponent' => false,
            'dynamic' => false, 'props' => [], 'children' => []]])->headVersion);

        return $page->fresh();
    }
}
