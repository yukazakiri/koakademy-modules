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
- Evidence from a compatible Koakademy host showing navigation, authorization,
  tenant isolation, migrations, and disabled-module behavior. Use the
  [extension starter](https://github.com/yukazakiri/koakademy/pull/486) as a workbench.

Keep the module’s provider class and Composer package name stable after the
first public release. Changing either one can make an installed module look
like a different package or prevent an existing application from booting.

## Contributor workflow

Submit a PR adding your public GitHub repository to the `modules.json` allowlist.
After the allowlist is reviewed, `.github/workflows/rebuild.yml` discovers new
stable releases and proposes unsigned catalog PRs. Every release still needs
maintainer review; discovery is not approval. The job leaves a pending generated
PR unchanged so its reviewed artifacts cannot be overwritten by the next run.

The manual commands below support local proposals. Install dependencies first
with `composer install`; PHP 8.5 with Sodium and ZIP is required. Check out the
exact tagged module commit before running the updater. Do not pass a different
checkout's metadata alongside a release ZIP.


Run these commands from a clean checkout of this registry repository after
the module release has been tagged:

```sh
MODULE_REPO="https://github.com/OWNER/REPOSITORY"
MODULE_VERSION="1.2.3"
MODULE_COMMIT="$(git -C /path/to/module-repository rev-parse "v$MODULE_VERSION^{commit}")"
curl --fail --location "$MODULE_REPO/archive/$MODULE_COMMIT.zip" \
    -o "/tmp/module-v$MODULE_VERSION.zip"

php scripts/update-module.php \
    --module=/path/to/module-repository \
    --archive="/tmp/module-v$MODULE_VERSION.zip" \
    --tag="v$MODULE_VERSION" \
    --source-ref="$MODULE_COMMIT"
php scripts/validate-registry.php
```

On Windows PowerShell, the download equivalent is:

```powershell
$moduleRepo = 'https://github.com/OWNER/REPOSITORY'
$moduleVersion = '1.2.3'
$moduleCommit = git -C 'C:\path\to\module-repository' rev-parse "v$moduleVersion^{commit}"
Invoke-WebRequest `
    -Uri "$moduleRepo/archive/$moduleCommit.zip" `
    -OutFile "$env:TEMP/module-v$moduleVersion.zip"

php scripts/update-module.php `
    --module='C:\path\to\module-repository' `
    --archive="$env:TEMP/module-v$moduleVersion.zip" `
    --tag="v$moduleVersion" `
    --source-ref="$moduleCommit"
php scripts/validate-registry.php
```

The command updates both indexes and prints a message that the signature was
removed. That is expected: contributors must not generate a competing key or
commit a private key. Commit the generated `registry.json` and
`packages.json`, include the module repository/tag in the pull request, and
explain any migrations or compatibility changes.

The updater rejects mismatched Composer identities/providers, invalid dependency
constraints, unsafe archives, changed existing releases, and version downgrades.
It preserves `permissions`, `capabilities`, and Composer license metadata.
Declare additional frontend dependencies as a reviewed host lockfile change;
a package-local `package.json` does not install dependencies in the host image.

## Maintainer merge workflow

After reviewing the module repository, the maintainer runs the following with
the existing private key loaded from a protected workstation, secret store,
or persistent registry deployment volume:

```sh
composer lint
composer test
php scripts/validate-registry.php
php scripts/sign-registry.php \
    registry.json \
    /secure/registry/registry-private.key \
    registry.json
php scripts/validate-registry.php
php scripts/verify-registry.php
```

Review the exact `source_ref`, matching archive checksums, passing module CI and
host integration evidence, database migrations, requested permissions and
capabilities, npm dependencies, and rollback behavior before signing. Signing
binds `packages.json` through `packages_sha256`; regenerate and resign together
if either index changes. Keep the committed public key unchanged.

Push the signed commit with maintainer credentials to trigger PR checks.
Generated PRs created by `GITHUB_TOKEN` do not automatically start other
workflows; Registry CI also supports manual dispatch on the generated branch.
Do not merge an unsigned catalog. This repository does not provide a hosted
signing service or require a private signing secret in discovery/validation CI.

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
- [ ] A compatible host integration run passes and the exact source commit is recorded.
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
