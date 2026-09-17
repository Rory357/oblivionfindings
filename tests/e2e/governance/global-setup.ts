import { execFileSync } from 'node:child_process';
import { existsSync, renameSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { phpRequiresShell, resolvePhpBinary } from '../php-binary';

export const GOVERNANCE_DISPOSABLE_DB_PREFIX = 'oblivion_gov_e2e_';

export default async function globalSetup(): Promise<void> {
    const here = dirname(fileURLToPath(import.meta.url));
    const root = resolve(here, '..', '..', '..');
    const hot = resolve(root, 'public', 'hot');
    const backup = resolve(root, 'public', '.hot.playwright.bak');

    if (existsSync(hot)) {
        renameSync(hot, backup);
        console.log('[governance playwright setup] moved public/hot aside');
    }

    const phpBin = resolvePhpBinary() ?? 'php';
    const timestamp = Date.now();
    const pid = process.pid;
    const dbName = `${GOVERNANCE_DISPOSABLE_DB_PREFIX}${timestamp}_${pid}`;

    // Safety guard: Ensure dbName starts with oblivion_gov_e2e_ and is not a live DB
    if (
        !dbName.startsWith(GOVERNANCE_DISPOSABLE_DB_PREFIX) ||
        dbName === 'oblivionfindings' ||
        dbName === 'oblivion_findings'
    ) {
        throw new Error(`Refusing to run tests against non-disposable database: ${dbName}`);
    }

    // Persist dbName for teardown and webServer env
    const stateFile = resolve(here, '.governance-e2e-db.json');
    writeFileSync(stateFile, JSON.stringify({ dbName, timestamp }), 'utf8');
    process.env.DB_DATABASE = dbName;

    console.log(`[governance playwright setup] creating disposable database ${dbName}...`);

    const runScript = resolve(here, 'setup-db.php');
    writeFileSync(
        runScript,
        `<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'testUser', 'test101', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS \`${dbName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$disposableConfigCache = sys_get_temp_dir() . '/oblivion_gov_config_${timestamp}.php';
putenv("DB_DATABASE=${dbName}");
$_ENV['DB_DATABASE'] = '${dbName}';
$_SERVER['DB_DATABASE'] = '${dbName}';
putenv("APP_CONFIG_CACHE={$disposableConfigCache}");
$_ENV['APP_CONFIG_CACHE'] = $disposableConfigCache;
$_SERVER['APP_CONFIG_CACHE'] = $disposableConfigCache;
putenv("MAIL_MAILER=array");
$_ENV['MAIL_MAILER'] = 'array';
$_SERVER['MAIL_MAILER'] = 'array';
putenv("QUEUE_CONNECTION=sync");
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_SERVER['QUEUE_CONNECTION'] = 'sync';
putenv("CACHE_STORE=array");
$_ENV['CACHE_STORE'] = 'array';
$_SERVER['CACHE_STORE'] = 'array';
putenv("SESSION_DRIVER=file");
$_ENV['SESSION_DRIVER'] = 'file';
$_SERVER['SESSION_DRIVER'] = 'file';

require '${root.replace(/\\/g, '/')}/vendor/autoload.php';
$app = require '${root.replace(/\\/g, '/')}/bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\DB;

$resolvedDb = config('database.connections.mysql.database');
if ($resolvedDb !== '${dbName}' || !str_starts_with($resolvedDb, '${GOVERNANCE_DISPOSABLE_DB_PREFIX}')) {
    throw new RuntimeException("Resolved DB {$resolvedDb} does not match disposable ${dbName}");
}

Artisan::call('migrate', ['--force' => true]);
\\Tests\\Support\\GovernanceSyntheticFixtures::seed();
echo "READY";
`,
        'utf8',
    );

    try {
        const out = execFileSync(phpBin, [runScript], {
            cwd: root,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'pipe'],
            shell: phpRequiresShell(phpBin),
        });
        if (!out.includes('READY')) {
            throw new Error(`Governance e2e database setup failed: ${out}`);
        }
        console.log(`[governance playwright setup] seeded ${dbName} successfully.`);
    } finally {
        if (existsSync(runScript)) {
            unlinkSync(runScript);
        }
    }
}
