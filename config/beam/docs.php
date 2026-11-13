<?php

return [
    'api_root' => env('BEAM_DOCS_API_ROOT', 'beam/docs'),
    // Retained old exposures until owner-review ticket16.
    'legacy_root' => 'beam/docs',
    'legacy_openapi_root' => 'beam',
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
            // How the section is drawn (docs-feedback ticket 07): 'links', one link per root under its
            // group's heading, or 'index', one `beam-docs/registry-index` node that beam-docs draws as a
            // search box over a cluster tree. Set 'index' once this host's built client carries the
            // renderer; an older bundle draws the node as a heading over cluster links only.
            'widget' => env('BEAM_DOCS_REGISTRIES_NAV_WIDGET', 'links'),
            // The index node's title, and the heading of the served pages the catalog does not list.
            'index_title' => 'Registry index',
            'unlisted_title' => 'On this host',
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

    // PHP API reference (php-api-reference slice 1; owner PRD 2026-11-06): a build-time static extract of the listed
    // packages. REGENERATION IS A COMMAND (`splicewire:beam:docs:php-api:generate`); nothing generates on a request.
    'php_api' => [
        // `vendor/name` (installed here) or `vendor/name => path` (a package this host does not install). Empty: off,
        // and the command is a no-op.
        'packages' => [],
        // Where the artifact is written. Null: storage_path('app/beam-docs/php-api').
        'artifact' => null,
        // `public` publishes only class-level `@api` symbols, strips citations and withholds paragraphs that still
        // carry a reference; `internal` shows every non-private symbol and its whole comment. `@internal` is excluded
        // under both. Null: `public` when beam.docs.visibility is `public`, else `internal`.
        'audience' => env('BEAM_DOCS_PHP_API_AUDIENCE'),
        // What counts as a reference (a ticket, ADR, ruling or map slug) in a docblock paragraph (owner ruling items 1-2).
        'references' => [
            '/\b(?:launch |registry-docs |docs-feedback |make-surface )?tickets? ?#?\d+[a-z]?\b/i',
            '/\bADR[- ]?\d+\b/i',
            '/\b(?:owner |orch[- ]?\w* )?rulings?\b(?: [A-Z0-9][\w.-]*)?/i',
            '/\b(?:orch|owner|lead)[\w@.-]* \d{1,2}:\d{2}Z\b/i',
            // Tracker keys such as APP-09b or TOWER-14; standards such as PSR-4, UTF-8, SHA-256 or RFC-7231 are not references.
            '/\b(?!(?:PSR|UTF|SHA|MD|RFC|ISO|HTTP|TLS|AES|ES|ECMA|PHP|X)-\d)[A-Z][A-Z0-9]{1,9}-\d+[a-z]?\b/',
            '/\b[a-z][a-z0-9]*(?:-[a-z0-9]+)+ (?:ticket|slice|ruling|DM|map) ?[\w.]*\d\w*\b/i',
        ],
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
