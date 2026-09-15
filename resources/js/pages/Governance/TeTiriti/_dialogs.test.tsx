import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        Link: ({ href, children }: { href: string; children: React.ReactNode }) =>
            ReactActual.createElement('a', { href }, children),
        router: { visit: vi.fn() },
        usePage: () => ({ props: { flash: {}, auth: { user: { id: 7 } } } }),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initial);
            const transformRef = ReactActual.useRef<
                ((d: Record<string, unknown>) => Record<string, unknown>) | null
            >(null);
            const send =
                (spy: typeof inertia.post) =>
                (url: string, options?: { onSuccess?: (page: unknown) => void }) => {
                    spy(url, transformRef.current ? transformRef.current(data) : data);
                    options?.onSuccess?.({ props: { flash: {} } });
                };
            return {
                data,
                errors: {},
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (
                    keyOrUpdater:
                        | string
                        | ((prev: Record<string, unknown>) => Record<string, unknown>),
                    value?: unknown,
                ) =>
                    setDataState((current) =>
                        typeof keyOrUpdater === 'function'
                            ? keyOrUpdater(current)
                            : { ...current, [keyOrUpdater]: value },
                    ),
                transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                    transformRef.current = fn;
                },
                clearErrors: vi.fn(),
                post: send(inertia.post),
                put: send(inertia.put),
            };
        },
    };
});

import {
    implementationStatusLabel,
    implementationStatusVariant,
    TeTiritiObligationWizardDialog,
} from './_dialogs';

const principles = [
    {
        value: 'tino_rangatiratanga',
        label: 'Tino rangatiratanga',
        description: 'Māori make the decisions about their own health and wellbeing.',
    },
    {
        value: 'options',
        label: 'Options (Kōwhiringa)',
        description: 'Māori can choose kaupapa Māori or culturally safe services.',
    },
];

const owners = [
    { id: 3, name: 'Aroha Lead' },
    { id: 7, name: 'Current User' },
];

afterEach(() => {
    cleanup();
    inertia.post.mockClear();
});

describe('Te Tiriti commitments', () => {
    it('uses "Done" and "Part of everyday practice", both in the success tone', () => {
        expect(implementationStatusLabel('implemented')).toBe('Done');
        expect(implementationStatusLabel('embedded')).toBe('Part of everyday practice');
        expect(implementationStatusVariant('implemented')).toBe('success');
        expect(implementationStatusVariant('embedded')).toBe('success');
    });

    it('shows each principle with its description and saves the owner', () => {
        render(
            <TeTiritiObligationWizardDialog
                open
                onClose={vi.fn()}
                principles={principles}
                owners={owners}
            />,
        );

        expect(screen.getAllByText('Add commitment').length).toBeGreaterThan(0);
        expect(
            screen.getByText('Māori can choose kaupapa Māori or culturally safe services.'),
        ).toBeTruthy();
        expect(screen.queryByText(/obligation/i)).toBeNull();

        fireEvent.change(screen.getByPlaceholderText(/Māori representation on the board/i), {
            target: { value: 'Kaupapa Māori service options' },
        });
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        fireEvent.change(screen.getByPlaceholderText(/What the organisation commits to/i), {
            target: { value: 'Offer kaupapa Māori providers for every service.' },
        });
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(screen.getByRole('button', { name: /^add commitment$/i }));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post).toHaveBeenCalledWith(
            '/governance/te-tiriti',
            expect.objectContaining({
                principle: 'tino_rangatiratanga',
                title: 'Kaupapa Māori service options',
                owner_id: 7,
                implementation_status: 'not_started',
                target_date: null,
            }),
        );
    });
});
