import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { HandoverPanel, type HandoverPreview } from './handover-panel';
import type { ProposalAgreementData } from './proposal-types';
import type { ReferralDetail } from './types';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: { get, isCancel: () => false } }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

const referral = {
    reference: '01JREFERRALPREVIEW000000001',
    version: 1,
} as ReferralDetail;
const data: HandoverPreview = {
    reference: referral.reference,
    version: 1,
    checked_at: '2026-10-16T00:00:00Z',
    preparation_checks_passed: false,
    checks: [
        {
            key: 'authority',
            label: 'Current authority to prepare support',
            passed: false,
            detail: 'Review current authority.',
            section: 'assessment',
        },
    ],
    matches: {
        checked: true,
        review_required: true,
        restricted_review_required: true,
        more_results: false,
        candidates: [],
    },
    target: null,
    fields: [
        {
            key: 'contact',
            label: 'Submitted callback contact',
            submitted: { name: 'Example parent', leave_message: false },
            current: null,
            destination: 'Verify before creating a person contact',
        },
    ],
    service_contexts: [],
    funding: {
        position: 'Funding is pending',
        proposal_version: null,
        open_cases: 1,
        personal_income_cases: 1,
        proposal_readiness: null,
    },
    open_work: 2,
    conversion: null,
};
/** The "Service agreement" check loads its own endpoint (Lane G). */
const agreement: ProposalAgreementData = {
    state: 'not_converted',
    version: 1,
    proposal: { id: 4, version: 1, title: 'Support proposal' },
    blockers: [],
    can_create: false,
    permission_reason: null,
    person: null,
    preview: null,
    agreement: null,
    earlier: [],
    approval_steps: [],
};
const handoverCalls = () =>
    get.mock.calls.filter(([url]) => String(url).endsWith('/handover'));
beforeEach(() => {
    vi.clearAllMocks();
    get.mockImplementation((url: string) =>
        Promise.resolve({
            data: url.endsWith('/service-agreement') ? agreement : data,
        }),
    );
});

it('keeps an internal preparation bound to its existing person without offering another person or a new record', async () => {
    get.mockResolvedValueOnce({
        data: {
            ...data,
            target_bound: true,
            target: {
                id: 7,
                name: 'Existing person',
                status: 'onboarding',
                fingerprint: 'fingerprint',
                existing_details_preserved: true,
            },
            matches: {
                checked: true,
                review_required: true,
                restricted_review_required: false,
                more_results: false,
                candidates: [
                    {
                        id: 7,
                        name: 'Existing person',
                        date_of_birth: null,
                        can_link: true,
                    },
                    {
                        id: 8,
                        name: 'Another candidate',
                        date_of_birth: null,
                        can_link: true,
                    },
                ],
            },
        },
    });
    render(<HandoverPanel referral={referral} onSection={vi.fn()} />);
    expect(
        await screen.findByText(
            /This internal support case belongs to the existing person/,
        ),
    ).toBeInTheDocument();
    expect(screen.getByText('Previewing this person')).toBeInTheDocument();
    expect(screen.getByText('Identity review only')).toBeInTheDocument();
    expect(
        screen.queryByRole('button', {
            name: /Return to new-person preview|Compare/i,
        }),
    ).not.toBeInTheDocument();
    expect(handoverCalls()).toHaveLength(1);
});

it('explains pending funding and restricted identity review without claiming conversion or service approval', async () => {
    const onSection = vi.fn();
    render(<HandoverPanel referral={referral} onSection={onSection} />);
    expect(
        await screen.findByText('Authorized identity review needed'),
    ).toBeInTheDocument();
    expect(screen.getByText('Example parent')).toBeInTheDocument();
    expect(screen.getByText('No')).toBeInTheDocument();
    expect(
        screen.getByText(/This preview does not create or change a person/),
    ).toBeInTheDocument();
    expect(
        screen.getByText(/Personal income assistance remains separate/),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Review Current authority to prepare support',
        }),
    );
    expect(onSection).toHaveBeenCalledWith('assessment');
    expect(
        screen.queryByRole('button', { name: /start support|create person/i }),
    ).not.toBeInTheDocument();
});

it('removes previous person data while refreshing and after access is lost', async () => {
    render(<HandoverPanel referral={referral} onSection={vi.fn()} />);
    expect(await screen.findByText('Example parent')).toBeInTheDocument();
    let reject: (reason: Error) => void = () => undefined;
    get.mockReturnValueOnce(
        new Promise((_resolve, rejectPromise) => {
            reject = rejectPromise;
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Refresh checks' }));
    await waitFor(() =>
        expect(screen.queryByText('Example parent')).not.toBeInTheDocument(),
    );
    reject(new Error('Access changed'));
    expect(
        await screen.findByText(
            /Your access or the person’s details may have changed/,
        ),
    ).toBeInTheDocument();
    expect(screen.queryByText('Example parent')).not.toBeInTheDocument();
});

it('does not offer a clinical profile link when conversion exists but person access is unavailable', async () => {
    get.mockResolvedValueOnce({
        data: {
            ...data,
            conversion: {
                converted_at: data.checked_at,
                created_person: true,
                person: null,
            },
        },
    });
    render(<HandoverPanel referral={referral} onSection={vi.fn()} />);
    expect(
        await screen.findByText('Handover already recorded'),
    ).toBeInTheDocument();
    expect(
        screen.getByText(/permissions do not allow opening it/),
    ).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

it('reads the Service agreement check from the real agreement status after handover', async () => {
    get.mockImplementation((url: string) =>
        Promise.resolve({
            data: url.endsWith('/service-agreement')
                ? {
                      ...agreement,
                      state: 'created',
                      agreement: {
                          id: 31,
                          title: 'Supported living plan',
                          status: 'pending_approval',
                          total_budget: '2400.00',
                          gst_inclusive: false,
                          starts_on: '2026-10-20',
                          ends_on: '2026-11-20',
                          proposal_id: 4,
                          proposal_version: 1,
                          created_at: '2026-10-21T00:00:00Z',
                          created_by_name: 'Example coordinator',
                          outdated: false,
                          warning: null,
                          can_open: true,
                          href: '/operations/service-agreements/31',
                      },
                  }
                : {
                      ...data,
                      conversion: {
                          converted_at: data.checked_at,
                          created_person: true,
                          person: { id: 7, name: 'Existing person' },
                      },
                  },
        }),
    );
    render(<HandoverPanel referral={referral} onSection={vi.fn()} />);
    expect(await screen.findByText('Waiting for approval')).toBeInTheDocument();
    expect(
        screen.getByText(/Waiting for a different colleague to approve it/),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Open agreement' }),
    ).toHaveAttribute('href', '/operations/service-agreements/31');
    expect(
        screen.queryByRole('button', {
            name: 'Create draft service agreement',
        }),
    ).not.toBeInTheDocument();
});
