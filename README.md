# splicewire/laravel-beam-docs

Optional Beam documentation: Scribe/OpenAPI generation, the existing YAML/JSON endpoints, a docs subtree, and Scalar Registry publication. Install it explicitly in hosts that generate SDK contracts or serve documentation.

## Install and upgrade

Require `splicewire/laravel-beam-docs`, then run `php artisan splicewire:beam:install`. The documentation install step publishes configuration, migrations and the Scribe configuration, and generates the artifact. The generic UX requirements migration must run before the docs adoption migration. Existing docs root IDs, paths, bodies and access tokens are preserved. Configure `beam.docs.root_slug` and `root_namespace` before migration if the host uses a different root identity.

Install `@splicewire/beam-docs` in the frontend. Call `configureDocs()` after the host's `configureEntryPage()` or `beamInertiaOptions()` call, and add `beamDocsPages` to the Inertia page resolver. The host can still inject its patched Scalar factory through `ApiReference`.

Use `php artisan splicewire:beam:docs:generate` for the public reference. The artifact is served at `/beam/openapi.yaml` and `/beam/openapi.json`; generation never happens during a GET. SDK-specific Scribe configurations remain separate explicit build inputs.

## Access and removal

`beam.docs.enabled` controls local docs availability; `beam.docs.visibility` is `public` or `private`. Private readers must satisfy `beam.docs.reader_tokens` (default `['auth']`) through the host's existing entry access gate. Existing entry restrictions still apply. The docs root carries a mandatory `beam-docs` requirement, checked before custom permission gates. Pages, compiled artifacts, navigation, sitemap and downloads honor it. Requirement-bearing responses use `no-store` so later privacy changes apply to subsequent requests.

Generic entries and MDX remain usable after removing this package. Retained docs entries fail closed because their requirement has no installed handler. Removal never deletes authored content or remote Registry versions. Published Scribe configurations guard against absent tooling so configuration loading still works. A previously published public Registry version must be handled separately if its remote visibility should change; disabling local docs does not unpublish historical remote content.

## Scalar publication

Provision a Scalar namespace and API slug, set `beam.docs.scalar.enabled`, and provide the server-side API token through the documented environment setting in the published config. Install the pinned `@scalar/cli@2.1.0` on the publishing worker; it requires Node 24 or newer. Set the executable path in configuration. The CLI is invoked using argument arrays and an isolated child-process credential home, leaving the user's CLI login untouched.

`php artisan splicewire:beam:docs:publish <release-version>` runs publication synchronously for release automation. It validates and uploads the exact captured public-reference bytes. Operator users authorized for `beam-docs.publish` can open `/beam/docs/manage`, queue an upload, inspect its status and retry failures. A worker must process the configured queue. Namespace, slug, artifact source and credentials are server configuration, not operator request fields.

Attempts retain their artifact bytes, SHA-256, explicit release version, destination, visibility and status. Retries preserve the snapshot; they never force-overwrite a Registry version. Existing remote destination visibility must match the intended visibility: Scalar CLI's `--private` only controls creation, so the integration refuses mismatches rather than assuming the flag changes an existing API.

A successful current-destination publication can expose a Registry link beside the embedded API reference when `scalar.show_link` is enabled. Credentials and raw CLI output never reach page props or saved error messages.

## Verification

Run `composer test`. The package's tests exercise generation and artifact routes, privacy/adoption, and command/operator publication through a controlled Scalar executable. A real remote smoke test additionally requires a provisioned Scalar destination and token; unit tests do not claim to upload to Scalar.
