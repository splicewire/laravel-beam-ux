<?php

// config-sections 08 fixture: the deploy-tier defaults for the `release-fixture` section an operator overrides in a Release.
return [
    'heading' => 'default heading',
    // A FAKE secret on the host tier (config-sections 11), so the panel tests prove a lower-tier secret never reaches the wire.
    'token' => 'host-secret-x',
];
