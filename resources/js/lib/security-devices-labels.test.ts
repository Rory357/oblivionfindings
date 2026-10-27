import { describe, expect, it } from 'vitest';
import { readableKey, readablePhrase } from './security-devices-labels';

describe('Security & Devices readable labels', () => {
    it('turns keys into sentence case with the usual acronym capitals', () => {
        expect(readableKey('collection_unavailable')).toBe(
            'Collection unavailable',
        );
        expect(readableKey('icmp')).toBe('ICMP');
        expect(readableKey('winrm_inventory')).toBe('WinRM inventory');
        expect(readableKey('snmp-health')).toBe('SNMP health');
        expect(readableKey('fall.detected')).toBe('Fall detected');
        expect(readableKey('it_infrastructure')).toBe('IT infrastructure');
        expect(readableKey('DeviceMaintenanceRecord')).toBe(
            'Device maintenance record',
        );
        expect(readableKey('evidence_backed')).toBe('Based on active checks');
    });

    it('keeps a fallback and a lowercase phrase form for mid-sentence use', () => {
        expect(readableKey(null)).toBe('Not recorded');
        expect(readableKey('', 'Unknown')).toBe('Unknown');
        expect(readablePhrase('runtime_unavailable')).toBe(
            'runtime unavailable',
        );
        expect(readablePhrase('tls_expiry')).toBe('TLS expiry');
        expect(readablePhrase(undefined)).toBe('not recorded');
    });
});
