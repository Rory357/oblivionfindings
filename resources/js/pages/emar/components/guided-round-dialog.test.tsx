import { act, fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog';
import type { GuidedRound, RoundItem } from '@/components/emar/rounds/types';
import { Button } from '@/components/ui/button';
import GuidedRoundDialog from './guided-round-dialog';

const { reload, post, on, unsubscribe } = vi.hoisted(() => ({
    reload: vi.fn(),
    post: vi.fn(),
    on: vi.fn(),
    unsubscribe: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: { reload, post, on } }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => true }));
vi.mock('@/components/emar/rounds/round-audit-timeline', () => ({
    default: () => null,
    itemsToAuditEntries: () => [],
}));
vi.mock('@/components/emar/record-dose/record-dose-dialog', () => ({
    RecordDoseDialog: ({
        target,
        onRecorded,
        onClose,
        onNext,
        nextLabel,
    }: ComponentProps<typeof RecordDoseDialog>) => (
        <section aria-label="Dose recorder">
            <p>{target.label?.medicine}</p>
            <Button
                onClick={() =>
                    onRecorded?.({ status: 'recorded', administrationId: 81 })
                }
            >
                Save dose
            </Button>
            <Button
                onClick={() => {
                    onRecorded?.({ status: 'queued', administrationId: null });
                    onClose();
                }}
            >
                Queue dose
            </Button>
            <Button onClick={onClose}>Done</Button>
            {onNext && nextLabel ? (
                <Button onClick={onNext}>{nextLabel}</Button>
            ) : null}
        </section>
    ),
}));

const first: RoundItem = {
    client_id: 1,
    client_name: 'Fixture person',
    client_photo_url: null,
    medication_id: 10,
    medication_name: 'Morning medicine',
    dose: '1 tablet',
    route: 'oral',
    form: 'tablet',
    instructions: null,
    site_id: 1,
    site_name: 'Fixture house',
    is_controlled: false,
    is_high_risk: false,
    requires_witness: false,
    requires_blood_glucose: false,
    requires_pulse: false,
    scheduled_for: '2026-10-04T08:00:00+13:00',
    dose_state: 'due',
    administration: null,
};
const second: RoundItem = {
    ...first,
    medication_id: 20,
    medication_name: 'Vitamin medicine',
};
const saved: RoundItem['administration'] = {
    id: 81,
    status: 'given',
    reason: null,
    reason_code: null,
    administered_at: '2026-10-04T08:05:00+13:00',
    administered_by: 'Fixture worker',
    witnessed_by: null,
    blood_glucose_level: null,
    pulse_bpm: null,
};
function round(items: RoundItem[] = [first, second]): GuidedRound {
    const completed = items.filter((item) => item.administration).length;
    return {
        can_record: true,
        can_start: false,
        can_complete: true,
        round: {
            id: 1,
            name: 'Morning',
            status: 'in_progress',
            scheduled_time: '08:00',
            window_minutes: 60,
            round_date: '2026-10-04',
        },
        items,
        progress: {
            total: items.length,
            completed,
            pending: items.length - completed,
            given: completed,
            refused: 0,
            held: 0,
            next_index: 0,
            percent: (completed / items.length) * 100,
        },
    };
}
const props: Omit<ComponentProps<typeof GuidedRoundDialog>, 'guided'> = {
    signer: {
        name: 'Fixture worker',
        med_competent: true,
        controlled_record: false,
        cd_witness: false,
    },
    witnesses: [],
    notGivenReasons: [],
    canExport: false,
    onPrint: vi.fn(),
    onClose: vi.fn(),
};
function finishRefresh(success: boolean) {
    const callbacks = reload.mock.calls.at(-1)![0];
    act(() => {
        if (success) callbacks.onSuccess();
        callbacks.onFinish();
    });
}

describe('guided round save sequencing', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        on.mockReturnValue(unsubscribe);
    });

    it('blocks stale next-dose and row actions until the saved chart has refreshed', () => {
        const view = render(<GuidedRoundDialog {...props} guided={round()} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: /Record next:.*Morning medicine/,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save dose' }));
        expect(
            screen.queryByRole('button', { name: /^Next due:/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /^Record next:/ }),
        ).toBeDisabled();
        for (const button of screen.getAllByRole('button', {
            name: 'Record dose',
        }))
            expect(button).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        fireEvent.click(screen.getByRole('button', { name: /^Record next:/ }));
        expect(
            screen.queryByRole('region', { name: 'Dose recorder' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <GuidedRoundDialog
                {...props}
                guided={round([{ ...first, administration: saved }, second])}
            />,
        );
        finishRefresh(true);
        const next = screen.getByRole('button', {
            name: /Record next:.*Vitamin medicine/,
        });
        expect(next).toBeEnabled();
        fireEvent.click(next);
        expect(
            screen.getByRole('region', { name: 'Dose recorder' }),
        ).toHaveTextContent('Vitamin medicine');
        expect(unsubscribe).toHaveBeenCalledTimes(2);
    });

    it('keeps a failed refresh blocked and retries without recording the dose again', () => {
        const view = render(<GuidedRoundDialog {...props} guided={round()} />);
        fireEvent.click(screen.getByRole('button', { name: /^Record next:/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Save dose' }));
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        finishRefresh(false);
        expect(screen.getByRole('status')).toHaveTextContent(
            'don’t give the dose again',
        );
        expect(
            screen.getByRole('button', { name: /^Record next:/ }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh round' }));
        expect(reload).toHaveBeenCalledTimes(2);
        expect(
            screen.queryByRole('button', { name: 'Refresh round' }),
        ).not.toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
        view.rerender(
            <GuidedRoundDialog
                {...props}
                guided={round([{ ...first, administration: saved }, second])}
            />,
        );
        finishRefresh(true);
        expect(
            screen.getByRole('button', {
                name: /Record next:.*Vitamin medicine/,
            }),
        ).toBeEnabled();
    });

    it('holds an offline dose until that exact scheduled slot reaches the chart', () => {
        const later = { ...first, scheduled_for: '2026-10-04T12:00:00+13:00' };
        const view = render(
            <GuidedRoundDialog {...props} guided={round([first, later])} />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Record next:/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Queue dose' }));
        expect(reload).not.toHaveBeenCalled();
        expect(screen.getByRole('status')).toHaveTextContent(
            'waiting to reach the chart',
        );
        view.rerender(
            <GuidedRoundDialog
                {...props}
                guided={round([first, { ...later, administration: saved }])}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Refresh round' }));
        finishRefresh(true);
        expect(
            screen.getByRole('button', { name: /^Record next:/ }),
        ).toBeDisabled();
        view.rerender(
            <GuidedRoundDialog
                {...props}
                guided={round([{ ...first, administration: saved }, later])}
            />,
        );
        expect(
            screen.getByRole('button', { name: /^Record next:/ }),
        ).toBeEnabled();
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});
