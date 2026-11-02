// A self-signed test certificate made at run time with node:crypto, so no private key is ever
// committed. Minimal X.509 v3 (RFC 5280) in DER: an EC P-256 key signed with ECDSA-SHA256.

import { generateKeyPairSync, randomBytes, sign } from 'node:crypto';

function length(size) {
    if (size < 0x80) return Buffer.from([size]);
    const bytes = [];
    for (let rest = size; rest > 0; rest >>= 8) bytes.unshift(rest & 0xff);
    return Buffer.from([0x80 | bytes.length, ...bytes]);
}

const tlv = (tag, ...parts) => {
    const body = Buffer.concat(parts);
    return Buffer.concat([Buffer.from([tag]), length(body.length), body]);
};
const sequence = (...parts) => tlv(0x30, ...parts);
const set = (...parts) => tlv(0x31, ...parts);
const oid = (text) => {
    const [a, b, ...rest] = text.split('.').map(Number);
    const bytes = [40 * a + b];
    for (const value of rest) {
        const chunk = [value & 0x7f];
        for (let rest7 = value >> 7; rest7 > 0; rest7 >>= 7)
            chunk.unshift((rest7 & 0x7f) | 0x80);
        bytes.push(...chunk);
    }
    return tlv(0x06, Buffer.from(bytes));
};
const utf8 = (text) => tlv(0x0c, Buffer.from(text, 'utf8'));
const time = (date) => {
    const text = date.toISOString().replace(/[-:T]/g, '').slice(2, 14) + 'Z';
    return tlv(0x17, Buffer.from(text, 'latin1'));
};

/** Returns { cert, key } as PEM strings for `name`, valid from now for `days`. */
export function selfSignedCertificate(
    name = 'relay.test',
    { days = 30, notBefore = new Date(Date.now() - 60_000) } = {},
) {
    const { privateKey, publicKey } = generateKeyPairSync('ec', {
        namedCurve: 'P-256',
    });
    const ecdsaSha256 = sequence(oid('1.2.840.10045.4.3.2'));
    const subject = sequence(set(sequence(oid('2.5.4.3'), utf8(name))));
    const notAfter = new Date(notBefore.getTime() + days * 86_400_000);
    const serial = randomBytes(8);
    serial[0] &= 0x7f;
    const subjectAltName = sequence(
        oid('2.5.29.17'),
        tlv(0x04, sequence(tlv(0x82, Buffer.from(name, 'latin1')))),
    );
    const tbs = sequence(
        tlv(0xa0, tlv(0x02, Buffer.from([2]))),
        tlv(0x02, serial),
        ecdsaSha256,
        subject,
        sequence(time(notBefore), time(notAfter)),
        subject,
        publicKey.export({ type: 'spki', format: 'der' }),
        tlv(0xa3, sequence(subjectAltName)),
    );
    const signature = sign('sha256', tbs, privateKey);
    const der = sequence(
        tbs,
        ecdsaSha256,
        tlv(0x03, Buffer.from([0]), signature),
    );
    const pem = `-----BEGIN CERTIFICATE-----\n${der
        .toString('base64')
        .match(/.{1,64}/g)
        .join('\n')}\n-----END CERTIFICATE-----\n`;
    return {
        cert: pem,
        key: privateKey.export({ type: 'pkcs8', format: 'pem' }),
        notAfter,
    };
}
