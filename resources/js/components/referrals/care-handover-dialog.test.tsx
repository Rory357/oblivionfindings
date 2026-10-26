import { Button } from '@/components/ui/button';
import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { CareHandoverDialog } from './care-handover-dialog';
import type { ReferralDocument } from './evidence-picker';

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
    EvidencePicker: ({
        onChange,
    }: {
        onChange: (value: number[], changed?: ReferralDocument) => void;
    }) => (
        <Button
            onClick={() =>
                onChange([4], {
                    id: 4,
                    title: 'Reviewed assessment',
                } as ReferralDocument)
            }
        >
            Select current evidence
        </Button>
    ),
}));

const preview = {
    referral_version: 8,
    published_version: 1,
    assessment_version: 2,
    authority_version: 1,
    current_review_ready: true,
    can_release_documents: true,
    can_release_contacts: true,
    people_contacts: [
        {
            id: '2d158157-9bfa-489f-979d-d7bcad586128',
            version: 3,
            review_current: true,
            details: {
                name: 'Morgan Example',
                role: 'supporter',
                relationship: 'Chosen supporter',
                phone: '021 555 0188',
                channel: 'phone',
                may_contact: true,
                leave_message: false,
                safe_time: 'Afternoons only',
                review_due_at: '2026-10-25T00:00:00Z',
            },
        },
    ],
    person: { id: 3, name: 'Casey Example', fingerprint: 'a'.repeat(64) },
    care: {
        goals: 'Visit the library',
        participation: 'Allow time for decisions',
        safety_needs: 'Review mobility support together',
    },
};

beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollTo = vi.fn();
    get.mockResolvedValue({ data: preview });
    post.mockResolvedValue({ data: { saved: true, published_version: 2 } });
});

async function review(release = false) {
    render(
        <CareHandoverDialog
            reference="01JREFERRALPREVIEW000000001"
            onClose={vi.fn()}
        />,
    );
    await screen.findByText('Visit the library');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(
        screen.getByLabelText('Why is an updated handover needed?'),
        { target: { value: 'Reviewed changed goals with the person' } },
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Select current evidence' }),
    );
    if (release) {
        fireEvent.click(
            screen.getByText('People involved in ongoing care · optional'),
        );
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Include Morgan Example in ongoing care',
            }),
        );
        fireEvent.change(
            screen.getByLabelText(
                'Why is this contact needed for ongoing care?',
            ),
            {
                target: {
                    value: 'Help coordinate continuing care appointments',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: /I checked the existing person contacts/,
            }),
        );
        fireEvent.click(screen.getByText(/Documents for the person’s profile/));
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Release Reviewed assessment to care staff',
            }),
        );
        fireEvent.change(
            screen.getByLabelText('Title on the person’s profile'),
            { target: { value: 'Renewed care document' } },
        );
        fireEvent.change(
            screen.getByLabelText('Why is the whole document needed for care?'),
            {
                target: {
                    value: 'Reviewed again under the current care authority',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('checkbox', { name: /I reviewed every page/ }),
        );
    }
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

it('withholds publication when the current authority assessment or destination is not ready', async () => {
    get.mockResolvedValueOnce({
        data: { ...preview, current_review_ready: false },
    });
    render(
        <CareHandoverDialog
            reference="01JREFERRALPREVIEW000000001"
            onClose={vi.fn()}
        />,
    );
    expect(
        await screen.findByText(
            /Review the assessment, authority and onboarding destination first/,
        ),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    expect(post).not.toHaveBeenCalled();
});

it('requires a named person sharing confirmation and reuses the identical publication after uncertain delivery', async () => {
    post.mockRejectedValueOnce({ isAxiosError: true });
    await review(true);
    fireEvent.click(
        screen.getByRole('button', { name: 'Publish care handover' }),
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: /I checked this information belongs to Casey Example/,
        }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Publish care handover' }),
    );
    await screen.findByRole('button', { name: 'Retry same publication' });
    expect(screen.getByRole('checkbox')).toBeDisabled();
    const first = structuredClone(post.mock.calls[0][1]);
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry same publication' }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(post.mock.calls[1][1]).toEqual(first);
    expect(first).toEqual(
        expect.objectContaining({
            version: 8,
            assessment_version: 2,
            authority_version: 1,
            person_fingerprint: 'a'.repeat(64),
            evidence_ids: [4],
            support_contacts: [
                {
                    contact_id: '2d158157-9bfa-489f-979d-d7bcad586128',
                    contact_version: 3,
                    purpose: 'Help coordinate continuing care appointments',
                    confirmed: true,
                },
            ],
            document_handover: [
                {
                    evidence_id: 4,
                    title: 'Renewed care document',
                    purpose: 'Reviewed again under the current care authority',
                    confirmed: true,
                },
            ],
            acknowledge_care_handover: true,
        }),
    );
    expect(
        await screen.findByText('Care handover version 2 saved'),
    ).toBeInTheDocument();
});

it('requires a fresh review after a stale version conflict instead of retrying with altered evidence', async () => {
    post.mockRejectedValueOnce({
        isAxiosError: true,
        response: {
            status: 409,
            data: { message: 'The linked person record changed.' },
        },
    });
    await review();
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: /I checked this information belongs to Casey Example/,
        }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Publish care handover' }),
    );
    expect(
        await screen.findByText(/Close and reload the care review/),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Publish care handover' }),
    ).toBeDisabled();
    expect(screen.getByRole('checkbox')).toBeDisabled();
    expect(post).toHaveBeenCalledTimes(1);
});
