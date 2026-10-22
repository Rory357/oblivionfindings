#!/usr/bin/env node
// Oblivion Live: Chat's own live-update gateway.
// Spec: docs/chat-experience/clean-room/specs/oblivion-live.md. Node built-in modules only.
//
//   node live/server.mjs
//
// Environment: LIVE_PORT, LIVE_INTERNAL_PORT, LIVE_SECRET, LIVE_KEY_ID, LIVE_PREVIOUS_SECRET,
// LIVE_PREVIOUS_KEY_ID, LIVE_ALLOWED_ORIGINS, LIVE_APP_URL (and optionally LIVE_HOST, LIVE_PATH,
// LIVE_MAX_CONNECTIONS). See deploy/chat-calling/chat-realtime.md.

import { createServer } from 'node:http';
import { clearInterval, setInterval, setTimeout } from 'node:timers';
import { pathToFileURL } from 'node:url';
import { readConfig } from './lib/config.mjs';
import { LiveConnection } from './lib/connection.mjs';
import { CloseCode } from './lib/frames.mjs';
import {
    acceptResponse,
    checkUpgrade,
    refusalResponse,
} from './lib/handshake.mjs';
import { Hub } from './lib/hub.mjs';
import { createInternalServer } from './lib/internal.mjs';
import { PresenceReporter } from './lib/presence.mjs';

export const VERSION = '1.0.0';

function defaultLog(level, event, fields = {}) {
    // Content-free: IDs, counts and reasons only. Never tickets, payloads or names.
    const line = JSON.stringify({
        time: new Date().toISOString(),
        level,
        event,
        ...fields,
    });
    (level === 'error' ? process.stderr : process.stdout).write(line + '\n');
}

function listen(server, port, host) {
    return new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(port, host, () => {
            server.off('error', reject);
            resolve(server.address().port);
        });
    });
}

/** Start both listeners. Resolves to a handle with the bound ports and a close() function. */
export async function startGateway(
    config,
    { log = defaultLog, presence } = {},
) {
    config = { version: VERSION, ...config };
    const reporter = presence ?? new PresenceReporter({ config, log });
    const hub = new Hub({ config, presence: reporter, log });
    let nextId = 1;

    const publicServer = createServer((request, response) => {
        // Only WebSocket upgrades are served here.
        response.writeHead(426, {
            'Content-Type': 'text/plain',
            Upgrade: 'websocket',
            Connection: 'close',
            'Cache-Control': 'no-store',
        });
        response.end('Upgrade required\n');
    });
    publicServer.headersTimeout = 10_000;
    publicServer.requestTimeout = 10_000;
    publicServer.on('clientError', (error, socket) => socket.destroy());
    publicServer.on('upgrade', (request, socket, head) => {
        socket.on('error', () => socket.destroy());
        const result = checkUpgrade(request, {
            path: config.path,
            allowedOrigins: config.allowedOrigins,
        });
        if (!result.ok) {
            socket.end(
                refusalResponse(result.status, result.reason, result.headers),
            );
            return;
        }
        if (hub.connectionCount >= config.maxConnections) {
            socket.end(
                refusalResponse(503, 'Service Unavailable', {
                    'Retry-After': '5',
                }),
            );
            return;
        }
        socket.write(acceptResponse(result.accept));
        const connection = new LiveConnection({
            id: nextId++,
            socket,
            config,
            hub,
            log,
        });
        hub.track(connection);
        connection.start(head);
    });

    const internalServer = createInternalServer({ hub, config, log });
    const sweep = setInterval(() => {
        const now = hub.nowSeconds();
        hub.pruneGrants(now);
        hub.pruneRevocations(now);
        hub.jti.prune(now);
    }, 30_000);
    sweep.unref();

    const port = await listen(publicServer, config.port, config.host);
    // The internal API is for the app on this machine only.
    const internalPort = await listen(
        internalServer,
        config.internalPort,
        '127.0.0.1',
    );
    log('info', 'gateway.started', {
        version: config.version,
        port,
        internal_port: internalPort,
        path: config.path,
    });

    let closing = null;
    const close = () => {
        closing ??= new Promise((resolve) => {
            clearInterval(sweep);
            hub.closeAll(CloseCode.GOING_AWAY, 'Server restarting');
            let pending = 2;
            const done = () => --pending === 0 && resolve();
            publicServer.close(done);
            internalServer.close(done);
            publicServer.closeAllConnections?.();
            internalServer.closeAllConnections?.();
            // Give browsers a moment to answer the close frames, then drop what is left.
            setTimeout(
                () => {
                    for (const connection of [...hub.connections])
                        connection.terminate();
                },
                Math.min(config.closeTimeoutMs, 1_000),
            ).unref();
        });
        return closing;
    };

    return {
        hub,
        config,
        port,
        internalPort,
        internalAddress: internalServer.address().address,
        close,
    };
}

async function main() {
    let config;
    try {
        config = readConfig(process.env);
    } catch (error) {
        defaultLog('error', 'gateway.config', { message: error.message });
        process.exit(78);
    }
    if (config.allowedOrigins.length === 0) {
        defaultLog('warn', 'gateway.no_origins', {
            message:
                'LIVE_ALLOWED_ORIGINS is empty, so every browser is refused',
        });
    }
    const gateway = await startGateway(config);
    const stop = async (signal) => {
        defaultLog('info', 'gateway.stopping', { signal });
        await gateway.close();
        process.exit(0);
    };
    process.on('SIGTERM', () => void stop('SIGTERM'));
    process.on('SIGINT', () => void stop('SIGINT'));
    process.on('uncaughtException', (error) => {
        defaultLog('error', 'gateway.uncaught', {
            message: error.message,
            stack: error.stack,
        });
        process.exit(70);
    });
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    await main();
}
