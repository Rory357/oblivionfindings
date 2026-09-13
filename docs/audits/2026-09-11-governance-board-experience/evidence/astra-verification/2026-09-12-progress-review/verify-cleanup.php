<?php
declare(strict_types=1);
$root=realpath(__DIR__.'/../../../../../..');
chdir($root);require $root.'/vendor/autoload.php';
foreach(simplexml_load_file($root.'/phpunit.xml')->php->env as $e){$k=(string)$e['name'];$v=(string)$e['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
foreach(['DB_DATABASE'=>'information_schema','APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$left=Illuminate\Support\Facades\DB::select('SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE ? OR schema_name LIKE ?',['oblivion_gov_review_20260912%','oblivion_gov_review_runtime_20260912%']);
$out=['checked_at'=>gmdate('c'),'remaining_review_databases'=>$left,'temporary_build_exists'=>is_dir($root.'/public/gov-review-20260912'),'runtime_shutdown_flag'=>is_file(__DIR__.'/stop-runtime.flag')];
file_put_contents(__DIR__.'/cleanup-check.json',json_encode($out,JSON_PRETTY_PRINT));echo json_encode($out,JSON_PRETTY_PRINT);
if(count($left)||$out['temporary_build_exists'])exit(1);

