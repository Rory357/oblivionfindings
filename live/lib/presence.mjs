// Presence reports to the app (spec section 5): POST <LIVE_APP_URL>/chat/api/live/presence when a
// person's first connection opens or their last one closes. Best effort: a lost report is
// corrected by the next one, and the app only uses it for display.

import { randomBytes } from 'node:crypto';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { signBody } from './signing.mjs';

export class PresenceReporter {
    constructor({ config, log }) {
        this.config = config;
        this.log = log;
        this.queue = [];
        this.running = 0;
        this.enabled = Boolean(config.appUrl);
        this.target = this.enabled
            ? new URL('/chat/api/live/presence', config.appUrl + '/')
            : null;
        // Latest state per person: a quick online/offline/online flap sends only what is current.
        this.latest = new Map();
    }

    report(userId, state) {
        if (!this.enabled) return;
        const queued = this.latest.has(userId);
        this.latest.set(userId, state);
        if (!queued) this.queue.push(userId);
        this.drain();
    }

    drain() {
        while (
            this.running < this.config.presenceConcurrency &&
            this.queue.length
        ) {
            const userId = this.queue.shift();
            const state = this.latest.get(userId);
            this.latest.delete(userId);
            this.running++;
            this.send(userId, state).finally(() => {
                this.running--;
                this.drain();
            });
        }
    }

    send(userId, state) {
        // The nonce keeps two identical reports in one second from looking like a replay.
        const body = Buffer.from(
            JSON.stringify({
                user_id: userId,
                state,
                nonce: randomBytes(12).toString('hex'),
            }),
            'utf8',
        );
        const timestamp = String(Math.floor(Date.now() / 1000));
        const target = this.target;
        const send = target.protocol === 'https:' ? httpsRequest : httpRequest;
        return new Promise((resolve) => {
            const outgoing = send(
                target,
                {
                    method: 'POST',
                    timeout: this.config.presenceTimeoutMs,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'Content-Length': body.length,
                        'X-Live-Timestamp': timestamp,
                        'X-Live-Signature': signBody(
                            this.config.signingSecret,
                            timestamp,
                            body,
                        ),
                    },
                },
                (response) => {
                    response.resume();
                    if (response.statusCode >= 300) {
                        this.log('warn', 'presence.rejected', {
                            status: response.statusCode,
                        });
                    }
                    response.on('end', resolve);
                    response.on('error', resolve);
                },
            );
            outgoing.on('timeout', () =>
                outgoing.destroy(new Error('timeout')),
            );
            outgoing.on('error', (error) => {
                this.log('warn', 'presence.failed', { message: error.message });
                resolve();
            });
            outgoing.end(body);
        });
    }
}
