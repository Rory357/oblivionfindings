/**
 * Plain-language words for Security & Devices keys — enum values, event
 * types, state codes and record types. Sentence case in NZ English, with
 * technical acronyms kept in their usual capitals (ICMP, WinRM, SNMP…).
 * Keys stay unchanged for logic; only the words people read change.
 */
const ACRONYMS: Record<string, string> = {
    api: 'API',
    cctv: 'CCTV',
    cpu: 'CPU',
    dhcp: 'DHCP',
    dns: 'DNS',
    gps: 'GPS',
    http: 'HTTP',
    https: 'HTTPS',
    icmp: 'ICMP',
    id: 'ID',
    iot: 'IoT',
    ip: 'IP',
    it: 'IT',
    lan: 'LAN',
    lldp: 'LLDP',
    lte: 'LTE',
    mac: 'MAC',
    mqtt: 'MQTT',
    nfc: 'NFC',
    nvr: 'NVR',
    poe: 'PoE',
    rfid: 'RFID',
    rtsp: 'RTSP',
    sdwan: 'SD-WAN',
    sms: 'SMS',
    snmp: 'SNMP',
    snmpv3: 'SNMPv3',
    sos: 'SOS',
    ssh: 'SSH',
    ssl: 'SSL',
    tcp: 'TCP',
    tls: 'TLS',
    udp: 'UDP',
    ups: 'UPS',
    url: 'URL',
    usb: 'USB',
    vpn: 'VPN',
    wan: 'WAN',
    wifi: 'Wi-Fi',
    winrm: 'WinRM',
};

function words(value: string): string[] {
    return value
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .split(/[\s._:-]+/)
        .filter(Boolean)
        .map((word) => ACRONYMS[word.toLowerCase()] ?? word.toLowerCase());
}

/** "collection_unavailable" → "Collection unavailable", "winrm" → "WinRM". */
export function readableKey(
    value: string | null | undefined,
    fallback = 'Not recorded',
): string {
    const parts = value ? words(value) : [];
    if (!parts.length) return fallback;
    const [first, ...rest] = parts;
    return [first.charAt(0).toUpperCase() + first.slice(1), ...rest].join(' ');
}

/** The same words for use mid-sentence: "Reported collection unavailable". */
export function readablePhrase(
    value: string | null | undefined,
    fallback = 'not recorded',
): string {
    const parts = value ? words(value) : [];
    return parts.length ? parts.join(' ') : fallback;
}
