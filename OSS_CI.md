# Registry CI and publishing

## Local checks and contributor CI

Run PHP 8.5 with Sodium and ZIP:

```sh
composer install
composer validate --strict
composer lint
composer test
composer registry-validate
composer registry-verify
```

The primary workflow runs on pushes, pull requests, and manual dispatch. It
installs the committed Composer lockfile, caches Composer downloads, lints all
PHP scripts and tests, runs the offline regression suite, and validates both
indexes. Errors fail the job. There are no private signing secrets, application
servers, or live module APIs in these checks. Dependencies are downloaded during
installation; the test suite itself uses temporary archives and throwaway keys.

PRs may contain unsigned proposals. Pushes to master must also pass signature
verification with the committed public key. Historical signed catalogs remain
verifiable. Newly signed catalogs include packages_sha256 and must match the
exact Composer index bytes. Contributor CI cannot approve a module's application
behavior: maintainers still need passing module and compatible host CI evidence.

## Release discovery

The rebuild workflow runs daily, manually, or on a module-released repository
dispatch. It uses the modules.json allowlist, paginated stable tags, and immutable
commit archives. A failure leaves the committed indexes intact. An unchanged
run preserves their existing signature. Changes always become an unsigned PR;
there is no signing secret or direct release push to master.

Runs are serialized. An open chore/registry-rebuild PR is left untouched while
maintainers review and sign it. The repository must allow Actions to create
pull requests. The built-in GITHUB_TOKEN has contents/PR write access only in
this job; other workflows use read access except the Pages deployment job.

GitHub does not trigger another workflow from a PR created using GITHUB_TOKEN.
Push the reviewed, signed commit with maintainer credentials to run PR checks,
or manually dispatch Registry CI on the generated branch before review. A
private signing credential must never be added to contributor or discovery CI.

## Publishing

Pages installs the locked tooling and validates structure and signature before
uploading the catalog, Composer index, and public key as one artifact. Verification
rejects a mismatch between a newly signed catalog and its Composer index. The
local container also validates and verifies its snapshot before serving it.
These publication checks do not make Composer itself an Ed25519 verifier.

The container's generated keypair persists in registry-data. Back up that volume
through the deployment's secret-management process. Its key is separate from
the existing public catalog's trust root; do not replace a production public key
with a local development key.

## Remaining operational requirements

- Protect master and require Registry CI before merging reviewed, signed releases.
- Keep signing access on a protected maintainer workstation or secret store.
- Review source commits, module/host CI, permissions, migrations, frontend
  dependencies, and rollback behavior before signing.
- Roll back the host image and lockfile together; the registry never installs
  code in an already-running school application.

These repository settings and private signing operations are not performed by
the contributor workflows.
