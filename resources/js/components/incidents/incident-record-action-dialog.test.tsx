import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { IncidentDetail, LifecycleAction } from './incident-detail-dialog';

type Callbacks = {
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
    put: vi.fn(),
    reload: vi.fn(),
    processing: false,
    errors: {} as Record<string, string>,
    callbacks: null as Callbacks | null,
    throws: false,
    before: null as ((event: BeforeVisit) => void) | null,
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/incidents/42',
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
            errors: state.errors,
            processing: state.processing,
            isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
            setData: (key: string, value: unknown) =>
                setData((previous) => ({ ...previous, [key]: value })),
            post: (url: string, callbacks: Callbacks) => {
                state.post(url, data);
                if (state.throws)
                    throw new Error('Synthetic transport failure');
                state.callbacks = {
                    ...callbacks,
                    onSuccess: (page) => {
                        callbacks.onSuccess(page);
                        setDefaults(data);
                    },
                };
            },
            put: (url: string, callbacks: Callbacks) => {
                state.put(url, data);
                if (state.throws)
                    throw new Error('Synthetic transport failure');
                state.callbacks = {
                    ...callbacks,
                    onSuccess: (page) => {
                        callbacks.onSuccess(page);
                        setDefaults(data);
                    },
                };
            },
        };
    },
}));

import { IncidentRecordActionDialog } from './incident-record-action-dialog';

function detail(action: LifecycleAction = 'reopen'): IncidentDetail {
    const status = {
        submit: 'draft',
        review: 'submitted',
        close: 'reviewed',
        reopen: 'closed',
    }[action];
    return {
        id: 42,
        ref: 'INC-2026-0042',
        type: 'fall',
        severity: 'high',
        status,
        description: 'A person fell in the lounge.',
        client: null,
        attachments: [],
        close_gate: { allowed: true, requirements: [] },
        can: { [action]: true },
    } as IncidentDetail;
}
function setup(action: LifecycleAction = 'reopen', incident = detail(action)) {
    const onClose = vi.fn();
    const view = render(
        <IncidentRecordActionDialog
            detail={incident}
            action={action}
            onClose={onClose}
        />,
    );
    return { ...view, onClose };
}
function editDetail(): IncidentDetail {
    return {
        ...detail('submit'),
        severity: 'critical',
        occurred_at: '2026-10-05T02:04:00Z',
        immediate_action_taken: 'Staff supported the person.',
        witnesses: null,
        potential_severity: null,
        potential_consequence: null,
        is_notifiable: null,
        can: { ...detail('submit').can, update: true },
    };
}
function setupEdit(incident = editDetail()) {
    const onClose = vi.fn();
    const view = render(
        <IncidentRecordActionDialog
            detail={incident}
            action="edit"
            onClose={onClose}
        />,
    );
    return { ...view, onClose };
}
function enterReason() {
    fireEvent.change(
        screen.getByRole('textbox', { name: /Reason for reopening/ }),
        { target: { value: 'New evidence needs review.' } },
    );
}
function send() {
    enterReason();
    fireEvent.click(screen.getByRole('button', { name: 'Reopen incident' }));
}
function returned(overrides: Record<string, unknown> = {}) {
    return {
        viewer_id: 7,
        auth: { user: { id: 7 } },
        detail: { id: 42, status: 'reviewed' },
        flash: {},
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

describe('Incident lifecycle command recovery', () => {
    it('requires a nonblank reopening reason and guards even a direct form submission', () => {
        setup();
        const submit = screen.getByRole('button', { name: 'Reopen incident' });
        expect(submit).toBeDisabled();
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: '   ' },
        });
        fireEvent.submit(submit.closest('form')!);
        expect(state.post).not.toHaveBeenCalled();
        enterReason();
        expect(submit).toBeEnabled();
    });

    it.each(['submit', 'review', 'close', 'reopen'] as const)(
        'refuses a stale %s command after the current status changes',
        (action) => {
            const incident = detail(action);
            const { rerender, onClose } = setup(action, incident);
            rerender(
                <IncidentRecordActionDialog
                    detail={{ ...incident, status: 'unknown' }}
                    action={action}
                    onClose={onClose}
                />,
            );
            fireEvent.submit(screen.getByRole('dialog').querySelector('form')!);
            expect(state.post).not.toHaveBeenCalled();
            expect(screen.getByRole('status')).toHaveTextContent(
                'This action is no longer available',
            );
        },
    );

    it('retains the same draft through permission withdrawal and restoration', () => {
        const incident = detail();
        const { rerender, onClose } = setup('reopen', incident);
        enterReason();
        rerender(
            <IncidentRecordActionDialog
                detail={{
                    ...incident,
                    can: { ...incident.can, reopen: false },
                }}
                action="reopen"
                onClose={onClose}
            />,
        );
        expect(screen.getByRole('textbox')).toHaveValue(
            'New evidence needs review.',
        );
        expect(screen.getByRole('textbox')).toBeDisabled();
        fireEvent.submit(screen.getByRole('dialog').querySelector('form')!);
        expect(state.post).not.toHaveBeenCalled();
        rerender(
            <IncidentRecordActionDialog
                detail={incident}
                action="reopen"
                onClose={onClose}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Reopen incident' }),
        );
        expect(state.post).toHaveBeenCalledWith(
            '/incidents/42/reopen',
            expect.objectContaining({
                reopened_reason: 'New evidence needs review.',
            }),
        );
    });

    it('does not send a draft under a replacement incident identity', () => {
        const incident = detail();
        const { rerender, onClose } = setup('reopen', incident);
        enterReason();
        rerender(
            <IncidentRecordActionDialog
                detail={{ ...incident, id: 43 }}
                action="reopen"
                onClose={onClose}
            />,
        );
        fireEvent.submit(screen.getByRole('dialog').querySelector('form')!);
        expect(state.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Reopen incident' }),
        ).toBeDisabled();
    });

    it('keeps closure requirements binding even when a complete outcome is entered', () => {
        const incident = detail('close');
        incident.close_gate = { allowed: false, requirements: [] };
        setup('close', incident);
        fireEvent.change(screen.getByRole('textbox', { name: /Outcome/ }), {
            target: { value: 'Care plan updated.' },
        });
        fireEvent.submit(screen.getByRole('dialog').querySelector('form')!);
        expect(state.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Close incident' }),
        ).toBeDisabled();
    });

    it('disables editing, dismissal and repeated submission while saving', () => {
        const { onClose } = setup();
        send();
        expect(screen.getByRole('textbox')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        fireEvent.submit(screen.getByRole('dialog').querySelector('form')!);
        expect(state.post).toHaveBeenCalledTimes(1);
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    });

    it('closes after the current incident and account confirm the resulting status', () => {
        const { onClose } = setup();
        send();
        complete(returned());
        expect(onClose).toHaveBeenCalledExactlyOnceWith();
    });

    it.each([
        { detail: { id: 42, status: 'closed' } },
        { detail: { id: 43, status: 'reviewed' } },
        { detail: null },
        { viewer_id: 8 },
        { auth: { user: { id: 8 } } },
        { flash: { error: 'Linked safety work must be checked first.' } },
    ])(
        'retains and guards rejected redirects after the form helper adopts posted defaults',
        (overrides) => {
            const { onClose } = setup();
            send();
            complete(returned(overrides));
            expect(onClose).not.toHaveBeenCalled();
            expect(screen.getByRole('alert')).toBeVisible();
            fireEvent.click(
                screen.getByRole('button', { name: 'Check current incident' }),
            );
            expect(state.reload).toHaveBeenCalledWith({
                only: ['viewer_id', 'detail'],
                preserveState: true,
                preserveScroll: true,
            });
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            fireEvent.click(
                within(screen.getByRole('alertdialog')).getByRole('button', {
                    name: 'Cancel',
                }),
            );
            expect(screen.getByRole('textbox')).toHaveValue(
                'New evidence needs review.',
            );
            expect(onClose).not.toHaveBeenCalled();
        },
    );

    it('retains entries after an unknown transport result and allows an explicit retry', () => {
        setup();
        state.throws = true;
        send();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(screen.getByRole('textbox')).toHaveValue(
            'New evidence needs review.',
        );
        state.throws = false;
        fireEvent.click(
            screen.getByRole('button', { name: 'Reopen incident' }),
        );
        expect(state.post).toHaveBeenCalledTimes(2);
    });

    it.each<{
        action: LifecycleAction;
        errors: Record<string, string>;
        name: RegExp;
    }>([
        {
            action: 'review' as const,
            errors: { review_notes: 'Check the review notes.' },
            name: /Review notes/,
        },
        {
            action: 'close' as const,
            errors: {
                closed_outcome: 'Enter an outcome.',
                closed_notes: 'Check closing notes.',
            },
            name: /Closing notes/,
        },
        {
            action: 'reopen' as const,
            errors: { reopened_reason: 'Enter a reason.' },
            name: /Reason for reopening/,
        },
    ])(
        'connects validation messages to their controls for $action',
        ({ action, errors, name }) => {
            state.errors = errors;
            setup(action);
            const control = screen.getByRole('textbox', { name });
            expect(control).toHaveAttribute('aria-invalid', 'true');
            expect(
                document.getElementById(
                    control.getAttribute('aria-describedby')!,
                ),
            ).toBeVisible();
        },
    );

    it('preserves draft work through a current-page refresh while guarding departure', () => {
        setup();
        enterReason();
        const preventDefault = vi.fn();
        act(() =>
            state.before!({
                detail: {
                    visit: {
                        url: new URL(
                            '/incidents/42?section=history',
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
        expect(screen.getByRole('textbox')).toHaveValue(
            'New evidence needs review.',
        );
    });
});

describe('Incident draft editing and occurrence time', () => {
    it.each([
        ['behavioural', 'Behavioural (saved type)'],
        ['medication_error', 'Medication Error (saved type)'],
    ])(
        'keeps the saved %s type visible and sends it unchanged',
        (type, label) => {
            setupEdit({ ...editDetail(), type });
            expect(
                screen.getByRole('combobox', { name: 'Select type' }),
            ).toHaveTextContent(label);
            fireEvent.change(
                screen.getByRole('textbox', { name: 'What happened' }),
                {
                    target: {
                        value: 'Clarify the original incident without reclassification.',
                    },
                },
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Save changes' }),
            );
            expect(state.put).toHaveBeenCalledWith(
                '/incidents/42',
                expect.objectContaining({ type }),
            );
        },
    );

    it('lets the worker choose another type and return to the original saved type', () => {
        setupEdit({ ...editDetail(), type: 'behavioural' });
        fireEvent.click(screen.getByRole('combobox', { name: 'Select type' }));
        fireEvent.click(
            screen.getByRole('option', { name: 'Fall', exact: true }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Select type' }),
        ).toHaveTextContent('Fall');
        fireEvent.click(screen.getByRole('combobox', { name: 'Select type' }));
        fireEvent.click(
            screen.getByRole('option', { name: 'Behavioural (saved type)' }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Select type' }),
        ).toHaveTextContent('Behavioural (saved type)');
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(state.put).toHaveBeenCalledWith(
            '/incidents/42',
            expect.objectContaining({ type: 'behavioural' }),
        );
    });

    it('sends an explicitly selected replacement for a saved type', () => {
        setupEdit({ ...editDetail(), type: 'behavioural' });
        fireEvent.click(screen.getByRole('combobox', { name: 'Select type' }));
        fireEvent.click(
            screen.getByRole('option', { name: 'Behaviour', exact: true }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(state.put).toHaveBeenCalledWith(
            '/incidents/42',
            expect.objectContaining({ type: 'behaviour' }),
        );
    });

    it('retains the original saved type through a same-record refresh and a temporary replacement', () => {
        const incident = { ...editDetail(), type: 'behavioural' };
        const { rerender, onClose } = setupEdit(incident);
        fireEvent.click(screen.getByRole('combobox', { name: 'Select type' }));
        fireEvent.click(
            screen.getByRole('option', { name: 'Fall', exact: true }),
        );
        rerender(
            <IncidentRecordActionDialog
                detail={{ ...incident, type: 'medication_error' }}
                action="edit"
                onClose={onClose}
            />,
        );
        expect(
            screen.getByRole('combobox', { name: 'Select type' }),
        ).toHaveTextContent('Fall');
        fireEvent.click(screen.getByRole('combobox', { name: 'Select type' }));
        expect(
            screen.queryByRole('option', {
                name: 'Medication Error (saved type)',
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('option', { name: 'Behavioural (saved type)' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(state.put).toHaveBeenCalledWith(
            '/incidents/42',
            expect.objectContaining({ type: 'behavioural' }),
        );
    });

    it.each(['incident', 'account'])(
        'refuses a saved-type edit after the %s identity changes',
        (changedIdentity) => {
            const incident = { ...editDetail(), type: 'behavioural' };
            const { rerender, onClose } = setupEdit(incident);
            fireEvent.change(
                screen.getByRole('textbox', { name: 'What happened' }),
                {
                    target: {
                        value: 'Keep this draft with its original record and account.',
                    },
                },
            );
            if (changedIdentity === 'account') state.actorId = 9;
            rerender(
                <IncidentRecordActionDialog
                    detail={{
                        ...incident,
                        id: changedIdentity === 'incident' ? 43 : 42,
                    }}
                    action="edit"
                    onClose={onClose}
                />,
            );
            expect(
                screen.getByRole('button', { name: 'Save changes' }),
            ).toBeDisabled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Save changes' }),
            );
            expect(state.put).not.toHaveBeenCalled();
            expect(onClose).not.toHaveBeenCalled();
        },
    );

    it('associates narrative, immediate-action and witness errors with the edited controls', () => {
        state.errors = {
            description: 'Check what happened.',
            immediate_action_taken: 'Check the immediate action.',
            witnesses: 'Check witnesses.',
        };
        setupEdit();
        for (const name of [
            'What happened',
            'Immediate action taken',
            'Witnesses',
        ]) {
            const input = screen.getByRole('textbox', { name });
            expect(input).toHaveAttribute('aria-invalid', 'true');
            expect(
                document.getElementById(
                    input.getAttribute('aria-describedby')!,
                ),
            ).toBeVisible();
        }
    });
    it('keeps an existing Critical severity and sends the same New Zealand occurrence wall time', () => {
        const { onClose } = setupEdit();
        expect(
            screen.getByRole('combobox', { name: 'Select severity' }),
        ).toHaveTextContent('Critical');
        expect(
            screen.getByRole('button', {
                name: 'When it occurred date: 5 Oct 2026',
            }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', {
                name: 'When it occurred time: 03:04 PM',
            }),
        ).toBeEnabled();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'What happened' }),
            { target: { value: 'Clarified synthetic draft.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(state.put).toHaveBeenCalledWith(
            '/incidents/42',
            expect.objectContaining({
                severity: 'critical',
                occurred_at: '2026-10-05T15:04',
                description: 'Clarified synthetic draft.',
            }),
        );
        expect(
            JSON.parse(JSON.stringify(state.put.mock.calls[0][1])),
        ).not.toHaveProperty('is_notifiable');
        complete(returned({ detail: { id: 42, status: 'draft' } }));
        expect(onClose).toHaveBeenCalledExactlyOnceWith();
    });

    it('does not discard edited draft entries when a rejection becomes the form helper default', () => {
        const { onClose } = setupEdit();
        fireEvent.change(
            screen.getByRole('textbox', { name: 'What happened' }),
            { target: { value: 'Keep this edited draft.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        complete(
            returned({
                flash: { error: 'The current incident must be checked first.' },
                detail: { id: 42, status: 'draft' },
            }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'must be checked first',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(
            screen.getByRole('textbox', { name: 'What happened' }),
        ).toHaveValue('Keep this edited draft.');
        expect(onClose).not.toHaveBeenCalled();
    });

    it.each(['submitted', 'reviewed', 'closed'])(
        'keeps edits but refuses sending after the incident becomes %s',
        (status) => {
            const incident = editDetail();
            const { rerender, onClose } = setupEdit(incident);
            fireEvent.change(
                screen.getByRole('textbox', { name: 'What happened' }),
                { target: { value: 'Keep the unsubmitted revision.' } },
            );
            rerender(
                <IncidentRecordActionDialog
                    detail={{ ...incident, status }}
                    action="edit"
                    onClose={onClose}
                />,
            );
            expect(
                screen.getByRole('textbox', { name: 'What happened' }),
            ).toHaveValue('Keep the unsubmitted revision.');
            expect(
                screen.getByRole('textbox', { name: 'What happened' }),
            ).toBeDisabled();
            fireEvent.submit(
                screen
                    .getByRole('button', { name: 'Save changes' })
                    .closest('form')!,
            );
            expect(state.put).not.toHaveBeenCalled();
        },
    );

    it('closes an open clock picker when edit permission is withdrawn and keeps the applied occurrence unchanged', async () => {
        const incident = editDetail();
        const { rerender, onClose } = setupEdit(incident);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it occurred time: 03:04 PM',
            }),
        );
        expect(
            await screen.findByRole('button', { name: 'Use time' }),
        ).toBeVisible();
        rerender(
            <IncidentRecordActionDialog
                detail={{
                    ...incident,
                    can: { ...incident.can, update: false },
                }}
                action="edit"
                onClose={onClose}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Use time' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'When it occurred time: 03:04 PM',
            }),
        ).toBeDisabled();
        expect(state.put).not.toHaveBeenCalled();
    });

    it('keeps exact minutes on Cancel and applies them explicitly without submitting the incident', async () => {
        setupEdit();
        const openClock = () =>
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'When it occurred time: 03:04 PM',
                }),
            );
        openClock();
        const useTime = await screen.findByRole('button', { name: 'Use time' });
        const clock = useTime.closest('[role="dialog"]')!;
        fireEvent.change(
            screen.getByLabelText('When it occurred time minute'),
            { target: { value: '17' } },
        );
        fireEvent.click(
            within(clock as HTMLElement).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(
            screen.getByRole('button', {
                name: 'When it occurred time: 03:04 PM',
            }),
        ).toBeEnabled();
        openClock();
        fireEvent.change(
            screen.getByLabelText('When it occurred time minute'),
            { target: { value: '17' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Use time' }));
        expect(state.put).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(state.put).toHaveBeenCalledWith(
            '/incidents/42',
            expect.objectContaining({ occurred_at: '2026-10-05T15:17' }),
        );
    });

    it('keeps an incomplete occurrence draft local and allows explicit unknown date and time', async () => {
        setupEdit();
        // Let the modal finish its initial focus before opening the clock's portalled focus scope.
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it occurred: Clear date and time',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it occurred time: Choose time',
            }),
        );
        await screen.findByRole('button', { name: 'Use time' });
        fireEvent.change(screen.getByLabelText('When it occurred time hour'), {
            target: { value: '1' },
        });
        fireEvent.change(
            screen.getByLabelText('When it occurred time minute'),
            { target: { value: '17' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'PM' }));
        fireEvent.click(screen.getByRole('button', { name: 'Use time' }));
        expect(
            screen.getByRole('button', { name: 'Save changes' }),
        ).toBeDisabled();
        fireEvent.submit(
            screen
                .getByRole('button', { name: 'Save changes' })
                .closest('form')!,
        );
        expect(state.put).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'When it occurred: Clear date and time',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(state.put).toHaveBeenCalledWith(
            '/incidents/42',
            expect.objectContaining({ occurred_at: '' }),
        );
    });

    it('prevents editing and a second PUT while a draft update is pending', () => {
        setupEdit();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(
            screen.getByRole('textbox', { name: 'What happened' }),
        ).toBeDisabled();
        fireEvent.submit(
            screen.getByRole('button', { name: 'Saving…' }).closest('form')!,
        );
        expect(state.put).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });
});
