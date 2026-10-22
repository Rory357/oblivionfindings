// The gateway's in-memory state: who is connected, what to send them, revocations and call
// grants. Nothing here is stored; after a restart browsers reconnect and catch up through the app.

import { clearTimeout, setTimeout } from 'node:timers';
import { LiveClose } from './connection.mjs';
import { encodeText } from './frames.mjs';
import { JtiRegistry, verifyTicket } from './token.mjs';

// Events the app may publish (spec section 4, "gateway to browser"). ready, renew, ping and
// call.signal come only from the gateway itself.
export const PUBLISHABLE = new Set([
    'conversation.changed',
    'inbox.changed',
    'unread.changed',
    'typing',
    'presence',
    'call.changed',
    'notice',
]);

const CALL_ID = /^[A-Za-z0-9-]{1,64}$/;

function isUserId(value) {
    return Number.isSafeInteger(value) && value > 0;
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export class Hub {
    constructor({ config, presence, log }) {
        this.config = config;
        this.presence = presence;
        this.log = log;
        this.connections = new Set();
        this.users = new Map();
        this.offlineTimers = new Map();
        this.jti = new JtiRegistry();
        // Tickets issued at or before a revocation are refused until they would have expired anyway.
        this.revokedUsers = new Map();
        this.revokedSessions = new Map();
        this.grants = new Map();
        this.startedAt = Date.now();
    }

    nowSeconds() {
        return Math.floor(Date.now() / 1000);
    }

    track(connection) {
        this.connections.add(connection);
    }

    forget(connection) {
        this.connections.delete(connection);
    }

    get connectionCount() {
        return this.connections.size;
    }

    /** Check a hello ticket for this connection. Returns its claims, or null to refuse. */
    admit(ticket, connection) {
        const now = this.nowSeconds();
        const result = verifyTicket(ticket, {
            keys: this.config.keys,
            audience: this.config.audience,
            nowSeconds: now,
            leewaySeconds: this.config.clockLeewaySeconds,
            maxLifetimeSeconds: this.config.ticketMaxLifetimeSeconds,
        });
        if (!result.ok) {
            this.log('info', 'ticket.refused', {
                connection: connection.id,
                reason: result.reason,
            });
            return null;
        }
        const claims = result.claims;
        if (connection.userId !== null && claims.sub !== connection.userId) {
            this.log('info', 'ticket.refused', {
                connection: connection.id,
                reason: 'renewal_subject',
            });
            return null;
        }
        this.pruneRevocations(now);
        const userRevoked = this.revokedUsers.get(claims.sub);
        const sessionRevoked = this.revokedSessions.get(claims.sid);
        if (
            (userRevoked && claims.iat <= userRevoked.at) ||
            (sessionRevoked && claims.iat <= sessionRevoked.at)
        ) {
            this.log('info', 'ticket.refused', {
                connection: connection.id,
                reason: 'revoked',
            });
            return null;
        }
        if (
            !this.jti.spend(
                claims.jti,
                claims.exp + this.config.clockLeewaySeconds,
                now,
            )
        ) {
            this.log('info', 'ticket.refused', {
                connection: connection.id,
                reason: 'reused',
            });
            return null;
        }
        return claims;
    }

    register(connection) {
        const userId = connection.userId;
        let set = this.users.get(userId);
        const first = !set;
        if (!set) {
            set = new Set();
            this.users.set(userId, set);
        }
        set.add(connection);
        // Per user at most N connections: the oldest gives way.
        while (set.size > this.config.maxConnectionsPerUser) {
            const oldest = set.values().next().value;
            set.delete(oldest);
            oldest.close(LiveClose.TOO_MANY, 'Too many connections');
        }
        if (first) {
            const pending = this.offlineTimers.get(userId);
            if (pending) {
                // Back within the grace period: the app never heard they left.
                clearTimeout(pending);
                this.offlineTimers.delete(userId);
            } else {
                this.presence?.report(userId, 'online');
            }
        }
    }

    unregister(connection) {
        const userId = connection.userId;
        if (userId === null) return;
        const set = this.users.get(userId);
        if (!set || !set.delete(connection) || set.size > 0) return;
        this.users.delete(userId);
        if (this.offlineTimers.has(userId)) return;
        const timer = setTimeout(() => {
            this.offlineTimers.delete(userId);
            if (!this.users.has(userId))
                this.presence?.report(userId, 'offline');
        }, this.config.offlineGraceMs);
        timer.unref?.();
        this.offlineTimers.set(userId, timer);
    }

    /** Check a publish body. Returns an error message, or null when every event is valid. */
    validateEvents(events) {
        if (
            !Array.isArray(events) ||
            events.length === 0 ||
            events.length > 1000
        )
            return 'events must be a non-empty list';
        for (const event of events) {
            if (!isPlainObject(event)) return 'each event must be an object';
            if (!PUBLISHABLE.has(event.type)) return 'unknown event type';
            if (
                !Array.isArray(event.to) ||
                event.to.length === 0 ||
                event.to.length > this.config.maxRecipientsPerEvent
            ) {
                return `to must list 1 to ${this.config.maxRecipientsPerEvent} users`;
            }
            if (!event.to.every(isUserId)) return 'to must list user IDs';
            if (event.data !== undefined && !isPlainObject(event.data))
                return 'data must be an object';
            if (event.data && Object.hasOwn(event.data, 'type'))
                return 'data cannot set the type';
        }
        return null;
    }

    /** Send already-validated events. Each event is encoded once and written to every recipient. */
    publish(events) {
        let delivered = 0;
        for (const event of events) {
            const frame = encodeText(
                JSON.stringify({ type: event.type, ...(event.data ?? {}) }),
            );
            for (const userId of new Set(event.to)) {
                for (const connection of this.users.get(userId) ?? []) {
                    // Presence is for staff only (decision D8), whatever the app sent.
                    if (event.type === 'presence' && connection.family)
                        continue;
                    if (connection.write(frame)) delivered++;
                }
            }
        }
        return delivered;
    }

    /** Close connections with 4403 and refuse tickets issued before now. */
    revoke(userIds, sessionIds = []) {
        const now = this.nowSeconds();
        const forgetAt =
            now +
            this.config.ticketMaxLifetimeSeconds +
            this.config.clockLeewaySeconds +
            1;
        const sessions = new Set(sessionIds);
        let closed = 0;
        for (const userId of new Set(userIds)) {
            if (sessions.size === 0) {
                this.revokedUsers.delete(userId);
                this.revokedUsers.set(userId, { at: now, forgetAt });
            }
            for (const connection of [...(this.users.get(userId) ?? [])]) {
                if (sessions.size && !sessions.has(connection.sid)) continue;
                connection.close(LiveClose.REVOKED, 'Access revoked');
                closed++;
            }
        }
        for (const sid of sessions) {
            this.revokedSessions.delete(sid);
            this.revokedSessions.set(sid, { at: now, forgetAt });
        }
        // A revoked person can no longer signal on any call.
        for (const people of this.grants.values()) {
            if (sessions.size === 0)
                for (const userId of userIds) people.delete(userId);
        }
        return closed;
    }

    pruneRevocations(now) {
        for (const list of [this.revokedUsers, this.revokedSessions]) {
            for (const [key, entry] of list) {
                if (entry.forgetAt >= now) break;
                list.delete(key);
            }
        }
    }

    grantCall(callId, userIds, until) {
        let people = this.grants.get(callId);
        if (!people) {
            people = new Map();
            this.grants.set(callId, people);
        }
        for (const userId of userIds) people.set(userId, until);
    }

    revokeCall(callId, userIds) {
        const people = this.grants.get(callId);
        if (!people) return;
        for (const userId of userIds) people.delete(userId);
        if (people.size === 0) this.grants.delete(callId);
    }

    hasGrant(callId, userId, now = this.nowSeconds()) {
        const until = this.grants.get(callId)?.get(userId);
        return until !== undefined && until > now;
    }

    pruneGrants(now = this.nowSeconds()) {
        for (const [callId, people] of this.grants) {
            for (const [userId, until] of people)
                if (until <= now) people.delete(userId);
            if (people.size === 0) this.grants.delete(callId);
        }
    }

    /** Forward call signalling only between two people who both hold a grant for that call. */
    signal(connection, message) {
        const { call_id: callId, to, payload } = message;
        if (
            typeof callId !== 'string' ||
            !CALL_ID.test(callId) ||
            !isUserId(to) ||
            !isPlainObject(payload)
        ) {
            connection.close(1008, 'Invalid call signal');
            return;
        }
        if (
            Buffer.byteLength(JSON.stringify(payload), 'utf8') >
            this.config.maxSignalBytes
        ) {
            connection.close(1009, 'Call signal too big');
            return;
        }
        if (
            to === connection.userId ||
            !this.hasGrant(callId, connection.userId) ||
            !this.hasGrant(callId, to)
        ) {
            return;
        }
        // The payload is passed on as it came; the gateway neither reads nor keeps it.
        const frame = encodeText(
            JSON.stringify({
                type: 'call.signal',
                call_id: callId,
                from: connection.userId,
                payload,
            }),
        );
        for (const target of this.users.get(to) ?? []) target.write(frame);
    }

    health() {
        return {
            status: 'ok',
            version: this.config.version ?? '1.0.0',
            uptime_seconds: Math.floor((Date.now() - this.startedAt) / 1000),
            connections: this.connections.size,
            users: this.users.size,
            calls: this.grants.size,
            // Resident memory, for capacity checks (spec: 5,000 idle connections under 500 MB).
            memory_mb: Math.round(process.memoryUsage().rss / 1048576),
        };
    }

    closeAll(code, reason) {
        for (const connection of [...this.connections])
            connection.close(code, reason);
        for (const timer of this.offlineTimers.values()) clearTimeout(timer);
        this.offlineTimers.clear();
    }
}
