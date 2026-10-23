import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    confirmsIndependenceReceipt,
    INCIDENT_INDEPENDENCE_KEY,
    type IndependenceCommand,
    type IndependencePolicy,
} from './incident-independence';
import { IncidentIndependencePanel } from './incident-independence-panel';

const io = vi.hoisted(() => ({ put: vi.fn(), processing: false }));
vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        router: { get: vi.fn() },
        useForm: (initial: IndependenceCommand) => {
            const original = React.useRef(initial);
            const [data, setData] = React.useState(initial);
            return {
                data,
                setData,
                errors: {},
                processing: io.processing,
                isDirty:
                    JSON.stringify(data) !== JSON.stringify(original.current),
                clearErrors: vi.fn(),
                put: (url: string, options: unknown) =>
                    io.put(url, data, options),
            };
        },
    };
});
const policy: IndependencePolicy = {
    schema_version: 1,
    mode: 'block_involved_access',
    policy_revision: 0,
    configured: false,
    configuration_valid: true,
    origin: 'application_default_unreviewed',
    policy_digest: 'a'.repeat(64),
    setting_change_id: null,
    reviewed_by: null,
    reviewed_at: null,
};
const panel = (actorId = 7, canManage = true, current = policy) => (
    <IncidentIndependencePanel
        policy={current}
        history={{ data: [], last_page: 1, links: [] }}
        canManage={canManage}
        actorId={actorId}
        discard={(action) => action()}
    />
);
const edit = () =>
    fireEvent.click(
        screen.getByRole('button', { name: 'Edit independent review' }),
    );
const reason = (value = 'Organisation decision about independent review') =>
    fireEvent.change(
        screen.getByLabelText('Reason for this independent review policy'),
        { target: { value } },
    );
const save = () =>
    fireEvent.click(
        screen.getByRole('button', { name: 'Save independent review policy' }),
    );
const latest = () => io.put.mock.calls.at(-1)!;
const receipt = (command: IndependenceCommand) => ({
    accepted: true,
    request_uuid: command.request_uuid,
    setting_key: INCIDENT_INDEPENDENCE_KEY,
    policy_revision: command.expected_policy_revision + 1,
    mode: command.mode,
    policy_digest: 'b'.repeat(64),
    action: 'changed',
    actor_id: 7,
});
const result = (command: IndependenceCommand) => ({
    props: {
        health_safety_independence_receipt: receipt(command),
        independence_viewer_id: 7,
        auth: { user: { id: 7 } },
    },
});
beforeEach(() => {
    io.put.mockReset();
    io.processing = false;
});
afterEach(cleanup);

it('shows the unreviewed application default without inventing a reviewer or acceptance', () => {
    render(panel());
    expect(screen.getByText('Application default · not reviewed')).toBeTruthy();
    expect(
        screen.getByText('Organisation review has not been recorded.'),
    ).toBeTruthy();
    expect(screen.queryByText('Independent review policy saved.')).toBeNull();
});

it('saves the explicit assignment-only choice with its reason and captured policy revision', () => {
    render(panel());
    edit();
    reason();
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Independent review protection' }),
        { target: { value: 'assignment_only' } },
    );
    expect(
        screen.getByText(
            /Existing read and notification permissions still apply/,
        ),
    ).toBeTruthy();
    save();
    expect(latest()[0]).toBe('/health-safety/settings/incident-independence');
    expect(latest()[1]).toMatchObject({
        mode: 'assignment_only',
        reason: 'Organisation decision about independent review',
        expected_policy_revision: 0,
    });
    act(() => latest()[2].onSuccess(result(latest()[1])));
    expect(screen.getByText('Independent review policy saved.')).toBeTruthy();
});

it('keeps an interrupted envelope unchanged and permits correction after a definite retry rejection', () => {
    render(panel());
    edit();
    reason();
    save();
    const first = structuredClone(latest()[1]);
    act(() => latest()[2].onFinish());
    reason('Attempted overwrite while uncertain');
    save();
    expect(latest()[1]).toEqual(first);
    act(() => latest()[2].onError());
    reason('Corrected organisation decision');
    save();
    expect(latest()[1].request_uuid).not.toBe(first.request_uuid);
    expect(latest()[1].reason).toBe('Corrected organisation decision');
});

it('retains an in-flight envelope across access withdrawal and requires a current restored retry', () => {
    const view = render(panel());
    edit();
    reason();
    save();
    const [, first, options] = latest();
    view.rerender(panel(7, false));
    act(() => {
        options.onFinish();
        options.onSuccess(result(first));
    });
    expect(screen.queryByText('Independent review policy saved.')).toBeNull();
    expect(
        screen.getByRole('button', { name: 'Save independent review policy' }),
    ).toHaveProperty('disabled', true);
    view.rerender(panel());
    reason('Do not replace an unconfirmed envelope');
    save();
    expect(latest()[1]).toEqual(first);
    act(() => latest()[2].onSuccess(result(first)));
    expect(screen.getByText('Independent review policy saved.')).toBeTruthy();
});

it('discards the previous account draft and ignores its late responses', () => {
    const view = render(panel());
    edit();
    reason('First account private reason');
    save();
    const [, first, options] = latest();
    view.rerender(panel(9));
    expect(
        screen.queryByDisplayValue('First account private reason'),
    ).toBeNull();
    edit();
    reason('Second account local reason');
    act(() => {
        options.onSuccess(result(first));
        options.onError();
        options.onFinish();
    });
    expect(screen.queryByText('Independent review policy saved.')).toBeNull();
    expect(
        screen.getByLabelText('Reason for this independent review policy'),
    ).toHaveProperty('value', 'Second account local reason');
});

it('does not accept a receipt delivered for another current account', () => {
    render(panel());
    edit();
    reason();
    save();
    act(() =>
        latest()[2].onSuccess({
            props: {
                ...result(latest()[1]).props,
                independence_viewer_id: 9,
                auth: { user: { id: 9 } },
            },
        }),
    );
    expect(screen.queryByText('Independent review policy saved.')).toBeNull();
    expect(
        screen.getByText(/The policy save could not be confirmed/),
    ).toBeTruthy();
});

it('keeps the captured revision and draft when a different setting revision arrives', () => {
    const view = render(panel());
    edit();
    reason();
    view.rerender(
        panel(7, true, {
            ...policy,
            policy_revision: 4,
            origin: 'organisation_setting',
            configured: true,
        }),
    );
    expect(screen.getByText(/The policy revision changed/)).toBeTruthy();
    save();
    expect(latest()[1].expected_policy_revision).toBe(0);
});

it('requires complete exact actor, mode, UUID, policy key, revision and digest receipt evidence', () => {
    const command: IndependenceCommand = {
        mode: 'block_involved_access',
        expected_policy_revision: 0,
        request_uuid: crypto.randomUUID(),
        reason: 'Explicit decision',
    };
    const accepted = receipt(command);
    expect(confirmsIndependenceReceipt(accepted, command, 7)).toBe(true);
    for (const update of [
        { accepted: false },
        { actor_id: 9 },
        { mode: 'assignment_only' },
        { request_uuid: crypto.randomUUID() },
        { setting_key: 'healthSafety.incidents.owner_routing' },
        { policy_revision: 0 },
        { policy_digest: null },
        { action: 'unknown' },
    ]) {
        expect(
            confirmsIndependenceReceipt({ ...accepted, ...update }, command, 7),
        ).toBe(false);
    }
});

it('does not report cancellation as a saved policy', () => {
    render(panel());
    edit();
    reason();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel editing' }));
    expect(screen.queryByText('Independent review policy saved.')).toBeNull();
    expect(io.put).not.toHaveBeenCalled();
});
