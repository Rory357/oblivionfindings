#!/usr/bin/env node
// Local development only: one origin for the app and Oblivion Live, as nginx gives production.
// /chat-live upgrades go to the gateway; every other request goes to the app (php -S or Herd).
//
//   node live/tools/dev-proxy.mjs --port 8899 --app 127.0.0.1:8898 --live 127.0.0.1:8091 [--host 127.0.0.1]

import { createServer, request } from 'node:http';
import { connect } from 'node:net';

function option(name, fallback) {
    const index = process.argv.indexOf(`--${name}`);
    return index === -1 ? fallback : process.argv[index + 1];
}

const port = Number(option('port', '8899'));
// Loopback only. A second address such as 127.0.0.2 gives a second browser identity
// (cookies are per host, not per port).
const host = option('host', '127.0.0.1');
const [appHost, appPort] = option('app', '127.0.0.1:8898').split(':');
const [liveHost, livePort] = option('live', '127.0.0.1:8091').split(':');
const livePath = option('path', '/chat-live');

const server = createServer((incoming, outgoing) => {
    const forwarded = request(
        {
            host: appHost,
            port: Number(appPort),
            method: incoming.method,
            path: incoming.url,
            headers: {
                ...incoming.headers,
                'x-forwarded-host': incoming.headers.host,
                'x-forwarded-proto': 'http',
            },
        },
        (response) => {
            outgoing.writeHead(response.statusCode ?? 502, response.rawHeaders);
            response.pipe(outgoing);
        },
    );
    forwarded.on('error', () => {
        if (!outgoing.headersSent)
            outgoing.writeHead(502, { 'Content-Type': 'text/plain' });
        outgoing.end('App unavailable\n');
    });
    incoming.pipe(forwarded);
});

server.on('upgrade', (incoming, socket, head) => {
    const pathname = new URL(incoming.url, 'http://proxy.invalid').pathname;
    if (pathname !== livePath) {
        socket.end(
            'HTTP/1.1 404 Not Found\r\nConnection: close\r\nContent-Length: 0\r\n\r\n',
        );
        return;
    }
    // Pass the request through byte for byte; the gateway does the handshake itself.
    const upstream = connect(Number(livePort), liveHost, () => {
        const lines = [
            `${incoming.method} ${incoming.url} HTTP/${incoming.httpVersion}`,
        ];
        for (let index = 0; index < incoming.rawHeaders.length; index += 2) {
            lines.push(
                `${incoming.rawHeaders[index]}: ${incoming.rawHeaders[index + 1]}`,
            );
        }
        upstream.write(lines.join('\r\n') + '\r\n\r\n');
        if (head.length) upstream.write(head);
        socket.pipe(upstream).pipe(socket);
    });
    const close = () => {
        socket.destroy();
        upstream.destroy();
    };
    upstream.on('error', close);
    socket.on('error', close);
    upstream.on('close', close);
    socket.on('close', close);
});

server.listen(port, host, () => {
    console.log(
        `dev proxy on http://${host}:${port} (app ${appHost}:${appPort}, live ${liveHost}:${livePort}${livePath})`,
    );
});
