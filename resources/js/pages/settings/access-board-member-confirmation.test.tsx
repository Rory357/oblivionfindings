import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AccessControlPage from './access';

// Settings › Overrides & Governance: removing a board member must ask first.
// Nothing reaches the server until the confirm button, and the request is
// the same DELETE as before.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const formDelete = vi.hoisted(() =>
    vi.fn((_url: string, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
);

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    usePage: () => ({
        props: { auth: { can: { settings: { manageAccess: true } } } },
    }),
    useForm: <T extends object>(data: T) => ({
        data,
        setData: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: formDelete,
        reset: vi.fn(),
        processing: false,
        errors: {},
    }),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));

const member = {
    id: 12,
    user_id: 5,
    board_role: 'secretary',
    term_start: '2026-01-01',
    term_end: '2028-12-31',
    notes: null,
    is_active: true,
    user: { id: 5, name: 'Aroha Ngata', email: 'aroha@example.test' },
};

const openBoardTab = () => {
    render(
        <AccessControlPage
            users={[]}
            roles={[]}
            permissions={[]}
            userOverrides={{}}
            boardMembers={[member]}
        />,
    );
    fireEvent.mouseDown(screen.getByRole('tab', { name: /Board & Governance/ }));
};

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    formDelete.mockClear();
});

describe('Settings › Board & Governance: remove board member', () => {
    it('asks before removing, and Cancel removes nothing', () => {
        openBoardTab();

        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));

        expect(formDelete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Remove this board member?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Aroha Ngata comes off the board, and their board roles are removed',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(formDelete).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });

    it('removes the chosen board member once confirmed', () => {
        openBoardTab();

        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Remove board member',
            }),
        );

        expect(formDelete).toHaveBeenCalledTimes(1);
        expect(formDelete).toHaveBeenCalledWith(
            '/settings/board-members/12',
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });
});
