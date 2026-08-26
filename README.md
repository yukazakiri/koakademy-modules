# KoAkademy Module Registry

This repository publishes two read-only distribution indexes:

- `registry.json` — a signed KoAkademy module catalog consumed by the core app.
- `packages.json` — a Composer repository containing the same tagged packages.

The standalone packages are:

```sh
composer config repositories.koakademy composer https://yukazakiri.github.io/koakademy-modules
composer require koakademy/announcement:^1.0
```

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
