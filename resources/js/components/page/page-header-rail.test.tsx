import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { PageHeaderRail } from './page-header';

afterEach(cleanup);
const items = [
    { key: 'teams', label: 'Teams' },
    { key: 'queues', label: 'Queues' },
    { key: 'operations', label: 'Operations' },
];

it('moves and wraps keyboard focus without activating a view or bypassing its navigation guard', () => {
    const select = vi.fn();
    render(<PageHeaderRail items={items} value="queues" onSelect={select} />);
    const [teams, queues, operations] = screen.getAllByRole('tab');
    expect(queues).toHaveAttribute('tabindex', '0');
    expect(teams).toHaveAttribute('tabindex', '-1');
    act(() => queues.focus());
    fireEvent.keyDown(queues, { key: 'ArrowRight' });
    expect(operations).toHaveFocus();
    expect(operations).toHaveAttribute('tabindex', '0');
    fireEvent.keyDown(operations, { key: 'ArrowRight' });
    expect(teams).toHaveFocus();
    fireEvent.keyDown(teams, { key: 'ArrowLeft' });
    expect(operations).toHaveFocus();
    fireEvent.keyDown(operations, { key: 'Home' });
    expect(teams).toHaveFocus();
    fireEvent.keyDown(teams, { key: 'End' });
    expect(operations).toHaveFocus();
    expect(select).not.toHaveBeenCalled();
    expect(queues).toHaveAttribute('aria-selected', 'true');
    fireEvent.click(operations);
    expect(select).toHaveBeenCalledExactlyOnceWith('operations');
});

it.each(['Escape', 'Close'])(
    'returns focus to Find after %s cancellation',
    async (method) => {
        render(
            <PageHeaderRail items={items} value="teams" onSelect={vi.fn()} />,
        );
        const find = screen.getByRole('button', { name: 'Find a view' });
        fireEvent.click(find);
        const input = await screen.findByRole('textbox', {
            name: 'Find a view — Views',
        });
        await waitFor(() => expect(input).toHaveFocus());
        if (method === 'Escape') fireEvent.keyDown(input, { key: 'Escape' });
        else
            fireEvent.click(
                screen.getByRole('button', { name: 'Close' }),
            );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        await waitFor(() => expect(find).toHaveFocus());
    },
);

it('returns focus to the chosen view after filtering and keyboard selection', async () => {
    function Workspace() {
        const [value, setValue] = useState('teams');
        return (
            <PageHeaderRail items={items} value={value} onSelect={setValue} />
        );
    }
    render(<Workspace />);
    fireEvent.click(screen.getByRole('button', { name: 'Find a view' }));
    const input = await screen.findByRole('textbox', {
        name: 'Find a view — Views',
    });
    fireEvent.change(input, { target: { value: 'oper' } });
    fireEvent.keyDown(input, { key: 'Enter' });
    const operations = await screen.findByRole('tab', { name: 'Operations' });
    await waitFor(() => expect(operations).toHaveFocus());
    expect(operations).toHaveAttribute('aria-selected', 'true');
});

it('keeps one keyboard entry after the focused view is removed or selection changes externally', () => {
    const select = vi.fn();
    const { rerender } = render(
        <PageHeaderRail items={items} value="teams" onSelect={select} />,
    );
    act(() => screen.getByRole('tab', { name: 'Queues' }).focus());
    rerender(
        <PageHeaderRail
            items={items.filter((item) => item.key !== 'queues')}
            value="teams"
            onSelect={select}
        />,
    );
    expect(screen.getByRole('tab', { name: 'Teams' })).toHaveAttribute(
        'tabindex',
        '0',
    );
    rerender(
        <PageHeaderRail items={items} value="operations" onSelect={select} />,
    );
    expect(screen.getByRole('tab', { name: 'Operations' })).toHaveAttribute(
        'tabindex',
        '0',
    );
    expect(
        screen.getAllByRole('tab').filter((tab) => tab.tabIndex === 0),
    ).toHaveLength(1);
});
