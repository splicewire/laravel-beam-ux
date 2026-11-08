<?php

namespace Splicewire\Beam\Ux\Doctor;

use Splicewire\Beam\Doctor\RetiredMigrationAudit;

/**
 * beam-ux's own retirement declarations, on the instrument beam already uses for its own (see
 * {@see RetiredMigrationAudit}: a retirement must be DECLARED, because a retired stub's defining property is that it
 * is absent).
 *
 * migration-flatten 06 (02 rulings) retired every ALTER beam-ux shipped beside its creates:
 *  - fold: their columns now live in `shared/create_beam_ux_entries_table` / `shared/create_beam_ux_releases_table`,
 *    appended in the order the ALTERs applied (02 Q10);
 *  - delete-noop: the create already declared the column;
 *  - delete-data: `baseline_beam_ux_entry_publication_pins` had nothing to pin on a fresh install;
 *    `EntryPublication::baseline()` pins an entry on its first draft.
 * Hosts migrated before the folds are re-baselined (migration-flatten 04), not re-migrated. Advisory, like its
 * parent: deleting a host's published copy stays the host's call.
 */
class BeamUxRetiredMigrationAudit extends RetiredMigrationAudit
{
    /**
     * The published path with its timestamp stripped => what supersedes it.
     *
     * @var array<string, string>
     */
    public const UX_RETIRED = [
        'shared/add_requirements_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_published_version_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_page_settings_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_child_fields_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_record_page_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_component_kind_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_component_names_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_chrome_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_provenance_to_beam_ux_entries_table' => 'shared/create_beam_ux_entries_table',
        'shared/add_origin_to_beam_ux_releases_table' => 'shared/create_beam_ux_releases_table',
        'shared/add_preview_links_to_beam_ux_releases_table' => 'shared/create_beam_ux_releases_table',
        'shared/baseline_beam_ux_entry_publication_pins' => 'EntryPublication::baseline() on first draft (no migration)',
    ];

    public function __construct(?string $migrationsPath = null)
    {
        parent::__construct(self::UX_RETIRED, $migrationsPath);
    }
}
