import type { IncidentDetail } from '@/components/incidents/incident-detail-dialog';
import type { RecordingReferenceEvidenceData } from '@/components/incidents/recording-reference-evidence';
import type { RecordingPreview } from '@/components/incidents/recording-window-command';
import {
    cleanup,
    act,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({
    url: '/incidents/42',
    actorId: 7,
    post: vi.fn(),
    get: vi.fn(),
    processing: false,
    nextErrors: null as Record<string, string> | null,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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
        url: state.url,
        props: { flash: {}, auth: { user: { id: state.actorId } } },
    }),
    router: { post: state.post, get: state.get, on: () => () => {} },
    useForm: function useForm(initial: Record<string, unknown>) {
        const [data, setData] = useState(initial);
        const [errors, setErrors] = useState<Record<string, string>>({});
        return {
            data,
            errors,
            processing: state.processing,
            isDirty: JSON.stringify(initial) !== JSON.stringify(data),
            setData: (key: string, value: unknown) =>
                setData((previous) => ({ ...previous, [key]: value })),
            post: (url: string) => {
                state.post(url, data);
                if (state.nextErrors) setErrors(state.nextErrors);
            },
            put: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            setError: (key: string, message: string) =>
                setErrors((previous) => ({ ...previous, [key]: message })),
        };
    },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));

import CurrentIncidentShow from './show';

const windowPreview = (): RecordingPreview => ({
    schema_version: 1, actor_id: 7, incident_id: 42, incident_evidence_version: 0, active_window_revision: null,
    policy: { state: 'configured', revision: 2, rule: {
        identifier: 'incident-camera', before_seconds: 60, after_seconds: 120, max_before_seconds: 300, max_after_seconds: 600,
        default_start_at: '2026-10-07T01:29:00Z', default_end_at: '2026-10-07T01:32:00Z',
        earliest_start_at: '2026-10-07T01:25:00Z', latest_end_at: '2026-10-07T01:40:00Z',
    } },
    permissions: { can_record: true, can_expand: false, can_attach: false },
    sources: { items: [], complete: true, limit: 64 },
});

describe('inline incident evidence window integration', () => {
    it('keeps the workflow on the evidence page and protects edited details when adopting a new policy', () => {
        state.url = '/incidents/42?section=evidence';
        const original = windowPreview();
        const view = render(<IncidentShow detail={{ ...detail(), recording_preview: original }} />);
        const form = screen.getByRole('form', { name: 'Record evidence window' });
        expect(form).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.change(within(form).getByLabelText('Why this time and these cameras'), {
            target: { value: 'Review the original incident period.' },
        });
        const updated = windowPreview(); updated.policy.revision = 3;
        view.rerender(<IncidentShow detail={{ ...detail(), recording_preview: updated }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Review current choices' }));
        const confirmation = screen.getByRole('alertdialog');
        expect(within(confirmation).getByText('Discard unsaved incident work?')).toBeVisible();
        fireEvent.click(within(confirmation).getByRole('button', { name: 'Cancel' }));
        expect(screen.getByDisplayValue('Review the original incident period.')).toBeInTheDocument();
    });
});

function IncidentShow(props: {
    detail: (IncidentDetail & { recording_evidence?: RecordingReferenceEvidenceData; recording_preview?: RecordingPreview }) | null;
    viewer_id?: number;
}) {
    return <CurrentIncidentShow {...props} viewer_id={props.viewer_id ?? 7} />;
}

function detail(): IncidentDetail {
    return {
        id: 42,
        ref: 'INC-2026-0042',
        type: 'fall',
        source: 'manual',
        interactive: true,
        status: 'reviewed',
        severity: 'high',
        occurred_at: '2026-10-07T01:30:00Z',
        description: 'A person fell in the lounge.',
        immediate_action_taken: 'Staff stayed with the person.',
        witnesses: null,
        is_notifiable: false,
        worksafe_notification_status: null,
        worksafe_notified_at: null,
        worksafe_reference: null,
        potential_severity: null,
        potential_consequence: null,
        investigation_status: null,
        submitted_at: null,
        reviewed_at: null,
        review_notes: null,
        closed_at: null,
        closed_outcome: null,
        closed_notes: null,
        reopened_at: null,
        reopened_reason: null,
        control_room_alert_id: null,
        client: null,
        reporter: { name: 'Test Reporter', email: 'reporter@example.test' },
        investigator: null,
        attachments: [],
        followups: [],
        hs_event: null,
        linked_operational_evidence: null,
        control_room_alert: null,
        close_gate: {
            allowed: false,
            requirements: [
                {
                    key: 'followups',
                    complete: false,
                    label: 'Complete incident follow-ups',
                    href: '/incidents/42?section=followups',
                },
            ],
        },
        journey_state: 'H&S governance active',
        assignable_staff: [],
        corrective_action_owners: [],
        can: {
            update: false,
            submit: false,
            review: false,
            close: true,
            reopen: false,
            followupsManage: false,
            followupsComplete: false,
            portalManage: false,
            raiseCorrectiveAction: false,
        },
    };
}

afterEach(cleanup);
beforeEach(() => {
    state.url = '/incidents/42';
    state.actorId = 7;
    state.processing = false;
    state.nextErrors = null;
    vi.clearAllMocks();
});

describe('Incident evidence page integration', () => {
    function evidence(): RecordingReferenceEvidenceData {
        return {
            schema_version: 1, incident_id: 42, incident_evidence_version: 23, window: null,
            references: { items: [], total: 21, page: 2, per_page: 20, has_more: false, previous_page: 1, next_page: null },
        };
    }

    it('loads the exact history page, keeps the register return, and guards pending visits', () => {
        state.url = '/incidents/42?section=evidence&return_to=%2Fincidents%3Fseverity%3Dhigh';
        render(<IncidentShow detail={{ ...detail(), recording_evidence: evidence() }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Previous page' }));
        expect(state.get).toHaveBeenCalledTimes(1);
        expect(state.get).toHaveBeenCalledWith('/incidents/42', {
            section: 'evidence', recording_page: 1, return_to: '/incidents?severity=high',
        }, expect.objectContaining({
            preserveState: true, preserveScroll: true, only: ['detail', 'viewer_id'],
        }));
        expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Previous page' }));
        expect(state.get).toHaveBeenCalledTimes(1);
        act(() => state.get.mock.calls[0][2].onFinish());
        expect(screen.getByRole('button', { name: 'Previous page' })).toBeEnabled();
    });

    it('retains unsent follow-up text through an evidence page refresh', () => {
        state.url = '/incidents/42?section=followups';
        const incident = { ...detail(), recording_evidence: evidence() };
        incident.can.followupsManage = true;
        const view = render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.change(screen.getByRole('textbox', { name: /Task/ }), {
            target: { value: 'Check the original incident follow-up.' },
        });
        state.url = '/incidents/42?section=evidence';
        view.rerender(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Previous page' }));
        act(() => state.get.mock.calls[0][2].onFinish());
        state.url = '/incidents/42?section=followups&recording_page=1';
        view.rerender(<IncidentShow detail={{ ...incident, recording_evidence: {
            ...evidence(), references: { ...evidence().references, page: 1, previous_page: null, next_page: 2, has_more: true },
        } }} />);
        expect(screen.getByRole('textbox', { name: /Task/ })).toHaveValue('Check the original incident follow-up.');
        expect(state.post).not.toHaveBeenCalled();
    });

    it('never presents missing projection data as an empty evidence history', () => {
        state.url = '/incidents/42?section=evidence';
        render(<IncidentShow detail={detail()} />);
        expect(screen.getByText(/Recording reference information is unavailable/)).toBeVisible();
        expect(screen.queryByText(/No recording references have been linked/)).toBeNull();
    });

    it('withholds recording history when the current viewer changes', () => {
        state.url = '/incidents/42?section=evidence';
        const incident = { ...detail(), recording_evidence: evidence() };
        const view = render(<IncidentShow detail={incident} />);
        expect(screen.getByRole('navigation', { name: 'Recording reference pages' })).toBeVisible();
        state.actorId = 8;
        view.rerender(<IncidentShow detail={incident} />);
        expect(screen.queryByRole('navigation', { name: 'Recording reference pages' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Reload incident' })).toBeVisible();
    });
});

describe('Permanent incident workspace', () => {
    it.each([
        ['draft', 'submit', 'Submit for review'],
        ['submitted', 'review', 'Review incident'],
        ['reviewed', 'close', 'Close incident'],
        ['closed', 'reopen', 'Reopen incident'],
    ] as const)(
        'places the permitted %s transition in the header once',
        (status, permission, label) => {
            const incident = detail();
            incident.status = status;
            incident.can[permission] = true;
            incident.close_gate.allowed = true;
            render(<IncidentShow detail={incident} />);
            const action = within(screen.getByRole('banner')).getByRole(
                'button',
                { name: label },
            );
            expect(
                screen.getAllByRole('button', { name: label }),
            ).toHaveLength(1);
            expect(action).toBeEnabled();
            fireEvent.click(action);
            expect(screen.getByRole('dialog')).toBeVisible();
            expect(state.post).not.toHaveBeenCalled();
        },
    );

    it('keeps draft editing available beside submission and promotes it when submission is unavailable', () => {
        const incident = detail();
        incident.status = 'draft';
        incident.can.update = true;
        incident.can.submit = true;
        const view = render(<IncidentShow detail={incident} />);
        const header = screen.getByRole('banner');
        expect(
            within(header).getByRole('button', { name: 'Edit draft' }),
        ).toBeVisible();
        expect(
            within(header).getByRole('button', { name: 'Submit for review' }),
        ).toBeVisible();
        incident.can.submit = false;
        view.rerender(<IncidentShow detail={incident} />);
        expect(
            screen.queryByRole('button', { name: 'Submit for review' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            within(header).getByRole('button', { name: 'Edit draft' }),
        );
        expect(screen.getByRole('dialog')).toBeVisible();
        expect(state.post).not.toHaveBeenCalled();
    });

    it('offers response request in the header only when permitted and no incident transition is available', () => {
        const incident = detail();
        incident.can.close = false;
        incident.can.requestResponse = true;
        const view = render(<IncidentShow detail={incident} />);
        const label = 'Request Control Room response';
        expect(screen.getAllByRole('button', { name: label })).toHaveLength(1);
        expect(
            within(screen.getByRole('banner')).getByRole('button', {
                name: label,
            }),
        ).toBeVisible();
        incident.can.requestResponse = false;
        view.rerender(<IncidentShow detail={incident} />);
        expect(
            screen.queryByRole('button', { name: label }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Close incident' }),
        ).not.toBeInTheDocument();
    });

    it('opens a deep section on its rail tab with a labelled panel and keeps the return context', () => {
        state.url =
            '/incidents/42?section=history&return_to=%2Fincidents%3Fowner%3Dme';
        render(<IncidentShow detail={detail()} />);
        const sections = screen.getByRole('tablist', {
            name: 'Incident sections',
        });
        expect(within(sections).getAllByRole('tab')).toHaveLength(6);
        expect(
            within(sections).getByRole('tab', { name: 'History' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tabpanel', { name: 'History' })).toBeVisible();
        fireEvent.click(within(sections).getByRole('tab', { name: 'Evidence' }));
        expect(state.get).toHaveBeenCalledWith(
            '/incidents/42',
            { section: 'evidence', return_to: '/incidents?owner=me' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('reads the older linked-records deep link as the overview', () => {
        state.url = '/incidents/42?section=linked';
        render(<IncidentShow detail={detail()} />);
        expect(
            within(
                screen.getByRole('tablist', { name: 'Incident sections' }),
            ).getByRole('tab', { name: 'Overview' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.getByRole('region', { name: 'Incident summary' }),
        ).toBeVisible();
    });

    it('keeps an unsent follow-up while moving between tabs', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        const view = render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        const input = screen.getByPlaceholderText(
            'e.g. Update the care plan and notify the GP',
        );
        fireEvent.change(input, {
            target: { value: 'Discuss the agreed plan at handover.' },
        });
        fireEvent.click(
            within(
                screen.getByRole('tablist', { name: 'Incident sections' }),
            ).getByRole('tab', { name: 'Safety review' }),
        );
        expect(state.get).toHaveBeenLastCalledWith(
            '/incidents/42',
            { section: 'investigation' },
            { preserveState: true, preserveScroll: true },
        );
        state.url = '/incidents/42?section=investigation';
        view.rerender(<IncidentShow detail={incident} />);
        expect(input).not.toBeVisible();
        state.url = '/incidents/42?section=followups';
        view.rerender(<IncidentShow detail={incident} />);
        expect(input).toHaveValue('Discuss the agreed plan at handover.');
        expect(input).toBeVisible();
    });

    it('finds all sections without intercepting typing or opening a second decision dialog', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        incident.can.requestResponse = true;
        render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.keyDown(
            screen.getByPlaceholderText(
                'e.g. Update the care plan and notify the GP',
            ),
            { key: '/' },
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        fireEvent.keyDown(window, { key: '/' });
        const find = screen.getByRole('dialog', { name: 'Jump to a section' });
        for (const name of [
            'Overview, Incident',
            'Follow-ups, Incident',
            'Safety review, Incident',
            'Evidence, Incident',
            'History, Incident',
        ]) {
            expect(within(find).getByRole('button', { name })).toBeVisible();
        }
        fireEvent.change(
            within(find).getByRole('textbox', {
                name: 'Find an incident section',
            }),
            { target: { value: 'Safety review' } },
        );
        expect(
            within(find).queryByRole('button', {
                name: 'History, Incident',
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            within(find).getByRole('button', {
                name: 'Safety review, Incident',
            }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(state.get).toHaveBeenLastCalledWith(
            '/incidents/42',
            { section: 'investigation' },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Request Control Room response',
            }),
        );
        fireEvent.keyDown(window, { key: '/' });
        expect(screen.getAllByRole('dialog')).toHaveLength(1);
        expect(
            screen.queryByRole('dialog', { name: 'Jump to a section' }),
        ).not.toBeInTheDocument();
    });

    it('conceals stale information and actions when the incident viewer does not match the current account', () => {
        state.url = '/incidents/42?section=evidence';
        render(<IncidentShow detail={detail()} viewer_id={9} />);
        expect(
            screen.queryByText('A person fell in the lounge.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Close incident' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('tab', { name: 'Evidence' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload incident' }),
        );
        expect(state.get).toHaveBeenCalledWith(
            '/incidents/42?section=evidence',
            {},
            { preserveState: false },
        );
    });

    it('hides prior account drafts when a freshly admitted page arrives for a different account', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        const view = render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.change(
            screen.getByPlaceholderText(
                'e.g. Update the care plan and notify the GP',
            ),
            { target: { value: 'Prior account private unsent instruction' } },
        );
        state.actorId = 9;
        view.rerender(<IncidentShow detail={incident} viewer_id={9} />);
        expect(
            screen.queryByDisplayValue(
                'Prior account private unsent instruction',
            ),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        expect(
            screen.getByPlaceholderText(
                'e.g. Update the care plan and notify the GP',
            ),
        ).toHaveValue('');
    });

    it('shows the record facts and one stage line with the next step in the header', () => {
        const incident = detail();
        incident.followups = [
            {
                id: 3,
                notes: 'Overdue synthetic follow-up',
                assigned_to: null,
                due_at: '2026-10-08T01:00:00Z',
                completed_at: null,
                created_by: null,
                overdue: true,
            },
        ];
        render(<IncidentShow detail={incident} />);
        const header = screen.getByRole('banner');
        expect(within(header).getByText('High harm')).toBeVisible();
        expect(within(header).getByText('Overdue follow-up')).toBeVisible();
        const stages = within(header).getByRole('list', {
            name: /Where the incident is up to/,
        });
        expect(within(stages).getAllByRole('listitem')).toHaveLength(6);
        expect(within(header).getByText('Next step')).toBeVisible();
        expect(
            within(header).getByRole('tab', { name: /Follow-ups/ }),
        ).toHaveTextContent('1');
    });

    it('keeps restricted or unavailable safety work unknown instead of claiming none', () => {
        const incident = detail();
        incident.linked_access = {
            control_room: 'absent',
            health_safety: 'restricted',
        };
        const view = render(<IncidentShow detail={incident} />);
        const stages = () =>
            within(screen.getByRole('banner')).getByRole('list', {
                name: /Where the incident is up to/,
            });
        expect(within(stages()).getByText('Access restricted')).toBeVisible();
        incident.linked_access.health_safety = 'absent';
        view.rerender(<IncidentShow detail={incident} />);
        expect(
            within(stages()).getByText('No safety review linked'),
        ).toBeVisible();
        expect(
            within(stages()).queryByText('Access restricted'),
        ).not.toBeInTheDocument();
    });

    it('does not call an unknown safety-review connection absent in its detail section', () => {
        state.url = '/incidents/42?section=investigation';
        render(<IncidentShow detail={detail()} />);
        expect(
            screen.getByText(
                'The safety review is unavailable. Reload the incident to check its current connection.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByText(
                'No Health & Safety event recorded for this incident.',
            ),
        ).not.toBeInTheDocument();
    });

    it('counts completed safety actions as outstanding until their source records verification', () => {
        const incident = detail();
        incident.hs_event = {
            id: 5,
            reference_number: 'HSE-0005',
            status: 'corrective_action',
            url: '/health-safety/events/5',
            corrective_actions_url: '/health-safety/corrective-actions?event=5',
            worksafe_notifiable: false,
            worksafe_status: null,
            worksafe_reference: null,
            worksafe_notified_at: null,
            worksafe_acknowledged_at: null,
            handover: {
                status: 'accepted',
                owner: null,
                accepted_by: null,
                accepted_at: null,
                notes: null,
                can_accept: false,
            },
            investigation_required: false,
            investigation: null,
            corrective_actions: ['open', 'completed', 'verified', 'closed'].map(
                (status, index) => ({
                    id: index + 1,
                    reference_number: `CA-${index + 1}`,
                    title: 'Synthetic action',
                    status,
                    priority: 'normal',
                    assigned_to: null,
                    due_date: null,
                }),
            ),
        };
        render(<IncidentShow detail={incident} />);
        const stages = within(screen.getByRole('banner')).getByRole('list', {
            name: /Where the incident is up to/,
        });
        expect(within(stages).getByText('2 corrective actions')).toBeVisible();
    });

    it('keeps an unsaved follow-up through hiding and section navigation', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        const view = render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.change(
            screen.getByPlaceholderText(
                'e.g. Update the care plan and notify the GP',
            ),
            {
                target: {
                    value: 'Check the agreed support plan with the team.',
                },
            },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Hide draft' }));
        state.url = '/incidents/42?section=overview';
        view.rerender(<IncidentShow detail={incident} />);
        state.url = '/incidents/42?section=followups';
        view.rerender(<IncidentShow detail={incident} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue follow-up' }),
        );
        expect(
            screen.getByPlaceholderText(
                'e.g. Update the care plan and notify the GP',
            ),
        ).toHaveValue('Check the agreed support plan with the team.');
        const unloading = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unloading);
        expect(unloading.defaultPrevented).toBe(true);
    });
    it('explains why a closed incident cannot receive a follow-up and sends no request', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.status = 'closed';
        incident.can.followupsManage = true;
        render(<IncidentShow detail={incident} />);
        const add = screen.getByRole('button', { name: 'Add follow-up' });
        expect(add).toBeDisabled();
        expect(
            screen.getByText('Reopen the incident before adding a follow-up.'),
        ).toBeVisible();
        fireEvent.click(add);
        expect(state.post).not.toHaveBeenCalled();
    });

    it('retains a follow-up draft when the incident closes and resumes it after reopening', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        const view = render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.change(screen.getByRole('textbox', { name: /Task/ }), {
            target: { value: 'Arrange a team debrief.' },
        });
        view.rerender(
            <IncidentShow detail={{ ...incident, status: 'closed' }} />,
        );
        expect(screen.getByRole('textbox', { name: /Task/ })).toHaveValue(
            'Arrange a team debrief.',
        );
        expect(screen.getByRole('textbox', { name: /Task/ })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Add follow-up' }),
        ).toBeDisabled();
        view.rerender(<IncidentShow detail={incident} />);
        expect(screen.getByRole('textbox', { name: /Task/ })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        expect(state.post).toHaveBeenCalledWith(
            '/incidents/42/followups',
            expect.objectContaining({ notes: 'Arrange a team debrief.' }),
        );
    });

    it('shows source, assignment and due-date errors while keeping the unsent task', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        state.nextErrors = {
            incident: 'This incident changed. Reload before saving.',
            assigned_to_user_id: 'Choose an available staff member.',
            due_at: 'Choose a valid due date.',
        };
        render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.change(screen.getByRole('textbox', { name: /Task/ }), {
            target: { value: 'Arrange an agreed review.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This incident changed. Reload before saving.',
        );
        const assignment = screen.getByRole('group', { name: 'Assign to' });
        expect(assignment).toHaveAttribute('aria-invalid', 'true');
        expect(
            document.getElementById(
                assignment.getAttribute('aria-describedby')!,
            ),
        ).toHaveTextContent('Choose an available staff member.');
        const due = screen.getByRole('button', { name: 'Due: Choose date' });
        expect(due).toHaveAttribute('aria-invalid', 'true');
        expect(
            document.getElementById(due.getAttribute('aria-describedby')!),
        ).toHaveTextContent('Choose a valid due date.');
        expect(screen.getByRole('textbox', { name: /Task/ })).toHaveValue(
            'Arrange an agreed review.',
        );
    });

    it('keeps an unavailable assignment until the user explicitly clears it', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        incident.assignable_staff = [
            { id: 81, name: 'Current support worker' },
        ];
        const view = render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.change(screen.getByRole('textbox', { name: /Task/ }), {
            target: { value: 'Confirm the follow-up plan.' },
        });
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'Assign to' }), {
            key: 'Enter',
        });
        fireEvent.click(
            screen.getByRole('option', { name: 'Current support worker' }),
        );
        view.rerender(
            <IncidentShow detail={{ ...incident, assignable_staff: [] }} />,
        );
        expect(
            screen.getByRole('combobox', { name: 'Assign to' }),
        ).toHaveTextContent('Staff selection unavailable');
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        expect(state.post).toHaveBeenLastCalledWith(
            '/incidents/42/followups',
            expect.objectContaining({ assigned_to_user_id: '81' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear assignment' }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Assign to' }),
        ).toHaveTextContent('Unassigned');
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        expect(state.post).toHaveBeenLastCalledWith(
            '/incidents/42/followups',
            expect.objectContaining({
                assigned_to_user_id: '',
                notes: 'Confirm the follow-up plan.',
            }),
        );
    });

    it('prevents another add request while the follow-up is being saved', () => {
        state.url = '/incidents/42?section=followups';
        const incident = detail();
        incident.can.followupsManage = true;
        const view = render(<IncidentShow detail={incident} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add follow-up' }));
        fireEvent.change(screen.getByRole('textbox', { name: /Task/ }), {
            target: { value: 'Arrange the review.' },
        });
        state.processing = true;
        view.rerender(<IncidentShow detail={incident} />);
        const saving = screen.getByRole('button', {
            name: 'Adding follow-up…',
        });
        expect(saving).toBeDisabled();
        expect(screen.getByRole('textbox', { name: /Task/ })).toBeDisabled();
        fireEvent.click(saving);
        expect(state.post).not.toHaveBeenCalled();
    });

    it('opens as a page with visible context and guarded closure, without an always-open dialog', () => {
        render(<IncidentShow detail={detail()} />);
        expect(
            screen.getByRole('heading', { name: 'Fall', level: 1 }),
        ).toBeVisible();
        expect(
            within(screen.getByRole('banner')).getByText(/INC-2026-0042/),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByText('A person fell in the lounge.')).toBeVisible();
        expect(
            within(screen.getByRole('banner')).getByRole('button', {
                name: 'Close incident',
            }),
        ).toBeDisabled();
        expect(
            screen.getByRole('link', { name: 'Complete incident follow-ups' }),
        ).toHaveAttribute('href', '/incidents/42?section=followups');
    });

    it('offers a bounded response request only with source and destination authority', () => {
        const incident = detail();
        const view = render(<IncidentShow detail={incident} />);
        expect(
            screen.queryByRole('button', {
                name: 'Request Control Room response',
            }),
        ).not.toBeInTheDocument();
        view.rerender(
            <IncidentShow
                detail={{
                    ...incident,
                    can: { ...incident.can, requestResponse: true },
                }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Request Control Room response',
            }),
        );
        const dialog = screen.getByRole('dialog');
        fireEvent.change(
            within(dialog).getByRole('textbox', {
                name: 'What help is needed?',
            }),
            {
                target: {
                    value: 'Please coordinate additional support at the house.',
                },
            },
        );
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(within(dialog).getByRole('textbox')).toHaveValue(
            'Please coordinate additional support at the house.',
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Request response' }),
        );
        expect(state.post).toHaveBeenCalledWith(
            '/control-room/incidents/create-alert',
            {
                source_type: 'client_incident',
                source_id: 42,
                severity: 'high',
                notes: 'Please coordinate additional support at the house.',
            },
        );
    });

    it('keeps the active section ahead of the side column without remounting hidden drafts', () => {
        const incident = detail();
        const view = render(<IncidentShow detail={incident} />);
        const summary = screen.getByRole('region', {
            name: 'Incident summary',
        });
        const side = screen.getByRole('complementary');
        expect(
            summary.compareDocumentPosition(side) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        state.url = '/incidents/42?section=followups';
        view.rerender(<IncidentShow detail={incident} />);
        const followups = screen.getByRole('region', {
            name: 'Incident follow-ups',
        });
        expect(
            followups.compareDocumentPosition(side) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(summary).toBeInTheDocument();
    });

    it('puts the next step for an active response in the header, with its wording kept', () => {
        render(
            <IncidentShow
                detail={{
                    ...detail(),
                    journey_state: 'Operational response active',
                }}
            />,
        );
        expect(
            within(screen.getByRole('banner')).getByText(
                /The operational response is still active\. Response and incident review are separate decisions\./,
            ),
        ).toBeVisible();
    });

    it('prioritizes the live response and does not invent a source link for restricted viewers', () => {
        const incident = detail();
        incident.journey_state = 'Operational response active';
        incident.control_room_alert = {
            id: 11,
            status: 'open',
            severity: 'high',
            alert_type: 'fall',
            triggered_at: null,
            resolved_at: null,
            url: '/control-room/alerts/11',
        };
        const view = render(<IncidentShow detail={incident} />);
        expect(
            within(screen.getByRole('banner')).getByRole('link', {
                name: 'Open response',
            }),
        ).toHaveAttribute('href', '/control-room/alerts/11');
        expect(
            screen.getAllByRole('link', { name: 'Open response' }),
        ).toHaveLength(1);
        expect(
            within(screen.getByRole('banner')).getByRole('button', {
                name: 'Close incident',
            }),
        ).toBeDisabled();
        incident.control_room_alert.url = null;
        view.rerender(<IncidentShow detail={incident} />);
        expect(
            screen.queryByRole('link', { name: 'Open response' }),
        ).not.toBeInTheDocument();
        expect(
            within(screen.getByRole('banner')).getByRole('button', {
                name: 'Close incident',
            }),
        ).toBeDisabled();
        expect(
            screen.getByText(/permitted Control Room responder/),
        ).toBeVisible();
    });

    it('retains register filters and opens shareable sections, including browser-back URLs', () => {
        state.url =
            '/incidents/42?return_to=%2Fincidents%3Ftab%3Dreview%26page%3D3';
        const view = render(<IncidentShow detail={detail()} />);
        expect(screen.getByRole('link', { name: 'Back' })).toHaveAttribute(
            'href',
            '/incidents?tab=review&page=3',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Find a section' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'History, Incident' }),
        );
        expect(state.get).toHaveBeenCalledWith(
            '/incidents/42',
            { section: 'history', return_to: '/incidents?tab=review&page=3' },
            { preserveState: true, preserveScroll: true },
        );
        state.url = '/incidents/42?section=history';
        view.rerender(<IncidentShow detail={detail()} />);
        expect(
            screen.getByRole('region', { name: 'Incident history' }),
        ).toBeVisible();
        expect(
            screen.queryByRole('region', { name: 'Incident overview' }),
        ).not.toBeInTheDocument();
    });

    it('keeps report closure separate from connected journey closure', () => {
        const incident = detail();
        incident.status = 'closed';
        render(<IncidentShow detail={incident} />);
        expect(
            screen.getByText(
                /Linked response or safety work still has its own completion requirements/,
            ),
        ).toBeVisible();
        expect(
            screen.queryByText('The connected journey is closed.'),
        ).not.toBeInTheDocument();
    });

    it('preserves an unsent reopening reason when cancelling dismissal and submits through the guarded command', () => {
        const incident = detail();
        incident.status = 'closed';
        incident.can.reopen = true;
        render(<IncidentShow detail={incident} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Reopen incident' }),
        );
        const dialog = screen.getByRole('dialog');
        fireEvent.change(within(dialog).getByRole('textbox'), {
            target: { value: 'New evidence needs review.' },
        });
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        const confirmation = screen.getByRole('alertdialog');
        fireEvent.click(
            within(confirmation).getByRole('button', { name: 'Cancel' }),
        );
        expect(within(dialog).getByRole('textbox')).toHaveValue(
            'New evidence needs review.',
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Reopen incident' }),
        );
        expect(state.post).toHaveBeenCalledWith(
            '/incidents/42/reopen',
            expect.objectContaining({
                reopened_reason: 'New evidence needs review.',
            }),
        );
    });
});

