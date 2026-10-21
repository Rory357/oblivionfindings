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

const { post, reload } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            !!error.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload, on: () => () => {}, visit: vi.fn() },
}));
vi.mock('@/layouts/marketing-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
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
    fireEvent.click(screen.getByRole('button', { name: 'Start an enquiry' }));
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
    fireEvent.click(screen.getByRole('button', { name: 'Send enquiry' }));
    await screen.findByText('Your enquiry is saved');
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
    fireEvent.click(screen.getByRole('button', { name: 'Start an enquiry' }));
}
function contact() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
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
    expect(screen.getByRole('button', { name: 'Send enquiry' })).toBeDisabled();
    fireEvent.click(
        screen.getByRole('checkbox', { name: /You may contact me/ }),
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /I have read/ }));
}

it('keeps website funding and service choices, requires safe contact, then saves a durable receipt', async () => {
    post.mockResolvedValue({
        data: { status: 'received', reference: 'REF-PREVIEW' },
    });
    begin();
    expect(
        screen.getByRole('combobox', {
            name: 'What would you like help with?',
        }),
    ).toHaveTextContent('Help with funding');
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
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
    fireEvent.click(screen.getByRole('button', { name: 'Send enquiry' }));
    await screen.findByText('Your enquiry is saved');
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
    fireEvent.click(screen.getByRole('button', { name: 'Send enquiry' }));
    await screen.findByRole('button', { name: 'Retry this submission' });
    expect(screen.getByRole('button', { name: 'Back' })).toBeDisabled();
    expect(
        screen.getByRole('checkbox', { name: /I have read/ }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry this submission' }),
    );
    await screen.findByText('Your enquiry is saved');
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
    fireEvent.click(screen.getByRole('button', { name: 'Send enquiry' }));
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
    expect(screen.getByRole('button', { name: 'Send enquiry' })).toBeDisabled();
});
