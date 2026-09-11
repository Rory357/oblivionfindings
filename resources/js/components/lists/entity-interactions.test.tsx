import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState, type AnchorHTMLAttributes } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { EntityCard } from './entity-card';
import { EntityContextMenu, useEntityContextMenu } from './entity-menu';
import { EntityTable } from './entity-table';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));

describe('Canonical list interactions', () => {
    it('preserves legacy table activation and isolates child keyboard events', () => {
        const opened = vi.fn();
        render(
            <EntityTable
                rows={[{ id: 1 }]}
                rowKey={(row) => row.id}
                identity={() => ({ name: 'Legacy row' })}
                columns={[]}
                actionsFor={() => [{ label: 'Open', onClick: opened }]}
                onOpen={opened}
            />,
        );
        const row = screen.getByText('Legacy row').closest('[role="row"]')!;
        fireEvent.keyDown(row, { key: 'Enter' });
        expect(opened).toHaveBeenCalledTimes(1);
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Actions for Legacy row' }),
            { key: 'Enter' },
        );
        expect(opened).toHaveBeenCalledTimes(1);
    });
    it('uses an independent table checkbox and keeps modified native link defaults', () => {
        const opened = vi.fn();
        const selected = vi.fn();
        render(
            <EntityTable
                rows={[{ id: 1 }, { id: 2 }]}
                rowKey={(row) => row.id}
                identity={(row) => ({ name: `Ticket ${row.id}` })}
                columns={[]}
                actionsFor={() => []}
                hrefFor={(row) => `/it/tickets/${row.id}`}
                onOpen={opened}
                selection={{
                    keys: new Set([1]),
                    labelFor: (row) => `Select ${row.id}`,
                    onToggle: selected,
                    canSelect: (row) => row.id === 1,
                }}
            />,
        );
        expect(
            screen.queryByRole('checkbox', { name: 'Select 2' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Select 1' }));
        expect(selected).toHaveBeenCalledWith({ id: 1 }, false);
        expect(opened).not.toHaveBeenCalled();
        const link = screen.getByRole('link', { name: 'Ticket 1' });
        const event = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
            ctrlKey: true,
        });
        link.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
        fireEvent.keyDown(link, { key: 'Enter' });
        expect(opened).not.toHaveBeenCalled();
    });
    it('derives card selection styling from the checkbox contract and retains legacy activation', () => {
        const opened = vi.fn();
        const selected = vi.fn();
        const { rerender } = render(
            <EntityCard
                name="Card"
                meridian="success"
                actions={[]}
                onOpen={opened}
                href="/it/tickets/1"
                selection={{
                    checked: true,
                    label: 'Select card',
                    onToggle: selected,
                }}
            />,
        );
        const card = screen
            .getByRole('checkbox')
            .closest('[data-slot="card"]')!;
        expect(card.className).toContain('ring-primary/45');
        fireEvent.click(screen.getByRole('checkbox'));
        expect(selected).toHaveBeenCalledWith(false);
        expect(opened).not.toHaveBeenCalled();
        rerender(
            <EntityCard
                name="Legacy card"
                meridian="success"
                actions={[]}
                onOpen={opened}
            />,
        );
        fireEvent.keyDown(
            screen.getByText('Legacy card').closest('[data-slot="card"]')!,
            {
                key: ' ',
            },
        );
        expect(opened).toHaveBeenCalledTimes(1);
    });
    it('keeps native anchor context menus and supports keyboard record action focus and dismissal', () => {
        function Example() {
            const menu = useEntityContextMenu<number>();
            const [clicked, setClicked] = useState(false);
            return (
                <>
                    <div
                        tabIndex={0}
                        data-testid="row"
                        onContextMenu={(event) => menu.open(event, 1)}
                    >
                        <a href="/it/tickets/1">Ticket</a>
                    </div>
                    {clicked && <p>Opened</p>}
                    {menu.ctx && (
                        <EntityContextMenu
                            x={menu.ctx.x}
                            y={menu.ctx.y}
                            title="Ticket actions"
                            items={[
                                {
                                    label: 'Open',
                                    onClick: () => setClicked(true),
                                },
                                { separator: true },
                                { label: 'Copy reference' },
                            ]}
                            onClose={menu.close}
                        />
                    )}
                </>
            );
        }
        render(<Example />);
        fireEvent.contextMenu(screen.getByRole('link'));
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        const row = screen.getByTestId('row');
        row.focus();
        fireEvent.contextMenu(row);
        const menu = screen.getByRole('menu', { name: 'Ticket actions' });
        expect(
            within(menu).getByRole('menuitem', { name: 'Open' }),
        ).toHaveFocus();
        fireEvent.keyDown(menu, { key: 'End' });
        expect(
            within(menu).getByRole('menuitem', { name: 'Copy reference' }),
        ).toHaveFocus();
        fireEvent.keyDown(menu, { key: 'ArrowDown' });
        expect(
            within(menu).getByRole('menuitem', { name: 'Open' }),
        ).toHaveFocus();
        fireEvent.keyDown(menu, { key: 'Escape' });
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(row).toHaveFocus();
    });
});
