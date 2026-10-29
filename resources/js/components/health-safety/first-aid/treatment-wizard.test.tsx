import type { WizardShell } from '@/components/wizard/shell';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    FirstAidTreatmentWizard,
    firstAidTreatmentDraft,
    firstAidTreatmentPayload,
} from './treatment-wizard';
import type { FirstAidPickers, FirstAidRecord } from './types';

vi.mock('axios', () => ({
    default: { get: vi.fn(), request: vi.fn(), isAxiosError: () => false },
}));
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { on: () => () => {} },
}));
vi.mock('@/components/wizard/shell', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/components/wizard/shell')>()),
    WizardShell: (props: ComponentProps<typeof WizardShell>) => (
        <section aria-label={props.title}>
            {props.success ?? (
                <>
                    {props.children}
                    <footer>
                        {props.footerStart}
                        {props.footerEnd}
                    </footer>
                </>
            )}
        </section>
    ),
}));
const pickers: FirstAidPickers = {
    sites: [{ id: 2, name: 'Approved Site' }],
    staff: [{ id: 6, name: 'Current staff member' }],
    clients: [{ id: 8, name: 'Approved Client', site_id: 2 }],
    first_aiders: [
        {
            id: 7,
            name: 'Current first aider',
            site_ids: [2],
            eligibility_state: 'current_hr_flag',
        },
    ],
    followup_assignees: [],
    incidents: [],
    person_types: [
        { value: 'staff', label: 'Staff' },
        { value: 'client', label: 'Client' },
    ],
    injury_types: [{ value: 'cut', label: 'Cut' }],
    outcomes: [
        { value: 'returned_to_activity', label: 'Returned to activity' },
    ],
    attachment_kinds: [],
};
const detail = {
    id: 9,
    expected_version: 4,
    lock_version: 4,
    status: 'recorded',
    site_id: 2,
    site: { id: 2, name: 'Approved Site' },
    treated_person_type: 'staff',
    treated_person_id: null,
    treated_person_name: 'Historical name',
    client_id: null,
    treatment_date: '2026-02-03T01:23:47+00:00',
    first_aider_id: 7,
    first_aider: { id: 7, name: 'Current first aider' },
    injury_illness_type: 'cut',
    injury_illness_description: 'Recorded injury',
    body_part: null,
    treatment_given: 'Recorded treatment',
    treatment_outcome: 'returned_to_activity',
    ambulance_called: false,
    first_aider_notes: null,
    incident_reported: false,
    related_incident: null,
    can: { edit: true },
} as unknown as FirstAidRecord;
beforeEach(() => {
    vi.mocked(axios.get).mockReset().mockResolvedValue({ data: pickers });
    vi.mocked(axios.request).mockReset();
});
afterEach(cleanup);
const choose = async (name: string, value: string) => {
    fireEvent.keyDown(screen.getByRole('combobox', { name }), {
        key: 'ArrowDown',
    });
    fireEvent.click(await screen.findByRole('option', { name: value }));
};
const next = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
async function fillNew() {
    await choose('Choose a site', 'Approved Site');
    await waitFor(() =>
        expect(
            screen.queryByText('Loading options…'),
        ).not.toBeInTheDocument(),
    );
    await choose('Choose a staff member or type a name', 'Enter a name');
    fireEvent.change(screen.getByLabelText("Person's name", { exact: false }), {
        target: { value: 'Recorded person' },
    });
    await choose('Choose the first aider', 'Current first aider');
    next();
    await choose('Choose an injury or illness', 'Cut');
    fireEvent.change(screen.getByLabelText('What happened', { exact: false }), {
        target: { value: 'Factual injury description' },
    });
    next();
    fireEvent.change(
        screen.getByLabelText('Treatment given', { exact: false }),
        { target: { value: 'Factual treatment recorded' } },
    );
    await choose('Choose an outcome', 'Returned to activity');
    next();
    next();
}
function accepted(config: { data?: unknown }, readable = true) {
    const data = config.data as Record<string, unknown>;
    return {
        data: {
            record_href: readable ? '/health-safety/first-aid/9' : null,
            first_aid_confirmation: {
                id: 9,
                lock_version: Number(data.expected_version ?? 0) + 1,
                idempotency_key: data.idempotency_key,
                command: data.expected_version ? 'update' : 'create',
                removed: false,
            },
        },
    };
}

it('omits unchanged legacy identity and the original treatment instant instead of backfilling or truncating them', () => {
    const legacy = {
        ...detail,
        treated_person_name: 'Person not recorded',
        first_aider_id: null,
    };
    const initial = firstAidTreatmentDraft(legacy);
    expect(
        firstAidTreatmentPayload(
            { ...initial, first_aider_notes: 'Correction note' },
            initial,
            legacy,
        ),
    ).toEqual({ first_aider_notes: 'Correction note' });
    expect(
        firstAidTreatmentPayload(
            { ...initial, body_part: '', ambulance_called: true },
            initial,
            legacy,
        ),
    ).toEqual({ ambulance_called: true });
    const changed = firstAidTreatmentPayload(
        {
            ...initial,
            treatment_date: '2026-02-04T14:23',
            time_offset: '+13:00',
        },
        initial,
        legacy,
    );
    expect(changed).toEqual({ treatment_date: '2026-02-04T14:23:00+13:00' });
});

it('retains the reviewed draft, version and command identity through wrong confirmation and an interrupted retry', async () => {
    vi.mocked(axios.request)
        .mockImplementationOnce(async (config) => {
            const response = accepted(config);
            response.data.first_aid_confirmation.idempotency_key =
                'wrong-command';
            return response;
        })
        .mockRejectedValueOnce(new Error('connection lost'))
        .mockImplementationOnce(async (config) => accepted(config));
    const onSaved = vi.fn();
    render(
        <FirstAidTreatmentWizard
            pickers={pickers}
            detail={detail}
            returnTo="/my-day"
            onClose={() => {}}
            onSaved={onSaved}
        />,
    );
    next();
    next();
    next();
    fireEvent.change(screen.getByLabelText('First aider notes'), {
        target: { value: 'Retain this correction' },
    });
    next();
    fireEvent.click(screen.getByRole('button', { name: 'Save correction' }));
    expect(
        await screen.findByText(/We can’t tell if this saved\. Your entries are still here\. Check the record/),
    ).toBeInTheDocument();
    expect(screen.getByText('Retain this correction')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save correction' }));
    expect(
        await screen.findByText(/You can try saving the same report again/),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save correction' }));
    await screen.findByText('Treatment record updated');
    const payloads = vi
        .mocked(axios.request)
        .mock.calls.map(([config]) => config.data);
    expect(payloads[1]).toEqual(payloads[0]);
    expect(payloads[2]).toEqual(payloads[0]);
    expect(payloads[0]).toMatchObject({
        first_aider_notes: 'Retain this correction',
        expected_version: 4,
    });
    expect(payloads[0]).not.toHaveProperty('treatment_date');
    expect(onSaved).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Done' }));
    expect(onSaved).toHaveBeenCalledOnce();
});

it('confirms create-only capture without loading the forbidden record and retains the receipt if the next form fails', async () => {
    vi.mocked(axios.request).mockImplementation(async (config) =>
        accepted(config, false),
    );
    render(
        <FirstAidTreatmentWizard
            pickers={pickers}
            returnTo="/dashboard"
            onClose={() => {}}
        />,
    );
    await fillNew();
    fireEvent.click(
        screen.getByRole('button', { name: 'Save treatment record' }),
    );
    await screen.findByText('Treatment record saved');
    expect(
        screen.queryByRole('link', { name: 'Open treatment record' }),
    ).not.toBeInTheDocument();
    expect(
        vi
            .mocked(axios.get)
            .mock.calls.every(
                ([url]) => url === '/health-safety/first-aid/options',
            ),
    ).toBe(true);
    vi.mocked(axios.get).mockRejectedValueOnce(new Error('options failed'));
    fireEvent.click(screen.getByRole('button', { name: 'Record another' }));
    expect(
        await screen.findByText(/The next form could not be loaded/),
    ).toBeInTheDocument();
    expect(screen.getByText('Treatment record saved')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Record another' }));
    await screen.findByText('Who received treatment?');
    expect(
        screen.getByLabelText("Person's name", { exact: false }),
    ).toHaveValue('');
    await fillNew();
    fireEvent.click(
        screen.getByRole('button', { name: 'Save treatment record' }),
    );
    await screen.findByText('Treatment record saved');
    const calls = vi.mocked(axios.request).mock.calls;
    expect(calls).toHaveLength(2);
    const first = calls[0][0].data as Record<string, unknown>;
    const second = calls[1][0].data as Record<string, unknown>;
    expect(second.idempotency_key).not.toBe(first.idempotency_key);
});

it('refreshes capture incidents for the explicit Client and Site, and opens the real optional incident selector', async () => {
    render(
        <FirstAidTreatmentWizard
            pickers={pickers}
            returnTo="/my-day"
            onClose={() => {}}
        />,
    );
    await choose('Choose a site', 'Approved Site');
    await choose('Choose a person type', 'Client');
    await choose('Choose a client', 'Approved Client');
    await waitFor(() =>
        expect(vi.mocked(axios.get).mock.lastCall?.[1]?.params).toEqual({
            site_id: '2',
            client_id: '8',
            record_id: undefined,
        }),
    );
    await waitFor(() =>
        expect(
            screen.queryByText('Loading options…'),
        ).not.toBeInTheDocument(),
    );
    await choose('Choose the first aider', 'Current first aider');
    next();
    await choose('Choose an injury or illness', 'Cut');
    fireEvent.change(screen.getByLabelText('What happened', { exact: false }), {
        target: { value: 'Recorded facts' },
    });
    next();
    fireEvent.change(
        screen.getByLabelText('Treatment given', { exact: false }),
        { target: { value: 'Recorded care' } },
    );
    await choose('Choose an outcome', 'Returned to activity');
    next();
    await choose('Choose an existing incident', 'No incident selected');
    expect(screen.getByLabelText('First aider notes')).toBeInTheDocument();
});

it('keeps edited content but disables a save after current edit authority is withdrawn', () => {
    const props = { pickers, detail, returnTo: '/my-day', onClose: () => {} };
    const mounted = render(<FirstAidTreatmentWizard {...props} />);
    next();
    next();
    next();
    fireEvent.change(screen.getByLabelText('First aider notes'), {
        target: { value: 'Keep after access changes' },
    });
    next();
    mounted.rerender(
        <FirstAidTreatmentWizard
            {...props}
            detail={{
                ...detail,
                expected_version: 8,
                can: { ...detail.can, edit: false },
            }}
        />,
    );
    expect(screen.getByText('Keep after access changes')).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Save correction' }),
    ).toBeDisabled();
    expect(axios.request).not.toHaveBeenCalled();
});
