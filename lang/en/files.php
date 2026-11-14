<?php

/*
 * beam-ux's local file reconciliation: what an inspection and a reconcile answer (localization 23, the recording/file
 * commit). The keys follow the response's own state code: `beam-ux::files.inspection.<state>`, `files.result.<status>`.
 */
return [
    'inspection' => [
        'unsupported' => 'This file cannot be reconciled.',
        'missing' => 'The local file is missing. Restore creates only the current published bytes.',
        'unchanged' => 'The local file matches the current published version.',
        'differs' => 'The local file differs from the current published version. Take it as a draft deliberately.',
    ],
    'result' => [
        'drafted' => 'Draft saved. The published version and local file are unchanged.',
        'unchanged' => 'The local file already matches the published version. Nothing changed.',
        'restored' => 'The missing published file was restored. Draft, history and Git are unchanged.',
        'remirrored' => 'The local file was replaced with the current published version. Draft and history are unchanged.',
    ],
    'diagnostic' => [
        'compile_failed' => 'Draft retained; compilation failed. Published content is unchanged. Review the draft before publication.',
    ],
];
