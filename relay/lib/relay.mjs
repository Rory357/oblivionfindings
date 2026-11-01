// Starts Oblivion Relay from a checked configuration: the client listeners, the health endpoint,
// the control channel, the sweep, reload and shutdown. relay/server.mjs is the program; tests call
// startRelay() directly with a fake clock and, for one network-path test only, a peer policy.

import {
    accessSync,
    existsSync,
    constants as fs,
    readFileSync,
    renameSync,
    writeFileSync,
} from 'node:fs';
import { join } from 'node:path';
import { clearInterval, setInterval, setTimeout } from 'node:timers';
import { ConfigError, loadSecrets, loadTls } from './config.mjs';
import { createControlServer } from './control.mjs';
import { parseOpaqueId, parseUsername } from './credentials.mjs';
import { createHealthServer } from './health.mjs';
import { startStream, startUdp } from './listeners.mjs';
import { AllocationLog, OpsLog } from './log.mjs';
import { createMetrics, RelayCore } from './turn.mjs';

export const systemClock = Object.freeze({ now: () => Date.now() });

/** The deny file (spec 3.5): one opaque id or "expiry:opaque-id" per line; "#" starts a comment. */
export function loadDenyFile(path) {
    const deny = { ids: new Set(), usernames: new Set() };
    if (!path) return deny;
    let text;
    try {
        text = readFileSync(path, 'utf8');
    } catch {
        throw new ConfigError(
            'RELAY_DENY_FILE',
            'names a file that cannot be read',
        );
    }
    text.split(/\r?\n/).forEach((line, index) => {
        const entry = line.replace(/#.*$/, '').trim();
        if (!entry) return;
        if (parseOpaqueId(entry)) deny.ids.add(entry);
        else if (parseUsername(Buffer.from(entry, 'latin1')))
            deny.usernames.add(entry);
        else
            throw new ConfigError(
                'RELAY_DENY_FILE',
                `line ${index + 1} is not an opaque id or a username`,
            );
    });
    return deny;
}

/** Held revocations from RELAY_STATE_DIR/revocations.json. An unreadable file stops start-up. */
export function loadRevocations(stateDir, nowSeconds, skewSeconds) {
    const path = join(stateDir, 'revocations.json');
    const held = new Map();
    if (!existsSync(path)) return held;
    try {
        const body = JSON.parse(readFileSync(path, 'utf8'));
        if (body?.version !== 1 || !Array.isArray(body.revocations))
            throw new Error('shape');
        for (const entry of body.revocations) {
            if (
                !parseOpaqueId(entry?.id) ||
                !Number.isSafeInteger(entry?.expires_up_to)
            )
                throw new Error('entry');
            if (entry.expires_up_to + skewSeconds >= nowSeconds) {
                held.set(
                    entry.id,
                    Math.max(held.get(entry.id) ?? 0, entry.expires_up_to),
                );
            }
        }
    } catch {
        throw new ConfigError(
            'RELAY_STATE_DIR',
            `${path} cannot be read; move it aside to start without it`,
        );
    }
    return held;
}

function saveRevocations(stateDir, held) {
    const path = join(stateDir, 'revocations.json');
    const temporary = `${path}.tmp`;
    const body = {
        version: 1,
        revocations: [...held].map(([id, until]) => ({
            id,
            expires_up_to: until,
        })),
    };
    writeFileSync(temporary, JSON.stringify(body), { mode: 0o640 });
    renameSync(temporary, path);
}

function writableDirectory(path, setting) {
    try {
        accessSync(path, fs.W_OK);
    } catch {
        throw new ConfigError(
            setting,
            'must be an existing directory the relay can write to',
        );
    }
}

function listen(server, host, port) {
    return new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen({ host, port, exclusive: true }, () => {
            server.off('error', reject);
            resolve(server.address().port);
        });
    });
}

/**
 * Start the relay. Options: clock ({ now() } in ms), opsWrite (a function for operations log
 * lines, stdout by default), peerPolicy (tests only; relay/server.mjs never passes one).
 */
export async function startRelay(
    config,
    { clock = systemClock, opsWrite, peerPolicy = null } = {},
) {
    const ops = new OpsLog({ level: config.logLevel, write: opsWrite, clock });
    writableDirectory(config.logDir, 'RELAY_LOG_DIR');
    writableDirectory(config.stateDir, 'RELAY_STATE_DIR');
    const held = loadRevocations(
        config.stateDir,
        Math.floor(clock.now() / 1000),
        config.clockSkewSeconds,
    );
    const denyList = loadDenyFile(config.denyFile);
    const allocationLog = new AllocationLog({
        dir: config.logDir,
        retentionDays: config.logRetentionDays,
        clock,
        ops,
    });
    allocationLog.sweep();
    let lastLogSweep = clock.now();

    const metrics = createMetrics();
    const core = new RelayCore({
        config,
        clock,
        ops,
        allocationLog,
        metrics,
        peerPolicy,
        denyList,
        revocations: held,
    });
    let tls = config.tls;
    const startedAt = clock.now();

    const relay = {
        config,
        core,
        metrics,
        allocationLog,
        ops,
        listeners: [],
        secrets: () => config.secrets,
        revoke(entries, reason) {
            const ended = core.revoke(entries, reason);
            saveRevocations(config.stateDir, core.held);
            return { ended, held: core.held.size };
        },
        healthBody() {
            const memory = process.memoryUsage();
            const portsFree = core.portsFree();
            const range = config.portMax - config.portMin + 1;
            const notAfter = tls?.notAfter ? new Date(tls.notAfter) : null;
            const listenersOk = relay.listeners.every(
                (listener) => listener.ok,
            );
            const degraded =
                (notAfter &&
                    notAfter.getTime() - clock.now() < 14 * 86_400_000) ||
                portsFree < range * 0.1;
            return {
                status: !listenersOk ? 'down' : degraded ? 'degraded' : 'ok',
                version: config.version,
                uptime_s: Math.floor((clock.now() - startedAt) / 1000),
                listeners: relay.listeners.map((listener) => ({
                    transport: listener.transport,
                    port: listener.port,
                    ok: listener.ok,
                })),
                classes: {
                    chat: {
                        enabled: !!config.secrets.chat.current,
                        previous_secret: !!config.secrets.chat.previous,
                    },
                    cctv: 'deferred',
                    remote: 'deferred',
                },
                allocations: core.allocationsByClass(),
                capacity: {
                    allocations_max: config.maxAllocations,
                    ports_free: portsFree,
                },
                memory: {
                    rss_mb: Math.round(memory.rss / 1048576),
                    heap_used_mb: Math.round(memory.heapUsed / 1048576),
                },
                tls: { not_after: notAfter ? notAfter.toISOString() : null },
            };
        },
        metricsBody() {
            return {
                allocations: {
                    created: metrics.allocations.created,
                    active: core.allocations.size,
                    ended: { ...metrics.allocations.ended },
                },
                requests: structuredClone(metrics.requests),
                relayed: structuredClone(metrics.relayed),
                drops: { ...metrics.drops },
                unauthenticated: { ...metrics.unauthenticated },
                refusals: { ...metrics.refusals },
                malformed: metrics.malformed,
                control: { ...metrics.control },
            };
        },
        /** SIGHUP: secret files, the certificate and the deny list, without dropping allocations. */
        reload() {
            const results = {};
            try {
                const secrets = loadSecrets(config);
                config.secrets = secrets;
                core.applySecrets(secrets);
                results.secrets = 'ok';
            } catch (error) {
                results.secrets = 'kept';
                ops.log('error', 'config_error', {
                    setting: error.setting,
                    message: error.message,
                });
            }
            try {
                const next = loadTls(config);
                if (next) {
                    tls = next;
                    config.tls = next;
                }
                results.tls = next ? 'ok' : 'none';
            } catch (error) {
                results.tls = 'kept';
                ops.log('error', 'config_error', {
                    setting: error.setting,
                    message: error.message,
                });
            }
            try {
                core.applyDenyList(loadDenyFile(config.denyFile));
                results.deny = 'ok';
            } catch (error) {
                results.deny = 'kept';
                ops.log('error', 'config_error', {
                    setting: error.setting,
                    message: error.message,
                });
            }
            core.rulesVersion++;
            ops.log('info', 'reload', { status: results });
            return results;
        },
        sweep() {
            const now = clock.now();
            if (core.sweep()) saveRevocations(config.stateDir, core.held);
            for (const listener of relay.listeners) listener.sweep?.(now);
            control.prune(now);
            if (now - lastLogSweep >= config.timings.logSweepMs) {
                lastLogSweep = now;
                allocationLog.sweep();
            }
        },
        port(transport) {
            return relay.listeners.find(
                (listener) => listener.transport === transport,
            )?.port;
        },
    };

    const context = {
        core,
        clock,
        config,
        tls: () => tls.context,
        allocationLog,
        ops,
    };
    const health = createHealthServer({ relay });
    const control = createControlServer({
        relay,
        config,
        clock,
        ops,
        allocationLog,
    });
    try {
        for (const entry of config.listeners) {
            relay.listeners.push(
                await (entry.transport === 'udp'
                    ? startUdp(entry, context)
                    : startStream(entry, context)),
            );
        }
        relay.healthPort = await listen(
            health,
            config.healthListen.host,
            config.healthListen.port,
        );
        relay.controlPort = await listen(
            control,
            config.controlListen.host,
            config.controlListen.port,
        );
    } catch (error) {
        ops.log('error', 'listener_error', { message: error.message });
        for (const listener of relay.listeners) await listener.close();
        health.close();
        control.close();
        allocationLog.close();
        throw error;
    }

    const timer = setInterval(() => relay.sweep(), config.timings.sweepMs);
    timer.unref();

    let closing = null;
    relay.close = () => {
        closing ??= (async () => {
            clearInterval(timer);
            core.closeAll('shutdown');
            const stop = Promise.all([
                ...relay.listeners.map((listener) => listener.close()),
                new Promise((done) => health.close(() => done())),
                new Promise((done) => control.close(() => done())),
            ]);
            health.closeAllConnections?.();
            control.closeAllConnections?.();
            await Promise.race([
                stop,
                new Promise((done) =>
                    setTimeout(done, config.timings.closeMs).unref(),
                ),
            ]);
            allocationLog.close();
        })();
        return closing;
    };

    ops.log('info', 'start', {
        version: config.version,
        listeners: relay.listeners.map((listener) => ({
            transport: listener.transport,
            port: listener.port,
        })),
    });
    return relay;
}
