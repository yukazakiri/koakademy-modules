<?php

declare(strict_types=1);

use function Koakademy\Registry\archiveMetadata;
use function Koakademy\Registry\manifest;
use function Koakademy\Registry\releasedAt;
use function Koakademy\Registry\repository;
use function Koakademy\Registry\writeIndexes;

require_once __DIR__.'/lib/metadata.php';

$options = getopt('', ['module:', 'archive:', 'released-at:', 'tag:', 'source-ref:', 'registry:', 'packages:']);
$modulePath = $options['module'] ?? null;
$archivePath = $options['archive'] ?? null;
$releasedAt = $options['released-at'] ?? gmdate('Y-m-d\TH:i:s\Z');
$registryPath = $options['registry'] ?? __DIR__.'/../registry.json';
$packagesPath = $options['packages'] ?? __DIR__.'/../packages.json';

if (! is_string($modulePath) || ! is_string($archivePath)) {
    fail('Usage: php scripts/update-module.php --module=/path/to/module --archive=/path/to/release.zip [--released-at=YYYY-MM-DDTHH:MM:SSZ]');
}

$modulePath = realpath($modulePath) ?: $modulePath;
$archivePath = realpath($archivePath) ?: $archivePath;
$manifest = readJson($modulePath.'/module.json');
$composer = readJson($modulePath.'/composer.json');
$registry = readJson($registryPath);
$packages = readJson($packagesPath);

if (! is_file($archivePath)) {
    fail("Release archive [{$archivePath}] does not exist.");
}

manifest($manifest);
releasedAt($releasedAt);
[$archiveManifest, $archiveComposer] = archiveMetadata($archivePath);
if ($archiveManifest != $manifest || $archiveComposer != $composer) {
    fail('The supplied module metadata differs from the release archive. Update and tag the actual source before publishing.');
}
if (($composer['name'] ?? null) !== $manifest['composer_package']
    || (isset($composer['version']) && $composer['version'] !== $manifest['version'])) {
    fail('Composer name/version must match module.json.');
}

foreach (['name', 'alias', 'composer_package', 'version', 'description', 'author', 'license', 'repository', 'homepage'] as $field) {
    requireString($manifest[$field] ?? null, "module.json field [{$field}]");
}

$packageName = (string) $manifest['composer_package'];
$version = (string) $manifest['version'];
$repository = repository($manifest['repository']);
$tag = $options['tag'] ?? 'v'.$version;
if (! in_array($tag, [$version, 'v'.$version], true)) {
    fail('The release tag must match the manifest version.');
}
$sourceRef = $options['source-ref'] ?? null;
if ($sourceRef !== null && (! is_string($sourceRef) || ! preg_match('/^[a-f0-9]{40}$/D', $sourceRef))) {
    fail('The source reference must be an immutable Git commit SHA.');
}
$assetUrl = $sourceRef !== null ? "{$repository}/archive/{$sourceRef}.zip" : "{$repository}/archive/refs/tags/{$tag}.zip";
$sha256 = hash_file('sha256', $archivePath);
$shasum = hash_file('sha1', $archivePath);

if (! is_string($sha256) || ! is_string($shasum)) {
    fail("Unable to hash release archive [{$archivePath}].");
}

$requires = $manifest['requires'] ?? [];
$requires = is_array($requires) ? $requires : [];
$requires['modules'] = is_array($requires['modules'] ?? null) ? $requires['modules'] : [];

$releaseRequires = array_filter([
    'core' => $requires['core'] ?? null,
    'php' => $requires['php'] ?? null,
], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

if ($requires['modules'] !== []) {
    $releaseRequires['modules'] = $requires['modules'];
}

$release = [
    'version' => $version,
    'asset_url' => $assetUrl,
    'sha256' => $sha256,
    'released_at' => (string) $releasedAt,
    'requires' => $releaseRequires,
];
if ($sourceRef !== null) {
    $release['source_ref'] = $sourceRef;
}

$module = [
    'name' => $manifest['name'],
    'alias' => $manifest['alias'],
    'composer_package' => $packageName,
    'version' => $version,
    'description' => $manifest['description'],
    'author' => $manifest['author'],
    'license' => $manifest['license'],
    'requires' => $requires,
    'compatibility' => is_array($manifest['compatibility'] ?? null) ? $manifest['compatibility'] : [],
    'providers' => is_array($manifest['providers'] ?? null) ? $manifest['providers'] : [],
    'repository' => $repository,
    'homepage' => $manifest['homepage'],
    'versions' => [],
];
foreach (['permissions', 'capabilities'] as $field) {
    if (isset($manifest[$field])) {
        $module[$field] = $manifest[$field];
    }
}

foreach ($registry['modules'] ?? [] as $existingModule) {
    if (is_array($existingModule) && strcasecmp((string) ($existingModule['composer_package'] ?? ''), $packageName) === 0) {
        if (version_compare((string) $existingModule['version'], $version, '>')) {
            fail('Refuse to downgrade the current module version. Publish a new version for corrections.');
        }
        $module['versions'] = is_array($existingModule['versions'] ?? null) ? $existingModule['versions'] : [];
        break;
    }
}

foreach ($module['versions'] as $existingRelease) {
    if (($existingRelease['version'] ?? null) === $version) {
        if (($existingRelease['sha256'] ?? null) !== $sha256 || ($existingRelease['asset_url'] ?? null) !== $assetUrl
            || (isset($existingRelease['source_ref']) && $sourceRef !== null && $existingRelease['source_ref'] !== $sourceRef)) {
            fail("Release [{$packageName} {$version}] changed. Publish a new version instead of replacing an existing release.");
        }
        $sourceRef ??= $existingRelease['source_ref'] ?? null;
        $release['released_at'] = $existingRelease['released_at'];
        if ($sourceRef !== null) {
            $release['source_ref'] = $sourceRef;
        }
    }
}

$module['versions'] = array_values(array_filter(
    $module['versions'],
    static fn (mixed $existingRelease): bool => is_array($existingRelease) && (string) ($existingRelease['version'] ?? '') !== $version,
));
$module['versions'][] = $release;

usort($module['versions'], static fn (array $first, array $second): int => version_compare((string) $second['version'], (string) $first['version']));

$registry['modules'] = array_values(array_filter(
    $registry['modules'] ?? [],
    static fn (mixed $existingModule): bool => ! is_array($existingModule) || strcasecmp((string) ($existingModule['composer_package'] ?? ''), $packageName) !== 0,
));
$registry['modules'][] = $module;
usort($registry['modules'], static fn (array $first, array $second): int => strcasecmp((string) $first['name'], (string) $second['name']));
$registry['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');
unset($registry['signature'], $registry['packages_sha256']);

$packageMetadata = [
    'name' => $packageName,
    'version' => $version,
    'type' => $composer['type'] ?? 'library',
    'dist' => [
        'type' => 'zip',
        'url' => $assetUrl,
        'shasum' => $shasum,
    ],
    'source' => [
        'type' => 'git',
        'url' => "{$repository}.git",
        'reference' => $sourceRef ?? $tag,
    ],
    'require' => is_array($composer['require'] ?? null) ? $composer['require'] : [],
    'autoload' => is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [],
    'extra' => is_array($composer['extra'] ?? null) ? $composer['extra'] : [],
];
foreach (['description', 'license', 'authors', 'homepage', 'support', 'funding', 'suggest', 'conflict', 'replace', 'provide', 'bin'] as $field) {
    if (isset($composer[$field])) {
        $packageMetadata[$field] = $composer[$field];
    }
}

if (! is_array($packages['packages'] ?? null)) {
    $packages['packages'] = [];
}

if (! is_array($packages['packages'][$packageName] ?? null)) {
    $packages['packages'][$packageName] = [];
}

$packages['packages'][$packageName][$version] = $packageMetadata;
uksort($packages['packages'], 'strnatcasecmp');
uksort($packages['packages'][$packageName], static fn (string $first, string $second): int => version_compare($second, $first));

writeIndexes($registryPath, $registry, $packagesPath, $packages);

fwrite(STDOUT, "Updated {$packageName} {$version}. The registry signature was removed; sign registry.json before merging.\n");

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
    if (! is_string($value) || trim($value) === '') {
        fail("{$field} must be a non-empty string.");
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "Registry update failed: {$message}\n");
    exit(1);
}
