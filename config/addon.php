<?php

return [
    // Add-on executable archives may only be fetched from these vendor hosts.
    // The install endpoint must return JSON with both `url` and a SHA-256
    // checksum, which is verified before any archive is extracted.
    'trusted_hosts' => [
        'lion-coders.com',
        'www.lion-coders.com',
    ],
];
