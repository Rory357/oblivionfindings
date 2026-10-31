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
import { RecordingReferenceDialog } from './_recording-dialog';
import RecordingReferencePage from './recording-reference';
import RecordingReferencesPage from './recording-references';
import {
    recordingDetailFixture,
    recordingPageFixture,
} from './recording-test-fixtures';
const mock = vi.hoisted(() => ({ json: vi.fn(), visit: vi.fn() }));
vi.mock('./live-transport', () => ({ cctvRecordingJson: mock.json }));
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
    router: { on: () => () => undefined, visit: mock.visit },
}));
vi.mock('@/components/clinical/actual-event-time-field', async () => {
    const actual = await vi.importActual<
        typeof import('@/components/clinical/actual-event-time-field')
    >('@/components/clinical/actual-event-time-field');
    return {
        ...actual,
        ActualEventTimeField: ({
            label,
            value,
            onChange,
        }: {
            label: string;
            value: string;
            onChange: (v: string, o: string) => void;
        }) => (
            <input
                aria-label={label}
                value={value}
                onChange={(e) => onChange(e.target.value, '')}
            />
        ),
    };
});
beforeEach(() => {
    mock.json.mockReset();
    mock.visit.mockReset();
});
afterEach(cleanup);
async function review() {
    await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 20));
    });
    fireEvent.change(screen.getByLabelText('Window start'), {
        target: { value: '2026-10-08T20:55' },
    });
    fireEvent.change(screen.getByLabelText('Window end'), {
        target: { value: '2026-10-08T21:15' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Reason for this reference'), {
        target: { value: recordingDetailFixture().reference.purpose },
    });
}
describe('Recording reference placement', () => {
    it('roots both trails at Home and follows the sidebar to All devices and the camera', () => {
        render(<RecordingReferencesPage {...recordingPageFixture()} />);
        expect(trail()).toEqual([
            ['Home', '/dashboard'],
            ['Security & Devices', '/security-devices'],
            ['All devices', '/security-devices/devices'],
            ['Main entrance', '/security-devices/devices/42'],
            ['Recording references', null],
        ]);
        cleanup();
        render(<RecordingReferencePage {...recordingDetailFixture()} />);
        expect(trail()).toEqual([
            ['Home', '/dashboard'],
            ['Security & Devices', '/security-devices'],
            ['All devices', '/security-devices/devices'],
            ['Main entrance', '/security-devices/devices/42'],
            [
                'Recording references',
                '/security-devices/devices/42/recording-references',
            ],
            ['Saved reference', null],
        ]);
    });
});
describe('Recording reference workflows', () => {
    it('separates current access, reference creation and unverified footage', () => {
        const page = recordingPageFixture();
        page.permissions.can_create = false;
        page.create_href = null;
        render(<RecordingReferencesPage {...page} />);
        expect(
            screen.queryByRole('button', { name: 'Add recording reference' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('No recording references')).toBeInTheDocument();
        expect(mock.json).not.toHaveBeenCalled();
        cleanup();
        render(<RecordingReferencePage {...recordingDetailFixture()} />);
        expect(
            screen.getByText('Footage not verified or preserved'),
        ).toBeInTheDocument();
        expect(screen.getByText('Unknown')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /play|preserve|export/i }),
        ).not.toBeInTheDocument();
    });
    it('recovers a lost save by an actor-private read and keeps the reviewed input locked', async () => {
        const detail = recordingDetailFixture(),
            saved = vi.fn();
        mock.json
            .mockRejectedValueOnce(Error('lost'))
            .mockResolvedValueOnce(detail);
        render(
            <RecordingReferenceDialog
                page={recordingPageFixture()}
                open
                onClose={() => {}}
                onSaved={saved}
            />,
        );
        await review();
        expect(mock.json).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save reference' }));
        await screen.findByRole('button', { name: 'Check save status' });
        expect(
            screen.getByLabelText('Reason for this reference'),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Back' }),
        ).not.toBeInTheDocument();
        const body = mock.json.mock.calls[0][4];
        fireEvent.click(
            screen.getByRole('button', { name: 'Check save status' }),
        );
        await waitFor(() => expect(saved).toHaveBeenCalledOnce());
        expect(mock.json.mock.calls[1][2]).toBe(
            '/security-devices/cctv/recording-references/requests/' +
                body.request_key +
                '?expected_actor_id=7',
        );
        expect(mock.json.mock.calls[1][4]).toBeUndefined();
        expect(
            screen.getByRole('link', { name: 'Open reference' }),
        ).toHaveAttribute('href', detail.reference.reader_href);
    });
    it('retries the exact same request without another UUID or changed window', async () => {
        mock.json
            .mockRejectedValueOnce(Error('lost'))
            .mockResolvedValueOnce(recordingDetailFixture());
        render(
            <RecordingReferenceDialog
                page={recordingPageFixture()}
                open
                onClose={() => {}}
                onSaved={() => {}}
            />,
        );
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save reference' }));
        await screen.findByRole('button', { name: 'Retry same save' });
        const first = structuredClone(mock.json.mock.calls[0][4]);
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same save' }),
        );
        await screen.findByText('Reference saved');
        expect(mock.json.mock.calls[1][4]).toEqual(first);
    });
    it('does not disclose a mismatched actor receipt as a successful save', async () => {
        const detail = recordingDetailFixture();
        detail.actor_id = 8;
        mock.json.mockResolvedValue(detail);
        const saved = vi.fn();
        render(
            <RecordingReferenceDialog
                page={recordingPageFixture()}
                open
                onClose={() => {}}
                onSaved={saved}
            />,
        );
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save reference' }));
        await screen.findByRole('button', { name: 'Check save status' });
        expect(saved).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('link', { name: 'Open reference' }),
        ).not.toBeInTheDocument();
    });
    it('aborts a late response when the acting viewer changes or leaves', async () => {
        let resolve!: (value: unknown) => void;
        mock.json.mockImplementation(
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        const saved = vi.fn();
        const ui = render(
            <RecordingReferenceDialog
                page={recordingPageFixture()}
                open
                onClose={() => {}}
                onSaved={saved}
            />,
        );
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save reference' }));
        await waitFor(() => expect(mock.json).toHaveBeenCalledOnce());
        ui.unmount();
        await act(async () => resolve(recordingDetailFixture()));
        expect(saved).not.toHaveBeenCalled();
    });
});
