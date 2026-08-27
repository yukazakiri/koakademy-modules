# Changelog

All notable changes to the public registry are documented here.

## Unreleased

- Added `modules.json`, `scripts/rebuild-registry.php`, and
  `.github/workflows/rebuild.yml` to regenerate the catalog automatically from
  module release tags on a schedule or via `repository_dispatch`, opening a
  pull request (or signing and pushing) instead of requiring a manual metadata
  change per release.


- Added `scripts/update-module.php` to generate consistent registry and
  Composer metadata from a tagged standalone module.
- Added `scripts/validate-registry.php` to check catalog/package consistency in
  contributor pull requests and before Pages publishing.
- Documented contributor releases, maintainer signing, Composer lockfile
  updates, Docker image delivery, Swarm rollouts, Marketplace behavior, and
  rollback boundaries.
