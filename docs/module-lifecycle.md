# Module release and deployment lifecycle

This guide describes what changes at each layer when a module is released or
updated.

## The four layers

| Layer | Purpose | How it changes |
| --- | --- | --- |
| Module repository | Source code, tests, migrations, and release tags | Maintainer merges code and creates `vX.Y.Z` |
| Registry | Signed catalog plus Composer metadata | Maintainer runs the updater, signs, and merges `master` |
| KoAkademy application | Declared package versions in `composer.json` and `composer.lock` | Application maintainer runs Composer and commits the lockfile |
| Container/Swarm | Built PHP/JS code and database state | Deployment builds a new image, migrates, and rolls replicas |

The Marketplace sits beside these layers. It reads the signed catalog and the
modules installed in the current application. It can enable or disable an
installed module, but it is not a package manager and does not replace the
application image.

## Releasing a new module version

1. Develop and test the module in its standalone repository.
2. Update `module.json` to the new semver version and update migrations,
   permissions, compatibility requirements, and changelog information.
3. Create and push the matching tag, for example `v1.2.3`.
4. The registry rebuild workflow detects the new tag automatically, either on
   its daily schedule or via a `module-released` dispatch from the module
   repository. No manual metadata edit is required; `scripts/rebuild-registry.php`
   downloads the tag, recalculates checksums, and normalizes the manifest
   version to the tag.

5. If the `REGISTRY_PRIVATE_KEY` secret is configured, the workflow signs the
   catalog and pushes to `master`. Otherwise it opens a pull request; a
   maintainer signs the catalog with the persistent Ed25519 private key and
   verifies it:

   ```sh
   php scripts/sign-registry.php registry.json /secure/registry-private.key registry.json
   php scripts/validate-registry.php
   php scripts/verify-registry.php
   ```

6. Merge to `master`. Registry CI and the Pages workflow validate the signed
   files before the public catalog is published.

## Installing or updating it in KoAkademy

The KoAkademy application owns the version that actually runs. After the
registry release is public, update the application repository:

```sh
composer update koakademy/announcement --with-dependencies
php artisan test --compact
git add composer.json composer.lock
git commit -m "chore(modules): update announcement"
git push
```

If the application does not declare the package yet, use
`composer require koakademy/announcement:^1.0.2` to add it and generate the
lockfile. Do not run `composer update` for a package that is not in the
application’s requirements.

For a first installation, add the package to the application’s Composer
requirements and commit the generated lockfile. Do not depend on a live
container resolving arbitrary versions at startup; reproducible images use
the committed lockfile.

The application image pipeline then performs the normal Composer install and
frontend build. Dokploy/Swarm must roll out that new image. After deployment:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
```

Run the migration/cache commands through the deployment’s release/one-off
container so all Swarm replicas start from the same database and optimized
configuration. Follow the application’s existing health-check and rollback
procedure.

## What existing deployments do

An existing deployment remains on its old module version until all of these
occur:

1. The standalone module release is published.
2. The signed registry catalog is updated.
3. The KoAkademy application lockfile is updated.
4. A new application image is built and deployed.

Refreshing the Marketplace page can show the newer catalog version, but it
does not mutate `vendor/`, rebuild assets, run Composer, or restart Swarm.
This separation prevents a web request from changing production code without
review and image provenance.

## Enabling through the Marketplace

When a module is already installed in the image, the Marketplace can show it
and an administrator can enable it. If it is disabled, enabling it changes
application module state; it does not download the package. If the module is
not installed in the image, the correct order is:

1. Add the Composer package to the application.
2. Build and deploy the image.
3. Run migrations and clear caches.
4. Open Marketplace and enable the installed module.

For existing installations that already have legacy source-tree modules
enabled, leave them enabled while planning their package migration. A
standalone registry release cannot update a duplicate local module until the
application is deliberately switched to the package.

## Rollback

Roll back the application image and Composer lockfile together. If the new
module ran an irreversible migration, follow that module’s documented rollback
procedure instead of assuming that `composer install` reverses database
changes. Registry entries are historical metadata; removing a catalog entry
does not remove code from an image or undo a database migration.
