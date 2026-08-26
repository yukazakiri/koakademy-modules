#!/bin/sh
set -eu

key_dir="${REGISTRY_KEY_DIR:-/data/keys}"
public_dir="${REGISTRY_PUBLIC_DIR:-/app/public}"

mkdir -p "$public_dir"
php /app/scripts/ensure-signing-key.php "$key_dir"
php /app/scripts/sign-registry.php /app/registry.json "$key_dir/private.key" "$public_dir/registry.json"
cp /app/packages.json "$public_dir/packages.json"
cp "$key_dir/public.key" "$public_dir/registry-public-key.txt"

exec "$@"
