#!/usr/bin/env node
// Oblivion Relay: Oblivion's own STUN and TURN server for Chat calls.
// Spec: docs/chat-experience/clean-room/specs/oblivion-relay.md. Node built-in modules only.
//
//   node --max-old-space-size=256 relay/server.mjs
//
// Settings come from the environment (RELAY_*); see deploy/chat-calling/oblivion-relay.env.example.
// SIGHUP re-reads secret files, the certificate and the deny list; SIGTERM stops within 5 seconds.

import { setTimeout } from 'node:timers';
import { pathToFileURL } from 'node:url';
import { readConfig } from './lib/config.mjs';
import { OpsLog } from './lib/log.mjs';
import { startRelay, systemClock } from './lib/relay.mjs';

async function main() {
    const ops = new OpsLog({ level: 'info', clock: systemClock });
    let config;
    try {
        config = readConfig(process.env);
    } catch (error) {
        ops.log('error', 'config_error', {
            setting: error.setting,
            message: error.message,
        });
        process.exit(78);
    }
    let relay;
    try {
        // This program never injects a peer policy: only the settings decide where it may send.
        relay = await startRelay(config);
    } catch (error) {
        ops.log('error', 'config_error', {
            setting: error.setting,
            message: error.message,
        });
        process.exit(78);
    }
    process.on('SIGHUP', () => relay.reload());
    const stop = async (signal) => {
        ops.log('info', 'stop', { signal });
        setTimeout(() => process.exit(0), 5000).unref();
        await relay.close();
        process.exit(0);
    };
    process.on('SIGTERM', () => void stop('SIGTERM'));
    process.on('SIGINT', () => void stop('SIGINT'));
    process.on('uncaughtException', (error) => {
        ops.log('error', 'listener_error', { message: error.message });
        process.exit(70);
    });
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    await main();
}
