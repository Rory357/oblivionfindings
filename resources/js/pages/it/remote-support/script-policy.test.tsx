import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ScriptPolicyEditor } from './script-policy';
import {
    scriptPolicyReferenceFor,
    scriptPolicyReply,
    type ScriptPolicyAttempt,
    type ScriptPolicyReply,
} from './script-policy-contract';
import {
    retainScriptPolicyReference,
    scriptPolicyReferences,
} from './script-policy-journal';

const auth = vi.hoisted(() => ({ actor: 2001 as number | null }));
vi.mock('axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: auth.actor === null ? null : { user: { id: auth.actor } },
        },
    }),
    router: { on: () => () => undefined },
}));
const device = { id: 2100, name: 'Reception computer' };
// Synthetic component fixtures, not an original-native HTTP interoperability export.
function current(): ScriptPolicyReply {
    return {
        actor_id: 2001,
        device_id: 2100,
        policy: { version: 0, enabled: false, consent_mode: 'attended' },
        assignment_matches: true,
        available: false,
        unavailable_reason: 'script_execution_not_implemented',
        can_manage: true,
        can_run: false,
        can_unattended: false,
        identity_confirmed: true,
    };
}
function saved(attempt: ScriptPolicyAttempt): ScriptPolicyReply {
    const policy = {
        version: attempt.expected_version + 1,
        enabled: attempt.enabled,
        consent_mode: attempt.consent_mode,
    };
    return {
        ...current(),
        policy,
        receipt: {
            actor_id: 2001,
            device_id: 2100,
            request_key: attempt.request_key,
            assignment_fingerprint: 'a'.repeat(64),
            policy,
            reason: attempt.reason,
            recorded_at: '2026-10-20T11:00:00Z',
        },
    };
}
beforeEach(() => {
    vi.resetAllMocks();
    sessionStorage.clear();
    auth.actor = 2001;
    vi.stubGlobal('crypto', webcrypto);
    vi.mocked(axios.get).mockResolvedValue({ data: current() });
    vi.mocked(axios.put).mockImplementation(async (_url, body) => ({
        data: saved(body as ScriptPolicyAttempt),
    }));
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});
async function open() {
    const view = render(<ScriptPolicyEditor actorId={2001} device={device} />);
    fireEvent.click(
        await screen.findByRole('button', { name: 'Script policy' }),
    );
    await screen.findByLabelText('Reason for this change');
    return view;
}
async function review() {
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: { value: 'Allow reviewed scripts with staff consent.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Review policy' }));
    await screen.findByRole('button', { name: 'Save policy' });
}

describe('script policy editor', () => {
    it('reviews a new actor-bound request and keeps execution unavailable', async () => {
        await open();
        await review();
        expect(axios.put).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText('Policy change recorded');
        expect(axios.put).toHaveBeenCalledTimes(1);
        const body = vi.mocked(axios.put).mock
            .calls[0][1] as ScriptPolicyAttempt;
        expect(body).toMatchObject({
            expected_actor_id: 2001,
            expected_version: 0,
            enabled: true,
            consent_mode: 'attended',
            reason: 'Allow reviewed scripts with staff consent.',
        });
        expect(body.request_key).toMatch(/^[0-9a-f-]{36}$/);
        expect(Object.isFrozen(body)).toBe(true);
        expect(
            screen.getByText(/Script execution is still unavailable/),
        ).toBeInTheDocument();
    });

    it('retries only the frozen original request after an uncertain save', async () => {
        vi.mocked(axios.put).mockRejectedValueOnce(Error('Lost response'));
        await open();
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        expect(
            screen.queryByLabelText('Reason for this change'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same save' }),
        );
        await screen.findByText('Policy change recorded');
        expect(vi.mocked(axios.put).mock.calls[1][1]).toBe(
            vi.mocked(axios.put).mock.calls[0][1],
        );
    });

    it('recovers after close and reopen without posting again and shows a newer current policy', async () => {
        vi.mocked(axios.put).mockRejectedValueOnce(Error('Lost response'));
        await open();
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        const body = vi.mocked(axios.put).mock
            .calls[0][1] as ScriptPolicyAttempt;
        fireEvent.click(screen.getAllByRole('button', { name: 'Close' })[0]);
        const recovery = saved(body);
        recovery.policy = {
            version: 2,
            enabled: false,
            consent_mode: 'attended',
        };
        vi.mocked(axios.get).mockResolvedValueOnce({ data: recovery });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check script policy save' }),
        );
        await screen.findByText('Policy change recorded');
        expect(
            screen.getByText(/Current script setting: disabled/),
        ).toBeInTheDocument();
        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(vi.mocked(axios.get).mock.calls.at(-1)?.[0]).toBe(
            `/security-devices/devices/2100/endpoint/script-policy/requests/${body.request_key}?expected_actor_id=2001`,
        );
    });

    it('keeps private context hidden after a denial followed by a network error', async () => {
        vi.mocked(axios.put).mockRejectedValueOnce({
            response: { status: 403 },
        });
        await open();
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/Current access could not be confirmed/);
        expect(screen.queryByText(device.name)).not.toBeInTheDocument();
        expect(
            screen.queryByText('Allow reviewed scripts with staff consent.'),
        ).not.toBeInTheDocument();
        vi.mocked(axios.get).mockRejectedValueOnce(Error('Offline'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Check current access' }),
        );
        await screen.findByText(/could not be loaded/);
        expect(
            screen.queryByRole('button', { name: 'Retry same save' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Reason for this change'),
        ).not.toBeInTheDocument();
        expect(axios.put).toHaveBeenCalledTimes(1);
    });

    it('requires identity confirmation before changing settings', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: { ...current(), identity_confirmed: false },
        });
        await open();
        expect(screen.getByRole('switch')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Review policy' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('link', { name: /Confirm your identity/ }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/2100/endpoint/script-policy/confirm-identity?expected_actor_id=2001',
        );
    });

    it('clears private drafts when shared authentication changes and ignores late replies', async () => {
        const view = await open();
        await review();
        let resolve!: (value: { data: ScriptPolicyReply }) => void;
        vi.mocked(axios.put).mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await waitFor(() => expect(axios.put).toHaveBeenCalledTimes(1));
        const body = vi.mocked(axios.put).mock
            .calls[0][1] as ScriptPolicyAttempt;
        const signal = vi.mocked(axios.put).mock.calls[0][2]
            ?.signal as AbortSignal;
        auth.actor = null;
        view.rerender(<ScriptPolicyEditor actorId={2001} device={device} />);
        expect(signal.aborted).toBe(true);
        expect(
            screen.queryByText('Allow reviewed scripts with staff consent.'),
        ).not.toBeInTheDocument();
        await act(async () => resolve({ data: saved(body) }));
        expect(
            screen.queryByText('Policy change recorded'),
        ).not.toBeInTheDocument();
    });

    it('does not display a policy entry or issue a read for another shared actor', () => {
        auth.actor = 3001;
        render(<ScriptPolicyEditor actorId={2001} device={device} />);
        expect(axios.get).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Script policy' }),
        ).not.toBeInTheDocument();
    });

    it('recovers a lost reply after unmount and reload without storing private form values', async () => {
        vi.mocked(axios.put).mockRejectedValueOnce(Error('Lost reply'));
        const view = await open();
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        const body = vi.mocked(axios.put).mock
            .calls[0][1] as ScriptPolicyAttempt;
        const stored = sessionStorage.getItem(sessionStorage.key(0)!);
        expect(stored).not.toContain(body.reason);
        expect(stored).not.toMatch(/consent_mode|enabled|Reception/);
        expect(scriptPolicyReferences(2001, 2100)).toHaveLength(1);
        view.unmount();
        vi.mocked(axios.get).mockImplementation(async (url) => ({
            data: String(url).includes('/requests/') ? saved(body) : current(),
        }));
        render(<ScriptPolicyEditor actorId={2001} device={device} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Check script policy save',
            }),
        );
        await screen.findByText('Policy change recorded');
        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(scriptPolicyReferences(2001, 2100)).toEqual([]);
    });

    it('blocks mutation when the browser cannot retain a recovery reference', async () => {
        await open();
        await review();
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw Error('Storage unavailable');
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/No save was sent/);
        expect(axios.put).not.toHaveBeenCalled();
        expect(
            screen.getByText('Allow reviewed scripts with staff consent.'),
        ).toBeInTheDocument();
    });

    it('keeps a missing reloaded request unresolved and reviews a separate current revision explicitly', async () => {
        const original: ScriptPolicyAttempt = {
            expected_actor_id: 2001,
            expected_version: 0,
            request_key: '11111111-1111-4111-8111-111111111111',
            enabled: true,
            consent_mode: 'attended',
            reason: 'Previous unconfirmed policy request.',
        };
        retainScriptPolicyReference(
            await scriptPolicyReferenceFor(original, 2100),
        );
        render(<ScriptPolicyEditor actorId={2001} device={device} />);
        const button = await screen.findByRole('button', {
            name: 'Check script policy save',
        });
        vi.mocked(axios.get).mockRejectedValueOnce({
            response: { status: 404 },
        });
        fireEvent.click(button);
        await screen.findByText(/Current access could not be confirmed/);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check current access' }),
        );
        await screen.findByRole('button', { name: 'Review a separate change' });
        expect(
            screen.getByRole('button', { name: 'Retry same save' }),
        ).toBeDisabled();
        expect(axios.put).not.toHaveBeenCalled();
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: {
                ...current(),
                policy: {
                    version: 1,
                    enabled: false,
                    consent_mode: 'attended',
                },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review a separate change' }),
        );
        await screen.findByLabelText('Reason for this change');
        expect(
            screen.getByText(
                /Earlier unconfirmed saves have not been cancelled/,
            ),
        ).toBeInTheDocument();
        await review();
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText('Policy change recorded');
        const sent = vi.mocked(axios.put).mock
            .calls[0][1] as ScriptPolicyAttempt;
        expect(sent.expected_version).toBe(1);
        expect(sent.request_key).not.toBe(original.request_key);
        expect(
            scriptPolicyReferences(2001, 2100).map((row) => row.request_key),
        ).toEqual([original.request_key]);
    });

    it('dismisses only a browser reminder without claiming cancellation or sending a write', async () => {
        const original: ScriptPolicyAttempt = {
            expected_actor_id: 2001,
            expected_version: 0,
            request_key: '11111111-1111-4111-8111-111111111111',
            enabled: true,
            consent_mode: 'attended',
            reason: 'Previous unconfirmed policy request.',
        };
        retainScriptPolicyReference(
            await scriptPolicyReferenceFor(original, 2100),
        );
        render(<ScriptPolicyEditor actorId={2001} device={device} />);
        const button = await screen.findByRole('button', {
            name: 'Check script policy save',
        });
        vi.mocked(axios.get).mockRejectedValueOnce(
            Error('Network unavailable'),
        );
        fireEvent.click(button);
        await screen.findByText(/saved request could not be confirmed/);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check current access' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Dismiss recovery reminder',
            }),
        );
        expect(
            screen.getByText(
                /does not cancel a save or delete its server record/,
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Dismiss reminder' }),
        );
        await screen.findByLabelText('Reason for this change');
        expect(scriptPolicyReferences(2001, 2100)).toEqual([]);
        expect(axios.put).not.toHaveBeenCalled();
    });

    it('does not persist or post a prepared save after authentication changes during hashing', async () => {
        const view = await open();
        await review();
        let resolve!: (value: ArrayBuffer) => void;
        vi.stubGlobal('crypto', {
            randomUUID: () => webcrypto.randomUUID(),
            subtle: {
                digest: () =>
                    new Promise<ArrayBuffer>((done) => {
                        resolve = done;
                    }),
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        auth.actor = null;
        view.rerender(<ScriptPolicyEditor actorId={2001} device={device} />);
        await act(async () => resolve(new ArrayBuffer(32)));
        expect(axios.put).not.toHaveBeenCalled();
        expect(sessionStorage.length).toBe(0);
    });

    it('clears unreadable reminders only for the selected account and computer after confirmation', async () => {
        const original: ScriptPolicyAttempt = {
            expected_actor_id: 2001,
            expected_version: 0,
            request_key: '11111111-1111-4111-8111-111111111111',
            enabled: true,
            consent_mode: 'attended',
            reason: 'Previous unconfirmed policy request.',
        };
        retainScriptPolicyReference(
            await scriptPolicyReferenceFor(original, 2200),
        );
        sessionStorage.setItem(
            'obli.script-policy.references.v1:2001:2100',
            '{broken',
        );
        await open();
        expect(
            screen.getByRole('button', { name: 'Review policy' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear unreadable reminders' }),
        );
        expect(
            screen.getByText(
                /does not cancel earlier saves or delete server records/,
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear browser reminders' }),
        );
        await waitFor(() =>
            expect(
                screen.getByLabelText('Reason for this change'),
            ).toBeEnabled(),
        );
        expect(scriptPolicyReferences(2001, 2100)).toEqual([]);
        expect(scriptPolicyReferences(2001, 2200)).toHaveLength(1);
        expect(axios.put).not.toHaveBeenCalled();
    });

    it('drops the old computer draft when a different computer is selected', async () => {
        const view = await open();
        await review();
        const other = { id: 2200, name: 'Office computer' };
        vi.mocked(axios.get).mockResolvedValue({
            data: { ...current(), device_id: other.id },
        });
        view.rerender(<ScriptPolicyEditor actorId={2001} device={other} />);
        expect(
            screen.queryByText('Allow reviewed scripts with staff consent.'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Script policy' }),
        );
        await screen.findByLabelText('Reason for this change');
        expect(screen.getByLabelText('Reason for this change')).toHaveValue('');
        expect(screen.queryByText(device.name)).not.toBeInTheDocument();
        expect(axios.put).not.toHaveBeenCalled();
    });

    it('hides policy settings when current permission is denied', async () => {
        vi.mocked(axios.get).mockRejectedValue({ response: { status: 403 } });
        const view = render(
            <ScriptPolicyEditor actorId={2001} device={device} />,
        );
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(1));
        expect(view.container).toBeEmptyDOMElement();
    });

    it('rejects changed receipts and unsupported or malformed current policy documents', () => {
        const attempt: ScriptPolicyAttempt = {
            expected_actor_id: 2001,
            expected_version: 0,
            request_key: '11111111-1111-4111-8111-111111111111',
            enabled: true,
            consent_mode: 'attended',
            reason: 'Reviewed synthetic policy change.',
        };
        expect(
            scriptPolicyReply(saved(attempt), 2001, 2100, attempt).receipt
                ?.request_key,
        ).toBe(attempt.request_key);
        const malformed = [
            { ...current(), actor_id: 3001 },
            { ...current(), available: true },
            { ...current(), extra: true },
            {
                ...current(),
                policy: { version: 0, enabled: true, consent_mode: 'attended' },
            },
            {
                ...current(),
                policy: {
                    version: 1,
                    enabled: true,
                    consent_mode: ['attended'],
                },
            },
            {
                ...current(),
                assignment_matches: false,
                policy: { version: 1, enabled: true, consent_mode: 'attended' },
            },
        ];
        for (const value of malformed)
            expect(() => scriptPolicyReply(value, 2001, 2100)).toThrow();
        for (const change of [
            { reason: 'Different private purpose.' },
            { request_key: '22222222-2222-4222-8222-222222222222' },
            { recorded_at: '2026-02-31T11:00:00Z' },
        ]) {
            const value = saved(attempt);
            Object.assign(value.receipt!, change);
            expect(() =>
                scriptPolicyReply(value, 2001, 2100, attempt),
            ).toThrow();
        }
    });
});
