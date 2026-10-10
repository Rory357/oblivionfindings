import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { webcrypto } from 'node:crypto';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TemplateDetailDialog, TemplateWizardDialog } from './template-dialogs';
import { TemplateLibrary } from './template-library';
import {
    TemplatesPane,
    type RosterTemplateRow,
    type TemplateCapabilities,
} from './templates-pane';
import type {
    TemplateCommand,
    TemplateLibraryData,
} from './use-template-command';
import { templateHash } from './use-template-command';
const transport = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
    on: vi.fn(() => vi.fn()),
    props: {} as Record<string, unknown>,
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: transport,
        usePage: () => ({ props: transport.props }),
        useForm: (initial: Record<string, unknown>) => {
            const original = React.useRef(initial),
                [data, setData] = React.useState(initial),
                [errors, setErrors] = React.useState<Record<string, string>>(
                    {},
                );
            return {
                data,
                errors,
                processing: false,
                isDirty:
                    JSON.stringify(data) !== JSON.stringify(original.current),
                clearErrors: () => setErrors({}),
                setError: setErrors,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                transform: vi.fn(),
                post: vi.fn(),
            };
        },
    };
});
vi.mock('@/components/people-locations/record-picker', () => ({
    RecordPicker: ({
        label,
        value,
        options,
        onChange,
    }: {
        label: string;
        value: string;
        options: { value: string; label: string }[];
        onChange: (value: string) => void;
    }) => (
        <label>
            {label}
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">Choose</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </label>
    ),
}));
vi.mock('@/components/fleet-assets/maintenance/time-picker', () => ({
    TimePicker: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <label>
            {label}
            <input
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    ),
}));
const clients = [
    { id: 10, name: 'Person North', site_id: 1, service_context_id: 20 },
];
const staff = [{ id: 30, name: 'Support worker' }];
const serviceContexts = [{ id: 20, name: 'House support', site_id: 1 }];
const template: RosterTemplateRow = {
    id: 1,
    source_revision: 'a'.repeat(64),
    capabilities: {
        can_view: true,
        can_create: true,
        can_edit: true,
        can_duplicate: true,
        can_delete: true,
        can_apply: true,
    },
    urls: {
        update: '/update',
        delete: '/delete',
        duplicate: '/copy',
        apply: '/apply',
    },
    name: 'Overnight house pattern',
    description: 'Retain support details',
    template_type: 'weekly',
    is_active: true,
    template_shifts_count: 1,
    template_shifts: [
        {
            id: 11,
            client_id: 10,
            user_id: 30,
            service_context_id: 20,
            day_of_week: 0,
            start_time: '22:15:00',
            end_time: '07:05:00',
            shift_type: 'sleepover',
            is_sleepover: true,
            is_on_call: false,
            is_lone_worker: true,
            expected_break_minutes: 35,
            required_skills: ['Hoist'],
            location: 'North house',
            notes: 'Keep handover detail',
            client: { id: 10, first_name: 'Person', last_name: 'North' },
            user: staff[0],
            service_context: serviceContexts[0],
        },
    ],
};
const readOnly: TemplateCapabilities = {
    can_view: true,
    can_create: false,
    can_edit: false,
    can_duplicate: false,
    can_apply: false,
    can_delete: false,
};

const onOpenChange = vi.fn();
function command(overrides: Partial<TemplateCommand> = {}): TemplateCommand {
    return {
        submit: vi.fn(async () => undefined),
        refresh: vi.fn(),
        activity: null,
        notice: null,
        needsRead: false,
        busy: false,
        blocked: false,
        isBusy: () => false,
        ...overrides,
    };
}
const wizardProps = {
    open: true,
    onOpenChange,
    template,
    clients,
    staff,
    serviceContexts,
    workerTimezone: 'Pacific/Auckland',
};
const openReview = () =>
    fireEvent.click(screen.getByRole('button', { name: /^Review/ }));
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', webcrypto);
    transport.props = {
        auth: { user: { id: 7 } },
        rosterTemplates: [template],
        templateCapabilities: template.capabilities,
        templateOptions: { clients, staff, serviceContexts },
        workerTimezone: 'Pacific/Auckland',
        errors: {},
        flash: {},
    };
});
describe('template library dialogs', () => {
    it('does not present the previous command as confirmation of a new edit', () => {
        const actions = command({
            notice: { kind: 'confirmed', message: 'Previous template saved.' },
        });
        render(<TemplateWizardDialog {...wizardProps} command={actions} />);
        fireEvent.change(screen.getByRole('textbox', { name: 'Description' }), {
            target: { value: 'New unsaved guidance' },
        });
        openReview();
        expect(screen.getByText('New unsaved guidance')).toBeInTheDocument();
        expect(
            screen.queryByText('Template confirmed'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Previous template saved.'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Done' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save changes' }),
        ).toBeEnabled();
        expect(actions.submit).not.toHaveBeenCalled();
    });
    it('reviews and submits every saved support field without shifting overnight minutes', () => {
        const actions = command();
        render(<TemplateWizardDialog {...wizardProps} command={actions} />);
        openReview();
        for (const value of [
            'Person North',
            'Support worker',
            'House support',
            '22:15–07:05 (ends next day)',
            '35 minutes',
            'Hoist',
            'North house',
            'Keep handover detail',
            'Pacific/Auckland',
        ])
            expect(screen.getAllByText(value).length).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        const intent = vi.mocked(actions.submit).mock.calls[0][0];
        expect(intent).toEqual({
            action: 'update',
            source: { template_id: 1, source_revision: 'a'.repeat(64) },
            rowCount: 1,
            values: {
                name: template.name,
                description: template.description,
                template_type: 'weekly',
                is_active: true,
                template_shifts: [
                    {
                        client_id: 10,
                        user_id: 30,
                        service_context_id: 20,
                        day_of_week: 0,
                        start_time: '22:15',
                        end_time: '07:05',
                        shift_type: 'sleepover',
                        is_sleepover: true,
                        is_on_call: false,
                        is_lone_worker: true,
                        expected_break_minutes: 35,
                        required_skills: ['Hoist'],
                        location: 'North house',
                        notes: 'Keep handover detail',
                    },
                ],
            },
        });
        expect(onOpenChange).not.toHaveBeenCalled();
    });
    it('keeps the draft on validation errors and exposes the precise field message', () => {
        const actions = command();
        render(<TemplateWizardDialog {...wizardProps} command={actions} />);
        openReview();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        act(() =>
            vi.mocked(actions.submit).mock.calls[0][2]?.({
                'template_shifts.0.user_id':
                    'This worker is no longer available to your sites.',
            }),
        );
        expect(
            screen.getByText(
                'This worker is no longer available to your sites.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Shift row 1 start time')).toHaveValue(
            '22:15',
        );
        expect(screen.getByLabelText('Shift row 1 staff member')).toHaveValue(
            '30',
        );
        expect(onOpenChange).not.toHaveBeenCalled();
    });
    it('shows success only when the command confirms it and keeps the save distinct from applying shifts', () => {
        const actions = command();
        render(<TemplateWizardDialog {...wizardProps} command={actions} />);
        openReview();
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        act(() =>
            vi.mocked(actions.submit).mock.calls[0][1]?.({
                action: 'update',
                templateId: 1,
                copyId: null,
                outcome: 'saved',
                resultRevision: 'b'.repeat(64),
                rowCount: 1,
            }),
        );
        expect(screen.getByText('Template updated')).toBeInTheDocument();
        expect(
            screen.getByText(/saving the pattern does not create shifts/),
        ).toBeInTheDocument();
        expect(onOpenChange).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });
    it('prevents close during a pending command and asks before discarding a changed draft', () => {
        const actions = command();
        const rendered = render(
            <TemplateWizardDialog {...wizardProps} command={actions} />,
        );
        fireEvent.change(
            screen.getByPlaceholderText('e.g. North House weekday support'),
            { target: { value: 'Changed pattern' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByText('Discard template changes?'),
        ).toBeInTheDocument();
        expect(onOpenChange).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        rendered.rerender(
            <TemplateWizardDialog
                {...wizardProps}
                command={command({
                    busy: true,
                    blocked: true,
                    isBusy: () => true,
                    activity: 'command',
                })}
            />,
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(
            screen.getByPlaceholderText('e.g. North House weekday support'),
        ).toHaveValue('Changed pattern');
    });
    it('retains an uncertain draft through reload and requires checking the current templates before another attempt', () => {
        const actions = command();
        const rendered = render(
            <TemplateWizardDialog {...wizardProps} command={actions} />,
        );
        fireEvent.change(
            screen.getByPlaceholderText('e.g. North House weekday support'),
            { target: { value: 'My unsent changes' } },
        );
        const held = command({
            needsRead: true,
            blocked: true,
            notice: { kind: 'unknown', message: 'Result unknown.' },
        });
        rendered.rerender(
            <TemplateWizardDialog {...wizardProps} command={held} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reload library' }));
        const current: TemplateLibraryData = {
            rosterTemplates: [template],
            templateCapabilities: template.capabilities!,
            templateOptions: { clients, staff, serviceContexts },
            workerTimezone: 'Pacific/Auckland',
        };
        act(() => vi.mocked(held.refresh).mock.calls[0][0]?.(current));
        rendered.rerender(
            <TemplateWizardDialog
                {...wizardProps}
                command={command({
                    notice: { kind: 'read', message: 'Loaded.' },
                })}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Continue editing' }),
        ).toBeDisabled();
        expect(
            screen.getByPlaceholderText('e.g. North House weekday support'),
        ).toHaveValue('My unsent changes');
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue editing' }),
        );
        expect(
            screen.queryByText('Check the saved library'),
        ).not.toBeInTheDocument();
    });
    it('prevents resubmitting a draft over a changed saved pattern after recovery', () => {
        const held = command({
            needsRead: true,
            blocked: true,
            notice: { kind: 'unknown', message: 'Result unknown.' },
        });
        const rendered = render(
            <TemplateWizardDialog {...wizardProps} command={held} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reload library' }));
        act(() =>
            vi.mocked(held.refresh).mock.calls[0][0]?.({
                rosterTemplates: [
                    { ...template, source_revision: 'b'.repeat(64) },
                ],
                templateCapabilities: template.capabilities!,
                templateOptions: { clients, staff, serviceContexts },
                workerTimezone: 'Pacific/Auckland',
            }),
        );
        rendered.rerender(
            <TemplateWizardDialog
                {...wizardProps}
                command={command({
                    notice: { kind: 'read', message: 'Loaded.' },
                })}
            />,
        );
        expect(
            screen.getByText(/The saved template has changed/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Continue editing' }),
        ).not.toBeInTheDocument();
        openReview();
        expect(
            screen.getByRole('button', { name: 'Save changes' }),
        ).toBeDisabled();
    });
    it('shows view-only users the complete detail without edit/delete/apply controls', () => {
        render(
            <TemplateDetailDialog
                open
                onOpenChange={onOpenChange}
                template={template}
                canManage={false}
                canDelete={false}
                canApply={false}
                actionsBlocked={false}
                onEdit={vi.fn()}
                onDelete={vi.fn()}
            />,
        );
        expect(screen.getByText('Keep handover detail')).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Close' })).toHaveLength(
            1,
        );
        expect(
            screen.getByText(
                'Review the saved template rows and support details.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Delete' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Apply to roster' }),
        ).not.toBeInTheDocument();
    });
    it('keeps card permissions separate and does not turn nested button keypresses into card activation', () => {
        const onView = vi.fn(),
            onlyCopy = { ...readOnly, can_create: true, can_duplicate: true };
        render(
            <TemplatesPane
                templates={[{ ...template, capabilities: onlyCopy }]}
                capabilities={onlyCopy}
                onCreate={vi.fn()}
                onView={onView}
                onEdit={vi.fn()}
                onDelete={vi.fn()}
                onDuplicate={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'View template' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit' }),
        ).not.toBeInTheDocument();
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Template actions' }),
            { key: 'Enter' },
        );
        expect(onView).not.toHaveBeenCalled();
    });
});

describe('shared library entry point', () => {
    it('returns keyboard focus to the template opener when its detail closes', async () => {
        transport.props = {
            ...transport.props,
            templateCapabilities: readOnly,
            rosterTemplates: [{ ...template, capabilities: readOnly }],
        };
        render(<TemplateLibrary standalone />);
        const opener = screen.getByRole('button', { name: 'View template' });
        opener.focus();
        fireEvent.click(opener);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        await waitFor(() => expect(opener).toHaveFocus());
        expect(transport.visit).not.toHaveBeenCalled();
    });
    it.each([false, true])(
        'preserves the selected week when deleting from standalone=%s',
        async (standalone) => {
            transport.props = {
                ...transport.props,
                weekStart: '2026-10-12',
                week: '2026-10-19',
            };
            render(<TemplateLibrary standalone={standalone} />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Review & apply' }),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
            fireEvent.click(
                screen.getByRole('button', { name: 'Delete template' }),
            );
            await waitFor(() =>
                expect(transport.visit).toHaveBeenCalledTimes(1),
            );
            expect(transport.visit.mock.calls[0][1].data.week).toBe(
                standalone ? '2026-10-19' : '2026-10-12',
            );
        },
    );

    it('keeps deletion open on an unconfirmed response and blocks duplicate sends', async () => {
        render(<TemplateLibrary />);
        fireEvent.click(screen.getByRole('button', { name: 'Review & apply' }));
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Delete template' }),
            );
        });
        await waitFor(() => expect(transport.visit).toHaveBeenCalledTimes(1));
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        await waitFor(() => expect(transport.visit).toHaveBeenCalledTimes(1));
        const options = transport.visit.mock.calls[0][1];
        act(() => {
            options.onSuccess({
                props: { ...transport.props, flash: { success: 'Deleted' } },
            });
            options.onFinish();
        });
        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Delete template' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Reload library' }),
        ).toBeInTheDocument();
    });
    it('closes deletion only for the actor-bound committed outcome and current list', async () => {
        const rendered = render(<TemplateLibrary />);
        fireEvent.click(screen.getByRole('button', { name: 'Review & apply' }));
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Delete template' }),
            );
        });
        await waitFor(() => expect(transport.visit).toHaveBeenCalledTimes(1));
        const options = transport.visit.mock.calls[0][1];
        const intent = {
            action: 'delete' as const,
            source: options.data.expected_source,
            values: null,
            rowCount: 1,
        };
        const receipt = {
            version: 1,
            scope: 'library',
            action: 'delete',
            actor_id: 7,
            request_id: options.data.request_id,
            template_id: 1,
            copy_id: null,
            expected_source: intent.source,
            source_revision: template.source_revision,
            result_revision: null,
            outcome: 'deleted',
            changed: true,
            template_shifts_count: 1,
            values_hash: await templateHash(intent),
            committed_at: '2026-10-08T01:00:00.000Z',
        };
        const response = {
            props: {
                ...transport.props,
                rosterTemplates: [],
                flash: { roster_template_result: receipt },
            },
        };
        act(() => {
            transport.props = response.props;
            options.onSuccess(response);
            options.onFinish();
        });
        rendered.rerender(<TemplateLibrary />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(
            screen.getByText(/Template deleted from the library/),
        ).toBeInTheDocument();
        expect(screen.getByText('No roster templates yet')).toBeInTheDocument();
    });
    it('retains an edited draft when a failed response omits the library props', async () => {
        const rendered = render(<TemplateLibrary />);
        fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
        fireEvent.change(
            screen.getByPlaceholderText('e.g. North House weekday support'),
            { target: { value: 'Still my draft' } },
        );
        openReview();
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save changes' }),
            );
        });
        await waitFor(() => expect(transport.visit).toHaveBeenCalledTimes(1));
        const options = transport.visit.mock.calls[0][1];
        act(() => {
            transport.props = {
                auth: { user: { id: 7 } },
                errors: { name: 'Review the name.' },
            };
            options.onError({ name: 'Review the name.' });
        });
        rendered.rerender(<TemplateLibrary />);
        expect(
            screen.getByPlaceholderText('e.g. North House weekday support'),
        ).toHaveValue('Still my draft');
        expect(screen.getAllByText('Review the name.').length).toBeGreaterThan(
            0,
        );
        expect(
            screen.getByRole('button', { name: 'Reload library' }),
        ).toBeInTheDocument();
    });
});
