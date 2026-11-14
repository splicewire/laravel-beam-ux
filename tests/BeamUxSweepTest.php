<?php

namespace Splicewire\Beam\Ux\Tests;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Ux\Analytics\AnalyticsError;
use Splicewire\Beam\Ux\Bindings\BindingError;
use Splicewire\Beam\Ux\Components\ComponentError;
use Splicewire\Beam\Ux\Containment\PageError;
use Splicewire\Beam\Ux\Files\FileConflict;
use Splicewire\Beam\Ux\Files\FileError;
use Splicewire\Beam\Ux\Files\FileOperation;
use Splicewire\Beam\Ux\Filters\FilterError;
use Splicewire\Beam\Ux\Intake\IntakeError;
use Splicewire\Beam\Ux\JsonDoc\SourceError;
use Splicewire\Beam\Ux\Localization\CodedConflictHttpException;
use Splicewire\Beam\Ux\Localization\DiagnosedValidationException;
use Splicewire\Beam\Ux\Manifest\ProfileError;
use Splicewire\Beam\Ux\Publish\EntryError;
use Splicewire\Beam\Ux\Recording\RecordingError;
use Splicewire\Beam\Ux\Recording\RecordingFailure;
use Splicewire\Beam\Ux\Redirect\RedirectError;
use Splicewire\Beam\Ux\Release\ReleaseError;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * localization 23: the laravel-beam-ux sweep, core.
 * - Every refusal this package can return is a coded error with a key in `beam-ux::`.
 * - Under the pseudo-locale each message is marked, its code stays the case's value, and the English is unchanged.
 * - The wire cases send a real request through the framework's handler, one per endpoint family, and snapshot its body.
 */
class BeamUxSweepTest extends TestCase
{
    private const PARAMS = ['detail' => 'd', 'name' => 'n', 'entries' => 'e', 'path' => '/p', 'attribute' => 'a', 'language' => 'tsx',
        'key' => 'k', 'ref' => 'r', 'format' => 'mdx', 'kind' => 'blocks', 'errors' => '{}', 'transition' => 't', 'blockers' => 'b',
        // localization 23A
        'id' => 'i', 'prop' => 'p', 'slot' => 's', 'category' => 'c', 'admitted' => 'x', 'title' => 'T', 'page' => 'pg', 'aliases' => 'al',
        'slug' => 'sl', 'reason' => 'why', 'compiler' => 'C'];

    /** @return list<CodedError> every case the sweep introduced */
    private function cases(): array
    {
        return [...ComponentError::cases(), ...RedirectError::cases(), ...BindingError::cases(), ...PageError::cases(),
            ...EntryError::cases(), ...SourceError::cases(), ...ReleaseError::cases(), ...AnalyticsError::cases(), ...FilterError::cases(),
            ...RecordingError::cases(), ...FileError::cases(), ...ProfileError::cases(), ...IntakeError::cases()];
    }

    private function pseudo(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);
    }

    public function test_every_case_derives_a_beam_ux_key_keeps_its_code_and_is_unique(): void
    {
        $codes = [];
        foreach ($this->cases() as $case) {
            $this->assertStringStartsWith('beam-ux::enums.', (string) $case->translationKey(), $case::class.'::'.$case->name);
            $this->assertSame($case->value, $case->code());
            $codes[] = $case->code();
        }
        $this->assertSame(array_unique($codes), $codes, 'every code is unique across the package');
    }

    public function test_every_message_is_marked_under_the_pseudo_locale_with_its_parameters_replaced(): void
    {
        $this->pseudo();

        foreach ($this->cases() as $case) {
            $message = $case->message(self::PARAMS);
            $this->assertTrue(PseudoLocale::isMarked($message), $case::class.'::'.$case->name.': '.$message);
            $this->assertDoesNotMatchRegularExpression('/:(detail|name|entries|path|attribute|language|key|ref|format|kind|errors|transition|blockers|id|prop|slot|category|admitted|title|page|aliases|slug|reason|compiler)\b/', $message, $case->name.' replaces every parameter');
        }
    }

    public function test_the_english_is_unchanged_including_parameters(): void
    {
        $this->assertSame('A page answers /p, and a page always wins.', RedirectError::PathIsPage->message(['path' => '/p']));
        $this->assertSame('The Release workflow refused publish: needs review; stale', ReleaseError::WorkflowRefused->message(['transition' => 'publish', 'blockers' => 'needs review; stale']));
        $this->assertSame('This document has a node the canvas editor cannot read (bad node).', SourceError::CanvasNodeUnreadableDetail->message(['detail' => 'bad node']));
        $this->assertSame("This entry declares a blocks body, not a form. Edit it through the blocks input, or change the entry's kind first.", EntryError::FormBodyMismatch->message(['kind' => 'blocks']));
        $this->assertSame('Name a Record as collection:id.', RedirectError::TargetRecordFormat->message());
    }

    public function test_the_explicit_dashboard_and_analytics_lines_are_catalogued_and_marked(): void
    {
        $catalog = $this->app->make(KeyCatalog::class)->byKey();
        $this->pseudo();

        foreach (['dashboard' => 8, 'analytics' => 5, 'files' => 9, 'recording' => 17] as $group => $count) {
            $lines = KeyCatalog::flatten((array) require dirname(__DIR__).'/lang/en/'.$group.'.php');
            $this->assertCount($count, $lines);
            foreach ($lines as $item => $english) {
                $key = 'beam-ux::'.$group.'.'.$item;
                $this->assertSame($english, $catalog[$key]->english ?? null, $key.' is catalogued with its English');
                $this->assertTrue(PseudoLocale::isMarked((string) __($key, ['name' => 'n'])), $key);
            }
        }
    }

    public function test_a_coded_409_stays_a_conflict_and_a_diagnosed_refusal_keeps_its_raw_text_off_the_wire(): void
    {
        $conflict = new CodedConflictHttpException(EntryError::EditorBaseMoved);
        $this->assertInstanceOf(ConflictHttpException::class, $conflict);
        $this->assertSame(409, $conflict->getStatusCode());

        $diagnosed = DiagnosedValidationException::diagnosed('source', SourceError::TsxLensFailed, new \RuntimeException('SyntaxError at 3:7'));
        $this->assertInstanceOf(CodedValidationException::class, $diagnosed);
        $this->assertSame('SyntaxError at 3:7', $diagnosed->diagnostic);
        $this->assertSame(['source' => ['The TSX source could not be converted to blocks.']], $diagnosed->errors());
    }

    /** The recording/file families: the exception keeps its raw English, and the response emit is localized and coded. */
    public function test_a_file_conflict_keeps_its_raw_english_and_its_emit_answers_with_the_coded_localized_message(): void
    {
        $english = FileError::MdxFileInvalid->defaultMessage();
        $this->pseudo();
        $conflict = new FileConflict('invalid', FileError::MdxFileInvalid, 422);
        $this->assertSame($english, $conflict->getMessage(), 'the exception message is the raw declared English, never pseudo-marked');

        foreach ([422 => 'inspection', 409 => null, 503 => null] as $status => $field) {
            Route::post('/sweep-23/file-'.$status, fn () => FileOperation::run(fn () => throw new FileConflict('invalid', FileError::MdxFileInvalid, $status)));
            $body = $this->postJson('/sweep-23/file-'.$status)->assertStatus($status)->json();
            if ($field !== null) {
                $this->assertTrue(PseudoLocale::isMarked($body['errors'][$field][0]), (string) $body['errors'][$field][0]);
                $this->assertSame(['file_mdx_invalid'], $body['codes'][$field]);
            } else {
                $this->assertTrue(PseudoLocale::isMarked($body['message']), (string) $body['message']);
                $this->assertSame('file_mdx_invalid', $body['code']);
            }
        }
    }

    public function test_a_recording_failure_keeps_its_reason_diagnostic_and_raw_english(): void
    {
        $failure = new RecordingFailure('git-failed', RecordingError::GitFailedLocalGitRecordingFailed, 'index-lock', 128, ['detail' => 'index-lock, exit 128']);

        $this->assertSame('Local Git recording failed (index-lock, exit 128).', $failure->getMessage(), 'byte-identical to the old concatenation');
        $this->assertSame(['git-failed', 'index-lock:128'], [$failure->reason, $failure->diagnostic()]);
        $this->assertSame(RecordingError::GitFailedLocalGitRecordingFailed, $failure->error);
    }

    /** One refusal per changed endpoint family, rendered by the framework's handler: the wire snapshot. */
    public function test_each_endpoint_family_answers_its_refusal_on_the_wire_with_message_errors_and_codes(): void
    {
        $families = [
            'redirect' => [fn () => throw CodedValidationException::for('path', RedirectError::PathTaken, ['path' => '/old']), 'path', 'A Redirect already starts from /old.', 'redirect_path_taken'],
            'release' => [fn () => throw CodedValidationException::for('release', ReleaseError::Empty), 'release', 'An empty Release cannot advance.', 'release_empty'],
            'component' => [fn () => throw CodedValidationException::for('body', ComponentError::Cycle), 'body', 'Components cannot contain a cycle.', 'component_cycle'],
            'binding' => [fn () => throw CodedValidationException::for('repeat.query.limit', BindingError::RepeatLimitRange), 'repeat.query.limit', 'The Repeat limit must be between 1 and 100.', 'repeat_limit_range'],
            'page' => [fn () => throw CodedValidationException::for('pageSettings', PageError::SettingsOnlyPages), 'pageSettings', 'Only pages have page settings.', 'page_settings_only_pages'],
            'entry' => [fn () => throw CodedValidationException::for('versionId', EntryError::PreviewVersionRequired), 'versionId', 'Choose a saved version of this entry.', 'entry_preview_version_required'],
            'source' => [fn () => throw DiagnosedValidationException::diagnosed('source', SourceError::TsxLensFailed, 'raw stderr'), 'source', 'The TSX source could not be converted to blocks.', 'source_tsx_lens_failed'],
            'analytics' => [fn () => throw CodedValidationException::for('clickEvent.name', AnalyticsError::ClickEventUnknown, ['name' => 'buy']), 'clickEvent.name', "The Click Event [buy] is not in this Manifest Profile's Event Vocabulary.", 'analytics_click_event_unknown'],
            'filter' => [fn () => throw CodedValidationException::for('filter.state', FilterError::ValueInvalid), 'filter.state', 'The filter value has an invalid type.', 'filter_value_invalid'],
        ];
        foreach ($families as $family => [$throw, $field, $english, $code]) {
            Route::post('/sweep-23/'.$family, $throw);
            $body = $this->postJson('/sweep-23/'.$family)->assertStatus(422)->json();
            $this->assertSame([$english], $body['errors'][$field] ?? null, $family);
            $this->assertSame([$code], $body['codes'][$field] ?? null, $family);
            $this->assertStringNotContainsString('raw stderr', json_encode($body, JSON_THROW_ON_ERROR), $family.' never leaks a diagnostic');
        }

        Route::post('/sweep-23/conflict', fn () => throw new CodedConflictHttpException(BindingError::RecordPagesChanged));
        $this->postJson('/sweep-23/conflict')->assertStatus(409)->assertExactJson([
            'message' => 'Record Page declarations changed while acquiring locks. Retry against the current state.',
            'code' => 'record_page_declarations_changed',
        ]);
    }
}
