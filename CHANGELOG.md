# Changelog

All notable changes to the public registry are documented here.

## Unreleased

- Release discovery now always proposes an unsigned PR and preserves a pending
  review branch. Signing credentials and direct publication were removed from
  the rebuild workflow.
- New releases use immutable commit archives and source references. Rebuilds
  stage the complete result, preserve no-op signatures, and reject mismatched
  metadata, moved releases, and silent downgrades.
- Added Composer constraint and manifest validation, archive/source identity
  checks, safe ZIP metadata reads, and offline regression coverage in CI.
- New catalog signatures bind the matching Composer index with
  `packages_sha256`, while historical signatures and the public key are retained.
- Documented contributor workbenches, host integration evidence, frontend
  dependencies, explicit permissions, and reviewed module migrations.

- Added `modules.json`, `scripts/rebuild-registry.php`, and
  `.github/workflows/rebuild.yml` to regenerate the catalog automatically from
  module release tags on a schedule or via `repository_dispatch`, opening a
  pull request instead of requiring a manual metadata
  change per release.


- Added `scripts/update-module.php` to generate consistent registry and
  Composer metadata from a tagged standalone module.
- Added `scripts/validate-registry.php` to check catalog/package consistency in
  contributor pull requests and before Pages publishing.
- Documented contributor releases, maintainer signing, Composer lockfile
  updates, Docker image delivery, Swarm rollouts, Marketplace behavior, and
  rollback boundaries.
