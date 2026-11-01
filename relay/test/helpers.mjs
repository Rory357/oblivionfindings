// Test tools for Oblivion Relay: a fake clock, a relay on loopback with temporary directories,
// credentials minted independently of the relay's own code, and a small TURN client over UDP,
// TCP or TLS that can also send bytes no browser would.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { createSocket } from 'node:dgram';
import {
    mkdtempSync,
    readdirSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { request } from 'node:http';
import { connect } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { connect as tlsConnect } from 'node:tls';
import { readConfig } from '../lib/config.mjs';
import { StreamFramer } from '../lib/listeners.mjs';
import { startRelay } from '../lib/relay.mjs';
import {
    Attr,
    Class,
    decode,
    decodeErrorCode,
    decodeXorAddress,
    encode,
    encodeChannelData,
    encodeXorAddress,
    first,
    Method,
    u32,
} from '../lib/stun.mjs';

// The shared known-answer vector (spec 3.1).
export const KAV = Object.freeze({
    secret: 'oblivion-relay-test-secret-0123456789',
    username:
        '1798761600:obli-chat-b0d51c58c8b9c1f458fadf16c7d375630ef51da4df81915893b05c0fa4ed8bc6',
    realm: 'turn.example.org',
    password: 'mnNManMB1KVphfDcfsHwp6Ly9LI=',
    key: '77ab678c8fe33bbc924f657e74cf8b14',
});
// The control-channel vector (spec 3.7).
export const CONTROL_KAV = Object.freeze({
    secret: 'oblivion-relay-control-test-0123456789',
    keyId: 'k1',
    method: 'POST',
    path: '/control/revoke',
    timestamp: '1798761600',
    nonce: '00112233445566778899aabbccddeeff',
    body: '{"revocations":[{"id":"obli-chat-b0d51c58c8b9c1f458fadf16c7d375630ef51da4df81915893b05c0fa4ed8bc6","expires_up_to":1798765200}],"reason":"offboarded"}',
    signature: 'ORL-3hdG48NXinOxKS55SnorOgyU3fULooysZTgFcOw',
});

export const SECRET = KAV.secret;
export const PREVIOUS_SECRET = 'oblivion-relay-previous-secret-abcdefghij';
export const CONTROL_SECRET = CONTROL_KAV.secret;
export const CONTROL_PREVIOUS_SECRET =
    'oblivion-relay-control-previous-0123456789';
export const REALM = KAV.realm;
export const PORT_MIN = 41_000;
export const PORT_MAX = 41_999;

export class FakeClock {
    // Whole seconds, as credential expiries are, so lifetimes come out exact.
    constructor(start = Math.floor(Date.now() / 1000) * 1000) {
        this.ms = start;
    }

    now() {
        return this.ms;
    }

    advance(ms) {
        this.ms += ms;
    }

    seconds() {
        return Math.floor(this.ms / 1000);
    }
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function waitFor(check, { timeoutMs = 2000, stepMs = 5 } = {}) {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        const value = await check();
        if (value) return value;
        if (Date.now() > deadline) throw new Error('Timed out waiting');
        await sleep(stepMs);
    }
}

export function tempDir(prefix = 'oblivion-relay-') {
    return mkdtempSync(join(tmpdir(), prefix));
}

export function baseEnv(overrides = {}) {
    return {
        RELAY_REALM: REALM,
        RELAY_LISTEN: 'udp://127.0.0.1:3478',
        RELAY_LISTEN_IP: '127.0.0.1',
        RELAY_EXTERNAL_IP: '127.0.0.1',
        RELAY_PORT_MIN: String(PORT_MIN),
        RELAY_PORT_MAX: String(PORT_MAX),
        RELAY_CHAT_SECRET: SECRET,
        RELAY_CONTROL_SECRET: CONTROL_SECRET,
        RELAY_CONTROL_KEY_ID: CONTROL_KAV.keyId,
        RELAY_LOG_DIR: '.',
        RELAY_STATE_DIR: '.',
        // Every test client is 127.0.0.1 and the fake clock stands still, so the per-address
        // limits are lifted here; the limits tests set them back explicitly.
        RELAY_UNAUTH_PER_IP_PER_SECOND: '100000',
        RELAY_UNAUTH_PER_SECOND: '1000000',
        RELAY_AUTH_FAILURES_PER_MINUTE: '100000',
        ...overrides,
    };
}

/**
 * Start a relay on loopback with ephemeral ports. `listeners` is a list such as
 * [{ transport: 'udp' }, { transport: 'tcp', proxy: true }].
 */
export async function startTestRelay({
    env = {},
    listeners = [{ transport: 'udp' }],
    clock = new FakeClock(),
    timings = {},
    overrides = {},
    peerPolicy = null,
    logDir = tempDir('relay-log-'),
    stateDir = tempDir('relay-state-'),
} = {}) {
    const config = readConfig(
        baseEnv({ RELAY_LOG_DIR: logDir, RELAY_STATE_DIR: stateDir, ...env }),
        {
            listeners: listeners.map((entry) => ({
                host: '127.0.0.1',
                port: 0,
                proxy: false,
                loopback: true,
                ...entry,
            })),
            healthListen: { host: '127.0.0.1', port: 0 },
            controlListen: { host: '127.0.0.1', port: 0 },
            timings: { sweepMs: 60_000, closeMs: 500, ...timings },
            ...overrides,
        },
    );
    const opsLines = [];
    const relay = await startRelay(config, {
        clock,
        opsWrite: (line) => opsLines.push(line),
        peerPolicy,
    });
    return {
        relay,
        clock,
        config,
        logDir,
        stateDir,
        opsLines,
        allocationLines() {
            return readdirSync(logDir)
                .filter((name) => name.endsWith('.jsonl'))
                .flatMap((name) =>
                    readFileSync(join(logDir, name), 'utf8')
                        .split('\n')
                        .filter(Boolean)
                        .map((line) => JSON.parse(line)),
                );
        },
        async close() {
            await relay.close();
            for (const dir of [logDir, stateDir])
                rmSync(dir, { recursive: true, force: true });
        },
    };
}

// ---- Credentials, minted the way the app does (spec 3.6) -----------------------------------------

export function opaqueId(seed = randomBytes(8).toString('hex'), tag = 'chat') {
    return `obli-${tag}-${createHash('sha256').update(seed).digest('hex')}`;
}

export function credential({ expiry, id = opaqueId(), secret = SECRET } = {}) {
    const username = `${expiry}:${id}`;
    const password = createHmac('sha1', secret)
        .update(username)
        .digest('base64');
    return { username, password, id, expiry };
}

export function md5Key(username, realm, password) {
    return createHash('md5')
        .update(`${username}:${realm}:${password}`)
        .digest();
}

// ---- Control channel ---------------------------------------------------------------------------

export function controlRequest(
    port,
    method,
    path,
    body = '',
    {
        secret = CONTROL_SECRET,
        keyId = CONTROL_KAV.keyId,
        timestamp,
        nonce = randomBytes(16).toString('hex'),
        signature,
        headers = {},
        signPath = path,
        signMethod = method,
        contentType = 'application/json',
    } = {},
) {
    const raw = typeof body === 'string' ? body : JSON.stringify(body);
    const ts = String(timestamp ?? Math.floor(Date.now() / 1000));
    const sig =
        signature ??
        createHmac('sha256', secret)
            .update(
                `${signMethod}\n${signPath}\n${keyId}\n${ts}\n${nonce}\n${raw}`,
            )
            .digest('base64url');
    const all = {
        'Content-Type': contentType,
        'Content-Length': Buffer.byteLength(raw),
        'X-Relay-Key': keyId,
        'X-Relay-Timestamp': ts,
        'X-Relay-Nonce': nonce,
        'X-Relay-Signature': sig,
        ...headers,
    };
    for (const [name, value] of Object.entries(all))
        if (value === null) delete all[name];
    return httpRequest(port, method, path, raw, all);
}

export function httpRequest(port, method, path, raw = '', headers = {}) {
    return new Promise((resolve, reject) => {
        const outgoing = request(
            { host: '127.0.0.1', port, method, path, headers },
            (response) => {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () => {
                    const text = Buffer.concat(chunks).toString('utf8');
                    let body = null;
                    try {
                        body = text ? JSON.parse(text) : null;
                    } catch {
                        body = text;
                    }
                    resolve({ status: response.statusCode, body, text });
                });
            },
        );
        outgoing.on('error', reject);
        outgoing.end(raw);
    });
}

// ---- A TURN client ------------------------------------------------------------------------------

export const txid = () => randomBytes(12);

export class TurnClient {
    /** transport: 'udp' | 'tcp' | 'tls'; for streams, `prefix` is written first (a PROXY line). */
    static async open(
        port,
        {
            transport = 'udp',
            prefix = null,
            tlsOptions = {},
            host = '127.0.0.1',
        } = {},
    ) {
        const client = new TurnClient(transport);
        if (transport === 'udp') {
            const socket = createSocket('udp4');
            await new Promise((resolve) =>
                socket.bind(0, '127.0.0.1', resolve),
            );
            socket.on('message', (message) => client.receive(message));
            socket.unref();
            client.socket = socket;
            client.write = (buffer) => socket.send(buffer, port, host);
            client.localPort = socket.address().port;
        } else {
            const raw = connect(port, host);
            await new Promise((resolve, reject) => {
                raw.once('connect', resolve);
                raw.once('error', reject);
            });
            raw.on('error', () => {});
            raw.unref();
            client.localPort = raw.localPort;
            if (prefix) raw.write(prefix);
            let stream = raw;
            if (transport === 'tls') {
                stream = tlsConnect({
                    socket: raw,
                    rejectUnauthorized: false,
                    servername: 'relay.test',
                    ...tlsOptions,
                });
                await new Promise((resolve, reject) => {
                    stream.once('secureConnect', resolve);
                    stream.once('error', reject);
                });
                stream.on('error', () => {});
            }
            const framer = new StreamFramer({
                onMessage: (message) => client.receive(message),
                onError: () => {},
            });
            stream.on('data', (chunk) => framer.push(chunk));
            stream.on('close', () => {
                client.closed = true;
                for (const waiter of client.closeWaiters.splice(0)) waiter();
            });
            client.socket = stream;
            client.raw = raw;
            client.write = (buffer) => stream.write(buffer);
        }
        return client;
    }

    constructor(transport) {
        this.transport = transport;
        this.pending = new Map();
        this.inbox = [];
        this.waiters = [];
        this.closeWaiters = [];
        this.closed = false;
        this.key = null;
        this.realm = null;
        this.nonce = null;
        this.username = null;
    }

    receive(buffer) {
        if ((buffer[0] & 0xc0) === 0x40) {
            this.deliver({
                kind: 'channel',
                channel: buffer.readUInt16BE(0),
                data: buffer.subarray(4, 4 + buffer.readUInt16BE(2)),
            });
            return;
        }
        const message = decode(buffer);
        if (!message) return;
        if (
            message.cls === Class.INDICATION &&
            message.method === Method.DATA
        ) {
            const peer = decodeXorAddress(
                first(message, Attr.XOR_PEER_ADDRESS).value,
                message.transactionId,
            );
            this.deliver({
                kind: 'data',
                peer,
                data: first(message, Attr.DATA).value,
            });
            return;
        }
        const key = message.transactionId.toString('hex');
        const waiter = this.pending.get(key);
        if (waiter) {
            this.pending.delete(key);
            waiter(message);
        }
    }

    deliver(item) {
        const waiter = this.waiters.shift();
        if (waiter) waiter(item);
        else this.inbox.push(item);
    }

    /** The next relayed packet (Data indication or ChannelData), or null after `timeoutMs`. */
    next(timeoutMs = 1000) {
        if (this.inbox.length) return Promise.resolve(this.inbox.shift());
        return new Promise((resolve) => {
            const waiter = (item) => {
                clearTimeout(timer);
                resolve(item);
            };
            const timer = setTimeout(() => {
                this.waiters.splice(this.waiters.indexOf(waiter), 1);
                resolve(null);
            }, timeoutMs);
            this.waiters.push(waiter);
        });
    }

    /** Send raw bytes and wait for the response to `transactionId`, or null after `timeoutMs`. */
    exchange(bytes, transactionId, timeoutMs = 1000) {
        return new Promise((resolve) => {
            const key = transactionId.toString('hex');
            const timer = setTimeout(() => {
                this.pending.delete(key);
                resolve(null);
            }, timeoutMs);
            this.pending.set(key, (message) => {
                clearTimeout(timer);
                resolve(message);
            });
            this.write(bytes);
        });
    }

    /** Build and send a request. `auth` false sends no integrity; otherwise the client's key. */
    request(
        method,
        attributes = [],
        {
            auth = true,
            transactionId = txid(),
            fingerprint = false,
            integrity,
            timeoutMs,
            retryStale = true,
        } = {},
    ) {
        const all = [...attributes];
        if (auth && this.key) {
            all.push(
                {
                    type: Attr.USERNAME,
                    value: Buffer.from(this.username, 'latin1'),
                },
                { type: Attr.REALM, value: Buffer.from(this.realm, 'latin1') },
                { type: Attr.NONCE, value: Buffer.from(this.nonce, 'latin1') },
            );
        }
        const bytes = encode(
            { method, cls: Class.REQUEST, transactionId, attributes: all },
            { key: auth && this.key ? this.key : null, integrity, fingerprint },
        );
        return this.exchange(bytes, transactionId, timeoutMs).then(
            (response) => {
                if (response) this.learn(response);
                // A stale nonce is normal after time moves on: retry once with the fresh one.
                if (
                    response &&
                    auth &&
                    this.key &&
                    retryStale &&
                    errorCode(response) === 438
                ) {
                    // The same transaction ID: XOR-encoded addresses in `attributes` depend on it.
                    return this.request(method, attributes, {
                        auth,
                        transactionId,
                        fingerprint,
                        integrity,
                        timeoutMs,
                        retryStale: false,
                    });
                }
                return response;
            },
        );
    }

    learn(response) {
        const realm = first(response, Attr.REALM);
        const nonce = first(response, Attr.NONCE);
        if (realm) this.realm = realm.value.toString('latin1');
        if (nonce) this.nonce = nonce.value.toString('latin1');
    }

    /** The usual Allocate: challenge first, then authenticated. Returns the final response. */
    async allocate({
        username,
        password,
        attributes = [
            {
                type: Attr.REQUESTED_TRANSPORT,
                value: Buffer.from([17, 0, 0, 0]),
            },
        ],
        lifetime,
        options = {},
    }) {
        const challenge = await this.request(Method.ALLOCATE, attributes, {
            auth: false,
        });
        if (!challenge) return null;
        this.username = username;
        this.key = md5Key(username, this.realm, password);
        const extra =
            lifetime === undefined
                ? []
                : [{ type: Attr.LIFETIME, value: u32(lifetime) }];
        return this.request(
            Method.ALLOCATE,
            [...attributes, ...extra],
            options,
        );
    }

    createPermission(peers, options = {}) {
        const transactionId = options.transactionId ?? txid();
        return this.request(
            Method.CREATE_PERMISSION,
            peers.map(({ address, port = 0 }) => ({
                type: Attr.XOR_PEER_ADDRESS,
                value: encodeXorAddress(address, port, transactionId),
            })),
            { ...options, transactionId },
        );
    }

    channelBind(number, address, port, options = {}) {
        const transactionId = options.transactionId ?? txid();
        const value = Buffer.alloc(4);
        value.writeUInt16BE(number, 0);
        return this.request(
            Method.CHANNEL_BIND,
            [
                { type: Attr.CHANNEL_NUMBER, value },
                {
                    type: Attr.XOR_PEER_ADDRESS,
                    value: encodeXorAddress(address, port, transactionId),
                },
            ],
            { ...options, transactionId },
        );
    }

    refresh(lifetime, options = {}) {
        return this.request(
            Method.REFRESH,
            lifetime === undefined
                ? []
                : [{ type: Attr.LIFETIME, value: u32(lifetime) }],
            options,
        );
    }

    send(address, port, data, extra = []) {
        const transactionId = txid();
        this.write(
            encode({
                method: Method.SEND,
                cls: Class.INDICATION,
                transactionId,
                attributes: [
                    {
                        type: Attr.XOR_PEER_ADDRESS,
                        value: encodeXorAddress(address, port, transactionId),
                    },
                    { type: Attr.DATA, value: data },
                    ...extra,
                ],
            }),
        );
    }

    sendChannel(number, data) {
        this.write(encodeChannelData(number, data, this.transport !== 'udp'));
    }

    waitForClose(timeoutMs = 2000) {
        if (this.closed) return Promise.resolve(true);
        return new Promise((resolve) => {
            const timer = setTimeout(() => resolve(false), timeoutMs);
            this.closeWaiters.push(() => {
                clearTimeout(timer);
                resolve(true);
            });
        });
    }

    close() {
        try {
            if (this.transport === 'udp') this.socket.close();
            else this.socket.destroy();
        } catch {
            // Already closed.
        }
    }
}

export function errorCode(response) {
    if (!response) return null;
    if (response.cls === Class.SUCCESS) return 200;
    return decodeErrorCode(first(response, Attr.ERROR_CODE)?.value);
}

export function relayedAddress(response) {
    return decodeXorAddress(
        first(response, Attr.XOR_RELAYED_ADDRESS).value,
        response.transactionId,
    );
}

export function mappedAddress(response) {
    return decodeXorAddress(
        first(response, Attr.XOR_MAPPED_ADDRESS).value,
        response.transactionId,
    );
}

export function lifetimeOf(response) {
    return first(response, Attr.LIFETIME)?.value.readUInt32BE(0) ?? null;
}

/** A client with a fresh chat credential and a successful UDP (or other) allocation. */
export async function allocated(
    t,
    port,
    {
        transport = 'udp',
        expiry = t.clock.seconds() + 3600,
        id,
        prefix,
        lifetime,
        secret,
    } = {},
) {
    const client = await TurnClient.open(port, { transport, prefix });
    const cred = credential({ expiry, id, secret });
    const response = await client.allocate({
        username: cred.username,
        password: cred.password,
        lifetime,
    });
    if (errorCode(response) !== 200)
        throw new Error(`Allocate failed: ${errorCode(response)}`);
    client.credential = cred;
    client.relayed = relayedAddress(response);
    client.response = response;
    return client;
}

export function writeSecretFile(dir, name, value) {
    const path = join(dir, name);
    writeFileSync(path, value);
    return path;
}
