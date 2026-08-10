import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

function source(path: string): string {
    return readFileSync(resolve(process.cwd(), path), 'utf8');
}

describe('Security & Devices governed confirmations', () => {
    it('keeps Device Group destructive actions out of browser-native confirms', () => {
        const groupShow = source(
            'resources/js/pages/security-devices/device-groups/show.tsx',
        );

        expect(groupShow).not.toMatch(/\bconfirm\s*\(/);
        expect(groupShow).toContain('title="Delete device group?"');
        expect(groupShow).toContain('title="Remove device from group?"');
    });

    it('uses named governed confirmations for every Device Profile destructive action', () => {
        const deviceShow = source(
            'resources/js/pages/security-devices/devices/show.tsx',
        );

        expect(deviceShow).not.toMatch(/\bconfirm\s*\(/);
        for (const title of [
            'Unlink asset from device?',
            'Remove device relationship?',
            'Delete device document?',
            'Release device assignment?',
            'Decommission device?',
        ]) {
            expect(deviceShow).toContain(`title="${title}"`);
        }
    });

    it('keeps Milesight credential, webhook, and mapping removal governed', () => {
        const milesight = source(
            'resources/js/pages/security-devices/integrations/milesight.tsx',
        );

        expect(milesight).not.toMatch(/\bconfirm\s*\(/);
        expect(milesight).toContain('title="Remove Milesight credentials?"');
        expect(milesight).toContain(
            'title="Disable Milesight webhook verification?"',
        );
        expect(milesight).toContain('title="Remove Milesight Site mapping?"');
    });

    it('keeps Queclink reject, restore, release, and legacy cleanup governed', () => {
        const queclink = source(
            'resources/js/pages/security-devices/integrations/queclink-hub.tsx',
        );

        expect(queclink).not.toMatch(/\bconfirm\s*\(/);
        for (const title of [
            'Reject tracker?',
            'Restore tracker to pending?',
            'Release tracker assignment?',
            'Remove legacy Queclink cloud credential?',
        ]) {
            expect(queclink).toContain(`title="${title}"`);
        }
    });
});
