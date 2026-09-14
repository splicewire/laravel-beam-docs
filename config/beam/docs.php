<?php

return [
    'enabled' => env('BEAM_DOCS_ENABLED', true),
    'visibility' => env('BEAM_DOCS_VISIBILITY', 'public'),
    'reader_tokens' => ['auth'],
    'seed' => true,
    'segment' => '/docs',
    'root_slug' => 'docs',
    'root_namespace' => null,

    'openapi' => [
        // Null preserves a published beam.core.openapi setting, then derives Scribe's local disk path.
        'artifact' => null,
        'middleware' => null,
    ],

    'scalar' => [
        'enabled' => env('BEAM_DOCS_SCALAR_ENABLED', false),
        'namespace' => env('BEAM_DOCS_SCALAR_NAMESPACE'),
        'slug' => env('BEAM_DOCS_SCALAR_SLUG'),
        'token' => env('SCALAR_API_KEY'),
        'executable' => base_path('node_modules/.bin/scalar'),
        'queue' => 'default',
        'process_timeout' => 30,
        'show_link' => false,
    ],
];
