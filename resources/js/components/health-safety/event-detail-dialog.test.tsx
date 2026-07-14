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

import { EventDetailDialog, type EventDetail } from './event-detail-dialog';

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
        worksafe_notifiable: false,
        worksafe_status: null,
        worksafe_reference: null,
        worksafe_notified_at: null,
        worksafe_acknowledged_at: null,
        worksafe_method: null,
        worksafe_site_preserved: false,
        worksafe_reason: null,
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
        },
        assignable_staff: [
            { id: 8, name: 'Moana Rangi' },
            { id: 9, name: 'Tama Lewis' },
        ],
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

function renderDialog(detail = eventDetail()) {
    return render(
        <EventDetailDialog detail={detail} open onClose={() => {}} />,
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
});
