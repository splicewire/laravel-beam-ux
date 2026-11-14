<?php

namespace Splicewire\Beam\Ux\JsonDoc;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a body's source or canvas document was refused (localization 23; ticket 13's pattern). The English is
 * byte-identical to the literal the refusal carried before.
 *
 * `CanvasNodeUnreadableDetail` keeps the parser's own text as `:detail` (coord-lead ruling (b): no English change in a
 * sweep). `TsxLensFailed` is GENERIC: it replaces the TSX lens's own output, which stays on the refusal's diagnostic.
 */
enum SourceError: string implements CodedError
{
    use IsCodedError;

    #[Message('Source Mode requires a central TSX or MDX blocks entry; Hosted sites have no Source Mode.')]
    case SourceModeUnavailable = 'source_mode_unavailable';

    #[Message("An MDX page's frontmatter is its page settings, not its body (ADR-0233 d3). Edit those in page settings and write the body alone.")]
    case FrontmatterIsSettings = 'source_frontmatter_is_settings';

    #[Message('`export const meta` becomes frontmatter when the host converts its MDX (ADR-0233 d9); a blocks page body holds no code.')]
    case MetaExportRefused = 'source_meta_export_refused';

    #[Message('This document has a node the canvas editor cannot read.')]
    case CanvasNodeUnreadable = 'source_canvas_node_unreadable';

    #[Message('This document has a node the canvas editor cannot read (:detail).')]
    case CanvasNodeUnreadableDetail = 'source_canvas_node_unreadable_detail';

    #[Message('This entry holds blocks, not code (ADR-0227 decision 1): a body that is not a canvas document is source, and source is refused here.')]
    case BlocksNotCode = 'source_blocks_not_code';

    #[Message('A hosted-site (tenant-owned) entry holds blocks, not source: a source body is code and is refused here (ADR-0227 decision 1, ADR-0233 decision 6).')]
    case HostedSourceRefused = 'source_hosted_refused';

    #[Message('This entry declares a :kind body, which has no source editor. Edit it through its own input.')]
    case NoSourceEditor = 'source_no_editor';

    /** GENERIC: replaces the TSX lens's own output (a script path, stderr, an empty result). */
    #[Message('The TSX source could not be converted to blocks.')]
    case TsxLensFailed = 'source_tsx_lens_failed';
}
