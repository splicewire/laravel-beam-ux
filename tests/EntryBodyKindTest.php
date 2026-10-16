<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Body\BodyKind;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * ADR-0228 d1: the entry DECLARES its body's kind; the data doesn't. A tsx page holds blocks, a
 * component holds source (central), a theme holds a form, an mdx page holds source (until ticket 15).
 * The kind is read off the entry's type + format, never sniffed from the stored payload — `array_is_list`
 * / `JsonDocShape::is` retire as the discriminator. `AcceptsJsonDoc` comes to mean "this kind is blocks".
 */
class EntryBodyKindTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Only the entries table is needed: bodyKind() reads the declared type/format and resolves the
        // codec through the registry — it never touches particles or versions.
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('particle_id')->nullable()->index();
            $table->string('slug')->index();
            $table->string('title')->nullable();
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
            $table->string('workflow_marking')->nullable()->index();
            $table->string('workflow_version')->nullable();
            $table->uuid('published_version')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['namespace', 'slug']);
        });
    }

    public function test_a_tsx_page_declares_blocks(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'home', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        $this->assertSame(BodyKind::Blocks, $entry->bodyKind());
    }

    public function test_an_mdx_page_declares_source(): void
    {
        $entry = BeamUxEntry::create(['slug' => 'docs', 'type' => 'page', 'format' => 'mdx', 'namespace' => null]);
        $this->assertSame(BodyKind::Source, $entry->bodyKind());
    }

    public function test_a_theme_declares_a_form_whatever_its_format(): void
    {
        $theme = BeamUxEntry::create(['slug' => 'beam', 'type' => 'theme', 'format' => 'css', 'namespace' => 'theme']);
        $this->assertSame(BodyKind::Form, $theme->bodyKind());
    }

    public function test_a_component_declares_source(): void
    {
        // ADR-0228 d1: a central component holds source (ADR-0231 later adds an Authored-Component blocks
        // path; not EP-05). Its format defaults to tsx, so this also proves the kind is the entry's
        // declaration (type), not the codec's AcceptsJsonDoc capability.
        $entry = BeamUxEntry::create(['slug' => 'hero', 'type' => 'component', 'namespace' => null]);
        $this->assertSame(BodyKind::Source, $entry->bodyKind());
    }

    public function test_blocks_is_exactly_the_accepts_json_doc_codec_for_a_page(): void
    {
        // The consequence ADR-0228 records: AcceptsJsonDoc means "this kind is blocks".
        $tsx = BeamUxEntry::create(['slug' => 'a', 'type' => 'page', 'format' => 'tsx', 'namespace' => null]);
        $mdx = BeamUxEntry::create(['slug' => 'b', 'type' => 'page', 'format' => 'mdx', 'namespace' => null]);
        $this->assertSame(BodyKind::Blocks === $tsx->bodyKind(), $tsx->codec() instanceof \Splicewire\Beam\Ux\Codec\AcceptsJsonDoc);
        $this->assertNotInstanceOf(\Splicewire\Beam\Ux\Codec\AcceptsJsonDoc::class, $mdx->codec());
    }
}
