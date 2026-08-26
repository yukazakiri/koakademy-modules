<?php

declare(strict_types=1);

$keyDirectory = $argv[1] ?? getenv('REGISTRY_KEY_DIR') ?: __DIR__.'/../keys';
$privatePath = rtrim($keyDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'private.key';
$publicPath = rtrim($keyDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'public.key';

if (! is_dir($keyDirectory) && ! mkdir($keyDirectory, 0700, true) && ! is_dir($keyDirectory)) {
    throw new RuntimeException("Unable to create registry key directory [{$keyDirectory}].");
}

if (is_file($privatePath)) {
    $privateKey = base64UrlDecode(trim((string) file_get_contents($privatePath)));

    if ($privateKey === false || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        throw new RuntimeException('The existing registry private key is invalid.');
    }

    $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey($privateKey);
} else {
    $keyPair = sodium_crypto_sign_keypair();
    $privateKey = sodium_crypto_sign_secretkey($keyPair);
    $derivedPublicKey = sodium_crypto_sign_publickey($keyPair);

    writeKey($privatePath, base64UrlEncode($privateKey), 0600);
}

$derivedPublic = base64UrlEncode($derivedPublicKey);

if (is_file($publicPath) && trim((string) file_get_contents($publicPath)) !== $derivedPublic) {
    throw new RuntimeException('The registry public key does not match the persisted private key.');
}

writeKey($publicPath, $derivedPublic, 0644);
fwrite(STDOUT, $derivedPublic.PHP_EOL);

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

function writeKey(string $path, string $value, int $mode): void
{
    if (file_put_contents($path, $value.PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write registry key file [{$path}].");
    }

    chmod($path, $mode);
}
