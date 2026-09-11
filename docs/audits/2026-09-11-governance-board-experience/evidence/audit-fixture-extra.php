<?php
$root=dirname(__DIR__,4); chdir($root); require $root.'/vendor/autoload.php';
$state=json_decode(file_get_contents(__DIR__.'/runtime-state.json'),true);
if(!preg_match('/^oblivion_gov_audit_20260911_\d+$/',$state['database'])) throw new RuntimeException('Wrong database');
$xml=simplexml_load_file($root.'/phpunit.xml');
foreach($xml->php->env as $e){$k=(string)$e['name'];$v=(string)$e['value'];putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
foreach(['DB_DATABASE'=>$state['database'],'APP_CONFIG_CACHE'=>__DIR__.'/unused-config.php','CACHE_STORE'=>'array'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$_SERVER[$k]=$v;}
$app=require $root.'/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$bm=App\Domain\Governance\Models\BoardMember::findOrFail($state['actors']['treasurer']['board_member_id']);
$bm->update(['board_role'=>'member']);
$committee=App\Domain\Governance\Models\BoardCommittee::firstOrCreate(['name'=>'Audit finance committee'],['committee_type'=>'finance','description'=>'Synthetic finance oversight','chair_id'=>$bm->id,'is_active'=>true]);
$committee->members()->syncWithoutDetaching([$bm->id=>['role'=>'chair','appointed_at'=>'2026-01-01','term_end'=>'2026-12-31','is_active'=>true]]);
$path='governance-audit-20260911-9888/audit-pack.pdf';
Illuminate\Support\Facades\Storage::disk('local')->put($path,file_get_contents(__DIR__.'/audit-pack.pdf'));
App\Domain\Governance\Models\BoardPack::findOrFail($state['pack_id'])->update(['file_path'=>$path]);
echo json_encode(['finance_board_role'=>$bm->fresh()->board_role,'committee_id'=>$committee->id,'pack_path'=>$path],JSON_PRETTY_PRINT).PHP_EOL;
