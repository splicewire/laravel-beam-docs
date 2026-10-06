<?php

return [
    'enabled' => env('BEAM_DOCS_ENABLED', true),
    'visibility' => env('BEAM_DOCS_VISIBILITY', 'public'),
    'reader_tokens' => ['auth'],
    'seed' => true,
    'segment' => '/docs',
    'root_slug' => 'docs',
    'root_namespace' => null,

    // docs-walkthrough DM1 / DOCS-01: the content roots this host declares as docs. Each is a directory scanned the way
    // `splicewire:beam:ux:register-from-disk {path} --under= --type=` scans it: `path` (relative to the base path, or
    // absolute) is the scan root, so a file's namespace is its directory chain below it; `under` is the public path
    // or entry id top-level files hang from; `type` is the UxType for files whose directory names none; `product`
    // names the docs product the root belongs to (DOC-1). The `docs.unregistered` doctor audit FAILs on a file here
    // with no row. The seed chain materializes them (DocsSourcesSeeder, DOCS-05) and fails when it cannot. `ignore` is a
    // list of globs relative to `path` (`**` crosses directories, `*` does not) for include-only files that must never
    // become rows, e.g. `fragments/**`.
    // [['path' => 'resources/js/content', 'under' => null, 'type' => 'page', 'product' => 'splicewire', 'ignore' => []], ...]
    'sources' => [],

    'openapi' => [
        // What this root's API reference documents (docs-walkthrough DM7, DOC-12): `self`, this host's own routes (the
        // starter default, C-7), or `product:vendor/name`. A product subject publishes no reference surface until
        // `artifact` below names the product's spec; the `docs.reference-subject` audit holds it.
        'subject' => env('BEAM_DOCS_OPENAPI_SUBJECT', 'self'),
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
