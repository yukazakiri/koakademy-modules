<?php

declare(strict_types=1);

use function Koakademy\Registry\archiveMetadata;

require_once __DIR__.'/../scripts/rebuild-registry.php';

$root = dirname(__DIR__);
$temporary = [];
$tests = [];
$assertions = 0;

function must(bool $condition, string $message): void
{
    $GLOBALS['assertions']++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function workspace(bool $published = false): string
{
    $path = sys_get_temp_dir().'/koakademy-registry-test-'.bin2hex(random_bytes(8));
    mkdir($path, 0700);
    $GLOBALS['temporary'][] = $path;
    if ($published) {
        copy($GLOBALS['root'].'/registry.json', $path.'/registry.json');
        copy($GLOBALS['root'].'/packages.json', $path.'/packages.json');
    } else {
        jsonFile($path.'/registry.json', ['schema' => 1, 'generated_at' => '2026-10-26T00:00:00Z', 'modules' => []]);
        jsonFile($path.'/packages.json', ['packages' => new stdClass]);
    }

    return $path;
}

function jsonFile(string $path, mixed $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
}

function execute(string $script, array $arguments = []): array
{
    $process = proc_open([PHP_BINARY, $GLOBALS['root'].'/scripts/'.$script, ...$arguments], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start PHP test process.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

function fixture(string $directory, string $name = 'Notes'): array
{
    $modulePath = $directory.'/'.$name;
    mkdir($modulePath, 0700);
    $manifest = [
        'name' => $name, 'alias' => strtolower($name), 'composer_package' => 'acme/'.strtolower($name),
        'version' => '1.0.0', 'description' => 'A test extension.', 'author' => 'Fixture contributor',
        'license' => 'AGPL-3.0-or-later', 'repository' => 'https://github.com/acme/'.strtolower($name),
        'homepage' => 'https://github.com/acme/'.strtolower($name),
        'providers' => ["Acme\\{$name}\\Providers\\{$name}ServiceProvider"],
        'requires' => ['core' => '^1.29', 'php' => '^8.5', 'modules' => ['LibrarySystem' => '^1.0 || ^2.0']],
        'compatibility' => ['laravel' => '^13.0', 'filament' => '^5.0'],
        'permissions' => ['notes.view'],
        'capabilities' => [['key' => 'enterprise.notes', 'name' => 'Notes', 'description' => 'Fixture notes.', 'permission' => 'notes.view']],
    ];
    $composer = [
        'name' => $manifest['composer_package'], 'type' => 'library', 'license' => $manifest['license'],
        'description' => $manifest['description'], 'require' => ['php' => '^8.5'],
        'autoload' => ['psr-4' => ["Acme\\{$name}\\" => 'app/']],
        'extra' => ['laravel' => ['providers' => $manifest['providers']]],
    ];
    jsonFile($modulePath.'/module.json', $manifest);
    jsonFile($modulePath.'/composer.json', $composer);
    $archive = $directory.'/'.$name.'.zip';
    packFixture($modulePath, $archive);

    return [$modulePath, $archive];
}

function packFixture(string $modulePath, string $archive, string $content = 'fixture source'): void
{
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (['module.json', 'composer.json'] as $file) {
        $zip->addFile($modulePath.'/'.$file, 'release/'.$file);
        $zip->setMtimeName('release/'.$file, 1700000000);
    }
    $zip->addFromString('release/app/source.txt', $content);
    $zip->setMtimeName('release/app/source.txt', 1700000000);
    $zip->close();
}

function update(string $directory, array $fixture, array $extra = []): array
{
    return execute('update-module.php', [
        '--module='.$fixture[0], '--archive='.$fixture[1],
        '--registry='.$directory.'/registry.json', '--packages='.$directory.'/packages.json',
        '--released-at=2026-10-26T00:00:00Z', ...$extra,
    ]);
}

function indexBytes(string $directory): array
{
    return [file_get_contents($directory.'/registry.json'), file_get_contents($directory.'/packages.json')];
}

function signingKeys(string $directory): void
{
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($directory.'/private.key', base64_encode(sodium_crypto_sign_secretkey($pair)));
    chmod($directory.'/private.key', 0600);
    file_put_contents($directory.'/public.key', base64_encode(sodium_crypto_sign_publickey($pair)));
    sodium_memzero($pair);
}

function fixtureApi(string $path): ?array
{
    if (str_contains($path, '/tags?')) {
        return [['name' => 'v1.0.0']];
    }
    if (str_contains($path, '/commits/')) {
        return ['sha' => str_repeat('a', 40), 'commit' => ['committer' => ['date' => '2026-10-26T00:00:00Z']]];
    }
    if (str_contains($path, '/releases/tags/')) {
        return null;
    }
    throw new RuntimeException('Unexpected GitHub request in offline fixture: '.$path);
}

$tests['published catalog still validates and verifies without changing its trust root'] = function (): void {
    [$status, $output] = execute('validate-registry.php');
    must($status === 0, $output);
    [$status, $output] = execute('verify-registry.php');
    must($status === 0, $output);
};

$invalid = [
    'cross-name alias collision' => [function (&$r, &$p): void { $r['modules'][1]['alias'] = strtolower($r['modules'][0]['name']); }, 'identity'],
    'invalid module identity' => [function (&$r, &$p): void { $r['modules'][0]['name'] = '../Notes'; }, 'identity'],
    'invalid package identity' => [function (&$r, &$p): void { $r['modules'][0]['composer_package'] = 'NOT-A-PACKAGE'; }, 'identity'],
    'unstructured module dependency constraints' => [function (&$r, &$p): void { $r['modules'][0]['requires']['modules'] = ['Library' => 123]; }, 'constraint'],
    'unknown requirement' => [function (&$r, &$p): void { $r['modules'][0]['requires']['runtime'] = '*'; }, 'Unknown'],
    'invalid Composer range' => [function (&$r, &$p): void { $r['modules'][0]['requires']['core'] = 'not-a-version'; }, 'constraint'],
    'unsafe release version' => [function (&$r, &$p): void { $r['modules'][0]['versions'][0]['version'] = '--prefer-source'; }, 'semantic'],
    'HTTP release URL' => [function (&$r, &$p): void { $r['modules'][0]['versions'][0]['asset_url'] = 'http://example.test/release.zip'; }, 'HTTPS'],
    'non-GitHub repository' => [function (&$r, &$p): void { $r['modules'][0]['repository'] = 'https://example.test/acme/notes'; }, 'GitHub'],
    'malformed provider' => [function (&$r, &$p): void { $r['modules'][0]['providers'] = ['not a PHP class']; }, 'class'],
    'impossible release date' => [function (&$r, &$p): void { $r['modules'][0]['versions'][0]['released_at'] = '2026-02-30T00:00:00Z'; }, 'date'],
    'missing reverse Composer version' => [function (&$r, &$p): void { unset($p['packages']['koakademy/announcement']['1.0.1']); }, 'no Composer'],
    'incorrect Composer source' => [function (&$r, &$p): void { $p['packages']['koakademy/announcement']['1.0.1']['source']['url'] = 'https://github.com/attacker/other.git'; }, 'source'],
    'missing Laravel provider discovery' => [function (&$r, &$p): void { $p['packages']['koakademy/announcement']['1.0.1']['extra']['laravel']['providers'] = []; }, 'Providers'],
];
foreach ($invalid as $label => [$mutate, $message]) {
    $tests['rejects '.$label] = function () use ($mutate, $message): void {
        $directory = workspace(true);
        $registry = readJson($directory.'/registry.json');
        $packages = readJson($directory.'/packages.json');
        $mutate($registry, $packages);
        jsonFile($directory.'/registry.json', $registry);
        jsonFile($directory.'/packages.json', $packages);
        [$status, $output] = execute('validate-registry.php', [$directory.'/registry.json', $directory.'/packages.json']);
        must($status !== 0 && stripos($output, $message) !== false, 'Unexpected validation result: '.$output);
    };
}

$tests['updater preserves dependency maps, capability metadata, license and immutable source reference'] = function (): void {
    $directory = workspace();
    [$status, $output] = update($directory, fixture($directory), ['--source-ref='.str_repeat('a', 40)]);
    must($status === 0, $output);
    $module = readJson($directory.'/registry.json')['modules'][0];
    $package = readJson($directory.'/packages.json')['packages']['acme/notes']['1.0.0'];
    must($module['versions'][0]['requires']['modules']['LibrarySystem'] === '^1.0 || ^2.0', 'Module requirements lost.');
    must($module['permissions'] === ['notes.view'] && $module['capabilities'][0]['key'] === 'enterprise.notes', 'Access metadata lost.');
    must($package['license'] === 'AGPL-3.0-or-later' && $package['source']['reference'] === str_repeat('a', 40), 'Composer metadata lost.');
};

$tests['updater rejects changed release bytes without changing either index'] = function (): void {
    $directory = workspace();
    $fixture = fixture($directory);
    [$status, $output] = update($directory, $fixture);
    must($status === 0, $output);
    $before = indexBytes($directory);
    packFixture($fixture[0], $fixture[1], 'moved release source');
    [$status, $output] = update($directory, $fixture);
    must($status !== 0 && str_contains($output, 'Publish a new version'), $output);
    must(indexBytes($directory) === $before, 'A rejected release changed the indexes.');
};

$tests['updater checks archive contents against the supplied source metadata'] = function (): void {
    $directory = workspace();
    $fixture = fixture($directory);
    $before = indexBytes($directory);
    $manifest = readJson($fixture[0].'/module.json');
    $manifest['description'] = 'Different source checkout';
    jsonFile($fixture[0].'/module.json', $manifest);
    [$status, $output] = update($directory, $fixture);
    must($status !== 0 && str_contains($output, 'differs from the release archive'), $output);
    must(indexBytes($directory) === $before, 'Mismatched metadata changed the indexes.');
};

$tests['updater validates Composer identity before writing'] = function (): void {
    $directory = workspace();
    $fixture = fixture($directory);
    $composer = readJson($fixture[0].'/composer.json');
    $composer['name'] = 'other/package';
    jsonFile($fixture[0].'/composer.json', $composer);
    packFixture(...$fixture);
    [$status, $output] = update($directory, $fixture);
    must($status !== 0 && str_contains($output, 'Composer name/version'), $output);
};

$tests['failed final validation leaves both indexes unchanged'] = function (): void {
    $directory = workspace();
    $fixture = fixture($directory);
    $before = indexBytes($directory);
    $composer = readJson($fixture[0].'/composer.json');
    $composer['extra']['laravel']['providers'] = ['Acme\\Other\\OtherProvider'];
    jsonFile($fixture[0].'/composer.json', $composer);
    packFixture(...$fixture);
    [$status, $output] = update($directory, $fixture);
    must($status !== 0 && str_contains($output, 'failed validation'), $output);
    must(indexBytes($directory) === $before, 'Partial output escaped failed validation.');
};

$tests['ZIP metadata reader rejects traversal and ambiguous roots without extracting source'] = function (): void {
    foreach (['../escape.php', 'another/module.json'] as $unsafe) {
        $directory = workspace();
        $fixture = fixture($directory);
        $zip = new ZipArchive;
        $zip->open($fixture[1]);
        $zip->addFromString($unsafe, '{}');
        $zip->close();
        try {
            archiveMetadata($fixture[1]);
            throw new LogicException('Unsafe archive was accepted.');
        } catch (RuntimeException $exception) {
            must(str_contains($exception->getMessage(), 'unsafe path') || str_contains($exception->getMessage(), 'exactly one'), $exception->getMessage());
        }
    }
};

$tests['release discovery checks later tag pages and retains bare tag names'] = function (): void {
    $paths = [];
    $release = latestRelease('acme', 'notes', function ($path) use (&$paths): ?array {
        $paths[] = $path;
        if (str_contains($path, '&page=1')) {
            return array_map(fn ($number) => ['name' => 'v1.0.'.$number], range(0, 99));
        }
        if (str_contains($path, '&page=2')) {
            return [['name' => '4.0.0'], ['name' => 'v5.0.0-beta.1']];
        }
        return fixtureApi($path);
    });
    must($release['tag'] === '4.0.0' && $release['source_ref'] === str_repeat('a', 40), 'Selected the wrong release or invented a tag.');
    must(count($paths) === 4, 'Expected two tag pages, commit lookup, and optional release lookup.');
};

$tests['a later rebuild failure preserves the complete committed catalog'] = function (): void {
    $directory = workspace();
    $first = fixture($directory, 'First');
    $second = fixture($directory, 'Second');
    $manifest = readJson($second[0].'/module.json');
    $manifest['version'] = '2.0.0';
    jsonFile($second[0].'/module.json', $manifest);
    packFixture(...$second);
    jsonFile($directory.'/modules.json', ['schema' => 1, 'modules' => [['repository' => 'https://github.com/acme/first'], ['repository' => 'https://github.com/acme/second']]]);
    $before = indexBytes($directory);
    try {
        rebuild(['rebuild', $directory.'/modules.json', $directory.'/registry.json', $directory.'/packages.json'], fixtureApi(...), function ($url, $output) use ($first, $second): void {
            copy(str_contains($url, '/first/') ? $first[1] : $second[1], $output);
        });
        throw new LogicException('Mismatched release was accepted.');
    } catch (RuntimeException $exception) {
        must(str_contains($exception->getMessage(), 'identity/version'), $exception->getMessage());
    }
    must(indexBytes($directory) === $before, 'A partial rebuild replaced the committed catalog.');
};

$tests['a no-op rebuild preserves the signed catalog byte for byte'] = function (): void {
    $directory = workspace();
    $fixture = fixture($directory);
    jsonFile($directory.'/modules.json', ['schema' => 1, 'modules' => [['repository' => 'https://github.com/acme/notes']]]);
    $arguments = ['rebuild', $directory.'/modules.json', $directory.'/registry.json', $directory.'/packages.json'];
    $download = function ($url, $output) use ($fixture): void { copy($fixture[1], $output); };
    rebuild($arguments, fixtureApi(...), $download);
    signingKeys($directory);
    [$status, $output] = execute('sign-registry.php', [$directory.'/registry.json', $directory.'/private.key', $directory.'/registry.json']);
    must($status === 0, $output);
    $before = indexBytes($directory);
    rebuild($arguments, fixtureApi(...), $download);
    must(indexBytes($directory) === $before, 'No-op rebuild removed the existing signature or changed generated_at.');
    [$status, $output] = execute('verify-registry.php', [$directory.'/registry.json', $directory.'/public.key']);
    must($status === 0, $output);
};

$tests['rebuild rejects a moved tag for an existing pinned release'] = function (): void {
    $directory = workspace();
    $fixture = fixture($directory);
    [$status, $output] = update($directory, $fixture, ['--source-ref='.str_repeat('b', 40)]);
    must($status === 0, $output);
    jsonFile($directory.'/modules.json', ['schema' => 1, 'modules' => [['repository' => 'https://github.com/acme/notes']]]);
    $before = indexBytes($directory);
    try {
        rebuild(['rebuild', $directory.'/modules.json', $directory.'/registry.json', $directory.'/packages.json'], fixtureApi(...), function (): void { throw new LogicException('A moved tag should fail before downloading.'); });
        throw new LogicException('A moved tag was accepted.');
    } catch (RuntimeException $exception) {
        must(str_contains($exception->getMessage(), 'moved'), $exception->getMessage());
    }
    must(indexBytes($directory) === $before, 'A moved tag changed the published indexes.');
};

$tests['updater refuses to downgrade the current module version'] = function (): void {
    $directory = workspace();
    $fixture = fixture($directory);
    $manifest = readJson($fixture[0].'/module.json');
    $manifest['version'] = '2.0.0';
    jsonFile($fixture[0].'/module.json', $manifest);
    packFixture(...$fixture);
    [$status, $output] = update($directory, $fixture);
    must($status === 0, $output);
    $before = indexBytes($directory);
    $manifest['version'] = '1.0.0';
    jsonFile($fixture[0].'/module.json', $manifest);
    packFixture(...$fixture);
    [$status, $output] = update($directory, $fixture);
    must($status !== 0 && str_contains($output, 'downgrade'), $output);
    must(indexBytes($directory) === $before, 'A downgrade changed the indexes.');
};

$tests['new signatures bind the Composer index and reject tampering'] = function (): void {
    $directory = workspace();
    signingKeys($directory);
    [$status, $output] = execute('sign-registry.php', [$directory.'/registry.json', $directory.'/private.key', $directory.'/registry.json']);
    must($status === 0, $output);
    [$status, $output] = execute('verify-registry.php', [$directory.'/registry.json', $directory.'/public.key']);
    must($status === 0, $output);
    file_put_contents($directory.'/packages.json', " \n", FILE_APPEND);
    [$status, $output] = execute('verify-registry.php', [$directory.'/registry.json', $directory.'/public.key']);
    must($status !== 0 && str_contains($output, 'Composer index'), $output);
};

foreach (['algorithm' => 'rsa', 'value' => 'AA'] as $field => $value) {
    $tests['signature verification rejects malformed '.$field] = function () use ($field, $value): void {
        $directory = workspace(true);
        $registry = readJson($directory.'/registry.json');
        $registry['signature'][$field] = $value;
        jsonFile($directory.'/registry.json', $registry);
        [$status, $output] = execute('verify-registry.php', [$directory.'/registry.json', $GLOBALS['root'].'/registry-public-key.txt']);
        must($status !== 0 && stripos($output, 'signature') !== false, $output);
    };
}

$tests['development signing keys persist and a mismatched public key is rejected'] = function (): void {
    $directory = workspace();
    [$status, $output] = execute('ensure-signing-key.php', [$directory.'/keys']);
    must($status === 0, $output);
    $before = file_get_contents($directory.'/keys/private.key');
    [$status, $output] = execute('ensure-signing-key.php', [$directory.'/keys']);
    must($status === 0 && file_get_contents($directory.'/keys/private.key') === $before, 'The persisted key changed.');
    file_put_contents($directory.'/keys/public.key', 'mismatch');
    [$status, $output] = execute('ensure-signing-key.php', [$directory.'/keys']);
    must($status !== 0 && str_contains($output, 'does not match'), 'A mismatched trust root was accepted.');
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
        }
    }
} finally {
    foreach ($temporary as $directory) {
        removeDirectory($directory);
    }
}
fwrite(STDOUT, count($tests)." tests, {$assertions} assertions, {$failed} failures.\n");
exit($failed === 0 ? 0 : 1);
