// Client-facing listeners (spec 5.1 and 8): UDP, plain TCP, TLS, and TLS or TCP on one port
// decided by the first byte (RFC 7983). Stream listeners may require a PROXY version 1 header.

import { createSocket } from 'node:dgram';
import { createServer } from 'node:net';
import { Duplex } from 'node:stream';
import { TLSSocket } from 'node:tls';
import { normalizeIp } from './addresses.mjs';
import { clientAllowed } from './peers.mjs';
import { takeProxyHeader } from './proxy.mjs';
import { MAGIC_COOKIE, MAX_REQUEST_BYTES, pad4 } from './stun.mjs';

const MAX_UNPARSED = 64 * 1024;
const MAX_DATA_BACKLOG = 256 * 1024;
const MAX_RESPONSE_BACKLOG = 1024 * 1024;
const MAX_UDP_CONTEXTS = 50_000;

/**
 * Splits a TCP or TLS byte stream into STUN messages and ChannelData (RFC 8656 12.5). A
 * framing error (wrong first bits, a wrong cookie, an impossible length) can't be recovered, so
 * it closes the connection. Oversized messages are skipped by their length.
 */
export class StreamFramer {
    constructor({
        onMessage,
        onError,
        onOversize = () => {},
        maxUnparsed = MAX_UNPARSED,
    }) {
        this.maxUnparsed = maxUnparsed;
        this.onMessage = onMessage;
        this.onError = onError;
        this.onOversize = onOversize;
        this.buffer = Buffer.alloc(0);
        this.skip = 0;
        this.failed = false;
    }

    push(chunk) {
        if (this.failed) return;
        if (this.skip > 0) {
            const dropped = Math.min(this.skip, chunk.length);
            this.skip -= dropped;
            chunk = chunk.subarray(dropped);
            if (chunk.length === 0) return;
        }
        this.buffer = this.buffer.length
            ? Buffer.concat([this.buffer, chunk])
            : chunk;
        while (this.buffer.length >= 4 && !this.failed) {
            const kind = this.buffer[0] & 0xc0;
            let total;
            let content;
            let oversize = false;
            if (kind === 0x00) {
                if (
                    this.buffer.length >= 8 &&
                    this.buffer.readUInt32BE(4) !== MAGIC_COOKIE
                )
                    return this.fail();
                const length = this.buffer.readUInt16BE(2);
                if (length % 4 !== 0) return this.fail();
                total = 20 + length;
                content = total;
                oversize = total > MAX_REQUEST_BYTES;
                if (!oversize && this.buffer.length < 20) break;
            } else if (kind === 0x40) {
                if (this.buffer.readUInt16BE(0) > 0x4fff) return this.fail();
                const length = this.buffer.readUInt16BE(2);
                content = 4 + length;
                total = content + pad4(length);
                oversize = length > 1500;
            } else {
                return this.fail();
            }
            if (oversize) {
                this.onOversize();
                if (this.buffer.length >= total) {
                    this.buffer = this.buffer.subarray(total);
                } else {
                    this.skip = total - this.buffer.length;
                    this.buffer = Buffer.alloc(0);
                }
                continue;
            }
            if (this.buffer.length < total) break;
            const message = this.buffer.subarray(0, content);
            this.buffer = this.buffer.subarray(total);
            this.onMessage(message);
        }
        // A backstop: the largest message kept is 2,048 bytes, so only a smaller test limit reaches it.
        if (this.buffer.length > this.maxUnparsed) this.fail();
    }

    fail() {
        this.failed = true;
        this.onError();
    }
}

/** Feeds bytes already read (a PROXY header's remainder, the sniffed byte) back in front of a socket. */
class PrefixedStream extends Duplex {
    constructor(socket, prefix) {
        super();
        this.socket = socket;
        if (prefix.length) this.push(prefix);
        socket.on('data', (chunk) => {
            if (!this.push(chunk)) socket.pause();
        });
        socket.on('end', () => this.push(null));
        socket.on('close', () => this.destroy());
        socket.on('error', (error) => this.destroy(error));
    }

    _read() {
        this.socket.resume();
    }

    _write(chunk, encoding, callback) {
        this.socket.write(chunk, encoding, callback);
    }

    _final(callback) {
        this.socket.end();
        callback();
    }

    _destroy(error, callback) {
        this.socket.destroy();
        callback(error);
    }
}

/** UDP listener: one message per datagram. */
export function startUdp(entry, { core, clock }) {
    return new Promise((resolve, reject) => {
        const family = entry.host.includes(':') ? 6 : 4;
        const socket = createSocket(
            family === 6 ? { type: 'udp6', ipv6Only: true } : { type: 'udp4' },
        );
        const contexts = new Map();
        const listener = {
            transport: 'udp',
            entry,
            socket,
            ok: true,
            port: 0,
            contexts,
        };
        socket.on('message', (message, rinfo) => {
            if (message.length > MAX_REQUEST_BYTES) return;
            const key = `${rinfo.address}|${rinfo.port}`;
            let ctx = contexts.get(key);
            if (!ctx) {
                const clientIp = normalizeIp(rinfo.address);
                if (!clientIp) return;
                ctx = {
                    transport: 'udp',
                    tupleKey: `udp|${entry.host}|${listener.port}|${clientIp}|${rinfo.port}`,
                    clientIp,
                    clientPort: rinfo.port,
                    stream: false,
                    allowed: clientAllowed(clientIp, entry.loopback),
                    send: (buffer) => {
                        socket.send(buffer, rinfo.port, rinfo.address);
                        return true;
                    },
                    close: () => {},
                    lastSeen: 0,
                };
                if (contexts.size < MAX_UDP_CONTEXTS) contexts.set(key, ctx);
            }
            ctx.lastSeen = clock.now();
            core.receive(ctx, message);
        });
        socket.once('error', reject);
        socket.bind(
            { port: entry.port, address: entry.host, exclusive: true },
            () => {
                socket.off('error', reject);
                socket.on('error', () => {
                    listener.ok = false;
                });
                listener.port = socket.address().port;
                listener.sweep = (now) => {
                    for (const [key, ctx] of contexts) {
                        if (
                            now - ctx.lastSeen > 60_000 &&
                            !core.hasAllocation(ctx.tupleKey)
                        )
                            contexts.delete(key);
                    }
                };
                listener.close = () =>
                    new Promise((done) => {
                        try {
                            socket.close(done);
                        } catch {
                            done();
                        }
                    });
                resolve(listener);
            },
        );
    });
}

/** TCP, TLS or tcp+tls listener. `tls()` returns the current secure context (it changes on SIGHUP). */
export function startStream(
    entry,
    { core, clock, config, tls, allocationLog, ops },
) {
    const connections = new Set();
    const perIp = new Map();
    let nextId = 1;
    const listener = {
        transport: entry.transport,
        entry,
        ok: true,
        port: 0,
        connections,
    };

    const security = (reason, clientIp) => {
        if (core.securityOnce.allow(`${reason}:${clientIp}`, clock.now())) {
            allocationLog.write('rate_limit', {
                reason,
                client_ip: clientIp,
                transport: entry.transport,
            });
            ops.log('warn', 'capacity', { reason });
        }
    };

    const server = createServer(
        { pauseOnConnect: false, noDelay: true },
        (socket) => {
            socket.on('error', () => {});
            const peer = normalizeIp(socket.remoteAddress ?? '');
            if (!peer || connections.size >= config.maxConnections) {
                if (peer) security('connections_total', peer);
                socket.destroy();
                return;
            }
            if (entry.proxy && !config.trustedProxies.containsText(peer)) {
                socket.destroy();
                return;
            }
            const conn = {
                id: nextId++,
                socket,
                out: socket,
                state: entry.proxy
                    ? 'proxy'
                    : entry.transport === 'tcp+tls'
                      ? 'sniff'
                      : entry.transport === 'tls'
                        ? 'tls'
                        : 'stream',
                connectedAt: clock.now(),
                tlsStartedAt: null,
                secure: false,
                raw: Buffer.alloc(0),
                ctx: null,
                countedIp: null,
            };
            connections.add(conn);

            const admit = (clientIp, clientPort) => {
                if (!clientAllowed(clientIp, entry.loopback)) return false;
                const count = perIp.get(clientIp) ?? 0;
                if (count >= config.maxConnectionsPerIp) {
                    security('connections_per_ip', clientIp);
                    return false;
                }
                perIp.set(clientIp, count + 1);
                conn.countedIp = clientIp;
                conn.ctx = {
                    transport: 'tcp',
                    tupleKey: `${entry.transport}|${listener.port}|${conn.id}`,
                    clientIp,
                    clientPort,
                    stream: true,
                    allowed: true,
                    closed: false,
                    send: (buffer, { data = false } = {}) => {
                        const out = conn.out;
                        if (out.destroyed || conn.ctx.closed) return false;
                        if (data && out.writableLength > MAX_DATA_BACKLOG)
                            return false;
                        if (
                            !data &&
                            out.writableLength > MAX_RESPONSE_BACKLOG
                        ) {
                            out.destroy();
                            return false;
                        }
                        out.write(buffer);
                        return true;
                    },
                    close: (destroy) => {
                        if (destroy) conn.out.destroy();
                        else conn.out.end();
                    },
                };
                return true;
            };

            const framer = new StreamFramer({
                onMessage: (message) => core.receive(conn.ctx, message),
                onError: () => conn.out.destroy(),
                onOversize: () => core.metrics.drops.size++,
            });

            const startTls = (prefix) => {
                conn.state = 'tls';
                conn.tlsStartedAt = clock.now();
                socket.removeAllListeners('data');
                const transport =
                    prefix === null
                        ? socket
                        : new PrefixedStream(socket, prefix);
                const secure = new TLSSocket(transport, {
                    isServer: true,
                    secureContext: tls(),
                    requestCert: false,
                });
                conn.out = secure;
                conn.ctx.transport = 'tls';
                secure.on('error', () => secure.destroy());
                secure.on('secure', () => {
                    conn.secure = true;
                    try {
                        secure.disableRenegotiation?.();
                    } catch {
                        // Not every build exposes it; SSL_OP_NO_RENEGOTIATION is set on the context too.
                    }
                });
                secure.on('data', (chunk) => {
                    conn.secure = true;
                    conn.state = 'stream';
                    framer.push(chunk);
                });
                secure.on('close', () => socket.destroy());
            };

            const proceed = (rest) => {
                if (conn.state === 'proxy') {
                    const taken = takeProxyHeader(rest);
                    if (taken === 'wait') {
                        conn.raw = rest;
                        return;
                    }
                    if (
                        !taken ||
                        !admit(taken.header.address, taken.header.port)
                    ) {
                        socket.destroy();
                        return;
                    }
                    rest = taken.rest;
                    // A second PROXY header is refused (spec 8).
                    if (rest.length && rest[0] === 0x50) {
                        socket.destroy();
                        return;
                    }
                    conn.state =
                        entry.transport === 'tcp+tls'
                            ? 'sniff'
                            : entry.transport === 'tls'
                              ? 'tls'
                              : 'stream';
                    if (conn.state === 'tls') return startTls(rest);
                }
                if (conn.state === 'sniff') {
                    if (rest.length === 0) return;
                    if (rest[0] === 22) return startTls(rest);
                    if (rest[0] > 3) {
                        socket.destroy();
                        return;
                    }
                    conn.state = 'stream';
                }
                if (conn.state === 'stream') {
                    conn.raw = Buffer.alloc(0);
                    if (rest.length) framer.push(rest);
                }
            };

            if (!entry.proxy && !admit(peer, socket.remotePort)) {
                connections.delete(conn);
                socket.destroy();
                return;
            }
            socket.on('close', () => {
                connections.delete(conn);
                if (conn.countedIp) {
                    const count = (perIp.get(conn.countedIp) ?? 1) - 1;
                    if (count > 0) perIp.set(conn.countedIp, count);
                    else perIp.delete(conn.countedIp);
                }
                if (conn.ctx) core.connectionClosed(conn.ctx);
            });
            if (conn.state === 'tls') {
                startTls(null);
                return;
            }
            socket.on('data', (chunk) => {
                if (conn.state === 'stream') {
                    framer.push(chunk);
                    return;
                }
                proceed(
                    conn.raw.length ? Buffer.concat([conn.raw, chunk]) : chunk,
                );
            });
        },
    );
    server.on('error', () => {
        listener.ok = false;
    });

    listener.sweep = (now) => {
        const { proxyHeaderMs, tlsHandshakeMs, idleConnectionMs } =
            config.timings;
        for (const conn of connections) {
            const late =
                (conn.state === 'proxy' &&
                    now - conn.connectedAt >= proxyHeaderMs) ||
                (conn.tlsStartedAt !== null &&
                    !conn.secure &&
                    now - conn.tlsStartedAt >= tlsHandshakeMs) ||
                (now - conn.connectedAt >= idleConnectionMs &&
                    !(conn.ctx && core.hasAllocation(conn.ctx.tupleKey)));
            if (late) conn.out.destroy();
        }
    };
    listener.close = () =>
        new Promise((done) => {
            server.close(() => done());
            for (const conn of connections) conn.out.destroy();
        });

    return new Promise((resolve, reject) => {
        server.once('error', reject);
        const ipv6Only = entry.host.includes(':');
        server.listen(
            { port: entry.port, host: entry.host, ipv6Only, exclusive: true },
            () => {
                server.off('error', reject);
                listener.port = server.address().port;
                listener.server = server;
                resolve(listener);
            },
        );
    });
}
