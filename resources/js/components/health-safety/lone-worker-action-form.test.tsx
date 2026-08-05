import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        useForm: <T extends Record<string, string>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );

            return {
                data,
                errors,
                processing: false,
                setData: (key: keyof T, value: T[keyof T]) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                setError: (key: string, value: string) =>
                    setErrors((current) => ({ ...current, [key]: value })),
                post: (
                    url: string,
                    options: { onSuccess?: (page: { props: object }) => void },
                ) => {
                    inertia.post(url, data);
                    options.onSuccess?.({ props: { flash: {} } });
                },
                patch: vi.fn(),
                delete: vi.fn(),
            };
        },
    };
});

vi.mock('@/components/wizard/primitives', async () => {
    const actual = await vi.importActual<
        typeof import('@/components/wizard/primitives')
    >('@/components/wizard/primitives');

    return {
        ...actual,
        StepHead: ({ title }: { title: string }) => <h2>{title}</h2>,
        InfoCard: ({ children }: { children: ReactNode }) => (
            <div>{children}</div>
        ),
    };
});

import { LoneWorkerActionForm } from './lone-worker-action-form';
import type { Alert } from './lone-worker-types';

const alert = (
    id: string,
    source: Alert['source'] = 'control_room',
): Alert => ({
    id,
    session: null,
    type: 'emergency',
    triggered_at: '2026-08-05T00:00:00Z',
    status: 'active',
    source,
    notes: null,
});

afterEach(cleanup);

beforeEach(() => {
    inertia.post.mockReset();
});

describe('LoneWorkerActionForm canonical alert routing', () => {
    it('submits a numeric canonical Control Room alert route', () => {
        render(
            <LoneWorkerActionForm
                target={{ kind: 'acknowledge', alert: alert('cr_42') }}
                onDone={() => {}}
                onCancel={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /^Acknowledge$/ }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/lone-workers/alerts/42/acknowledge',
            { notes: '' },
        );
    });

    it.each([
        ['legacy_42', 'legacy_history'],
        ['cr_4.2', 'control_room'],
        ['cr_01', 'control_room'],
    ] as const)('refuses non-canonical alert reference %s', (id, source) => {
        render(
            <LoneWorkerActionForm
                target={{ kind: 'acknowledge', alert: alert(id, source) }}
                onDone={() => {}}
                onCancel={() => {}}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: /^Acknowledge$/,
            }),
        );

        expect(inertia.post).not.toHaveBeenCalled();
        expect(
            screen.getByText(
                'This is not a canonical Control Room alert and cannot be changed here.',
            ),
        ).toBeInTheDocument();
    });
});
