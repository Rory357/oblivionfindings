import { Button } from '@/components/ui/button';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { InternalFundingDialog } from './internal-funding-dialog';
import type { SupportProposal } from './proposal-types';
import type { ReferralDetail } from './types';

const { get, post, reload } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        get,
        post,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            error.isAxiosError === true,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('./evidence-picker', () => ({
    EvidencePicker: ({ onChange }: { onChange: (ids: number[]) => void }) => (
        <Button onClick={() => onChange([3])}>Select reviewed evidence</Button>
    ),
}));
vi.mock('./date-time', () => ({
    ReferralDateTime: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
    validReferralDateTime: (value: string) => value !== '',
}));
const referral = {
    reference: 'referral-example',
    version: 9,
    name: 'Fictional person',
    stage: 'assessment',
    general_enquiry: false,
} as ReferralDetail;
const proposal = {
    id: 12,
    version: 3,
    terms: {
        title: 'Temporary support',
        starts_on: '2026-10-18',
        ends_on: '2026-10-24',
    },
} as SupportProposal;
const preview = {
    enabled: true,
    current: true,
    gap: { ready: true, issues: [], currency: 'NZD', gross: '552.00' },
    arrangement: null,
    history: [],
};
const props = {
    referral,
    proposal,
    permissions: { 'funding.manage': true, 'proposals.approve': true },
    actorId: 1,
    onClose: vi.fn(),
};
beforeEach(() => {
    vi.clearAllMocks();
    HTMLElement.prototype.scrollTo = vi.fn();
    get.mockResolvedValue({ data: preview });
});

async function completeForm() {
    await screen.findByText('NZD 552.00');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    for (const [name, value] of [
        ['Responsible company payer', 'Company support budget'],
        ['Finance budget reference', 'QA-001'],
        ['Full-period cost cap', '600.00'],
        [
            'Reason for temporary company support',
            'Fictional temporary budget commitment only',
        ],
        ['Review due', '2026-10-19T12:00+13:00'],
    ]) {
        fireEvent.change(
            screen.getByRole('textbox', { name: new RegExp(name) }),
            { target: { value } },
        );
    }
    fireEvent.click(
        screen.getByRole('button', { name: 'Select reviewed evidence' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

it('requires payer, budget, evidence and review before submitting the exact proposal for independent review', async () => {
    post.mockResolvedValue({ data: { saved: true, arrangement_id: 7 } });
    render(<InternalFundingDialog {...props} />);
    await completeForm();
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Submit for independent review' }),
    );
    await screen.findByText('Arrangement prepared');
    expect(post).toHaveBeenCalledWith(
        '/operations/referrals/referral-example/proposals/12/internal-funding',
        expect.objectContaining({
            version: 9,
            payer: 'Company support budget',
            budget_reference: 'QA-001',
            cost_cap: '600.00',
            evidence_ids: [3],
        }),
    );
    expect(reload).toHaveBeenCalledTimes(1);
});

it('does not resend an uncertain decision or claim it saved', async () => {
    post.mockRejectedValue({ isAxiosError: true });
    render(<InternalFundingDialog {...props} />);
    await completeForm();
    fireEvent.click(
        screen.getByRole('button', { name: 'Submit for independent review' }),
    );
    await screen.findByRole('button', { name: 'Close and check saved record' });
    expect(screen.queryByText('Arrangement prepared')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Submit for independent review' }),
    ).not.toBeInTheDocument();
    expect(post).toHaveBeenCalledTimes(1);
    expect(reload).not.toHaveBeenCalled();
});

it('shows the policy boundary without offering a hidden preparation bypass', async () => {
    get.mockResolvedValue({ data: { ...preview, enabled: false } });
    render(<InternalFundingDialog {...props} />);
    await screen.findByText(/Temporary company funding is disabled/);
    expect(
        screen.queryByRole('button', { name: 'Continue' }),
    ).not.toBeInTheDocument();
});

it('lets the author inspect their recorded arrangement but requires another reviewer', async () => {
    get.mockResolvedValue({
        data: {
            ...preview,
            arrangement: {
                id: 7,
                authored_by: 1,
                authored_at: '2026-10-18T00:00:00Z',
                terms: {
                    payer: 'Company',
                    budget_reference: 'QA-001',
                    cost_cap: '600.00',
                    currency: 'NZD',
                    review_due_at: '2026-10-19T00:00:00Z',
                    reason: 'Fictional arrangement only',
                    evidence_ids: [3],
                },
                availability: {
                    usable: false,
                    issues: ['Independent review required'],
                },
            },
        },
    });
    render(<InternalFundingDialog {...props} />);
    await screen.findByText(/Another authorized colleague must review/);
    expect(
        screen.queryByRole('button', { name: 'Continue' }),
    ).not.toBeInTheDocument();
    await waitFor(() => expect(post).not.toHaveBeenCalled());
});
