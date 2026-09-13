<?php
// Audit-only loopback preview using this review's disposable database and assets.
declare(strict_types=1);
$root=realpath(__DIR__.'/../../../../../..');
$state=json_decode(file_get_contents(__DIR__.'/runtime-state.json'),true);
if (!$root || !preg_match('/^oblivion_gov_review_runtime_20260912_[0-9]+$/',$state['database']??'')) { http_response_code(503); exit('Review database guard failed'); }
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=realpath($root.'/public/'.$uri);
if ($file && str_starts_with($file,$root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR) && is_file($file)) return false;
chdir($root);
foreach(simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) { $k=(string)$entry['name']; $v=(string)$entry['value']; putenv($k.'='.$v); $_ENV[$k]=$_SERVER[$k]=$v; }
$overrides=['APP_ENV'=>'local','APP_DEBUG'=>'false','APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','APP_URL'=>'http://127.0.0.1:8777','DB_DATABASE'=>$state['database'],'SESSION_DRIVER'=>'file','SESSION_COOKIE'=>'gov_review_20260912','SESSION_SECURE_COOKIE'=>'false','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync','BROADCAST_CONNECTION'=>'null','CACHE_STORE'=>'array'];
foreach($overrides as $k=>$v) { putenv($k.'='.$v); $_ENV[$k]=$_SERVER[$k]=$v; }
define('LARAVEL_START',microtime(true));
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$app->make(Illuminate\Foundation\Vite::class)->useBuildDirectory('gov-review-20260912')->useHotFile(__DIR__.'/unused-hot');
$app->handleRequest(Illuminate\Http\Request::capture());
