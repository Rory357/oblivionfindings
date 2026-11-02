import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { daysWords, rangeWords } from '@/lib/security-devices/camera-privacy';
import { watchPurpose, watchReasonProblem } from '@/components/security-devices/camera-popups';
import CameraPage from './camera';
import { CameraWall } from './_camera-wall';
import type { CameraPageProps } from './_camera-page-types';
import { minutesAgo } from './device-page-test-fixtures';

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7, name: 'Synthetic Viewer' } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
const http = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { ...actual, default: { ...actual.default, post: http.post, get: http.get, isAxiosError: actual.default.isAxiosError } };
});
const live = vi.hoisted(() => ({ cctvJson: vi.fn(), stopCctv: vi.fn() }));
vi.mock('@/pages/security-devices/cctv/live-transport', () => live);

const inHours = (hours: number) => new Date(Date.now() + hours * 60 * 60_000).toISOString();

function camera(overrides: Partial<CameraPageProps> = {}): CameraPageProps {
    return {
        device: {
            id: 52,
            name: 'Synthetic porch camera',
            kind: 'camera',
            kind_label: 'Camera',
            group: 'camera',
            state: 'online',
            badge: { label: 'Online', tone: 'success' },
            model: 'Synthetic CAM-4K',
            site: 'Synthetic House',
            site_id: 4,
            address: '10.0.0.45',
            watching: 'Online',
            subline: [
                { text: 'Synthetic CAM-4K', at: null },
                { text: 'Synthetic House · front porch', at: null },
                { text: 'checked {ago} by Synthetic hub', at: minutesAgo(1) },
            ],
            back: { href: '/security-devices/devices?tab=cameras', label: 'Devices' },
        },
        tab: 'live',
        tabs: [
            { key: 'live', label: 'Live' },
            { key: 'recordings', label: 'Recordings' },
            { key: 'checks', label: 'Checks & alerts' },
            { key: 'settings', label: 'Settings' },
        ],
        meters: [
            { key: 'picture', label: 'Picture', value: 'On', from: null, ago: null, caption: { text: 'Camera answered {ago}', at: minutesAgo(1) }, tone: 'success', tab: 'live', bar: null },
            { key: 'recording', label: 'Recording', value: 'Synthetic recorder', from: null, ago: null, caption: { text: 'Clips stay on the recorder', at: null }, tone: 'brand', tab: 'recordings', bar: null },
            { key: 'motion', label: 'Motion today', value: null, from: null, ago: null, caption: { text: 'Not reported by this camera', at: null }, tone: 'brand', tab: 'live', bar: null },
        ],
        can: {
            edit: true,
            watch: true,
            move: true,
            retire: true,
            assign: true,
            upload: false,
            see_events: true,
            see_picture: true,
            watch_live: true,
            see_clips: true,
            save_clip: true,
            privacy: true,
        },
        actions: { ping: true, backup: null, remote_href: null, recorder_href: '/security-devices/devices/60' },
        privacy: { enabled: false, ranges: [], active: false, until: null, next_start: null },
        live: { available: true, reason: null, actor_id: 7, source_revision: 3, start_href: '/security-devices/devices/52/live-sessions', via: 'Synthetic hub', max_seconds: 300 },
        overview: {
            events: [{ key: 'live-1', at: minutesAgo(20), tone: 'info', title: 'Watching live: Synthetic Manager', sub: 'Reason: safety concern' }],
            last_view: { at: minutesAgo(20), who: 'Synthetic Manager', reason: 'Safety concern' },
            facts: [['Model', 'Synthetic CAM-4K'], ['Recorder', 'Synthetic recorder']],
            it: [['Connects by', 'UniFi Protect']],
        },
        recordings: null,
        checks: null,
        settings: null,
        ...overrides,
    };
}

describe('Camera page (pg-sdr-4, 4b, 4c) and its pop-ups (16, 64, 78)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        live.cctvJson.mockImplementation(() => new Promise(() => undefined));
    });
    afterEach(cleanup);

    it('asks for a reason before every live view, then opens the full view with that reason', async () => {
        render(<CameraPage {...camera()} />);

        expect(screen.getByText('A reason is asked before every live view, and kept with your name.')).toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: /History/ })).not.toBeInTheDocument();
        expect(screen.getByText(/Last watched live/)).toHaveTextContent('Synthetic Manager');

        fireEvent.click(screen.getByRole('button', { name: 'Watch live' }));
        const reason = await screen.findByTestId('sd-watch-live-reason');
        expect(within(reason).getByText('Watch live: give a reason')).toBeInTheDocument();
        expect(within(reason).getByText('Viewing is recorded with your name and reason.')).toBeInTheDocument();

        // Nothing starts until a reason is given.
        fireEvent.click(within(reason).getByRole('button', { name: 'Watch live' }));
        expect(await within(reason).findByText('Choose why you’re watching.')).toBeInTheDocument();
        expect(live.cctvJson).not.toHaveBeenCalled();

        fireEvent.click(within(reason).getByRole('radio', { name: /Safety concern/ }));
        fireEvent.change(within(reason).getByLabelText('Note'), { target: { value: 'Shouting heard outside' } });
        fireEvent.click(within(reason).getByRole('button', { name: 'Watch live' }));

        const full = await screen.findByTestId('sd-camera-full-view');
        const privacyLine = within(full).getByText('Viewing is recorded').parentElement as HTMLElement;
        expect(privacyLine).toHaveTextContent('Synthetic Viewer');
        expect(privacyLine).toHaveTextContent('Why: Safety concern: Shouting heard outside');
        expect(within(full).getByRole('link', { name: 'Open recorder' })).toHaveAttribute('href', '/security-devices/devices/60');
        expect(within(full).getByRole('button', { name: 'Save clip…' })).toBeInTheDocument();
        await waitFor(() =>
            expect(live.cctvJson).toHaveBeenCalledWith(
                '/security-devices/devices/52/live-sessions',
                expect.anything(),
                expect.objectContaining({ expected_actor_id: 7, source_revision: 3, purpose: 'Safety concern: Shouting heard outside' }),
            ),
        );
    });

    it('needs a few words for “Other”, and keeps the reason within the server’s 10–500 characters', () => {
        expect(watchReasonProblem('', '')).toBe('Choose why you’re watching.');
        expect(watchReasonProblem('Other', 'tiny')).toMatch(/at least 10 letters/);
        expect(watchPurpose('Other', '  Checking the   back gate ')).toBe('Checking the back gate');
        expect(watchPurpose('Safety concern', '')).toBe('Safety concern');
        expect(watchReasonProblem('Safety concern', '')).toBeNull();
        expect(watchReasonProblem('Following up an alert', 'x'.repeat(490))).toMatch(/under 500/);
    });

    it('shows no picture and no way to ask for one inside privacy hours', () => {
        render(
            <CameraPage
                {...camera({
                    privacy: { enabled: true, ranges: [{ days: [1, 2, 3, 4, 5, 6, 7], from: '22:00', to: '06:00' }], active: true, until: inHours(5), next_start: null },
                    can: { ...camera().can, watch_live: false },
                    live: { ...camera().live!, available: false, reason: 'privacy_hours', start_href: null, source_revision: null },
                })}
            />,
        );

        expect(screen.getAllByText(/^Privacy hours until \d{1,2}:\d{2} (am|pm)$/).length).toBeGreaterThanOrEqual(2);
        expect(screen.getByText('No picture is shown until then, on any screen.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Watch live' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Show the latest picture/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    it('shows the latest picture only when asked, and says privacy hours when the server refuses', async () => {
        http.post.mockResolvedValueOnce({ data: { ok: true, reason_code: 'captured', data_uri: 'data:image/jpeg;base64,AAAA', captured_at: minutesAgo(0) } });
        render(<CameraPage {...camera()} />);

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Show the latest picture' }));
        expect(await screen.findByRole('img', { name: 'Latest picture from Synthetic porch camera' })).toHaveAttribute('src', 'data:image/jpeg;base64,AAAA');
        expect(http.post).toHaveBeenCalledWith('/security-devices/devices/52/camera/snapshot', {}, expect.anything());

        http.post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 409, data: { ok: false, reason_code: 'privacy_hours', privacy_until: inHours(2) } } });
        fireEvent.click(screen.getByRole('button', { name: 'Show a newer picture' }));
        expect(await screen.findByText(/^Privacy hours until/)).toBeInTheDocument();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    it('lists clip references as places on the recorder, never as copies', () => {
        render(
            <CameraPage
                {...camera({
                    tab: 'recordings',
                    overview: null,
                    recordings: {
                        clips: [
                            {
                                key: '7f9c8a52-1111-4a2b-9c3d-000000000001',
                                start: minutesAgo(30),
                                end: minutesAgo(28),
                                why: 'Courier left a parcel',
                                by: 'Synthetic Manager',
                                href: '/security-devices/cctv/recording-references/7f9c8a52-1111-4a2b-9c3d-000000000001',
                            },
                        ],
                        unavailable: false,
                        facts: [['Recorder', 'Synthetic recorder · NVR-8'], ['Keeps', 'Not read from the recorder']],
                    },
                })}
            />,
        );

        expect(screen.getByText(/Recordings stay on the recorder/)).toBeInTheDocument();
        expect(screen.getByText(/Courier left a parcel/)).toBeInTheDocument();
        expect(screen.getByText('Saved by Synthetic Manager')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Open the clip reference/ })).toHaveAttribute(
            'href',
            '/security-devices/cctv/recording-references/7f9c8a52-1111-4a2b-9c3d-000000000001',
        );
        expect(screen.queryByText(/Who viewed recordings/)).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Play/ })).not.toBeInTheDocument();
    });

    it('sets privacy hours in pop-up 64 and asks before turning them off', async () => {
        const settings: CameraPageProps['settings'] = {
            camera: {
                name: 'Synthetic porch camera',
                site_id: 4,
                site: 'Synthetic House',
                location: 'Front porch',
                address: '10.0.0.45',
                manufacturer: 'Synthetic',
                model: 'CAM-4K',
                notes: null,
                domain: 'security',
                category: 'cctv',
                subcategory: 'ip_camera',
            },
            recorder: 'Synthetic recorder',
            login: 'Saved · stored encrypted in Oblivion',
            who: [['Live', 'IT manager'], ['Recordings', 'IT manager']],
        };
        const { unmount } = render(<CameraPage {...camera({ tab: 'settings', overview: null, settings })} />);

        expect(screen.getByText('Cameras are never put in bedrooms or bathrooms. Inside shared spaces, use privacy hours.')).toBeInTheDocument();
        expect(screen.getByText('Ask for a reason before every live view')).toBeInTheDocument();
        expect(screen.queryByText(/Privacy mask/)).not.toBeInTheDocument();

        // On, with no times yet: pop-up 64 opens with every day, 10:00 pm – 6:00 am to start from.
        fireEvent.click(screen.getByRole('switch', { name: 'Privacy hours' }));
        const popup = await screen.findByTestId('sd-privacy-hours');
        expect(within(popup).getByText('the next morning')).toBeInTheDocument();
        expect(within(popup).queryByText(/Night mode/)).not.toBeInTheDocument();
        fireEvent.click(within(popup).getByRole('button', { name: 'Save privacy hours' }));
        expect(inertia.put).toHaveBeenCalledWith(
            '/security-devices/devices/52/camera/privacy-hours',
            { enabled: true, ranges: [{ days: [1, 2, 3, 4, 5, 6, 7], from: '22:00', to: '06:00' }] },
            expect.anything(),
        );
        unmount();

        render(
            <CameraPage
                {...camera({
                    tab: 'settings',
                    overview: null,
                    settings,
                    privacy: { enabled: true, ranges: [{ days: [1, 2, 3, 4, 5], from: '22:00', to: '06:00' }], active: false, until: null, next_start: inHours(3) },
                })}
            />,
        );
        expect(screen.getByText(/Mon – Fri, 10:00 pm – 6:00 am · next from/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('switch', { name: 'Privacy hours' }));
        const confirm = await screen.findByTestId('sd-privacy-off');
        expect(within(confirm).getByText('Turn off privacy hours?')).toBeInTheDocument();
        fireEvent.click(within(confirm).getByRole('button', { name: 'Turn off privacy hours' }));
        expect(inertia.put).toHaveBeenLastCalledWith(
            '/security-devices/devices/52/camera/privacy-hours',
            { enabled: false, ranges: [{ days: [1, 2, 3, 4, 5], from: '22:00', to: '06:00' }] },
            expect.anything(),
        );
    });

    it('writes privacy hours one way everywhere', () => {
        expect(daysWords([1, 2, 3, 4, 5, 6, 7])).toBe('Every day');
        expect(daysWords([1, 2, 3, 4, 5])).toBe('Mon – Fri');
        expect(daysWords([6, 7])).toBe('Sat and Sun');
        expect(daysWords([1, 3, 5])).toBe('Mon, Wed and Fri');
        expect(rangeWords({ days: [6], from: '00:00', to: '00:00' })).toBe('Sat, all day');
        expect(rangeWords({ days: [1, 2, 3, 4, 5, 6, 7], from: '22:00', to: '06:00' })).toBe('Every day, 10:00 pm – 6:00 am');
    });

    it('shows “Privacy hours until …” on the camera wall instead of a picture', () => {
        render(
            <CameraWall
                items={[
                    { id: 52, name: 'Synthetic porch camera', site: 'Synthetic House', state: 'online', line: 'online', line_at: null, privacy_until: inHours(4), href: '/security-devices/devices/52' },
                    { id: 53, name: 'Synthetic gate camera', site: 'Synthetic House', state: 'online', line: 'online', line_at: null, privacy_until: null, href: '/security-devices/devices/53' },
                ]}
                cantCheck={[]}
                head={null}
                now={new Date()}
            />,
        );

        expect(screen.getByText(/^Privacy hours until \d{1,2}:\d{2} (am|pm)$/)).toBeInTheDocument();
        expect(screen.getAllByText(/Picture not available/)).toHaveLength(1);
    });
});
