import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StaffCredentials from './credentials';

// Removing a credential used browser confirm(). It now opens the shared
// ConfirmDialog and only "Remove credential" sends the request.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const form = vi.hoisted(() => ({
    delete: vi.fn((_url: string, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    useForm: <T extends object>(data: T) => ({
        data,
        setData: vi.fn(),
        post: vi.fn(),
        delete: form.delete,
        processing: false,
        recentlySuccessful: false,
        errors: {},
    }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({ PageHero: () => null }));
vi.mock('@/components/page-shell', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));

const renderPage = () =>
    render(
        <StaffCredentials
            user={{ id: 8, name: 'Tama Hohaia', email: 'tama@example.test' }}
            credentials={[
                {
                    id: 21,
                    type: 'First Aid',
                    issuer: 'Red Cross',
                    issued_at: '2025-03-01',
                    expires_at: '2027-03-01',
                },
            ]}
            canManage
        />,
    );

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    form.delete.mockClear();
});

describe('Staff › Credentials: remove', () => {
    it('asks before removing, and Cancel removes nothing', async () => {
        renderPage();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove First Aid' }),
        );

        expect(form.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Remove this credential?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            "First Aid is deleted from Tama Hohaia's credentials and can't be brought back.",
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(form.delete).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('removes the credential once confirmed', async () => {
        renderPage();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove First Aid' }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Remove credential',
            }),
        );

        expect(form.delete).toHaveBeenCalledTimes(1);
        expect(form.delete).toHaveBeenCalledWith(
            '/staff/8/credentials/21',
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});
