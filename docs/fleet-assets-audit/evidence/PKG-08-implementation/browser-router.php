<?php
$environment = json_decode(file_get_contents(__DIR__.'/browser-environment.json'), true, 512, JSON_THROW_ON_ERROR);
if (($environment['APP_ENV'] ?? '') !== 'testing' || ! str_starts_with($environment['DB_DATABASE'] ?? '', 'of_pkg08_browser_20260928_')) { http_response_code(503); exit; }
foreach ($environment as $key => $value) { putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value; }
$root = dirname(__DIR__, 4);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$candidate = realpath($root.'/public/'.$path);
if ($path !== '/' && $candidate && str_starts_with($candidate, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($candidate)) return false;
require $root.'/public/index.php';
