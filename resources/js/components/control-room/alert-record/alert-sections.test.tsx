import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

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
        router: {
            post: vi.fn(),
            delete: vi.fn(),
            reload: vi.fn(),
            get: vi.fn(),
        },
        usePage: () => ({ props: { flash: {}, auth: { user: { id: 7 } } } }),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initial);
            const transformRef = ReactActual.useRef<
                | ((data: Record<string, unknown>) => Record<string, unknown>)
                | null
            >(null);

            const form = {
                data,
                errors: {},
                processing: false,
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) => {
                    if (typeof key === 'string') {
                        setDataState((current) => ({
                            ...current,
                            [key]: value,
                        }));
                    } else {
                        setDataState(key);
                    }
                },
                transform: (
                    callback: (
                        data: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => {
                    transformRef.current = callback;
                    return form;
                },
                post: (
                    url: string,
                    options?: {
                        onSuccess?: (page: {
                            props: { flash: Record<string, unknown> };
                        }) => void;
                    },
                ) => {
                    inertia.post(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                    options?.onSuccess?.({ props: { flash: {} } });
                },
                reset: () => setDataState(initial),
                clearErrors: vi.fn(),
            };

            return form;
        },
    };
});

import {
    AddNoteForm,
    EvidenceSection,
    LinkedSection,
    type AlertWorkspaceDetail,
} from '@/components/control-room/alert-record/alert-sections';
import {
    useAlertActions,
    type AlertActionKey,
} from '@/components/control-room/alert-record/alert-actions';
import { alertDetail } from '@/components/control-room/alert-record/test-fixtures';
import { useEffect } from 'react';

/** Opens one action's pop-up exactly as the alert page does. */
function ActionHarness({
    d,
    action,
}: {
    d: AlertWorkspaceDetail;
    action: AlertActionKey;
}) {
    const actions = useAlertActions(d);
    useEffect(() => actions.run(action), []); // eslint-disable-line react-hooks/exhaustive-deps
    return <>{actions.host}</>;
}

function detail(
    handover: AlertWorkspaceDetail['linked_hs_event'] extends infer T
        ? T extends { handover: infer H }
            ? H
            : never
        : never,
    href: string | null,
): AlertWorkspaceDetail {
    return {
        alert: { context: {}, asset: null },
        client: null,
        linked_hs_event: {
            id: 17,
            reference_number: 'HS-2026-0017',
            status: 'open',
            worksafe_notifiable: true,
            worksafe_status: 'pending',
            worksafe_reference: null,
            worksafe_notified_at: null,
            worksafe_acknowledged_at: null,
            handover,
            investigation_required: true,
            investigation: null,
            href,
        },
    } as unknown as AlertWorkspaceDetail;
}

afterEach(cleanup);

beforeEach(() => {
    inertia.post.mockReset();
});

describe('Monitoring recovery evidence', () => {
    it('shows recorded recovery without offering automatic resolution to a read-only operator', () => {
        const d = {
            alert: { id: 1, status: 'open' },
            can: { manage: false },
            evidence_packs: [],
            monitoring_recovery: {
                observed_at: '2026-09-12T01:00:00Z',
                verification_required: true,
            },
        } as unknown as AlertWorkspaceDetail;
        render(<EvidenceSection d={d} />);
        expect(
            screen.getByText('Monitoring recovery recorded'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /does not resolve the operational alert or complete linked IT work/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /resolve|New evidence pack/i,
            }),
        ).not.toBeInTheDocument();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('does not infer recovery when the authorised projection omits it', () => {
        const d = {
            alert: { id: 1, status: 'open' },
            can: { manage: false },
            evidence_packs: [],
            monitoring_recovery: null,
        } as unknown as AlertWorkspaceDetail;
        render(<EvidenceSection d={d} />);
        expect(
            screen.queryByText('Monitoring recovery recorded'),
        ).not.toBeInTheDocument();
    });
});

describe('Control Room linked H&S handover', () => {
    it('shows accepted owner, actor and time without advertising an inaccessible H&S link', () => {
        render(
            <LinkedSection
                d={detail(
                    {
                        status: 'accepted',
                        owner: { id: 8, name: 'Moana Rangi' },
                        accepted_by: { id: 9, name: 'Tama Lewis' },
                        accepted_at: '2026-07-14T02:15:00Z',
                        notes: 'Accepted for formal investigation.',
                    },
                    null,
                )}
            />,
        );

        const row = screen.getByText('Health & Safety event').closest('div');
        expect(row).toHaveTextContent('Accepted into H&S');
        expect(row).toHaveTextContent('owner Moana Rangi');
        expect(row).toHaveTextContent('accepted by Tama Lewis');
        expect(
            screen.getByText('Health & Safety event').closest('a'),
        ).toBeNull();
    });

    it('shows awaiting acceptance and links only when the viewer can open H&S', () => {
        render(
            <LinkedSection
                d={detail(
                    {
                        status: 'awaiting_acceptance',
                        owner: null,
                        accepted_by: null,
                        accepted_at: null,
                        notes: null,
                    },
                    '/health-safety/events/17',
                )}
            />,
        );

        expect(screen.getByText(/Awaiting H&S acceptance/)).toBeInTheDocument();
        expect(
            screen.getByText('Health & Safety event').closest('a'),
        ).toHaveAttribute('href', '/health-safety/events/17');
    });

    it('opens the canonical live IT incident without creating another work register', () => {
        const d = detail(
            {
                status: 'awaiting_acceptance',
                owner: null,
                accepted_by: null,
                accepted_at: null,
                notes: null,
            },
            null,
        );
        d.linked_it_work = {
            id: 42,
            reference: 'IT-000042',
            title: 'Core switch monitoring outage',
            status: 'in_progress',
            status_reason: 'monitoring_recovered',
            priority: 'urgent',
            sla_state: 'at_risk',
            resolution_due_at: '2026-07-18T02:00:00Z',
            monitoring_recovered_at: '2026-07-18T01:30:00Z',
            assignee: { id: 8, name: 'Moana Rangi' },
            href: '/it/tickets/42',
            access: { state: 'available', message: null },
        };
        d.linked_device = {
            id: 10,
            uid: 'NET-CORE-01',
            name: 'Core switch',
            href: '/security-devices/devices/10',
            access: { state: 'available', message: null },
        };

        render(<LinkedSection d={d} />);

        const link = screen.getByText('IT incident work').closest('a');
        expect(link).toHaveAttribute('href', '/it/tickets/42');
        expect(link).toHaveTextContent('IT-000042');
        expect(link).toHaveTextContent('with Moana Rangi');
        expect(link).toHaveTextContent(
            'monitoring recovered; technician closure still required',
        );
        expect(screen.queryByText(/create IT ticket/i)).not.toBeInTheDocument();
        expect(
            screen.getByText('Canonical Device').closest('a'),
        ).toHaveAttribute('href', '/security-devices/devices/10');
        expect(
            screen.getByText('Canonical Device').closest('a'),
        ).toHaveTextContent('Core switch · NET-CORE-01');
    });

    it('keeps same-Site handoff ownership explicit without leaking inaccessible record identity', () => {
        const d = detail(
            {
                status: 'awaiting_acceptance',
                owner: null,
                accepted_by: null,
                accepted_at: null,
                notes: null,
            },
            null,
        );
        d.linked_it_work = {
            id: null,
            reference: null,
            title: null,
            status: null,
            status_reason: null,
            priority: null,
            sla_state: null,
            resolution_due_at: null,
            monitoring_recovered_at: null,
            assignee: null,
            href: null,
            access: {
                state: 'restricted',
                message: 'IT workspace access is required to open this record.',
            },
        };
        d.linked_device = {
            id: null,
            uid: null,
            name: null,
            href: null,
            access: {
                state: 'restricted',
                message:
                    'Security & Devices access is required to open this Device.',
            },
        };

        render(<LinkedSection d={d} />);

        expect(screen.getByText('IT incident work').closest('a')).toBeNull();
        expect(screen.getByText('Canonical Device').closest('a')).toBeNull();
        expect(
            screen.getByText(
                'IT workspace access is required to open this record.',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Security & Devices access is required to open this Device.',
            ),
        ).toBeVisible();
        expect(screen.queryByText('IT-000042')).toBeNull();
        expect(screen.queryByText('Core switch')).toBeNull();
    });
});

describe('Control Room journey gates', () => {
    function gateDetail(): AlertWorkspaceDetail {
        return alertDetail({
            alert: {
                id: 41,
                reference_number: 'CR-2026-0041',
                source: 'manual',
                alert_type: 'incident.fall',
                severity: 'high',
                status: 'triaging',
                notes: 'Resident found beside the bed.',
            },
            client: { id: 7, name: 'Aroha Rangi' },
            resolve_gate: {
                allowed: false,
                requirements: [
                    {
                        key: 'operational_tasks',
                        complete: false,
                        label: 'Complete, cancel with a reason, or transfer Confirm the temporary control remains effective',
                        href: '/control-room/alerts/41',
                    },
                ],
            },
            close_gate: {
                allowed: false,
                requirements: [
                    {
                        key: 'incident_closed',
                        complete: false,
                        label: 'Close linked incident INC-2026-0042',
                        href: '/incidents/42',
                    },
                ],
            },
        } as Partial<AlertWorkspaceDetail>);
    }

    it('blocks resolve on the server gate and says a linked incident stays open', () => {
        render(<ActionHarness d={gateDetail()} action="resolve" />);

        expect(
            screen.getByText(
                'Ends the live response. A linked incident or H&S work stays open with its own owner.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: /Complete, cancel with a reason, or transfer/,
            }),
        ).toHaveAttribute('href', '/control-room/alerts/41');
        expect(screen.getByRole('button', { name: 'Review resolution' })).toBeDisabled();
        expect(screen.queryByText(/they stay open after resolving/i)).not.toBeInTheDocument();
    });

    it('blocks final alert closure until the linked journey gate is complete', () => {
        const d = gateDetail();
        d.alert.status = 'resolved';

        render(<ActionHarness d={d} action="close" />);

        expect(
            screen.getByText(
                'Closes the response record. This is only possible once the linked incident and H&S work are closed.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Close linked incident INC-2026-0042' }),
        ).toHaveAttribute('href', '/incidents/42');
        expect(screen.getByRole('button', { name: 'Close alert' })).toBeDisabled();
    });
});

describe('Control Room immediate-control capture', () => {
    it('submits an operator note with an explicit purpose', async () => {
        render(<AddNoteForm alertId={41} />);

        fireEvent.click(screen.getByRole('combobox', { name: 'Note purpose' }));
        fireEvent.click(await screen.findByRole('option', { name: 'Immediate controls' }));
        fireEvent.change(screen.getByPlaceholderText('Add an operator note…'), {
            target: { value: 'Area isolated and the resident checked.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add note' }));

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith('/control-room/alerts/41/note', {
                note: 'Area isolated and the resident checked.',
                purpose: 'immediate_controls',
            }),
        );
    });

    it('shows where the immediate-action prefill came from and reports the operator’s edit', async () => {
        const d = alertDetail({
            alert: { id: 41, reference_number: 'CR-2026-0041', alert_type: 'fall', severity: 'high' },
            client: { id: 7, name: 'Aroha Rangi' },
            incident_defaults: {
                immediate_action_taken: 'Area isolated and first aid started.',
                source_note: { id: 81, user_name: 'Moana Operator', created_at: '2026-07-16T05:30:00Z' },
            },
        });

        render(<ActionHarness d={d} action="report_incident" />);

        expect(screen.getByText(/Filled in from an Immediate controls note/)).toHaveTextContent(
            'Moana Operator',
        );
        const action = screen.getByLabelText(/Immediate action taken/);
        expect(action).toHaveValue('Area isolated and first aid started.');

        fireEvent.change(action, {
            target: { value: 'Edited before submission: area isolated and RN called.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Review report' }));
        fireEvent.click(screen.getByRole('button', { name: 'Report incident' }));

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith(
                '/control-room/alerts/41/create-incident',
                expect.objectContaining({
                    immediate_action_taken: 'Edited before submission: area isolated and RN called.',
                }),
            ),
        );
    });

    it('blocks a serious incident report while the immediate action is blank', () => {
        const d = alertDetail({
            alert: { id: 42, reference_number: 'CR-2026-0042', alert_type: 'injury', severity: 'critical' },
            client: { id: 8, name: 'Wiremu Kauri' },
        });

        render(<ActionHarness d={d} action="report_incident" />);

        expect(screen.getByRole('button', { name: 'Review report' })).toBeDisabled();
        expect(screen.getByText(/No immediate control was possible/)).toBeVisible();
        expect(screen.getByText(/No Immediate controls note was found/)).toBeVisible();
    });

    it('requires controls when a low alert is raised to a high-severity incident', async () => {
        const d = alertDetail({
            alert: { id: 44, reference_number: 'CR-2026-0044', alert_type: 'fall', severity: 'low' },
            client: { id: 10, name: 'Mereana Kauri' },
        });

        render(<ActionHarness d={d} action="report_incident" />);
        expect(screen.getByRole('button', { name: 'Review report' })).toBeEnabled();

        fireEvent.click(screen.getByRole('combobox', { name: 'Severity' }));
        fireEvent.click(await screen.findByRole('option', { name: 'High' }));

        expect(screen.getByRole('button', { name: 'Review report' })).toBeDisabled();
    });

    it('carries the immediate-control prefill through sensor confirmation', async () => {
        const d = alertDetail({
            alert: {
                id: 43,
                reference_number: 'CR-2026-0043',
                source: 'sensor',
                alert_type: 'fall_detected',
                severity: 'critical',
            },
            client: { id: 9, name: 'Hemi Te Rangi' },
            incident_defaults: {
                immediate_action_taken: 'Resident checked and the sensor area made safe.',
                source_note: { id: 83, user_name: 'Aroha Operator', created_at: '2026-07-16T06:00:00Z' },
            },
        });

        render(<ActionHarness d={d} action="confirm_sensor" />);

        expect(screen.getByLabelText(/Immediate action taken/)).toHaveValue(
            'Resident checked and the sensor area made safe.',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Review confirmation' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm and report incident' }));

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith(
                '/control-room/alerts/43/confirm',
                expect.objectContaining({
                    immediate_action_taken: 'Resident checked and the sensor area made safe.',
                }),
            ),
        );
    });

    it('requires controls when sensor confirmation defaults a medium alert to a high incident', () => {
        const d = alertDetail({
            alert: {
                id: 45,
                reference_number: 'CR-2026-0045',
                source: 'sensor',
                alert_type: 'fall_detected',
                severity: 'medium',
            },
            client: { id: 11, name: 'Rangi Moana' },
        });

        render(<ActionHarness d={d} action="confirm_sensor" />);

        expect(screen.getByRole('button', { name: 'Review confirmation' })).toBeDisabled();
        expect(screen.getByText(/No Immediate controls note was found/)).toBeVisible();
    });
});
