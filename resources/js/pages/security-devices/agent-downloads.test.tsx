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
import AgentDownloads, {
    type AgentDownload,
    type AgentDownloadsProps,
} from './agent-downloads';
const mocks = vi.hoisted(() => ({
    actor: 7,
    can: {} as Record<string, boolean>,
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
            props: {
                auth: {
                    user: mocks.actor ? { id: mocks.actor } : null,
                    can: mocks.can,
                },
            },
        }),
        router: { ...actual.router, reload: mocks.reload },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
function fixture(): AgentDownloadsProps {
    const release = (
        id: string,
        platform: AgentDownload['platform'],
        roles: AgentDownload['roles'],
        filename: string,
        architecture: AgentDownload['architecture'] = 'amd64',
    ): AgentDownload => ({
        id,
        platform,
        roles,
        filename,
        architecture,
        version: '1.2.3',
        release_revision: 4,
        size: 10485761,
        sha256: 'a'.repeat(64),
        download_url: `/security-devices/agent-downloads/${id}/${'a'.repeat(64)}`,
    });
    return {
        artifacts: [
            release(
                'windows-both',
                'windows',
                ['site', 'computer'],
                'Obli-Windows-1.2.3.exe',
            ),
            release(
                'windows-computer',
                'windows',
                ['computer'],
                'Obli-Computer-1.2.3.zip',
                'arm64',
            ),
            release('linux-site', 'linux', ['site'], 'Obli-Linux-1.2.3.tar.gz'),
        ],
        catalogue_available: true,
        catalogue_expires_at: '2027-01-01T00:00:00Z',
        can_manage_computers: true,
        can_manage_collectors: true,
    };
}
beforeEach(() => {
    mocks.actor = 7;
    mocks.can = {
        'securityDevices.viewAny': true,
        'securityDevices.commands.admin': true,
        'securityDevices.integrations.manage': true,
        'securityDevices.devices.view': true,
        'securityDevices.integrations.view': true,
    };
    mocks.reload.mockReset();
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
describe('Agent downloads shared release records', () => {
    it('keeps exact release and file verification fields in the shared full viewer', async () => {
        render(<AgentDownloads {...fixture()} />);
        expect(
            screen.getByText(/Catalogue valid until.*2027/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Download Installer/ }),
        ).toHaveAttribute('href', fixture().artifacts[0].download_url);
        const card = screen.getByRole('article', {
            name: /Release windows-both:/,
        });
        fireEvent.click(within(card).getByRole('button', { name: /Open/ }));
        const dialog = await screen.findByRole('dialog');
        for (const value of [
            'windows-both',
            'Computer support + site monitoring',
            'Windows',
            'x64',
            'Installer',
            '1.2.3',
            '4',
            '10 MB · 10,485,761 bytes',
            'Obli-Windows-1.2.3.exe',
            'a'.repeat(64),
        ])
            expect(within(dialog).getAllByText(value).length).toBeGreaterThan(
                0,
            );
        expect(
            within(dialog).getByText('Catalogue valid until'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('link', { name: /Download Installer/ }),
        ).toHaveAttribute('href', fixture().artifacts[0].download_url);
    });
    it('connects platform meters, platform views, scoped search and the compact table', () => {
        render(<AgentDownloads {...fixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: /Linux/ }));
        expect(
            screen.queryByRole('article', { name: /windows-both/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('article', { name: /linux-site/ }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /windows releases/i }),
        );
        expect(screen.getAllByRole('article')).toHaveLength(2);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'ARM64' },
        });
        expect(screen.getAllByRole('article')).toHaveLength(1);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Download Offline package/ }),
        ).toHaveAttribute('href', fixture().artifacts[1].download_url);
        expect(screen.queryByText('a'.repeat(64))).not.toBeInTheDocument();
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'no release matches' },
        });
        expect(screen.getByText(/No results/)).toBeInTheDocument();
    });
    it('opens identical full details through keyboard menu and context menu with focus return', async () => {
        render(<AgentDownloads {...fixture()} />);
        const card = screen.getByRole('article', { name: /windows-computer/ });
        fireEvent.contextMenu(
            within(card).getByRole('button', { name: /^Computer support/ }),
            { clientX: 100, clientY: 100 },
        );
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View evidence' }),
        );
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        fireEvent.click(
            within(screen.getByRole('dialog')).getAllByRole('button', {
                name: 'Close',
            })[0],
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        const button = within(card).getByRole('button', { name: /Actions/ });
        await waitFor(() => expect(button).toHaveFocus());
        button.focus();
        fireEvent.keyDown(button, { key: 'ArrowDown', code: 'ArrowDown' });
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'View evidence' }),
        );
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
    });
    it('removes private open context and combined packages after current permission replacement', async () => {
        const props = fixture();
        const view = render(<AgentDownloads {...props} />);
        fireEvent.click(
            within(
                screen.getByRole('article', { name: /windows-both/ }),
            ).getByRole('button', { name: /Open/ }),
        );
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        mocks.can['securityDevices.commands.admin'] = false;
        view.rerender(<AgentDownloads {...props} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: /windows-both/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: /windows-computer/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('article', { name: /linux-site/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Open device register' }),
        ).not.toBeInTheDocument();
        mocks.actor = 0;
        view.rerender(<AgentDownloads {...props} />);
        expect(screen.getByText('Downloads restricted')).toBeInTheDocument();
        expect(
            screen.queryByText('Obli-Linux-1.2.3.tar.gz'),
        ).not.toBeInTheDocument();
    });
    it('distinguishes empty publication, unavailable catalogue and restricted permission', () => {
        const props = fixture();
        const view = render(<AgentDownloads {...props} artifacts={[]} />);
        expect(
            screen.getByText('No download available yet'),
        ).toBeInTheDocument();
        view.rerender(
            <AgentDownloads {...props} catalogue_available={false} />,
        );
        expect(
            screen.getByText('Verified catalogue unavailable'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Unavailable').length).toBeGreaterThan(0);
        expect(
            screen.queryAllByRole('link', { name: /Download / }),
        ).toHaveLength(0);
        mocks.can = {};
        view.rerender(<AgentDownloads {...props} />);
        expect(screen.getByText('Downloads restricted')).toBeInTheDocument();
        expect(screen.getAllByText('Restricted').length).toBeGreaterThan(0);
    });
    it('expires an open release view and removes links without reloading or extending publication', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-13T00:00:00Z'));
        const props = {
            ...fixture(),
            catalogue_expires_at: '2026-10-13T00:00:01Z',
        };
        render(<AgentDownloads {...props} />);
        fireEvent.click(
            within(
                screen.getByRole('article', { name: /windows-both/ }),
            ).getByRole('button', { name: /Open/ }),
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        act(() => vi.advanceTimersByTime(1001));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByText('Verified catalogue unavailable'),
        ).toBeInTheDocument();
        expect(
            screen.queryAllByRole('link', { name: /Download / }),
        ).toHaveLength(0);
        expect(mocks.reload).not.toHaveBeenCalled();
    });
    it('permits only the canonical artifact and digest download URL', () => {
        const props = fixture();
        props.artifacts[0].download_url = 'https://unapproved.example/download';
        render(<AgentDownloads {...props} />);
        expect(
            screen.queryByRole('link', { name: /Download Installer/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Refresh to load the current download link.'),
        ).toBeInTheDocument();
        expect(
            document.querySelector('a[href^="https://unapproved"]'),
        ).toBeNull();
    });
    it('keeps canonical setup permission fences and refreshes only the current catalogue and authority', () => {
        const props = fixture();
        const view = render(<AgentDownloads {...props} />);
        expect(
            screen.getByRole('link', { name: 'Open device register' }),
        ).toHaveAttribute('href', '/security-devices/devices');
        expect(
            screen.getByRole('link', { name: 'Open site collectors' }),
        ).toHaveAttribute('href', '/security-devices/discovery?tab=collectors');
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.reload).toHaveBeenCalledWith({
            only: [
                'artifacts',
                'catalogue_available',
                'catalogue_expires_at',
                'can_manage_computers',
                'can_manage_collectors',
                'auth',
            ],
        });
        mocks.can['securityDevices.devices.view'] = false;
        mocks.can['securityDevices.integrations.view'] = false;
        view.rerender(<AgentDownloads {...props} />);
        expect(
            screen.queryByRole('link', { name: 'Open device register' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Open site collectors' }),
        ).not.toBeInTheDocument();
        expect(screen.getAllByRole('article')).toHaveLength(3);
    });
});
