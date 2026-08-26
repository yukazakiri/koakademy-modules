<?php

declare(strict_types=1);

$sourcePath = $argv[1] ?? __DIR__.'/../registry.json';
$privatePath = $argv[2] ?? getenv('REGISTRY_PRIVATE_KEY') ?: __DIR__.'/../keys/private.key';
$outputPath = $argv[3] ?? __DIR__.'/../registry.json';

$payload = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);

if (! is_array($payload)) {
    throw new RuntimeException('The registry payload must be a JSON object.');
}

unset($payload['signature']);

$privateKey = base64UrlDecode(trim((string) file_get_contents($privatePath)));

if ($privateKey === false || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    throw new RuntimeException('The registry private key is invalid.');
}

$canonicalPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$payload['signature'] = [
    'algorithm' => 'ed25519',
    'value' => base64UrlEncode(sodium_crypto_sign_detached($canonicalPayload, $privateKey)),
];

if (file_put_contents($outputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException("Unable to write signed registry [{$outputPath}].");
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode(string $value): string|false
{
    $normalized = strtr($value, '-_', '+/');
    $remainder = strlen($normalized) % 4;

    if ($remainder !== 0) {
        $normalized .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode($normalized, true);
}
