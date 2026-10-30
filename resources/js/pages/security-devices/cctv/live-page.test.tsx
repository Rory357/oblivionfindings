import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cameraReply, cameraSession } from './cctv-test-fixtures';
import CctvLive, { type CctvLiveProps } from './live';
const mocks = vi.hoisted(() => ({
    json: vi.fn(),
    stop: vi.fn(),
    make: vi.fn(),
    start: vi.fn(),
    localStop: vi.fn(),
}));
vi.mock('./live-transport', () => ({
    cctvJson: mocks.json,
    stopCctv: mocks.stop,
}));
vi.mock('./live-viewer', () => ({
    CctvViewer: class {
        constructor(...args: unknown[]) {
            mocks.make(...args);
        }
        start = mocks.start;
        stop = mocks.localStop;
    },
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
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { on: () => () => {} },
}));
const props: CctvLiveProps = {
    actor_id: 7,
    camera: {
        id: 42,
        name: 'Entrance',
        site: { id: 9, name: 'Harbour House' },
    },
    live: {
        available: true,
        reason_code: null,
        can_start: true,
        source_revision: 1,
        transport: 'webrtc',
        video_codec: 'H264',
        audio_enabled: false,
        max_session_seconds: 300,
        lease_seconds: 15,
        start_href: '/security-devices/devices/42/live-sessions',
        setup_href: null,
        setup_ready: true,
        streaming: false,
    },
    recording_references: { state: 'not_requested', reference: null },
};
beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);
function submit() {
    fireEvent.change(screen.getByLabelText('Reason for viewing'), {
        target: { value: 'Check the reported concern at the entrance.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Start live view' }));
}
describe('CCTV live view placement', () => {
    it('roots the trail at Home and follows the sidebar to All devices and the camera', () => {
        render(<CctvLive {...props} />);
        const trail = screen.getByRole('navigation', { name: 'Breadcrumbs' });
        expect(
            [...trail.children].map((crumb) => [
                crumb.textContent,
                crumb.getAttribute('href'),
            ]),
        ).toEqual([
            ['Home', '/dashboard'],
            ['Security & Devices', '/security-devices'],
            ['All devices', '/security-devices/devices'],
            ['Entrance', '/security-devices/devices/42'],
            ['Live view', null],
        ]);
    });
});
describe('CCTV explicit viewing and uncertain start', () => {
    it('makes no connection on render and requires a recorded reason', async () => {
        mocks.json.mockResolvedValue(cameraReply(cameraSession()));
        render(<CctvLive {...props} />);
        expect(mocks.json).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Start live view' }),
        );
        expect(mocks.json).not.toHaveBeenCalled();
        submit();
        await waitFor(() => expect(mocks.start).toHaveBeenCalledOnce());
        expect(mocks.json.mock.calls[0][2]).toMatchObject({
            expected_actor_id: 7,
            source_revision: 1,
            purpose: 'Check the reported concern at the entrance.',
        });
    });
    it('keeps the reviewed request immutable after a lost response', async () => {
        mocks.json.mockRejectedValue(new Error('Lost reply'));
        render(<CctvLive {...props} />);
        submit();
        await screen.findByRole('button', { name: 'Check connection' });
        expect(screen.getByLabelText('Reason for viewing')).toBeDisabled();
        const original = mocks.json.mock.calls[0][2];
        fireEvent.click(
            screen.getByRole('button', { name: 'Check connection' }),
        );
        await waitFor(() => expect(mocks.json).toHaveBeenCalledTimes(2));
        expect(mocks.json.mock.calls[1][2]).toEqual(original);
        expect(mocks.make).not.toHaveBeenCalled();
    });
    it('denies a reply bound to a different actor', async () => {
        mocks.json.mockResolvedValue(
            cameraReply(cameraSession({ actor_id: 8 })),
        );
        render(<CctvLive {...props} />);
        submit();
        await screen.findByRole('button', { name: 'Check connection' });
        expect(mocks.make).not.toHaveBeenCalled();
    });
    it('stops a late confirmed session after the page unmounts', async () => {
        let finish!: (reply: unknown) => void;
        mocks.json.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const ui = render(<CctvLive {...props} />);
        submit();
        ui.unmount();
        finish(cameraReply(cameraSession()));
        await waitFor(() =>
            expect(mocks.stop).toHaveBeenCalledWith(cameraSession()),
        );
        expect(mocks.make).not.toHaveBeenCalled();
    });
    it('clears the previous viewer on a changed camera or actor context', async () => {
        mocks.json.mockResolvedValue(cameraReply(cameraSession()));
        const ui = render(<CctvLive {...props} />);
        submit();
        await waitFor(() => expect(mocks.start).toHaveBeenCalledOnce());
        ui.rerender(<CctvLive {...props} actor_id={8} />);
        expect(mocks.localStop).toHaveBeenCalledOnce();
        expect(screen.getByLabelText('Reason for viewing')).toHaveValue('');
    });
});
