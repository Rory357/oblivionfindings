// Where the relay may send (spec section 6). One decision: may an allocation of this class exchange
// datagrams with address A and port P? Every CreatePermission, ChannelBind, Send, ChannelData and
// incoming datagram comes through decide().

import { CidrList, cidrsOverlap, parseCidr, parseIp } from './addresses.mjs';

const list = (...cidrs) => new CidrList(cidrs.map(parseCidr));

// 6.1: never relayed to or from, whatever any allowlist says.
export const ALWAYS_REFUSED = list(
    '0.0.0.0/8',
    '::/128',
    '127.0.0.0/8',
    '::1/128',
    '169.254.0.0/16',
    'fe80::/10',
    '224.0.0.0/4',
    'ff00::/8',
    '240.0.0.0/4',
    '255.255.255.255/32',
    '::ffff:0:0/96',
    '2001::/32',
    '2002::/16',
    '192.88.99.0/24',
    '64:ff9b::/96',
    '64:ff9b:1::/48',
);

// 6.2: refused unless the class's allowlist names them.
export const ALLOWLIST_ONLY = list(
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
    '100.64.0.0/10',
    'fc00::/7',
    'fec0::/10',
    '192.0.0.0/24',
    '2001::/23',
    '192.0.2.0/24',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '2001:db8::/32',
    '3fff::/20',
    '198.18.0.0/15',
    '100::/64',
);

const GLOBAL_UNICAST_V6 = list('2000::/3');

// 6.1: system ports and other STUN and TURN servers are always refused as peer ports.
export function fixedDeniedPort(port) {
    return port <= 1023 || port === 3478 || port === 5349;
}

/** Validate RELAY_<CLASS>_ALLOWED_PEER_CIDRS (6.3): at most 8, narrow, and clear of 6.1. */
export function parseAllowlist(text, name) {
    const allowed = CidrList.parse(text, name);
    if (allowed.cidrs.length > 8)
        throw new Error(`${name} may list at most 8 CIDRs`);
    for (const cidr of allowed.cidrs) {
        if (
            (cidr.family === 4 && cidr.prefix < 24) ||
            (cidr.family === 6 && cidr.prefix < 120)
        ) {
            throw new Error(
                `${name} entries must be /24 or narrower for IPv4 and /120 or narrower for IPv6`,
            );
        }
        if (
            ALWAYS_REFUSED.cidrs.some((refused) => cidrsOverlap(refused, cidr))
        ) {
            throw new Error(
                `${name} overlaps an address range that is always refused`,
            );
        }
    }
    return allowed;
}

export class PeerRules {
    /**
     * ownPool: the external and bound relayed addresses (text). ownAddresses: those plus every
     * interface address of the host. deniedPorts: RELAY_DENIED_PEER_PORTS.
     */
    constructor({ ownPool, ownAddresses, portMin, portMax, deniedPorts }) {
        const key = (text) => {
            const ip = parseIp(text);
            return ip ? `${ip.family}:${ip.bytes.toString('hex')}` : null;
        };
        this.pool = new Set(ownPool.map(key).filter(Boolean));
        this.own = new Set(
            [...ownPool, ...ownAddresses].map(key).filter(Boolean),
        );
        this.portMin = portMin;
        this.portMax = portMax;
        this.deniedPorts = deniedPorts;
    }

    inRange(port) {
        return port >= this.portMin && port <= this.portMax;
    }

    /** True for one of this relay's relayed addresses with a port in the relayed range (6.3). */
    isOwnPool(family, bytes, port) {
        return (
            this.pool.has(`${family}:${bytes.toString('hex')}`) &&
            (port === null || this.inRange(port))
        );
    }

    /**
     * policy is { peers: 'public' | 'relay-only', allowed: CidrList }. port is null for
     * CreatePermission, which ignores ports. Returns { ok, own }.
     */
    decide(policy, family, bytes, port) {
        const refused = { ok: false, own: false };
        const key = `${family}:${bytes.toString('hex')}`;
        if (this.pool.has(key) && (port === null || this.inRange(port)))
            return { ok: true, own: true };
        if (this.own.has(key)) return refused;
        if (ALWAYS_REFUSED.contains(family, bytes)) return refused;
        if (
            port !== null &&
            (fixedDeniedPort(port) || this.deniedPorts.has(port))
        )
            return refused;
        if (policy.allowed.contains(family, bytes))
            return { ok: true, own: false };
        if (policy.peers !== 'public') return refused;
        if (ALLOWLIST_ONLY.contains(family, bytes)) return refused;
        if (family === 6 && !GLOBAL_UNICAST_V6.contains(family, bytes))
            return refused;
        return { ok: true, own: false };
    }
}

const CLIENT_REFUSED = list(
    '0.0.0.0/8',
    '::/128',
    '224.0.0.0/4',
    'ff00::/8',
    '255.255.255.255/32',
    '::ffff:0:0/96',
    '2001::/32',
    '2002::/16',
    '192.88.99.0/24',
);
const LOOPBACK = list('127.0.0.0/8', '::1/128');

/** Client addresses (6.4): private and CGNAT are fine; loopback only on a loopback listener. */
export function clientAllowed(text, loopbackListener) {
    const ip = parseIp(text);
    if (!ip || CLIENT_REFUSED.contains(ip.family, ip.bytes)) return false;
    return loopbackListener || !LOOPBACK.contains(ip.family, ip.bytes);
}
