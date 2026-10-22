import { Button } from '@/components/ui/button';
import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import type { ReferralDocument } from './evidence-picker';
import { HandoverDialog } from './handover-dialog';
import type { HandoverPreview } from './handover-panel';
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
vi.mock('@inertiajs/react', () => ({ router: { reload }, Link: 'a' }));
vi.mock('@/components/wizard/primitives', async (original) => {
    const real =
        await original<typeof import('@/components/wizard/primitives')>();
    return {
        ...real,
        SelectInput: ({
            id,
            value,
            onChange,
            options,
        }: {
            id?: string;
            value: string;
            onChange: (value: string) => void;
            options: { value: string; label: string }[];
        }) => (
            <select
                id={id}
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
        ),
    };
});
vi.mock('./evidence-picker', () => ({
    EvidencePicker: ({
        onChange,
    }: {
        onChange: (value: number[], changed?: ReferralDocument) => void;
    }) => (
        <Button
            onClick={() =>
                onChange([7], {
                    id: 7,
                    title: 'Reviewed assessment',
                } as ReferralDocument)
            }
        >
            Select reviewed evidence
        </Button>
    ),
}));

const referral = {
    reference: '01JREFERRALPREVIEW000000001',
    version: 4,
    name: 'Casey Example',
    site_id: 1,
} as ReferralDetail;
const preview: HandoverPreview = {
    reference: referral.reference,
    version: 4,
    checked_at: '2026-10-16T00:00:00Z',
    preparation_checks_passed: true,
    can_release_documents: true,
    checks: [],
    matches: {
        checked: true,
        review_required: false,
        restricted_review_required: false,
        more_results: false,
        candidates: [],
    },
    target: null,
    fields: [],
    profile_fields: [
        { key: 'languages', label: 'Languages', current: null, can_add: true },
    ],
    contact_profile: {
        phone: null,
        email: null,
        preferences: null,
        review_current: false,
        can_add: true,
        fingerprint: null,
    },
    service_contexts: [{ id: 3, name: 'Home support', type: 'Community' }],
    funding: {
        position: 'Funding remains pending',
        proposal_version: 2,
        open_cases: 1,
        personal_income_cases: 0,
        proposal_readiness: null,
    },
    open_work: 2,
    conversion: null,
};

beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollTo = vi.fn();
    get.mockResolvedValue({
        data: { staff: [{ id: 9, name: 'Example manager' }] },
    });
    post.mockResolvedValue({
        data: {
            saved: true,
            person: null,
            created_person: true,
            service_commenced: false,
        },
    });
});

it('requires an explicit identity review before staff can prepare onboarding', () => {
    render(
        <HandoverDialog
            referral={referral}
            preview={preview}
            onClose={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(/Record the identity review and a reason/),
    ).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});

it('retains the exact handover after an uncertain save and requires both continuity acknowledgements', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true });
    render(
        <HandoverDialog
            referral={referral}
            preview={preview}
            onClose={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByLabelText('How was identity verified?'), {
        target: { value: 'Identity and participation reviewed together' },
    });
    fireEvent.click(screen.getByText(/Culture and language/));
    fireEvent.change(screen.getByLabelText(/Languages/), {
        target: { value: 'English, te reo Māori' },
    });
    fireEvent.change(
        screen.getByLabelText('How were these profile details verified?'),
        {
            target: {
                value: 'Fictional language verification with the person',
            },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(/Verify and confirm any optional profile details/),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /person’s own details/ }),
    );
    fireEvent.click(screen.getByText(/Personal contact ·/));
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: /Include verified personal contact/,
        }),
    );
    fireEvent.change(screen.getByLabelText('Person’s email'), {
        target: { value: 'person@example.test' },
    });
    fireEvent.change(screen.getByLabelText(/Preferred contact channel/), {
        target: { value: 'email' },
    });
    fireEvent.change(screen.getByLabelText(/Permission to leave a message/), {
        target: { value: 'no' },
    });
    fireEvent.change(screen.getByLabelText(/How were contact details/), {
        target: {
            value: 'The fictional person reviewed their own contact details',
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(/Verify and confirm any optional profile details/),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /These contact details belong/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Service setting'), {
        target: { value: '3' },
    });
    await screen.findByRole('option', { name: 'Example manager' });
    fireEvent.change(screen.getByLabelText('Onboarding owner'), {
        target: { value: '9' },
    });
    fireEvent.change(
        screen.getByLabelText('Handover instructions and outstanding needs'),
        {
            target: {
                value: 'Keep the funding application and follow-up open',
            },
        },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Select reviewed evidence' }),
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /Share these reviewed goals/ }),
    );
    fireEvent.click(screen.getByText(/Documents for the person’s profile/));
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'Release Reviewed assessment to care staff',
        }),
    );
    fireEvent.change(screen.getByLabelText('Title on the person’s profile'), {
        target: { value: 'Care assessment' },
    });
    fireEvent.change(
        screen.getByLabelText('Why is the whole document needed for care?'),
        { target: { value: 'Reviewed instructions for ongoing daily care' } },
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I reviewed every page/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText('Identity and participation reviewed together'),
    ).toBeInTheDocument();
    expect(
        screen.getByText('Keep the funding application and follow-up open'),
    ).toBeInTheDocument();
    expect(screen.getByText('Confirmed')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Prepare onboarding' }));
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: /Outstanding funding assistance/,
        }),
    );
    fireEvent.click(
        screen.getByRole('checkbox', { name: /This prepares onboarding/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Prepare onboarding' }));
    await screen.findByRole('button', { name: 'Retry same handover' });
    const original = structuredClone(post.mock.calls[0][1]);
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry same handover' }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(post.mock.calls[1][1]).toEqual(original);
    expect(original).toEqual(
        expect.objectContaining({
            version: 4,
            target_id: null,
            target_fingerprint: null,
            service_context_id: 3,
            onboarding_owner_id: 9,
            evidence_ids: [7],
            document_handover: [
                {
                    evidence_id: 7,
                    title: 'Care assessment',
                    purpose: 'Reviewed instructions for ongoing daily care',
                    confirmed: true,
                },
            ],
            acknowledge_care_handover: true,
            acknowledge_pending_work: true,
            acknowledge_separate_start: true,
            profile_handover: {
                fields: { languages: ['English', 'te reo Māori'] },
                verification_note:
                    'Fictional language verification with the person',
                confirmed: true,
            },
            contact_handover: expect.objectContaining({
                email: 'person@example.test',
                phone: null,
                channel: 'email',
                leave_message: false,
                confirmed: true,
            }),
        }),
    );
    expect(await screen.findByText('Onboarding prepared')).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

it('requires an evidence-based resolution for every candidate before creating another person', () => {
    const withCandidate = {
        ...preview,
        matches: {
            ...preview.matches,
            review_required: true,
            candidates: [
                {
                    id: 8,
                    name: 'Casey Example',
                    date_of_birth: null,
                    can_link: true,
                },
            ],
        },
    };
    render(
        <HandoverDialog
            referral={referral}
            preview={withCandidate}
            onClose={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByLabelText('How was identity verified?'), {
        target: { value: 'Identity reviewed with the person' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(/Record the identity review and a reason/),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('option', { name: /Same person/ }),
    ).not.toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});

it('lets an identity reviewer continue past matches they cannot open only with a recorded reason', () => {
    const withHidden = {
        ...preview,
        matches: {
            ...preview.matches,
            review_required: true,
            restricted_review_required: true,
            can_record_restricted_review: true,
        },
    };
    render(
        <HandoverDialog
            referral={referral}
            preview={withHidden}
            onClose={vi.fn()}
        />,
    );
    expect(
        screen.getByText('Possible matches you cannot open'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Their details are not shown/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('How was identity verified?'), {
        target: { value: 'Identity reviewed with the person' },
    });
    fireEvent.change(
        screen.getByLabelText('Why this is not one of those people'),
        { target: { value: 'Too short' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByText(/including the matches you cannot open/),
    ).toBeInTheDocument();
    fireEvent.change(
        screen.getByLabelText('Why this is not one of those people'),
        {
            target: {
                value: 'Different birth year and region confirmed with the referrer',
            },
        },
    );
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: /reviewed the possible matches I cannot open/,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByLabelText('Service setting')).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
});
