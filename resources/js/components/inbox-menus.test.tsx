import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router } from '@inertiajs/react';
import InboxMenus from './inbox-menus';
const inboxFixture = vi.hoisted(() => ({
    items: [] as Array<{
        id: string;
        type: string;
        read_at: string | null;
        data: { type: string; title: string; action_url: string };
    }>,
}));
afterEach(() => {
    cleanup();
    inboxFixture.items = [];
    vi.clearAllMocks();
});

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        post: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({
        props: {
            inbox: {
                notifications: {
                    unread_count: 2,
                    items: inboxFixture.items,
                },
                announcements: {
                    unread_count: 0,
                    items: [],
                },
            },
        },
    }),
}));

describe('InboxMenus', () => {
    it.each([null, '2026-10-06T09:00:00Z'])(
        'opens the named confirmation directly after marking an unread notification read (%s)',
        (readAt) => {
            inboxFixture.items = [
                {
                    id: 'confirmation-note',
                    type: 'MedicationSecondPersonConfirmationNotification',
                    read_at: readAt,
                    data: {
                        type: 'medication_second_person_confirmation',
                        title: 'Were you there?',
                        action_url: '/medication-followups?open=91',
                    },
                },
            ];
            render(<InboxMenus />);
            fireEvent.pointerDown(
                screen.getByRole('button', { name: 'Notifications' }),
            );
            fireEvent.click(
                screen.getByRole('menuitem', { name: /Were you there/ }),
            );
            if (!readAt) {
                expect(router.visit).not.toHaveBeenCalled();
                expect(router.post).toHaveBeenCalledWith(
                    '/inbox/notifications/confirmation-note/read',
                    {},
                    expect.objectContaining({
                        onSuccess: expect.any(Function),
                    }),
                );
                const options = vi.mocked(router.post).mock.calls[0][2];
                options?.onSuccess?.({} as never);
            } else {
                expect(router.post).not.toHaveBeenCalled();
            }
            expect(router.visit).toHaveBeenCalledWith(
                '/medication-followups?open=91',
            );
            expect(router.reload).not.toHaveBeenCalled();
        },
    );
    it('exposes a labelled mark-all-read action for notifications', () => {
        render(<InboxMenus />);

        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'Notifications' }),
        );

        expect(
            screen.getByRole('button', { name: 'Mark all notifications read' }),
        ).toHaveAttribute('aria-label', 'Mark all notifications read');
    });
});
