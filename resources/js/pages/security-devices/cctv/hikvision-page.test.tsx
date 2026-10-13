import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { hikBase } from './_hikvision-contract';
import {
    hikDiscoveryFixture,
    hikFixture,
    hikMappingFixture,
    hikReviewFixture,
    hikSavedFixture,
} from './hikvision-test-fixtures';
import CctvSourcePage from './source';
const mocks = vi.hoisted(() => ({ json: vi.fn() }));
vi.mock('./live-transport', () => ({
    hikvisionJson: mocks.json,
    cctvSourceJson: vi.fn(),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
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
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
async function choose(label: string, name: string) {
    fireEvent.click(screen.getByRole('combobox', { name: label }));
    fireEvent.click(
        await screen.findByRole('option', { name: new RegExp(name) }),
    );
    await waitFor(() =>
        expect(screen.getByRole('combobox', { name: label })).toHaveTextContent(
            name,
        ),
    );
    await waitFor(() =>
        expect(screen.queryByRole('listbox')).not.toBeInTheDocument(),
    );
}
function respond() {
    const setup = hikFixture(),
        review = hikReviewFixture(setup);
    mocks.json.mockImplementation(
        async (
            _camera: number,
            _actor: number,
            path: string,
            _signal: AbortSignal,
            body?: unknown,
        ) => {
            if (path.includes('/setup?')) {
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
            if (path.endsWith('/hikvision-discoveries'))
                return hikDiscoveryFixture(setup);
            if (path.includes('/source/discoveries/')) return review;
            if (path.endsWith('/source') && body)
                return hikMappingFixture(setup, review);
            throw Error('Unexpected destination');
        },
    );
    return { setup, review };
}
async function reachChannels() {
    await waitFor(() =>
        expect(
            screen.getByRole('dialog').contains(document.activeElement),
        ).toBe(true),
    );
    await choose('Site collector', 'Aurora collector');
    await choose('Approved network', 'Approved camera network');
    await choose('Credential reference', 'Approved camera access');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Certificate server name'), {
        target: { value: 'camera.example.test' },
    });
    fireEvent.change(screen.getByLabelText('Certificate SHA-256'), {
        target: { value: 'c'.repeat(64) },
    });
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: { value: 'Review the approved camera for reception safety.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Check camera' }));
    await screen.findByText('Observed camera');
}
async function reachMapping() {
    await reachChannels();
    await choose('Camera channel', 'Reception');
    fireEvent.click(screen.getByRole('button', { name: 'Review connection' }));
}
describe('Hikvision manager workflow (component fixtures)', () => {
    it('checks equipment then saves only the selected channel and leaves viewing off', async () => {
        const { setup, review } = respond();
        render(<CctvSourcePage {...setup} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        await reachMapping();
        expect(mocks.json.mock.calls.filter((c) => c[4])).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Save connection' }),
        );
        await screen.findByText('Change confirmed');
        const mapping = mocks.json.mock.calls.find(
            (c) => c[2] === hikBase(42) + '/source' && c[4],
        );
        expect(mapping?.[4]).toMatchObject({
            expected_actor_id: 7,
            expected_revision: 0,
            discovery_uuid: review.discovery_uuid,
            expected_result_sha256: review.result_sha256,
            channel_id: '101',
        });
        expect(
            mocks.json.mock.calls.some((c) => c[2].endsWith('/enable')),
        ).toBe(false);
        expect(
            screen.getByText(
                'Connection saved. Refresh setup before turning on viewing.',
            ),
        ).toBeInTheDocument();
    });
    it('recovers a lost save with the same reviewed body and request key', async () => {
        const { setup, review } = respond();
        render(<CctvSourcePage {...setup} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        await reachMapping();
        const original = mocks.json.getMockImplementation()!;
        let lost = true;
        mocks.json.mockImplementation(async (...args) => {
            if (args[2] === hikBase(42) + '/source' && args[4] && lost) {
                lost = false;
                throw Error('Lost reply');
            }
            return original(...args);
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save connection' }),
        );
        await screen.findByRole('button', { name: 'Check this request' });
        const body = mocks.json.mock.calls.at(-1)?.[4];
        expect(screen.getByRole('button', { name: /Channels/ })).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check this request' }),
        );
        await screen.findByText('Change confirmed');
        const saves = mocks.json.mock.calls.filter(
            (c) => c[2] === hikBase(42) + '/source' && c[4],
        );
        expect(saves).toHaveLength(2);
        expect(saves[1][4]).toEqual(body);
        expect(saves[1][4].expected_result_sha256).toBe(review.result_sha256);
    });
    it('turns off the previous connection even when the newer mapping cannot be enabled', async () => {
        const setup = hikSavedFixture(true);
        setup.source = {
            ...setup.source!,
            id: 101,
            revision: 2,
            enabled: false,
        };
        setup.revision = 2;
        setup.readiness.can_enable = false;
        setup.readiness.reason_code = 'active_connection_exists';
        mocks.json.mockResolvedValue({
            actor_id: 7,
            camera_id: 42,
            source: {
                id: 100,
                revision: 1,
                enabled: false,
                stream_state: 'not_requested',
                stream_verified_at: null,
            },
        });
        render(<CctvSourcePage {...setup} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Turn off viewing' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByLabelText('Reason for this change'), {
            target: {
                value: 'Withdraw the previous reviewed camera connection.',
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Turn off viewing' }),
        );
        await screen.findByText('Change confirmed');
        expect(mocks.json.mock.calls[0][4].expected_revision).toBe(1);
        expect(
            screen.getByText('Viewing is off for this connection.'),
        ).toBeInTheDocument();
    });
    it('keeps viewing off while the Site collector or approved equipment support is unavailable', () => {
        const setup = hikSavedFixture(false);
        setup.readiness.can_enable = false;
        setup.readiness.reason_code = 'connection_unavailable';
        render(<CctvSourcePage {...setup} />);
        expect(
            screen.queryByRole('button', { name: 'Turn on viewing' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'View camera' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/Viewing is unavailable/)).toBeInTheDocument();
    });
    it('rejects changed camera ownership before showing any setup actions', () => {
        const setup = hikFixture();
        setup.camera.id = 999;
        render(<CctvSourcePage {...setup} />);
        expect(screen.getAllByRole('alert')[0]).toHaveTextContent(
            'Camera setup could not be verified',
        );
        expect(mocks.json).not.toHaveBeenCalled();
    });
});
