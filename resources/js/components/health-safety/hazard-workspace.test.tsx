import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { HazardDetail } from './hazard-kit';

const state = vi.hoisted(() => ({
    url: '/hazards/8',
    visit: vi.fn(),
    replace: vi.fn(),
    post: vi.fn(),
    outcome: 'validation-error',
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Link: ({
            href,
            children,
            ...props
        }: {
            href: string;
            children: ReactNode;
        }) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
        usePage: () => ({ url: state.url, props: { flash: {} } }),
        router: {
            visit: state.visit,
            replace: state.replace,
            on: () => () => undefined,
            post: vi.fn(),
        },
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = React.useState(initial);
            const defaults = React.useRef(initial);
            return {
                data,
                errors: {},
                processing: false,
                isDirty:
                    JSON.stringify(defaults.current) !== JSON.stringify(data),
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                post: (
                    url: string,
                    options: { onSuccess?: (page: { props: object }) => void },
                ) => {
                    state.post(url, data);
                    if (state.outcome !== 'validation-error')
                        options.onSuccess?.({
                            props: {
                                flash:
                                    state.outcome === 'flash-error'
                                        ? { error: 'Blocked' }
                                        : {},
                            },
                        });
                },
            };
        },
    };
});

import {
    hazardConnectionHref,
    hazardHref,
    hazardLocation,
    hazardReturn,
} from '@/lib/health-safety/hazard-navigation';
import { HazardConnectionsSection } from './hazard-connections';
import { HazardWorkspace } from './hazard-workspace';

function detail(overrides: Partial<HazardDetail> = {}): HazardDetail {
    return {
        id: 8,
        reference_number: 'HZ-SYNTHETIC',
        site: { id: 1, name: 'Kauri House', type: 'house' },
        hazard_type: 'slip_trip_fall',
        hazard_label: 'Slip / trip / fall',
        custom_hazard_type: null,
        severity: 'high',
        likelihood: 'likely',
        risk_rating: 'extreme',
        residual_severity: null,
        residual_likelihood: null,
        residual_risk_rating: null,
        control_hierarchy: [],
        description: 'Synthetic loose carpet',
        location: 'Hallway',
        witnesses: null,
        immediate_action_applied: false,
        immediate_action_taken: null,
        status: 'open',
        reported_by: { id: 2, name: 'Reporter' },
        assigned_to: { id: 3, name: 'Named owner' },
        due_date: '2026-10-08',
        created_at: '2026-10-07T11:30:00Z',
        closed_at: null,
        status_changed_at: null,
        status_changed_by: null,
        worksafe_notifiable: true,
        resolution_summary: null,
        photo_paths: [],
        document_paths: [],
        resolution_evidence: [],
        actions: [],
        assignable_staff: [{ id: 3, name: 'Named owner' }],
        close_gate: { actions_ok: true, blockers: [] },
        history: [],
        can: { manage: true, assign: true, close: true },
        ...overrides,
    };
}
beforeEach(() => {
    vi.clearAllMocks();
    state.url = '/hazards/8';
    state.outcome = 'validation-error';
    state.replace.mockImplementation((options) => options.onSuccess?.());
});
afterEach(cleanup);

it('mounts a command only after its permanent URL is ready for input', () => {
    state.replace.mockImplementationOnce(() => undefined);
    render(<HazardWorkspace detail={detail()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Record a review' }));
    expect(
        screen.queryByRole('textbox', { name: /^Review notes/ }),
    ).not.toBeInTheDocument();
    act(() => state.replace.mock.calls[0][0].onSuccess());
    expect(
        screen.getByRole('textbox', { name: /^Review notes/ }),
    ).toBeInTheDocument();
});

it('retains a proposed assignment when the saved owner changes and requires reviewing the new state', () => {
    state.url = '/hazards/8?action=assign';
    const view = render(<HazardWorkspace detail={detail()} />);
    const due = screen.getByLabelText('Due date');
    fireEvent.change(due, { target: { value: '2026-10-12' } });
    view.rerender(
        <HazardWorkspace
            detail={detail({ assigned_to: { id: 7, name: 'Cover owner' } })}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Save assignment' }),
    ).toBeDisabled();
    expect(due).toHaveValue('2026-10-12');
    fireEvent.click(
        screen.getByRole('button', {
            name: 'I have reviewed the current assignment',
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save assignment' }));
    expect(state.post).toHaveBeenCalledWith(
        '/hazards/8/assign',
        expect.objectContaining({
            assigned_to_user_id: '3',
            expected_assigned_to_user_id: 7,
            expected_due_date: '2026-10-08',
            due_date: '2026-10-12',
        }),
    );
});

it('keeps linked source domains distinct and does not claim hidden records do not exist', () => {
    render(
        <HazardConnectionsSection
            connections={{
                safety_reviews: [
                    {
                        id: 5,
                        reference_number: 'HS-SYNTHETIC',
                        status: 'open',
                        owner: { id: 6, name: 'Review owner' },
                        href: '/health-safety/events/5?return_to=%2Fhazards%2F8',
                    },
                ],
                corrective_actions: [],
                control_room: [],
                sources: [],
                tasks: [],
                tasks_href: null,
            }}
        />,
    );
    expect(screen.getByRole('link', { name: 'HS-SYNTHETIC' })).toHaveAttribute(
        'href',
        '/health-safety/events/5?return_to=%2Fhazards%2F8',
    );
    expect(screen.getByText('Review owner: Review owner')).toBeInTheDocument();
    expect(
        screen.getAllByText('No linked records available here.'),
    ).toHaveLength(4);
    expect(
        screen.queryByRole('link', { name: 'Open All Tasks' }),
    ).not.toBeInTheDocument();
});

it('uses a permanent profile header without a detail dialog, retains the named owner and filtered return', () => {
    state.url = hazardHref(8, {
        returnTo: '/sites/1/hazards?q=carpet&tab=overdue&page=2',
    });
    const { container } = render(<HazardWorkspace detail={detail()} />);
    expect(container.querySelectorAll('.eh-header')).toHaveLength(1);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.getAllByText('Named owner').length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: /back/i })).toHaveAttribute(
        'href',
        '/sites/1/hazards?q=carpet&tab=overdue&page=2',
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'View actions still open' }),
    );
    expect(state.visit).toHaveBeenCalledWith(
        hazardHref(8, {
            section: 'actions',
            returnTo: '/sites/1/hazards?q=carpet&tab=overdue&page=2',
        }),
        expect.anything(),
    );
    expect(screen.getByRole('tabpanel')).toHaveFocus();
});

it('guards a changed command, keeps entries after cancel and both failure types, then closes only on success', async () => {
    render(<HazardWorkspace detail={detail()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Record a review' }));
    const input = screen.getByRole('textbox', { name: /^Review notes/ });
    fireEvent.change(input, { target: { value: 'Keep this control review.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    const confirmation = await screen.findByRole('alertdialog', {
        name: 'Discard unsaved safety work?',
    });
    fireEvent.click(
        within(confirmation).getByRole('button', { name: 'Cancel' }),
    );
    expect(input).toHaveValue('Keep this control review.');
    const submit = screen.getByRole('button', {
        name: 'Record review',
    });
    fireEvent.click(submit);
    expect(state.post).toHaveBeenLastCalledWith('/hazards/8/review', {
        note: 'Keep this control review.',
    });
    expect(input).toBeInTheDocument();
    state.outcome = 'flash-error';
    fireEvent.click(submit);
    expect(input).toBeInTheDocument();
    state.outcome = 'success';
    fireEvent.click(submit);
    expect(
        screen.queryByRole('textbox', { name: /^Review notes/ }),
    ).not.toBeInTheDocument();
    expect(state.replace).toHaveBeenLastCalledWith(
        expect.objectContaining({ url: '/hazards/8' }),
    );
});

it('does not offer forbidden, closed, stale or foreign action commands from a URL', () => {
    state.url = '/hazards/8?action=complete-action&corrective_action=99';
    const view = render(
        <HazardWorkspace
            detail={detail({
                can: { manage: false, assign: false, close: false },
            })}
        />,
    );
    expect(
        screen.getByText(/This action is no longer available/),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Mark complete' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Assign an owner' }),
    ).not.toBeInTheDocument();
    view.rerender(<HazardWorkspace detail={detail({ status: 'closed' })} />);
    expect(
        screen.queryByRole('button', { name: 'Close hazard' }),
    ).not.toBeInTheDocument();
});

it('shows the real closure blocker and refuses to present a false reviewed or closable state', () => {
    state.url = '/hazards/8?action=close';
    render(
        <HazardWorkspace
            detail={detail({
                close_gate: {
                    actions_ok: false,
                    blockers: ['1 corrective action still open.'],
                },
            })}
        />,
    );
    expect(
        within(screen.getByRole('tabpanel')).getByRole('button', {
            name: 'Close hazard',
        }),
    ).toBeDisabled();
    expect(screen.queryByText('Hazard reviewed')).not.toBeInTheDocument();
    expect(screen.queryByText(/You can still close/)).not.toBeInTheDocument();
});

it('keeps staged evidence behind the same leave guard', async () => {
    state.url = '/hazards/8?section=evidence';
    const { container } = render(<HazardWorkspace detail={detail()} />);
    const input = container.querySelector('input[type=file]')!;
    fireEvent.change(input, {
        target: {
            files: [new File(['proof'], 'proof.png', { type: 'image/png' })],
        },
    });
    await act(async () =>
        fireEvent.click(screen.getByRole('tab', { name: 'Hazard' })),
    );
    expect(
        await screen.findByRole('alertdialog', {
            name: 'Discard unsaved safety work?',
        }),
    ).toBeInTheDocument();
    expect(state.visit).not.toHaveBeenCalled();
    expect(screen.getByText('proof.png')).toBeInTheDocument();
});

it('keeps safe worklist context, drops recursive/external return values and validates action identifiers', () => {
    expect(hazardReturn('//outside.example')).toBe('/compliance/hazards');
    expect(hazardReturn('/\\outside.example')).toBe('/compliance/hazards');
    expect(
        hazardReturn(
            '/sites/7/hazards?q=door&tab=due_soon&page=3&hazard=9&return_to=evil',
        ),
    ).toBe('/sites/7/hazards?q=door&tab=due_soon&page=3');
    expect(
        hazardLocation('/hazards/8?action=complete-action&corrective_action=-1')
            .pane,
    ).toBeNull();
    expect(
        hazardLocation('/hazards/8?action=complete-action&corrective_action=9')
            .pane,
    ).toEqual({ kind: 'complete_action', actionId: 9 });
});

it('keeps the hazard worklist round trip while preserving linked record query and fragment', () => {
    const returnTo = hazardHref(8, {
        section: 'connections',
        returnTo: '/tasks?sources=hazard&assigned=me&q=stairs',
    });
    for (const href of [
        '/health-safety/events/7?section=actions#record',
        '/health-safety/corrective-actions/6?action=verify',
        '/control-room/alerts/3?section=tasks',
    ]) {
        const next = new URL(
            hazardConnectionHref(href, returnTo),
            'https://app.example',
        );
        const original = new URL(href, 'https://app.example');
        expect(next.pathname).toBe(original.pathname);
        expect(next.hash).toBe(original.hash);
        original.searchParams.forEach((value, key) =>
            expect(next.searchParams.get(key)).toBe(value),
        );
        expect(next.searchParams.get('return_to')).toBe(returnTo);
    }
    expect(hazardConnectionHref('/sites/1/inspections', returnTo)).toBe(
        '/sites/1/inspections',
    );
    expect(
        new URL(
            hazardConnectionHref('/hazards/8', returnTo),
            'https://app.example',
        ).searchParams.get('return_to'),
    ).toBe('/tasks?sources=hazard&assigned=me&q=stairs');
    expect(
        hazardConnectionHref(
            '/control-room/alerts/3',
            '//outside.example/hazards/8',
        ),
    ).toBe('/control-room/alerts/3');
});
