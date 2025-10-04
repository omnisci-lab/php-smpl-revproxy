<?php

return [
    'TARGET_BASE' => 'https://example.com',
    'MAX_BODY_SIZE' => 10 * 1024 * 1024,
    'TIMEOUT' => 30,
    'VERIFY_SSL' => true,
    'REWRITE_BODY' => true,
    'CUSTOM_HEADERS' => [
        'key' => 'value'
    ],
    'DEBUG' => false
];