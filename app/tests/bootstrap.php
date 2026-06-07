<?php

require dirname(__DIR__) . '/vendor/autoload.php';

// No .env files: configuration comes from the real environment (injected by docker
// compose). The deterministic, non-secret test-only overrides live here so the
// response-signature assertions stay stable. The test database name is derived in
// config/packages/test/doctrine.yaml; its host/port/user/password are inherited
// from the real environment.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '1';
$_SERVER['API_SIGNING_SECRET'] = $_ENV['API_SIGNING_SECRET'] = 'test_signing_secret';
$_SERVER['API_SIGNING_KEY_ID'] = $_ENV['API_SIGNING_KEY_ID'] = 'test';

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
