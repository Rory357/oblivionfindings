import { render, screen, within } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { RoundsTab } from './_rounds-stock';
import type { RoundInfo, ScheduleRow } from './types';

vi.mock('@inertiajs/react', () => ({
    Link: (props: ComponentProps<'a'>) => <a {...props} />,
}));

const dose = (
    key: string,
    medicationName: string,
    overrides: Partial<ScheduleRow> = {},
): ScheduleRow => ({
    key,
    client_id: 1,
    client_name: 'Aroha Ngata',
    medication_id: 10,
    medication_name: medicationName,
    dose: '1 tablet',
    route: 'oral',
    is_controlled: false,
    requires_witness: false,
    scheduled_for: '2026-10-04T08:00:00+13:00',
    time: '08:00',
    round_label: 'Morning',
    status: 'due',
    recorded: null,
    mar_url: '/emar/mar?client_id=1',
    ...overrides,
});

const round = (overrides: Partial<RoundInfo> = {}): RoundInfo => ({
    id: 1,
    name: 'Kōwhai morning',
    status: 'pending',
    scheduled_time: '08:00',
    scheduled_at: '2026-10-04T08:00:00+13:00',
    dose_keys: ['10:202610031900'],
    total: 1,
    completed: 0,
    percent: 0,
    url: '/meds/rounds/1',
    ...overrides,
});

describe('RoundsTab scoped dose previews', () => {
    it('uses exact round identities across houses, times and broad time buckets', () => {
        render(
            <RoundsTab
                rounds={[
                    round(),
                    round({
                        id: 2,
                        name: 'Kauri morning',
                        dose_keys: ['20:202610032200'],
                        url: '/meds/rounds/2',
                    }),
                ]}
                schedule={[
                    dose('10:202610031900', 'Morning medicine'),
                    dose('10:202610032030', 'Different scheduled dose'),
                    dose('20:202610032200', 'Later window medicine', {
                        client_id: 2,
                        client_name: 'Hemi Walker',
                        medication_id: 20,
                        round_label: 'Midday',
                        scheduled_for: '2026-10-04T11:00:00+13:00',
                    }),
                    dose('30:202610031900', 'Other house medicine', {
                        client_id: 3,
                        client_name: 'Another Person',
                        medication_id: 30,
                    }),
                ]}
                clientById={new Map()}
                canRecord
            />,
        );

        const first = within(
            screen.getByRole('region', { name: 'Kōwhai morning round' }),
        );
        const second = within(
            screen.getByRole('region', { name: 'Kauri morning round' }),
        );
        expect(first.getByRole('list')).toHaveTextContent('Morning medicine');
        expect(first.getByRole('list')).not.toHaveTextContent(
            'Later window medicine',
        );
        expect(second.getByRole('list')).toHaveTextContent(
            'Later window medicine',
        );
        expect(second.getByRole('list')).not.toHaveTextContent(
            'Morning medicine',
        );
        expect(
            screen.queryByText(/Different scheduled dose/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText(/Other house medicine/),
        ).not.toBeInTheDocument();
        expect(first.getByText(/Scheduled 8:00 am/)).toBeInTheDocument();
        expect(second.getByRole('list')).toHaveTextContent('11:00 am');
        expect(
            first.getByRole('link', { name: 'Start Kōwhai morning' }),
        ).toHaveAttribute('href', '/meds/rounds/1');
    });

    it('does not guess dose membership when the scoped round has no dose keys', () => {
        render(
            <RoundsTab
                rounds={[round({ dose_keys: [], total: 0 })]}
                schedule={[
                    dose('10:202610031900', 'Unrelated morning medicine'),
                ]}
                clientById={new Map()}
                canRecord={false}
            />,
        );

        expect(
            screen.queryByText(/Unrelated morning medicine/),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Start/ }),
        ).not.toBeInTheDocument();
    });
});
