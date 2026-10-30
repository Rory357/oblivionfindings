import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    recorderDiscoveryReply,
    recorderSetupReply,
    type RecorderDiscovery,
    type RecorderSetup,
} from './_hikvision-recorder-contract';
import {
    recorderFixture,
    recorderResult,
} from './hikvision-recorder-test-fixtures';
import { hikTime, hikUuid } from './hikvision-test-fixtures';
import SourcePage from './source';
const mocks = vi.hoisted(() => ({ json: vi.fn() }));
vi.mock('./live-transport', () => ({
    hikvisionJson: mocks.json,
    cctvSourceJson: vi.fn(),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        breadcrumbs = [],
        children,
    }: {
        breadcrumbs?: { title: string; href?: string }[];
        children: ReactNode;
    }) => (
        <main>
            <nav aria-label="Breadcrumbs">
                {breadcrumbs.map((crumb) =>
                    crumb.href ? (
                        <a key={crumb.title} href={crumb.href}>
                            {crumb.title}
                        </a>
                    ) : (
                        <span key={crumb.title}>{crumb.title}</span>
                    ),
                )}
            </nav>
            {children}
        </main>
    ),
}));
const trail = () =>
    [...screen.getByRole('navigation', { name: 'Breadcrumbs' }).children].map(
        (crumb) => [crumb.textContent, crumb.getAttribute('href')],
    );
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { on: () => () => {} },
}));
beforeEach(() => {
    mocks.json.mockReset();
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
function directory(setup: RecorderSetup, path: string) {
    const q = new URLSearchParams(path.split('?')[1]);
    return {
        ...setup,
        choice: q.get('choice'),
        q: q.get('q') ?? '',
        collector_id: q.has('collector_id')
            ? Number(q.get('collector_id'))
            : null,
    };
}
async function choose(label: string, name: string) {
    fireEvent.click(screen.getByRole('combobox', { name: label }));
    fireEvent.click(
        await screen.findByRole('option', { name: new RegExp(name) }),
    );
    await waitFor(() =>
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument(),
    );
}
async function review() {
    fireEvent.click(screen.getByRole('button', { name: 'Check recorder' }));
    await waitFor(() =>
        expect(screen.getByRole('dialog')).toContainElement(
            document.activeElement as HTMLElement,
        ),
    );
    await choose('Site collector', 'Aurora collector');
    await choose('Approved network', 'Approved camera network');
    await choose('Credential reference', 'Approved camera access');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Certificate name'), {
        target: { value: 'recorder.example.test' },
    });
    fireEvent.change(screen.getByLabelText('Certificate SHA256 fingerprint'), {
        target: { value: 'c'.repeat(64) },
    });
    fireEvent.change(screen.getByLabelText('Reason for this check'), {
        target: { value: 'Review the reported recorder inputs.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
describe('closed recorder browser contracts', () => {
    it('preserves zero FPS and unknown input status with no inferred identity', () => {
        const s = recorderFixture(),
            r = recorderResult(s);
        expect(recorderSetupReply(s, s.actor_id, s.recorder_id)).toEqual(s);
        expect(recorderDiscoveryReply(r, s)).toEqual(r);
        expect(r.inventory?.channels[0].fps100).toBe(0);
        expect(r.inventory?.inputs[0].online).toBeNull();
        for (const address of ['2001:DB8::10', '::ffff:192.0.2.20']) {
            const observed = recorderResult(s);
            observed.inventory!.inputs[0].address = address;
            expect(recorderDiscoveryReply(observed, s)).toEqual(observed);
        }
        const hostname = recorderResult(s);
        hostname.inventory!.inputs[0].addressing_type = 'hostname';
        hostname.inventory!.inputs[0].address = 'camera.example.test';
        hostname.inventory!.inputs[0].management_port = 0;
        expect(recorderDiscoveryReply(hostname, s)).toEqual(hostname);
    });
    it('denies camera schemas, wrong actors/devices, duplicate IDs and secret extras', () => {
        const s = recorderFixture();
        for (const change of [
            (r: RecorderDiscovery) => {
                r.actor_id++;
            },
            (r: RecorderDiscovery) => {
                r.device_id++;
            },
            (r: RecorderDiscovery) => {
                r.inventory!.inputs.push({ ...r.inventory!.inputs[0] });
            },
            (r: RecorderDiscovery) => {
                Object.assign(r.inventory!.inputs[0], {
                    password: 'must-not-be-shown',
                });
            },
            (r: RecorderDiscovery) => {
                Object.assign(r.inventory!.inputs[0], {
                    camera_identity: { serial: 'unproven' },
                });
            },
            (r: RecorderDiscovery) => {
                r.inventory!.channels[0].fps100 = -1;
            },
            (r: RecorderDiscovery) => {
                Object.assign(r.inventory!.inputs[0], {
                    addressing_type: 'automatic',
                });
            },
            (r: RecorderDiscovery) => {
                Object.assign(r.inventory!.inputs[0], {
                    address: 'https://outside.example.test',
                });
            },
            (r: RecorderDiscovery) => {
                Object.assign(r.inventory!.inputs[0], { proxy_protocol: null });
            },
            (r: RecorderDiscovery) => {
                Reflect.deleteProperty(r.inventory!, 'inputs');
            },
        ]) {
            const r = recorderResult(s);
            change(r);
            expect(() => recorderDiscoveryReply(r, s)).toThrow();
        }
    });
    it('rejects broadened setup rights and recent-reader destinations', () => {
        const s = recorderFixture();
        Object.assign(s.readiness, { can_view: true });
        expect(() =>
            recorderSetupReply(s, s.actor_id, s.recorder_id),
        ).toThrow();
        const t = recorderFixture();
        t.recent_requests = [
            {
                request_uuid: hikUuid,
                status: 'queued',
                created_at: hikTime(),
                reader_href: 'https://outside.example.test/private',
            },
        ];
        expect(() =>
            recorderSetupReply(t, t.actor_id, t.recorder_id),
        ).toThrow();
    });
});
describe('recorder information placement', () => {
    it('roots the trail at Home and follows the sidebar to All devices and the recorder', () => {
        const s = recorderFixture();
        mocks.json.mockImplementation(
            async (_d: number, _a: number, path: string) =>
                path.includes('/setup')
                    ? directory(s, path)
                    : recorderResult(s),
        );
        render(<SourcePage {...s} />);
        expect(trail()).toEqual([
            ['Home', '/dashboard'],
            ['Security & Devices', '/security-devices'],
            ['All devices', '/security-devices/devices'],
            ['Aurora recorder', '/security-devices/devices/42'],
            ['Recorder information', null],
        ]);
    });
});
describe('reviewed recorder information journey', () => {
    it('selects the recorder DTO from an Inertia page with shared layout props', () => {
        const setup = {
            ...recorderFixture(),
            auth: { user: { id: 7 } },
            flash: {},
            branding: { name: 'Oblivion Care' },
            errors: {},
        };
        render(<SourcePage {...setup} />);
        expect(
            screen.getByRole('button', { name: 'Check recorder' }),
        ).toBeEnabled();
        expect(
            screen.queryByText(/could not be verified/),
        ).not.toBeInTheDocument();
        expect(() =>
            recorderSetupReply(setup, setup.actor_id, setup.recorder_id),
        ).toThrow();
    });

    it('shows reported data and unknown facts without camera mapping or live actions', async () => {
        const s = recorderFixture();
        mocks.json.mockImplementation(
            async (_d: number, _a: number, path: string) =>
                path.includes('/setup')
                    ? directory(s, path)
                    : recorderResult(s),
        );
        render(<SourcePage {...s} />);
        await review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Request recorder check' }),
        );
        const dialog = screen.getByRole('dialog');
        expect(
            await within(dialog).findByText('Camera identity unverified'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Status not reported'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/Frame rate not reported/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /Save connection|Start viewing|Turn on camera/,
            }),
        ).not.toBeInTheDocument();
        const sent = mocks.json.mock.calls.find((c) =>
            c[2].endsWith('/hikvision-discoveries'),
        );
        expect(sent?.[4]).toMatchObject({
            expected_actor_id: s.actor_id,
            expected_source_revision: 0,
            allowed_ip: s.recorder.ip_address,
            collector_id: 11,
            discovery_scope_id: 12,
            credential_reference_id: 13,
        });
        expect(
            mocks.json.mock.calls.some((c) => c[2].includes('/source/enable')),
        ).toBe(false);
    });
    it('clears both wizard and page observation when the same request reader is denied', async () => {
        const s = recorderFixture();
        mocks.json.mockImplementation(
            async (_d: number, _a: number, path: string) =>
                path.includes('/setup')
                    ? directory(s, path)
                    : recorderResult(s),
        );
        render(<SourcePage {...s} />);
        await review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Request recorder check' }),
        );
        await waitFor(() =>
            expect(screen.getAllByText('Synthetic recorder')).toHaveLength(2),
        );
        mocks.json.mockRejectedValueOnce(new Error('unavailable'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check same request' }),
        );
        await screen.findByText(
            'This request could not be read. Check the same request before starting another.',
        );
        expect(screen.queryAllByText('Synthetic recorder')).toHaveLength(0);
        expect(
            screen.queryAllByText('Camera identity unverified'),
        ).toHaveLength(0);
    });
    it('keeps the same reviewed request identity after a lost reply and failed recovery', async () => {
        const s = recorderFixture();
        let posts = 0;
        mocks.json.mockImplementation(
            async (_d: number, _a: number, path: string) => {
                if (path.includes('/setup')) return directory(s, path);
                if (path.includes('/requests/'))
                    throw Error('No confirmed receipt');
                if (path.endsWith('/hikvision-discoveries') && ++posts === 1)
                    throw Error('Lost response');
                return recorderResult(s);
            },
        );
        render(<SourcePage {...s} />);
        await review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Request recorder check' }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Check same request' }),
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Retry same request' }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        await within(screen.getByRole('dialog')).findByText(
            'Camera identity unverified',
        );
        const sent = mocks.json.mock.calls.filter((c) =>
            c[2].endsWith('/hikvision-discoveries'),
        );
        expect(sent).toHaveLength(2);
        expect(sent[0][4]).toEqual(sent[1][4]);
    });
    it('reopens an actor-private pending check before allowing another request', async () => {
        const s = recorderFixture();
        s.recent_requests = [
            {
                request_uuid: hikUuid,
                status: 'queued',
                created_at: hikTime(),
                reader_href: recorderResult(s).reader_href,
            },
        ];
        mocks.json.mockResolvedValue(recorderResult(s));
        render(<SourcePage {...s} />);
        expect(
            screen.getByRole('button', { name: 'Check recorder' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Open check' }));
        expect(
            await screen.findByText('Camera identity unverified'),
        ).toBeInTheDocument();
        expect(mocks.json.mock.calls.every((c) => c[4] === undefined)).toBe(
            true,
        );
    });
    it('clears displayed recorder information when the current setup cannot be authorised', async () => {
        const s = recorderFixture();
        mocks.json.mockRejectedValue(Error('Access changed'));
        render(<SourcePage {...s} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh information' }),
        );
        expect(
            await screen.findByText(
                /Recorder information could not be verified/,
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Aurora recorder')).not.toBeInTheDocument();
    });

    it('clears an earlier Site observation before refreshing to the new Site', async () => {
        const s = recorderFixture();
        s.recent_requests = [
            {
                request_uuid: hikUuid,
                status: 'observed',
                created_at: hikTime(),
                reader_href: recorderResult(s).reader_href,
            },
        ];
        const moved = recorderFixture();
        moved.recorder.site = { id: 4, name: 'New approved Site' };
        moved.recent_requests = [];
        mocks.json.mockImplementation(
            async (_d: number, _a: number, path: string) =>
                path.includes('/setup') ? moved : recorderResult(s),
        );
        render(<SourcePage {...s} />);
        fireEvent.click(screen.getByRole('button', { name: 'Open check' }));
        await screen.findByText('Camera identity unverified');
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh information' }),
        );
        expect(
            screen.queryByText('Camera identity unverified'),
        ).not.toBeInTheDocument();
        await screen.findByText(/New approved Site/);
        expect(
            screen.queryByText('Synthetic recorder'),
        ).not.toBeInTheDocument();
    });
    it('invalidates an old reader and refuses a late response after refreshed page context', async () => {
        const s = recorderFixture();
        s.recent_requests = [
            {
                request_uuid: hikUuid,
                status: 'observed',
                created_at: hikTime(),
                reader_href: recorderResult(s).reader_href,
            },
        ];
        let resolve!: (value: unknown) => void;
        const late = new Promise<unknown>((r) => {
            resolve = r;
        });
        mocks.json.mockReturnValue(late);
        const view = render(<SourcePage {...s} />);
        fireEvent.click(screen.getByRole('button', { name: 'Open check' }));
        const moved = recorderFixture();
        moved.recorder.site = { id: 4, name: 'New approved Site' };
        view.rerender(<SourcePage {...moved} />);
        expect(mocks.json.mock.calls[0][3].aborted).toBe(true);
        resolve(recorderResult(s));
        await waitFor(() =>
            expect(screen.getByText(/New approved Site/)).toBeInTheDocument(),
        );
        expect(
            screen.queryByText('Camera identity unverified'),
        ).not.toBeInTheDocument();
    });
});
