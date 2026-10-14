import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NetworkItWorkspacePanels } from './network-it-workspace';
import { networkRecordFixture } from './network-record-test-fixtures';
const visit = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return { ...actual, router: { ...actual.router, visit } };
});
afterEach(() => {
    cleanup();
    visit.mockReset();
});
describe('Network operating record layouts', () => {
    it('keeps every interface counter, index and missing-value distinction in table mode', () => {
        const data = networkRecordFixture('interfaces');
        data.activeTab.interfaces[0].errors = null;
        data.activeTab.interfaces[0].discards = 0;
        render(<NetworkItWorkspacePanels data={data} view="table" />);
        const table = screen.getByRole('table');
        expect(
            within(table).getByRole('link', { name: 'WAN 1' }),
        ).toHaveAttribute('href', '/security-devices/devices/12');
        expect(within(table).getByText('85% inbound')).toBeInTheDocument();
        expect(within(table).getByText('62% outbound')).toBeInTheDocument();
        expect(within(table).getByText('1 Gbps')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View evidence for WAN 1' }),
        );
        fireEvent.click(screen.getByRole('button', { name: /^Counters/ }));
        const dialog = screen.getByRole('dialog', {
            name: 'Evidence for WAN 1',
        });
        expect(
            within(dialog).getAllByText('Not collected').length,
        ).toBeGreaterThan(0);
        expect(within(dialog).getByText('0')).toBeInTheDocument();
        const row = data.activeTab.interfaces[0];
        expect(within(dialog).getByText(String(row.index))).toBeInTheDocument();
    });
    it('keeps service source, dependency and paused-state information in both layouts', () => {
        const data = networkRecordFixture('services');
        data.activeTab.services[0].enabled = false;
        data.activeTab.services[0].collector = {
            name: 'Synthetic Site collector',
            status: 'stale',
            lastSeenAt: '2026-10-01T00:00:00Z',
        };
        const view = render(<NetworkItWorkspacePanels data={data} />);
        expect(screen.getByText('Disabled')).toBeInTheDocument();
        expect(screen.getByText('2 known dependants')).toBeInTheDocument();
        expect(
            screen.getByText('Synthetic Site collector · Stale'),
        ).toBeInTheDocument();
        view.rerender(<NetworkItWorkspacePanels data={data} view="table" />);
        expect(
            within(screen.getByRole('table')).getByText('Disabled'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Synthetic Site collector · Stale'),
        ).toBeInTheDocument();
    });
    it('offers the same existing Open action through keyboard and context menus', async () => {
        render(
            <NetworkItWorkspacePanels
                data={networkRecordFixture('interfaces')}
                view="table"
            />,
        );
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Actions for WAN 1' }),
            { key: 'Enter' },
        );
        expect(
            await screen.findByRole('menuitem', { name: 'Open device' }),
        ).toBeInTheDocument();
        fireEvent.keyDown(
            screen.getByRole('menuitem', { name: 'Open device' }),
            { key: 'Escape' },
        );
        fireEvent.contextMenu(screen.getByText('Core switch'), {
            clientX: 40,
            clientY: 80,
        });
        expect(
            await screen.findByRole('menuitem', { name: 'Open device' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Open device' }));
        expect(visit).toHaveBeenCalledWith('/security-devices/devices/12');
    });
    it('keeps the supplied nested IP address in the compact device table and identifier viewer', () => {
        const data = networkRecordFixture('devices');
        render(<NetworkItWorkspacePanels data={data} view="table" />);
        expect(
            within(screen.getByRole('table')).getByText('10.0.0.21'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Kauri core switch',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: /^Identifiers/ }));
        const dialog = screen.getByRole('dialog', {
            name: 'Evidence for Kauri core switch',
        });
        expect(within(dialog).getByText('10.0.0.21')).toBeInTheDocument();
        expect(within(dialog).getByText('1.4.0')).toBeInTheDocument();
        expect(visit).not.toHaveBeenCalled();
    });
    it('does not replace unknown technical health with an active lifecycle state', () => {
        const data = networkRecordFixture('devices');
        data.activeTab.devices[0].status = 'active';
        data.activeTab.devices[0].health = null;
        render(<NetworkItWorkspacePanels data={data} />);
        expect(screen.getByText('Health: Unknown')).toBeInTheDocument();
        expect(screen.queryByText('Healthy')).not.toBeInTheDocument();
    });
    it('keeps snapshot content, bounded history and download availability in table mode', () => {
        const data = networkRecordFixture('configuration-firmware');
        render(<NetworkItWorkspacePanels data={data} view="table" />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Drifted firewall',
            }),
        );
        expect(
            screen.getByRole('link', { name: 'Download governed snapshot' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/31/configuration-snapshots/902',
        );
        expect(
            screen.getByText(/Compared with snapshot #901: 1 added, 1 changed/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Additional retained snapshots exist outside this bounded Site history/,
            ),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByText(/View previous governed snapshots/));
        expect(visit).not.toHaveBeenCalled();
        expect(
            screen.getByRole('link', { name: 'Download this snapshot' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/31/configuration-snapshots/901',
        );
        expect(
            screen.queryByRole('button', { name: /apply|upgrade|push/i }),
        ).not.toBeInTheDocument();
    });
    it('shows unavailable snapshot downloads without offering an action', () => {
        const data = networkRecordFixture('configuration-firmware');
        const row = data.activeTab.configuration[0];
        row.latestSnapshot!.downloadHref = null;
        row.snapshotHistory!.forEach((snapshot) => {
            snapshot.downloadHref = null;
        });
        render(<NetworkItWorkspacePanels data={data} view="table" />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Drifted firewall',
            }),
        );
        expect(screen.getByText('Download unavailable')).toBeInTheDocument();
        fireEvent.click(screen.getByText(/View previous governed snapshots/));
        expect(screen.getByText('Unavailable')).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
        expect(visit).not.toHaveBeenCalled();
    });
    it('opens retained snapshot evidence outside table rows and closes with focus returned', async () => {
        render(
            <NetworkItWorkspacePanels
                data={networkRecordFixture('configuration-firmware')}
                view="table"
            />,
        );
        expect(
            screen.queryByText('Latest governed snapshot'),
        ).not.toBeInTheDocument();
        const trigger = screen.getByRole('button', {
            name: 'View evidence for Drifted firewall',
        });
        fireEvent.click(trigger);
        const dialog = screen.getByRole('dialog', {
            name: 'Evidence for Drifted firewall',
        });
        expect(
            within(dialog).getByText('Latest governed snapshot'),
        ).toBeInTheDocument();
        expect(visit).not.toHaveBeenCalled();
        fireEvent.click(
            within(dialog).getByRole('button', { name: /^Configuration/ }),
        );
        expect(within(dialog).getByText('desired-hash')).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: /^Firmware/ }),
        );
        expect(within(dialog).getByText('1.5.0')).toBeInTheDocument();
        fireEvent.keyDown(dialog, { key: 'Escape', code: 'Escape' });
        await waitFor(() => expect(trigger).toHaveFocus());
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(visit).not.toHaveBeenCalled();
    });
    it('keeps missing snapshots explicit in the read-only evidence viewer', () => {
        render(
            <NetworkItWorkspacePanels
                data={networkRecordFixture('configuration-firmware')}
                view="table"
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Basic printer',
            }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'Evidence for Basic printer',
        });
        expect(
            within(dialog).getByText('No saved snapshot'),
        ).toBeInTheDocument();
        expect(
            within(dialog).queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
    });
    it('removes an open evidence viewer immediately when the supplied records change', () => {
        const data = networkRecordFixture('configuration-firmware');
        const result = render(
            <NetworkItWorkspacePanels data={data} view="table" />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Drifted firewall',
            }),
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        result.rerender(
            <NetworkItWorkspacePanels
                data={{
                    ...data,
                    activeTab: { ...data.activeTab, configuration: [] },
                }}
                view="table"
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
    });
    it('clears a stale context menu immediately when the visible records change', async () => {
        const data = networkRecordFixture('interfaces');
        const view = render(
            <NetworkItWorkspacePanels data={data} view="table" />,
        );
        fireEvent.contextMenu(screen.getByText('Core switch'), {
            clientX: 40,
            clientY: 80,
        });
        expect(
            await screen.findByRole('menuitem', { name: 'Open device' }),
        ).toBeInTheDocument();
        view.rerender(
            <NetworkItWorkspacePanels
                data={{
                    ...data,
                    activeTab: { ...data.activeTab, interfaces: [] },
                }}
                view="table"
            />,
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Open device' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Core switch')).not.toBeInTheDocument();
    });
});
