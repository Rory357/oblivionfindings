import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { clearItTicketDraftMemory } from '@/hooks/use-it-ticket-draft-memory';
import { ItWizard, type RequestRow, type TicketRow } from '../it-wizards';
import type { TicketIntakePolicy } from '../ticket-intake-fields';

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();
    return {
        ...actual,
        usePage: () => ({ props: { auth: { user: { id: 230 } } } }),
    };
});

const sites = [
    { id: 9403, name: 'Approved Site A' },
    { id: 9404, name: 'Approved Site B' },
];
const policy: TicketIntakePolicy = {
    matrix_version: 1,
    priority_matrix: {
        individual: {
            low: 'low',
            normal: 'normal',
            high: 'high',
            critical: 'urgent',
        },
        team: {
            low: 'normal',
            normal: 'normal',
            high: 'high',
            critical: 'urgent',
        },
        site: {
            low: 'normal',
            normal: 'high',
            high: 'urgent',
            critical: 'urgent',
        },
        organization: {
            low: 'high',
            normal: 'high',
            high: 'urgent',
            critical: 'urgent',
        },
    },
};
const assignees = [
    { id: 10, name: 'Site A technician', site_ids: [9403] },
    { id: 11, name: 'Site B technician', site_ids: [9404] },
    {
        id: 12,
        name: 'Organisation-wide only',
        site_ids: [],
        organisation_wide: true,
    },
    { id: 13, name: 'Missing Site scope' },
];
const props = (type: 'raise' | 'ticket'): ComponentProps<typeof ItWizard> => ({
    modal: { type },
    assignees: type === 'ticket' ? assignees : [],
    siteOptions: sites,
    intakePolicy: policy,
    onClose: vi.fn(),
});
function options(label: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name: label }), {
        key: 'ArrowDown',
    });
}
function choose(label: string, option: string) {
    options(label);
    fireEvent.click(screen.getByRole('option', { name: option }));
}
function startTechnician() {
    choose('Affected Site', 'Approved Site A');
    fireEvent.change(
        screen.getByPlaceholderText('e.g. Printer offline — Sunnyside Lodge'),
        { target: { value: 'Synthetic scoped triage' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

describe('Canonical ticket intake assessment', () => {
    beforeEach(() => {
        clearItTicketDraftMemory();
        sessionStorage.clear();
        vi.spyOn(axios, 'post').mockRejectedValue({
            isAxiosError: true,
            response: { status: 500 },
        });
    });
    afterEach(() => vi.restoreAllMocks());

    it('lets a requester select the second approved Site and preserves dimensional intake on same-request retry', async () => {
        render(<ItWizard {...props('raise')} />);
        options('Affected Site');
        expect(
            screen.getAllByRole('option').map((option) => option.textContent),
        ).toEqual([
            'Choose the affected Site',
            'Approved Site A',
            'Approved Site B',
        ]);
        fireEvent.click(
            screen.getByRole('option', { name: 'Approved Site B' }),
        );
        fireEvent.change(
            screen.getByPlaceholderText('e.g. My work phone won’t charge'),
            { target: { value: 'Synthetic site request' } },
        );
        choose('Who is affected?', 'A whole Site');
        choose('How urgent is it?', 'Work is blocked');
        fireEvent.click(screen.getByRole('button', { name: 'Raise ticket' }));
        await screen.findByRole('button', { name: 'Retry same request' });
        const submitted = vi.mocked(axios.post).mock.calls[0][1] as FormData;
        expect(Object.fromEntries(submitted)).toMatchObject({
            site_id: '9404',
            impact: 'site',
            urgency: 'high',
            title: 'Synthetic site request',
        });
        expect(submitted.has('priority')).toBe(false);
        expect(submitted.has('assigned_to_user_id')).toBe(false);
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry same request' }),
        );
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
        expect(
            Object.fromEntries(
                vi.mocked(axios.post).mock.calls[1][1] as FormData,
            ),
        ).toEqual(Object.fromEntries(submitted));
    });

    it('blocks missing or revoked Site choices and conceals the requester draft on a server access denial', async () => {
        const original = props('raise');
        const { rerender } = render(<ItWizard {...original} />);
        choose('Affected Site', 'Approved Site B');
        fireEvent.change(
            screen.getByPlaceholderText('e.g. My work phone won’t charge'),
            { target: { value: 'Private requester draft' } },
        );
        rerender(<ItWizard {...original} siteOptions={[sites[0]]} />);
        expect(
            screen.getByRole('button', { name: 'Raise ticket' }),
        ).toBeDisabled();
        choose('Affected Site', 'Approved Site A');
        vi.mocked(axios.post).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403 },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Raise ticket' }));
        await screen.findByText(/Your access has changed/);
        expect(
            screen.queryByDisplayValue('Private requester draft'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('combobox', { name: 'Affected Site' }),
        ).not.toBeInTheDocument();
    });

    it('shows a useful setup gap when no approved Site is available', () => {
        render(<ItWizard {...props('raise')} siteOptions={[]} />);
        expect(screen.getByText(/No approved Site is available/)).toBeVisible();
        fireEvent.change(
            screen.getByPlaceholderText('e.g. My work phone won’t charge'),
            { target: { value: 'Cannot submit without scope' } },
        );
        expect(
            screen.getByRole('button', { name: 'Raise ticket' }),
        ).toBeDisabled();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('selects an ordinary approved employee by user identity and retains a now-ineligible choice for correction', async () => {
        render(
            <ItWizard
                {...props('ticket')}
                employeeOptions={[
                    {
                        id: 501,
                        name: 'Ordinary Site A employee',
                        requester: { user_id: 88, site_ids: [9403] },
                    },
                    {
                        id: 502,
                        name: 'Other Site employee',
                        requester: { user_id: 89, site_ids: [9404] },
                    },
                    { id: 503, name: 'Profile without an eligible account' },
                ]}
            />,
        );
        choose('Affected Site', 'Approved Site A');
        options('Requester');
        expect(
            screen.queryByRole('option', { name: 'Site A technician' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Other Site employee' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('option', {
                name: 'Profile without an eligible account',
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('option', { name: 'Ordinary Site A employee' }),
        );
        fireEvent.change(
            screen.getByPlaceholderText(
                'e.g. Printer offline — Sunnyside Lodge',
            ),
            { target: { value: 'Ordinary employee help' } },
        );
        choose('Affected Site', 'Approved Site B');
        expect(
            screen.getByText(
                /The selected requester is unavailable for this Site/,
            ),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        choose('Affected Site', 'Approved Site A');
        expect(
            screen.getByRole('combobox', { name: 'Requester' }),
        ).toHaveTextContent('Ordinary Site A employee');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('Ordinary Site A employee')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Log ticket' }));
        await screen.findByRole('button', { name: 'Retry same request' });
        expect(
            (vi.mocked(axios.post).mock.calls[0][1] as FormData).get(
                'requester_user_id',
            ),
        ).toBe('88');
    });

    it('previews the supplied policy and requires a reason before posting an explicit priority override', async () => {
        const original = props('ticket');
        const { rerender } = render(<ItWizard {...original} />);
        startTechnician();
        choose('Who is affected?', 'A whole Site');
        expect(screen.getByText('High', { selector: 'strong' })).toBeVisible();
        rerender(
            <ItWizard
                {...original}
                intakePolicy={{
                    ...policy,
                    priority_matrix: {
                        ...policy.priority_matrix,
                        site: {
                            ...policy.priority_matrix.site,
                            normal: 'urgent',
                        },
                    },
                }}
            />,
        );
        expect(
            screen.getByText('Urgent', { selector: 'strong' }),
        ).toBeVisible();
        choose('Priority decision', 'Set low priority');
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        fireEvent.change(
            screen.getByLabelText(/Why change the assessed priority/),
            {
                target: {
                    value: 'Operational review confirms a temporary workaround.',
                },
            },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Log ticket' }));
        await screen.findByRole('button', { name: 'Retry same request' });
        expect(
            Object.fromEntries(
                vi.mocked(axios.post).mock.calls[0][1] as FormData,
            ),
        ).toMatchObject({
            impact: 'site',
            urgency: 'normal',
            priority: 'low',
            priority_reason:
                'Operational review confirms a temporary workaround.',
        });
    });

    it.each([
        { enabled: true, unit: 'working minutes' },
        { enabled: false, unit: 'minutes' },
    ])(
        'shows configured SLA targets without promising a browser-clock deadline when calendar is $enabled',
        ({ enabled, unit }) => {
            render(
                <ItWizard
                    {...props('ticket')}
                    slaPolicies={{
                        normal: {
                            first_response_minutes: 600,
                            resolution_minutes: 2400,
                            is_custom: true,
                        },
                    }}
                    slaCalendar={{
                        enabled,
                        open_time: '08:00',
                        close_time: '17:00',
                        working_days: ['mon', 'tue', 'wed', 'thu', 'fri'],
                        holiday_dates: ['2026-09-28'],
                    }}
                />,
            );
            startTechnician();
            expect(
                screen.getByText(`600 ${unit}`, { selector: 'strong' }),
            ).toBeVisible();
            expect(
                screen.getByText(`2,400 ${unit}`, { selector: 'strong' }),
            ).toBeVisible();
            expect(
                screen.getByText(/Deadlines are calculated when saved/),
            ).toBeVisible();
            expect(
                screen.queryByText(/First response due/),
            ).not.toBeInTheDocument();

            fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
            expect(screen.getByText('Resolution target')).toBeVisible();
            expect(
                screen.getByText(
                    `2,400 ${unit} · Deadline calculated when saved`,
                ),
            ).toBeVisible();
            expect(
                screen.queryByText('Resolution due'),
            ).not.toBeInTheDocument();
            expect(axios.post).not.toHaveBeenCalled();
        },
    );

    it('only offers technicians approved for the selected Site and records a reason for manual assignment', async () => {
        render(<ItWizard {...props('ticket')} />);
        startTechnician();
        options('Unassigned');
        expect(
            screen.getAllByRole('option').map((option) => option.textContent),
        ).toEqual(['Unassigned', 'Site A technician']);
        fireEvent.click(
            screen.getByRole('option', { name: 'Site A technician' }),
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Why this technician/), {
            target: {
                value: 'The technician is handling the affected device.',
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Log ticket' }));
        await screen.findByRole('button', { name: 'Retry same request' });
        const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
        expect(body.get('assigned_to_user_id')).toBe('10');
        expect(body.get('routing_reason')).toBe(
            'The technician is handling the affected device.',
        );
        expect(body.has('priority')).toBe(false);
    });

    it('clears an incompatible manual assignee and reason when the affected Site changes', () => {
        render(<ItWizard {...props('ticket')} />);
        startTechnician();
        choose('Unassigned', 'Site A technician');
        fireEvent.change(screen.getByLabelText(/Why this technician/), {
            target: { value: 'Approved at Site A only.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        choose('Affected Site', 'Approved Site B');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.queryByLabelText(/Why this technician/),
        ).not.toBeInTheDocument();
        options('Unassigned');
        expect(
            screen.getAllByRole('option').map((option) => option.textContent),
        ).toEqual(['Unassigned', 'Site B technician']);
    });

    it('requires and preserves the ticket assignment reason and version on server rejection', async () => {
        const patch = vi.spyOn(router, 'patch').mockImplementation(() => {});
        const ticket = {
            id: 14,
            lock_version: 3,
            title: 'Scoped existing ticket',
            assignee: null,
        } as TicketRow;
        render(
            <ItWizard
                {...props('ticket')}
                modal={{ type: 'assign-ticket', ticket }}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Site A technician/ }),
        );
        expect(screen.getByRole('button', { name: 'Assign' })).toBeDisabled();
        fireEvent.change(screen.getByLabelText(/Reason for assignment/), {
            target: { value: 'Providing approved cover.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Assign' }));
        expect(patch).toHaveBeenCalledWith(
            '/it/tickets/14',
            {
                assigned_to_user_id: 10,
                routing_reason: 'Providing approved cover.',
                expected_version: 3,
            },
            expect.any(Object),
        );
        await act(async () => {
            patch.mock.calls[0][2]?.onError?.({
                assigned_to_user_id: 'This technician is no longer eligible.',
            });
        });
        expect(
            screen.getByText('This technician is no longer eligible.'),
        ).toBeVisible();
        expect(
            screen.getByDisplayValue('Providing approved cover.'),
        ).toBeVisible();
        expect(screen.queryByText('Owner assigned')).not.toBeInTheDocument();
    });

    it('keeps provisioning assignment on its existing payload contract', () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        const request = {
            id: 8,
            item: 'Existing provisioning item',
            assignee: null,
        } as RequestRow;
        render(
            <ItWizard
                {...props('ticket')}
                modal={{ type: 'assign-request', request }}
            />,
        );
        expect(
            screen.queryByLabelText(/Reason for assignment/),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Site A technician/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Assign' }));
        expect(post).toHaveBeenCalledWith(
            '/it/provisioning/8/assign',
            { assigned_to_user_id: 10 },
            expect.any(Object),
        );
    });
});
