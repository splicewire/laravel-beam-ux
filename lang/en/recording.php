<?php

/*
 * beam-ux's Release recording status lines (localization 23, the recording/file commit), keyed by the projection's own
 * status code and, for a failed or unavailable recording, the stored failure reason: `beam-ux::recording.status.<status>`
 * and `recording.status.<status>.<reason>`. The stored reason is a code, never English, so nothing is inferred from
 * saved text.
 */
return [
    'status' => [
        'not-published' => 'Publication has not completed.',
        'recorded' => 'Recorded locally.',
        'recorded-no-change' => 'Files already matched the parent commit; no new commit was created.',
        'pending' => 'Recording is pending. Files can safely resume this same attempt.',
        'mirror-deleted' => 'The Release removed its mirror file and is finishing the same local Git attempt.',
        'recovery-required' => 'The recording result needs verification. Files can resume the saved attempt; no second commit is guessed.',
        'failed' => [
            'mirror-drift' => 'A file differs from this Release. Resolve it explicitly before retrying.',
            'superseded' => 'A newer publication superseded this mirror completion.',
            'tenancy' => 'Local recording is unavailable for this tenancy context.',
            'mirror' => 'Publication succeeded. A mirror could not be completed or verified; no whole-Release Git commit was installed.',
            'path' => 'A mirror path could not be verified as a contained regular file.',
            'default' => 'Publication succeeded. Local recording failed; check mirror, Git, signing and hooks before retrying.',
        ],
        'unavailable' => [
            'mirror-drift' => 'The published file differs from its mirror. The unknown mirror content was left untouched.',
            'mirror-disabled' => 'Local recording needs an enabled local mirror disk.',
            'path' => 'A mirror path could not be verified as a contained regular file.',
            'manifest' => 'A complete bounded recording manifest could not be frozen; no Git commit was attempted.',
            'default' => 'No supported local recording receipt is available for this Release.',
        ],
    ],
];
