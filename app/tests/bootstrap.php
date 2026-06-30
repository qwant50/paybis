<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// No .env files: configuration comes from the real environment (injected by docker
// compose). The deterministic, non-secret test-only overrides live here so the
// response-signature assertions stay stable. The test database name is derived in
// config/packages/test/doctrine.yaml; its host/port/user/password are inherited
// from the real environment.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '1';
// Deterministic Ed25519 signing seed (32-byte hex); its public key is
// 207a067892821e25d770f1fba0c47c11ff4b813e54162ece9eb839e076231ab6, which the
// signature assertions verify against.
$_SERVER['API_SIGNING_PRIVATE_KEY'] = $_ENV['API_SIGNING_PRIVATE_KEY'] = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$_SERVER['API_SIGNING_KEY_ID'] = $_ENV['API_SIGNING_KEY_ID'] = 'test';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
