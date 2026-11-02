import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

function source(path: string): string {
    return readFileSync(resolve(process.cwd(), path), 'utf8');
}

// S&D rebuild C6: the checks on the old dashboard, workspaces, discovery, device-group and
// command-batch pages went with those pages. What stays covers code that is still live.
describe('Security & Devices canonical workflow links', () => {
    it('routes SLA policy navigation through the supported ticket workspace and opens the real editor', () => {
        const navigation = source('app/Domain/It/ItModuleNavigation.php');
        const itWorkspace = source('resources/js/pages/it/index.tsx');

        expect(navigation).not.toContain('/it?tab=sla');
        expect(navigation).toContain('/it?tab=tickets&action=sla');
        expect(itWorkspace).toContain(
            "url.searchParams.get('action') !== 'sla'",
        );
        expect(itWorkspace).toContain("setModal({ type: 'sla' })");
    });

    it('keeps IT catalogue navigation permission-aware and normalises unsupported tabs', () => {
        const navigation = source('app/Domain/It/ItModuleNavigation.php');
        const itWorkspace = source('resources/js/pages/it/index.tsx');

        expect(navigation).toContain('$canRequest');
        expect(navigation).toContain(
            "? self::item('Service catalogue', '/it?tab=catalog'",
        );
        expect(itWorkspace).toContain('const VIEW_TABS = new Set([');
        expect(itWorkspace).toContain(
            "const REQUEST_TABS = new Set(['catalog', 'my-tickets']);",
        );
        expect(itWorkspace).toContain(
            'if (VIEW_TABS.has(id)) return can.view;',
        );
        expect(itWorkspace).toContain(
            'if (REQUEST_TABS.has(id)) return can.request;',
        );
    });

    it('sends IT navigation to the new Security & devices homes, never a retired page', () => {
        const navigation = source('app/Domain/It/ItModuleNavigation.php');

        expect(navigation).toContain("self::item('Monitoring', '/security-devices/alerts'");
        expect(navigation).toContain(
            "self::item('Integrations & API', '/security-devices/setup/services?tab=connected'",
        );
        expect(navigation).not.toContain("'/security-devices/monitoring'");
        expect(navigation).not.toContain("'/security-devices/integrations'");
    });

    it('requires a reason and preserves history when a Device relationship is removed', () => {
        const deviceProfile = source(
            'resources/js/pages/security-devices/devices/show.tsx',
        );
        // History rows are rendered by the shared device profile records.
        const profileRecords = source(
            'resources/js/pages/security-devices/devices/device-profile-records.tsx',
        );

        expect(deviceProfile).toContain(
            'Reason for removing this relationship',
        );
        expect(deviceProfile).toContain('data: { reason }');
        expect(deviceProfile).toContain(
            'the relationship is retained as history',
        );
        expect(deviceProfile).toContain('rows={relationshipHistory}');
        expect(profileRecords).toContain('title="Relationship history"');
        expect(profileRecords).toContain('...rows.parents.map(');
        expect(profileRecords).toContain('...rows.children.map(');
        expect(profileRecords).toContain("unlink_reason: 'Removal reason'");
        expect(profileRecords).toContain('this device');
    });

    it('renders nested command evidence in plain language instead of raw JSON', () => {
        const profile = source(
            'resources/js/pages/security-devices/devices/device-profile-sections.tsx',
        );

        expect(profile).toContain('formatReadableOperationalState(state)');
        expect(profile).not.toContain('JSON.stringify(value)');
    });
});
