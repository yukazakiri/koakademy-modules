<?php

declare(strict_types=1);

$registryPath = $argv[1] ?? __DIR__.'/../registry.json';
$packagesPath = $argv[2] ?? __DIR__.'/../packages.json';

$registry = readJson($registryPath);
$packages = readJson($packagesPath);

if (($registry['schema'] ?? null) !== 1) {
    fail('The registry schema must be 1.');
}

if (! is_array($registry['modules'] ?? null)) {
    fail('The registry modules value must be an array.');
}

$modulesByPackage = [];
$names = [];
$aliases = [];

foreach ($registry['modules'] as $index => $module) {
    if (! is_array($module)) {
        fail("Registry module [{$index}] must be an object.");
    }

    foreach (['name', 'alias', 'composer_package', 'version', 'description', 'author', 'license', 'repository', 'homepage'] as $field) {
        requireString($module[$field] ?? null, "Registry module [{$index}] field [{$field}]");
    }

    $name = (string) $module['name'];
    $alias = (string) $module['alias'];
    $composerPackage = (string) $module['composer_package'];
    $nameKey = mb_strtolower($name);
    $aliasKey = mb_strtolower($alias);

    if (isset($names[$nameKey])) {
        fail("Duplicate module name [{$name}].");
    }

    if (isset($aliases[$aliasKey])) {
        fail("Duplicate module alias [{$alias}].");
    }

    if (isset($modulesByPackage[$composerPackage])) {
        fail("Duplicate Composer package [{$composerPackage}].");
    }

    $names[$nameKey] = true;
    $aliases[$aliasKey] = true;
    $modulesByPackage[$composerPackage] = $module;

    if (! is_array($module['requires'] ?? null) || ! is_array($module['compatibility'] ?? null)) {
        fail("Registry module [{$name}] requires object-valued requires and compatibility fields.");
    }

    if (! is_array($module['providers'] ?? null) || $module['providers'] === []) {
        fail("Registry module [{$name}] must declare at least one provider.");
    }

    if (! is_array($module['versions'] ?? null) || $module['versions'] === []) {
        fail("Registry module [{$name}] must declare at least one release.");
    }

    $releaseVersions = [];

    foreach ($module['versions'] as $releaseIndex => $release) {
        if (! is_array($release)) {
            fail("Registry module [{$name}] release [{$releaseIndex}] must be an object.");
        }

        foreach (['version', 'asset_url', 'sha256', 'released_at'] as $field) {
            requireString($release[$field] ?? null, "Registry module [{$name}] release [{$releaseIndex}] field [{$field}]");
        }

        $releaseVersion = (string) $release['version'];

        if (isset($releaseVersions[$releaseVersion])) {
            fail("Registry module [{$name}] contains duplicate release [{$releaseVersion}].");
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', (string) $release['sha256'])) {
            fail("Registry module [{$name}] release [{$releaseVersion}] must contain a SHA-256 checksum.");
        }

        if (! filter_var($release['asset_url'], FILTER_VALIDATE_URL)) {
            fail("Registry module [{$name}] release [{$releaseVersion}] has an invalid asset URL.");
        }

        if (! is_array($release['requires'] ?? null)) {
            fail("Registry module [{$name}] release [{$releaseVersion}] requires an object-valued requires field.");
        }

        $releaseVersions[$releaseVersion] = $release;
    }

    if (! isset($releaseVersions[(string) $module['version']])) {
        fail("Registry module [{$name}] current version [{$module['version']}] is missing from its releases.");
    }
}

if (! is_array($packages['packages'] ?? null)) {
    fail('The Composer packages value must be an object.');
}

foreach ($packages['packages'] as $composerPackage => $packageVersions) {
    if (! is_string($composerPackage) || ! isset($modulesByPackage[$composerPackage])) {
        fail("Composer package [{$composerPackage}] is not represented in registry.json.");
    }

    if (! is_array($packageVersions) || $packageVersions === []) {
        fail("Composer package [{$composerPackage}] must contain at least one version.");
    }

    $registryReleases = [];

    foreach ($modulesByPackage[$composerPackage]['versions'] as $release) {
        $registryReleases[(string) $release['version']] = $release;
    }

    foreach ($packageVersions as $version => $package) {
        if (! is_array($package)) {
            fail("Composer package [{$composerPackage}] version [{$version}] must be an object.");
        }

        if (! isset($registryReleases[(string) $version])) {
            fail("Composer package [{$composerPackage}] version [{$version}] is missing from registry.json.");
        }

        if (($package['name'] ?? null) !== $composerPackage || ($package['version'] ?? null) !== $version) {
            fail("Composer package [{$composerPackage}] version [{$version}] has inconsistent name or version metadata.");
        }

        $dist = $package['dist'] ?? null;

        if (! is_array($dist) || ($dist['type'] ?? null) !== 'zip' || ($dist['url'] ?? null) !== $registryReleases[$version]['asset_url']) {
            fail("Composer package [{$composerPackage}] version [{$version}] has inconsistent distribution metadata.");
        }

        if (! is_string($dist['shasum'] ?? null) || ! preg_match('/^[a-f0-9]{40}$/i', $dist['shasum'])) {
            fail("Composer package [{$composerPackage}] version [{$version}] must contain a SHA-1 distribution checksum.");
        }

        $source = $package['source'] ?? null;

        if (! is_array($source) || ($source['reference'] ?? null) !== "v{$version}") {
            fail("Composer package [{$composerPackage}] version [{$version}] must reference tag [v{$version}].");
        }
    }
}

foreach (array_keys($modulesByPackage) as $composerPackage) {
    if (! array_key_exists($composerPackage, $packages['packages'])) {
        fail("Registry module [{$composerPackage}] has no Composer package entry.");
    }

    $packageVersions = $packages['packages'][$composerPackage];

    foreach ($modulesByPackage[$composerPackage]['versions'] as $release) {
        $releaseVersion = (string) $release['version'];

        if (! array_key_exists($releaseVersion, $packageVersions)) {
            fail("Registry module [{$composerPackage}] release [{$releaseVersion}] has no Composer package entry.");
        }
    }
}

fwrite(STDOUT, "Registry structure is valid.\n");

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    if (! is_file($path)) {
        fail("JSON file [{$path}] does not exist.");
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        fail("JSON file [{$path}] must contain an object.");
    }

    return $decoded;
}

function requireString(mixed $value, string $field): void
{
    if (! is_string($value) || mb_trim($value) === '') {
        fail("{$field} must be a non-empty string.");
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "Registry validation failed: {$message}\n");
    exit(1);
}
