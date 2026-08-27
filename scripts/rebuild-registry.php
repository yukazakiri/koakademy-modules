<?php

declare(strict_types=1);

$configPath = realpath($argv[1] ?? __DIR__.'/../modules.json');

if ($configPath === false || ! is_file($configPath)) {
    fail("Module registry config [{$configPath}] does not exist.");
}

$config = readJson($configPath);
$modules = $config['modules'] ?? null;

if (! is_array($modules) || ! array_is_list($modules) || $modules === []) {
    fail('modules.json must contain a non-empty "modules" list.');
}

$registryPath = realpath(__DIR__.'/../registry.json');
$packagesPath = realpath(__DIR__.'/../packages.json');

if ($registryPath === false || $packagesPath === false) {
    fail('registry.json and packages.json must exist before rebuilding.');
}

$beforeRegistry = (string) file_get_contents($registryPath);
$beforePackages = (string) file_get_contents($packagesPath);
$beforeHash = meaningfulHash($registryPath, $packagesPath);

$tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'koakademy-registry-'.bin2hex(random_bytes(6));

if (! mkdir($tempRoot, 0770, true) && ! is_dir($tempRoot)) {
    fail("Unable to create temporary directory [{$tempRoot}].");
}

$rebuilt = [];

try {
    foreach ($modules as $index => $module) {
        if (! is_array($module) || ! is_string($module['repository'] ?? null)) {
            fail("modules.json entry [{$index}] must declare a repository string.");
        }

        $repository = rtrim((string) $module['repository'], '/');
        [$owner, $repo] = ownerAndRepo($repository);
        $release = latestRelease($owner, $repo);
        $tag = (string) $release['tag'];
        $version = preg_replace('/^v(?=\d)/i', '', $tag) ?? $tag;

        if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            fail("Repository [{$repository}] latest tag [{$tag}] is not a valid semantic version.");
        }

        $zipPath = $tempRoot.DIRECTORY_SEPARATOR.$repo.'-'.$tag.'.zip';
        $extractRoot = $tempRoot.DIRECTORY_SEPARATOR.$repo;

        download("{$repository}/archive/refs/tags/{$tag}.zip", $zipPath);
        extractZip($zipPath, $extractRoot);
        $modulePath = locateModulePath($extractRoot);
        normalizeModuleVersion($modulePath, $version);
        $releasedAt = is_string($release['released_at'] ?? null) ? $release['released_at'] : gmdate('Y-m-d\TH:i:s\Z');

        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(__DIR__.'/update-module.php')
            .' --module='.escapeshellarg($modulePath)
            .' --archive='.escapeshellarg($zipPath)
            .' --released-at='.escapeshellarg($releasedAt);

        passthru($command, $exitCode);

        if ($exitCode !== 0) {
            fail("update-module.php failed for [{$repository}] with exit code [{$exitCode}].");
        }

        $rebuilt[] = "{$owner}/{$repo} {$version} ({$tag})";
    }
} finally {
    removeDirectory($tempRoot);
}

if (meaningfulHash($registryPath, $packagesPath) === $beforeHash) {
    if (file_put_contents($registryPath, $beforeRegistry, LOCK_EX) === false
        || file_put_contents($packagesPath, $beforePackages, LOCK_EX) === false) {
        fail('Unable to restore the unchanged registry files.');
    }

    fwrite(STDOUT, "No changes. Registry is already up to date.".PHP_EOL);
} else {
    fwrite(STDOUT, 'Rebuilt '.count($rebuilt).' module(s):'.PHP_EOL);

    foreach ($rebuilt as $line) {
        fwrite(STDOUT, "  - {$line}".PHP_EOL);
    }
}

/**
 * @return array{tag: string, released_at: ?string}
 */
function latestRelease(string $owner, string $repo): array
{
    $tags = apiGet("/repos/{$owner}/{$repo}/tags");

    if (! is_array($tags)) {
        fail("Unable to list tags for [{$owner}/{$repo}].");
    }

    $versions = [];

    foreach ($tags as $tag) {
        $name = $tag['name'] ?? null;

        if (is_string($name) && preg_match('/^v?\d+\.\d+\.\d+$/', $name) === 1) {
            $versions[] = preg_replace('/^v/i', '', $name);
        }
    }

    if ($versions === []) {
        fail("Repository [{$owner}/{$repo}] has no semantic version tags.");
    }

    usort($versions, static fn (string $first, string $second): int => version_compare($second, $first));

    $tag = 'v'.$versions[0];

    return [
        'tag' => $tag,
        'released_at' => releasePublishedAt($owner, $repo, $tag) ?? tagCommitDate($owner, $repo, $tag),
    ];
}

function releasePublishedAt(string $owner, string $repo, string $tag): ?string
{
    $release = apiGet("/repos/{$owner}/{$repo}/releases/tags/{$tag}");

    if (! is_array($release) || ! is_string($release['published_at'] ?? null)) {
        return null;
    }

    return $release['published_at'];
}

function tagCommitDate(string $owner, string $repo, string $tag): ?string
{
    $commit = apiGet("/repos/{$owner}/{$repo}/commits/{$tag}");

    if (! is_array($commit)) {
        return null;
    }

    $date = $commit['commit']['committer']['date'] ?? $commit['commit']['author']['date'] ?? null;

    return is_string($date) ? $date : null;
}

/**
 * @return array<string, mixed>|null
 */
function apiGet(string $path): ?array
{
    $url = 'https://api.github.com'.$path;
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: koakademy-modules-registry',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $token = getenv('GITHUB_TOKEN');

    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer '.$token;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 30,
        ],
    ]);

    $contents = @file_get_contents($url, false, $context);
    $statusLine = (string) ($http_response_header[0] ?? '');

    if ($contents === false) {
        fail("Unable to reach GitHub API [{$path}].");
    }

    if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
        $status = (int) $matches[1];

        if ($status === 404) {
            return null;
        }

        if ($status === 403 || $status === 429) {
            fail("GitHub API rate limit or access denied for [{$path}].");
        }

        if ($status >= 400) {
            fail("GitHub API request [{$path}] failed with status [{$status}].");
        }
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array{0: string, 1: string}
 */
function ownerAndRepo(string $repository): array
{
    $repository = preg_replace('/\.git$/i', '', $repository) ?? $repository;
    $path = parse_url($repository, PHP_URL_PATH);

    if (! is_string($path)) {
        fail("Repository URL [{$repository}] must include an owner and repository.");
    }

    $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));

    if (count($parts) < 2) {
        fail("Repository URL [{$repository}] must include an owner and repository.");
    }

    return [$parts[count($parts) - 2], $parts[count($parts) - 1]];
}

function download(string $url, string $destination): void
{
    $contents = @file_get_contents($url);

    if ($contents === false || @file_put_contents($destination, $contents) === false) {
        fail("Unable to download release archive [{$url}].");
    }
}

function extractZip(string $zipPath, string $destination): void
{
    if (! mkdir($destination, 0770, true) && ! is_dir($destination)) {
        fail("Unable to create extraction directory [{$destination}].");
    }

    $zip = new \ZipArchive;

    if ($zip->open($zipPath) !== true) {
        fail("Unable to open release archive [{$zipPath}].");
    }

    if (! $zip->extractTo($destination)) {
        $zip->close();
        fail("Unable to extract release archive [{$zipPath}].");
    }

    $zip->close();
}

function locateModulePath(string $root): string
{
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'module.json') {
            $directory = $file->getPath();

            if (is_file($directory.DIRECTORY_SEPARATOR.'composer.json')) {
                return $directory;
            }
        }
    }

    fail("Extracted archive [{$root}] does not contain module.json and composer.json.");
}
function normalizeModuleVersion(string $modulePath, string $version): void
{
    $manifestPath = $modulePath.DIRECTORY_SEPARATOR.'module.json';

    if (! is_file($manifestPath)) {
        fail("Module manifest [{$manifestPath}] does not exist.");
    }

    $manifest = readJson($manifestPath);
    $manifest['version'] = $version;

    $contents = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

    if (file_put_contents($manifestPath, $contents, LOCK_EX) === false) {
        fail("Unable to write module manifest [{$manifestPath}].");
    }
}



function removeDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

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

function meaningfulHash(string $registryPath, string $packagesPath): string
{
    $registry = readJson($registryPath);
    unset($registry['generated_at'], $registry['signature']);

    $payload = json_encode(
        ['registry' => $registry, 'packages' => readJson($packagesPath)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
    );

    return hash('sha256', (string) $payload);
}

function fail(string $message): never
{
    fwrite(STDERR, "Registry rebuild failed: {$message}\n");
    exit(1);
}

