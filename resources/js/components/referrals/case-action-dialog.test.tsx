import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { CaseActionDialog } from './case-action-dialog';
import type { Options, ReferralDetail } from './types';

const { request, get, reload, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('axios', () => ({
    default: { request, get, isCancel: () => false, isAxiosError: () => true },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload, visit } }));
vi.mock('./date-time', () => ({ ReferralDateTime: () => null }));

const referral = {
    reference: 'fictional-workflow',
    version: 1,
    name: 'Fictional person',
    site_id: 1,
    owner_id: 1,
    team: 'Intake',
    stage: 'triage',
    contact: {},
    next_action: 'Review referral',
    activity: { data: [] },
    workflow_targets: [
        {
            id: 'assessment',
            label: 'Assessment',
            required_evidence: ['funding:agreement'],
            restricted_requirements: false,
        },
    ],
} as unknown as ReferralDetail;
const props = {
    action: 'transition' as const,
    referral,
    options: { sites: [] } as unknown as Options,
    permissions: {},
    onClose: vi.fn(),
};
beforeEach(() => {
    vi.clearAllMocks();
    HTMLElement.prototype.scrollTo = vi.fn();
    get.mockResolvedValue({
        data: {
            staff: [
                { id: 1, name: 'Outgoing owner' },
                { id: 2, name: 'Next owner' },
            ],
        },
    });
});

it('reviews explicit follow-up transfer and returns to the list if assignment removes current access', async () => {
    request.mockResolvedValue({ data: { saved: true, can_view: false } });
    render(
        <CaseActionDialog
            {...props}
            action="assign"
            options={
                {
                    sites: [{ id: 1, name: 'Preview site' }],
                } as unknown as Options
            }
            permissions={{ 'tasks.manage': true }}
        />,
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason for this change' }),
        { target: { value: 'Fictional accountable transfer' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    // Staff options are loaded independently of the form review.
    await waitFor(() => expect(get).toHaveBeenCalled());
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Select eligible staff' }),
    );
    fireEvent.click(await screen.findByRole('option', { name: 'Next owner' }));
    fireEvent.click(
        screen.getByRole('checkbox', { name: /Transfer the outgoing owner/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(
            'Transfer outgoing owner’s open follow-ups; retain other owners and all dates',
        ),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await screen.findByRole('button', { name: 'Return to Referrals' });
    expect(request).toHaveBeenCalledWith(
        expect.objectContaining({
            url: '/operations/referrals/fictional-workflow/assign',
            data: expect.objectContaining({
                owner_id: 2,
                transfer_open_work: true,
                version: 1,
            }),
        }),
    );
    expect(reload).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Return to Referrals' }),
    );
    expect(visit).toHaveBeenCalledWith('/operations/referrals');
});

it('shows configured evidence at the start of the stage-change wizard', () => {
    render(<CaseActionDialog {...props} />);
    expect(screen.getByText('Funding · Agreement')).toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
});

it('requires explicit test-entry confirmation and includes it in the reviewed closure', async () => {
    request.mockResolvedValue({ data: { saved: true } });
    render(<CaseActionDialog {...props} action="close" />);
    fireEvent.click(screen.getByRole('combobox', { name: 'Select reason' }));
    expect(
        screen.queryByRole('option', { name: 'Duplicate' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('option', { name: 'Test' }));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason for this change' }),
        {
            target: { value: 'Fictional demonstration only' },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(
            'Confirm this is a test entry with no genuine request for support.',
        ),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /This is a test entry/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(
        screen.getByRole('textbox', { name: /Communication or reason/ }),
        {
            target: { value: 'Demonstration only; no external communication.' },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(request).toHaveBeenCalled());
    expect(request).toHaveBeenCalledWith(
        expect.objectContaining({
            data: expect.objectContaining({
                closure_reason: 'test',
                test_entry_confirmed: true,
            }),
        }),
    );
    expect(request.mock.calls[0][0].data).not.toHaveProperty(
        'duplicate_reference',
    );
});

it('submits the reviewed original version and human match confirmation', async () => {
    const original = {
        reference: '01M56FK502AJ1TSMS9Y7C0M859',
        version: 7,
        name: 'Original enquiry',
        stage: 'assessment',
        owner_name: 'Named owner',
    };
    get.mockResolvedValue({ data: { original } });
    request.mockResolvedValue({ data: { saved: true } });
    render(
        <CaseActionDialog
            {...props}
            action="close"
            permissions={{ 'duplicates.manage': true }}
        />,
    );
    fireEvent.click(screen.getByRole('combobox', { name: 'Select reason' }));
    fireEvent.click(screen.getByRole('option', { name: 'Duplicate' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(
            'Review the original referral, explain the match and confirm it is the same enquiry.',
        ),
    ).toBeVisible();
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Original referral reference' }),
        {
            target: { value: original.reference },
        },
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Review original referral' }),
    );
    await screen.findByText(original.name);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Why this is the same enquiry' }),
        {
            target: {
                value: 'Same fictional person and dated enquiry, reviewed together.',
            },
        },
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /I reviewed both/ }));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason for this change' }),
        {
            target: { value: 'Reviewed repeated fictional enquiry' },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(
        screen.getByRole('textbox', { name: /Communication or reason/ }),
        {
            target: { value: 'Demonstration only; no external communication.' },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText(new RegExp(original.reference))).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(request).toHaveBeenCalled());
    expect(request).toHaveBeenCalledWith(
        expect.objectContaining({
            data: expect.objectContaining({
                closure_reason: 'duplicate',
                duplicate_reference: original.reference,
                duplicate_version: 7,
                same_enquiry_confirmed: true,
            }),
        }),
    );
});

it('keeps converted referral site placement out of the assignment shortcut', () => {
    render(
        <CaseActionDialog
            {...props}
            action="assign"
            referral={{ ...referral, stage: 'converted' }}
            options={
                {
                    sites: [{ id: 1, name: 'Preview site' }],
                } as unknown as Options
            }
        />,
    );
    expect(
        screen.getByRole('combobox', { name: 'Choose site' }),
    ).toBeDisabled();
    expect(
        screen.getByText(/Site moves must be reviewed through/),
    ).toBeInTheDocument();
});

it('prevents continuing when another evidence audience is required', () => {
    render(
        <CaseActionDialog
            {...props}
            referral={{
                ...referral,
                workflow_targets: [
                    {
                        id: 'assessment',
                        label: 'Assessment',
                        required_evidence: [],
                        restricted_requirements: true,
                    },
                ],
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(
            'A colleague with access to the required evidence must make this stage change.',
        ),
    ).toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
});

it('does not carry a removed target through a refreshed record', () => {
    const { rerender } = render(<CaseActionDialog {...props} />);
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason for this change' }),
        { target: { value: 'Review the fictional application' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    rerender(
        <CaseActionDialog
            {...props}
            referral={{
                ...referral,
                version: 2,
                workflow_targets: [
                    { id: 'funding_assistance', label: 'Funding assistance' },
                ],
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(
            'No stage change is available under this referral’s workflow and your current role.',
        ),
    ).toBeInTheDocument();
    expect(request).not.toHaveBeenCalled();
});

const prioritised = {
    ...referral,
    priority: 'standard',
    priority_label: 'Standard',
    choices: {
        hold: [],
        close: [],
        priority: [
            { id: 'urgent', label: 'Urgent' },
            { id: 'standard', label: 'Standard' },
            { id: 'same_day', label: 'Same day' },
        ],
    },
} as unknown as ReferralDetail;

it('sets one of the enabled priorities with a reason and never sends a next action', async () => {
    request.mockResolvedValue({ data: { saved: true } });
    render(
        <CaseActionDialog
            {...props}
            action="priority"
            referral={prioritised}
            permissions={{ edit: true }}
        />,
    );
    // The current priority is stated, and only the priorities turned on in Settings are offered.
    expect(screen.getByText('Standard priority')).toBeVisible();
    expect(
        screen.getByRole('button', { name: /Standard.*Current priority/ }),
    ).toHaveAttribute('aria-pressed', 'false');
    expect(
        screen.queryByRole('button', { name: /^High/ }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Same day/ }));
    expect(screen.getByRole('button', { name: /Same day/ })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason for this change' }),
        { target: { value: 'Hospital discharge is planned for tomorrow' } },
    );
    // Two steps only: there is no next action or date to plan.
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText('Standard priority → Same day priority'),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() =>
        expect(request).toHaveBeenCalledWith({
            url: '/operations/referrals/fictional-workflow/lifecycle/priority',
            method: 'post',
            data: {
                version: 1,
                reason: 'Hospital discharge is planned for tomorrow',
                priority: 'same_day',
            },
        }),
    );
    await screen.findByRole('button', { name: 'Done' });
});

it('asks for a different priority before saving the current one again', () => {
    render(
        <CaseActionDialog
            {...props}
            action="priority"
            referral={prioritised}
            permissions={{ edit: true }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('Choose the new priority.')).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: /Standard.*Current priority/ }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Reason for this change' }),
        { target: { value: 'No change really needed' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(
            'This is already the referral’s priority. Choose a different one.',
        ),
    ).toBeVisible();
    expect(request).not.toHaveBeenCalled();
});
