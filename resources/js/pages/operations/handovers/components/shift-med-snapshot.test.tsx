import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { ShiftMedSummary, type ShiftMedSnapshot } from './shift-med-snapshot';

afterEach(cleanup);

const snapshot: ShiftMedSnapshot = {
    window: { start: '2026-06-16T07:00:00+12:00', end: '2026-06-16T15:00:00+12:00' },
    counts: { due: 3, given: 5, missed: 1, refused: 0, cd_due: 2, prn_given: 4, reviews_outstanding: 6, omissions: 7 },
    due: [{ name: 'Quetiapine', time: '08:00', state: 'due', controlled: true }],
    alerts: [{ kind: 'stock', tone: 'warning', message: 'Quetiapine low stock — 2 on hand (reorder at 5).' }],
    generated_at: '2026-06-16T09:00:00+12:00',
};

describe('ShiftMedSummary', () => {
    it('prompts to pick a shift when none is linked', () => {
        render(<ShiftMedSummary snapshot={null} loading={false} hasShift={false} noShiftHint="Pick the outgoing shift" />);
        expect(screen.getByText('Pick the outgoing shift')).toBeInTheDocument();
    });

    it('shows a loading state while the snapshot is fetching', () => {
        render(<ShiftMedSummary snapshot={null} loading={true} hasShift={true} />);
        expect(screen.getByText(/Loading the shift's medication state/)).toBeInTheDocument();
    });

    it('renders the stat tiles, optional note and alerts from the snapshot', () => {
        render(<ShiftMedSummary snapshot={snapshot} loading={false} hasShift={true} note="Due meds pre-filled below" />);

        // Stat-tile labels.
        expect(screen.getByText('Due')).toBeInTheDocument();
        expect(screen.getByText('CD due')).toBeInTheDocument();
        expect(screen.getByText('Reviews due')).toBeInTheDocument();
        expect(screen.getByText('Omissions')).toBeInTheDocument();

        // A couple of distinct count values (3 = due, 7 = omissions).
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument();

        // The contextual note + the alert message.
        expect(screen.getByText('Due meds pre-filled below')).toBeInTheDocument();
        expect(screen.getByText(/Quetiapine low stock/)).toBeInTheDocument();
    });
});
