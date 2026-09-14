# Publish a host's OpenAPI artifact to Scalar

Install `@scalar/cli` **2.1.0** on the release runner and queue worker, using Node **24 or newer**.
Set `beam.docs.scalar.executable` to that installed executable. The adapter checks its version before
authentication or publication; it does not install npm packages while handling an operator action.

Generate the public-reference artifact explicitly with the host's normal Scribe build step. The
publisher resolves `OpenApiSpecSource` with a synthetic host request and captures its YAML bytes,
SHA-256, release version, visibility and server-configured destination. It never generates on a
read or accepts an artifact path from HTTP. Keep SDK-only output in a separate Scribe configuration.

Configure `beam.docs.scalar.enabled`, `namespace`, `slug`, `token`, `executable`, `queue`, and optionally
`show_link`. Credentials belong in the worker's environment/configuration; `SCALAR_API_KEY` supplies
the default token. Publish the `beam-docs-migrations` migration and migrate the host database before
enabling publication. This table belongs at the host migration root, not in tenant/shared migrations.
The service rejects active tenant contexts and uses the host's current connection without a hardcoded
connection name.

For releases, call:

```sh
php artisan splicewire:beam:docs:publish 1.2.3 --json
php artisan splicewire:beam:docs:publish --status=ATTEMPT_UUID --json
php artisan splicewire:beam:docs:publish --retry=FAILED_ATTEMPT_UUID --json
```

The first and third commands run synchronously and exit nonzero unless the attempt succeeds. Status
reads do not upload. Exactly one of a release version, `--status`, or `--retry` is required. A retry
creates a new attempt retaining the original bytes and destination; repeated retries of the same
failed attempt identify the same child. Retry the child if that attempt also fails. Versions are never
force-overwritten, including when an upload timed out after Scalar may have accepted it.

The operator endpoints under `/beam/docs/publications` require the host's `beam-docs.publish` Gate for
all reads and writes. POST captures before queueing; the job contains only an attempt ID. The queue
worker must have the same host configuration/database, the CLI and the credentials. Jobs have one try,
a 180-second timeout, and fail-on-timeout; each CLI subprocess is bounded to at most 30 seconds. Configure
the queue connection's retry interval above 180 seconds. Failed dispatch and worker failure hooks record
a failed attempt. A machine terminated without running Laravel's failure hook can leave an attempt
running; inspect the remote version before any operator repair rather than blindly replaying it.

Publication states are `queued`, `running`, `succeeded`, and `failed`. An atomic database transition
prevents duplicate jobs from executing the same attempt concurrently. A later failure leaves earlier
successful publication records intact. `latestSuccessfulUrl()` returns a link only when display is
enabled and a successful record matches the current namespace, slug and visibility. The reader-facing
link endpoint additionally applies the documentation read policy.

## CLI compatibility and privacy

The adapter's protocol was verified against the published
[`@scalar/cli` 2.1.0 tarball](https://registry.npmjs.org/@scalar/cli/-/cli-2.1.0.tgz), alongside Scalar's
[Registry CLI guide](https://scalar.com/products/registry/cli) and
[authentication guide](https://scalar.com/tools/cli/authentication):

- `auth login` reads `SCALAR_API_KEY`; `registry publish` reads `.scalar-config` in the process home.
  Each attempt uses a private temporary home for the child processes. The calling process environment
  and the operator's home/configuration stay untouched. Only the authentication subprocess receives
  the API key; all temporary credentials and artifact files are removed on normal success/failure.
- `registry publish --private` applies privacy when **creating** an API. Adding a version to an existing
  API preserves its current remote access. The adapter reads the pinned CLI's Registry Access table
  first and refuses an existing destination whose visibility differs from the snapshot. Unrecognized,
  truncated or empty output also fails closed. Provision a matching Registry destination before retrying;
  the CLI's metadata update command is not a privacy repair.
- This access check and the subsequent upload are separate remote operations. Restrict concurrent
  Registry access-setting changes while a publication is running. The CLI has no atomic conditional
  upload based on remote visibility.
- Private snapshots always receive `--private`. A queued public snapshot is refused if local docs
  become private or disabled; the policy is checked again after validation/authentication, immediately
  before upload. Destination changes also block retries until the original configuration is restored.
- Arguments are passed as an array without a shell. Output is bounded; raw stdout/stderr is never
  persisted because upstream authentication failures can contain exchanged credentials. Errors identify
  the failed stage and actionable category. Success must include the exact expected HTTPS Registry URL.

Package tests use an executable fixture to verify arguments, bytes, authentication isolation, cleanup,
access mismatches, failure stages and timeouts without an account. A separate test races two real worker
processes against an isolated SQLite file. Real publication still requires host-provisioned credentials
and a destination; package installation never uploads anything.
