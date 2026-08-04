import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

function source(path: string): string {
    return readFileSync(resolve(process.cwd(), path), 'utf8');
}

describe('Security & Devices canonical workflow links', () => {
    it('routes estate domains through the shared canonical navigation contract', () => {
        const dashboard = source(
            'resources/js/pages/security-devices/dashboard.tsx',
        );

        expect(dashboard).toContain('securityDevicesDomainHref(');
        expect(dashboard).not.toContain("tracking: '/tracking'");
        expect(dashboard).not.toContain("iot_healthcare: '/healthcare'");
        expect(dashboard).not.toContain("it_infrastructure: '/network-it'");
        expect(dashboard).not.toContain("facilities: '/facilities-iot'");
    });

    it('connects Site technology and IT ticket context to the canonical Site profile', () => {
        const siteTechnology = source(
            'resources/js/pages/security-devices/sites/show.tsx',
        );
        const ticket = source('resources/js/pages/it/tickets/show.tsx');

        expect(siteTechnology).toContain('href={`/sites/${site.id}`}');
        expect(siteTechnology).toContain('Open Site profile');
        expect(ticket).toContain('href={ticket.site.href}');
        expect(ticket).toContain('Open {ticket.site.name} profile');
    });

    it('keeps operational Site and Device context navigable', () => {
        const discovery = source(
            'resources/js/pages/security-devices/discovery.tsx',
        );
        const commandBatch = source(
            'resources/js/pages/security-devices/command-batches/show.tsx',
        );
        const groupShow = source(
            'resources/js/pages/security-devices/device-groups/show.tsx',
        );
        const ruleBuilder = source(
            'resources/js/pages/security-devices/device-groups/auto-rule-builder.tsx',
        );

        expect(discovery).toContain('href={path.site.href}');
        expect(commandBatch).toContain('target.site.href');
        expect(groupShow).toContain(
            'href={`/security-devices/devices/${device.id}`}',
        );
        expect(ruleBuilder).toContain(
            'href={`/security-devices/devices/${device.id}`}',
        );
    });

    it('labels collector-free coverage as configuration and renders truthful discovery limits', () => {
        const discovery = source(
            'resources/js/pages/security-devices/discovery.tsx',
        );

        expect(discovery).toContain('Collector-free configuration');
        expect(discovery).toContain('workspace.summary.runs_truncated');
        expect(discovery).toContain('workspace.summary.candidates_truncated');
        expect(discovery).toContain('.unsupported_state,');
        expect(discovery).toContain('workspace.limitations.unsupported_note');
        expect(discovery).not.toContain('Not assessed');
        expect(discovery).not.toContain('result drill-down');
    });

    it('does not claim operational queues are live without freshness evidence', () => {
        const dashboard = source(
            'resources/js/pages/security-devices/dashboard.tsx',
        );

        expect(dashboard).toContain(
            'Current operational queues from the latest available',
        );
        expect(dashboard).not.toContain('Live operational queues');
    });
});
