// The relay's two log streams (spec section 9).
//
// - The operations log goes to stdout (journald). It never carries an IP address, an id or an id
//   hash, so it can follow the host's usual retention.
// - The allocation log goes to one file per UTC day in RELAY_LOG_DIR, mode 0640, and is deleted
//   after RELAY_LOG_RETENTION_DAYS. It carries client IPs and ports, and id hashes, never ids,
//   usernames, credentials, nonces, peers or payloads.

import {
    closeSync,
    fchmodSync,
    openSync,
    readdirSync,
    unlinkSync,
    writeSync,
} from 'node:fs';
import { join } from 'node:path';

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };
const DAY_MS = 86_400_000;

// Field names each stream may carry. Anything else is dropped before writing.
const OPS_FIELDS = new Set([
    'version',
    'setting',
    'message',
    'transport',
    'port',
    'listeners',
    'signal',
    'reason',
    'count',
    'counts',
    'status',
    'allocations',
    'limit',
]);
const ALLOCATION_FIELDS = new Set([
    'class',
    'id',
    'client_ip',
    'client_port',
    'source_ip',
    'transport',
    'relay_port',
    'lifetime',
    'duration_s',
    'bytes_in',
    'bytes_out',
    'packets_in',
    'packets_out',
    'reason',
    'ended',
]);

// Error messages from the platform can name addresses (EADDRINUSE 0.0.0.0:3478); the
// operations log keeps only the words.
const ADDRESS_LIKE =
    /(\[?[0-9a-fA-F]*:[0-9a-fA-F:.]*[0-9a-fA-F]\]?(:\d+)?)|(\d{1,3}(\.\d{1,3}){3}(:\d+)?)/g;

function sanitise(value) {
    return typeof value === 'string'
        ? value.replace(ADDRESS_LIKE, '[address]').slice(0, 300)
        : value;
}

function pick(fields, allowed) {
    const out = {};
    for (const [key, value] of Object.entries(fields ?? {}))
        if (allowed.has(key) && value !== undefined) out[key] = value;
    return out;
}

export class OpsLog {
    constructor({
        level = 'info',
        write = (line) => process.stdout.write(line),
        clock,
    }) {
        this.level = LEVELS[level] ?? LEVELS.info;
        this.write = write;
        this.clock = clock;
    }

    log(level, event, fields = {}) {
        if ((LEVELS[level] ?? 0) > this.level) return;
        const safe = pick(fields, OPS_FIELDS);
        if (safe.message !== undefined) safe.message = sanitise(safe.message);
        this.write(
            JSON.stringify({
                time: new Date(this.clock.now()).toISOString(),
                level,
                event,
                ...safe,
            }) + '\n',
        );
    }
}

const dayOf = (ms) => new Date(ms).toISOString().slice(0, 10);

export class AllocationLog {
    constructor({ dir, retentionDays, clock, ops }) {
        this.dir = dir;
        this.retentionDays = retentionDays;
        this.clock = clock;
        this.ops = ops;
        this.fd = null;
        this.day = null;
        this.lastError = 0;
    }

    path(day) {
        return join(this.dir, `allocations-${day}.jsonl`);
    }

    write(event, fields = {}) {
        const now = this.clock.now();
        const line =
            JSON.stringify({
                time: new Date(now).toISOString(),
                level: 'info',
                event,
                ...pick(fields, ALLOCATION_FIELDS),
            }) + '\n';
        try {
            const day = dayOf(now);
            if (day !== this.day) this.open(day);
            writeSync(this.fd, line);
        } catch {
            this.close();
            if (now - this.lastError > 60_000) {
                this.lastError = now;
                this.ops.log('error', 'config_error', {
                    setting: 'RELAY_LOG_DIR',
                    message: 'the allocation log could not be written',
                });
            }
        }
    }

    open(day) {
        this.close();
        // 'wx' first so a new file gets 0640 exactly, whatever the umask; then append.
        let fd;
        try {
            fd = openSync(this.path(day), 'wx', 0o640);
            fchmodSync(fd, 0o640);
        } catch (error) {
            if (error.code !== 'EEXIST') throw error;
            fd = openSync(this.path(day), 'a');
        }
        this.fd = fd;
        this.day = day;
    }

    /** Delete files whose newest possible line is older than the retention limit. */
    sweep() {
        const cutoff = this.clock.now() - this.retentionDays * DAY_MS;
        let names = [];
        try {
            names = readdirSync(this.dir);
        } catch {
            return 0;
        }
        let removed = 0;
        for (const name of names) {
            const match = /^allocations-(\d{4}-\d{2}-\d{2})\.jsonl$/.exec(name);
            if (!match) continue;
            const start = Date.parse(`${match[1]}T00:00:00Z`);
            if (
                Number.isFinite(start) &&
                start + DAY_MS <= cutoff &&
                match[1] !== this.day
            ) {
                try {
                    unlinkSync(join(this.dir, name));
                    removed++;
                } catch {
                    // A file another process removed first is fine.
                }
            }
        }
        return removed;
    }

    close() {
        if (this.fd !== null) {
            try {
                closeSync(this.fd);
            } catch {
                // Already closed.
            }
        }
        this.fd = null;
        this.day = null;
    }
}
