<?php

// Deliberate access changes to the original technician fixture in one owned browser schema.
// Never loaded by application routes; never accepts a database, actor or permission from input.
$token = $argv[1] ?? '';
$fingerprint = $argv[2] ?? '';
$action = $argv[3] ?? '';
if (PHP_SAPI !== 'cli' || count($argv) !== 4 || ! preg_match('/\A[a-f0-9]{16}\z/', $token)
    || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)
    || ! in_array($action, ['revoke', 'restore', 'expire'], true)) {
    exit(2);
}
$committed = false;
try {
    $repo = realpath(__DIR__.'/../../../..');
    if (str_replace('\\', '/', (string) $repo) !== 'C:/Users/steph/Herd/oblivionfindings') {
        throw new RuntimeException('Unexpected checkout');
    }
    $root = $repo.'/storage/framework/testing/it-draft-browser-'.$token;
    if (! is_dir($root) || is_link($root) || realpath($root) !== str_replace('/', DIRECTORY_SEPARATOR, $root)) {
        throw new RuntimeException('Owned directory missing or escaped');
    }
    $owner = json_decode(file_get_contents($root.'/owner.json'), true, flags: JSON_THROW_ON_ERROR);
    $ready = json_decode(file_get_contents($root.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
    $database = 'oblivion_it_draft_browser_'.$token;
    if (($owner['token'] ?? null) !== $token || ($owner['database'] ?? null) !== $database
        || ($owner['approval_fingerprint'] ?? null) !== $fingerprint || ($ready['database'] ?? null) !== $database
        || ! ($ready['ready'] ?? false) || ! ($owner['monitoring_fixtures'] ?? false)) {
        throw new RuntimeException('Owned manifest mismatch');
    }
    foreach ($owner['source_hashes'] as $file => $hash) {
        if (basename($file) !== $file || hash_file('sha256', __DIR__.'/'.$file) !== $hash) {
            throw new RuntimeException('Reviewed fixture changed');
        }
    }
    $access = [];
    foreach (simplexml_load_file($repo.'/phpunit.xml')->php->env as $setting) {
        if (in_array((string) $setting['name'], ['DB_USERNAME', 'DB_PASSWORD'], true)) {
            $access[(string) $setting['name']] = (string) $setting['value'];
        }
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database, $access['DB_USERNAME'], $access['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    unset($access);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Database mismatch');
    }
    $actorId = $ready['fixtures']['actors']['tech']['id'] ?? null;
    if (! is_int($actorId)) {
        throw new RuntimeException('Synthetic actor missing');
    }
    $pdo->beginTransaction();
    $actor = $pdo->prepare('SELECT id FROM users WHERE id = ? AND email = ? AND name = ? FOR UPDATE');
    $actor->execute([$actorId, 'w06-tech@demo.test', 'W06 '.$token.' tech']);
    if ((int) $actor->fetchColumn() !== $actorId) {
        throw new RuntimeException('Synthetic actor identity changed');
    }
    if ($action === 'expire') {
        // Exercise the real database-session expiry check without reading session IDs/payloads.
        $statement = $pdo->prepare('UPDATE sessions SET last_activity = 1 WHERE user_id = ? AND last_activity > 1');
        $statement->execute([$actorId]);
        $changed = $statement->rowCount();
        if ($changed !== 1) {
            throw new RuntimeException('Expected exactly one current synthetic session');
        }
    } else {
        $grant = $pdo->prepare("SELECT r.id AS role_id, p.id AS permission_id FROM roles r JOIN role_user ru ON ru.role_id = r.id CROSS JOIN permissions p WHERE ru.user_id = ? AND r.name = 'w06-browser-tech' AND p.`key` = 'controlRoom.alerts.manage'");
        $grant->execute([$actorId]);
        $rows = $grant->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new RuntimeException('Expected one fixture role and permission');
        }
        $binding = [$rows[0]['role_id'], $rows[0]['permission_id']];
        $marker = $root.'/w14-access-grant-revoked.json';
        if ($action === 'revoke') {
            if (file_exists($marker)) {
                throw new RuntimeException('Revocation already recorded');
            }
            $statement = $pdo->prepare('DELETE FROM role_permission WHERE role_id = ? AND permission_id = ?');
            $statement->execute($binding);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Expected existing synthetic grant');
            }
            if (file_put_contents($marker, json_encode(['token' => $token, 'fingerprint' => $fingerprint, 'binding' => $binding], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Could not record restorable grant');
            }
        } else {
            $record = json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR);
            if ($record !== ['token' => $token, 'fingerprint' => $fingerprint, 'binding' => $binding]) {
                throw new RuntimeException('Restoration does not match the recorded grant');
            }
            $statement = $pdo->prepare('INSERT INTO role_permission (role_id, permission_id) VALUES (?, ?)');
            $statement->execute($binding);
        }
        $changed = $statement->rowCount();
    }
    $pdo->commit();
    $committed = true;
    echo json_encode(['token' => $token, 'database' => $database, 'actor_id' => $actorId, 'action' => $action,
        'changed_rows' => $changed, 'committed' => true, 'production_mutations' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['token' => $token, 'action' => $action, 'committed' => $committed, 'error_class' => $exception::class,
        'reason' => $exception instanceof RuntimeException ? $exception->getMessage() : 'Guarded fixture transition failed']).PHP_EOL;
    exit(1);
}
