// Spec 11, test 17: every invalid setting stops start-up, naming the setting and never a secret.

import assert from 'node:assert/strict';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, test } from 'node:test';
import { ConfigError, readConfig } from '../lib/config.mjs';
import { startRelay } from '../lib/relay.mjs';
import { selfSignedCertificate } from './certs.mjs';
import { baseEnv, CONTROL_SECRET, SECRET, tempDir } from './helpers.mjs';

const ENV_EXAMPLE = join(dirname(fileURLToPath(import.meta.url)), '../../deploy/chat-calling/oblivion-relay.env.example');

describe('the committed env example', () => {
    test(
        'every RELAY_ line in deploy/chat-calling/oblivion-relay.env.example is a setting the relay accepts',
        { skip: !existsSync(ENV_EXAMPLE) && 'relay/ copied on its own' },
        () => {
            const env = {};
            for (const line of readFileSync(ENV_EXAMPLE, 'utf8').split(/\r?\n/)) {
                const match = /^(RELAY_[A-Z0-9_]+)=(.*)$/.exec(line);
                if (match) env[match[1]] = match[2];
            }
            assert.ok(Object.keys(env).length > 40);
            // Swap the host paths and the one value the operator supplies for local ones.
            const dir = tempDir('relay-example-');
            const { cert, key } = selfSignedCertificate('relay.oblivionfindings.com');
            const files = { cert: 'fullchain.pem', key: 'privkey.pem', chat: 'chat.secret', control: 'control.secret', deny: 'deny' };
            writeFileSync(join(dir, files.cert), cert);
            writeFileSync(join(dir, files.key), key);
            writeFileSync(join(dir, files.chat), SECRET);
            writeFileSync(join(dir, files.control), CONTROL_SECRET);
            writeFileSync(join(dir, files.deny), '');
            writeFileSync(join(dir, 'empty'), '');
            const config = readConfig({
                ...env,
                RELAY_EXTERNAL_IP: '203.0.113.10',
                RELAY_TLS_CERT_FILE: join(dir, files.cert),
                RELAY_TLS_KEY_FILE: join(dir, files.key),
                RELAY_CHAT_SECRET_FILE: join(dir, files.chat),
                RELAY_CHAT_PREVIOUS_SECRET_FILE: join(dir, 'empty'),
                RELAY_CONTROL_SECRET_FILE: join(dir, files.control),
                RELAY_CONTROL_PREVIOUS_SECRET_FILE: join(dir, 'empty'),
                RELAY_DENY_FILE: join(dir, files.deny),
            });
            assert.equal(config.realm, 'relay.oblivionfindings.com');
            assert.deepEqual([config.portMin, config.portMax, config.maxAllocations, config.logRetentionDays], [61_000, 64_999, 3000, 14]);
            assert.equal(config.secrets.chat.previous, null, 'an empty previous-secret file means none');
            assert.equal(config.controlListen.host, '127.0.0.1');
        },
    );
});

function refuses(env, setting, message) {
    let error;
    try {
        readConfig(baseEnv(env));
    } catch (caught) {
        error = caught;
    }
    assert.ok(
        error instanceof ConfigError,
        `${message ?? setting}: expected a ConfigError`,
    );
    assert.equal(error.setting, setting, message ?? setting);
    assert.ok(error.message.includes(setting));
    for (const secret of [SECRET, CONTROL_SECRET, ...Object.values(env)]) {
        if (typeof secret === 'string' && secret.length >= 32)
            assert.equal(
                error.message.includes(secret),
                false,
                'never the value of a secret',
            );
    }
}

describe('17. Settings', () => {
    test('the defaults and the test server shape (10.3) are accepted', () => {
        const dir = tempDir('relay-config-');
        const { cert, key } = selfSignedCertificate(
            'relay.oblivionfindings.com',
        );
        writeFileSync(join(dir, 'cert.pem'), cert);
        writeFileSync(join(dir, 'key.pem'), key);
        const tls = {
            RELAY_TLS_CERT_FILE: join(dir, 'cert.pem'),
            RELAY_TLS_KEY_FILE: join(dir, 'key.pem'),
        };
        const defaults = readConfig(
            baseEnv({
                RELAY_LISTEN: undefined,
                RELAY_PORT_MIN: undefined,
                RELAY_PORT_MAX: undefined,
                ...tls,
            }),
        );
        assert.deepEqual(
            defaults.listeners.map(
                (entry) => `${entry.transport}://${entry.host}:${entry.port}`,
            ),
            [
                'udp://0.0.0.0:3478',
                'tcp://0.0.0.0:3478',
                'tcp+tls://0.0.0.0:443',
            ],
        );
        assert.deepEqual(
            [defaults.portMin, defaults.portMax, defaults.maxAllocations],
            [49_152, 65_535, 4000],
        );
        assert.deepEqual(
            [
                defaults.classes.chat.maxCredentialSeconds,
                defaults.classes.chat.graceSeconds,
            ],
            [3600, 14_400],
        );
        assert.equal(defaults.stunBinding, true);
        const shapeB = readConfig(
            baseEnv({
                RELAY_REALM: 'relay.oblivionfindings.com',
                RELAY_LISTEN:
                    'udp://0.0.0.0:3479,tls://127.0.0.1:5350?proxy=required',
                RELAY_EXTERNAL_IP: '203.0.113.10',
                RELAY_LISTEN_IP: '0.0.0.0',
                RELAY_PORT_MIN: '61000',
                RELAY_PORT_MAX: '64999',
                RELAY_MAX_ALLOCATIONS: '3000',
                RELAY_TOTAL_MBPS: '100',
                ...tls,
            }),
        );
        assert.deepEqual(
            shapeB.listeners.map((entry) => [
                entry.transport,
                entry.port,
                entry.proxy,
            ]),
            [
                ['udp', 3479, false],
                ['tls', 5350, true],
            ],
        );
        assert.ok(shapeB.tls.notAfter);
    });

    test('required and malformed basics', () => {
        refuses({ RELAY_REALM: '' }, 'RELAY_REALM', 'a missing realm');
        refuses(
            { RELAY_REALM: 'a'.repeat(33) },
            'RELAY_REALM',
            'a realm over 32 characters',
        );
        refuses({ RELAY_EXTERNAL_IP: '' }, 'RELAY_EXTERNAL_IP');
        refuses(
            { RELAY_EXTERNAL_IP: 'relay.example.org' },
            'RELAY_EXTERNAL_IP',
        );
        refuses({ RELAY_LISTEN_IP: '::1' }, 'RELAY_LISTEN_IP');
        refuses(
            { RELAY_LISTEN_IPV6: '2001:db8::1' },
            'RELAY_EXTERNAL_IPV6',
            'half an IPv6 pair',
        );
        refuses({ RELAY_LISTEN: 'sctp://0.0.0.0:3478' }, 'RELAY_LISTEN');
        refuses(
            { RELAY_LISTEN: 'udp://0.0.0.0:3478?proxy=required' },
            'RELAY_LISTEN',
            'PROXY over UDP',
        );
        refuses(
            { RELAY_LISTEN: 'udp://0.0.0.0:3478,udp://0.0.0.0:3478' },
            'RELAY_LISTEN',
            'a listener twice',
        );
        refuses({ RELAY_LOG_LEVEL: 'verbose' }, 'RELAY_LOG_LEVEL');
        refuses({ RELAY_STUN_BINDING: 'yes' }, 'RELAY_STUN_BINDING');
        refuses({ RELAY_TOTAL_MBPS: 'lots' }, 'RELAY_TOTAL_MBPS');
        refuses(
            { RELAY_MAX_LIFETIME_SECONDS: '7200' },
            'RELAY_MAX_LIFETIME_SECONDS',
        );
        refuses(
            { RELAY_CHAT_GRACE_SECONDS: '14401' },
            'RELAY_CHAT_GRACE_SECONDS',
            'past the five-hour call',
        );
        refuses(
            { RELAY_CHAT_MAX_CREDENTIAL_SECONDS: '3601' },
            'RELAY_CHAT_MAX_CREDENTIAL_SECONDS',
        );
        refuses({ RELAY_CHAT_PEERS: 'anything' }, 'RELAY_CHAT_PEERS');
        refuses(
            { RELAY_DENIED_PEER_PORTS: '1900,abc' },
            'RELAY_DENIED_PEER_PORTS',
        );
        refuses(
            { RELAY_TRUSTED_PROXIES: '127.0.0.1/33' },
            'RELAY_TRUSTED_PROXIES',
        );
        refuses(
            { RELAY_CHAT_SECRETS: 'typo-in-the-name' },
            'RELAY_CHAT_SECRETS',
            'an unknown setting',
        );
    });

    test('the relayed range', () => {
        refuses({ RELAY_PORT_MIN: '1000' }, 'RELAY_PORT_MIN', 'below 1024');
        refuses(
            { RELAY_PORT_MIN: '50000', RELAY_PORT_MAX: '40000' },
            'RELAY_PORT_MIN',
            'min above max',
        );
        refuses(
            { RELAY_MAX_ALLOCATIONS: '1001' },
            'RELAY_MAX_ALLOCATIONS',
            'a range smaller than the allocation total',
        );
        refuses(
            { RELAY_PORT_MIN: '3000', RELAY_PORT_MAX: '4000' },
            'RELAY_LISTEN',
            'a listener port in the range',
        );
        refuses(
            { RELAY_HEALTH_LISTEN: '127.0.0.1:41500' },
            'RELAY_HEALTH_LISTEN',
            'the health port in the range',
        );
        refuses(
            { RELAY_DENIED_PEER_PORTS: '41500' },
            'RELAY_DENIED_PEER_PORTS',
            'a denied port in the range',
        );
    });

    test('secrets', () => {
        refuses(
            { RELAY_CHAT_SECRET_FILE: '/etc/oblivion/chat' },
            'RELAY_CHAT_SECRET',
            'both a secret and its _FILE',
        );
        refuses({ RELAY_CHAT_SECRET: 'too-short' }, 'RELAY_CHAT_SECRET');
        refuses(
            {
                RELAY_CHAT_SECRET: '',
                RELAY_CHAT_SECRET_FILE: join(tempDir(), 'none'),
            },
            'RELAY_CHAT_SECRET_FILE',
            'an unreadable secret file',
        );
        refuses(
            { RELAY_CHAT_PREVIOUS_SECRET: SECRET },
            'RELAY_CHAT_PREVIOUS_SECRET',
            'previous equal to current',
        );
        refuses(
            { RELAY_CONTROL_SECRET: '', RELAY_CONTROL_KEY_ID: '' },
            'RELAY_CONTROL_SECRET',
            'chat on without a control secret',
        );
        refuses(
            { RELAY_CONTROL_SECRET: SECRET },
            'RELAY_CONTROL_SECRET',
            'a control secret equal to a credential secret',
        );
        refuses({ RELAY_CONTROL_KEY_ID: '' }, 'RELAY_CONTROL_KEY_ID');
        refuses({ RELAY_CONTROL_KEY_ID: 'has spaces' }, 'RELAY_CONTROL_KEY_ID');
        refuses(
            {
                RELAY_CONTROL_PREVIOUS_SECRET:
                    'previous-control-secret-0123456789abcd',
            },
            'RELAY_CONTROL_PREVIOUS_KEY_ID',
        );
        // A secret file works, and a trailing newline isn't part of it.
        const dir = tempDir();
        writeFileSync(join(dir, 'chat'), `${SECRET}\n`);
        const config = readConfig(
            baseEnv({
                RELAY_CHAT_SECRET: '',
                RELAY_CHAT_SECRET_FILE: join(dir, 'chat'),
            }),
        );
        assert.equal(config.secrets.chat.current.toString(), SECRET);
    });

    test('any RELAY_CCTV_* or RELAY_REMOTE_* setting stops start-up: those classes are deferred', () => {
        for (const name of [
            'RELAY_CCTV_SECRET',
            'RELAY_CCTV_PEERS',
            'RELAY_REMOTE_SECRET_FILE',
            'RELAY_REMOTE_GRACE_SECONDS',
        ]) {
            refuses({ [name]: '' }, name);
        }
    });

    test('TLS, health and the control channel', () => {
        refuses(
            { RELAY_LISTEN: 'tls://127.0.0.1:5350' },
            'RELAY_TLS_CERT_FILE',
            'a TLS listener without a certificate',
        );
        const dir = tempDir();
        refuses(
            {
                RELAY_LISTEN: 'tcp+tls://127.0.0.1:443',
                RELAY_TLS_CERT_FILE: join(dir, 'none.pem'),
                RELAY_TLS_KEY_FILE: join(dir, 'none.key'),
            },
            'RELAY_TLS_CERT_FILE',
            'an unreadable certificate',
        );
        refuses(
            { RELAY_HEALTH_LISTEN: '0.0.0.0:9478' },
            'RELAY_HEALTH_LISTEN',
            'a non-loopback health address',
        );
        for (const value of [
            '0.0.0.0:9479',
            '[::]:9479',
            '203.0.113.5:9479',
            '8.8.8.8:9479',
        ]) {
            refuses(
                { RELAY_CONTROL_LISTEN: value },
                'RELAY_CONTROL_LISTEN',
                value,
            );
        }
        assert.equal(
            readConfig(baseEnv({ RELAY_CONTROL_LISTEN: '10.0.0.5:9479' }))
                .controlListen.host,
            '10.0.0.5',
        );
        assert.equal(
            readConfig(baseEnv({ RELAY_CONTROL_LISTEN: '100.64.0.2:9479' }))
                .controlListen.host,
            '100.64.0.2',
        );
        refuses(
            { RELAY_CONTROL_ALLOWED_SOURCES: '0.0.0.0/0' },
            'RELAY_CONTROL_ALLOWED_SOURCES',
            'a public CIDR in the sources',
        );
        refuses(
            { RELAY_CONTROL_ALLOWED_SOURCES: '127.0.0.1/32,203.0.113.0/24' },
            'RELAY_CONTROL_ALLOWED_SOURCES',
        );
        refuses(
            { RELAY_CONTROL_ALLOWED_SOURCES: '10.0.0.0/7' },
            'RELAY_CONTROL_ALLOWED_SOURCES',
            'wider than a private range',
        );
    });

    test('log retention outside 1 to 90 days', () => {
        refuses({ RELAY_LOG_RETENTION_DAYS: '0' }, 'RELAY_LOG_RETENTION_DAYS');
        refuses({ RELAY_LOG_RETENTION_DAYS: '91' }, 'RELAY_LOG_RETENTION_DAYS');
        assert.equal(readConfig(baseEnv()).logRetentionDays, 14);
    });

    test('an unreadable revocations.json, a missing log directory or a bad deny file stops start-up', async () => {
        const logDir = tempDir();
        const stateDir = tempDir();
        writeFileSync(join(stateDir, 'revocations.json'), '{ not json');
        const overrides = {
            listeners: [],
            healthListen: { host: '127.0.0.1', port: 0 },
            controlListen: { host: '127.0.0.1', port: 0 },
        };
        const start = (env) =>
            startRelay(
                readConfig(
                    baseEnv({
                        RELAY_LOG_DIR: logDir,
                        RELAY_STATE_DIR: stateDir,
                        ...env,
                    }),
                    overrides,
                ),
                { opsWrite: () => {} },
            );
        await assert.rejects(
            start({}),
            (error) =>
                error.setting === 'RELAY_STATE_DIR' &&
                error.message.includes('revocations.json'),
        );
        await assert.rejects(
            start({ RELAY_LOG_DIR: join(logDir, 'missing') }),
            (error) => error.setting === 'RELAY_LOG_DIR',
        );
        writeFileSync(
            join(stateDir, 'revocations.json'),
            JSON.stringify({ version: 1, revocations: [] }),
        );
        writeFileSync(join(stateDir, 'deny'), 'not an id\n');
        await assert.rejects(
            start({ RELAY_DENY_FILE: join(stateDir, 'deny') }),
            (error) => error.setting === 'RELAY_DENY_FILE',
        );
        const relay = await start({});
        await relay.close();
    });
});
