import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: T[keyof T]) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                post: (
                    url: string,
                    options: {
                        preserveScroll?: boolean;
                        onSuccess?: (page: { props: object }) => void;
                    },
                ) => {
                    inertia.post(url, data, options);
                    options.onSuccess?.({ props: { flash: {} } });
                },
            };
        },
    };
});

vi.mock('@/components/wizard/shell', async () => {
    const actual = await vi.importActual<
        typeof import('@/components/wizard/shell')
    >('@/components/wizard/shell');

    return {
        ...actual,
        ReviewCard: ({
            title,
            children,
        }: {
            title: string;
            children: ReactNode;
        }) => (
            <section>
                <h3>{title}</h3>
                {children}
            </section>
        ),
    };
});

import { CorrectiveActionHandoverPane } from './corrective-action-handover-pane';

const handover = {
    eligible_owners: [
        { id: 81, name: 'Playwright Incident Reviewer' },
        { id: 82, name: 'Playwright H&S Owner' },
    ],
    unresolved_control_room_tasks: [
        {
            id: 501,
            reference: 'CR task #501',
            title: 'Replace the unsafe bathroom rail',
            description: 'Permanent repair and evidence required.',
            status: 'in_progress',
            priority: 'high',
            due_at: '2026-08-20T00:00:00+12:00',
        },
    ],
};

afterEach(cleanup);

beforeEach(() => {
    inertia.post.mockReset();
});

describe('CorrectiveActionHandoverPane', () => {
    it('requires and submits the exact transferred responsibility', () => {
        render(
            <CorrectiveActionHandoverPane
                eventId={17}
                investigationId={31}
                recommendationIndex={0}
                recommendation={{
                    description: 'Install a permanent bathroom safety rail.',
                    priority: 'high',
                }}
                handover={handover}
                onDone={() => {}}
            />,
        );

        expect(
            screen.getByText('Install a permanent bathroom safety rail.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Replace the unsafe bathroom rail'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Create and hand over action',
            }),
        ).toBeDisabled();

        fireEvent.click(screen.getByRole('combobox', { name: 'Action owner' }));
        fireEvent.click(
            screen.getByRole('option', {
                name: 'Playwright Incident Reviewer',
            }),
        );
        fireEvent.change(screen.getByLabelText('Due date'), {
            target: { value: '2026-08-31' },
        });
        fireEvent.click(
            screen.getByLabelText('Transfer this operational task'),
        );
        fireEvent.click(
            screen.getByRole('combobox', {
                name: 'Source Control Room task',
            }),
        );
        fireEvent.click(
            screen.getByRole('option', {
                name: /CR task #501.*Replace the unsafe bathroom rail/,
            }),
        );

        const review = screen
            .getByRole('heading', { name: 'Final handover review' })
            .closest('section');
        expect(review).not.toBeNull();
        expect(
            within(review as HTMLElement).getByText(
                'Playwright Incident Reviewer',
            ),
        ).toBeInTheDocument();
        const submit = screen.getByRole('button', {
            name: 'Create and hand over action',
        });
        expect(submit).toBeEnabled();
        fireEvent.click(submit);

        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/events/17/investigations/31/seed-action',
            {
                recommendation_index: 0,
                assigned_to_user_id: 81,
                due_date: '2026-08-31',
                priority: 'high',
                responsibility_choice: 'transfer_task',
                source_control_room_task_id: 501,
                new_responsibility_reason: '',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('requires a reason before creating a new responsibility', () => {
        render(
            <CorrectiveActionHandoverPane
                eventId={17}
                investigationId={31}
                recommendationIndex={1}
                recommendation={{
                    description: 'Introduce monthly safety checks.',
                    priority: 'medium',
                }}
                handover={handover}
                onDone={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('combobox', { name: 'Action owner' }));
        fireEvent.click(
            screen.getByRole('option', { name: 'Playwright H&S Owner' }),
        );
        fireEvent.change(screen.getByLabelText('Due date'), {
            target: { value: '2026-09-15' },
        });
        fireEvent.click(
            screen.getByLabelText('Create a new H&S responsibility'),
        );

        const submit = screen.getByRole('button', {
            name: 'Create and hand over action',
        });
        expect(submit).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Why is this new work?'), {
            target: {
                value: 'No current operational task covers this recommendation.',
            },
        });
        expect(submit).toBeEnabled();
    });
});
