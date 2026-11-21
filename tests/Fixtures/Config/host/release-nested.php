<?php

// config-sections 11 r5 fixture: deploy-tier defaults for `release-nested`, whose leaves become secret later.
// `relay.auth.user` is HOST-owned (ceiling Host): an operator import must leave this value in place.
return [
    'relay' => ['auth' => ['token' => null, 'user' => 'host.user.fixed'], 'region' => null],
    'deep' => ['outer' => ['inner' => ['token' => null, 'pub' => null]]],
    'smtp' => ['password' => null, 'port' => null],
    'vault' => ['key' => null],
];
