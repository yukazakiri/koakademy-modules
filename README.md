# KoAkademy Module Registry

This repository publishes `registry.json`, the signed Marketplace catalog.
Public Composer packages are distributed through [Packagist](https://packagist.org/).
The generated `packages.json` remains available as a legacy Composer index for
existing installations. Newly signed catalogs bind its exact bytes; historical
signatures may predate that binding.

After a public release is available on Packagist, install it in a compatible host:

```sh
composer require koakademy/forms:^1.10
```

The registry is metadata and distribution infrastructure. It does not execute
Composer or install PHP code in an already-running KoAkademy container. A
module becomes available to an application when the application image is
built with the package in `composer.lock`; the Marketplace then reports the
catalog entry and controls whether that installed module is enabled.

## Add or update a module

Start with a standalone module repository containing a `module.json`, a
Composer package, a Laravel service provider, tests, and a semver tag matching
the manifest version (`v1.2.3` for `1.2.3`). The registry discovers releases
from the module repositories listed in `modules.json`. Publish the tagged
package on Packagist and verify its package name, version, and source commit
before proposing its Marketplace listing. Publishing to Packagist does not
automatically add or approve a catalog entry.

Use the [Koakademy extension starter and SDK](https://github.com/yukazakiri/koakademy/pull/486)
for a working package, tenant policies, permissions, frontend integration, and
contributor CI. Pin a compatible released host revision when using its workbench.

### Reviewed rebuilds

`.github/workflows/rebuild.yml` proposes updates to both indexes.
It runs daily, on `workflow_dispatch`, and on a `repository_dispatch` event
named `module-released`. It selects the highest stable semver across paginated
tags, resolves new releases to an immutable commit, reads metadata from that
commit's ZIP without executing module code, and recalculates checksums. The
archive's manifest version and repository must match the selected release;
the tooling never rewrites a mismatched source manifest.

Changed catalogs always become unsigned pull requests for review. The discovery
job has no private signing key and does not sign or push releases directly to `master`.
An existing generated PR is left untouched while it awaits review. GitHub must
allow Actions to create pull requests; the job uses its built-in `GITHUB_TOKEN`.

To add a module, submit a PR adding its repository to `modules.json` with the
Packagist link, release, CI evidence, compatibility, migrations, permissions,
and rollback notes. Maintainers must manually verify that Packagist exposes the
same release and source commit; the builder does not check Packagist. Later tags
are discovered without repeated allowlist edits, but every changed catalog still
requires release review and signing. PRs created with `GITHUB_TOKEN` do not start
other Actions workflows: push the reviewed, signed commit with maintainer
credentials, or manually run Registry CI on the generated branch.

### Manual rebuild (optional)

```sh
composer install
php scripts/rebuild-registry.php
php scripts/validate-registry.php
```

The full rebuild is staged and validated before either index is replaced. A
failure in a later module leaves the committed files intact; a no-op preserves
both files and their signature byte for byte. Changed catalogs lose the old
signature. Existing releases cannot be replaced with different bytes or silently
downgraded. Historical tag-based entries remain unchanged; new releases record
`source_ref` and use commit-based archives and Composer source references.

### Maintainer signing

After review, sign the catalog with the persistent registry key:

```sh
php scripts/sign-registry.php \
    registry.json \
    /secure/registry/registry-private.key \
    registry.json
php scripts/validate-registry.php
php scripts/verify-registry.php
```

New signatures include `packages_sha256`, binding the exact Composer index
bytes into the signed catalog. Verification checks that binding before Pages
or the local container serves it. Older published catalogs without this field
remain verifiable with their existing key; this change does not rotate keys or
resign the committed catalog. Composer consumers still rely on HTTPS and their
lockfile; Composer does not itself verify the catalog's Ed25519 signature.

## Verify locally

```sh
composer install
composer validate --strict
composer lint
composer test
composer registry-validate
composer registry-verify
```

The regression suite uses temporary fixture archives, mocked GitHub responses,
and throwaway signing keys. It needs no network, application server, or secrets
after dependencies are installed. PHP 8.5 with Sodium and ZIP is required.

## Delivery lifecycle

The complete release and container update sequence is documented in
[`docs/module-lifecycle.md`](docs/module-lifecycle.md). In short:

1. Release a new module tag and verify that version and commit on Packagist.
2. The registry rebuild workflow detects the release.
3. A maintainer reviews the exact source commit, module and host CI, permissions,
   migrations, frontend dependencies, and rollback impact, then signs and merges.
4. Update the KoAkademy application Composer requirement and lockfile.
5. Build and deploy a new application image.
6. Run migrations/cache clearing and roll the Swarm service.

Updating the registry alone changes what the Marketplace can display. It does
not change a previously built application image.

## Signing

The registry uses Ed25519. The private key is never committed. A container
deployment runs `scripts/ensure-signing-key.php` on first start, stores the
keypair in the persistent `REGISTRY_KEY_DIR`, signs the catalog, and exposes
the matching public key as `registry-public-key.txt`. Restarts reuse the same
key; a mismatched existing key fails rather than rotating silently.

For a public deployment, copy the generated public key into the KoAkademy
application `MODULE_REGISTRY_PUBLIC_KEY` secret and set the registry URL to
the HTTPS `registry.json` endpoint.

## Local container

```sh
docker compose -f docker-compose.yml up --build
```

The container serves the catalog and legacy Composer index on port `8080` and persists keys
in the `registry-data` volume.

## Maintainer references

- [Contributing and release procedure](CONTRIBUTING.md)
- [Module lifecycle and deployment guide](docs/module-lifecycle.md)
- [CI and Pages publishing](OSS_CI.md)
- [Security and signing-key reporting](SECURITY.md)
- [Open-source maintenance checklist](OSS_DOCS.md)
