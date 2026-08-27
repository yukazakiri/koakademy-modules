<?php

declare(strict_types=1);

$options = getopt('', ['module:', 'archive:', 'released-at::']);
$modulePath = $options['module'] ?? null;
$archivePath = $options['archive'] ?? null;
$releasedAt = $options['released-at'] ?? gmdate('Y-m-d\TH:i:s\Z');
$registryPath = __DIR__.'/../registry.json';
$packagesPath = __DIR__.'/../packages.json';

if (! is_string($modulePath) || ! is_string($archivePath)) {
    fail('Usage: php scripts/update-module.php --module=/path/to/module --archive=/path/to/release.zip [--released-at=YYYY-MM-DDTHH:MM:SSZ]');
}

$modulePath = realpath($modulePath) ?: $modulePath;
$archivePath = realpath($archivePath) ?: $archivePath;
$manifest = readJson($modulePath.'/module.json');
$composer = readJson($modulePath.'/composer.json');
$registry = readJson(__DIR__.'/../registry.json');
$packages = readJson(__DIR__.'/../packages.json');

if (! is_file($archivePath)) {
    fail("Release archive [{$archivePath}] does not exist.");
}

foreach (['name', 'alias', 'composer_package', 'version', 'description', 'author', 'license', 'repository', 'homepage'] as $field) {
    requireString($manifest[$field] ?? null, "module.json field [{$field}]");
}

$packageName = (string) $manifest['composer_package'];
$version = (string) $manifest['version'];
$repository = rtrim((string) $manifest['repository'], '/');
$repository = preg_replace('/\.git$/i', '', $repository) ?: $repository;
$assetUrl = "{$repository}/archive/refs/tags/v{$version}.zip";
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
], static fn (mixed $value): bool => is_string($value) && mb_trim($value) !== '');

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

foreach ($registry['modules'] ?? [] as $existingModule) {
    if (is_array($existingModule) && strcasecmp((string) ($existingModule['composer_package'] ?? ''), $packageName) === 0) {
        $module['versions'] = is_array($existingModule['versions'] ?? null) ? $existingModule['versions'] : [];
        break;
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
unset($registry['signature']);

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
        'reference' => "v{$version}",
    ],
    'require' => is_array($composer['require'] ?? null) ? $composer['require'] : [],
    'autoload' => is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [],
    'extra' => is_array($composer['extra'] ?? null) ? $composer['extra'] : [],
];

if (! is_array($packages['packages'] ?? null)) {
    $packages['packages'] = [];
}

if (! is_array($packages['packages'][$packageName] ?? null)) {
    $packages['packages'][$packageName] = [];
}

$packages['packages'][$packageName][$version] = $packageMetadata;
uksort($packages['packages'], 'strnatcasecmp');
uksort($packages['packages'][$packageName], static fn (string $first, string $second): int => version_compare($second, $first));

writeJson(__DIR__.'/../registry.json', $registry);
writeJson(__DIR__.'/../packages.json', $packages);

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

function writeJson(string $path, array $payload): void
{
    $contents = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        fail("Unable to write JSON file [{$path}].");
    }
}

function requireString(mixed $value, string $field): void
{
    if (! is_string($value) || mb_trim($value) === '') {
        fail("{$field} must be a non-empty string.");
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "Registry update failed: {$message}\n");
    exit(1);
}
