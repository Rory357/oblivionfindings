// The TURN server core (RFC 8656 with the choices in spec sections 3 to 7). Listeners hand it one
// framed message at a time with a client context; it answers through ctx.send().
//
// A client context is { transport: 'udp' | 'tcp' | 'tls', tupleKey, clientIp, clientPort, stream,
// allowed, send(buffer, { data }) => boolean, close(destroy) }. Over TCP and TLS the tuple is the
// connection itself; clientIp and clientPort come from the PROXY header when there is one.

import { randomBytes, randomInt } from 'node:crypto';
import { createSocket } from 'node:dgram';
import { formatIp, parseIp } from './addresses.mjs';
import {
    idHash,
    longTermKey,
    parseUsername,
    password,
} from './credentials.mjs';
import {
    bandwidthBucket,
    FailureLimiter,
    KeyedBuckets,
    OncePer,
    takeAll,
    TokenBucket,
} from './limits.mjs';
import { NonceIssuer } from './nonce.mjs';
import { PeerRules } from './peers.mjs';
import {
    all,
    Attr,
    Class,
    decode,
    decodeXorAddress,
    encode,
    encodeChannelData,
    encodeErrorCode,
    encodeUnknownAttributes,
    encodeXorAddress,
    first,
    isChannelNumber,
    MAX_REQUEST_BYTES,
    Method,
    MethodName,
    u32,
    unknownRequired,
    verifyIntegrity,
} from './stun.mjs';

const MAX_PAYLOAD = 1500;
const DEFAULT_LIFETIME = 600;
const MIN_LIFETIME = 600;
const NO_INTEGRITY = new Set([400, 401, 438]);

const ipKey = (family, bytes) => `${family}:${bytes.toString('hex')}`;

export function createMetrics() {
    return {
        allocations: { created: 0, ended: {} },
        requests: {},
        relayed: {},
        drops: {
            no_permission: 0,
            peer_refused: 0,
            bandwidth: 0,
            size: 0,
            back_pressure: 0,
            rate_limit: 0,
        },
        unauthenticated: { sent: 0, dropped: 0 },
        refusals: { 401: 0, 403: 0, 438: 0 },
        malformed: 0,
        control: { accepted: 0, refused: 0 },
    };
}

export class RelayCore {
    constructor({
        config,
        clock,
        ops,
        allocationLog,
        metrics,
        peerPolicy = null,
        denyList,
        revocations,
    }) {
        this.config = config;
        this.clock = clock;
        this.ops = ops;
        this.allocationLog = allocationLog;
        this.metrics = metrics;
        this.peerPolicy = peerPolicy;
        this.secrets = config.secrets;
        this.deny = denyList;
        // opaque id -> latest expires_up_to (Unix seconds) the app has revoked (spec 3.7).
        this.held = revocations;
        this.realm = Buffer.from(config.realm, 'latin1');
        this.nonces = new NonceIssuer({ seconds: config.nonceSeconds, clock });
        this.rules = new PeerRules({
            ownPool: [
                config.externalIp,
                config.listenIp,
                config.externalIpv6,
                config.listenIpv6,
            ].filter(
                (address) => address && !/^(0\.0\.0\.0|::)$/.test(address),
            ),
            ownAddresses: config.interfaceAddresses ?? [],
            portMin: config.portMin,
            portMax: config.portMax,
            deniedPorts: config.deniedPeerPorts,
        });
        this.rulesVersion = 1;

        this.allocations = new Map(); // tupleKey -> allocation
        this.byPort = new Map(); // "family:port" -> allocation
        this.byOpaqueId = new Map(); // opaque id -> Set of allocations
        this.perIp = new Map(); // client IP -> allocations
        this.pending = new Map(); // response cache key -> reservation, while an Allocate binds its port
        this.responses = new Map(); // response cache key -> { response, expires }
        this.idBuckets = new Map(); // opaque id -> { up, down, count }

        const now = clock.now();
        const totalBytes = config.totalMbps * 1000;
        this.totalUp = bandwidthBucket(totalBytes, now);
        this.totalDown = bandwidthBucket(totalBytes, now);
        this.unauthTotal = new TokenBucket(
            config.unauthPerSecond,
            config.unauthPerSecond,
            now,
        );
        this.unauthPerIp = new KeyedBuckets(
            config.unauthPerIpPerSecond,
            config.unauthPerIpPerSecond * 2,
        );
        this.failures = new FailureLimiter(config.authFailuresPerMinute);
        this.securityOnce = new OncePer(60_000);
        this.txCounter = 0n;
        this.txPrefix = randomBytes(4);
        this.nextAllocationId = 1;
        this.lastSummary = now;
        this.summary = { 401: 0, 403: 0, 438: 0 };
        this.closed = false;
    }

    // ---- Settings that change on SIGHUP --------------------------------------------------------

    /** New secrets from a reload. Allocations whose secret is gone end at once (spec 3.4). */
    applySecrets(secrets) {
        this.secrets = secrets;
        for (const allocation of [...this.allocations.values()]) {
            const slot = secrets[allocation.cls.name];
            if (
                ![slot?.current, slot?.previous].some(
                    (secret) => secret && secret.equals(allocation.secret),
                )
            ) {
                this.end(allocation, 'secret_removed', { destroy: true });
            }
        }
    }

    /** A new deny list from a reload (spec 3.5). Matching allocations end at once. */
    applyDenyList(deny) {
        this.deny = deny;
        for (const allocation of [...this.allocations.values()]) {
            if (this.denied(allocation.opaqueId, allocation.username))
                this.end(allocation, 'denied', { destroy: true });
        }
    }

    denied(opaqueId, username) {
        return this.deny.ids.has(opaqueId) || this.deny.usernames.has(username);
    }

    classFor(tag) {
        for (const cls of Object.values(this.config.classes)) {
            if (cls.tags.includes(tag))
                return this.secrets[cls.name]?.current ? cls : null;
        }
        return null;
    }

    policyFor(cls) {
        return { peers: cls.peers, allowed: cls.allowed };
    }

    /** Section 6 for one allocation's class. A test-only peerPolicy(family, address, port) may answer first. */
    decide(cls, family, bytes, port) {
        if (this.peerPolicy) {
            const injected = this.peerPolicy(
                family,
                formatIp(family, bytes),
                port,
            );
            if (injected) return injected;
        }
        return this.rules.decide(this.policyFor(cls), family, bytes, port);
    }

    // ---- Entry point ---------------------------------------------------------------------------

    /** One framed message from a client: STUN or ChannelData. */
    receive(ctx, buffer) {
        if (this.closed || !ctx.allowed) return;
        const now = this.clock.now();
        if (this.failures.silenced(ctx.clientIp, now)) return;
        const kind = buffer[0] & 0xc0;
        if (kind === 0x40) {
            this.channelDataFromClient(ctx, buffer, now);
            return;
        }
        if (kind !== 0 || buffer.length > MAX_REQUEST_BYTES) return;
        const message = decode(buffer);
        if (!message) {
            this.metrics.malformed++;
            return;
        }
        if (message.cls === Class.REQUEST) {
            if (message.method === Method.BINDING)
                this.binding(ctx, message, now);
            else this.request(ctx, message, now);
        } else if (
            message.cls === Class.INDICATION &&
            message.method === Method.SEND
        ) {
            this.sendIndication(ctx, message, now);
        }
        // Binding indications (keep-alives) and anything else are ignored.
    }

    // ---- Unauthenticated replies ---------------------------------------------------------------

    binding(ctx, message, now) {
        if (!this.config.stunBinding) return;
        const unknown = unknownRequired(message);
        const response = unknown.length
            ? encode(
                  {
                      method: Method.BINDING,
                      cls: Class.ERROR,
                      transactionId: message.transactionId,
                      attributes: [
                          {
                              type: Attr.ERROR_CODE,
                              value: encodeErrorCode(420),
                          },
                          {
                              type: Attr.UNKNOWN_ATTRIBUTES,
                              value: encodeUnknownAttributes(unknown),
                          },
                      ],
                  },
                  { fingerprint: !!message.fingerprint },
              )
            : encode(
                  {
                      method: Method.BINDING,
                      cls: Class.SUCCESS,
                      transactionId: message.transactionId,
                      attributes: [
                          {
                              type: Attr.XOR_MAPPED_ADDRESS,
                              value: encodeXorAddress(
                                  ctx.clientIp,
                                  ctx.clientPort,
                                  message.transactionId,
                              ),
                          },
                      ],
                  },
                  { fingerprint: !!message.fingerprint },
              );
        this.unauthenticatedReply(
            ctx,
            message,
            response,
            unknown.length ? 420 : 200,
            now,
        );
    }

    /** Spec 7: at most one reply, never more than 4x the request, within the per-IP and total rates. */
    unauthenticatedReply(ctx, message, response, code, now) {
        if (response.length > 4 * message.raw.length) {
            this.metrics.unauthenticated.dropped++;
            return;
        }
        if (
            !this.unauthPerIp.tryTake(ctx.clientIp, now) ||
            !this.unauthTotal.tryTake(1, now)
        ) {
            this.metrics.unauthenticated.dropped++;
            if (this.securityOnce.allow(`unauth:${ctx.clientIp}`, now)) {
                this.allocationLog.write('rate_limit', {
                    reason: 'unauthenticated_replies',
                    client_ip: ctx.clientIp,
                    client_port: ctx.clientPort,
                    transport: ctx.transport,
                });
            }
            return;
        }
        this.metrics.unauthenticated.sent++;
        this.count(message.method, code);
        ctx.send(response, { data: false });
    }

    /** 401 or 438 with REALM and a fresh NONCE, and no integrity (spec 3.3). */
    challenge(ctx, message, code, now) {
        const nonce = this.nonces.issue(
            ctx.transport,
            ctx.clientIp,
            ctx.clientPort,
        );
        const response = encode(
            {
                method: message.method,
                cls: Class.ERROR,
                transactionId: message.transactionId,
                attributes: [
                    { type: Attr.ERROR_CODE, value: encodeErrorCode(code) },
                    { type: Attr.REALM, value: this.realm },
                    { type: Attr.NONCE, value: Buffer.from(nonce, 'latin1') },
                ],
            },
            { fingerprint: !!message.fingerprint },
        );
        this.noteRefusal(code, now);
        if (code === 401) {
            this.unauthenticatedReply(ctx, message, response, code, now);
        } else {
            this.count(message.method, code);
            ctx.send(response, { data: false });
        }
    }

    badRequest(ctx, message, now) {
        const response = encode(
            {
                method: message.method,
                cls: Class.ERROR,
                transactionId: message.transactionId,
                attributes: [
                    { type: Attr.ERROR_CODE, value: encodeErrorCode(400) },
                ],
            },
            { fingerprint: !!message.fingerprint },
        );
        this.unauthenticatedReply(ctx, message, response, 400, now);
    }

    authFailure(ctx, message, now, reason = 'failures') {
        if (
            reason === 'denied_id' &&
            this.securityOnce.allow(`deny:${ctx.clientIp}`, now)
        ) {
            this.allocationLog.write('auth_limit', {
                reason,
                client_ip: ctx.clientIp,
                client_port: ctx.clientPort,
                transport: ctx.transport,
            });
        }
        if (this.failures.fail(ctx.clientIp, now)) {
            this.allocationLog.write('auth_limit', {
                reason: 'failures',
                client_ip: ctx.clientIp,
                client_port: ctx.clientPort,
                transport: ctx.transport,
            });
        }
        this.challenge(ctx, message, 401, now);
    }

    // ---- Authenticated requests (spec 3.3) -----------------------------------------------------

    request(ctx, message, now) {
        const cacheKey = `${ctx.tupleKey}|${message.transactionId.toString('hex')}`;
        const cached = this.responses.get(cacheKey);
        if (cached && cached.expires > now) {
            ctx.send(cached.response, { data: false });
            return;
        }
        if (this.pending.has(cacheKey)) return; // the original is still binding its port

        let allocation = this.allocations.get(ctx.tupleKey) ?? null;
        if (allocation) {
            const due = this.dueToEnd(allocation, now);
            if (due) {
                this.end(allocation, due);
                allocation = null;
            } else if (!allocation.requests.tryTake(1, now)) {
                this.metrics.drops.rate_limit++;
                return;
            }
        }

        // 1. No integrity: challenge.
        if (!message.integrity && !message.integritySha256) {
            this.challenge(ctx, message, 401, now);
            return;
        }
        // 2 and 3.
        const usernameAttr = first(message, Attr.USERNAME);
        const realmAttr = first(message, Attr.REALM);
        const nonceAttr = first(message, Attr.NONCE);
        if (!usernameAttr || !realmAttr || !nonceAttr) {
            this.badRequest(ctx, message, now);
            return;
        }
        if (
            first(message, Attr.USERHASH) ||
            first(message, Attr.PASSWORD_ALGORITHM) ||
            first(message, Attr.PASSWORD_ALGORITHMS)
        ) {
            this.badRequest(ctx, message, now);
            return;
        }
        // 4. Realm and username.
        const credential = parseUsername(usernameAttr.value);
        const cls = credential ? this.classFor(credential.tag) : null;
        if (!realmAttr.value.equals(this.realm) || !credential || !cls) {
            this.authFailure(ctx, message, now);
            return;
        }
        if (this.denied(credential.opaqueId, credential.username)) {
            this.authFailure(ctx, message, now, 'denied_id');
            return;
        }
        const isAllocate = message.method === Method.ALLOCATE;
        if (isAllocate) {
            const seconds = Math.floor(now / 1000);
            const skew = this.config.clockSkewSeconds;
            const held = this.held.get(credential.opaqueId);
            if (
                credential.expiry + skew < seconds ||
                credential.expiry > seconds + cls.maxCredentialSeconds + skew ||
                (held !== undefined && credential.expiry <= held)
            ) {
                this.authFailure(ctx, message, now);
                return;
            }
        }
        // 5. Integrity: the allocation's own key, or the class's secrets.
        const sameUser =
            !!allocation && allocation.username === credential.username;
        let auth = null;
        if (!isAllocate && sameUser) {
            if (this.verify(message, allocation.key))
                auth = { key: allocation.key, secret: allocation.secret };
        } else {
            for (const secret of [
                this.secrets[cls.name].current,
                this.secrets[cls.name].previous,
            ]) {
                if (!secret) continue;
                const key = longTermKey(
                    credential.username,
                    this.config.realm,
                    password(secret, credential.username),
                );
                if (this.verify(message, key)) {
                    auth = { key, secret };
                    break;
                }
            }
        }
        if (!auth) {
            this.authFailure(ctx, message, now);
            return;
        }
        // 6. Nonce.
        if (
            !this.nonces.valid(
                nonceAttr.value.toString('latin1'),
                ctx.transport,
                ctx.clientIp,
                ctx.clientPort,
            )
        ) {
            this.challenge(ctx, message, 438, now);
            return;
        }
        Object.assign(auth, { credential, cls, cacheKey });
        // 7. Allocation checks for everything but Allocate.
        if (!isAllocate) {
            if (!allocation) return this.respond(ctx, message, auth, now, 437);
            if (!sameUser) return this.respond(ctx, message, auth, now, 441);
        }

        switch (message.method) {
            case Method.ALLOCATE:
                return this.allocate(ctx, message, auth, now);
            case Method.REFRESH:
                return this.refresh(ctx, message, auth, allocation, now);
            case Method.CREATE_PERMISSION:
                return this.createPermission(
                    ctx,
                    message,
                    auth,
                    allocation,
                    now,
                );
            case Method.CHANNEL_BIND:
                return this.channelBind(ctx, message, auth, allocation, now);
            default:
                return this.respond(ctx, message, auth, now, 400);
        }
    }

    verify(message, key) {
        return verifyIntegrity(message, key);
    }

    /** A response to an authenticated request: integrity except for 400, 401 and 438; cached 40 s. */
    respond(ctx, message, auth, now, code, attributes = []) {
        const response = encode(
            {
                method: message.method,
                cls: code ? Class.ERROR : Class.SUCCESS,
                transactionId: message.transactionId,
                attributes: code
                    ? [
                          {
                              type: Attr.ERROR_CODE,
                              value: encodeErrorCode(code),
                          },
                          ...attributes,
                      ]
                    : attributes,
            },
            {
                key: code && NO_INTEGRITY.has(code) ? null : auth.key,
                fingerprint: !!message.fingerprint,
            },
        );
        this.cache(auth.cacheKey, response, now);
        if (code === 403) this.noteRefusal(403, now);
        this.count(message.method, code ?? 200);
        ctx.send(response, { data: false });
    }

    cache(key, response, now) {
        this.responses.delete(key);
        this.responses.set(key, {
            response,
            expires: now + this.config.timings.responseCacheMs,
        });
        const limit = this.config.maxAllocations * 4;
        while (this.responses.size > limit)
            this.responses.delete(this.responses.keys().next().value);
    }

    count(method, code) {
        const name = MethodName[method] ?? 'Other';
        const byCode = (this.metrics.requests[name] ??= {});
        byCode[code] = (byCode[code] ?? 0) + 1;
    }

    noteRefusal(code, now) {
        if (this.summary[code] === undefined) return;
        this.summary[code]++;
        this.metrics.refusals[code]++;
        this.flushSummary(now);
    }

    /** Normal ICE refusals are counted, with at most one summary line a minute (spec 9). */
    flushSummary(now) {
        if (now - this.lastSummary < 60_000) return;
        const counts = this.summary;
        if (counts[401] + counts[403] + counts[438] > 0)
            this.ops.log('info', 'refusals', { counts: { ...counts } });
        this.summary = { 401: 0, 403: 0, 438: 0 };
        this.lastSummary = now;
    }

    // ---- Allocate (spec 5.2) -------------------------------------------------------------------

    lifetime(message, hardEndMs, now) {
        const attribute = first(message, Attr.LIFETIME);
        let seconds =
            attribute && attribute.value.length === 4
                ? attribute.value.readUInt32BE(0)
                : DEFAULT_LIFETIME;
        seconds = Math.max(
            MIN_LIFETIME,
            Math.min(seconds, this.config.maxLifetimeSeconds),
        );
        return Math.min(seconds, Math.floor((hardEndMs - now) / 1000));
    }

    async allocate(ctx, message, auth, now) {
        const { credential, cls } = auth;
        if (
            this.allocations.has(ctx.tupleKey) ||
            [...this.pending.values()].some(
                (entry) => entry.tupleKey === ctx.tupleKey,
            )
        ) {
            return this.respond(ctx, message, auth, now, 437);
        }
        const transport = first(message, Attr.REQUESTED_TRANSPORT);
        if (!transport || transport.value.length !== 4)
            return this.respond(ctx, message, auth, now, 400);
        if (transport.value[0] !== 17)
            return this.respond(ctx, message, auth, now, 442);
        const unknown = unknownRequired(message);
        if (unknown.length) {
            return this.respond(ctx, message, auth, now, 420, [
                {
                    type: Attr.UNKNOWN_ATTRIBUTES,
                    value: encodeUnknownAttributes(unknown),
                },
            ]);
        }
        const token = first(message, Attr.RESERVATION_TOKEN);
        const even = first(message, Attr.EVEN_PORT);
        const requested = first(message, Attr.REQUESTED_ADDRESS_FAMILY);
        const additional = first(message, Attr.ADDITIONAL_ADDRESS_FAMILY);
        if (
            (even && even.value.length !== 1) ||
            (requested && requested.value.length !== 4) ||
            (additional && additional.value.length !== 4)
        ) {
            return this.respond(ctx, message, auth, now, 400);
        }
        if (token)
            return this.respond(
                ctx,
                message,
                auth,
                now,
                even || requested || additional ? 400 : 508,
            );
        if (even && even.value[0] & 0x80)
            return this.respond(ctx, message, auth, now, 508);
        if (requested && additional)
            return this.respond(ctx, message, auth, now, 400);
        if (additional && additional.value[0] !== 0x02)
            return this.respond(ctx, message, auth, now, 400);
        let families = [4];
        let addressError = false;
        if (requested) {
            if (requested.value[0] === 0x02) {
                if (!this.config.ipv6)
                    return this.respond(ctx, message, auth, now, 440);
                families = [6];
            } else if (requested.value[0] !== 0x01) {
                return this.respond(ctx, message, auth, now, 440);
            }
        }
        if (additional) {
            if (this.config.ipv6) families = [4, 6];
            else addressError = true;
        }

        // Quotas (spec 7): per credential first, then per address and in total.
        const pendingFor = (test) =>
            [...this.pending.values()].filter(test).length;
        const forId =
            (this.byOpaqueId.get(credential.opaqueId)?.size ?? 0) +
            pendingFor((entry) => entry.opaqueId === credential.opaqueId);
        if (forId >= cls.maxAllocations)
            return this.respond(ctx, message, auth, now, 486);
        const forIp =
            (this.perIp.get(ctx.clientIp) ?? 0) +
            pendingFor((entry) => entry.clientIp === ctx.clientIp);
        if (
            forIp >= this.config.maxAllocationsPerIp ||
            this.allocations.size + this.pending.size >=
                this.config.maxAllocations
        ) {
            this.capacity(now, 'allocations');
            return this.respond(ctx, message, auth, now, 508);
        }
        const hardEnd = (credential.expiry + cls.graceSeconds) * 1000;
        const lifetime = this.lifetime(message, hardEnd, now);
        if (lifetime <= 0) {
            this.authFailure(ctx, message, now);
            return;
        }

        this.pending.set(auth.cacheKey, {
            tupleKey: ctx.tupleKey,
            opaqueId: credential.opaqueId,
            clientIp: ctx.clientIp,
        });
        let bound;
        try {
            bound = await this.bindRelayed(families, !!even);
        } catch {
            this.pending.delete(auth.cacheKey);
            this.capacity(this.clock.now(), 'ports');
            return this.respond(ctx, message, auth, this.clock.now(), 508);
        }
        this.pending.delete(auth.cacheKey);
        const after = this.clock.now();
        const held = this.held.get(credential.opaqueId);
        // The world may have moved on while the port was binding.
        if (
            this.closed ||
            ctx.closed ||
            this.denied(credential.opaqueId, credential.username) ||
            (held !== undefined && credential.expiry <= held) ||
            ![
                this.secrets[cls.name]?.current,
                this.secrets[cls.name]?.previous,
            ].some((secret) => secret && secret.equals(auth.secret))
        ) {
            for (const socket of Object.values(bound.sockets)) socket.close();
            this.releasePort(families, bound.port);
            if (!this.closed && !ctx.closed)
                this.authFailure(ctx, message, after);
            return;
        }

        const allocation = this.register(
            ctx,
            auth,
            families,
            bound,
            lifetime,
            hardEnd,
            after,
        );
        const attributes = families.map((family) => ({
            type: Attr.XOR_RELAYED_ADDRESS,
            value: encodeXorAddress(
                family === 4
                    ? this.config.externalIp
                    : this.config.externalIpv6,
                bound.port,
                message.transactionId,
            ),
        }));
        attributes.push(
            {
                type: Attr.XOR_MAPPED_ADDRESS,
                value: encodeXorAddress(
                    ctx.clientIp,
                    ctx.clientPort,
                    message.transactionId,
                ),
            },
            { type: Attr.LIFETIME, value: u32(lifetime) },
        );
        if (addressError)
            attributes.push({
                type: Attr.ADDRESS_ERROR_CODE,
                value: encodeErrorCode(440, 6),
            });
        this.respond(ctx, message, auth, after, null, attributes);
        return allocation;
    }

    capacity(now, reason) {
        if (this.securityOnce.allow(`capacity:${reason}`, now))
            this.ops.log('warn', 'capacity', { reason });
    }

    register(ctx, auth, families, bound, lifetime, hardEnd, now) {
        const { credential, cls } = auth;
        const allocation = {
            id: this.nextAllocationId++,
            ctx,
            tupleKey: ctx.tupleKey,
            username: credential.username,
            opaqueId: credential.opaqueId,
            idHash: idHash(credential.opaqueId),
            cls,
            key: auth.key,
            secret: auth.secret,
            credentialExpiry: credential.expiry,
            hardEnd,
            expiresAt: now + lifetime * 1000,
            families,
            port: bound.port,
            sockets: bound.sockets,
            permissions: new Map(),
            channels: new Map(),
            channelByPeer: new Map(),
            reserved: new Map(),
            reservedByPeer: new Map(),
            decisions: new Map(),
            decisionsVersion: this.rulesVersion,
            up: bandwidthBucket(cls.allocationKbps, now),
            down: bandwidthBucket(cls.allocationKbps, now),
            requests: new TokenBucket(50, 50, now),
            bytesIn: 0,
            bytesOut: 0,
            packetsIn: 0,
            packetsOut: 0,
            startedAt: now,
            ended: false,
        };
        this.allocations.set(ctx.tupleKey, allocation);
        for (const family of families)
            this.byPort.set(`${family}:${bound.port}`, allocation);
        let set = this.byOpaqueId.get(credential.opaqueId);
        if (!set) this.byOpaqueId.set(credential.opaqueId, (set = new Set()));
        set.add(allocation);
        this.perIp.set(ctx.clientIp, (this.perIp.get(ctx.clientIp) ?? 0) + 1);
        let shared = this.idBuckets.get(credential.opaqueId);
        if (!shared) {
            shared = {
                up: bandwidthBucket(cls.idKbps, now),
                down: bandwidthBucket(cls.idKbps, now),
                count: 0,
            };
            this.idBuckets.set(credential.opaqueId, shared);
        }
        shared.count++;
        allocation.shared = shared;
        for (const [family, socket] of Object.entries(bound.sockets)) {
            socket.on('message', (payload, rinfo) =>
                this.fromNetwork(allocation, Number(family), payload, rinfo),
            );
        }
        this.metrics.allocations.created++;
        this.allocationLog.write('allocation_start', {
            class: cls.name,
            id: allocation.idHash,
            client_ip: ctx.clientIp,
            client_port: ctx.clientPort,
            transport: ctx.transport,
            relay_port: bound.port,
            lifetime,
        });
        return allocation;
    }

    async bindRelayed(families, even) {
        for (let attempt = 0; attempt < 16; attempt++) {
            const port = this.pickPort(families, even);
            if (port === null) break;
            for (const family of families)
                this.byPort.set(`${family}:${port}`, null);
            const sockets = {};
            try {
                for (const family of families)
                    sockets[family] = await this.bindSocket(family, port);
                return { port, sockets };
            } catch {
                for (const socket of Object.values(sockets)) socket.close();
                this.releasePort(families, port);
                // Another program holds that port; try a different one.
            }
        }
        throw new Error('no relayed port is free');
    }

    pickPort(families, even) {
        const { portMin, portMax } = this.config;
        const size = portMax - portMin + 1;
        const free = (port) =>
            (!even || port % 2 === 0) &&
            families.every((family) => !this.byPort.has(`${family}:${port}`));
        // Random, not in sequence (RFC 8656 21.1.7).
        for (let attempt = 0; attempt < 32; attempt++) {
            const port = portMin + randomInt(size);
            if (free(port)) return port;
        }
        const start = randomInt(size);
        for (let step = 0; step < size; step++) {
            const port = portMin + ((start + step) % size);
            if (free(port)) return port;
        }
        return null;
    }

    releasePort(families, port) {
        for (const family of families) {
            const key = `${family}:${port}`;
            if (this.byPort.get(key) === null) this.byPort.delete(key);
        }
    }

    bindSocket(family, port) {
        return new Promise((resolve, reject) => {
            const socket = createSocket(
                family === 6
                    ? { type: 'udp6', ipv6Only: true }
                    : { type: 'udp4' },
            );
            socket.once('error', reject);
            socket.bind(
                {
                    port,
                    address:
                        family === 6
                            ? this.config.listenIpv6
                            : this.config.listenIp,
                    exclusive: true,
                },
                () => {
                    socket.off('error', reject);
                    // Send errors (an unreachable peer) are never fatal to the relay.
                    socket.on('error', () => {});
                    resolve(socket);
                },
            );
        });
    }

    // ---- Refresh (spec 5.3) --------------------------------------------------------------------

    refresh(ctx, message, auth, allocation, now) {
        const requested = first(message, Attr.REQUESTED_ADDRESS_FAMILY);
        if (requested) {
            if (requested.value.length !== 4)
                return this.respond(ctx, message, auth, now, 400);
            const family =
                requested.value[0] === 0x02
                    ? 6
                    : requested.value[0] === 0x01
                      ? 4
                      : 0;
            if (!allocation.families.includes(family))
                return this.respond(ctx, message, auth, now, 443);
        }
        const unknown = unknownRequired(message);
        if (unknown.length) {
            return this.respond(ctx, message, auth, now, 420, [
                {
                    type: Attr.UNKNOWN_ATTRIBUTES,
                    value: encodeUnknownAttributes(unknown),
                },
            ]);
        }
        const attribute = first(message, Attr.LIFETIME);
        if (
            attribute &&
            attribute.value.length === 4 &&
            attribute.value.readUInt32BE(0) === 0
        ) {
            this.respond(ctx, message, auth, now, null, [
                { type: Attr.LIFETIME, value: u32(0) },
            ]);
            this.end(allocation, 'refresh_zero');
            return;
        }
        const lifetime = this.lifetime(message, allocation.hardEnd, now);
        if (lifetime <= 0) {
            this.end(allocation, 'credential_grace');
            return this.respond(ctx, message, auth, now, 437);
        }
        allocation.expiresAt = now + lifetime * 1000;
        this.respond(ctx, message, auth, now, null, [
            { type: Attr.LIFETIME, value: u32(lifetime) },
        ]);
    }

    // ---- CreatePermission (spec 5.4) and ChannelBind (spec 5.5) ---------------------------------

    createPermission(ctx, message, auth, allocation, now) {
        const unknown = unknownRequired(message);
        if (unknown.length) {
            return this.respond(ctx, message, auth, now, 420, [
                {
                    type: Attr.UNKNOWN_ATTRIBUTES,
                    value: encodeUnknownAttributes(unknown),
                },
            ]);
        }
        const attributes = all(message, Attr.XOR_PEER_ADDRESS);
        if (attributes.length < 1 || attributes.length > 16)
            return this.respond(ctx, message, auth, now, 400);
        const peers = attributes.map((attribute) =>
            decodeXorAddress(attribute.value, message.transactionId),
        );
        if (peers.some((peer) => !peer))
            return this.respond(ctx, message, auth, now, 400);
        if (peers.some((peer) => !allocation.families.includes(peer.family)))
            return this.respond(ctx, message, auth, now, 443);
        if (
            peers.some(
                (peer) =>
                    !this.decide(allocation.cls, peer.family, peer.bytes, null)
                        .ok,
            )
        ) {
            return this.respond(ctx, message, auth, now, 403);
        }
        this.prunePermissions(allocation, now);
        const keys = [
            ...new Set(peers.map((peer) => ipKey(peer.family, peer.bytes))),
        ];
        const added = keys.filter(
            (key) => !allocation.permissions.has(key),
        ).length;
        if (allocation.permissions.size + added > this.config.maxPermissions)
            return this.respond(ctx, message, auth, now, 508);
        for (const key of keys)
            allocation.permissions.set(
                key,
                now + this.config.timings.permissionMs,
            );
        this.respond(ctx, message, auth, now, null);
    }

    channelBind(ctx, message, auth, allocation, now) {
        const unknown = unknownRequired(message);
        if (unknown.length) {
            return this.respond(ctx, message, auth, now, 420, [
                {
                    type: Attr.UNKNOWN_ATTRIBUTES,
                    value: encodeUnknownAttributes(unknown),
                },
            ]);
        }
        const numberAttr = first(message, Attr.CHANNEL_NUMBER);
        const peerAttr = first(message, Attr.XOR_PEER_ADDRESS);
        if (!numberAttr || numberAttr.value.length !== 4 || !peerAttr)
            return this.respond(ctx, message, auth, now, 400);
        const number = numberAttr.value.readUInt16BE(0);
        const peer = decodeXorAddress(peerAttr.value, message.transactionId);
        if (!isChannelNumber(number) || !peer)
            return this.respond(ctx, message, auth, now, 400);
        if (!allocation.families.includes(peer.family))
            return this.respond(ctx, message, auth, now, 443);
        if (
            !this.decide(allocation.cls, peer.family, peer.bytes, peer.port).ok
        ) {
            return this.respond(ctx, message, auth, now, 403);
        }
        this.pruneChannels(allocation, now);
        this.prunePermissions(allocation, now);
        const peerKey = `${ipKey(peer.family, peer.bytes)}:${peer.port}`;
        const bound = allocation.channels.get(number);
        const numberForPeer = allocation.channelByPeer.get(peerKey);
        const reserved = allocation.reserved.get(number);
        const reservedForPeer = allocation.reservedByPeer.get(peerKey);
        if (
            (bound && bound.peerKey !== peerKey) ||
            (numberForPeer !== undefined && numberForPeer !== number) ||
            (reserved && reserved.peerKey !== peerKey) ||
            (reservedForPeer !== undefined && reservedForPeer !== number)
        ) {
            return this.respond(ctx, message, auth, now, 400);
        }
        if (!bound && allocation.channels.size >= this.config.maxChannels)
            return this.respond(ctx, message, auth, now, 508);
        const permission = ipKey(peer.family, peer.bytes);
        if (
            !allocation.permissions.has(permission) &&
            allocation.permissions.size >= this.config.maxPermissions
        ) {
            return this.respond(ctx, message, auth, now, 508);
        }
        allocation.channels.set(number, {
            peerKey,
            peer,
            expiresAt: now + this.config.timings.channelMs,
        });
        allocation.channelByPeer.set(peerKey, number);
        allocation.reserved.delete(number);
        allocation.reservedByPeer.delete(peerKey);
        allocation.permissions.set(
            permission,
            now + this.config.timings.permissionMs,
        );
        this.respond(ctx, message, auth, now, null);
    }

    prunePermissions(allocation, now) {
        for (const [key, expires] of allocation.permissions)
            if (expires <= now) allocation.permissions.delete(key);
    }

    pruneChannels(allocation, now) {
        for (const [number, channel] of allocation.channels) {
            if (channel.expiresAt > now) continue;
            allocation.channels.delete(number);
            allocation.channelByPeer.delete(channel.peerKey);
            // RFC 8656 12: the number and the peer stay reserved for each other for 5 minutes.
            allocation.reserved.set(number, {
                peerKey: channel.peerKey,
                until: channel.expiresAt + this.config.timings.channelReserveMs,
            });
            allocation.reservedByPeer.set(channel.peerKey, number);
        }
        for (const [number, entry] of allocation.reserved) {
            if (entry.until > now) continue;
            allocation.reserved.delete(number);
            allocation.reservedByPeer.delete(entry.peerKey);
        }
    }

    // ---- Data (spec 5.6) -----------------------------------------------------------------------

    liveAllocation(tupleKey, now) {
        const allocation = this.allocations.get(tupleKey);
        if (!allocation) return null;
        const due = this.dueToEnd(allocation, now);
        if (due) {
            this.end(allocation, due);
            return null;
        }
        return allocation;
    }

    sendIndication(ctx, message, now) {
        const allocation = this.liveAllocation(ctx.tupleKey, now);
        if (!allocation) return;
        // DONT-FRAGMENT is unknown and comprehension-required here, so such a Send is dropped.
        if (unknownRequired(message).length) return;
        const peerAttr = first(message, Attr.XOR_PEER_ADDRESS);
        const data = first(message, Attr.DATA);
        const peer = peerAttr
            ? decodeXorAddress(peerAttr.value, message.transactionId)
            : null;
        if (!peer || !data) return;
        this.toPeer(allocation, peer, data.value, now);
    }

    channelDataFromClient(ctx, buffer, now) {
        if (buffer.length < 4) return;
        const number = buffer.readUInt16BE(0);
        const length = buffer.readUInt16BE(2);
        // Over UDP the padding is optional; more than 3 extra bytes is not ChannelData.
        if (4 + length > buffer.length || buffer.length - 4 - length > 3)
            return;
        const allocation = this.liveAllocation(ctx.tupleKey, now);
        if (!allocation) return;
        const channel = allocation.channels.get(number);
        if (!channel || channel.expiresAt <= now) {
            this.metrics.drops.no_permission++;
            return;
        }
        this.toPeer(
            allocation,
            channel.peer,
            buffer.subarray(4, 4 + length),
            now,
        );
    }

    decision(allocation, family, bytes, port) {
        if (allocation.decisionsVersion !== this.rulesVersion) {
            allocation.decisions.clear();
            allocation.decisionsVersion = this.rulesVersion;
        }
        const key = `${family}:${bytes.toString('hex')}:${port}`;
        let decision = allocation.decisions.get(key);
        if (!decision) {
            decision = this.decide(allocation.cls, family, bytes, port);
            if (allocation.decisions.size >= 256) allocation.decisions.clear();
            allocation.decisions.set(key, decision);
        }
        return decision;
    }

    toPeer(allocation, peer, payload, now) {
        if (!allocation.families.includes(peer.family)) return;
        const expires = allocation.permissions.get(
            ipKey(peer.family, peer.bytes),
        );
        if (!expires || expires <= now) {
            this.metrics.drops.no_permission++;
            return;
        }
        const decision = this.decision(
            allocation,
            peer.family,
            peer.bytes,
            peer.port,
        );
        if (!decision.ok) {
            this.metrics.drops.peer_refused++;
            return;
        }
        if (payload.length > MAX_PAYLOAD) {
            this.metrics.drops.size++;
            return;
        }
        if (
            !takeAll(
                [allocation.up, allocation.shared.up, this.totalUp],
                payload.length,
                now,
            )
        ) {
            this.metrics.drops.bandwidth++;
            return;
        }
        allocation.bytesIn += payload.length;
        allocation.packetsIn++;
        this.relayed(allocation.cls.name, 'to_peer', payload.length);
        if (decision.own) {
            // Spec 5.6: one of this relay's own relayed addresses. Hand it over inside the process.
            const target = this.byPort.get(`${peer.family}:${peer.port}`);
            if (!target) return;
            const source = parseIp(
                peer.family === 4
                    ? this.config.externalIp
                    : this.config.externalIpv6,
            );
            this.fromPeer(
                target,
                peer.family,
                source.bytes,
                allocation.port,
                payload,
                now,
            );
            return;
        }
        allocation.sockets[peer.family]?.send(payload, peer.port, peer.address);
    }

    fromNetwork(allocation, family, payload, rinfo) {
        const ip = parseIp(rinfo.address);
        if (!ip || ip.family !== family) return;
        this.fromPeer(
            allocation,
            family,
            ip.bytes,
            rinfo.port,
            payload,
            this.clock.now(),
        );
    }

    fromPeer(allocation, family, bytes, port, payload, now) {
        if (allocation.ended || !allocation.families.includes(family)) return;
        if (now >= allocation.expiresAt || now >= allocation.hardEnd) return;
        const decision = this.decision(allocation, family, bytes, port);
        if (!decision.ok) {
            this.metrics.drops.peer_refused++;
            return;
        }
        const permission = ipKey(family, bytes);
        const expires = allocation.permissions.get(permission);
        if (!expires || expires <= now) {
            this.metrics.drops.no_permission++;
            return;
        }
        if (payload.length > MAX_PAYLOAD) {
            this.metrics.drops.size++;
            return;
        }
        if (
            !takeAll(
                [allocation.down, allocation.shared.down, this.totalDown],
                payload.length,
                now,
            )
        ) {
            this.metrics.drops.bandwidth++;
            return;
        }
        const number = allocation.channelByPeer.get(`${permission}:${port}`);
        const channel =
            number === undefined ? null : allocation.channels.get(number);
        let out;
        if (channel && channel.expiresAt > now) {
            out = encodeChannelData(number, payload, allocation.ctx.stream);
        } else {
            const transactionId = this.indicationId();
            out = encode({
                method: Method.DATA,
                cls: Class.INDICATION,
                transactionId,
                attributes: [
                    {
                        type: Attr.XOR_PEER_ADDRESS,
                        value: encodeXorAddress(
                            formatIp(family, bytes),
                            port,
                            transactionId,
                        ),
                    },
                    { type: Attr.DATA, value: payload },
                ],
            });
        }
        if (!allocation.ctx.send(out, { data: true })) {
            this.metrics.drops.back_pressure++;
            return;
        }
        allocation.bytesOut += payload.length;
        allocation.packetsOut++;
        this.relayed(allocation.cls.name, 'to_client', payload.length);
    }

    indicationId() {
        // Indications need distinct transaction IDs, not secret ones.
        const id = Buffer.alloc(12);
        this.txPrefix.copy(id, 0);
        id.writeBigUInt64BE(this.txCounter++ & 0xffffffffffffffffn, 4);
        return id;
    }

    relayed(className, direction, bytes) {
        const byClass = (this.metrics.relayed[className] ??= {
            to_peer: { packets: 0, bytes: 0 },
            to_client: { packets: 0, bytes: 0 },
        });
        byClass[direction].packets++;
        byClass[direction].bytes += bytes;
    }

    // ---- Ending allocations --------------------------------------------------------------------

    dueToEnd(allocation, now) {
        if (now >= allocation.hardEnd) return 'credential_grace';
        if (now >= allocation.expiresAt) return 'lifetime';
        return null;
    }

    /**
     * End an allocation: close its relayed sockets, drop permissions and channels, and log it.
     * `destroy` closes its TCP or TLS connection at once (revoked, denied, secret removed);
     * otherwise the connection is ended after any queued response.
     */
    end(allocation, reason, { destroy = false } = {}) {
        if (allocation.ended) return;
        allocation.ended = true;
        for (const socket of Object.values(allocation.sockets)) {
            try {
                socket.close();
            } catch {
                // Already closed.
            }
        }
        if (this.allocations.get(allocation.tupleKey) === allocation)
            this.allocations.delete(allocation.tupleKey);
        for (const family of allocation.families) {
            const key = `${family}:${allocation.port}`;
            if (this.byPort.get(key) === allocation) this.byPort.delete(key);
        }
        const set = this.byOpaqueId.get(allocation.opaqueId);
        set?.delete(allocation);
        if (set && set.size === 0) this.byOpaqueId.delete(allocation.opaqueId);
        const count = (this.perIp.get(allocation.ctx.clientIp) ?? 1) - 1;
        if (count > 0) this.perIp.set(allocation.ctx.clientIp, count);
        else this.perIp.delete(allocation.ctx.clientIp);
        if (--allocation.shared.count <= 0)
            this.idBuckets.delete(allocation.opaqueId);
        allocation.permissions.clear();
        allocation.channels.clear();
        allocation.channelByPeer.clear();
        this.metrics.allocations.ended[reason] =
            (this.metrics.allocations.ended[reason] ?? 0) + 1;
        const now = this.clock.now();
        this.allocationLog.write('allocation_end', {
            class: allocation.cls.name,
            id: allocation.idHash,
            client_ip: allocation.ctx.clientIp,
            client_port: allocation.ctx.clientPort,
            transport: allocation.ctx.transport,
            relay_port: allocation.port,
            duration_s: Math.max(
                0,
                Math.round((now - allocation.startedAt) / 1000),
            ),
            bytes_in: allocation.bytesIn,
            bytes_out: allocation.bytesOut,
            packets_in: allocation.packetsIn,
            packets_out: allocation.packetsOut,
            reason,
        });
        if (allocation.ctx.stream && reason !== 'connection_closed')
            allocation.ctx.close(destroy);
    }

    /** A TCP or TLS connection closed: its allocation ends at once (spec 5.1). */
    connectionClosed(ctx) {
        ctx.closed = true;
        const allocation = this.allocations.get(ctx.tupleKey);
        if (allocation) this.end(allocation, 'connection_closed');
    }

    hasAllocation(tupleKey) {
        return (
            this.allocations.has(tupleKey) ||
            [...this.pending.values()].some(
                (entry) => entry.tupleKey === tupleKey,
            )
        );
    }

    // ---- Revocation (spec 3.7) -----------------------------------------------------------------

    /** entries: [{ id, expiresUpTo }]. Ends matching allocations and holds the ids. */
    revoke(entries, reason) {
        let ended = 0;
        for (const { id, expiresUpTo } of entries) {
            this.held.set(id, Math.max(this.held.get(id) ?? 0, expiresUpTo));
            let count = 0;
            for (const allocation of [...(this.byOpaqueId.get(id) ?? [])]) {
                if (allocation.credentialExpiry <= expiresUpTo) {
                    this.end(allocation, 'revoked', { destroy: true });
                    count++;
                }
            }
            ended += count;
            const tag = /^obli-([a-z]+)-/.exec(id)?.[1];
            const cls = Object.values(this.config.classes).find((entry) =>
                entry.tags.includes(tag),
            );
            this.allocationLog.write('revoked', {
                class: cls?.name ?? tag,
                id: idHash(id),
                reason,
                ended: count,
            });
        }
        this.pruneHeld(this.clock.now());
        return ended;
    }

    pruneHeld(now) {
        const seconds = Math.floor(now / 1000);
        let changed = false;
        for (const [id, until] of this.held) {
            if (until + this.config.clockSkewSeconds < seconds) {
                this.held.delete(id);
                changed = true;
            }
        }
        return changed;
    }

    // ---- Housekeeping --------------------------------------------------------------------------

    sweep() {
        const now = this.clock.now();
        for (const allocation of [...this.allocations.values()]) {
            const due = this.dueToEnd(allocation, now);
            if (due) {
                this.end(allocation, due);
                continue;
            }
            this.prunePermissions(allocation, now);
            this.pruneChannels(allocation, now);
        }
        for (const [key, entry] of this.responses)
            if (entry.expires <= now) this.responses.delete(key);
        this.unauthPerIp.prune(now);
        this.failures.prune(now);
        this.securityOnce.prune(now);
        this.flushSummary(now);
        return this.pruneHeld(now);
    }

    closeAll(reason = 'shutdown') {
        this.closed = true;
        for (const allocation of [...this.allocations.values()])
            this.end(allocation, reason, { destroy: true });
    }

    portsFree() {
        let used = 0;
        for (const key of this.byPort.keys()) if (key.startsWith('4:')) used++;
        return this.config.portMax - this.config.portMin + 1 - used;
    }

    allocationsByClass() {
        const counts = Object.fromEntries(
            Object.keys(this.config.classes).map((name) => [name, 0]),
        );
        for (const allocation of this.allocations.values())
            counts[allocation.cls.name]++;
        return counts;
    }
}
