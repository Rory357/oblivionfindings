import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ShowDataSubjectRequest from './show';

// Verify, complete, extend and refuse used to collect their details through
// browser prompts (two chained prompts for extend and refuse). Each now opens
// one dialog with labelled, validated fields; nothing is sent until its submit
// button, and the payload keys are the ones the controller validates.

type VisitOptions = {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const router = vi.hoisted(() => ({
    post: vi.fn(
        (_url: string, _data: unknown, options?: VisitOptions) => {
            options?.onStart?.();
            options?.onSuccess?.();
            options?.onFinish?.();
        },
    ),
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: ({
        title,
        children,
    }: PropsWithChildren<{ title?: ReactNode }>) => (
        <>
            <h1>{title}</h1>
            {children}
        </>
    ),
    PageLayout: ({
        hero,
        children,
    }: PropsWithChildren<{ hero?: ReactNode }>) => (
        <>
            {hero}
            {children}
        </>
    ),
}));

const pending = {
    id: 12,
    reference_number: 'PR-2026-012',
    request_type: 'access',
    status: 'identity_verification',
    identity_verified: 'pending',
    subject_name: 'Aroha Ngata',
    subject_email: 'aroha@example.test',
    received_at: '2026-10-10T00:00:00Z',
    due_date: '2026-11-07',
    extended_due_date: null,
    request_details: 'All records about me.',
};
const inProgress = {
    ...pending,
    status: 'in_progress',
    identity_verified: 'verified',
    identity_verified_at: '2026-10-12T00:00:00Z',
    verification_method: 'Passport sighted',
};

const dialog = () => screen.getByRole('dialog');
const press = (name: string) =>
    fireEvent.click(screen.getByRole('button', { name }));
const pressInDialog = (name: string) =>
    fireEvent.click(within(dialog()).getByRole('button', { name }));
const fill = (label: string | RegExp, value: string) =>
    fireEvent.change(within(dialog()).getByLabelText(label), {
        target: { value },
    });

beforeEach(() => {
    router.post.mockClear();
    // 20 Oct 2026, 1 pm in New Zealand: "after today" means 21 Oct onwards.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-20T00:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Privacy › request: verify identity', () => {
    it('opens a dialog without sending, and Cancel sends nothing', async () => {
        render(<ShowDataSubjectRequest request={pending} staff={[]} />);
        press('Verify Identity');

        expect(router.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('dialog', {
                name: "Verify Aroha Ngata's identity?",
            }),
        ).toBeInTheDocument();

        pressInDialog('Cancel');
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(router.post).not.toHaveBeenCalled();
    });

    it('requires the method, caps it at 255 characters, then sends it', async () => {
        render(<ShowDataSubjectRequest request={pending} staff={[]} />);
        press('Verify Identity');

        pressInDialog('Verify identity');
        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent(
            'Say how you checked their identity.',
        );

        fill('How you checked their identity', '   ');
        pressInDialog('Verify identity');
        expect(router.post).not.toHaveBeenCalled();

        fill('How you checked their identity', 'x'.repeat(256));
        pressInDialog('Verify identity');
        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent(
            'Keep this to 255 characters or fewer.',
        );

        fill('How you checked their identity', 'Passport sighted in person');
        pressInDialog('Verify identity');

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/requests/12/verify-identity',
            { verification_method: 'Passport sighted in person' },
            expect.any(Object),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });
});

describe('Privacy › request: mark complete', () => {
    it('sends optional completion notes', () => {
        render(<ShowDataSubjectRequest request={inProgress} staff={[]} />);
        press('Mark Complete');

        expect(router.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('dialog', {
                name: 'Mark this request as complete?',
            }),
        ).toHaveTextContent("It can't be reopened.");

        pressInDialog('Mark complete');
        expect(router.post).toHaveBeenCalledWith(
            '/privacy/requests/12/complete',
            { completion_notes: '' },
            expect.any(Object),
        );
    });

    it('sends the notes when given', () => {
        render(<ShowDataSubjectRequest request={inProgress} staff={[]} />);
        press('Mark Complete');
        fill(/Completion notes/, 'Copy of records emailed.');
        pressInDialog('Mark complete');

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/requests/12/complete',
            { completion_notes: 'Copy of records emailed.' },
            expect.any(Object),
        );
    });
});

describe('Privacy › request: extend the due date', () => {
    it('collects the date and reason in one dialog, after today only', async () => {
        render(<ShowDataSubjectRequest request={inProgress} staff={[]} />);
        press('Extend Deadline');

        expect(router.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('dialog', {
                name: 'Extend the due date for this request?',
            }),
        ).toBeInTheDocument();
        const date = within(dialog()).getByLabelText('New due date');
        expect(date).toHaveAttribute('type', 'date');
        expect(date).toHaveAttribute('min', '2026-10-21');

        pressInDialog('Extend due date');
        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent('Choose the new due date.');
        expect(dialog()).toHaveTextContent('Add the reason for the extension.');

        fill('New due date', '2026-10-20');
        fill('Reason for the extension', 'Records held by a former provider.');
        pressInDialog('Extend due date');
        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent('Choose a date after today.');

        fill('New due date', '2026-12-04');
        pressInDialog('Extend due date');

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/requests/12/extend',
            {
                extension_reason: 'Records held by a former provider.',
                extended_due_date: '2026-12-04',
            },
            expect.any(Object),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('shows a server date error under the date field', () => {
        router.post.mockImplementationOnce(
            (_url: string, _data: unknown, options?: VisitOptions) => {
                options?.onStart?.();
                options?.onError?.({
                    extended_due_date:
                        'The extended due date field must be a date after today.',
                });
                options?.onFinish?.();
            },
        );
        render(<ShowDataSubjectRequest request={inProgress} staff={[]} />);
        press('Extend Deadline');
        fill('New due date', '2026-10-21');
        fill('Reason for the extension', 'Waiting on archived files.');
        pressInDialog('Extend due date');

        expect(dialog()).toHaveTextContent(
            'The extended due date field must be a date after today.',
        );
        expect(within(dialog()).getByLabelText('New due date')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });
});

describe('Privacy › request: refuse', () => {
    it('states the effect, requires both fields, then refuses', async () => {
        render(<ShowDataSubjectRequest request={inProgress} staff={[]} />);
        press('Refuse Request');

        expect(router.post).not.toHaveBeenCalled();
        const refuse = screen.getByRole('dialog', {
            name: 'Refuse this request?',
        });
        expect(refuse).toHaveTextContent(
            "It can't be reopened or changed afterwards.",
        );
        expect(refuse).toHaveTextContent("The requester isn't told automatically");

        pressInDialog('Refuse request');
        expect(router.post).not.toHaveBeenCalled();
        expect(refuse).toHaveTextContent('Add the reason for refusing.');
        expect(refuse).toHaveTextContent('Add the legal basis for refusing.');

        fill('Reason for refusing', 'Would disclose another person’s affairs.');
        fill('Legal basis', 'x'.repeat(256));
        pressInDialog('Refuse request');
        expect(router.post).not.toHaveBeenCalled();
        expect(refuse).toHaveTextContent('Keep this to 255 characters or fewer.');

        fill('Legal basis', 'Privacy Act 2020, section 53');
        pressInDialog('Refuse request');

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/requests/12/refuse',
            {
                rejection_reason: 'Would disclose another person’s affairs.',
                rejection_legal_basis: 'Privacy Act 2020, section 53',
            },
            expect.any(Object),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('Cancel sends nothing', async () => {
        render(<ShowDataSubjectRequest request={inProgress} staff={[]} />);
        press('Refuse Request');
        fill('Reason for refusing', 'Not needed');
        pressInDialog('Cancel');

        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(router.post).not.toHaveBeenCalled();
    });

    it('starts empty when reopened after Cancel', () => {
        render(<ShowDataSubjectRequest request={inProgress} staff={[]} />);
        press('Refuse Request');
        fill('Reason for refusing', 'Draft reason');
        pressInDialog('Cancel');
        press('Refuse Request');

        expect(
            within(dialog()).getByLabelText('Reason for refusing'),
        ).toHaveValue('');
    });
});
