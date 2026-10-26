<?php

declare(strict_types=1);

use function Koakademy\Registry\archiveMetadata;
use function Koakademy\Registry\repository;
use function Koakademy\Registry\writeIndexes;

require_once __DIR__.'/lib/metadata.php';

/** Callbacks let the regression suite replay GitHub responses without network access. */
function rebuild(array $argv, ?Closure $fetch = null, ?Closure $downloadArchive = null): void
{
    $configPath = realpath($argv[1] ?? __DIR__.'/../modules.json');

    if ($configPath === false || ! is_file($configPath)) {
        fail("Module registry config [{$configPath}] does not exist.");
    }

    $config = readJson($configPath);
    $modules = $config['modules'] ?? null;

    if (($config['schema'] ?? null) !== 1 || ! is_array($modules) || ! array_is_list($modules) || $modules === []) {
        fail('modules.json must contain a non-empty "modules" list.');
    }

    $registryPath = realpath($argv[2] ?? __DIR__.'/../registry.json');
    $packagesPath = realpath($argv[3] ?? __DIR__.'/../packages.json');

    if ($registryPath === false || $packagesPath === false) {
        fail('registry.json and packages.json must exist before rebuilding.');
    }

    $beforeHash = meaningfulHash($registryPath, $packagesPath);

    $tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'koakademy-registry-'.bin2hex(random_bytes(6));

    if (! mkdir($tempRoot, 0770, true) && ! is_dir($tempRoot)) {
        fail("Unable to create temporary directory [{$tempRoot}].");
    }

    $rebuilt = [];
    $repositories = [];
    $stagedRegistry = $tempRoot.'/registry.json';
    $stagedPackages = $tempRoot.'/packages.json';
    if (! copy($registryPath, $stagedRegistry) || ! copy($packagesPath, $stagedPackages)) {
        removeDirectory($tempRoot);
        fail('Unable to stage the current indexes.');
    }

    try {
        foreach ($modules as $index => $module) {
            if (! is_array($module) || ! is_string($module['repository'] ?? null)) {
                fail("modules.json entry [{$index}] must declare a repository string.");
            }

            $repository = repository($module['repository']);
            if (isset($repositories[strtolower($repository)])) {
                fail("Duplicate repository [{$repository}] in modules.json.");
            }
            $repositories[strtolower($repository)] = true;
            [$owner, $repo] = ownerAndRepo($repository);
            $release = latestRelease($owner, $repo, $fetch);
            $tag = (string) $release['tag'];
            $version = preg_replace('/^v(?=\d)/i', '', $tag) ?? $tag;

            foreach (readJson($stagedRegistry)['modules'] as $existing) {
                if (repository($existing['repository']) !== $repository) {
                    continue;
                }
                if (version_compare($existing['version'], $version, '>')) {
                    fail('Refuse to downgrade a catalog entry because a newer upstream tag disappeared.');
                }
                foreach ($existing['versions'] as $known) {
                    if ($known['version'] !== $version) {
                        continue;
                    }
                    if (isset($known['source_ref']) && $known['source_ref'] !== $release['source_ref']) {
                        fail("Release tag [{$tag}] moved to another commit. Publish a new version.");
                    }
                    // Historical tag archives retain their reviewed metadata and checksums.
                    // New releases are downloaded and recorded using the immutable commit.
                    continue 3;
                }
            }

            if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
                fail("Repository [{$repository}] latest tag [{$tag}] is not a valid semantic version.");
            }

            $zipPath = $tempRoot.DIRECTORY_SEPARATOR.$repo.'-'.$tag.'.zip';
            ($downloadArchive ?? download(...))("{$repository}/archive/{$release['source_ref']}.zip", $zipPath);
            [$manifest, $composer] = archiveMetadata($zipPath);
            if (($manifest['version'] ?? null) !== $version || repository($manifest['repository'] ?? null) !== $repository) {
                fail("Repository [{$repository}] archive identity/version does not match tag [{$tag}]. Fix the source and publish a new tag.");
            }
            $modulePath = $tempRoot.'/module-'.$index;
            if (! mkdir($modulePath, 0770)) {
                fail('Unable to stage module metadata.');
            }
            foreach (['module.json' => $manifest, 'composer.json' => $composer] as $file => $data) {
                if (file_put_contents($modulePath.'/'.$file, json_encode($data, JSON_THROW_ON_ERROR)) === false) {
                    fail('Unable to write staged module metadata.');
                }
            }
            $releasedAt = is_string($release['released_at'] ?? null) ? $release['released_at'] : gmdate('Y-m-d\TH:i:s\Z');

            $command = escapeshellarg(PHP_BINARY)
                .' '.escapeshellarg(__DIR__.'/update-module.php')
                .' --module='.escapeshellarg($modulePath)
                .' --archive='.escapeshellarg($zipPath)
                .' --released-at='.escapeshellarg($releasedAt)
                .' --tag='.escapeshellarg($tag)
                .' --source-ref='.escapeshellarg($release['source_ref'])
                .' --registry='.escapeshellarg($stagedRegistry)
                .' --packages='.escapeshellarg($stagedPackages);

            passthru($command, $exitCode);

            if ($exitCode !== 0) {
                fail("update-module.php failed for [{$repository}] with exit code [{$exitCode}].");
            }

            $rebuilt[] = "{$owner}/{$repo} {$version} ({$tag})";
        }
        if (meaningfulHash($stagedRegistry, $stagedPackages) === $beforeHash) {
            fwrite(STDOUT, 'No changes. Registry is already up to date.'.PHP_EOL);
        } else {
            writeIndexes($registryPath, readJson($stagedRegistry), $packagesPath, readJson($stagedPackages));
            fwrite(STDOUT, 'Rebuilt '.count($rebuilt).' module(s):'.PHP_EOL);
            foreach ($rebuilt as $line) {
                fwrite(STDOUT, "  - {$line}".PHP_EOL);
            }
        }
    } finally {
        removeDirectory($tempRoot);
    }
}

/**
 * @return array{tag: string, source_ref: string, released_at: ?string}
 */
function latestRelease(string $owner, string $repo, ?Closure $fetch = null): array
{
    $fetch ??= apiGet(...);
    $versions = [];
    for ($page = 1; $page <= 100; $page++) {
        $tags = $fetch("/repos/{$owner}/{$repo}/tags?per_page=100&page={$page}");
        if (! is_array($tags) || ! array_is_list($tags)) {
            fail("Unable to list tags for [{$owner}/{$repo}].");
        }
        foreach ($tags as $tag) {
            $name = $tag['name'] ?? null;
            if (is_string($name) && preg_match('/^v?\d+\.\d+\.\d+$/D', $name) === 1) {
                $versions[] = $name;
            }
        }
        if (count($tags) < 100) {
            break;
        }
        if ($page === 100) {
            fail('Tag listing exceeded 10,000 entries; refuse an incomplete release selection.');
        }
    }

    if ($versions === []) {
        fail("Repository [{$owner}/{$repo}] has no semantic version tags.");
    }

    usort($versions, static fn (string $first, string $second): int => version_compare(ltrim($second, 'v'), ltrim($first, 'v')) ?: strcmp($second, $first));
    $tag = $versions[0];
    $commit = $fetch("/repos/{$owner}/{$repo}/commits/{$tag}");
    if (! is_string($commit['sha'] ?? null) || ! preg_match('/^[a-f0-9]{40}$/D', $commit['sha'])) {
        fail('The selected release could not be resolved to an immutable commit.');
    }

    return [
        'tag' => $tag,
        'source_ref' => $commit['sha'],
        'released_at' => ($fetch("/repos/{$owner}/{$repo}/releases/tags/{$tag}")['published_at'] ?? null)
            ?? $commit['commit']['committer']['date'] ?? $commit['commit']['author']['date'] ?? null,
    ];
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
    $contents = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'koakademy-modules-registry']]));

    if ($contents === false || @file_put_contents($destination, $contents) === false) {
        fail("Unable to download release archive [{$url}].");
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
    unset($registry['generated_at'], $registry['signature'], $registry['packages_sha256']);

    $payload = json_encode(
        ['registry' => $registry, 'packages' => readJson($packagesPath)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
    );

    return hash('sha256', (string) $payload);
}

function fail(string $message): never
{
    throw new RuntimeException("Registry rebuild failed: {$message}");
}


if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        rebuild($argv);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
        exit(1);
    }
}
