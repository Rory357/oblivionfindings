// One browser connection after the handshake: authentication with a ticket (spec section 3),
// the limits in section 4, keep-alive pings and the RFC 6455 closing handshake.

import {
    clearInterval,
    clearTimeout,
    setInterval,
    setTimeout,
} from 'node:timers';
import {
    CloseCode,
    encodeClose,
    encodeFrame,
    encodeText,
    FrameReader,
    Opcode,
    parseClosePayload,
} from './frames.mjs';

export const LiveClose = Object.freeze({
    TICKET: 4401,
    REVOKED: 4403,
    TOO_MANY: 4409,
});

const PING_MESSAGE = encodeText('{"type":"ping"}');
const RENEW_MESSAGE = encodeText('{"type":"renew"}');

export class LiveConnection {
    constructor({ id, socket, config, hub, log }) {
        this.id = id;
        this.socket = socket;
        this.config = config;
        this.hub = hub;
        this.log = log;
        this.reader = new FrameReader({
            maxMessageBytes: config.maxMessageBytes,
            maxFragments: 128,
        });
        this.state = 'pending';
        this.userId = null;
        this.sid = null;
        this.family = false;
        this.openedAt = Date.now();
        this.authenticatedAt = 0;
        this.received = [];
        this.missedPongs = 0;
        this.timers = {
            hello: null,
            renew: null,
            expiry: null,
            ping: null,
            close: null,
        };
    }

    start(head) {
        const socket = this.socket;
        socket.setNoDelay(true);
        socket.on('data', (chunk) => this.receive(chunk));
        socket.on('error', () => this.terminate());
        socket.on('end', () => this.terminate());
        socket.on('close', () => this.cleanup());
        this.timers.hello = setTimeout(
            () => this.close(LiveClose.TICKET, 'No ticket'),
            this.config.helloTimeoutMs,
        );
        this.timers.ping = setInterval(
            () => this.heartbeat(),
            this.config.pingIntervalMs,
        );
        if (head && head.length) this.receive(head);
    }

    receive(chunk) {
        if (this.state === 'closed') return;
        const { events, error } = this.reader.push(chunk);
        for (const event of events) {
            if (this.state === 'closed') return;
            this.handleFrame(event);
        }
        if (error && this.state !== 'closed') {
            this.close(error.code, error.message);
        }
    }

    handleFrame({ opcode, data }) {
        if (opcode === Opcode.CLOSE) {
            let code = CloseCode.NO_STATUS;
            try {
                code = parseClosePayload(data).code;
            } catch (error) {
                this.close(error.code, error.message);
                return;
            }
            if (this.state === 'closing') {
                // Our close was answered: the handshake is complete.
                this.socket.end();
                return;
            }
            // Echo the peer's status (section 5.5.1), or close without one if it sent none.
            this.state = 'closing';
            this.stopTimers();
            this.hub.unregister(this);
            this.socket.write(
                code === CloseCode.NO_STATUS
                    ? encodeFrame(Opcode.CLOSE)
                    : encodeClose(code),
            );
            this.socket.end();
            return;
        }
        // After sending a close, only the peer's close matters.
        if (this.state === 'closing') return;
        if (!this.countMessage()) return;

        if (opcode === Opcode.PING) {
            this.write(encodeFrame(Opcode.PONG, data));
            return;
        }
        if (opcode === Opcode.PONG) {
            this.missedPongs = 0;
            return;
        }
        if (opcode === Opcode.BINARY) {
            this.close(
                CloseCode.UNSUPPORTED_DATA,
                'Binary messages are not used',
            );
            return;
        }
        this.handleMessage(data.toString('utf8'));
    }

    /** At most rateLimitMessages in any rateLimitWindowMs; more closes with 1008. */
    countMessage() {
        const now = Date.now();
        const windowStart = now - this.config.rateLimitWindowMs;
        while (this.received.length && this.received[0] <= windowStart)
            this.received.shift();
        this.received.push(now);
        if (this.received.length > this.config.rateLimitMessages) {
            this.close(CloseCode.POLICY, 'Too many messages');
            return false;
        }
        return true;
    }

    handleMessage(text) {
        let message;
        try {
            message = JSON.parse(text);
        } catch {
            this.close(CloseCode.POLICY, 'Messages must be JSON');
            return;
        }
        if (
            !message ||
            typeof message !== 'object' ||
            Array.isArray(message) ||
            typeof message.type !== 'string'
        ) {
            this.close(CloseCode.POLICY, 'Messages need a type');
            return;
        }
        switch (message.type) {
            case 'hello':
                this.hello(message.ticket);
                return;
            case 'pong':
                this.missedPongs = 0;
                return;
            case 'call.signal':
                if (this.state !== 'open') {
                    this.close(LiveClose.TICKET, 'Not signed in');
                    return;
                }
                this.hub.signal(this, message);
                return;
            default:
                this.close(
                    this.state === 'open' ? CloseCode.POLICY : LiveClose.TICKET,
                    'Unknown message type',
                );
        }
    }

    hello(ticket) {
        const renewing = this.state === 'open';
        const claims = this.hub.admit(ticket, this);
        if (!claims) {
            this.close(LiveClose.TICKET, 'Ticket refused');
            return;
        }
        this.sid = claims.sid;
        this.family = claims.fam;
        this.authenticatedAt = Date.now();
        clearTimeout(this.timers.hello);
        clearTimeout(this.timers.renew);
        clearTimeout(this.timers.expiry);
        this.timers.hello = null;
        const lifetime = this.config.sessionLifetimeMs;
        this.timers.renew = setTimeout(
            () => this.write(RENEW_MESSAGE),
            Math.max(0, lifetime - this.config.renewBeforeMs),
        );
        this.timers.expiry = setTimeout(
            () => this.close(LiveClose.TICKET, 'Ticket expired'),
            lifetime,
        );
        if (!renewing) {
            this.userId = claims.sub;
            this.state = 'open';
            this.hub.register(this);
        }
        // resume: true asks the browser to catch up through the app; a renewal on the same
        // connection lost nothing, so it does not.
        this.send({ type: 'ready', resume: !renewing });
    }

    heartbeat() {
        if (this.state === 'pending') return;
        if (this.missedPongs >= this.config.maxMissedPongs) {
            this.log('info', 'connection.timeout', { connection: this.id });
            this.terminate();
            return;
        }
        this.missedPongs++;
        // A protocol ping for the transport, and an application ping for browsers that
        // cannot see protocol pings. Either answer resets the count.
        this.write(encodeFrame(Opcode.PING));
        this.write(PING_MESSAGE);
    }

    send(message) {
        return this.write(encodeText(JSON.stringify(message)));
    }

    write(frame) {
        if (this.state === 'closed' || this.socket.destroyed) return false;
        // A browser that stops reading cannot make the gateway hold its backlog.
        if (this.socket.writableLength > this.config.maxBufferedBytes) {
            this.log('info', 'connection.slow', { connection: this.id });
            this.terminate();
            return false;
        }
        this.socket.write(frame);
        return true;
    }

    /** Start the closing handshake: send a close frame, then wait briefly for the answer. */
    close(code, reason = '') {
        if (this.state === 'closing' || this.state === 'closed') return;
        this.state = 'closing';
        this.stopTimers();
        this.hub.unregister(this);
        if (!this.socket.destroyed) {
            this.socket.write(encodeClose(code, reason));
        }
        this.timers.close = setTimeout(
            () => this.terminate(),
            this.config.closeTimeoutMs,
        );
    }

    terminate() {
        if (this.state === 'closed') return;
        this.cleanup();
        this.socket.destroy();
    }

    stopTimers() {
        for (const name of ['hello', 'renew', 'expiry']) {
            clearTimeout(this.timers[name]);
            this.timers[name] = null;
        }
        clearInterval(this.timers.ping);
        this.timers.ping = null;
    }

    cleanup() {
        if (this.state === 'closed') return;
        this.state = 'closed';
        this.stopTimers();
        clearTimeout(this.timers.close);
        this.hub.unregister(this);
        this.hub.forget(this);
    }
}
