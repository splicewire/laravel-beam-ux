<?php

// config-sections 11 fixture: the deploy-tier defaults for the `release-keys` section the Release panel tests override.
return [
    'workers' => 'two',
    'mail' => [
        'host' => 'mail.host.test',
        // A FAKE secret leaf on the host tier, inside an object (config-sections 11).
        'password' => 'host-pw-x',
    ],
    // Shares `mail`'s string prefix, never its pointer prefix (`/mail/`).
    'mailer' => 'mailer-host-x',
];
