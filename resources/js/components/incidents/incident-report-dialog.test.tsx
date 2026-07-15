import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    processing: false,
    nextError: null as Record<string, string> | null,
    nextResult: null as Record<string, unknown> | null,
    pageProps: { flash: { created_incident_id: 42 } } as Record<
        string,
        unknown
    >,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactModule = await import('react');

    return {
        usePage: () => ({ props: inertia.pageProps }),
        router: { visit: vi.fn() },
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setFormData] = ReactModule.useState<T>(initial);
            const [errors, setErrors] = ReactModule.useState<
                Record<string, string>
            >({});
            const transform = ReactModule.useRef<(value: T) => unknown>(
                (value) => value,
            );

            return {
                data,
                errors,
                processing: inertia.processing,
                setData: (key: keyof T, value: T[keyof T]) =>
                    setFormData((current) => ({ ...current, [key]: value })),
                transform: (callback: (value: T) => unknown) => {
                    transform.current = callback;
                },
                post: (url: string, options: Record<string, unknown>) => {
                    const payload = transform.current(data);
                    inertia.post(url, payload, options);

                    if (inertia.nextError) {
                        setErrors(inertia.nextError);
                        (
                            options.onError as
                                | ((value: Record<string, string>) => void)
                                | undefined
                        )?.(inertia.nextError);
                        return;
                    }

                    (
                        options.onSuccess as
                            | ((page: {
                                  props: Record<string, unknown>;
                              }) => void)
                            | undefined
                    )?.({
                        props: {
                            flash: {
                                incident_report_result: inertia.nextResult,
                            },
                        },
                    });
                },
                reset: () => setFormData(initial),
                clearErrors: () => setErrors({}),
            };
        },
    };
});

import { ReportIncidentDialog } from '../../pages/health-safety/components/report-incident-dialog';
import { ReportLauncher } from '../../pages/health-safety/components/report-launcher';
import {
    IncidentReportDialog,
    type IncidentReportDefaults,
    type IncidentReportEntryContext,
} from './incident-report-dialog';

const clients = [
    { id: 7, first_name: 'Aroha', last_name: 'Ngata', site_id: 3 },
];
const sites = [{ id: 3, name: 'Kauri House' }];
const defaults = {
    client_id: 7,
    site_id: 3,
    shift_id: 19,
    type: 'fall',
    severity: 'medium' as const,
    occurred_at: '2026-07-12T09:15',
    description: 'Aroha slipped beside the dining table.',
};

function renderDialog(
    entryContext: IncidentReportEntryContext = 'incidents',
    overrides: {
        defaults?: IncidentReportDefaults;
        canManageFollowups?: boolean;
        staff?: Array<{ id: number; name: string }>;
    } = {},
) {
    return render(
        <IncidentReportDialog
            open
            mode="incident"
            entryContext={entryContext}
            clients={clients}
            sites={sites}
            staff={overrides.staff ?? []}
            defaults={overrides.defaults ?? defaults}
            canManageFollowups={overrides.canManageFollowups}
            onClose={() => {}}
            onOpenIncident={() => {}}
        />,
    );
}

function renderHealthSafetyDialog(
    dialogDefaults: IncidentReportDefaults = defaults,
) {
    return render(
        <ReportIncidentDialog
            open
            clients={clients}
            sites={sites}
            defaults={dialogDefaults}
            onClose={() => {}}
        />,
    );
}

function openStep(label: RegExp) {
    const stepButton = screen
        .getAllByRole('button')
        .find((button) => label.test(button.textContent ?? ''));

    expect(stepButton).toBeDefined();
    fireEvent.click(stepButton!);
}

function openReviewStep() {
    openStep(/Review/);
}

function postedPayload(callIndex = 0) {
    return inertia.post.mock.calls[callIndex]?.[1] as Record<string, unknown>;
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.processing = false;
    inertia.nextError = null;
    inertia.nextResult = null;
    inertia.pageProps = { flash: { created_incident_id: 42 } };
});

afterEach(() => cleanup());

describe('IncidentReportDialog truthful incident intent', () => {
    it('renders exactly one Save draft action and one Submit incident action', () => {
        renderDialog();
        openReviewStep();

        expect(
            screen.getAllByRole('button', { name: 'Save draft' }),
        ).toHaveLength(1);
        expect(
            screen.getAllByRole('button', { name: 'Submit incident' }),
        ).toHaveLength(1);
    });

    it.each<IncidentReportEntryContext>([
        'incidents',
        'health_safety',
        'control_room',
    ])(
        'uses the same canonical submit payload from the %s entry context',
        (entryContext) => {
            renderDialog(entryContext);
            openReviewStep();

            fireEvent.click(
                screen.getByRole('button', { name: 'Submit incident' }),
            );

            expect(inertia.post).toHaveBeenCalledTimes(1);
            expect(inertia.post).toHaveBeenCalledWith(
                '/incidents',
                expect.objectContaining({
                    intent: 'submit',
                    client_id: 7,
                    site_id: 3,
                    shift_id: 19,
                    type: 'fall',
                    severity: 'medium',
                    description: 'Aroha slipped beside the dining table.',
                }),
                expect.any(Object),
            );
            expect(
                (inertia.post.mock.calls[0]?.[1] as Record<string, unknown>)
                    .entry_context,
            ).toBeUndefined();
        },
    );

    it('posts the explicit draft intent through the canonical payload', () => {
        renderDialog();
        openReviewStep();

        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/incidents',
            expect.objectContaining({
                intent: 'draft',
                client_id: 7,
                site_id: 3,
                shift_id: 19,
            }),
            expect.any(Object),
        );
    });

    it('reuses one report request UUID when a direct submission is retried', () => {
        inertia.nextError = { description: 'Please retry.' };
        renderHealthSafetyDialog();
        openReviewStep();

        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );
        inertia.nextError = null;
        openReviewStep();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(postedPayload(0).report_request_uuid).toEqual(
            expect.any(String),
        );
        expect(postedPayload(1).report_request_uuid).toBe(
            postedPayload(0).report_request_uuid,
        );
        expect(postedPayload(0).incident_id).toBeUndefined();
    });

    it('reuses the draft UUID for submit and changes it only after Report another', () => {
        inertia.nextResult = {
            result: 'draft',
            incident_reference: 'INC-2026-0042',
        };
        renderHealthSafetyDialog();
        openReviewStep();

        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        const draftUuid = postedPayload(0).report_request_uuid;

        inertia.nextResult = {
            result: 'submitted',
            incident_reference: 'INC-2026-0042',
            hs_reference: 'HS-2026-0017',
        };
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );
        expect(postedPayload(1).report_request_uuid).toBe(draftUuid);

        fireEvent.click(screen.getByRole('button', { name: 'Report another' }));
        openReviewStep();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(postedPayload(2).report_request_uuid).not.toBe(draftUuid);
    });

    it('returns from Draft saved to the first invalid step when submit fails', () => {
        inertia.nextResult = {
            result: 'draft',
            incident_reference: 'INC-2026-0042',
        };
        renderHealthSafetyDialog();
        openReviewStep();

        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        const draftUuid = postedPayload(0).report_request_uuid;
        expect(
            screen.getByRole('heading', { name: 'Draft saved' }),
        ).toBeInTheDocument();

        inertia.nextResult = null;
        inertia.nextError = {
            shift_id: 'The selected shift does not match this incident.',
        };
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(
            screen.queryByRole('heading', { name: 'Draft saved' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Type & people' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(
            screen.getAllByText(
                'The selected shift does not match this incident.',
            ),
        ).toHaveLength(2);
        expect(postedPayload(1).report_request_uuid).toBe(draftUuid);

        openStep(/What happened/);
        expect(
            screen.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('Aroha slipped beside the dining table.');
    });

    it('submits restored occurrence and H&S fields while preserving Critical provenance', () => {
        renderHealthSafetyDialog();

        openStep(/What happened/);
        fireEvent.change(screen.getByLabelText('Incident date'), {
            target: { value: '2026-07-13' },
        });
        fireEvent.change(screen.getByLabelText('Incident time'), {
            target: { value: '14:35' },
        });

        openStep(/Severity & actions/);
        fireEvent.click(screen.getByRole('button', { name: /Critical/ }));
        fireEvent.change(screen.getByLabelText('Harm or injury'), {
            target: { value: 'A bruised shoulder was observed.' },
        });
        fireEvent.change(screen.getByLabelText('Consequence'), {
            target: { value: 'Urgent clinical review was required.' },
        });

        openStep(/WorkSafe check/);
        fireEvent.click(screen.getByLabelText('Potentially notifiable'));
        fireEvent.click(
            screen.getByRole('combobox', { name: 'WorkSafe status' }),
        );
        fireEvent.click(
            screen.getByRole('option', { name: 'WorkSafe notified' }),
        );
        fireEvent.change(screen.getByLabelText('WorkSafe reference'), {
            target: { value: 'WS-2026-7788' },
        });
        fireEvent.click(screen.getByLabelText('Site preserved'));

        openReviewStep();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(postedPayload()).toEqual(
            expect.objectContaining({
                occurred_at: '2026-07-13T14:35',
                severity: 'high',
                reported_severity: 'critical',
                harm_or_injury: 'A bruised shoulder was observed.',
                consequence: 'Urgent clinical review was required.',
                is_notifiable: true,
                worksafe_notification_status: 'notified',
                worksafe_reference: 'WS-2026-7788',
                site_preserved: true,
            }),
        );
    });

    it('keeps ordinary High distinct from Critical provenance', () => {
        renderDialog('incidents', {
            defaults: { ...defaults, severity: 'high' },
        });
        openReviewStep();

        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(postedPayload().severity).toBe('high');
        expect(postedPayload().reported_severity).toBeNull();
    });

    it('mounts the real H&S wrapper with the canonical payload and no incident navigation', () => {
        renderDialog();
        openReviewStep();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );
        const canonicalPayload = postedPayload();

        cleanup();
        inertia.post.mockClear();
        inertia.nextResult = {
            result: 'submitted',
            incident_reference: 'INC-2026-0042',
            incident_url: '/incidents/42',
        };
        renderHealthSafetyDialog();
        openReviewStep();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );
        const healthSafetyPayload = postedPayload();

        expect({
            ...healthSafetyPayload,
            report_request_uuid: 'stable',
        }).toEqual({ ...canonicalPayload, report_request_uuid: 'stable' });
        expect(
            screen.queryByRole('button', { name: 'Open incident' }),
        ).not.toBeInTheDocument();
    });

    it('omits assignment controls and assignee IDs without explicit capability', () => {
        renderHealthSafetyDialog({
            ...defaults,
            followups: [
                {
                    notes: 'Update the care plan.',
                    assigned_to_user_id: '81',
                    due_at: '2026-07-15',
                },
            ],
        });

        openStep(/Follow-ups/);
        expect(screen.queryByText('Assign to')).not.toBeInTheDocument();
        openReviewStep();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(postedPayload().followups).toEqual([
            {
                notes: 'Update the care plan.',
                due_at: '2026-07-15',
            },
        ]);
    });

    it('shows and sends assignee IDs only with explicit follow-up capability', () => {
        renderDialog('incidents', {
            canManageFollowups: true,
            staff: [{ id: 81, name: 'Moana Rangi' }],
            defaults: {
                ...defaults,
                followups: [
                    {
                        notes: 'Update the care plan.',
                        assigned_to_user_id: '81',
                        due_at: '2026-07-15',
                    },
                ],
            },
        });

        openStep(/Follow-ups/);
        expect(screen.getByText('Assign to')).toBeInTheDocument();
        openReviewStep();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(postedPayload().followups).toEqual([
            {
                notes: 'Update the care plan.',
                assigned_to_user_id: 81,
                due_at: '2026-07-15',
            },
        ]);
    });

    it('promises only incident reporting from the H&S launcher tile', () => {
        render(
            <ReportLauncher open onClose={() => {}} onWorkflow={() => {}} />,
        );

        expect(
            screen.getByRole('button', { name: /Report incident/i }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Report incident \/ near-miss/i),
        ).not.toBeInTheDocument();
    });

    it('disables both result actions coherently while pending', () => {
        inertia.processing = true;
        renderDialog();
        openReviewStep();

        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Submit incident' }),
        ).toBeDisabled();
    });

    it('preserves entered form state when the server returns an error', () => {
        inertia.nextError = { description: 'Please add more detail.' };
        renderDialog();
        openReviewStep();

        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );
        const whatButton = screen
            .getAllByRole('button')
            .find((button) => /What happened/.test(button.textContent ?? ''));
        fireEvent.click(whatButton!);

        expect(
            screen.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('Aroha slipped beside the dining table.');
        expect(screen.getAllByText('Please add more detail.')).toHaveLength(2);
    });

    it('jumps to the first invalid step and keeps every error in the summary', () => {
        inertia.nextError = {
            'followups.0.assigned_to_user_id':
                'The selected follow-up assignee is unavailable.',
            shift_id: 'The selected shift does not match this incident.',
            report_request_uuid:
                'The selected shift does not match this incident.',
            description: '',
        };
        renderDialog();
        openReviewStep();

        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Type & people' }),
        ).toBeInTheDocument();
        const summary = screen.getByRole('alert');
        expect(
            within(summary).getAllByText(
                'The selected shift does not match this incident.',
            ),
        ).toHaveLength(1);
        expect(
            within(summary).getByText(
                'The selected follow-up assignee is unavailable.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText(
                'The selected shift does not match this incident.',
            ),
        ).toHaveLength(2);

        openStep(/What happened/);
        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: 'Description' }),
        ).toHaveValue('Aroha slipped beside the dining table.');
    });

    it('jumps to Follow-ups and renders nested assignee errors inline', () => {
        inertia.nextError = {
            'followups.0.assigned_to_user_id':
                'The selected follow-up assignee is unavailable.',
        };
        renderDialog('incidents', {
            canManageFollowups: true,
            staff: [{ id: 81, name: 'Moana Rangi' }],
            defaults: {
                ...defaults,
                followups: [
                    {
                        notes: 'Update the care plan.',
                        assigned_to_user_id: '81',
                        due_at: '2026-07-15',
                    },
                ],
            },
        });
        openReviewStep();

        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Follow-ups' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(
            screen.getAllByText(
                'The selected follow-up assignee is unavailable.',
            ),
        ).toHaveLength(2);
    });

    it('shows a truthful Draft saved result without claiming H&S handover', () => {
        inertia.nextResult = {
            result: 'draft',
            incident_reference: 'INC-2026-0042',
            incident_url: '/incidents/42',
        };
        renderDialog();
        openReviewStep();

        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(
            screen.getByRole('heading', { name: 'Draft saved' }),
        ).toBeInTheDocument();
        expect(screen.getByText('INC-2026-0042')).toBeInTheDocument();
        expect(
            screen.queryByText(/Awaiting H&S acceptance/i),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('INC-42')).not.toBeInTheDocument();
    });

    it('shows official references and icon plus text for the submitted H&S handover state', () => {
        inertia.nextResult = {
            result: 'submitted',
            incident_reference: 'INC-2026-0042',
            hs_reference: 'HS-2026-0017',
            handover_state: 'awaiting_hs_acceptance',
            incident_url: '/incidents/42',
        };
        renderDialog();
        openReviewStep();

        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Incident submitted' }),
        ).toBeInTheDocument();
        expect(screen.getByText('INC-2026-0042')).toBeInTheDocument();
        expect(screen.getByText('HS-2026-0017')).toBeInTheDocument();
        const status = screen.getByText('Awaiting H&S acceptance');
        expect(status).toBeInTheDocument();
        expect(status.parentElement?.querySelector('svg')).not.toBeNull();
        expect(screen.queryByText('INC-42')).not.toBeInTheDocument();
    });
});
