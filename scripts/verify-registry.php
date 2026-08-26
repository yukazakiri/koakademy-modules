<?php

declare(strict_types=1);

$registryPath = $argv[1] ?? __DIR__.'/../registry.json';
$publicPath = $argv[2] ?? __DIR__.'/../registry-public-key.txt';

$payload = json_decode((string) file_get_contents($registryPath), true, 512, JSON_THROW_ON_ERROR);
$publicKey = base64UrlDecode(trim((string) file_get_contents($publicPath)));
$signature = $payload['signature']['value'] ?? null;

if (($payload['schema'] ?? null) !== 1 || ! is_array($payload['modules'] ?? null)) {
    throw new RuntimeException('The registry schema is invalid.');
}

if (! is_string($signature) || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
    throw new RuntimeException('The registry signature or public key is invalid.');
}

unset($payload['signature']);
$canonicalPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$signatureBytes = base64UrlDecode($signature);

if ($signatureBytes === false || ! sodium_crypto_sign_verify_detached($signatureBytes, $canonicalPayload, $publicKey)) {
    throw new RuntimeException('The registry signature could not be verified.');
}

fwrite(STDOUT, 'Registry signature verified.'.PHP_EOL);

function base64UrlDecode(string $value): string|false
{
    $normalized = strtr($value, '-_', '+/');
    $remainder = strlen($normalized) % 4;

    if ($remainder !== 0) {
        $normalized .= str_repeat('=', 4 - $remainder);
    }

    return base64_decode($normalized, true);
}
