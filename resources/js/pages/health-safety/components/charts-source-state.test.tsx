import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { GaugeCard, RatioDonutCard } from './charts';

afterEach(cleanup);

describe('Readable near-miss and recordable operands', () => {
    it('withholds a stale positive ratio and arc when the medical denominator is not readable', () => {
        render(
            <RatioDonutCard
                ratio={4}
                operands={{ near_misses: 8, recordable: null }}
            />,
        );
        const chart = screen.getByRole('img', {
            name: 'Near-miss ratio not confirmed',
        });
        expect(within(chart).getByText('—')).toBeInTheDocument();
        expect(screen.queryByText('4×')).not.toBeInTheDocument();
        expect(screen.getByText('Not confirmed')).toBeInTheDocument();
        const arc = chart.querySelectorAll('circle')[1];
        expect(Number(arc.getAttribute('stroke-dashoffset'))).toBeCloseTo(
            Number(arc.getAttribute('stroke-dasharray')),
        );
        expect(screen.getByText('8')).toBeInTheDocument();
    });

    it('preserves a recorded positive ratio and its exact readable reporting share', () => {
        render(
            <RatioDonutCard
                ratio={4}
                operands={{ near_misses: 8, recordable: 2 }}
            />,
        );
        const chart = screen.getByRole('img', { name: 'Near-miss ratio' });
        expect(within(chart).getByText('4×')).toBeInTheDocument();
        const arc = chart.querySelectorAll('circle')[1];
        expect(Number(arc.getAttribute('stroke-dashoffset'))).toBeCloseTo(
            Number(arc.getAttribute('stroke-dasharray')) * 0.2,
        );
        expect(
            screen.getByText(
                'Compares readable near misses with recordable injuries.',
            ),
        ).toBeInTheDocument();
    });

    it('keeps an undefined ratio neutral when readable operands have no recordable denominator', () => {
        render(
            <RatioDonutCard
                ratio={null}
                operands={{ near_misses: 8, recordable: 0 }}
            />,
        );
        expect(
            screen.getByRole('img', { name: 'Near-miss ratio not confirmed' }),
        ).toHaveTextContent('—');
        expect(screen.getByText('0')).toBeInTheDocument();
        expect(
            screen.queryByText(/hazards are caught before harm/i),
        ).not.toBeInTheDocument();
    });
});

describe('Training and drill source states', () => {
    it.each([null, 0, 100])(
        'keeps an unavailable percentage distinct from readable zero or completion (%s)',
        (pct) => {
            render(<GaugeCard pct={pct} title="Training compliance" />);
            expect(screen.getByText('Training compliance')).toBeInTheDocument();
            if (pct === null) {
                expect(screen.getByText('Not confirmed')).toBeInTheDocument();
                expect(screen.queryByRole('img')).not.toBeInTheDocument();
                expect(screen.queryByText('0%')).not.toBeInTheDocument();
            } else {
                expect(
                    screen.getByRole('img', { name: `${pct}%` }),
                ).toHaveTextContent(`${pct}%`);
                expect(
                    screen.queryByText('Not confirmed'),
                ).not.toBeInTheDocument();
            }
        },
    );
});
