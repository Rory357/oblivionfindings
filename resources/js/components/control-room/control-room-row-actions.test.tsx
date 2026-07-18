import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Eye, UserRound } from 'lucide-react';
import { describe, expect, it, vi } from 'vitest';

import { ControlRoomRowActions } from './control-room-row-actions';

function Fixture({ canClaim = true }: { canClaim?: boolean }) {
    const open = vi.fn();

    return (
        <ControlRoomRowActions
            label="Actions for ALT-1042"
            items={[
                {
                    key: 'open',
                    label: 'Open alert',
                    icon: Eye,
                    onSelect: open,
                },
                ...(canClaim
                    ? [
                          {
                              key: 'claim',
                              label: 'Claim alert',
                              icon: UserRound,
                              onSelect: vi.fn(),
                          },
                      ]
                    : []),
            ]}
        >
            {({ rowProps, overflowButton }) => (
                <div data-testid="alert-row" {...rowProps}>
                    Alert ALT-1042
                    {overflowButton}
                </div>
            )}
        </ControlRoomRowActions>
    );
}

describe('ControlRoomRowActions', () => {
    it('opens the same accessible action menu from right click and the overflow control', async () => {
        render(<Fixture />);

        fireEvent.contextMenu(screen.getByTestId('alert-row'), {
            clientX: 120,
            clientY: 90,
        });

        expect(
            await screen.findByRole('menu', {
                name: 'Actions for ALT-1042',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'Open alert' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'Claim alert' }),
        ).toBeInTheDocument();

        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });
        await waitFor(() =>
            expect(screen.queryByRole('menu')).not.toBeInTheDocument(),
        );
        await waitFor(() =>
            expect(screen.getByTestId('alert-row')).toHaveFocus(),
        );

        const overflow = screen.getByRole('button', {
            name: 'Actions for ALT-1042',
        });
        overflow.focus();
        fireEvent.click(overflow);
        expect(await screen.findByRole('menu')).toBeInTheDocument();

        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });
        await waitFor(() => expect(overflow).toHaveFocus());
    });

    it('supports arrow-key navigation and omits actions the server did not authorise', async () => {
        render(<Fixture canClaim={false} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Actions for ALT-1042' }),
        );

        const menu = await screen.findByRole('menu');
        const open = screen.getByRole('menuitem', { name: 'Open alert' });
        expect(open).toHaveFocus();
        expect(
            screen.queryByRole('menuitem', { name: 'Claim alert' }),
        ).not.toBeInTheDocument();

        fireEvent.keyDown(menu, { key: 'ArrowDown' });
        expect(open).toHaveFocus();
    });

    it('ignores programmatic scroll restoration and closes on a user scroll gesture', async () => {
        render(<Fixture />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Actions for ALT-1042' }),
        );
        expect(await screen.findByRole('menu')).toBeInTheDocument();

        fireEvent.scroll(window);
        expect(screen.getByRole('menu')).toBeInTheDocument();

        fireEvent.wheel(window);
        await waitFor(() =>
            expect(screen.queryByRole('menu')).not.toBeInTheDocument(),
        );
    });
});
