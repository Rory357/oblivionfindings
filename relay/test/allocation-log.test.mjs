// Spec 11, test 19: the allocation log, and the guard from spec 14 that relay/ imports only
// node: modules and its own files.

import assert from 'node:assert/strict';
import {
    existsSync,
    readdirSync,
    readFileSync,
    statSync,
    writeFileSync,
} from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { AllocationLog, OpsLog } from '../lib/log.mjs';
import { allocated, FakeClock, startTestRelay, tempDir } from './helpers.mjs';

const DAY = 86_400_000;
const day = (ms) => new Date(ms).toISOString().slice(0, 10);

describe('19. The allocation log', () => {
    test('lines go to one file per UTC day', () => {
        const dir = tempDir('relay-log-');
        const clock = new FakeClock(Date.parse('2026-11-01T23:59:50Z'));
        const log = new AllocationLog({
            dir,
            retentionDays: 14,
            clock,
            ops: new OpsLog({ clock, write: () => {} }),
        });
        log.write('allocation_start', {
            class: 'chat',
            client_ip: '198.51.100.1',
            client_port: 5000,
            secret: 'never written',
        });
        clock.advance(20_000);
        log.write('allocation_end', {
            class: 'chat',
            client_ip: '198.51.100.1',
            client_port: 5000,
            reason: 'lifetime',
        });
        log.close();
        assert.deepEqual(readdirSync(dir).sort(), [
            'allocations-2026-11-01.jsonl',
            'allocations-2026-11-02.jsonl',
        ]);
        const first = JSON.parse(
            readFileSync(join(dir, 'allocations-2026-11-01.jsonl'), 'utf8'),
        );
        assert.deepEqual(Object.keys(first), [
            'time',
            'level',
            'event',
            'class',
            'client_ip',
            'client_port',
        ]);
        assert.equal(first.event, 'allocation_start');
    });

    test('files older than the retention are deleted at start and on the hourly sweep; newer ones are kept', async () => {
        const logDir = tempDir('relay-log-');
        const clock = new FakeClock(Date.parse('2026-11-20T12:00:00Z'));
        const now = clock.now();
        for (const daysAgo of [30, 16, 15, 14, 13, 1, 0])
            writeFileSync(
                join(logDir, `allocations-${day(now - daysAgo * DAY)}.jsonl`),
                '{}\n',
            );
        writeFileSync(join(logDir, 'notes.txt'), 'not ours');
        const t = await startTestRelay({
            clock,
            logDir,
            env: { RELAY_LOG_RETENTION_DAYS: '14' },
        });
        try {
            const names = () => readdirSync(logDir).sort();
            // At start (cut-off 6 Nov 12:00): every file whose last possible line is older goes.
            assert.deepEqual(names(), [
                'allocations-2026-11-06.jsonl',
                'allocations-2026-11-07.jsonl',
                'allocations-2026-11-19.jsonl',
                'allocations-2026-11-20.jsonl',
                'notes.txt',
            ]);
            // A day later (cut-off 7 Nov 12:00), at the next hourly sweep, 6 Nov goes and 7 Nov stays.
            clock.advance(DAY);
            const client = await allocated(t, t.relay.port('udp'));
            t.relay.sweep();
            assert.deepEqual(names(), [
                'allocations-2026-11-07.jsonl',
                'allocations-2026-11-19.jsonl',
                'allocations-2026-11-20.jsonl',
                'allocations-2026-11-21.jsonl',
                'notes.txt',
            ]);
            client.close();
        } finally {
            await t.close();
        }
    });

    test(
        'files are created with mode 0640 and the directory group',
        { skip: process.platform === 'win32' && 'POSIX modes and groups' },
        () => {
            const dir = tempDir('relay-log-');
            const clock = new FakeClock();
            const log = new AllocationLog({
                dir,
                retentionDays: 14,
                clock,
                ops: new OpsLog({ clock, write: () => {} }),
            });
            log.write('allocation_start', { class: 'chat' });
            log.close();
            const file = statSync(
                join(dir, `allocations-${day(clock.now())}.jsonl`),
            );
            assert.equal(file.mode & 0o777, 0o640);
            assert.equal(file.gid, statSync(dir).gid);
        },
    );
});

describe('Guard: relay/ imports only node: modules and its own files', () => {
    test('every import in relay/ is node: or a relative path inside relay/, and nothing can load a package', () => {
        // The module loader (imported, or fetched from `process` without an import) and its
        // require maker could load any installed package, so all of them are refused. The
        // names are built here so this file doesn't trip its own check.
        const loader = ['node', 'module'].join(':');
        const loaders = [
            ['create', 'Require'].join(''),
            ['getBuiltin', 'Module'].join(''),
        ];
        const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
        const files = [];
        const walk = (dir) => {
            for (const entry of readdirSync(dir, { withFileTypes: true })) {
                const path = join(dir, entry.name);
                if (entry.isDirectory()) walk(path);
                else if (/\.(mjs|js|cjs)$/.test(entry.name)) files.push(path);
            }
        };
        walk(root);
        assert.ok(files.length > 10);
        const pattern =
            /(?:^|[\s;])(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|import\s*\(\s*['"]([^'"]+)['"]\s*\)|require\s*\(\s*['"]([^'"]+)['"]\s*\)|^import\s+['"]([^'"]+)['"]/gm;
        for (const file of files) {
            const source = readFileSync(file, 'utf8');
            for (const name of loaders) {
                assert.ok(
                    !new RegExp(`\\b${name}\\b`).test(source),
                    `${relative(root, file)} uses ${name}`,
                );
            }
            for (const match of source.matchAll(pattern)) {
                const target = match[1] ?? match[2] ?? match[3] ?? match[4];
                assert.ok(
                    target !== loader && target !== 'module',
                    `${relative(root, file)} imports ${target}`,
                );
                if (target.startsWith('node:')) continue;
                assert.ok(
                    target.startsWith('./') || target.startsWith('../'),
                    `${relative(root, file)} imports ${target}`,
                );
                const resolved = resolve(dirname(file), target);
                assert.ok(
                    !relative(root, resolved).startsWith('..'),
                    `${relative(root, file)} reaches outside relay/: ${target}`,
                );
                assert.ok(
                    existsSync(resolved),
                    `${relative(root, file)} imports a missing file: ${target}`,
                );
            }
        }
    });
});
