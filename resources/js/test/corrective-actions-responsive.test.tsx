import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    ActionTable,
    type ActionRow,
} from '@/pages/health-safety/corrective-actions/index';

const longTitle =
    'Whakaritea-te-arotake-haumaru-kore-wehenga mō te wāhi mahi me ngā taputapu katoa';
const longOwner =
    'Te Kaiārahi Haumaru Whakataunga Roa Kore Mokowā Hei Whakamātautau';

const action: ActionRow = {
    id: 42,
    reference_number: 'CA-2026-0042',
    title: longTitle,
    action_type: 'corrective',
    priority: 'critical',
    status: 'completed',
    assigned_to_name: longOwner,
    due_date: '2026-08-31',
    is_overdue: true,
    completed_at: '2026-08-20T04:00:00Z',
    verified_at: null,
    completed_by_user_id: 18,
    completed_by_name: 'Aroha Tester',
    can_verify: true,
    evidence: {
        load_state: 'loaded',
        attachments: [{ id: 8 }],
    },
    rework: {
        latest_reason:
            'Whakaratohia he taunakitanga taipitopito mō te whakatikatika.',
    },
    recommendation:
        'Arotakengia ngā tukanga me ngā taputapu i mua i te kati.',
    source: {
        type: 'control_room_task',
        id: 91,
        reference: 'CRT-0091',
        title: 'Whai ake i te tūraru mahi kua kitea',
    },
    event: {
        id: 77,
        reference_number: 'HS-2026-0077',
        event_category: 'incident',
        severity: 'high',
        status: 'monitoring',
        site_name: 'Te Whare Manaaki o Te Tai Tokerau',
        url: '/health-safety/events/77',
        monitoring: true,
    },
};

describe('corrective actions responsive register', () => {
    it('keeps a labelled mobile card branch and a contained desktop table', () => {
        const onOpen = vi.fn();
        const onMenu = vi.fn();

        render(
            <ActionTable
                rows={[action]}
                canViewReports
                onOpen={onOpen}
                onMenu={onMenu}
            />,
        );

        const cards = screen.getByRole('list', {
            name: 'Corrective actions',
        });
        expect(cards).toHaveClass('min-w-0', 'md:hidden');

        const tableRegion = screen.getByRole('region', {
            name: 'Corrective actions table',
        });
        expect(tableRegion).toHaveClass(
            'hidden',
            'max-w-full',
            'overflow-x-auto',
            'overscroll-x-contain',
            'md:block',
        );
        expect(tableRegion).toHaveAttribute('tabindex', '0');
        expect(within(tableRegion).getByRole('table')).toBeInTheDocument();

        const card = within(cards).getByRole('listitem');
        for (const label of [
            'Due',
            'Stage',
            'Priority',
            'Owner',
            'Parent event',
            'Flags',
        ]) {
            expect(within(card).getByText(label)).toBeVisible();
        }

        expect(within(card).getByText(longTitle)).toHaveClass(
            '[overflow-wrap:anywhere]',
        );
        expect(within(card).getByText(longOwner)).toHaveClass(
            '[overflow-wrap:anywhere]',
        );
        expect(within(card).getByText('Verify')).toBeVisible();

        const controls = within(card).getAllByRole('button');
        expect(controls).toHaveLength(2);
        expect(controls[0]).toHaveAccessibleName(
            'Open parent event for action CA-2026-0042',
        );
        expect(controls[0]).toHaveClass('min-h-11');
        expect(controls[1]).toHaveAccessibleName(
            'Lifecycle actions for CA-2026-0042',
        );
        expect(controls[1]).toHaveClass('frontline-tap');

        fireEvent.click(controls[0]);
        expect(onOpen).toHaveBeenCalledWith(77, { section: 'actions' });

        fireEvent.click(controls[1]);
        expect(onMenu).toHaveBeenCalledWith(action, 0, 0);
    });
});
