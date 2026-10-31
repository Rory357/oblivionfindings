import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }));
vi.mock('@/components/hr', () => ({
    MyHrShell: ({ children }: { children: ReactNode }) => (
        <main>{children}</main>
    ),
}));

import { MyHrTabs } from '@/components/hr/my-hr-tabs';
import MyInjuries, { type OwnInjury } from './injuries';

const injury: OwnInjury = {
    id: 3,
    reference: 'INJ-2026-0003',
    status: 'return_to_work',
    severity: 'moderate',
    injury_type: 'manual_handling',
    body_part: 'Lower back',
    description: 'Lifted a laundry basket and felt a twinge.',
    injury_date: '2026-10-01T02:00:00Z',
    site: 'Kauri House',
    lost_time_days: 3,
    expected_return_date: '2026-10-20',
    actual_return_date: null,
    next_review_date: '2026-11-03',
    coordinator: 'Mere Manager',
    worksafe_notifiable: false,
    immediate_treatment: 'Ice pack and rest.',
    medical_treatment_type: 'gp_visit',
    acc_claim_lodged: true,
    acc_claim_number: '26/123456',
    plans: [
        {
            id: 9,
            status: 'active',
            start_date: '2026-10-06',
            end_date: null,
            next_review_date: null,
            manager: 'Mere Manager',
            goals: ['Back to full duties'],
            stages: [],
            workplace_modifications: null,
            medical_clearance_date: null,
            medical_clearance_provider: null,
            worker_agreed: false,
            worker_agreed_at: null,
            duties: [
                {
                    id: 1,
                    status: 'active',
                    start_date: '2026-10-06',
                    end_date: null,
                    description: 'Desk work only',
                    restrictions: 'No lifting over 5 kg',
                    accommodations: null,
                    hours_per_day: 6,
                },
            ],
        },
    ],
};

const myHr = {} as Parameters<typeof MyInjuries>[0]['myHr'];

describe('My HR › Injuries (own injuries, read-only)', () => {
    it('shows the facts, care and plan of the worker’s own injury', () => {
        render(<MyInjuries myHr={myHr} injuries={[injury]} />);
        expect(
            screen.getByRole('heading', {
                name: 'Manual handling · Lower back',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Return to work')).toBeInTheDocument();
        expect(screen.getByText('GP visit')).toBeInTheDocument();
        expect(screen.getByText('26/123456')).toBeInTheDocument();
        expect(screen.getByText('Desk work only')).toBeInTheDocument();
        expect(
            screen.getByText('Restrictions: No lifting over 5 kg'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('shows an empty state with no injuries', () => {
        render(<MyInjuries myHr={myHr} injuries={[]} />);
        expect(
            screen.getByText('No workplace injuries recorded for you'),
        ).toBeInTheDocument();
    });

    it('lists the Injuries tab only for someone with an injury on record', () => {
        const { unmount } = render(<MyHrTabs active="overview" />);
        expect(screen.queryByText('Injuries')).toBeNull();
        unmount();
        render(<MyHrTabs active="overview" showInjuries />);
        expect(screen.getByText('Injuries')).toBeInTheDocument();
    });
});
