import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { IncidentDetail } from './incident-detail-dialog';

type VisitCallbacks = {
    onSuccess: (page: { props: Record<string, unknown> }) => void;
    onFinish: () => void;
};
type BeforeVisit = {
    detail: { visit: { url: URL; method: string; preserveState: boolean } };
    preventDefault: () => void;
};
const state = vi.hoisted(() => ({
    actorId: 7,
    post: vi.fn(),
    reload: vi.fn(),
    processing: false,
    errors: {} as Record<string, string>,
    callbacks: null as VisitCallbacks | null,
    throws: false,
    before: null as ((event: BeforeVisit) => void) | null,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/incidents/42?section=overview',
        props: { auth: { user: { id: state.actorId } }, flash: {} },
    }),
    router: {
        on: (_name: string, callback: (event: BeforeVisit) => void) => {
            state.before = callback;
            return () => {
                state.before = null;
            };
        },
        reload: state.reload,
    },
    useForm: (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const [defaults, setDefaults] = useState(initial);
        return {
            data,
            processing: state.processing,
            errors: state.errors,
            isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
            setData: (key: string, value: unknown) =>
                setData((previous) => ({ ...previous, [key]: value })),
            post: (url: string, callbacks: VisitCallbacks) => {
                state.post(url, data);
                if (state.throws)
                    throw new Error('Synthetic transport failure');
                state.callbacks = {
                    ...callbacks,
                    onSuccess: (page) => {
                        callbacks.onSuccess(page);
                        // Match installed Inertia: even a rejected application
                        // receipt is a successful redirect to the form helper.
                        setDefaults(data);
                    },
                };
            },
        };
    },
}));

import { IncidentResponseRequestDialog } from './incident-response-request-dialog';

function detail(): IncidentDetail {
    return {
        id: 42,
        ref: 'INC-2026-0042',
        status: 'submitted',
        severity: 'high',
        can: { requestResponse: true },
    } as IncidentDetail;
}
function setup(incident = detail()) {
    const onClose = vi.fn();
    const view = render(
        <IncidentResponseRequestDialog detail={incident} onClose={onClose} />,
    );
    return { ...view, onClose };
}
function enterAndSend() {
    fireEvent.change(
        screen.getByRole('textbox', { name: 'What help is needed?' }),
        { target: { value: 'Please coordinate additional support.' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Request response' }));
}
function returned(overrides: Record<string, unknown> = {}) {
    return {
        viewer_id: 7,
        auth: { user: { id: 7 } },
        detail: { id: 42, control_room_alert_id: 11 },
        flash: { created_alert_id: 11 },
        ...overrides,
    };
}
function complete(props: Record<string, unknown>) {
    act(() => {
        state.callbacks!.onSuccess({ props });
        state.callbacks!.onFinish();
    });
}
beforeEach(() => {
    state.actorId = 7;
    state.processing = false;
    state.errors = {};
    state.callbacks = null;
    state.throws = false;
    state.before = null;
    vi.clearAllMocks();
});
afterEach(cleanup);

describe('Incident response request recovery and confirmation', () => {
    it('closes only after the current incident and account confirm the returned response identity', () => {
        const { onClose } = setup();
        enterAndSend();
        expect(state.post).toHaveBeenCalledWith(
            '/control-room/incidents/create-alert',
            {
                source_type: 'client_incident',
                source_id: 42,
                severity: 'high',
                notes: 'Please coordinate additional support.',
            },
        );
        complete(returned());
        expect(onClose).toHaveBeenCalledExactlyOnceWith();
    });

    it('accepts the saved response receipt without inventing an alert link when the current page withholds that alert', () => {
        const { onClose } = setup();
        enterAndSend();
        complete(returned({ detail: { id: 42, control_room_alert_id: null } }));
        expect(onClose).toHaveBeenCalledExactlyOnceWith();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it.each([
        ['missing receipt', { flash: {} }],
        ['wrong incident', { detail: { id: 43, control_room_alert_id: 11 } }],
        ['wrong viewer', { viewer_id: 8 }],
        ['different account', { auth: { user: { id: 8 } } }],
        [
            'conflicting visible alert',
            { detail: { id: 42, control_room_alert_id: 12 } },
        ],
        ['missing current incident', { detail: null }],
        ['string alert ID', { flash: { created_alert_id: '11' } }],
        ['invalid alert ID', { flash: { created_alert_id: 0 } }],
    ])(
        'keeps entries after an unconfirmed redirect: %s',
        (_name, overrides) => {
            const { onClose } = setup();
            enterAndSend();
            complete(returned(overrides as Record<string, unknown>));
            expect(onClose).not.toHaveBeenCalled();
            expect(screen.getByRole('alert')).toHaveTextContent(
                'The response could not be confirmed.',
            );
            expect(
                screen.getByRole('textbox', { name: 'What help is needed?' }),
            ).toHaveValue('Please coordinate additional support.');
            fireEvent.click(
                screen.getByRole('button', { name: 'Check current incident' }),
            );
            expect(state.reload).toHaveBeenCalledWith({
                only: ['viewer_id', 'detail'],
                preserveState: true,
                preserveScroll: true,
            });
            expect(state.post).toHaveBeenCalledTimes(1);
        },
    );

    it('shows redirected guardrail failures without closing or discarding the request', () => {
        const { onClose } = setup();
        enterAndSend();
        complete(
            returned({
                flash: {
                    created_alert_id: 11,
                    error: 'The source is being changed. Check the current incident.',
                },
            }),
        );
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The source is being changed.',
        );
        expect(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
        ).toHaveValue('Please coordinate additional support.');
    });

    it('does not treat an unrecognised error value as accepted delivery', () => {
        const { onClose } = setup();
        enterAndSend();
        complete(
            returned({
                flash: { created_alert_id: 11, error: { unavailable: true } },
            }),
        );
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The response could not be confirmed.',
        );
    });

    it.each([
        { flash: {} },
        { flash: { error: 'The response is currently unavailable.' } },
    ])(
        'still guards dismissal and navigation after Inertia adopts an unconfirmed redirect as form defaults',
        (overrides) => {
            const { onClose } = setup();
            enterAndSend();
            complete(returned(overrides));
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            fireEvent.click(
                within(screen.getByRole('alertdialog')).getByRole('button', {
                    name: 'Cancel',
                }),
            );
            expect(onClose).not.toHaveBeenCalled();
            const preventDefault = vi.fn();
            act(() =>
                state.before!({
                    detail: {
                        visit: {
                            url: new URL('/incidents', window.location.href),
                            method: 'get',
                            preserveState: true,
                        },
                    },
                    preventDefault,
                }),
            );
            expect(preventDefault).toHaveBeenCalledExactlyOnceWith();
            fireEvent.click(
                within(screen.getByRole('alertdialog')).getByRole('button', {
                    name: 'Cancel',
                }),
            );
            expect(
                screen.getByRole('textbox', { name: 'What help is needed?' }),
            ).toHaveValue('Please coordinate additional support.');
            expect(state.post).toHaveBeenCalledTimes(1);
        },
    );

    it('prevents another request and editing while the first request is in flight', () => {
        setup();
        enterAndSend();
        const sending = screen.getByRole('button', {
            name: 'Requesting response…',
        });
        expect(sending).toBeDisabled();
        expect(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('combobox', { name: 'Response urgency' }),
        ).toBeDisabled();
        fireEvent.click(sending);
        expect(state.post).toHaveBeenCalledTimes(1);
    });

    it('keeps the request readable after a synchronous transport failure and permits an explicit retry', () => {
        setup();
        state.throws = true;
        enterAndSend();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The response request could not be confirmed.',
        );
        expect(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
        ).toHaveValue('Please coordinate additional support.');
        state.throws = false;
        fireEvent.click(
            screen.getByRole('button', { name: 'Request response' }),
        );
        expect(state.post).toHaveBeenCalledTimes(2);
    });

    it('retains an unsent request when current permission is withdrawn and enables it only after restoration', () => {
        const incident = detail();
        const { rerender, onClose } = setup(incident);
        fireEvent.change(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
            { target: { value: 'Keep this unsent coordination request.' } },
        );
        rerender(
            <IncidentResponseRequestDialog
                detail={{
                    ...incident,
                    can: { ...incident.can, requestResponse: false },
                }}
                onClose={onClose}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Request response' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
        ).toHaveValue('Keep this unsent coordination request.');
        expect(state.post).not.toHaveBeenCalled();
        rerender(
            <IncidentResponseRequestDialog
                detail={incident}
                onClose={onClose}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Request response' }),
        );
        expect(state.post).toHaveBeenCalledWith(
            '/control-room/incidents/create-alert',
            expect.objectContaining({
                notes: 'Keep this unsent coordination request.',
            }),
        );
    });

    it.each(['draft', 'closed'])(
        'does not offer a predictable denied command on a %s incident',
        (status) => {
            setup({ ...detail(), status });
            expect(
                screen.getByRole('button', { name: 'Request response' }),
            ).toBeDisabled();
            expect(
                screen.getByRole('button', { name: 'Check current incident' }),
            ).toBeVisible();
            expect(state.post).not.toHaveBeenCalled();
        },
    );

    it('associates urgency and instruction errors with their controls', () => {
        state.errors = {
            severity: 'Choose an allowed urgency.',
            notes: 'Describe the response without exceeding the limit.',
        };
        setup();
        for (const name of ['Response urgency', 'What help is needed?']) {
            const input = screen.getByLabelText(name);
            expect(input).toHaveAttribute('aria-invalid', 'true');
            expect(
                document.getElementById(
                    input.getAttribute('aria-describedby')!,
                ),
            ).toBeVisible();
        }
    });

    it('keeps unsent instructions when cancelling dismissal', () => {
        const { onClose } = setup();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
            { target: { value: 'Keep this unsent request.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(onClose).not.toHaveBeenCalled();
        expect(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
        ).toHaveValue('Keep this unsent request.');
    });

    it('refreshes the same incident without discarding its draft but still guards navigation away', () => {
        setup();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
            { target: { value: 'Keep this unsent request through refresh.' } },
        );
        const preventDefault = vi.fn();
        act(() =>
            state.before!({
                detail: {
                    visit: {
                        url: new URL(
                            '/incidents/42?section=overview',
                            window.location.href,
                        ),
                        method: 'get',
                        preserveState: true,
                    },
                },
                preventDefault,
            }),
        );
        expect(preventDefault).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        act(() =>
            state.before!({
                detail: {
                    visit: {
                        url: new URL('/incidents/43', window.location.href),
                        method: 'get',
                        preserveState: true,
                    },
                },
                preventDefault,
            }),
        );
        expect(preventDefault).toHaveBeenCalledTimes(1);
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(
            screen.getByRole('textbox', { name: 'What help is needed?' }),
        ).toHaveValue('Keep this unsent request through refresh.');
    });
});
