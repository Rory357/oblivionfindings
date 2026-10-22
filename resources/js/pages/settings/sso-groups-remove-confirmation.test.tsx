import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SsoGroups from './sso-groups';

// Legacy SSO group mapping page: removing a mapping must ask first. Nothing
// reaches the server until the confirm button, and the request is the same
// DELETE as before.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn((_url: string, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    useForm: <T extends object>(data: T) => ({
        data,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
        processing: false,
        errors: {},
    }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: () => null,
}));

const renderPage = () =>
    render(
        <SsoGroups
            mappings={[
                {
                    id: 41,
                    provider: 'microsoft',
                    external_group_id: 'group-41',
                    external_group_name: 'Rimu House team leads',
                    role_id: 3,
                    auto_assign: true,
                    auto_remove: false,
                    last_synced_at: null,
                    role: { id: 3, name: 'team_lead', label: 'Team lead' },
                },
            ]}
            roles={[{ id: 3, name: 'team_lead', label: 'Team lead' }]}
            stats={{ total: 1, microsoft: 1, google: 0 }}
        />,
    );

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.delete.mockClear();
});

describe('Settings › SSO group mapping (legacy page): remove mapping', () => {
    it('asks before removing, and Cancel removes nothing', () => {
        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Remove mapping for Rimu House team leads',
            }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Remove this group mapping?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Rimu House team leads will no longer give people the Team lead role',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
    });

    it('removes the chosen mapping once confirmed', () => {
        renderPage();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Remove mapping for Rimu House team leads',
            }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Remove mapping' }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/settings/sso-groups/41',
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });
});
