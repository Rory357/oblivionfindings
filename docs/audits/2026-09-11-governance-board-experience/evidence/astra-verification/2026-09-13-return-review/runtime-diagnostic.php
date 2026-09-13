<?php
// Read-only audit schema/progress inventory; deliberately excludes query text.
$root=realpath(__DIR__.'/../../../../../..');$env=[];
foreach(simplexml_load_file($root.'/phpunit.xml')->php->env as $e)$env[(string)$e['name']]=(string)$e['value'];
$pdo=new PDO('mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'],$env['DB_USERNAME'],$env['DB_PASSWORD']);
$prefixes=['oblivion_gov_audit_20260913_','oblivion_gov_review_runtime_20260913_','oblivion_gov_sites_audit_20260913_'];$result=[];
foreach($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $db){if(!array_filter($prefixes,fn($p)=>str_starts_with($db,$p)))continue;$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=?');$q->execute([$db]);$result['schemas'][]=['name'=>$db,'tables'=>$q->fetchColumn()];}
foreach($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $row){if(!array_filter($prefixes,fn($p)=>str_starts_with($row['db']??'',$p)))continue;$result['connections'][]=array_intersect_key($row,array_flip(['Id','db','Command','Time','State']));}
echo json_encode($result,JSON_PRETTY_PRINT);

