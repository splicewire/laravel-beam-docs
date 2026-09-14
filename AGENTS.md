> You are in **splicewire/laravel-beam-docs** — optional API documentation, docs presentation integration, and Scalar Registry publishing for Beam hosts.

Depends on Beam and its generic UX/MDX infrastructure. Core and generic UX never depend on documentation. See [package ownership](docs/adr/0001-optional-documentation.md) before moving a runtime declaration into this package.

## Vendored family-package conventions

Before editing a vendored family dependency, read that dependency's own `AGENTS.md`. Before changing an I/O surface, read `vendor/splicewire/laravel-beam/docs/agents/particle-doctrine.md`.

## Publication

Read [installation and publishing](README.md) before running a real upload. Publishing snapshots the configured public-reference artifact; HTTP never accepts a filesystem path or credentials. Scalar transport is the pinned CLI, with its protocol verified in tests and upstream source.
