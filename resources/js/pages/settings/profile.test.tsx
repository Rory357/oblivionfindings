import { render, screen } from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Profile from './profile';

const page = vi.hoisted(() => ({
    props: {
        auth: {
            user: {
                id: 1,
                name: 'Aroha Support',
                email: 'aroha@example.test',
                avatar: undefined,
            },
        },
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    Form: ({
        children,
    }: {
        children: (state: {
            processing: boolean;
            recentlySuccessful: boolean;
            errors: Record<string, string>;
            resetAndClearErrors: () => void;
        }) => ReactNode;
    }) => (
        <form>
            {children({
                processing: false,
                recentlySuccessful: false,
                errors: {},
                resetAndClearErrors: vi.fn(),
            })}
        </form>
    ),
    usePage: () => page,
    useForm: <T extends object>(data: T) => ({
        data,
        setData: vi.fn(),
        patch: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
        processing: false,
        recentlySuccessful: false,
        errors: {},
    }),
    router: { put: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: ({ title }: { title: string }) => <h1>{title}</h1>,
}));

function renderProfile(phone: string | null) {
    return render(
        <Profile
            mustVerifyEmail={false}
            profile={{
                ...{ onCallCellphoneConsentedAt: null },
                phone,
                jobTitle: 'Support Worker',
                timezone: 'Pacific/Auckland',
                locale: 'en',
                dateFormat: 'DD/MM/YYYY',
                timeFormat: '24',
                landingRoutePreference: null,
                landingOptions: [],
                emailVerifiedAt: '2026-01-01T00:00:00+13:00',
                createdAt: '2025-01-01T00:00:00+13:00',
                updatedAt: null,
                lastLoginAt: null,
                passwordChangedAt: null,
                roles: ['Support Worker'],
                twoFactorEnabled: false,
                microsoftLinked: false,
                googleLinked: false,
                profilePhotoPath: null,
            }}
        />,
    );
}

describe('Settings profile phone field', () => {
    it('is labelled as the personal mobile and says it stays out of the staff directory', () => {
        renderProfile('021 555 0101');

        const field = screen.getByLabelText('Personal mobile');

        expect(field).toHaveValue('021 555 0101');
        expect(field).toHaveAttribute('name', 'phone');
        expect(field).toHaveAccessibleDescription(
            'Not shown in the staff directory. HR manages your work phone.',
        );
        expect(screen.queryByLabelText('Phone number')).toBeNull();
    });

    it('starts empty when no personal mobile is saved', () => {
        renderProfile(null);

        expect(screen.getByLabelText('Personal mobile')).toHaveValue('');
    });
});
