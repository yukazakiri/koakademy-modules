# Open-source documentation and maintenance

This repository is the public distribution point for KoAkademy modules. The
following documents are the maintained entry points:

- `README.md`: purpose, quick start, and lifecycle overview.
- `CONTRIBUTING.md`: module contract, contributor workflow, signing boundary,
  and pull-request checklist.
- `docs/module-lifecycle.md`: release, Composer, image, Swarm, Marketplace,
  and rollback behavior.
- `OSS_CI.md`: local and GitHub Actions validation commands.
- `SECURITY.md`: private reporting and signing-key handling.
- `CHANGELOG.md`: changes to registry tooling and public behavior.

## Architecture contract

The contract is intentionally small and reviewable:

1. `registry.json` is the signed KoAkademy catalog.
2. `packages.json` is the Composer repository for the same releases.
3. `scripts/update-module.php` derives both indexes from a module manifest,
   Composer metadata, and the exact tag archive.
4. `scripts/validate-registry.php` checks cross-file consistency without
   requiring the private key, so contributor pull requests can be verified.
5. `scripts/sign-registry.php` is the maintainer-only signing step.
6. `scripts/verify-registry.php` verifies the public trust root used by
   applications.

The signing key is deployment state, not repository content. It must persist
across registry container restarts and must not be regenerated for ordinary
module updates.

## Maintenance checklist

For every module release:

- confirm the module tag, manifest version, repository URL, provider, and
  Composer package name agree;
- run the updater and both validators;
- review checksums and URLs in the generated diff;
- review runtime requirements, migrations, permissions, and compatibility;
- sign only after review;
- update the KoAkademy application lockfile and image separately.

For registry tooling changes:

- add or update a focused validation case;
- run PHP syntax checks and both registry checks;
- verify Pages still validates before publishing;
- update the lifecycle documentation when delivery behavior changes.

## Reproducibility

The registry records SHA-256 checksums for catalog releases and SHA-1
distribution checksums for Composer. Applications should use the committed
`composer.lock` and immutable application images. A registry refresh alone is
not an application upgrade.

## License and attribution

The Composer metadata declares `AGPL-3.0-or-later` for this registry and the
published packages. Each module repository remains responsible for its own
license file, notices, dependencies, and third-party attribution.

## Deferred follow-ups

- Store the existing private signing key in a documented production secret
  manager or protected registry volume and test restoration before the next
  catalog update.
- Add a formal, organization-owned key-rotation runbook before rotating the
  public trust root.
- Add a complete repository `LICENSE` text if the project policy requires the
  license to be distributed as a standalone file in addition to SPDX metadata.
