import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SearchInsights, type HubSearchInsightData } from './_search-insights';

const data: HubSearchInsightData = {
    days: 28,
    since: '2026-09-16',
    until: '2026-10-13',
    total: 8,
    unanswered: 3,
    topics: [
        {
            key: 'policies',
            label: 'Policies & guidance',
            total: 5,
            unanswered: 3,
        },
    ],
};
describe('grouped search insights', () => {
    it('starts an editable guide from a broad topic only when creation is available', () => {
        const draft = vi.fn();
        const { rerender } = render(
            <SearchInsights data={data} onDraftAnswer={draft} />,
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Draft an answer for Policies & guidance',
            }),
        );
        expect(draft).toHaveBeenCalledWith(
            'Policies & guidance: useful answers',
        );
        rerender(<SearchInsights data={data} />);
        expect(
            screen.queryByRole('button', { name: /Draft an answer/ }),
        ).not.toBeInTheDocument();
    });
    it('shows real topic totals and explains the measurement without displaying search phrases or people', () => {
        render(<SearchInsights data={data} />);
        expect(
            screen.getByRole('region', { name: 'Unanswered search topics' }),
        ).toHaveTextContent('3 without results · 5 searches');
        expect(screen.getByText('Policies & guidance')).toBeInTheDocument();
        expect(
            screen.getByText(/keyboard suggestions are excluded/),
        ).toHaveTextContent('without search phrases or staff names');
    });
    it('distinguishes having no recorded searches from searches that returned results', () => {
        const { rerender } = render(
            <SearchInsights
                data={{ ...data, total: 0, unanswered: 0, topics: [] }}
            />,
        );
        expect(
            screen.getByText(/No searches recorded in this period yet/),
        ).toBeInTheDocument();
        rerender(
            <SearchInsights
                data={{ ...data, total: 4, unanswered: 0, topics: [] }}
            />,
        );
        expect(
            screen.queryByText(/No searches recorded/),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Every recorded search returned at least one result in this period.',
            ),
        ).toBeInTheDocument();
    });
});
