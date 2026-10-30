import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PageHeader, PageHeaderRail } from './page-header';

vi.mock('@inertiajs/react', () => ({ Link: 'a', router: { get: vi.fn() } }));

const items = [
    { key: 'roster', label: 'Roster' },
    { key: 'safety', label: 'Safety & eligibility' },
    { key: 'notifications', label: 'Notifications' },
];

function Workspace({ frontline = true }: { frontline?: boolean }) {
    const [view, setView] = useState('roster');
    return (
        <>
            <PageHeader
                frontline={frontline}
                title="Workforce settings"
                rail={
                    <PageHeaderRail
                        items={items}
                        value={view}
                        onSelect={setView}
                    />
                }
            />
            <button type="button">Review staffing rules</button>
        </>
    );
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(250);
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(
        function (this: HTMLElement) {
            if (this.textContent === 'More') return 80;
            if (this.textContent === 'Find') return 44;
            return 104;
        },
    );
    // DOM emulation has no layout. The actual phone race is also browser-tested.
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockImplementation(
        function (this: HTMLElement) {
            return (this.isConnected
                ? [new DOMRect(0, 0, 104, 44)]
                : []) as unknown as DOMRectList;
        },
    );
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
});

const finishClosing = async () => {
    await act(async () => {
        vi.advanceTimersByTime(300);
    });
};

describe('More view focus when a worker continues immediately', () => {
    it('shows discovery details only in More and keeps its destination selectable', async () => {
        const select = vi.fn();
        render(
            <PageHeaderRail
                items={items.map((item) => ({
                    ...item,
                    overflow: {
                        description: `Explore ${item.label}`,
                        unread: item.key === 'notifications',
                    },
                }))}
                value="roster"
                onSelect={select}
            />,
        );
        expect(
            screen.queryByText('Explore Notifications'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /^More views/ }));
        await finishClosing();
        expect(screen.getByText('Explore Notifications')).toBeInTheDocument();
        expect(screen.getByLabelText('New updates')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: /Notifications.*Explore Notifications/,
            }),
        );
        expect(select).toHaveBeenCalledWith('notifications');
    });
    it('preserves focus moved to the next control after selecting an overflow view', async () => {
        render(<Workspace />);
        fireEvent.click(screen.getByRole('button', { name: /^More views/ }));
        await finishClosing();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Safety & eligibility',
            }),
        );
        const review = screen.getByRole('button', {
            name: 'Review staffing rules',
        });
        act(() => review.focus());
        await finishClosing();
        expect(review).toHaveFocus();
        expect(
            screen.getByRole('tab', { name: 'Safety & eligibility' }),
        ).toHaveAttribute('aria-selected', 'true');
    });

    it('returns focus to More on ordinary keyboard cancellation', async () => {
        render(<Workspace />);
        const more = screen.getByRole('button', { name: /^More views/ });
        act(() => more.focus());
        fireEvent.click(more);
        await finishClosing();
        fireEvent.keyDown(
            screen.getByRole('button', {
                name: 'Safety & eligibility',
            }),
            { key: 'Escape' },
        );
        await finishClosing();
        expect(more).toHaveFocus();
    });

    it('preserves the existing non-frontline menu default', async () => {
        render(<Workspace frontline={false} />);
        const more = screen.getByRole('button', { name: /^More views/ });
        fireEvent.click(more);
        await finishClosing();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Safety & eligibility',
            }),
        );
        act(() =>
            screen
                .getByRole('button', { name: 'Review staffing rules' })
                .focus(),
        );
        await finishClosing();
        expect(more).toHaveFocus();
    });
});
