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

    // registry-docs ticket 02 (ticket 01 rulings 2–4): the registry documentation spine.
    'registries' => [
        // The committed product-documentation catalog: every family registry root with its doc
        // pointer, independent of this host's installed runtime roots. REGENERATION IS A COMMAND
        // (`splicewire:beam:docs:registries:catalog` writes it; nothing else does); the resolver,
        // the deep-link route and nav only read. Relative paths resolve against base_path().
        'catalog' => 'docs/registries/catalog.json',
        // The docs product the generic family `docs/registries/` source rows mount under (DOC-1).
        'product' => 'splicewire',
        // Extra `vendor/name => install path` packages for the CATALOG COMMAND only, merged over
        // Composer's installed enumeration: the seam a spine host uses to regenerate over family
        // packages it does not itself install (one host's bake is never the estate census). The
        // generic source contributor never reads this — non-installed roots are covered by their
        // catalog rows' pointers, not mounted.
        'packages' => [],
        // The registry section of the `site` nav (ruling 7): catalog clusters plus availability,
        // composed into NavProjector's gated tree by Registries\RegistryDocsNav.
        'nav' => [
            // heading => root prefixes: groups gathered under one titled heading that is not a
            // cluster (e.g. 'Tower operations' => ['conduit', 'determination', 'retrieval']).
            'groupings' => [],
            // root => group key, for a root that sits outside its natural prefix
            // (e.g. 'workflow' => 'beam.workflows').
            'place' => [],
            // public paths of projected sections copied in as the registry section's first
            // children (e.g. a host's '/beam/docs/foundations').
            'lead' => [],
            // group key => heading, for a group with no cluster page (e.g. 'beam' => 'Beam core').
            'titles' => [],
        ],
    ],

    // Literal authoring-tool tokens, checked in stored bodies and declared source files.
    'lint' => [
        'forbidden' => [
            'XDEBUG_MODE' => 'fail',
            'herd php' => 'warn',
            'herd composer' => 'warn',
        ],
    ],

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
