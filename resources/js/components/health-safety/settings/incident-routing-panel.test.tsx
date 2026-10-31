import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { InternalAxiosRequestConfig } from 'axios';
import axios from 'axios';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    INCIDENT_ROUTING_KEY,
    type RoutingCommand,
    type RoutingSnapshot,
} from './incident-routing';
import { IncidentRoutingPanel } from './incident-routing-panel';

const io = vi.hoisted(() => ({ put: vi.fn(), processing: false }));
vi.mock('axios', () => ({ default: { get: vi.fn() } }));
vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        router: { get: vi.fn() },
        useForm: (initial: RoutingCommand) => {
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
const snapshot: RoutingSnapshot = {
    setting_key: INCIDENT_ROUTING_KEY,
    revision: 1,
    configuration_valid: true,
    state: 'configured',
    configuration: {
        schema_version: 1,
        routes: [
            {
                site_id: 8,
                scope: 'ordinary',
                lead_user_id: 51,
                effective_from: '2026-10-12T00:00:00.000000Z',
                effective_until: null,
                cover_user_id: null,
                cover_from: null,
                cover_until: null,
            },
        ],
        triage: { visibility: 'restricted', state: 'configured' },
    },
    digest: 'a'.repeat(64),
    setting_change_id: 3,
    recorded_at: '2026-10-12T00:00:00Z',
};
const history = { data: [], last_page: 1, links: [] };
const sites = [{ id: 8, name: 'Synthetic supported home' }];
const discard = (action: () => void) => action();
const panel = (
    canManage = true,
    current = snapshot,
    currentSites = sites,
    actorId = 7,
) => (
    <IncidentRoutingPanel
        snapshot={current}
        history={history}
        sites={currentSites}
        actorId={actorId}
        canManage={canManage}
        discard={discard}
    />
);
const edit = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Edit routing' }));
const reason = (value = 'Reviewed incident responsibilities') =>
    fireEvent.change(
        screen.getByLabelText('Reason for this organisation decision'),
        { target: { value } },
    );
const save = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Save routing' }));
const latest = () => io.put.mock.calls.at(-1)!;
function receipt(input: RoutingCommand) {
    return {
        accepted: true,
        setting_key: INCIDENT_ROUTING_KEY,
        request_uuid: input.request_uuid,
        actor_id: 7,
        revision: input.expected_revision + 1,
        action: 'changed',
        configuration_digest: 'b'.repeat(64),
    };
}
beforeEach(() => {
    io.put.mockReset();
    io.processing = false;
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.get).mockImplementation(async (_url, config) => ({
        data: {
            viewer_id: 7,
            candidates: config?.params?.selected_id
                ? [
                      {
                          id: Number(config.params.selected_id),
                          name: 'Synthetic eligible lead',
                      },
                  ]
                : [],
        },
        status: 200,
        statusText: 'OK',
        headers: {},
        config: config as InternalAxiosRequestConfig,
    }));
});
afterEach(cleanup);

it('keeps restricted triage omitted and original precise instants unchanged in a scoped save', () => {
    render(panel());
    edit();
    reason();
    save();
    const command = latest()[1] as RoutingCommand;
    expect(command.configuration).not.toHaveProperty('triage');
    expect(command.configuration.routes[0].effective_from).toBe(
        snapshot.configuration!.routes[0].effective_from,
    );
    expect(command.expected_revision).toBe(1);
    expect(
        screen.getByText(/Saved triage references are restricted/),
    ).toBeTruthy();
});

it('does not show saved confirmation when editing is cancelled', () => {
    render(panel());
    edit();
    reason();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel editing' }));
    expect(screen.queryByText('Routing configuration saved.')).toBeNull();
    expect(io.put).not.toHaveBeenCalled();
});

it('shows admitted triage names but submits only the exact three reference fields', () => {
    const current: RoutingSnapshot = {
        ...snapshot,
        configuration: {
            ...snapshot.configuration!,
            triage: {
                visibility: 'readable',
                state: 'configured',
                values: {
                    user_id: 71,
                    escalation_user_id: 72,
                    escalation_after_minutes: 47,
                },
                display_names: {
                    user: 'Synthetic triage coordinator',
                    escalation: 'Synthetic escalation recipient',
                },
            },
        },
    };
    render(panel(true, current));
    expect(screen.getByText('Synthetic triage coordinator')).toBeTruthy();
    expect(screen.getByText('Synthetic escalation recipient')).toBeTruthy();
    edit();
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Triage decision' }),
        { target: { value: 'set' } },
    );
    reason();
    save();
    expect(latest()[1].configuration.triage).toEqual({
        user_id: 71,
        escalation_user_id: 72,
        escalation_after_minutes: 47,
    });
});

it('freezes an interrupted request envelope while allowing the exact unchanged retry', () => {
    render(panel());
    edit();
    reason();
    save();
    const first = structuredClone(latest()[1]);
    act(() => latest()[2].onFinish());
    expect(screen.getByRole('alert').textContent).toContain(
        'response was interrupted',
    );
    save();
    expect(latest()[1]).toEqual(first);
    reason('Changed responsibility review');
    save();
    expect(latest()[1]).toEqual(first);
    expect(
        screen
            .getByLabelText('Reason for this organisation decision')
            .matches(':disabled'),
    ).toBe(true);
    expect(screen.getByRole('button', { name: 'Save routing' })).toHaveProperty(
        'disabled',
        false,
    );
});

it('allows correction after an interrupted request retry receives a definitive validation error', () => {
    render(panel());
    edit();
    reason('First responsibility review');
    save();
    const first = latest()[1].request_uuid;
    act(() => latest()[2].onFinish());
    save();
    expect(latest()[1].request_uuid).toBe(first);
    act(() => latest()[2].onError());
    reason('Changed responsibility review');
    save();
    expect(latest()[1].request_uuid).not.toBe(first);
    expect(latest()[1].reason).toBe('Changed responsibility review');
});

it('requires the exact retained actor/request/revision receipt before closing a saved form', () => {
    render(panel());
    edit();
    reason();
    save();
    const input = latest()[1] as RoutingCommand;
    const options = latest()[2];
    act(() =>
        options.onSuccess({
            props: {
                health_safety_routing_receipt: {
                    ...receipt(input),
                    actor_id: 99,
                },
                routing_viewer_id: 7,
                auth: { user: { id: 7 } },
            },
        }),
    );
    expect(screen.queryByText('Routing configuration saved.')).toBeNull();
    expect(
        screen.getByLabelText('Reason for this organisation decision'),
    ).toHaveProperty('value', 'Reviewed incident responsibilities');
    act(() =>
        options.onSuccess({
            props: {
                health_safety_routing_receipt: receipt(input),
                routing_viewer_id: 7,
                auth: { user: { id: 7 } },
            },
        }),
    );
    expect(screen.getByText('Routing configuration saved.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Save routing' })).toBeNull();
});

it('discards another account draft and ignores its late successful and failed responses', () => {
    const view = render(panel());
    edit();
    reason('First account private routing reason');
    save();
    const [, input, options] = latest();
    view.rerender(panel(true, snapshot, sites, 9));
    expect(
        screen.queryByDisplayValue('First account private routing reason'),
    ).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save routing' })).toBeNull();
    edit();
    reason('Second account local decision');
    act(() => {
        options.onSuccess({
            props: {
                health_safety_routing_receipt: receipt(input),
                routing_viewer_id: 7,
                auth: { user: { id: 7 } },
            },
        });
        options.onError();
        options.onFinish();
    });
    expect(screen.queryByText('Routing configuration saved.')).toBeNull();
    expect(
        screen.getByLabelText('Reason for this organisation decision'),
    ).toHaveProperty('value', 'Second account local decision');
    expect(screen.getByRole('button', { name: 'Save routing' })).toBeTruthy();
});

it('does not confirm an original actor receipt returned with a different current account', () => {
    render(panel());
    edit();
    reason();
    save();
    const [, input, options] = latest();
    act(() =>
        options.onSuccess({
            props: {
                health_safety_routing_receipt: receipt(input),
                routing_viewer_id: 9,
                auth: { user: { id: 9 } },
            },
        }),
    );
    expect(screen.queryByText('Routing configuration saved.')).toBeNull();
    expect(
        screen.getByLabelText('Reason for this organisation decision'),
    ).toHaveProperty('value', 'Reviewed incident responsibilities');
    expect(
        screen.getByText(/The routing save could not be confirmed/),
    ).toBeTruthy();
});

it('resolves the selected saved lead beyond the search list and conceals it immediately after Site withdrawal', async () => {
    const view = render(panel());
    edit();
    await waitFor(() =>
        expect(
            screen.getByRole('option', { name: 'Synthetic eligible lead' }),
        ).toBeTruthy(),
    );
    expect(
        vi
            .mocked(axios.get)
            .mock.calls.some(
                ([, config]) => config?.params?.selected_id === 51,
            ),
    ).toBe(true);
    view.rerender(
        panel(
            true,
            {
                ...snapshot,
                configuration: { ...snapshot.configuration!, routes: [] },
            },
            [],
        ),
    );
    expect(
        screen.queryByRole('option', { name: 'Synthetic eligible lead' }),
    ).toBeNull();
    expect(screen.queryByText('Synthetic eligible lead')).toBeNull();
    expect(
        screen.getByRole('combobox', { name: 'Incident lead' }),
    ).toHaveProperty('disabled', true);
});

it('hides current nomination names and prevents saving after settings access withdrawal while retaining reason', async () => {
    const view = render(panel());
    edit();
    reason('Keep this local draft');
    await waitFor(() =>
        expect(
            screen.getByRole('option', { name: 'Synthetic eligible lead' }),
        ).toBeTruthy(),
    );
    view.rerender(panel(false));
    expect(
        screen.queryByRole('option', { name: 'Synthetic eligible lead' }),
    ).toBeNull();
    expect(screen.getByRole('button', { name: 'Save routing' })).toHaveProperty(
        'disabled',
        true,
    );
    expect(
        screen.getByLabelText('Reason for this organisation decision'),
    ).toHaveProperty('value', 'Keep this local draft');
    save();
    expect(io.put).not.toHaveBeenCalled();
});

it('rejects candidate names returned for a different authenticated account', async () => {
    vi.mocked(axios.get).mockResolvedValue({
        data: {
            viewer_id: 99,
            candidates: [{ id: 51, name: 'Other account secret name' }],
        },
    });
    render(panel());
    edit();
    await waitFor(() =>
        expect(
            screen.getAllByText(/Eligible people could not be loaded/).length,
        ).toBeGreaterThan(0),
    );
    expect(screen.queryByText('Other account secret name')).toBeNull();
});

it('requires an explicit daylight-saving occurrence and posts the chosen UTC offset', () => {
    render(panel());
    edit();
    reason();
    fireEvent.change(screen.getByLabelText('Rule starts'), {
        target: { value: '2026-04-05T02:30' },
    });
    const occurrence = screen.getByRole('combobox', {
        name: 'Rule starts occurrence',
    });
    expect(occurrence).toHaveProperty('value', '');
    fireEvent.change(occurrence, { target: { value: '+12:00' } });
    save();
    expect(latest()[1].configuration.routes[0].effective_from).toBe(
        '2026-04-05T02:30:00+12:00',
    );
});
