import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TableList } from './_table-list';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { get: vi.fn() },
}));

afterEach(cleanup);

const row = {
    id: 51,
    name: 'Synthetic long medicine name',
    person: 'Mere Jones · Kauri House',
    quantity: null as number | null,
};

function showList(open = vi.fn(), blocked = vi.fn()) {
    render(
        <TableList
            title="Stock"
            pager={{ data: [row], total: 1, links: [], last_page: 1 }}
            name={(item) => item.name}
            subline={(item) => item.person}
            open={open}
            columns={[
                {
                    key: 'balance',
                    label: 'On hand',
                    width: '1fr',
                    cell: (item) =>
                        item.quantity === null
                            ? 'Unknown'
                            : item.quantity + ' tablets',
                },
                {
                    key: 'expiry',
                    label: 'Use first',
                    width: '1fr',
                    cell: () => 'Expiry unknown',
                },
            ]}
            actions={(item) => [
                { label: 'Open the stock item', onClick: () => open(item) },
                {
                    label: 'Count it',
                    disabled: 'Pack tracking has not been set up.',
                    onClick: blocked,
                },
            ]}
        />,
    );
    return { open, blocked };
}

describe('Stock phone cards', () => {
    it('retains person identity and all stock facts without manufacturing a balance', () => {
        showList();
        const cards = screen.getByRole('list', { name: 'Stock cards' });
        expect(cards).toHaveClass('md:hidden');
        const card = within(cards);
        expect(card.getByText(row.name)).toBeVisible();
        expect(card.getByText(row.person)).toBeVisible();
        expect(card.getByText('On hand')).toBeVisible();
        expect(card.getByText('Unknown')).toBeVisible();
        expect(card.getByText('Use first')).toBeVisible();
        expect(card.getByText('Expiry unknown')).toBeVisible();
        expect(card.queryByText('0 tablets')).not.toBeInTheDocument();
    });

    it('opens the same record by keyboard and keeps a blocked action explained and inactive', () => {
        const { open, blocked } = showList();
        const cards = screen.getByRole('list', { name: 'Stock cards' });
        const card = within(cards)
            .getByText(row.name)
            .closest('[role="button"]')!;
        fireEvent.keyDown(card, { key: 'Enter' });
        expect(open).toHaveBeenCalledExactlyOnceWith(row);
        fireEvent.contextMenu(card, { clientX: 50, clientY: 60 });
        const blockedAction = screen.getByRole('menuitem', {
            name: 'Count it',
        });
        expect(blockedAction).toHaveAttribute('aria-disabled', 'true');
        expect(blockedAction).toHaveAccessibleDescription(
            'Pack tracking has not been set up.',
        );
        fireEvent.click(blockedAction);
        fireEvent.keyDown(blockedAction, { key: 'Enter' });
        expect(blocked).not.toHaveBeenCalled();
        expect(open).toHaveBeenCalledTimes(1);
    });
});
