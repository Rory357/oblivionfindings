<?php

/** PHP drops only the exact newly owned schema; PowerShell owns path removal. */

require __DIR__.'/w06-draft-browser-runtime.php';
try {
    w06BrowserRequire(PHP_SAPI === 'cli' && ($argv[1] ?? null) === '--drop-stopped-owned-schema' && count($argv) === 2, 'Explicit guarded teardown mode is required.');
    $context = w06BrowserGuard();
    w06BrowserRequire(is_file($context['root'].'/server-stopped.json') && is_file($context['root'].'/schema-created.json'), 'Exact stopped-server and schema ownership evidence is required.');
    $created = json_decode(file_get_contents($context['root'].'/schema-created.json'), true, flags: JSON_THROW_ON_ERROR);
    $stopped = json_decode(file_get_contents($context['root'].'/server-stopped.json'), true, flags: JSON_THROW_ON_ERROR);
    w06BrowserRequire(($created['database'] ?? null) === $context['database'] && ($created['token'] ?? null) === $context['token']
        && ($stopped['token'] ?? null) === $context['token'] && ($stopped['server_settled'] ?? false) === true, 'Stopped schema identity differs.');
    $pdo = w06BrowserPdo();
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$context['database']]);
    w06BrowserRequire((int) $exists->fetchColumn() === 1, 'Owned schema is absent; inspect previous cleanup outcome.');
    $pdo->exec('DROP DATABASE `'.$context['database'].'`');
    w06BrowserSaveNew($context['root'].'/schema-removed.json', ['database' => $context['database'], 'token' => $context['token'], 'removed_at' => gmdate(DATE_ATOM)]);
    echo json_encode(['database_removed' => $context['database'], 'token' => $context['token'], 'other_databases_affected' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'W06 isolated teardown stopped. No broad cleanup or inferred target was used. Inspect the exact owned schema/directory before retry.'.PHP_EOL);
    exit(1);
}
