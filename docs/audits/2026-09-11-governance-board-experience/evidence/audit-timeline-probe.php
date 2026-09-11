<?php
// Read-only reproduction of the observed post-pack dashboard rendering failure.
$root=dirname(__DIR__,4); chdir($root); require $root.'/vendor/autoload.php';
$state=json_decode(file_get_contents(__DIR__.'/runtime-state.json'),true,512,JSON_THROW_ON_ERROR);
if($state['database']!=='oblivion_gov_audit_20260911_9888') throw new RuntimeException('Unexpected audit database');
$xml=simplexml_load_file($root.'/phpunit.xml');
foreach($xml->php->env as $entry){$k=(string)$entry['name'];$v=(string)$entry['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
foreach(['DB_DATABASE'=>$state['database'],'APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','CACHE_STORE'=>'array','MAIL_MAILER'=>'array'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$presenter=app(App\Domain\Governance\Support\GovernancePresenter::class);
$method=new ReflectionMethod($presenter,'buildTimeline');
$out=['database'=>$state['database'],'method'=>'GovernancePresenter::buildTimeline','read_only'=>true];
foreach(['member','treasurer','chair'] as $persona){
    $user=App\Models\User::findOrFail($state['actors'][$persona]['user_id']);
    $events=$method->invoke($presenter,$user)['events'];
    $out['roles'][$persona]=['count'=>count($events),'keys'=>array_keys($events),'is_list'=>array_is_list($events),'json_prefix'=>substr(json_encode($events),0,1)];
}
file_put_contents(__DIR__.'/timeline-probe-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
