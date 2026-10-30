import type { FormQuestion } from '@/components/referrals/form-types';
import '@testing-library/jest-dom/vitest';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import PublicReferral from './create';

const { post, reload, page } = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    page: { url: '/referrals/new', props: {} as Record<string, unknown> },
}));
vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            !!error.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload, on: () => () => {}, visit: vi.fn() },
    usePage: () => page,
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));
vi.mock('@/layouts/marketing-layout', () => ({
    default: ({
        children,
        intro,
    }: {
        children: ReactNode;
        intro?: ReactNode;
    }) => (
        <main>
            {intro}
            {children}
        </main>
    ),
}));
const intake = {
    enabled: true,
    provider_name: 'Preview Care',
    contact_phone: '',
    response_hours: 'Office hours',
    notice: 'Published privacy notice',
    notice_version: 'v1',
    settings_version_id: 1,
    services: [{ id: 'supported_living', label: 'Supported living' }],
};
beforeEach(() => {
    post.mockReset();
    reload.mockReset();
    page.url = '/referrals/new';
    page.props = { provider: { name: 'Preview Care' } };
    Element.prototype.scrollTo = vi.fn();
});
afterEach(cleanup);

it('collects a configured required question and carries its answer into the durable submission', async () => {
    const question: FormQuestion = {
        id: 'qa_question',
        label: 'Preferred introduction',
        help: 'Fictional test question',
        type: 'text',
        options: [],
        required: 'intake',
        service_ids: ['supported_living'],
        purposes: ['support'],
    };
    post.mockResolvedValue({
        data: { status: 'received', reference: 'REF-FORM-QA' },
    });
    page.url = '/referrals/new?purpose=support&service=supported_living';
    render(
        <PublicReferral
            intake={{
                ...intake,
                fields: [question],
                pathways: [
                    {
                        id: 'dss_nasc',
                        label: 'DSS / NASC',
                        service_ids: ['supported_living'],
                        personal_income: false,
                    },
                ],
            }}
            submissionToken="13c53597-e607-498a-b85e-fd664815f38d"
            initialPurpose="support"
            initialService="supported_living"
        />,
    );
    start();
    contact();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getAllByText('Answer preferred introduction before continuing.')
            .length,
    ).toBeGreaterThan(0);
    expect(post).not.toHaveBeenCalled();
    fireEvent.change(
        screen.getByRole('textbox', { name: /Preferred introduction/ }),
        { target: { value: 'A phone call' } },
    );
    fireEvent.click(
        screen.getByRole('combobox', { name: 'Funding pathway, if known' }),
    );
    fireEvent.click(await screen.findByRole('option', { name: 'DSS / NASC' }));
    review();
    expect(screen.getByText('A phone call')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Send referral' }));
    await screen.findByText('Thanks — your referral is with us');
    expect(post.mock.calls[0][1].answers).toEqual({
        qa_question: 'A phone call',
    });
    expect(post.mock.calls[0][1].funding_pathway_id).toBe('dss_nasc');
});

function begin(purpose = 'funding') {
    render(
        <PublicReferral
            intake={intake}
            submissionToken="13c53597-e607-498a-b85e-fd664815f38d"
            initialPurpose={purpose}
            initialService="supported_living"
        />,
    );
    start();
}
/** The tiles on the page, then the wizard opens at Safe contact. */
function start() {
    fireEvent.click(screen.getByRole('radio', { name: 'Myself' }));
    fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
}
function contact() {
    fireEvent.change(screen.getByLabelText(/Your name/), {
        target: { value: 'Fictional Person' },
    });
    fireEvent.change(screen.getByLabelText(/Phone number/), {
        target: { value: '0210000000' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}
function review() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        screen.getByRole('button', { name: 'Send referral' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /You may contact me/ }),
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /I have read/ }));
}

it('starts with nothing chosen from the header link, then keeps the chosen purpose', () => {
    render(
        <PublicReferral
            intake={intake}
            submissionToken="13c53597-e607-498a-b85e-fd664815f38d"
        />,
    );
    const continueButton = screen.getByRole('button', { name: /Continue/ });
    expect(continueButton).toBeDisabled();
    expect(
        screen.getByRole('radio', { name: /Ask about support/ }),
    ).toHaveAttribute('aria-checked', 'false');
    // Who it's for is an explicit choice too.
    fireEvent.click(screen.getByRole('radio', { name: /Ask about support/ }));
    expect(screen.getByRole('radio', { name: 'Myself' })).toHaveAttribute(
        'aria-checked',
        'false',
    );
    expect(continueButton).toBeDisabled();
    fireEvent.click(screen.getByRole('radio', { name: 'Myself' }));
    expect(continueButton).toBeEnabled();
    fireEvent.click(screen.getByRole('radio', { name: /Request a callback/ }));
    // A callback needs no "who is it for".
    expect(
        screen.queryByRole('radiogroup', { name: 'Who is the support for?' }),
    ).not.toBeInTheDocument();
    expect(continueButton).toBeEnabled();
});

it('asks for the authority to share someone else’s details before continuing', () => {
    begin('referral');
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(
        screen.getByRole('radio', { name: /A family or whānau member/ }),
    );
    expect(
        screen.getByRole('radio', { name: /I’d like guidance first/ }),
    ).toHaveAttribute('aria-checked', 'true');
    expect(
        screen.getByText(/We can talk about the process first/),
    ).toBeVisible();
});

it('keeps website funding and service choices, requires safe contact, then saves a durable receipt', async () => {
    post.mockResolvedValue({
        data: { status: 'received', reference: 'REF-PREVIEW' },
    });
    begin();
    // The page sits behind the open wizard (hidden from the a11y tree).
    expect(
        screen.getByRole('radio', { name: /Help with funding/, hidden: true }),
    ).toHaveAttribute('aria-checked', 'true');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByLabelText(/Your name/)).toHaveAttribute(
        'aria-invalid',
        'true',
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText(/Your name/), {
        target: { value: 'Fictional Person' },
    });
    fireEvent.change(screen.getByLabelText(/Phone number/), {
        target: { value: '0210000000' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    review();
    const leavingDraft = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leavingDraft);
    expect(leavingDraft.defaultPrevented).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Send referral' }));
    await screen.findByText('Thanks — your referral is with us');
    expect(screen.getByText('REF-PREVIEW')).toBeInTheDocument();
    const leavingReceipt = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leavingReceipt);
    expect(leavingReceipt.defaultPrevented).toBe(false);
    expect(post.mock.calls[0][1]).toMatchObject({
        purpose: 'funding',
        requested_service: 'supported_living',
        notice_version: 'v1',
        contact_permission: true,
        contact: { leave_message: false },
    });
});

it('asks before discarding answers when the wizard is closed part-way', async () => {
    begin();
    fireEvent.change(screen.getByLabelText(/Your name/), {
        target: { value: 'Fictional Person' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(
        await screen.findByRole('alertdialog', {
            name: 'Discard this referral?',
        }),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Keep going' }));
    await waitFor(() =>
        expect(screen.getByLabelText(/Your name/)).toHaveValue(
            'Fictional Person',
        ),
    );
});

it('retries the identical submission after an uncertain response without allowing edits', async () => {
    post.mockRejectedValueOnce(
        new Error('Connection interrupted'),
    ).mockResolvedValueOnce({
        data: { status: 'received', reference: 'REF-ONCE' },
    });
    begin('business');
    contact();
    expect(
        screen.queryByLabelText('Person’s first name (optional)'),
    ).not.toBeInTheDocument();
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Send referral' }));
    await screen.findByRole('button', { name: 'Retry this submission' });
    expect(screen.getByRole('button', { name: 'Back' })).toBeDisabled();
    expect(
        screen.getByRole('checkbox', { name: /I have read/ }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry this submission' }),
    );
    await screen.findByText('Thanks — your referral is with us');
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
    expect(post.mock.calls[0][1].person).toEqual({});
});

it('requires renewed notice acknowledgement when current published information changes', async () => {
    post.mockRejectedValueOnce({
        isAxiosError: true,
        response: {
            status: 422,
            data: {
                errors: {
                    notice_version: ['Review the current privacy notice.'],
                },
            },
        },
    });
    begin();
    contact();
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Send referral' }));
    await waitFor(() =>
        expect(
            screen.getAllByText('Review the current privacy notice.').length,
        ).toBeGreaterThan(0),
    );
    const refresh = screen.getByRole('button', {
        name: 'Load the latest notice and keep my answers',
    });
    fireEvent.click(refresh);
    expect(reload).toHaveBeenCalled();
    const options = reload.mock.calls[0][0];
    options.onSuccess({
        props: {
            intake: { ...intake, notice_version: 'v2', settings_version_id: 2 },
        },
    });
    options.onFinish();
    await waitFor(() =>
        expect(
            screen.getByRole('checkbox', { name: /I have read/ }),
        ).not.toBeChecked(),
    );
    expect(
        screen.getByRole('button', { name: 'Send referral' }),
    ).toBeDisabled();
});

it('returns a server error about the page choices to the tiles', async () => {
    post.mockRejectedValueOnce({
        isAxiosError: true,
        response: {
            status: 422,
            data: {
                errors: {
                    authority_status: [
                        'Choose the authority that applies to the person you are contacting us for.',
                    ],
                },
            },
        },
    });
    begin();
    contact();
    review();
    fireEvent.click(screen.getByRole('button', { name: 'Send referral' }));
    await waitFor(() =>
        expect(
            screen.getByText('Please check your choices'),
        ).toBeInTheDocument(),
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});
