# KoAkademy Module Registry

This repository publishes two read-only distribution indexes:

- `registry.json` — a signed KoAkademy module catalog consumed by the core app.
- `packages.json` — a Composer repository containing the same tagged packages.

The standalone packages are:

```sh
composer config repositories.koakademy composer https://yukazakiri.github.io/koakademy-modules
composer require koakademy/announcement:^1.0
```

The registry is metadata and distribution infrastructure. It does not execute
Composer or install PHP code in an already-running KoAkademy container. A
module becomes available to an application when the application image is
built with the package in `composer.lock`; the Marketplace then reports the
catalog entry and controls whether that installed module is enabled.

## Add or update a module

Start with a standalone module repository containing a `module.json`, a
Composer package, a Laravel service provider, tests, and a semver tag matching
the manifest version (`v1.2.3` for `1.2.3`). Contributors can prepare the
registry metadata without access to the signing key:

```sh
curl -L https://github.com/OWNER/REPOSITORY/archive/refs/tags/v1.2.3.zip \
    -o /tmp/module-v1.2.3.zip
php scripts/update-module.php \
    --module=/path/to/module \
    --archive=/tmp/module-v1.2.3.zip
php scripts/validate-registry.php
```

The updater reads `module.json` and `composer.json`, calculates the release
checksums, and updates both `registry.json` and `packages.json`. It removes
the old signature deliberately. Open a pull request with those two generated
files and the module release reference. A maintainer reviews the module and
signs the final catalog with the persistent registry key before merging; see
[`CONTRIBUTING.md`](CONTRIBUTING.md).

## Delivery lifecycle

The complete release and container update sequence is documented in
[`docs/module-lifecycle.md`](docs/module-lifecycle.md). In short:

1. Release a new module tag.
2. Update and validate the registry metadata.
3. Sign the catalog and merge it to `master`.
4. Update the KoAkademy application’s Composer requirement and lockfile.
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
application's `MODULE_REGISTRY_PUBLIC_KEY` secret and set the registry URL to
the HTTPS `registry.json` endpoint.

## Local container

```sh
docker compose -f docker-compose.yml up --build
```

The container serves the Composer repository on port `8080` and persists keys
in the `registry-data` volume.

## Maintainer references

- [Contributing and release procedure](CONTRIBUTING.md)
- [Module lifecycle and deployment guide](docs/module-lifecycle.md)
- [CI and Pages publishing](OSS_CI.md)
- [Security and signing-key reporting](SECURITY.md)
- [Open-source maintenance checklist](OSS_DOCS.md)
