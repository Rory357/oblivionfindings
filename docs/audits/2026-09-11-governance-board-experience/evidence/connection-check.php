<?php
$xml=simplexml_load_file(dirname(__DIR__,4).'/phpunit.xml');$env=[];foreach($xml->php->env as $entry)$env[(string)$entry['name']]=(string)$entry['value'];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306',$env['DB_USERNAME'],$env['DB_PASSWORD'],[PDO::ATTR_TIMEOUT=>5]);
foreach($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $row)echo json_encode(array_intersect_key($row,array_flip(['Id','db','Command','Time','State']))).PHP_EOL;
