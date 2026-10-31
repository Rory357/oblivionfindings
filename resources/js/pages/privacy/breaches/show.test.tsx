import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShowDataBreach from './show';

// Breach actions used to collect their evidence through browser prompts. Each
// now opens a dialog with labelled fields; nothing is sent until its submit
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

const breach = {
    id: 7,
    breach_reference: 'BR-2026-007',
    status: 'discovered',
    discovered_at: '2026-10-18T21:00:00Z',
    nature_of_breach: 'A report link was shared outside the team.',
    requires_authority_notification: true,
    requires_subject_notification: true,
    authority_notified_at: null,
    subjects_notified_at: null,
    resolved_at: null,
};

const dialog = () => screen.getByRole('dialog');
const press = (name: string) =>
    fireEvent.click(screen.getByRole('button', { name }));
const pressInDialog = (name: string) =>
    fireEvent.click(within(dialog()).getByRole('button', { name }));

beforeEach(() => {
    router.post.mockClear();
});

describe('Privacy › breach: record the Privacy Commissioner notification', () => {
    it('opens a dialog without sending, and Cancel sends nothing', async () => {
        render(<ShowDataBreach breach={breach} />);
        press('Record OPC Notification');

        expect(router.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('dialog', {
                name: "Record that you've notified the Privacy Commissioner?",
            }),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Office of the Privacy Commissioner',
        );
        expect(dialog()).toHaveTextContent("This can't be undone.");

        pressInDialog('Cancel');
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(router.post).not.toHaveBeenCalled();
    });

    it('sends an empty reference when none is given (the field is optional)', async () => {
        render(<ShowDataBreach breach={breach} />);
        press('Record OPC Notification');
        pressInDialog('Record notification');

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/breaches/7/notify-opc',
            { authority_reference: '' },
            expect.any(Object),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('sends the reference, and blocks one longer than 255 characters', () => {
        render(<ShowDataBreach breach={breach} />);
        press('Record OPC Notification');
        const field = within(dialog()).getByLabelText(
            /Privacy Commissioner's reference/,
        );

        fireEvent.change(field, { target: { value: 'x'.repeat(256) } });
        pressInDialog('Record notification');
        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent(
            'Keep this to 255 characters or fewer.',
        );

        fireEvent.change(field, { target: { value: ' NU-2026-0042 ' } });
        pressInDialog('Record notification');
        expect(router.post).toHaveBeenCalledWith(
            '/privacy/breaches/7/notify-opc',
            { authority_reference: 'NU-2026-0042' },
            expect.any(Object),
        );
    });
});

describe('Privacy › breach: record that affected people were told', () => {
    it('requires how they were told, then sends it', async () => {
        render(<ShowDataBreach breach={breach} />);
        press('Record Subject Notification');

        expect(router.post).not.toHaveBeenCalled();
        expect(
            screen.getByRole('dialog', {
                name: 'Record that the affected people were told?',
            }),
        ).toBeInTheDocument();

        pressInDialog('Record notification');
        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent(
            'Say how you told the affected people.',
        );

        fireEvent.change(
            within(dialog()).getByLabelText('How you told them'),
            { target: { value: 'Letter and phone call' } },
        );
        pressInDialog('Record notification');

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/breaches/7/notify-subjects',
            { notification_method: 'Letter and phone call' },
            expect.any(Object),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });
});

describe('Privacy › breach: mark resolved', () => {
    it('requires resolution notes, then sends them', () => {
        render(<ShowDataBreach breach={breach} />);
        press('Mark Resolved');

        expect(
            screen.getByRole('dialog', {
                name: 'Mark this breach as resolved?',
            }),
        ).toBeInTheDocument();

        pressInDialog('Mark resolved');
        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent('Add resolution notes.');

        fireEvent.change(
            within(dialog()).getByLabelText('Resolution notes'),
            { target: { value: 'Link revoked; sharing settings tightened.' } },
        );
        pressInDialog('Mark resolved');

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/breaches/7/resolve',
            { resolution_notes: 'Link revoked; sharing settings tightened.' },
            expect.any(Object),
        );
    });

    it('keeps the dialog open and shows a server error under the field', () => {
        router.post.mockImplementationOnce(
            (_url: string, _data: unknown, options?: VisitOptions) => {
                options?.onStart?.();
                options?.onError?.({
                    resolution_notes: 'The resolution notes field is required.',
                });
                options?.onFinish?.();
            },
        );
        render(<ShowDataBreach breach={breach} />);
        press('Mark Resolved');
        fireEvent.change(
            within(dialog()).getByLabelText('Resolution notes'),
            { target: { value: 'Done' } },
        );
        pressInDialog('Mark resolved');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(dialog()).toHaveTextContent(
            'The resolution notes field is required.',
        );
        expect(
            within(dialog()).getByLabelText('Resolution notes'),
        ).toHaveAttribute('aria-invalid', 'true');
    });

    it('disables both buttons while the request is in flight', () => {
        router.post.mockImplementationOnce(
            (_url: string, _data: unknown, options?: VisitOptions) => {
                options?.onStart?.();
            },
        );
        render(<ShowDataBreach breach={breach} />);
        press('Mark Resolved');
        fireEvent.change(
            within(dialog()).getByLabelText('Resolution notes'),
            { target: { value: 'Done' } },
        );
        pressInDialog('Mark resolved');

        expect(
            within(dialog()).getByRole('button', { name: 'Mark resolved' }),
        ).toBeDisabled();
        expect(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        ).toBeDisabled();
        fireEvent.keyDown(dialog(), { key: 'Escape' });
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });
});
