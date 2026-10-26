<?php

declare(strict_types=1);

namespace Koakademy\Registry;

use Composer\Semver\VersionParser;
use DateTimeImmutable;
use RuntimeException;
use ZipArchive;

require_once __DIR__.'/../../vendor/autoload.php';

function requireString(mixed $value, string $field): string
{
    if (! is_string($value) || trim($value) === '') {
        throw new RuntimeException("{$field} must be a non-empty string.");
    }

    return $value;
}

function version(mixed $value): string
{
    $value = requireString($value, 'Version');
    if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $value)) {
        throw new RuntimeException("Version [{$value}] must use semantic versioning.");
    }
    (new VersionParser)->normalize($value);

    return $value;
}

function constraint(mixed $value): void
{
    (new VersionParser)->parseConstraints(requireString($value, 'Version constraint'));
}

function requirements(mixed $value): void
{
    if (! is_array($value)) {
        throw new RuntimeException('Requirements must be an object.');
    }
    foreach ($value as $name => $requirement) {
        if ($name === 'modules') {
            if (! is_array($requirement)) {
                throw new RuntimeException('Requirements.modules must be an object.');
            }
            foreach ($requirement as $module => $range) {
                if (! is_string($module) || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $module)) {
                    throw new RuntimeException('Module dependencies must use valid module identifiers.');
                }
                constraint($range);
            }
        } elseif ($name === 'core' || $name === 'php') {
            constraint($requirement);
        } else {
            throw new RuntimeException("Unknown module requirement [{$name}].");
        }
    }
}

function httpsUrl(mixed $value): string
{
    $value = requireString($value, 'URL');
    if (! filter_var($value, FILTER_VALIDATE_URL) || parse_url($value, PHP_URL_SCHEME) !== 'https'
        || parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
        throw new RuntimeException('Release and repository URLs must use HTTPS without credentials.');
    }

    return $value;
}

function repository(mixed $value): string
{
    $value = preg_replace('/\.git$/i', '', rtrim(httpsUrl($value), '/'));
    if (! is_string($value) || ! preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $value)) {
        throw new RuntimeException('Module repositories must be GitHub owner/repository HTTPS URLs.');
    }

    return $value;
}

function stringList(mixed $value, string $field, bool $required = false): array
{
    if (! is_array($value) || ! array_is_list($value) || ($required && $value === [])) {
        throw new RuntimeException("{$field} must be a list of strings".($required ? ' with at least one item.' : '.'));
    }
    foreach ($value as $item) {
        requireString($item, $field);
    }

    return $value;
}

function manifest(array $value): void
{
    foreach (['name', 'alias', 'composer_package', 'version', 'description', 'author', 'license', 'repository', 'homepage'] as $field) {
        requireString($value[$field] ?? null, "Module field [{$field}]");
    }
    if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $value['name'])
        || ! preg_match('/^[a-z0-9][a-z0-9-]*$/D', $value['alias'])
        || ! preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $value['composer_package'])) {
        throw new RuntimeException('Module name, alias, or Composer identity is invalid.');
    }
    version($value['version']);
    repository($value['repository']);
    httpsUrl($value['homepage']);
    requirements($value['requires'] ?? []);
    if (! is_array($value['compatibility'] ?? [])) {
        throw new RuntimeException('Compatibility must be an object of constraints.');
    }
    foreach ($value['compatibility'] ?? [] as $key => $range) {
        requireString($key, 'Compatibility key');
        constraint($range);
    }
    foreach (stringList($value['providers'] ?? null, 'Providers', true) as $provider) {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $provider)) {
            throw new RuntimeException('Providers must be fully qualified PHP class names.');
        }
    }
    stringList($value['permissions'] ?? [], 'Permissions');
    if (! is_array($value['capabilities'] ?? []) || ! array_is_list($value['capabilities'] ?? [])) {
        throw new RuntimeException('Capabilities must be a list.');
    }
    $capabilities = [];
    foreach ($value['capabilities'] ?? [] as $capability) {
        if (! is_array($capability) || ! is_string($capability['key'] ?? null)
            || ! preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $capability['key'])
            || strlen($capability['key']) > 120 || isset($capabilities[$capability['key']])) {
            throw new RuntimeException('Capabilities must declare unique valid keys.');
        }
        foreach (['name', 'description', 'permission'] as $field) {
            requireString($capability[$field] ?? null, "Capability {$field}");
        }
        foreach (['entry_route', 'feature'] as $field) {
            if (isset($capability[$field])) {
                requireString($capability[$field], "Capability {$field}");
            }
        }
        $capabilities[$capability['key']] = true;
    }
}

function releasedAt(mixed $value): void
{
    $value = requireString($value, 'Release timestamp');
    if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        throw new RuntimeException('Release timestamps must use RFC 3339.');
    }
    new DateTimeImmutable($value);
    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
        throw new RuntimeException('Release timestamp contains an impossible date.');
    }
}

/** Read metadata without extracting or executing any code from the archive. */
function archiveMetadata(string $path): array
{
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open release ZIP.');
    }
    try {
        $roots = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (! is_string($name) || str_contains($name, '\\') || str_starts_with($name, '/')
                || in_array('..', explode('/', $name), true)) {
                throw new RuntimeException('Release ZIP contains an unsafe path.');
            }
            if (preg_match('~^(?:[^/]+/)?module\.json$~D', $name)) {
                $roots[] = substr($name, 0, -strlen('module.json'));
            }
        }
        if (count($roots) !== 1) {
            throw new RuntimeException('Release ZIP must contain exactly one root module.json.');
        }
        $metadata = [];
        foreach (['module.json', 'composer.json'] as $file) {
            $entry = $roots[0].$file;
            $stat = $zip->statName($entry);
            if ($stat === false || $stat['size'] > 1048576) {
                throw new RuntimeException("Archive [{$file}] is missing or exceeds 1 MB.");
            }
            $data = json_decode((string) $zip->getFromName($entry), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new RuntimeException("Archive [{$file}] must contain a JSON object.");
            }
            $metadata[] = $data;
        }

        return $metadata;
    } finally {
        $zip->close();
    }
}

/** Validate both proposed files before replacing either committed index. */
function writeIndexes(string $registryPath, array $registry, string $packagesPath, array $packages): void
{
    if (realpath($registryPath) === realpath($packagesPath)) {
        throw new RuntimeException('Registry and Composer outputs must be different existing files.');
    }
    $paths = [$registryPath, $packagesPath];
    $payloads = [$registry, $packages];
    $temporary = [];
    $before = [];
    try {
        foreach ($paths as $index => $path) {
            $before[$index] = (string) file_get_contents($path);
            $temp = tempnam(dirname($path), '.registry-');
            if ($temp === false) {
                throw new RuntimeException('Unable to prepare registry output files.');
            }
            $temporary[] = $temp;
            $contents = json_encode($payloads[$index], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($temp, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Unable to write complete registry output.');
            }
            chmod($temp, 0644);
        }
        $process = proc_open([PHP_BINARY, __DIR__.'/../validate-registry.php', ...$temporary], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to validate proposed registry indexes.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Proposed registry indexes failed validation: '.$output);
        }
        foreach ($paths as $index => $path) {
            if (! rename($temporary[$index], $path)) {
                // Two paths cannot be renamed as one filesystem operation.
                foreach (array_slice($paths, 0, $index) as $restored => $previous) {
                    if (file_put_contents($previous, $before[$restored], LOCK_EX) === false) {
                        throw new RuntimeException('Registry output and rollback failed; restore both indexes from Git.');
                    }
                }
                throw new RuntimeException('Unable to publish proposed indexes; previous files were restored.');
            }
        }
    } finally {
        foreach ($temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
