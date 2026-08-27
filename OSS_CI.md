# Registry CI and publishing

## Pull requests and pushes

`.github/workflows/ci.yml` runs on pushes and pull requests. It performs PHP
syntax checks, validates the relationship between `registry.json` and
`packages.json`, and verifies the Ed25519 signature on pushes to `master`.

Pull requests may contain unsigned generated catalog changes because the
private signing key must not be available to contributors. A master push must
be signed, so an unsigned or tampered catalog cannot be published accidentally.

The core local equivalents are:

```sh
php -l scripts/ensure-signing-key.php
php -l scripts/sign-registry.php
php -l scripts/update-module.php
php -l scripts/validate-registry.php
php -l scripts/verify-registry.php
php scripts/validate-registry.php
php scripts/verify-registry.php
```

## GitHub Pages

`.github/workflows/pages.yml` runs for registry/index, signing-key, script, and
workflow changes on `master`. Before copying files to the Pages artifact it
runs both structural validation and signature verification. This makes the
published URL fail closed when a catalog is malformed or signed by the wrong
key.

## No secrets in CI

The public validation workflows need no repository secrets. The private
signing operation is deliberately a maintainer-controlled step. If signing is
later automated, use a protected environment and a secret manager with audit
logging; never put the private key in GitHub Actions logs or ordinary
repository variables.

## Automated rebuilds

`.github/workflows/rebuild.yml` regenerates `registry.json` and `packages.json`
from the module repositories listed in `modules.json`. It runs daily, on
`workflow_dispatch`, and on `repository_dispatch` (`module-released`). It uses
`scripts/rebuild-registry.php`, validates the result, then either:

- signs the catalog and pushes to `master` when `REGISTRY_PRIVATE_KEY` is
  available in a protected environment, or
- opens a pull request for a maintainer to sign and merge.

The rebuild is idempotent: when no module has a new release, the generated
files match the committed ones and nothing is pushed. Signing remains a
maintainer-controlled step when the private key is not configured as a secret.


## Local container

The local Docker Compose stack serves the Composer repository and persists the
generated keypair in the `registry-data` volume. Back up that volume or the
private key through the deployment’s secret-management process before
recreating the registry service. A new key changes the trust root and requires
coordinated application configuration updates.

## Troubleshooting

- `Registry structure is invalid`: compare the module manifest and Composer
  package, then rerun `scripts/update-module.php`.
- `The registry signature could not be verified`: restore the existing private
  key or revert the unsigned catalog; do not generate a replacement key as a
  quick fix.
- Composer cannot resolve a new version: verify that Pages has published
  `packages.json`, then update the KoAkademy application lockfile and rebuild
  its image.
- Marketplace shows a module but it cannot enable: confirm the matching
  package is installed in the image and that its core/PHP compatibility matches
  the running application version.
