import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CctvSourcePage from './source';
import { sourceFixture } from './source-test-fixtures';
const mocks = vi.hoisted(() => ({ json: vi.fn() }));
vi.mock('./live-transport', () => ({ cctvSourceJson: mocks.json }));
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
beforeEach(() => {
    mocks.json.mockReset();
});
afterEach(cleanup);
function openReview() {
    fireEvent.click(screen.getByRole('button', { name: 'Edit connection' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: { value: 'Verify the recorder and network before viewing.' },
    });
}
describe('CCTV source setup placement', () => {
    it('roots the trail at Home and follows the sidebar to All devices and the camera', () => {
        render(<CctvSourcePage {...sourceFixture()} />);
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
            ['Main entrance', '/security-devices/devices/42'],
            ['Camera setup', null],
        ]);
    });
});
describe('CCTV source setup workflow', () => {
    it('separates saved connection from unavailable vendor stream and needs explicit review', () => {
        render(<CctvSourcePage {...sourceFixture()} />);
        expect(screen.getByText('Unavailable')).toBeInTheDocument();
        expect(mocks.json).not.toHaveBeenCalled();
        openReview();
        expect(mocks.json).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Verify and save connection' }),
        ).toBeInTheDocument();
    });
    it('keeps all source settings and the request key immutable after a lost save', async () => {
        const setup = sourceFixture();
        mocks.json.mockRejectedValueOnce(Error('lost')).mockResolvedValueOnce({
            actor_id: 7,
            camera_id: 42,
            source: { ...setup.source, revision: 3 },
        });
        render(<CctvSourcePage {...setup} />);
        openReview();
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save connection' }),
        );
        await screen.findByRole('button', { name: 'Check this request' });
        const body = mocks.json.mock.calls[0][3];
        expect(body).toMatchObject({
            expected_actor_id: 7,
            expected_revision: 2,
            recorder_device_id: 48,
            collector_id: 51,
            discovery_scope_id: 61,
            stream_port: 7441,
        });
        expect(screen.getByLabelText('Reason for this change')).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check this request' }),
        );
        await screen.findByText('Connection saved');
        expect(mocks.json.mock.calls[1][3]).toEqual(body);
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(screen.getByText('Unavailable')).toBeInTheDocument();
    });
    it('rejects another actor’s save result and discards late results after an account change', async () => {
        const setup = sourceFixture();
        let finish!: (value: unknown) => void;
        mocks.json.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const view = render(<CctvSourcePage {...setup} />);
        openReview();
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save connection' }),
        );
        const signal = mocks.json.mock.calls[0][2] as AbortSignal;
        view.rerender(<CctvSourcePage {...setup} actor_id={8} />);
        expect(signal.aborted).toBe(true);
        finish({
            actor_id: 7,
            camera_id: 42,
            source: { ...setup.source, revision: 3 },
        });
        await waitFor(() =>
            expect(
                screen.queryByText('Connection saved'),
            ).not.toBeInTheDocument(),
        );
        expect(
            screen.getByRole('button', { name: 'Edit connection' }),
        ).toBeInTheDocument();
    });
    it('rejects invalid connection input without sending a source write', () => {
        render(<CctvSourcePage {...sourceFixture()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit connection' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByLabelText('Recorder IP address'), {
            target: { value: 'https://unapproved.example' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(mocks.json).not.toHaveBeenCalled();
        expect(
            screen.getByLabelText('Recorder IP address'),
        ).toBeInTheDocument();
    });
    it('clears the old network when another collector is chosen and searches its own networks', async () => {
        const setup = sourceFixture();
        mocks.json.mockImplementation(async (_camera: number, href: string) => {
            const query = new URLSearchParams(href.split('?')[1]),
                next = sourceFixture();
            next.choice = query.get('choice') as typeof next.choice;
            next.q = query.get('q') ?? '';
            next.collector_id = query.has('collector_id')
                ? Number(query.get('collector_id'))
                : null;
            if (next.collector_id)
                next.choices.scopes.items = next.choices.scopes.items.filter(
                    (item) => item.collector_id === next.collector_id,
                );
            return next;
        });
        render(<CctvSourcePage {...setup} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Edit connection' }),
        );
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 20));
        });
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Site collector' }),
        );
        fireEvent.click(
            await screen.findByRole('option', { name: 'Second collector' }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Approved network' }),
        ).toHaveTextContent('Choose approved network');
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 20));
        });
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Approved network' }),
        );
        expect(
            await screen.findByRole('option', { name: 'Second network' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Camera network' }),
        ).not.toBeInTheDocument();
        expect(mocks.json.mock.calls.at(-1)?.[1]).toContain('collector_id=52');
    });
    it('requires a reason and keeps enable recovery on the same reviewed request', async () => {
        mocks.json.mockRejectedValue(Error('lost'));
        render(<CctvSourcePage {...sourceFixture()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Enable camera stream' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Enable and verify stream' }),
        );
        expect(mocks.json).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Reason for this change'), {
            target: {
                value: 'Enable viewing for the authorised response team.',
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Enable and verify stream' }),
        );
        await screen.findByRole('button', { name: 'Check this request' });
        const body = mocks.json.mock.calls[0][3];
        expect(mocks.json.mock.calls[0][1]).toMatch(/\/source\/enable-stream$/);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check this request' }),
        );
        await waitFor(() => expect(mocks.json).toHaveBeenCalledTimes(2));
        expect(mocks.json.mock.calls[1][3]).toEqual(body);
    });
});
