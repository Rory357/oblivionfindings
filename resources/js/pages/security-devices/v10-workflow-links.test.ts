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
        // The dashboard's domain records are built by the shared estate
        // records module, which owns the canonical domain destinations.
        const estateRecords = source(
            'resources/js/components/security-devices/estate-records.tsx',
        );

        expect(dashboard).toContain(
            "from '@/components/security-devices/estate-records'",
        );
        expect(estateRecords).toContain(
            'destination: securityDevicesDomainHref(row.domain)',
        );
        for (const file of [dashboard, estateRecords]) {
            expect(file).not.toContain("tracking: '/tracking'");
            expect(file).not.toContain("iot_healthcare: '/healthcare'");
            expect(file).not.toContain("it_infrastructure: '/network-it'");
            expect(file).not.toContain("facilities: '/facilities-iot'");
        }
    });

    it('connects Site technology and IT ticket context to the canonical Site profile', () => {
        const siteTechnology = source(
            'resources/js/pages/security-devices/sites/show.tsx',
        );
        const ticket = source('resources/js/pages/it/tickets/show.tsx');

        expect(siteTechnology).toContain('{can.view_site_profile ? (');
        expect(siteTechnology).toContain("'?tab=technology'");
        expect(siteTechnology).toContain('Open Site profile');
        expect(siteTechnology).toContain('<SiteProfileAccessRequired');
        expect(ticket).toContain('href={ticket.site.href}');
        expect(ticket).toContain('v={ticket.site.name}');
    });

    it('keeps Healthcare Site context visible without emitting inaccessible profile links', () => {
        const presenter = source(
            'app/Domain/SecurityDevices/Presenters/HealthcareWorkspacePresenter.php',
        );
        const healthcare = source(
            'resources/js/components/security-devices/healthcare-workspace.tsx',
        );

        expect(presenter).toContain(
            "Gate::forUser($viewer)->allows('view', $site)",
        );
        expect(presenter).toContain(
            '\'href\' => $canViewSiteProfile ? "/sites/{$site->id}" : null',
        );
        expect(healthcare).toContain('device.location.site.href &&');
        expect(healthcare).toContain('<SiteProfileAccessRequired');
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

        // Collection paths keep their Site; the shared detail view links it
        // only when the server sent the canonical Security & Devices Site URL.
        expect(discovery).toContain('site: path.site,');
        expect(discovery).toContain(
            "site && site.href === '/security-devices/sites/' + site.id",
        );
        expect(discovery).toContain('<Link href={safeSiteHref(entry.site)!}>');
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
        const presenter = source(
            'app/Domain/SecurityDevices/Presenters/DiscoveryOperationsPresenter.php',
        );

        // The coverage label now comes from the server so every reader uses
        // the same wording.
        expect(presenter).toContain(
            "'path_label' => 'Collector-free monitor configuration'",
        );
        expect(discovery).toContain('title={w.direct_coverage.path_label}');
        expect(discovery).toContain('w.summary.runs_truncated');
        expect(discovery).toContain('w.summary.candidates_truncated');
        expect(discovery).toContain('plain(w.limitations.unsupported_state)');
        expect(discovery).toContain('w.limitations.unsupported_note');
        expect(discovery).not.toContain('Not assessed');
        expect(discovery).not.toContain('result drill-down');
    });

    it('does not claim operational queues are live without freshness evidence', () => {
        const dashboard = source(
            'resources/js/pages/security-devices/dashboard.tsx',
        );

        expect(dashboard).toContain(
            'Counts and queues from the existing device, monitoring and work records.',
        );
        expect(dashboard).toContain(
            'These are stored changes, not a live feed.',
        );
        expect(dashboard).not.toContain('Live operational queues');
    });

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
        expect(itWorkspace).toContain(
            'const tab = tabIsAllowed(requestedTab) ? requestedTab : capabilityDefault;',
        );
        expect(itWorkspace).not.toContain(
            'return can.view; // overview / tickets / provisioning / reports',
        );
    });

    it('keeps estate actions permission-aware and points open IT work at the supported queue', () => {
        const presenter = source(
            'app/Domain/SecurityDevices/Presenters/EstateOperationsPresenter.php',
        );
        const dashboard = source(
            'resources/js/pages/security-devices/dashboard.tsx',
        );
        const estateRecords = source(
            'resources/js/components/security-devices/estate-records.tsx',
        );

        expect(presenter).toContain('/it?tab=tickets&view=all_open');
        expect(presenter).not.toContain('/it?view=open');
        expect(presenter).toContain("'restriction_reason'");
        expect(estateRecords).toContain('row.restriction_reason ||');
        expect(estateRecords).toContain(
            'destination: allowed ? row.href : null',
        );
        expect(dashboard).toContain('data.can.view_devices');
        expect(dashboard).toContain('data.can.view_events');
        expect(dashboard).toContain('data.can.view_maintenance');
        expect(estateRecords).toContain(
            'Device access is required to open this Site.',
        );
    });

    it('explains the command-batch decision minimum accessibly', () => {
        const commandBatch = source(
            'resources/js/pages/security-devices/command-batches/show.tsx',
        );

        expect(commandBatch).toContain(
            'aria-describedby="batch-decision-comment-help"',
        );
        expect(commandBatch).toContain('id="batch-decision-comment-help"');
        expect(commandBatch).toContain('Minimum 10 characters.');
        expect(commandBatch).toContain('comment.trim().length < 10');
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
        const batch = source(
            'resources/js/pages/security-devices/command-batches/show.tsx',
        );
        const profile = source(
            'resources/js/pages/security-devices/devices/device-profile-sections.tsx',
        );

        expect(batch).toContain('formatReadableOperationalValue(entry)');
        expect(profile).toContain('formatReadableOperationalState(state)');
        expect(batch).not.toContain('JSON.stringify(entry)');
        expect(profile).not.toContain('JSON.stringify(value)');
    });
});
