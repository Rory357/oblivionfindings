#!/usr/bin/env node
// Load test for Oblivion Relay (spec 11, "Load test"; requirement 10). Run by hand, never in CI.
//
//   node relay/tools/load.mjs [--pairs 500] [--pps 30] [--size 200] [--seconds 30]
//
// With no --target it starts a relay in this process on loopback. With --target host:port it
// uses a running relay; pass the same RELAY_CHAT_SECRET (env) and RELAY_REALM. Each pair is two
// UDP allocations that send each other ChannelData. It reports packets a second, loss, added
// latency (p50 and p99) and the relay's memory.

import { createHash, createHmac, randomBytes } from 'node:crypto';
import { request } from 'node:http';
import { parseArgs } from 'node:util';
import { readConfig } from '../lib/config.mjs';
import { startRelay } from '../lib/relay.mjs';
import { TurnClient, errorCode, relayedAddress } from '../test/helpers.mjs';

const { values } = parseArgs({
    options: {
        pairs: { type: 'string', default: '200' },
        pps: { type: 'string', default: '25' },
        size: { type: 'string', default: '200' },
        seconds: { type: 'string', default: '20' },
        target: { type: 'string' },
        health: { type: 'string' },
    },
});
const pairs = Number(values.pairs);
const pps = Number(values.pps);
const size = Math.max(16, Number(values.size));
const seconds = Number(values.seconds);

const secret = process.env.RELAY_CHAT_SECRET ?? randomBytes(24).toString('hex');
const realm = process.env.RELAY_REALM ?? 'load.relay.test';

let relay = null;
let host = '127.0.0.1';
let port;
let healthPort = values.health ? Number(values.health.split(':').pop()) : null;
if (values.target) {
    [host, port] = [
        values.target.slice(0, values.target.lastIndexOf(':')),
        Number(values.target.split(':').pop()),
    ];
} else {
    const config = readConfig(
        {
            RELAY_REALM: realm,
            RELAY_LISTEN: 'udp://127.0.0.1:3478',
            RELAY_EXTERNAL_IP: '127.0.0.1',
            RELAY_LISTEN_IP: '127.0.0.1',
            RELAY_PORT_MIN: '40000',
            RELAY_PORT_MAX: '47999',
            RELAY_CHAT_SECRET: secret,
            RELAY_CONTROL_SECRET: randomBytes(24).toString('hex'),
            RELAY_CONTROL_KEY_ID: 'load',
            RELAY_LOG_DIR: process.env.TEMP ?? '/tmp',
            RELAY_STATE_DIR: process.env.TEMP ?? '/tmp',
            RELAY_LOG_LEVEL: 'error',
            RELAY_MAX_ALLOCATIONS_PER_IP: '8000',
            RELAY_UNAUTH_PER_IP_PER_SECOND: '100000',
            RELAY_TOTAL_MBPS: '2000',
        },
        {
            listeners: [
                {
                    transport: 'udp',
                    host: '127.0.0.1',
                    port: 0,
                    proxy: false,
                    loopback: true,
                },
            ],
            healthListen: { host: '127.0.0.1', port: 0 },
            controlListen: { host: '127.0.0.1', port: 0 },
        },
    );
    relay = await startRelay(config, { opsWrite: () => {} });
    port = relay.port('udp');
    healthPort = relay.healthPort;
}

async function allocation() {
    const client = await TurnClient.open(port, { host });
    const expiry = Math.floor(Date.now() / 1000) + 3600;
    const username = `${expiry}:obli-chat-${createHash('sha256').update(randomBytes(16)).digest('hex')}`;
    const password = createHmac('sha1', secret)
        .update(username)
        .digest('base64');
    const response = await client.allocate({
        username,
        password,
        lifetime: 3600,
    });
    if (errorCode(response) !== 200)
        throw new Error(`Allocate failed with ${errorCode(response)}`);
    client.relayed = relayedAddress(response);
    return client;
}

function health() {
    if (!healthPort) return Promise.resolve(null);
    return new Promise((resolve) => {
        request(
            { host: '127.0.0.1', port: healthPort, path: '/health' },
            (response) => {
                const chunks = [];
                response.on('data', (chunk) => chunks.push(chunk));
                response.on('end', () =>
                    resolve(JSON.parse(Buffer.concat(chunks).toString())),
                );
            },
        )
            .on('error', () => resolve(null))
            .end();
    });
}

console.log(`Setting up ${pairs} pairs (${pairs * 2} allocations)...`);
const clients = [];
for (let index = 0; index < pairs; index++) {
    const a = await allocation();
    const b = await allocation();
    await a.channelBind(0x4000, b.relayed.address, b.relayed.port);
    await b.channelBind(0x4000, a.relayed.address, a.relayed.port);
    clients.push([a, b], [b, a]);
}

let sent = 0;
let received = 0;
const latencies = [];
for (const [, receiver] of clients) {
    receiver.deliver = (item) => {
        received++;
        const stamp = item.data.readDoubleBE(0);
        if (latencies.length < 200_000)
            latencies.push(performance.now() - stamp);
    };
}
const memoryBefore = (await health())?.memory;
console.log(
    `Sending ${pps} packets a second from each of ${clients.length} senders, ${size} bytes each, for ${seconds} s...`,
);
const started = performance.now();
const payload = Buffer.alloc(size);
const timer = setInterval(() => {
    for (const [sender] of clients) {
        payload.writeDoubleBE(performance.now(), 0);
        sender.sendChannel(0x4000, payload);
        sent++;
    }
}, 1000 / pps);
await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
clearInterval(timer);
await new Promise((resolve) => setTimeout(resolve, 500));
const elapsed = (performance.now() - started) / 1000;
latencies.sort((x, y) => x - y);
const at = (fraction) =>
    latencies[
        Math.min(latencies.length - 1, Math.floor(latencies.length * fraction))
    ]?.toFixed(2);
const memoryAfter = (await health())?.memory;
console.log(
    JSON.stringify(
        {
            allocations: pairs * 2,
            packets_per_second: Math.round(received / elapsed),
            mbit_per_second_each_way: +(
                (received * size * 8) /
                elapsed /
                1e6 /
                2
            ).toFixed(1),
            loss_percent: +(
                (100 * (sent - received)) /
                Math.max(1, sent)
            ).toFixed(3),
            latency_ms: { p50: at(0.5), p99: at(0.99) },
            memory_before: memoryBefore,
            memory_after: memoryAfter,
        },
        null,
        2,
    ),
);
for (const [client] of clients) client.close();
await relay?.close();
process.exit(0);
