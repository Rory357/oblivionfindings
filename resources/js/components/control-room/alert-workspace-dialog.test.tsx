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
    ClosePane,
    CreateIncidentPane,
    LinkedSection,
    ResolvePane,
    SensorConfirmPane,
    WatchToggle,
    type AlertWorkspaceDetail,
} from './alert-workspace-dialog';

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

describe('Control Room workspace permissions', () => {
    it('does not render the manage-only watcher action for a read-only viewer', () => {
        render(
            <WatchToggle
                d={
                    {
                        alert: { id: 41 },
                        can: { manage: false, watch: false },
                        watchers: [],
                        staff: [],
                        is_watching: false,
                    } as unknown as AlertWorkspaceDetail
                }
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Watch this alert/ }),
        ).not.toBeInTheDocument();
    });
});

describe('Control Room journey gates', () => {
    function gateDetail(): AlertWorkspaceDetail {
        return {
            alert: {
                id: 41,
                reference_number: 'CR-2026-0041',
                source: 'manual',
                alert_type: 'incident.fall',
                severity: 'high',
                status: 'triaging',
                context: {},
                notes: 'Resident found beside the bed.',
                resolution_code: null,
                triggered_at: '2026-07-16T05:30:00Z',
            },
            client: { id: 7, name: 'Aroha Rangi' },
            tasks: [
                {
                    id: 81,
                    title: 'Confirm the temporary control remains effective',
                    status: 'in_progress',
                },
            ],
            config_options: { resolution_codes: [] },
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
            journey_state: 'Operational response active',
        } as unknown as AlertWorkspaceDetail;
    }

    it('blocks resolve on the server gate and removes the false open-task promise', () => {
        render(<ResolvePane d={gateDetail()} onDone={vi.fn()} />);

        expect(
            screen.getByText(
                'Resolve ends the live operational response. It does not close the linked incident or H&S governance.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: /Complete, cancel with a reason, or transfer/,
            }),
        ).toHaveAttribute('href', '/control-room/alerts/41');
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
        expect(
            screen.queryByText(/they stay open after resolving/i),
        ).not.toBeInTheDocument();
    });

    it('blocks final alert closure until the linked journey gate is complete', () => {
        const d = gateDetail();
        d.alert.status = 'resolved';

        render(<ClosePane d={d} onDone={vi.fn()} />);

        expect(
            screen.getByText(
                'Close is available only when the incident and H&S governance are closed.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Close linked incident INC-2026-0042',
            }),
        ).toHaveAttribute('href', '/incidents/42');
        expect(
            screen.getByRole('button', { name: 'Close alert' }),
        ).toBeDisabled();
    });
});

describe('Control Room immediate-control capture', () => {
    it('submits an operator note with an explicit purpose', async () => {
        render(<AddNoteForm alertId={41} />);

        fireEvent.click(screen.getByRole('combobox', { name: 'Note purpose' }));
        fireEvent.click(
            await screen.findByRole('option', { name: 'Immediate controls' }),
        );
        fireEvent.change(screen.getByPlaceholderText('Add an operator note…'), {
            target: {
                value: 'Area isolated and the resident checked.',
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add note' }));

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith(
                '/control-room/alerts/41/note',
                {
                    note: 'Area isolated and the resident checked.',
                    purpose: 'immediate_controls',
                },
            ),
        );
    });

    it('shows where the immediate-action prefill came from and submits the operators edit', async () => {
        const workspace = {
            alert: {
                id: 41,
                reference_number: 'CR-2026-0041',
                alert_type: 'fall',
                severity: 'high',
                context: {},
            },
            client: { id: 7, name: 'Aroha Rangi' },
            incident_defaults: {
                immediate_action_taken: 'Area isolated and first aid started.',
                source_note: {
                    id: 81,
                    user_name: 'Moana Operator',
                    created_at: '2026-07-16T05:30:00Z',
                },
            },
        } as unknown as AlertWorkspaceDetail;

        render(<CreateIncidentPane d={workspace} onDone={vi.fn()} />);

        expect(
            screen.getByText(/Prefilled from an Immediate controls note/),
        ).toHaveTextContent('Moana Operator');
        const action = screen.getByLabelText('Immediate action taken *');
        expect(action).toHaveValue('Area isolated and first aid started.');

        fireEvent.change(action, {
            target: {
                value: 'Edited before submission: area isolated and RN called.',
            },
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Create incident and hand over',
            }),
        );

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith(
                '/control-room/alerts/41/create-incident',
                expect.objectContaining({
                    immediate_action_taken:
                        'Edited before submission: area isolated and RN called.',
                }),
            ),
        );
    });

    it('blocks serious incident handover while immediate action is blank', () => {
        const workspace = {
            alert: {
                id: 42,
                reference_number: 'CR-2026-0042',
                alert_type: 'injury',
                severity: 'critical',
                context: {},
            },
            client: { id: 8, name: 'Wiremu Kauri' },
            incident_defaults: {
                immediate_action_taken: '',
                source_note: null,
            },
        } as unknown as AlertWorkspaceDetail;

        render(<CreateIncidentPane d={workspace} onDone={vi.fn()} />);

        expect(
            screen.getByRole('button', {
                name: 'Create incident and hand over',
            }),
        ).toBeDisabled();
        expect(
            screen.getByText(/No immediate control was possible/),
        ).toBeVisible();
        expect(
            screen.getByText(/No marked Immediate controls note was found/),
        ).toBeVisible();
    });

    it('requires controls when a low alert is raised to a high-severity incident', async () => {
        const workspace = {
            alert: {
                id: 44,
                reference_number: 'CR-2026-0044',
                alert_type: 'fall',
                severity: 'low',
                context: {},
            },
            client: { id: 10, name: 'Mereana Kauri' },
            incident_defaults: {
                immediate_action_taken: '',
                source_note: null,
            },
        } as unknown as AlertWorkspaceDetail;

        render(<CreateIncidentPane d={workspace} onDone={vi.fn()} />);

        expect(
            screen.getByRole('button', {
                name: 'Create incident and hand over',
            }),
        ).toBeEnabled();

        fireEvent.click(
            screen.getByRole('combobox', { name: 'Select severity' }),
        );
        fireEvent.click(await screen.findByRole('option', { name: 'High' }));

        expect(
            screen.getByRole('button', {
                name: 'Create incident and hand over',
            }),
        ).toBeDisabled();
    });

    it('carries the typed immediate-control prefill through sensor confirmation', async () => {
        const workspace = {
            alert: {
                id: 43,
                reference_number: 'CR-2026-0043',
                source: 'sensor',
                alert_type: 'fall_detected',
                severity: 'critical',
                context: {},
            },
            client: { id: 9, name: 'Hemi Te Rangi' },
            incident_defaults: {
                immediate_action_taken:
                    'Resident checked and the sensor area made safe.',
                source_note: {
                    id: 83,
                    user_name: 'Aroha Operator',
                    created_at: '2026-07-16T06:00:00Z',
                },
            },
        } as unknown as AlertWorkspaceDetail;

        render(<SensorConfirmPane d={workspace} onDone={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect(screen.getByLabelText('Immediate action taken *')).toHaveValue(
            'Resident checked and the sensor area made safe.',
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Confirm — create incident',
            }),
        );

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith(
                '/control-room/alerts/43/confirm',
                expect.objectContaining({
                    immediate_action_taken:
                        'Resident checked and the sensor area made safe.',
                }),
            ),
        );
    });

    it('requires controls when sensor confirmation defaults a medium alert to a high incident', () => {
        const workspace = {
            alert: {
                id: 45,
                reference_number: 'CR-2026-0045',
                source: 'sensor',
                alert_type: 'fall_detected',
                severity: 'medium',
                context: {},
            },
            client: { id: 11, name: 'Rangi Moana' },
            incident_defaults: {
                immediate_action_taken: '',
                source_note: null,
            },
        } as unknown as AlertWorkspaceDetail;

        render(<SensorConfirmPane d={workspace} onDone={vi.fn()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect(
            screen.getByRole('button', {
                name: 'Confirm — create incident',
            }),
        ).toBeDisabled();
        expect(
            screen.getByText(/No marked Immediate controls note was found/),
        ).toBeVisible();
    });
});
