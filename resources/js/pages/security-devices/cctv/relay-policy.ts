export type CctvRelayPolicy = {
    relay_cidrs: string[];
    relay_port_min: number;
    relay_port_max: number;
};
type Address = { bits: 32 | 128; value: bigint };
function address(value: string): Address | null {
    if (/^(?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){3}$/.test(value)) {
        const octets = value.split('.').map(Number);
        return octets.every((octet) => octet <= 255)
            ? {
                  bits: 32,
                  value: octets.reduce(
                      (total, octet) => (total << 8n) | BigInt(octet),
                      0n,
                  ),
              }
            : null;
    }
    if (!/^[0-9a-fA-F:]+$/.test(value) || !value.includes(':')) return null;
    const halves = value.split('::');
    if (halves.length > 2) return null;
    const left = halves[0] === '' ? [] : halves[0].split(':');
    const right =
        halves.length < 2 || halves[1] === '' ? [] : halves[1].split(':');
    if ([...left, ...right].some((group) => !/^[0-9a-fA-F]{1,4}$/.test(group)))
        return null;
    const missing = 8 - left.length - right.length;
    if (halves.length === 1 ? missing !== 0 : missing < 1) return null;
    const groups = [...left, ...new Array(missing).fill('0'), ...right];
    const parsed = groups.reduce(
        (total, group) => (total << 16n) | BigInt(parseInt(group, 16)),
        0n,
    );
    // IPv4-mapped addresses have two possible pool interpretations; require native literals.
    if (parsed >> 32n === 65535n) return null;
    return { bits: 128, value: parsed };
}
function network(value: unknown): { address: Address; shift: bigint } | null {
    if (typeof value !== 'string' || value.length > 64) return null;
    const parts = value.split('/');
    if (parts.length !== 2 || !/^(0|[1-9]\d{0,2})$/.test(parts[1])) return null;
    const parsed = address(parts[0]),
        prefix = Number(parts[1]);
    if (
        !parsed ||
        prefix > parsed.bits ||
        prefix < (parsed.bits === 32 ? 24 : 120)
    )
        return null;
    const shift = BigInt(parsed.bits - prefix);
    if ((parsed.value >> shift) << shift !== parsed.value) return null;
    return { address: parsed, shift };
}
export function validRelayPolicy(value: Record<string, unknown>): boolean {
    return (
        Array.isArray(value.relay_cidrs) &&
        value.relay_cidrs.length >= 1 &&
        value.relay_cidrs.length <= 8 &&
        value.relay_cidrs.every((cidr) => network(cidr) !== null) &&
        new Set(value.relay_cidrs).size === value.relay_cidrs.length &&
        Number.isInteger(value.relay_port_min) &&
        Number.isInteger(value.relay_port_max) &&
        Number(value.relay_port_min) >= 1024 &&
        Number(value.relay_port_max) <= 65535 &&
        Number(value.relay_port_max) >= Number(value.relay_port_min) &&
        Number(value.relay_port_max) - Number(value.relay_port_min) < 32768
    );
}
function allowed(
    value: string,
    port: string,
    policy: CctvRelayPolicy,
): boolean {
    if (!/^[1-9]\d{0,4}$/.test(port)) return false;
    const parsed = address(value),
        number = Number(port);
    return (
        !!parsed &&
        number >= policy.relay_port_min &&
        number <= policy.relay_port_max &&
        policy.relay_cidrs.some((cidr) => {
            const pool = network(cidr);
            return (
                !!pool &&
                parsed.bits === pool.address.bits &&
                parsed.value >> pool.shift === pool.address.value >> pool.shift
            );
        })
    );
}
function connection(
    value: string,
    policy: CctvRelayPolicy,
    port?: string,
): boolean {
    const match = /^IN (IP4|IP6) (\S+)$/.exec(value);
    if (!match) return false;
    const parsed = address(match[2]);
    if (!parsed || parsed.bits !== (match[1] === 'IP4' ? 32 : 128))
        return false;
    if (parsed.value === 0n)
        return port === undefined || port === '9' || port === '0';
    return allowed(match[2], port ?? String(policy.relay_port_min), policy);
}
export function validRelayCandidate(
    line: string,
    policy: CctvRelayPolicy,
): boolean {
    const parts = line.slice('a=candidate:'.length).split(' ');
    if (
        parts.length < 8 ||
        parts.some((part) => !part) ||
        !/^[a-zA-Z0-9+/]{1,32}$/.test(parts[0]) ||
        parts[1] !== '1' ||
        !['udp', 'tcp'].includes(parts[2].toLowerCase()) ||
        !/^[1-9]\d{0,9}$/.test(parts[3]) ||
        Number(parts[3]) > 4294967295 ||
        !allowed(parts[4], parts[5], policy) ||
        parts[6] !== 'typ' ||
        parts[7] !== 'relay' ||
        (parts.length - 8) % 2 !== 0
    )
        return false;
    const extensions = new Map<string, string>();
    for (let index = 8; index < parts.length; index += 2) {
        const key = parts[index],
            value = parts[index + 1];
        if (extensions.has(key)) return false;
        extensions.set(key, value);
        if (key === 'raddr') {
            if (!['0.0.0.0', '::'].includes(value)) return false;
        } else if (key === 'rport') {
            if (value !== '0') return false;
        } else if (key === 'tcptype') {
            if (
                parts[2].toLowerCase() !== 'tcp' ||
                !['active', 'passive', 'so'].includes(value)
            )
                return false;
        } else if (key === 'ufrag') {
            if (!/^[a-zA-Z0-9+/]{4,256}$/.test(value)) return false;
        } else if (['generation', 'network-id', 'network-cost'].includes(key)) {
            if (!/^(0|[1-9]\d{0,4})$/.test(value) || Number(value) > 65535)
                return false;
        } else return false;
    }
    return (
        extensions.has('raddr') === extensions.has('rport') &&
        (parts[2].toLowerCase() !== 'tcp' || extensions.has('tcptype'))
    );
}
export function validRelayConnections(
    lines: string[],
    policy: CctvRelayPolicy,
): boolean {
    if (!validRelayPolicy(policy)) return false;
    for (const line of lines) {
        if (line.startsWith('c=') && !connection(line.slice(2), policy))
            return false;
        if (line.startsWith('a=rtcp:')) {
            const match = /^a=rtcp:(\d+) (IN IP[46] \S+)$/.exec(line);
            if (!match || !connection(match[2], policy, match[1])) return false;
        }
        if (/^a=(?:remote-candidates|ice-mismatch|ice-lite)(?::|$)/.test(line))
            return false;
        if (
            line.startsWith('a=ice-options:') &&
            line !== 'a=ice-options:trickle'
        )
            return false;
    }
    return true;
}
