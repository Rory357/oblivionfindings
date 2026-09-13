<?php
// Audit-only loopback server; normal login and CSRF remain enabled.
$root=dirname(__DIR__,4);
$state=json_decode(file_get_contents(__DIR__.'/runtime-state.json'),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^oblivion_gov_audit_20260911_\d+$/',$state['database'])) throw new RuntimeException('Unexpected audit database');
$xml=simplexml_load_file($root.'/phpunit.xml');
foreach($xml->php->env as $entry) { $k=(string)$entry['name'];$v=(string)$entry['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v; }
foreach(['APP_ENV'=>'local','APP_DEBUG'=>'false','APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','APP_URL'=>'http://127.0.0.1:8776','DB_DATABASE'=>$state['database'],'SESSION_DRIVER'=>'file','SESSION_COOKIE'=>'gov_audit_20260911','SESSION_SECURE_COOKIE'=>'false','SESSION_DOMAIN'=>'null','CACHE_STORE'=>'array','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync','BROADCAST_CONNECTION'=>'null'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path!=='/' && is_file($root.'/public'.$path)) return false;
require $root.'/public/index.php';
