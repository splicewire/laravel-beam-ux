<?php

namespace Splicewire\Beam\Ux\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns a migrated host's `beam_ux_entries` carries, for tests that hand-build a minimal table instead of running
 * `create_beam_ux_entries_table`. migration-flatten 06 folded the ALTERs that used to add them into that create
 * (02 rulings: add_published_version delete-noop, add_child_fields and add_provenance fold), so these tests can no
 * longer require those stubs. Each method is idempotent and matches the retired ALTER's definition.
 */
final class MigratedHostColumns
{
    public static function publishedVersion(): void
    {
        if (Schema::hasTable('beam_ux_entries') && ! Schema::hasColumn('beam_ux_entries', 'published_version')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->uuid('published_version')->nullable());
        }
    }

    public static function childFields(): void
    {
        if (Schema::hasTable('beam_ux_entries') && ! Schema::hasColumn('beam_ux_entries', 'child_fields')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->string('child_fields')->nullable());
        }
    }

    public static function provenance(): void
    {
        if (! Schema::hasColumn('beam_ux_entries', 'origin')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->string('origin')->nullable()->index());
        }
        if (! Schema::hasColumn('beam_ux_entries', 'asserted_hash')) {
            Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->string('asserted_hash')->nullable());
        }
    }

    /** A host from before provenance: the folded create's `origin` and `asserted_hash` taken back off. */
    public static function withoutProvenance(): void
    {
        Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropIndex('beam_ux_entries_origin_index'));
        Schema::table('beam_ux_entries', fn (Blueprint $table) => $table->dropColumn(['origin', 'asserted_hash']));
    }
}
