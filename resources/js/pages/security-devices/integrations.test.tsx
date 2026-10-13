import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { integrationsFixture } from '../../../../.endpoint-tools/sd-ui-integrations-20261013/fixtures';
import IntegrationsHub from './integrations';
const mocks = vi.hoisted(() => ({
    actor: 7,
    can: {} as Record<string, boolean>,
    visit: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: { auth: { user: { id: mocks.actor }, can: mocks.can } },
        }),
        router: {
            ...actual.router,
            on: actual.router.on.bind(actual.router),
            visit: mocks.visit,
            reload: mocks.reload,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
beforeEach(() => {
    mocks.actor = 7;
    mocks.can = {
        'securityDevices.integrations.view': true,
        'securityDevices.integrations.manage': true,
        'securityDevices.devices.view': true,
    };
    mocks.visit.mockReset();
    mocks.reload.mockReset();
});
afterEach(cleanup);
const card = (name: string) =>
    screen.getByRole('article', { name: 'Provider record: ' + name });
async function details(name = 'UniFi') {
    fireEvent.keyDown(
        within(card(name)).getByRole('button', { name: 'Actions for ' + name }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'View evidence' }),
    );
    return screen.findByRole('dialog');
}
function invalid(status: number, path = '/security-devices/integrations') {
    const e = new CustomEvent('inertia:invalid', {
        cancelable: true,
        detail: {
            response: {
                status,
                config: { method: 'get', url: path },
                data: { private: 'Private error details' },
            },
        },
    });
    act(() => document.dispatchEvent(e));
    return e;
}
describe('Shared integration catalogue', () => {
    it('separates connected, old collection, unconfigured and unsupported/unavailable states', () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        expect(
            within(card('UniFi')).getByText('Connected'),
        ).toBeInTheDocument();
        expect(
            within(card('UniFi')).getByText('Old collection'),
        ).toBeInTheDocument();
        expect(
            within(card('Queclink')).getByText('Cloud API unavailable'),
        ).toBeInTheDocument();
        expect(
            within(card('Queclink')).getByText('Not supported'),
        ).toBeInTheDocument();
        expect(
            within(card('Milesight')).getByText('Not configured'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Healthy')).not.toBeInTheDocument();
    });
    it('retains every safe provider/connection/credential field through the full reader', async () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        const d = await details();
        for (const label of [
            'Provider key',
            'Vendor',
            'Summary',
            'Device scope',
            'Imported devices',
            'Events in past 24 hours',
            'Exceptions',
            'Read started',
            'Provider diagnostics',
        ])
            expect(within(d).getAllByText(label).length).toBeGreaterThan(0);
        fireEvent.click(
            within(d).getByRole('button', {
                name: /Connection & credentialsRetained evidence/,
            }),
        );
        for (const label of [
            'Connection',
            'Connected',
            'Last connection test',
            'Last provider sync',
            'Credentials configured',
            'Credential reference',
            'Credential label',
            'Credential source',
            'Rotation review',
            'Review cadence',
            'Rotated',
            'Credential created',
            'Credential last tested',
            'Site credentials',
            'Enabled Site credentials',
            'Site credentials needing attention',
            'Site credential capabilities',
        ])
            expect(within(d).getAllByText(label).length).toBeGreaterThan(0);
        expect(within(d).getByText('SYNTHETIC-REF-1234')).toBeInTheDocument();
        expect(
            within(d).getAllByText(/13 October 2026/).length,
        ).toBeGreaterThan(0);
    });
    it('retains bounded Site names/IDs/states, all sync counters and absolute times', async () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Sites & sync' }));
        const d = await details();
        for (const label of [
            'Provider Site mappings',
            'Mapped',
            'Unmapped',
            'Site records shown',
            'Site 1',
            'Site 1 record ID',
            'Site 1 mapping state',
            'Site 2',
            'Site 2 record ID',
            'Site 2 mapping state',
            'Sync state',
            'Sync freshness',
            'Last sync',
            'Items processed',
            'Items errored',
            'Sites with old sync',
            'Sites affected by sync',
            'Sync summary',
        ])
            expect(within(d).getAllByText(label).length).toBeGreaterThan(0);
        expect(within(d).getByText('Kauri House')).toBeInTheDocument();
        expect(within(d).getByText('Name not supplied')).toBeInTheDocument();
        expect(within(d).getByText('2 of 2')).toBeInTheDocument();
        expect(
            within(d).getByText('Synthetic sync needs review.'),
        ).toBeInTheDocument();
    });
    it('preserves health/support and every runtime capability limit without treating a declaration as acceptance', async () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Collected health' }));
        const d = await details();
        for (const label of [
            'Collection state',
            'Collection freshness',
            'Last attempted',
            'Last collected',
            'Collection summary',
            'Next health action',
            'Health destination',
            'Monitoring support',
            'Support scope',
            'Support note',
        ])
            expect(within(d).getAllByText(label).length).toBeGreaterThan(0);
        fireEvent.click(
            within(d).getByRole('button', {
                name: /Runtime capabilitiesRetained evidence/,
            }),
        );
        for (const label of [
            'Contract version',
            'Contract state',
            'Contract label',
            'Contract note',
            'Capabilities',
            'Page limit',
            'Minimum interval',
            'Backfill limit',
            'Cursor scopes',
            'Partial scopes',
            'Runtime exceptions',
            'Latest completion',
            'Latest exception',
            'Exception codes',
            'Disconnect availability',
            'Revoke availability',
        ])
            expect(within(d).getAllByText(label).length).toBeGreaterThan(0);
        expect(within(d).getByText('60 seconds')).toBeInTheDocument();
        expect(
            within(d).getByText(/Synthetic declared runtime/),
        ).toBeInTheDocument();
    });
    it('retains all reconciliation counters, action types/counts/next steps and catalogue boundaries', async () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        const d = await details();
        fireEvent.click(
            within(d).getByRole('button', {
                name: /Device reconciliationRetained evidence/,
            }),
        );
        for (const label of [
            'Imported devices',
            'Unassigned devices',
            'Duplicate candidates',
            'Unsupported checks',
        ])
            expect(within(d).getAllByText(label).length).toBeGreaterThan(0);
        fireEvent.click(
            within(d).getByRole('button', {
                name: /Required actionRetained evidence/,
            }),
        );
        expect(
            within(d).getByText('Action 2 affected count'),
        ).toBeInTheDocument();
        expect(
            within(d).getByRole('link', { name: 'Review imported devices' }),
        ).toHaveAttribute('href', '/security-devices/devices?view=unassigned');
        fireEvent.click(
            within(d).getByRole('button', {
                name: /Catalogue boundariesRetained evidence/,
            }),
        );
        for (const label of [
            'Sync becomes old after',
            'Credential review cadence',
            'Correlated alert owner',
            'Providers in catalogue',
            'Connected providers',
            'Connection errors',
            'Imported devices across providers',
            'Events in past 24 hours across providers',
            'Reconciliation exceptions across providers',
            'Mapped provider Sites across providers',
        ])
            expect(within(d).getAllByText(label).length).toBeGreaterThan(0);
        expect(within(d).getByText('Control Room')).toBeInTheDocument();
    });
    it('keeps original diagnostic/health/device destinations in both kebab and context actions', async () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        fireEvent.contextMenu(
            within(card('UniFi')).getByRole('button', {
                name: /UniFi Ubiquiti/,
            }),
            { clientX: 50, clientY: 60 },
        );
        fireEvent.click(
            screen.getByRole('menuitem', {
                name: 'Review imported devices (2)',
            }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/devices?view=unassigned',
        );
        fireEvent.keyDown(
            within(card('UniFi')).getByRole('button', {
                name: 'Actions for UniFi',
            }),
            { key: 'Enter', code: 'Enter' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', {
                name: 'Open UniFi diagnostics',
            }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/integrations/unifi',
        );
    });
    it('uses 50px shared table rows and a full reader with keyboard focus return', async () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const row = screen.getByRole('row', { name: /UniFi/ });
        expect(row).toHaveClass('h-[50px]');
        fireEvent.click(
            within(row).getByRole('button', {
                name: 'View evidence for UniFi',
            }),
        );
        const d = screen.getByRole('dialog');
        fireEvent.click(within(d).getAllByRole('button', { name: 'Close' })[0]);
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            within(row).getByRole('button', {
                name: 'View evidence for UniFi',
            }),
        ).toHaveFocus();
    });
    it('searches permitted details, resets on view selection and distinguishes zero mapping from healthy', () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        const search = screen.getByRole('searchbox', {
            name: 'Search provider records',
        });
        fireEvent.change(search, { target: { value: 'no-such-provider' } });
        expect(screen.getByText('No matching providers')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Sites & sync' }));
        expect(search).toHaveValue('');
        expect(
            within(card('Milesight')).getByText('Not measured'),
        ).toBeInTheDocument();
    });
    it('clears old private credential readers/search and disables manage destinations when current manage permission is withdrawn', async () => {
        const data = integrationsFixture(),
            r = render(<IntegrationsHub {...data} />);
        const d = await details();
        fireEvent.click(
            within(d).getByRole('button', {
                name: /Connection & credentialsRetained evidence/,
            }),
        );
        expect(screen.getByText('SYNTHETIC-REF-1234')).toBeInTheDocument();
        mocks.can['securityDevices.integrations.manage'] = false;
        r.rerender(<IntegrationsHub {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        const newD = await details();
        fireEvent.click(
            within(newD).getByRole('button', {
                name: /Connection & credentialsRetained evidence/,
            }),
        );
        expect(
            within(newD).getByText('Integration management access required'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('SYNTHETIC-REF-1234'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            within(newD).getAllByRole('button', { name: 'Close' })[0],
        );
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search provider records' }),
            { target: { value: 'SYNTHETIC-REF-1234' } },
        );
        expect(screen.getByText('No matching providers')).toBeInTheDocument();
    });
    it('removes device destinations after current device permission is withdrawn', async () => {
        mocks.can['securityDevices.devices.view'] = false;
        render(<IntegrationsHub {...integrationsFixture()} />);
        const d = await details();
        fireEvent.click(
            within(d).getByRole('button', {
                name: /Required actionRetained evidence/,
            }),
        );
        expect(
            within(d).queryByRole('link', { name: 'Review imported devices' }),
        ).not.toBeInTheDocument();
        expect(
            within(d).getByRole('link', { name: 'Review sync schedule' }),
        ).toHaveAttribute('href', '/security-devices/integrations/unifi');
    });
    it('quarantines all prior provider records for another actor or missing integration-view authority', async () => {
        const data = integrationsFixture(),
            r = render(<IntegrationsHub {...data} />);
        await details();
        mocks.actor = 8;
        r.rerender(<IntegrationsHub {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Provider records are restricted',
        );
    });
    it('quarantines the catalogue for an owned denied GET while leaving unrelated errors with their owner', () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(
            invalid(403, '/security-devices/monitoring').defaultPrevented,
        ).toBe(false);
        expect(card('UniFi')).toBeInTheDocument();
        expect(invalid(403).defaultPrevented).toBe(true);
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Provider records are restricted',
        );
    });
    it('labels retained readings after a failed GET without exposing the private error body', () => {
        render(<IntegrationsHub {...integrationsFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        invalid(503);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Previous provider readings',
        );
        expect(card('UniFi')).toBeInTheDocument();
        expect(
            screen.queryByText('Private error details'),
        ).not.toBeInTheDocument();
        expect(screen.getAllByText(/Read started/).length).toBeGreaterThan(0);
    });
    it('keeps empty results honest and does not turn no reconciliation exceptions into healthy', () => {
        const data = integrationsFixture();
        data.providers = [];
        data.stats = {
            providers_total: 0,
            providers_connected: 0,
            providers_errored: 0,
            imported_devices: 0,
            events_24h: 0,
            exceptions: 0,
        };
        render(<IntegrationsHub {...data} />);
        expect(
            screen.getByText(
                'No provider records returned; connection and health are not measured',
            ),
        ).toBeInTheDocument();
        cleanup();
        render(<IntegrationsHub {...integrationsFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Required action' }));
        expect(
            within(card('Milesight')).getByText('No exceptions'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Healthy')).not.toBeInTheDocument();
    });
    it('keeps current collection without a destination distinct from restricted actions', async () => {
        const data = integrationsFixture();
        data.providers[0].health = {
            ...data.providers[0].health,
            state: 'current',
            freshness: 'current',
            action: 'No action needed',
            href: null,
        };
        render(<IntegrationsHub {...data} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Collected health' }));
        const d = await details();
        expect(
            within(d).getByText('No action required for this collection'),
        ).toBeInTheDocument();
        expect(within(d).getByText('No action needed')).toBeInTheDocument();
        expect(
            within(d).queryByText(
                'Access to this destination is required. Ask an administrator.',
            ),
        ).not.toBeInTheDocument();
    });
});
