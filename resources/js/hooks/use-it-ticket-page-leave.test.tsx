import { Button } from '@/components/ui/button';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useItTicketPageLeave } from './use-it-ticket-page-leave';

type Visit = { method: string; url: URL; preserveState?: boolean };
type Before = (event: {
    detail: { visit: Visit };
    preventDefault: () => void;
}) => void;
const events = vi.hoisted(() => ({
    before: undefined as Before | undefined,
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        on: (_name: string, listener: Before) => {
            events.before = listener;
            return () => {
                if (events.before === listener) events.before = undefined;
            };
        },
        visit: events.visit,
    },
}));

function Harness({
    actorId = 8,
    ticketId = 42,
    additionalDirty = false,
}: {
    actorId?: number;
    ticketId?: number;
    additionalDirty?: boolean;
}) {
    const guard = useItTicketPageLeave({ actorId, ticketId, additionalDirty });
    return (
        <>
            <Button
                onClick={() =>
                    guard.onDraftStateChange({ dirty: true, busy: false })
                }
            >
                Enter reply
            </Button>
            <Button
                onClick={() =>
                    guard.onDraftStateChange({ dirty: false, busy: true })
                }
            >
                Submit reply
            </Button>
            <Button
                onClick={() =>
                    guard.onDraftStateChange({ dirty: false, busy: false })
                }
            >
                Clear reply
            </Button>
            {guard.confirmation}
        </>
    );
}

function navigate(path = '/it', options: Partial<Visit> = {}) {
    const preventDefault = vi.fn();
    const visit = {
        method: 'get',
        url: new URL(path, window.location.origin),
        ...options,
    };
    act(() => events.before?.({ detail: { visit }, preventDefault }));
    return { visit, preventDefault };
}

beforeEach(() => {
    events.before = undefined;
    events.visit.mockReset();
});

describe('Ticket page unsaved navigation', () => {
    it('requires an explicit discard for outside navigation and allows cancellation', () => {
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Enter reply' }));
        expect(navigate().preventDefault).toHaveBeenCalledOnce();
        expect(screen.getByRole('alertdialog')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(events.visit).not.toHaveBeenCalled();
        const attempt = navigate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(events.visit).toHaveBeenCalledExactlyOnceWith(
            attempt.visit.url,
            attempt.visit,
        );
    });

    it('allows retained same-ticket section navigation and reply posts but guards a fresh page visit', () => {
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Enter reply' }));
        expect(
            navigate('/it/tickets/42?tab=files', { preserveState: true })
                .preventDefault,
        ).not.toHaveBeenCalled();
        expect(
            navigate('/it/tickets/42/comments', { method: 'post' })
                .preventDefault,
        ).not.toHaveBeenCalled();
        expect(
            navigate('/it/tickets/42', { preserveState: false }).preventDefault,
        ).toHaveBeenCalledOnce();
    });

    it('warns before refresh only while reply or classification work is unsaved', () => {
        const view = render(<Harness additionalDirty />);
        const first = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(first);
        expect(first.defaultPrevented).toBe(true);
        view.rerender(<Harness />);
        const clean = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);
        fireEvent.click(screen.getByRole('button', { name: 'Enter reply' }));
        fireEvent.click(screen.getByRole('button', { name: 'Clear reply' }));
        expect(navigate().preventDefault).not.toHaveBeenCalled();
    });

    it('discards stale pending navigation when actor or ticket changes', () => {
        const view = render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Enter reply' }));
        navigate();
        view.rerender(<Harness actorId={9} />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(navigate().preventDefault).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Enter reply' }));
        navigate();
        view.rerender(<Harness actorId={9} ticketId={43} />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(events.visit).not.toHaveBeenCalled();
    });

    it('describes an in-flight reply as uncertain rather than cancelled', () => {
        render(<Harness />);
        fireEvent.click(screen.getByRole('button', { name: 'Submit reply' }));
        navigate();
        expect(
            screen.getByText('Leave while the reply is saving?'),
        ).toBeVisible();
        expect(
            screen.getByText(/Leaving does not cancel a submitted reply/),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Leave and check later' }),
        ).toBeVisible();
    });
});
