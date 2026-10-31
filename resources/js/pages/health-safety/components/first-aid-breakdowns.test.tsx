import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FirstAidBreakdowns } from './first-aid-breakdowns';

afterEach(cleanup);
const recorded = {
    source_access: 'readable' as const,
    by_type: [{ type: 'minor_burn', count: 3 }],
    by_outcome: [{ outcome: 'returned_to_activity', count: 3 }],
};

describe('First-aid source states in Analytics', () => {
    it('withholds stale chart rows, tables and drill buttons when access is restricted', () => {
        render(
            <FirstAidBreakdowns
                data={{ ...recorded, source_access: 'restricted' }}
                onOpen={vi.fn()}
            />,
        );
        expect(screen.getAllByRole('status')).toHaveLength(2);
        expect(
            screen.getAllByText(
                'Access to first-aid breakdowns is restricted.',
            ),
        ).toHaveLength(2);
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.queryByText('minor burn')).not.toBeInTheDocument();
        expect(
            screen.queryByText('returned to activity'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/no .*records/i)).not.toBeInTheDocument();
    });

    it.each([
        undefined,
        { ...recorded, source_access: 'unavailable' as const },
    ])('shows unavailable data without an empty-record claim (%j)', (data) => {
        render(<FirstAidBreakdowns data={data} onOpen={vi.fn()} />);
        expect(
            screen.getAllByText('First-aid breakdowns are unavailable.'),
        ).toHaveLength(2);
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect(screen.queryByText(/no .*records/i)).not.toBeInTheDocument();
    });

    it('retains a real readable empty period and its accessible empty tables', () => {
        render(
            <FirstAidBreakdowns
                data={{
                    source_access: 'readable',
                    by_type: [],
                    by_outcome: [],
                }}
                onOpen={vi.fn()}
            />,
        );
        expect(
            screen.getAllByText(
                'No matching readable first-aid records in this period.',
            ),
        ).toHaveLength(2);
        expect(screen.getAllByRole('table')).toHaveLength(2);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('keeps a readable breakdown when only the other operand is unavailable', () => {
        render(
            <FirstAidBreakdowns
                data={{ ...recorded, by_outcome: null }}
                onOpen={vi.fn()}
            />,
        );
        expect(
            within(
                screen.getByRole('group', {
                    name: 'First aid by treatment outcome',
                }),
            ).getByRole('status'),
        ).toHaveTextContent('unavailable');
        const types = screen.getByRole('group', {
            name: 'First aid by injury or illness type',
        });
        expect(within(types).getByRole('table')).toHaveTextContent(
            'minor burn3',
        );
        expect(
            within(types).getByRole('button', { name: /minor burn/i }),
        ).toBeEnabled();
    });

    it('preserves positive counts, accessible tables and both register entry points', () => {
        const onOpen = vi.fn();
        render(<FirstAidBreakdowns data={recorded} onOpen={onOpen} />);
        expect(
            screen.getByRole('table', { name: 'First aid by type' }),
        ).toHaveTextContent('minor burn3');
        expect(
            screen.getByRole('table', { name: 'First aid by outcome' }),
        ).toHaveTextContent('returned to activity3');
        fireEvent.click(screen.getByRole('button', { name: /minor burn/i }));
        fireEvent.click(
            screen.getByRole('button', { name: /returned to activity/i }),
        );
        expect(onOpen).toHaveBeenCalledTimes(2);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(screen.queryByText(/no .*records/i)).not.toBeInTheDocument();
    });
});
