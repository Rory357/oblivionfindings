import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Remove session and Trigger emergency used to be a form pane whose own button
// fired straight away. Both now ask in the shared ConfirmDialog, and nothing is
// sent until its confirm button.

type VisitOptions = { onSuccess?: (page: { props: object }) => void };

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    flash: {} as { error?: string },
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        useForm: <T extends Record<string, string>>(initial: T) => {
            const [data] = React.useState(initial);
            return {
                data,
                errors: {},
                processing: false,
                setData: vi.fn(),
                setError: vi.fn(),
                post: (url: string, options: VisitOptions) => {
                    inertia.post(url, data);
                    options.onSuccess?.({ props: { flash: inertia.flash } });
                },
                delete: (url: string, options: VisitOptions) => {
                    inertia.delete(url);
                    options.onSuccess?.({ props: { flash: inertia.flash } });
                },
                patch: vi.fn(),
            };
        },
    };
});

import {
    LoneWorkerActionForm,
    LoneWorkerActionModal,
} from './lone-worker-action-form';
import type { Session } from './lone-worker-types';

const session = (status: Session['status']) =>
    ({
        id: 31,
        status,
        user: { id: 5, name: 'Aroha Ngata' },
        site: { id: 2, name: 'Kowhai House' },
        expected_end_at: '2026-10-20T08:00:00Z',
        check_in_interval_minutes: 30,
    }) as unknown as Session;

beforeEach(() => {
    inertia.post.mockClear();
    inertia.delete.mockClear();
    inertia.flash = {};
});

describe('Lone worker › Trigger emergency', () => {
    it('asks before raising the emergency, then posts the same request', () => {
        const onClose = vi.fn();
        render(
            <LoneWorkerActionModal
                open
                onClose={onClose}
                target={{ kind: 'emergency', session: session('active') }}
            />,
        );

        const confirm = screen.getByRole('alertdialog');
        expect(
            within(confirm).getByText('Raise an emergency for Aroha Ngata?'),
        ).toBeTruthy();
        expect(
            within(confirm).getByText(/critical alert to the Control Room/),
        ).toBeTruthy();
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(inertia.post).not.toHaveBeenCalled();

        const raise = within(confirm).getByRole('button', {
            name: 'Raise emergency',
        });
        expect(raise.className).toMatch(/bg-destructive/);
        fireEvent.click(raise);

        expect(inertia.post).toHaveBeenCalledWith(
            '/health-safety/lone-workers/sessions/31/emergency',
            { emergency_notes: '' },
        );
        expect(onClose).toHaveBeenCalled();
    });

    it('Cancel sends nothing', () => {
        const onClose = vi.fn();
        render(
            <LoneWorkerActionModal
                open
                onClose={onClose}
                target={{ kind: 'emergency', session: session('overdue') }}
            />,
        );
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(onClose).toHaveBeenCalled();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('asks the same way from the session viewer pane', () => {
        const onDone = vi.fn();
        const onCancel = vi.fn();
        render(
            <LoneWorkerActionForm
                target={{ kind: 'emergency', session: session('active') }}
                onDone={onDone}
                onCancel={onCancel}
            />,
        );
        expect(screen.getByRole('alertdialog')).toBeTruthy();
        expect(inertia.post).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Raise emergency' }),
        );
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(onDone).toHaveBeenCalled();
        expect(onCancel).not.toHaveBeenCalled();
    });
});

describe('Lone worker › Remove session', () => {
    it('asks before removing a completed session, then deletes it', () => {
        const onClose = vi.fn();
        render(
            <LoneWorkerActionModal
                open
                onClose={onClose}
                target={{ kind: 'delete', session: session('completed') }}
            />,
        );

        const confirm = screen.getByRole('alertdialog');
        expect(within(confirm).getByText('Remove this session?')).toBeTruthy();
        expect(
            within(confirm).getByText(/can’t be put back from the app/),
        ).toBeTruthy();
        expect(inertia.delete).not.toHaveBeenCalled();

        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Remove session' }),
        );
        expect(inertia.delete).toHaveBeenCalledWith(
            '/health-safety/lone-workers/sessions/31',
        );
        expect(onClose).toHaveBeenCalled();
    });

    it('closes without claiming success when the server refuses', () => {
        inertia.flash = {
            error: 'Only completed sessions can be removed. End the session first.',
        };
        const onDone = vi.fn();
        const onCancel = vi.fn();
        render(
            <LoneWorkerActionForm
                target={{ kind: 'delete', session: session('completed') }}
                onDone={onDone}
                onCancel={onCancel}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Remove session' }));
        expect(inertia.delete).toHaveBeenCalledTimes(1);
        expect(onDone).not.toHaveBeenCalled();
        expect(onCancel).toHaveBeenCalled();
    });
});

describe('Lone worker › forms with fields keep their pane', () => {
    it('End session still renders its form, not a confirm', () => {
        render(
            <LoneWorkerActionForm
                target={{ kind: 'end', session: session('active') }}
                onDone={vi.fn()}
                onCancel={vi.fn()}
            />,
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(
            screen.getByRole('button', { name: /end session/i }),
        ).toBeTruthy();
    });
});
