import { Button } from '@/components/ui/button';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ControlRoomAlertShow from './show';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: inertia,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/command-centre/command-centre-page', () => ({
    CommandCentrePage: ({ children }: { children: ReactNode }) => (
        <>{children}</>
    ),
}));

vi.mock('@/components/ui/card', () => ({
    Card: ({ children }: { children: ReactNode }) => <>{children}</>,
    CardContent: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/control-room/alert-workspace-dialog', () => ({
    AlertWorkspaceDialog: ({ onClose }: { onClose: () => void }) => (
        <Button type="button" onClick={onClose}>
            Close workspace
        </Button>
    ),
}));

beforeEach(() => {
    inertia.visit.mockReset();
});

describe('Control Room alert deep-link recovery', () => {
    it('closes to the exact filtered Tasks return path for a read-only viewer', () => {
        const returnTo =
            '/tasks?q=CR-2026-2135&sources=alert&bucket=in_progress';
        const props = {
            alert: {
                id: 41,
                reference_number: 'CR-2026-2135',
            },
            can: {
                manage: false,
            },
            return_to: returnTo,
        } as unknown as ComponentProps<typeof ControlRoomAlertShow>;

        render(<ControlRoomAlertShow {...props} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Close workspace' }),
        );

        expect(inertia.visit).toHaveBeenCalledWith(returnTo);
    });
});
