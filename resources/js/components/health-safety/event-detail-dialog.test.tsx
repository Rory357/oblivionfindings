import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

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
        router: { post: vi.fn() },
        usePage: () => ({ props: { flash: {} } }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: T[keyof T]) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                post: (
                    url: string,
                    options: { onSuccess?: (page: { props: object }) => void },
                ) => {
                    inertia.post(url, data);
                    options.onSuccess?.({ props: { flash: {} } });
                },
                reset: vi.fn(),
                clearErrors: vi.fn(),
            };
        },
    };
});

vi.mock('@/components/wizard/shell', async () => {
    const actual = await vi.importActual<
        typeof import('@/components/wizard/shell')
    >('@/components/wizard/shell');

    return {
        ...actual,
        WizardShell: ({
            children,
            footerStart,
            footerEnd,
            steps,
            onStepClick,
        }: {
            children?: ReactNode;
            footerStart?: ReactNode;
            footerEnd?: ReactNode;
            steps: Array<{ key: string; label: string; blurb: string }>;
            onStepClick: (index: number) => void;
        }) => (
            <div>
                <nav>
                    {steps.map((step, index) => (
                        // eslint-disable-next-line no-restricted-syntax -- lightweight WizardShell test double navigation control
                        <button
                            key={step.key}
                            type="button"
                            onClick={() => onStepClick(index)}
                        >
                            {step.label} {step.blurb}
                        </button>
                    ))}
                </nav>
                <main>{children}</main>
                <footer>
                    {footerStart}
                    {footerEnd}
                </footer>
            </div>
        ),
    };
});

import {
    EventDetailDialog,
    type EventActionKey,
    type EventDetail,
} from './event-detail-dialog';

function eventDetail(overrides: Partial<EventDetail> = {}): EventDetail {
    return {
        id: 17,
        reference_number: 'HS-2026-0017',
        event_category: 'incident',
        severity: 'high',
        status: 'open',
        occurred_at: '2026-07-14T01:30:00Z',
        reported_at: '2026-07-14T01:45:00Z',
        description: 'A contractor fell beside the loading bay.',
        site: { id: 3, name: 'Kauri House' },
        client: null,
        staff: null,
        asset: null,
        worksafe: {
            notifiable: false,
            status: null,
            decision_reason:
                'The event does not meet the statutory notification threshold.',
            decision_source: 'manual',
            decided_at: '2026-07-14T02:20:00Z',
            decided_by: { id: 9, name: 'Tama Lewis' },
            reference: null,
            notified_at: null,
            acknowledged_at: null,
            method: null,
            site_preserved: false,
            can_decide: true,
            can_notify: false,
            can_acknowledge: false,
        },
        investigation_required: true,
        control_room_alert: {
            id: 11,
            reference_number: 'ALT-2026-0011',
            severity: 'high',
            status: 'resolved',
            url: '/control-room/alerts/11',
        },
        closed_at: null,
        closure_summary: null,
        created_by_name: 'Ari Patel',
        source: {
            type: 'incident',
            id: 42,
            label: 'INC-2026-0042',
            url: '/incidents/42',
            unwired: false,
        },
        investigations: [],
        corrective_actions: [],
        risk_assessments: [],
        attachments: [],
        close_gate: {
            acceptance_ok: false,
            worksafe_ok: true,
            investigation_ok: false,
            recommendations_ok: false,
            actions_ok: true,
            blockers: [
                'Accept the H&S handover before closing this event.',
                'Complete the required investigation before closing this event.',
            ],
            requirements: [
                {
                    key: 'worksafe_decision',
                    complete: true,
                    label: 'WorkSafe decision recorded — not notifiable',
                    href: '/health-safety/events/17?action=worksafe-decision',
                },
            ],
        },
        assignable_staff: [
            { id: 8, name: 'Moana Rangi' },
            { id: 9, name: 'Tama Lewis' },
        ],
        action_handover: {
            eligible_owners: [
                { id: 8, name: 'Moana Rangi' },
                { id: 9, name: 'Tama Lewis' },
            ],
            unresolved_control_room_tasks: [],
        },
        can: { manage: true, override_closure: false },
        handover: {
            status: 'awaiting_acceptance',
            owner: null,
            accepted_by: null,
            accepted_at: null,
            notes: null,
            can_accept: true,
        },
        lifecycle: {
            control_room: 'resolved',
            incident: 'submitted',
            health_safety: 'open',
        },
        handover_summary: {
            incident_reference: 'INC-2026-0042',
            alert_reference: 'ALT-2026-0011',
            narrative: 'A contractor slipped while unloading supplies.',
            immediate_controls: 'Loading bay isolated and first aid provided.',
            witnesses: 'Hana Te Rangi',
            potential_consequence: 'Serious head injury.',
            reporter: 'Ari Patel',
            source_label: 'Control Room escalation',
            site_name: 'Kauri House',
            attachments: [
                {
                    id: 71,
                    name: 'loading-bay-photo.jpg',
                    mime: 'image/jpeg',
                    size: 4096,
                    uploaded_by: 'Ari Patel',
                    created_at: '2026-07-14T01:50:00Z',
                    download_url: '/attachments/71',
                },
            ],
            control_room_evidence: [
                {
                    id: 81,
                    title: 'Alert evidence pack',
                    status: 'complete',
                    items: [
                        {
                            id: 82,
                            title: 'CCTV preservation note',
                            description:
                                'Footage retained by the duty manager.',
                            download_url: '/evidence/82',
                        },
                    ],
                },
            ],
            playbook: {
                name: 'Serious incident response',
                status: 'completed',
                outcome: 'Scene secured and escalation completed.',
            },
            communications: [
                {
                    id: 91,
                    channel: 'phone',
                    purpose: 'Duty manager escalation',
                    content: 'Duty manager briefed on immediate controls.',
                    status: 'sent',
                    sent_at: '2026-07-14T01:55:00Z',
                },
            ],
            operational_tasks: [
                {
                    id: 101,
                    title: 'Preserve loading bay CCTV',
                    status: 'in_progress',
                    priority: 'high',
                    assignee: 'Tama Lewis',
                    due_at: '2026-07-14T05:00:00Z',
                },
            ],
            next_action: {
                label: 'Review incident record',
                href: '/incidents/42',
            },
        },
        ...overrides,
    } as EventDetail;
}

function renderDialog(
    detail = eventDetail(),
    initialAction: EventActionKey | null = null,
) {
    return render(
        <EventDetailDialog
            detail={detail}
            open
            initialAction={initialAction}
            onClose={() => {}}
        />,
    );
}

beforeEach(() => inertia.post.mockReset());
afterEach(cleanup);

describe('EventDetailDialog control-room handover', () => {
    it('shows awaiting ownership and all three lifecycle states in the overview', () => {
        renderDialog();

        expect(screen.getByText('Awaiting H&S acceptance')).toBeInTheDocument();
        expect(screen.getByText('No H&S owner assigned')).toBeInTheDocument();
        expect(screen.getAllByText('Control Room')).not.toHaveLength(0);
        expect(screen.getByText('Resolved')).toBeInTheDocument();
        expect(screen.getAllByText('Incident')).not.toHaveLength(0);
        expect(screen.getByText('Submitted')).toBeInTheDocument();
        expect(screen.getByText('Health & Safety')).toBeInTheDocument();
    });

    it('keeps the complete handover package available in one scan-friendly section', () => {
        renderDialog();
        fireEvent.click(screen.getByRole('button', { name: /Handover/ }));

        for (const text of [
            'INC-2026-0042',
            'ALT-2026-0011',
            'Control Room escalation',
            'Kauri House',
            'A contractor slipped while unloading supplies.',
            'Loading bay isolated and first aid provided.',
            'Hana Te Rangi',
            'Serious head injury.',
            'Ari Patel',
            'loading-bay-photo.jpg',
            'Alert evidence pack',
            'CCTV preservation note',
            'Serious incident response',
            'Duty manager escalation',
            'Preserve loading bay CCTV',
            'Review incident record',
        ]) {
            expect(screen.getByText(text)).toBeInTheDocument();
        }
    });

    it('offers one acceptance action only when permitted and posts the owner and notes', () => {
        renderDialog();

        expect(
            screen.getAllByRole('button', { name: 'Accept handover' }),
        ).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Accept handover' }),
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: /Acceptance notes/ }),
            {
                target: {
                    value: 'Accepted for investigation by the H&S team.',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Accept handover' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/events/17/accept-handover',
            {
                owner_user_id: '',
                acceptance_notes: 'Accepted for investigation by the H&S team.',
            },
        );
    });

    it('shows accepted ownership, actor, time and notes without another acceptance action', () => {
        renderDialog(
            eventDetail({
                handover: {
                    status: 'accepted',
                    owner: { id: 8, name: 'Moana Rangi' },
                    accepted_by: { id: 9, name: 'Tama Lewis' },
                    accepted_at: '2026-07-14T02:15:00Z',
                    notes: 'Accepted for formal investigation.',
                    can_accept: false,
                },
            } as Partial<EventDetail>),
        );

        expect(screen.getByText('Accepted into H&S')).toBeInTheDocument();
        expect(screen.getByText('Moana Rangi')).toBeInTheDocument();
        expect(screen.getByText('Tama Lewis')).toBeInTheDocument();
        expect(
            screen.getByText('Accepted for formal investigation.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Accept handover' }),
        ).not.toBeInTheDocument();
    });
});

describe('EventDetailDialog closure governance', () => {
    it('lists every blocker in plain language and does not offer an unauthorised override', () => {
        renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Close event' }));

        expect(
            screen.getByText(
                'Accept the H&S handover before closing this event.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Complete the required investigation before closing this event.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('textbox', { name: /Override reason/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Close event' }),
        ).toBeDisabled();
    });

    it('shows the override decision only to an authorised user and posts the reason', () => {
        renderDialog(
            eventDetail({
                can: { manage: true, override_closure: true },
            } as Partial<EventDetail>),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Close event' }));
        fireEvent.change(
            screen.getByRole('textbox', { name: /Closure summary/ }),
            { target: { value: 'Closed under the formal exception process.' } },
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: /Override reason/ }),
            { target: { value: 'Executive statutory direction.' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Close event' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/events/17/close',
            {
                closure_summary: 'Closed under the formal exception process.',
                override_reason: 'Executive statutory direction.',
            },
        );
    });
});

describe('EventDetailDialog WorkSafe governance', () => {
    const acceptedHandover: EventDetail['handover'] = {
        status: 'accepted',
        owner: { id: 8, name: 'Moana Rangi' },
        accepted_by: { id: 9, name: 'Tama Lewis' },
        accepted_at: '2026-07-14T02:15:00Z',
        notes: null,
        can_accept: false,
    };

    it('shows an undecided state and records an explicit reasoned choice', () => {
        renderDialog(
            eventDetail({
                handover: acceptedHandover,
                worksafe: {
                    notifiable: null,
                    status: null,
                    decision_reason: null,
                    decision_source: null,
                    decided_at: null,
                    decided_by: null,
                    reference: null,
                    notified_at: null,
                    acknowledged_at: null,
                    method: null,
                    site_preserved: false,
                    can_decide: true,
                    can_notify: false,
                    can_acknowledge: false,
                },
                close_gate: {
                    acceptance_ok: true,
                    worksafe_ok: false,
                    investigation_ok: false,
                    recommendations_ok: false,
                    actions_ok: true,
                    blockers: [
                        'Record the WorkSafe notifiability decision before closing this event.',
                    ],
                    requirements: [
                        {
                            key: 'worksafe_decision',
                            complete: false,
                            label: 'Record the WorkSafe notifiability decision',
                            href: '/health-safety/events/17?action=worksafe-decision',
                        },
                    ],
                },
            }),
        );

        expect(screen.getByText('Decision not recorded')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record WorkSafe decision',
            }),
        );

        expect(screen.getByLabelText('Notifiable')).toBeInTheDocument();
        expect(screen.getByLabelText('Not notifiable')).toBeInTheDocument();
        expect(screen.getByLabelText('Decision rationale')).toBeRequired();

        fireEvent.click(screen.getByLabelText('Notifiable'));
        fireEvent.change(screen.getByLabelText('Decision rationale'), {
            target: {
                value: 'The hospital admission meets the statutory notification threshold.',
            },
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record WorkSafe decision',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/events/17/worksafe/decision',
            {
                notifiable: true,
                reason: 'The hospital admission meets the statutory notification threshold.',
                source: 'manual',
            },
        );
    });

    it('shows an explicit not-notifiable decision with actor time and reason', () => {
        renderDialog(eventDetail({ handover: acceptedHandover }));

        expect(
            screen.getByText('Not notifiable — decision recorded'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'The event does not meet the statutory notification threshold.',
            ),
        ).toBeInTheDocument();
        expect(screen.getAllByText(/Tama Lewis/).length).toBeGreaterThan(0);
        expect(
            screen.getByRole('button', {
                name: 'Update WorkSafe decision',
            }),
        ).toBeEnabled();
    });

    it('shows notification pending with the notify action', () => {
        renderDialog(
            eventDetail({
                handover: acceptedHandover,
                worksafe: {
                    ...eventDetail().worksafe,
                    notifiable: true,
                    status: 'pending',
                    decision_reason:
                        'The serious injury meets the statutory notification threshold.',
                    can_notify: true,
                },
            }),
        );

        expect(
            screen.getAllByText('Notification pending').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getByRole('button', {
                name: 'Record WorkSafe notification',
            }),
        ).toBeEnabled();
    });

    it('shows notified and acknowledged states with the correct controls', () => {
        const notified = eventDetail({
            handover: acceptedHandover,
            worksafe: {
                ...eventDetail().worksafe,
                notifiable: true,
                status: 'notified',
                notified_at: '2026-07-14T03:00:00Z',
                method: 'online',
                reference: 'WS-2026-0017',
                can_notify: false,
                can_acknowledge: true,
            },
        });
        const { unmount } = renderDialog(notified);

        expect(
            screen.getAllByText('Notified — acknowledgement pending').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getByRole('button', {
                name: 'Record acknowledgement',
            }),
        ).toBeEnabled();

        unmount();
        renderDialog(
            eventDetail({
                handover: acceptedHandover,
                worksafe: {
                    ...notified.worksafe,
                    status: 'acknowledged',
                    acknowledged_at: '2026-07-14T04:00:00Z',
                    can_acknowledge: false,
                },
            }),
        );

        expect(screen.getAllByText('Acknowledged').length).toBeGreaterThan(0);
        expect(
            screen.queryByRole('button', {
                name: 'Record acknowledgement',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Record WorkSafe notification',
            }),
        ).not.toBeInTheDocument();
    });

    it('keeps decision truth visible but removes mutation actions for a view-only user', () => {
        renderDialog(
            eventDetail({
                handover: acceptedHandover,
                can: { manage: false, override_closure: false },
                worksafe: {
                    ...eventDetail().worksafe,
                    can_decide: false,
                    can_notify: false,
                },
            }),
        );

        expect(
            screen.getByText('Not notifiable — decision recorded'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Update WorkSafe decision',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Record WorkSafe notification',
            }),
        ).not.toBeInTheDocument();
    });

    it('uses the server closure label and direct action link', () => {
        renderDialog(
            eventDetail({
                handover: acceptedHandover,
                worksafe: {
                    ...eventDetail().worksafe,
                    notifiable: null,
                    status: null,
                    decision_reason: null,
                    decision_source: null,
                    decided_at: null,
                    decided_by: null,
                },
                close_gate: {
                    acceptance_ok: true,
                    worksafe_ok: false,
                    investigation_ok: true,
                    recommendations_ok: true,
                    actions_ok: true,
                    blockers: [
                        'Record the WorkSafe notifiability decision before closing this event.',
                    ],
                    requirements: [
                        {
                            key: 'worksafe_decision',
                            complete: false,
                            label: 'Record the WorkSafe notifiability decision',
                            href: '/health-safety/events/17?action=worksafe-decision',
                        },
                    ],
                },
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Close event' }));

        expect(
            screen.getByRole('link', {
                name: 'Record the WorkSafe notifiability decision',
            }),
        ).toHaveAttribute(
            'href',
            '/health-safety/events/17?action=worksafe-decision',
        );
    });

    it('uses the pending notification link and opens the notification pane', () => {
        const pending = eventDetail({
            handover: acceptedHandover,
            worksafe: {
                ...eventDetail().worksafe,
                notifiable: true,
                status: 'pending',
                can_notify: true,
            },
            close_gate: {
                acceptance_ok: true,
                worksafe_ok: false,
                investigation_ok: true,
                recommendations_ok: true,
                actions_ok: true,
                blockers: [
                    'Record the WorkSafe notification before closing this event.',
                ],
                requirements: [
                    {
                        key: 'worksafe_decision',
                        complete: false,
                        label: 'Record the WorkSafe notification',
                        href: '/health-safety/events/17?action=worksafe-notify',
                    },
                ],
            },
        });
        const { unmount } = renderDialog(pending);

        fireEvent.click(screen.getByRole('button', { name: 'Close event' }));
        expect(
            screen.getByRole('link', {
                name: 'Record the WorkSafe notification',
            }),
        ).toHaveAttribute(
            'href',
            '/health-safety/events/17?action=worksafe-notify',
        );

        unmount();
        renderDialog(pending, 'worksafe_notify');
        expect(
            screen.getByText('Record WorkSafe notification'),
        ).toBeInTheDocument();
        expect(screen.getByText('Notified at')).toBeInTheDocument();
    });

    it('does not open forced WorkSafe mutation panes without capability', () => {
        const viewOnly = eventDetail({
            can: { manage: false, override_closure: false },
            worksafe: {
                ...eventDetail().worksafe,
                can_decide: false,
                can_notify: false,
                can_acknowledge: false,
            },
        });
        const { unmount } = renderDialog(viewOnly, 'worksafe_decision');

        expect(
            screen.queryByLabelText('Decision rationale'),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Governance stage')).toBeInTheDocument();

        unmount();
        renderDialog(
            eventDetail({
                status: 'closed',
                worksafe: {
                    ...eventDetail().worksafe,
                    notifiable: true,
                    status: 'pending',
                    can_decide: false,
                    can_notify: false,
                    can_acknowledge: false,
                },
            }),
            'worksafe_notify',
        );

        expect(
            screen.queryByText('Record WorkSafe notification'),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Governance stage')).toBeInTheDocument();
    });

    it('does not present an unknown WorkSafe status as acknowledged', () => {
        renderDialog(
            eventDetail({
                handover: acceptedHandover,
                worksafe: {
                    ...eventDetail().worksafe,
                    notifiable: true,
                    status: 'legacy_unknown',
                },
            }),
        );

        expect(
            screen.getAllByText('WorkSafe status needs review').length,
        ).toBeGreaterThan(0);
        expect(screen.queryByText('Acknowledged')).not.toBeInTheDocument();
    });
});

describe('EventDetailDialog recommendation outcomes', () => {
    it('shows the decision, action link, actor and time for each recommendation', () => {
        renderDialog(
            eventDetail({
                handover: {
                    status: 'accepted',
                    owner: { id: 8, name: 'Moana Rangi' },
                    accepted_by: { id: 9, name: 'Tama Lewis' },
                    accepted_at: '2026-07-14T02:15:00Z',
                    notes: null,
                    can_accept: false,
                },
                investigations: [
                    {
                        id: 31,
                        reference_number: 'INV-2026-0031',
                        investigation_type: 'standard',
                        status: 'completed',
                        methodology: '5_whys',
                        lead_investigator_name: 'Moana Rangi',
                        started_at: '2026-07-14T03:00:00Z',
                        target_completion_date: '2026-07-21',
                        completed_at: '2026-07-15T01:00:00Z',
                        is_overdue: false,
                        has_findings: true,
                        has_recommendations: true,
                        recommendation_count: 2,
                        immediate_causes: [],
                        root_causes: [],
                        contributing_factors: [],
                        findings_summary: 'Controls were reviewed.',
                        recommendations: [
                            {
                                description: 'Retain the current control.',
                                priority: 'medium',
                                disposition: {
                                    disposition: 'accepted_risk',
                                    reason: 'Residual risk is within tolerance.',
                                    corrective_action: null,
                                    decided_by_name: 'Tama Lewis',
                                    decided_at: '2026-07-15T02:00:00Z',
                                },
                            },
                            {
                                description:
                                    'Update the loading-bay procedure.',
                                priority: 'high',
                                disposition: null,
                            },
                        ],
                        lessons_learned: null,
                    },
                ],
            } as Partial<EventDetail>),
        );

        fireEvent.click(screen.getByRole('button', { name: /Investigation/ }));

        expect(screen.getByText('Accepted risk')).toBeInTheDocument();
        expect(
            screen.getByText('Residual risk is within tolerance.'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Tama Lewis/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Choose outcome' }),
        ).toBeInTheDocument();
    });

    it('records a reasoned non-action outcome from the recommendation row', () => {
        const detail = eventDetail({
            investigations: [
                {
                    id: 31,
                    reference_number: 'INV-2026-0031',
                    investigation_type: 'standard',
                    status: 'completed',
                    methodology: '5_whys',
                    lead_investigator_name: 'Moana Rangi',
                    started_at: '2026-07-14T03:00:00Z',
                    target_completion_date: '2026-07-21',
                    completed_at: '2026-07-15T01:00:00Z',
                    is_overdue: false,
                    has_findings: true,
                    has_recommendations: true,
                    recommendation_count: 1,
                    immediate_causes: [],
                    root_causes: [],
                    contributing_factors: [],
                    findings_summary: 'Controls were reviewed.',
                    recommendations: [
                        {
                            description: 'Retain the current control.',
                            priority: 'medium',
                            disposition: null,
                        },
                    ],
                    lessons_learned: null,
                },
            ],
        } as Partial<EventDetail>);
        renderDialog(detail);
        fireEvent.click(screen.getByRole('button', { name: /Investigation/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Choose outcome' }));
        fireEvent.click(screen.getByRole('combobox', { name: /Outcome/ }));
        fireEvent.click(
            screen.getByRole('option', {
                name: 'Accept the residual risk',
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Record outcome' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByRole('textbox', { name: /Reason/ }), {
            target: { value: 'Residual risk is within tolerance.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Record outcome' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/events/17/investigations/31/recommendations/0/disposition',
            {
                disposition: 'accepted_risk',
                reason: 'Residual risk is within tolerance.',
            },
        );
    });

    it('opens the focused ownership handover for a corrective-action outcome', () => {
        const detail = eventDetail({
            investigations: [
                {
                    id: 31,
                    reference_number: 'INV-2026-0031',
                    investigation_type: 'standard',
                    status: 'completed',
                    methodology: '5_whys',
                    lead_investigator_name: 'Moana Rangi',
                    started_at: '2026-07-14T03:00:00Z',
                    target_completion_date: '2026-07-21',
                    completed_at: '2026-07-15T01:00:00Z',
                    is_overdue: false,
                    has_findings: true,
                    has_recommendations: true,
                    recommendation_count: 1,
                    immediate_causes: [],
                    root_causes: [],
                    contributing_factors: [],
                    findings_summary: 'Controls were reviewed.',
                    recommendations: [
                        {
                            description:
                                'Install a permanent bathroom safety rail.',
                            priority: 'high',
                            disposition: null,
                        },
                    ],
                    lessons_learned: null,
                },
            ],
        } as Partial<EventDetail>);

        renderDialog(detail);
        fireEvent.click(screen.getByRole('button', { name: /Investigation/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Choose outcome' }));
        fireEvent.click(screen.getByRole('combobox', { name: /Outcome/ }));
        fireEvent.click(
            screen.getByRole('option', {
                name: 'Raise a corrective action',
            }),
        );

        expect(
            screen.getByRole('combobox', { name: 'Action owner' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Create and hand over action',
            }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Record outcome' }),
        ).not.toBeInTheDocument();
    });
});

describe('EventDetailDialog corrective-action provenance', () => {
    it('shows the recommendation, owner, due date and transferred task', () => {
        renderDialog(
            eventDetail({
                corrective_actions: [
                    {
                        id: 61,
                        reference_number: 'CA-2026-0061',
                        title: 'Install a permanent bathroom safety rail.',
                        action_type: 'corrective',
                        priority: 'high',
                        status: 'open',
                        assigned_to_name: 'Playwright Incident Reviewer',
                        due_date: '2026-08-31',
                        is_overdue: false,
                        completed_at: null,
                        completed_by_user_id: null,
                        completed_by_name: null,
                        can_verify: false,
                        verified_at: null,
                        verified_by_name: null,
                        effectiveness_confirmed: null,
                        hs_investigation_id: 31,
                        recommendation_index: 0,
                        recommendation:
                            'Install a permanent bathroom safety rail.',
                        source: {
                            type: 'control_room_task',
                            id: 501,
                            reference: 'CR task #501',
                            title: 'Replace the unsafe bathroom rail',
                        },
                    },
                ],
            }),
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Corrective actions/ }),
        );

        expect(
            screen.getByText('Playwright Incident Reviewer', { exact: false }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Recommendation: Install a permanent bathroom safety rail.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Transferred from Control Room task: CR task #501 · Replace the unsafe bathroom rail',
            ),
        ).toBeInTheDocument();
    });
});
