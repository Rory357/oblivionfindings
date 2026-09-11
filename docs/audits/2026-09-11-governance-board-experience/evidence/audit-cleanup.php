<?php
// Remove only the named disposable database and the exact synthetic pack file.
$root=dirname(__DIR__,4);
$state=json_decode(file_get_contents(__DIR__.'/runtime-state.json'),true,512,JSON_THROW_ON_ERROR);
$expected='oblivion_gov_audit_20260911_9888';
if($state['database']!==$expected || (int)$state['pid']!==9888) throw new RuntimeException('Audit ownership guard failed');
$xml=simplexml_load_file($root.'/phpunit.xml');$env=[];
foreach($xml->php->env as $entry)$env[(string)$entry['name']]=(string)$entry['value'];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
$exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$expected]);
$out=['database'=>$expected,'checked_ownership'=>true,'database_present_before'=>(int)$exists->fetchColumn(),'checked_at'=>gmdate('c')];
if($out['database_present_before']) {
    $emails=$pdo->query('SELECT email FROM `oblivion_gov_audit_20260911_9888`.`users`')->fetchAll(PDO::FETCH_COLUMN);
    $expectedEmails=array_column($state['actors'],'email');
    if(count($emails)!==7 || array_diff($emails,$expectedEmails) || array_diff($expectedEmails,$emails)) throw new RuntimeException('Unexpected user data; cleanup refused');
    $out['synthetic_user_count']=count($emails);
    $pdo->exec('DROP DATABASE `oblivion_gov_audit_20260911_9888`');
}
$exists->execute([$expected]);$out['database_present_after']=(int)$exists->fetchColumn();
$privateRoot=realpath($root.'/storage/app/private');
$packFile=$root.'/storage/app/private/governance-audit-20260911-9888/audit-pack.pdf';
if(is_file($packFile)) {
    $resolved=realpath($packFile);
    if(!$privateRoot || !$resolved || !str_starts_with(strtolower($resolved),strtolower($privateRoot.DIRECTORY_SEPARATOR))) throw new RuntimeException('File containment guard failed');
    if(hash_file('sha256',$packFile)!==hash_file('sha256',__DIR__.'/audit-pack.pdf')) throw new RuntimeException('Synthetic pack content changed; cleanup refused');
    if(!unlink($resolved)) throw new RuntimeException('Pack removal failed');
    // Non-recursive: succeeds only if this task's exact directory is now empty.
    if(!rmdir(dirname($resolved))) throw new RuntimeException('Audit file directory not empty; inspect manually');
}
$out['synthetic_private_pack_present_after']=is_file($packFile);
$out['evidence_pdf_retained']=is_file(__DIR__.'/audit-pack.pdf');
file_put_contents(__DIR__.'/cleanup-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
