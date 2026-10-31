import { Button } from '@/components/ui/button';
import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { ServiceStartDialog } from './service-start-dialog';
import type { StartPreview } from './service-start-types';

const { post, reload } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            error.isAxiosError === true,
    },
}));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('@/components/fleet-assets/maintenance/date-time-field', () => ({
    DateTimeField: ({
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
}));
vi.mock('./evidence-picker', () => ({
    EvidencePicker: ({ onChange }: { onChange: (ids: number[]) => void }) => (
        <Button onClick={() => onChange([7])}>Select signature evidence</Button>
    ),
}));

const preview: StartPreview = {
    version: 5,
    checked_at: '2026-10-16T00:00:00Z',
    person: { id: 2, name: 'Casey Example', status: 'onboarding' },
    workflow: { status: 'completed', url: null },
    proposal: {
        id: 3,
        version: 2,
        title: 'Daily living support',
        starts_on: '2026-10-16',
        ends_on: '2026-11-16',
        lines: [
            {
                key: 'daily',
                description: 'Daily support',
                quantity: '8',
                unit: 'hours',
                unit_label: null,
                period: 'week',
                currency: 'NZD',
                unit_rate: '60',
                price_basis: 'exclusive',
            },
        ],
    },
    agreements: [
        {
            id: 4,
            title: 'Community agreement',
            status: 'active',
            starts_on: '2026-10-16',
            ends_on: '2026-11-30',
            total_budget: '5000',
            gst_inclusive: false,
            fingerprint: 'a'.repeat(64),
            lines: [
                {
                    id: 8,
                    description: 'Living support',
                    unit: 'hour',
                    unit_price: '60',
                    quantity: '100',
                    budget_allocated: '6000',
                    budget_used: '0',
                },
            ],
        },
    ],
    care_plans: [
        {
            id: 5,
            title: 'Care and safety plan',
            status: 'active',
            version: 2,
            next_review_on: '2026-11-30',
            digest: 'b'.repeat(64),
        },
    ],
    staff: [{ id: 6, name: 'Example worker' }],
    more_options: false,
    start: {
        status: 'scheduled',
        agreed_date: '2026-10-16',
        commenced_at: null,
        approved_at: '2026-10-16T00:00:00Z',
        review_required: false,
    },
};
beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollTo = vi.fn();
    post.mockResolvedValue({ data: { saved: true } });
});
async function choose(name: string, option: RegExp) {
    fireEvent.pointerDown(screen.getByRole('combobox', { name }), {
        button: 0,
        ctrlKey: false,
        pointerType: 'mouse',
    });
    fireEvent.click(await screen.findByRole('option', { name: option }));
}
async function reviewSchedule() {
    render(
        <ServiceStartDialog
            reference="01JREFERRALPREVIEW000000001"
            preview={preview}
            operation="schedule"
            onClose={vi.fn()}
        />,
    );
    await choose('Canonical agreement', /Community agreement/);
    await choose('Agreement line for Daily support', /Living support/);
    await choose('Current care plan', /Care and safety plan/);
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText(/Person or authorized signatory/), {
        target: { value: 'Casey Example' },
    });
    fireEvent.change(screen.getByLabelText(/Agreement signed at/), {
        target: { value: '2026-10-16T12:00' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Select signature evidence' }),
    );
    for (const label of [
        /Agreement and signature review/,
        /Care, safety and consent review/,
        /Staffing and cover arrangements/,
    ])
        fireEvent.change(screen.getByLabelText(label), {
            target: {
                value: 'Reviewed current records and arrangements with the person.',
            },
        });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Example worker' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I reviewed these exact/ }),
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /This approves a date/ }),
    );
}

it('checks readiness before approval and explains blockers without issuing a start decision', async () => {
    post.mockResolvedValueOnce({
        data: {
            ready: false,
            issues: ['A mandatory consent needs review.'],
            agreement_mapping: {
                required_budget: '2400',
                currency: 'NZD',
                budget_basis: 'exclusive',
                mapping: [],
            },
        },
    });
    await reviewSchedule();
    expect(screen.getByText('Signatory')).toBeInTheDocument();
    expect(screen.getByText('Agreement signed at')).toBeInTheDocument();
    expect(screen.getByText('Care and consent review')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Check readiness' }));
    expect(
        await screen.findByText('A mandatory consent needs review.'),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Review and agree start' }),
    ).toBeDisabled();
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0][0]).toMatch(/service-start\/check$/);
    expect(post.mock.calls[0][1]).toEqual(
        expect.objectContaining({
            agreement_fingerprint: 'a'.repeat(64),
            care_plan_digest: 'b'.repeat(64),
            signed_at: '2026-10-16T12:00+13:00',
            agreement_lines: [{ line_key: 'daily', line_item_id: 8 }],
        }),
    );
});

it('requires actual-start acknowledgement and retries the same decision after uncertain delivery', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true });
    render(
        <ServiceStartDialog
            reference="01JREFERRALPREVIEW000000001"
            preview={preview}
            operation="commence"
            onClose={vi.fn()}
        />,
    );
    fireEvent.change(
        screen.getByLabelText(/What happened at the actual start/),
        {
            target: {
                value: 'Support began with the person and assigned worker.',
            },
        },
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm actual start' }),
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /Support actually began/ }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Confirm actual start' }),
    );
    await screen.findByRole('button', { name: 'Retry same decision' });
    expect(screen.getByRole('checkbox')).toBeDisabled();
    const first = structuredClone(post.mock.calls[0][1]);
    expect(first.commenced_at).toMatch(/T\d{2}:\d{2}:\d{2}\+1[23]:00$/);
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry same decision' }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(post.mock.calls[1][1]).toEqual(first);
    expect(
        await screen.findByText('Actual start recorded'),
    ).toBeInTheDocument();
});

it('explains missing canonical records and prevents an incomplete scheduling review', () => {
    render(
        <ServiceStartDialog
            reference="01JREFERRALPREVIEW000000001"
            preview={{ ...preview, agreements: [], care_plans: [] }}
            operation="schedule"
            onClose={vi.fn()}
        />,
    );
    expect(
        screen.getByText(/No service agreement is available/),
    ).toHaveTextContent('No care plan is available');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(/Complete the required fields/),
    ).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});

it('freezes a stale review and removes person details after access is lost', async () => {
    post.mockRejectedValueOnce({
        isAxiosError: true,
        response: {
            status: 403,
            data: { message: 'Current permission is required.' },
        },
    });
    render(
        <ServiceStartDialog
            reference="01JREFERRALPREVIEW000000001"
            preview={preview}
            operation="cancel"
            onClose={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByLabelText(/Reason for cancelling/), {
        target: { value: 'The person requested a later review.' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Cancel scheduled start' }),
    );
    await screen.findByText('Current permission is required.');
    expect(screen.queryByText('Casey Example')).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Cancel scheduled start' }),
    ).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Retry same decision' }),
    ).not.toBeInTheDocument();
});
