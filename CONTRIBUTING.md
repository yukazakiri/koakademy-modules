# Contributing modules

Thank you for contributing a KoAkademy module. Each module lives in its own
repository and is added to this registry through a reviewed metadata change.
The registry is intentionally separate from module source code so the public
catalog can be signed and applications can consume packages through Composer.

## Module requirements

A module repository must provide:

- `module.json` with a unique `name`, `alias`, `composer_package`, `version`,
  `description`, `author`, `license`, `repository`, `homepage`, and at least
  one service-provider class in `providers`.
- `composer.json` with the same package identity, PSR-4 autoloading, Laravel
  provider discovery, runtime requirements, and a compatible open-source
  license.
- Database migrations, authorization rules, tests, and upgrade notes when the
  module changes persisted data.
- A public semver Git tag whose name is `v` followed by the manifest version.
  For example, manifest `1.2.3` must be released as tag `v1.2.3`.
- A passing module CI workflow before the registry pull request is opened.

Keep the module’s provider class and Composer package name stable after the
first public release. Changing either one can make an installed module look
like a different package or prevent an existing application from booting.

## Contributor workflow

Registry metadata is rebuilt automatically by `.github/workflows/rebuild.yml`
from the repositories listed in `modules.json`. The manual commands below are
for one-off or local changes only; normal releases no longer require them.


Run these commands from a clean checkout of this registry repository after
the module release has been tagged:

```sh
MODULE_REPO="https://github.com/OWNER/REPOSITORY"
MODULE_VERSION="1.2.3"
curl -L "$MODULE_REPO/archive/refs/tags/v$MODULE_VERSION.zip" \
    -o "/tmp/module-v$MODULE_VERSION.zip"

php scripts/update-module.php \
    --module=/path/to/module-repository \
    --archive="/tmp/module-v$MODULE_VERSION.zip"
php scripts/validate-registry.php
```

On Windows PowerShell, the download equivalent is:

```powershell
$moduleRepo = 'https://github.com/OWNER/REPOSITORY'
$moduleVersion = '1.2.3'
Invoke-WebRequest `
    -Uri "$moduleRepo/archive/refs/tags/v$moduleVersion.zip" `
    -OutFile "$env:TEMP/module-v$moduleVersion.zip"

php scripts/update-module.php `
    --module='C:\path\to\module-repository' `
    --archive="$env:TEMP/module-v$moduleVersion.zip"
php scripts/validate-registry.php
```

The command updates both indexes and prints a message that the signature was
removed. That is expected: contributors must not generate a competing key or
commit a private key. Commit the generated `registry.json` and
`packages.json`, include the module repository/tag in the pull request, and
explain any migrations or compatibility changes.

## Maintainer merge workflow

After reviewing the module repository, the maintainer runs the following with
the existing private key loaded from a protected workstation, secret store,
or persistent registry deployment volume:

```sh
php scripts/validate-registry.php
php scripts/sign-registry.php \
    registry.json \
    /secure/registry/registry-private.key \
    registry.json
php scripts/validate-registry.php
php scripts/verify-registry.php
```

The private key must never be committed, pasted into an issue, or stored in a
module repository. The public key is the trust root for existing KoAkademy
installations. Do not run the key-generation script for a normal module
update: generating a new key would make existing installations reject the
catalog. Key rotation requires a separate, coordinated migration of every
application’s `MODULE_REGISTRY_PUBLIC_KEY` secret.

After the signed change is merged to `master`:

- Registry CI validates structure and signature.
- GitHub Pages publishes the signed catalog and Composer metadata.
- Composer can resolve the new package version from the registry.
- The KoAkademy repository must update its package requirement/lockfile and
  build a new image before deployed containers receive the code.

## Pull request checklist

- [ ] The module repository has a public `vX.Y.Z` tag matching `module.json`.
- [ ] The module CI checks pass.
- [ ] `php scripts/update-module.php` generated both registry indexes.
- [ ] `php scripts/validate-registry.php` passes locally.
- [ ] The pull request does not contain a private signing key.
- [ ] Migrations, permissions, compatibility, and rollback impact are
      described.
- [ ] A maintainer will sign the catalog after review.

## Updating an existing module

Use the same process for every new version. For example, after releasing
`koakademy/announcement` `1.0.2`, update the registry and then update the
KoAkademy application:

```sh
composer update koakademy/announcement --with-dependencies
php artisan test --compact
git add composer.json composer.lock
git commit -m "chore(modules): update announcement"
git push
```

The application image build must install the updated lockfile. For a running
Docker Swarm deployment, deploy that new image, run `php artisan migrate
--force` when the module includes migrations, clear/rebuild application cache,
and roll all replicas. The Marketplace does not run Composer in the live
container.

If the package is not yet declared by the application, use a first-install
command instead of `composer update`:

```sh
composer require koakademy/announcement:^1.0.2
```

Only use the update command after the package is present in the application’s
`composer.json`. A local source-tree module must be migrated to the package as
described below before this command can replace it.

### Important legacy-module note

If a module still exists as source under the KoAkademy application’s local
`Modules/` directory, publishing a standalone package does not automatically
replace that source. The application must first be migrated to the Composer
package and the local copy removed in a separately tested change. Until that
migration is complete, updates to the standalone repository affect only
applications that actually install the standalone package.
