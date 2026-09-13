<?php
// Audit-only loopback preview; fail closed without this task's disposable state.
declare(strict_types=1);
$root=realpath(__DIR__.'/../../../../../..');
$stateFile=__DIR__.'/runtime-state.json';
$state=is_file($stateFile)?json_decode(file_get_contents($stateFile),true):[];
if(!$root || is_file(__DIR__.'/stop-runtime.flag') || !preg_match('/^oblivion_gov_review2_runtime_20260913_[0-9]+$/',$state['database']??'')){http_response_code(503);exit('Audit runtime unavailable');}
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$file=realpath($root.'/public/'.$uri);
if($file && str_starts_with($file,$root.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR) && is_file($file))return false;
chdir($root);
foreach(simplexml_load_file($root.'/phpunit.xml')->php->env as $e){$k=(string)$e['name'];$v=(string)$e['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$overrides=['APP_ENV'=>'local','APP_DEBUG'=>'false','APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','APP_URL'=>'http://127.0.0.1:8781','DB_DATABASE'=>$state['database'],'SESSION_DRIVER'=>'file','SESSION_COOKIE'=>'gov_review2_20260913','SESSION_SECURE_COOKIE'=>'false','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync','BROADCAST_CONNECTION'=>'null','CACHE_STORE'=>'array'];
foreach($overrides as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$runtimeStorage=__DIR__.'/runtime-storage';
foreach(['framework/sessions','framework/views','framework/cache/data','logs','app/private','app/public'] as $directory){if(!is_dir($runtimeStorage.'/'.$directory))mkdir($runtimeStorage.'/'.$directory,0777,true);}
define('LARAVEL_START',microtime(true));require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->useStoragePath($runtimeStorage);
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Notification::fake();
$app->make(Illuminate\Foundation\Vite::class)->useBuildDirectory('build-gov-audit2-20260913')->useHotFile(__DIR__.'/unused-hot');
$app->handleRequest(Illuminate\Http\Request::capture());

