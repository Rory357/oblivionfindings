import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ItHero, type ItHeroSummary } from '../it-hero';
import { SlaChip } from '../sla-chip';
import {
    SlaEvidence,
    SlaWatchdogNote,
    type SlaClock,
    type SlaSummary,
    type SlaVerdict,
    type SlaWatchdog,
} from '../sla-evidence';

const at = '2026-09-09T02:10:00Z';
const clock: SlaClock = {
    state: 'ok',
    reason: null,
    due_at: '2026-09-09T04:00:00Z',
    completed_at: null,
    breached_at: null,
    ever_breached: false,
    policy_recorded: true,
    paused: false,
    paused_minutes: 0,
    remaining_minutes: 110,
};
const verdict: SlaVerdict = {
    state: 'ok',
    coverage: 'full',
    ever_breached: false,
    evaluated_at: at,
    clocks: { first_response: clock, resolution: clock },
};
const counts = {
    ok: 1,
    at_risk: 0,
    breached: 0,
    met: 0,
    paused: 0,
    unmeasured: 1,
};
const measured: SlaSummary = {
    total: 2,
    by_state: counts,
    by_coverage: { full: 1, partial: 0, none: 1 },
    ever_breached: 0,
    clocks: {
        first_response: { by_state: counts },
        resolution: { by_state: counts },
    },
    evaluated_at: at,
};
const watchdog: SlaWatchdog = {
    state: 'fresh',
    last_success_at: at,
    latest_status: 'succeeded',
    latest_started_at: at,
    required_since: at,
    evaluated_at: at,
    grace_seconds: 300,
};
const summary: ItHeroSummary = {
    my: { open: 0, waiting: 0, resolved_30d: 0 },
    tickets: {
        open: 2,
        unassigned: 0,
        urgent_unassigned: 0,
        urgent_open: 0,
        at_risk: 0,
        breached: 0,
        awaiting_reply: 0,
        waiting: 0,
        resolved_30d: 2,
        met_30d: 1,
        measured_30d: 1,
        sla_open: measured,
        sla_watchdog: watchdog,
    },
};
const can = { view: true, manage: true, request: true };
const noop = () => {};

describe('SLA evidence presentation', () => {
    it('shows missing evidence as unmeasured instead of hiding the chip', () => {
        render(<SlaChip ticket={{}} />);
        expect(screen.getByText('SLA unmeasured')).toBeVisible();
    });

    it('keeps a late response visible after resolution and shows both independent outcomes', () => {
        const sla: SlaVerdict = {
            ...verdict,
            state: 'breached',
            ever_breached: true,
            clocks: {
                first_response: {
                    ...clock,
                    state: 'breached',
                    ever_breached: true,
                    breached_at: at,
                    completed_at: at,
                },
                resolution: { ...clock, state: 'met', completed_at: at },
            },
        };
        render(
            <>
                <SlaChip ticket={{ sla }} />
                <SlaEvidence sla={sla} />
            </>,
        );
        expect(screen.getByText('SLA breached')).toBeVisible();
        expect(screen.getByText('Breached')).toBeVisible();
        expect(screen.getByText('Met')).toBeVisible();
        expect(screen.getByText(/Earlier breach retained/)).toBeVisible();
    });

    it('does not pause a still-running response clock when resolution is waiting', () => {
        render(
            <SlaEvidence
                sla={{
                    ...verdict,
                    state: 'at_risk',
                    clocks: {
                        first_response: { ...clock, state: 'at_risk' },
                        resolution: { ...clock, state: 'paused', paused: true },
                    },
                }}
            />,
        );
        expect(screen.getByText('At risk')).toBeVisible();
        expect(screen.getByText('Paused')).toBeVisible();
        expect(
            screen.getByText(/first-response clock is independent/),
        ).toBeVisible();
    });

    it('keeps incomplete coverage explicit even when the known clock has breached', () => {
        render(
            <SlaChip
                ticket={{
                    sla: { ...verdict, state: 'breached', coverage: 'partial' },
                }}
            />,
        );
        expect(
            screen.getByText('SLA breached · partial measurement'),
        ).toBeVisible();
    });

    it('uses only fully measured outcomes in the compliance ring', () => {
        render(
            <ItHero summary={summary} can={can} onRaise={noop} onLog={noop} />,
        );
        const meter = screen.getByRole('link', {
            name: 'View tickets resolved in the last 30 days',
        });
        expect(within(meter).getByText('100%')).toBeVisible();
        expect(meter).toHaveTextContent('1 of 1');
        expect(meter).toHaveTextContent('1 excluded');
        expect(screen.getByText('1 incompletely measured')).toBeVisible();
        expect(screen.queryByText('SLA healthy')).not.toBeInTheDocument();
    });

    it('does not show zero percent when resolved tickets have no measurement', () => {
        render(
            <ItHero
                summary={{
                    ...summary,
                    tickets: {
                        ...summary.tickets!,
                        met_30d: 0,
                        measured_30d: 0,
                    },
                }}
                can={can}
                onRaise={noop}
                onLog={noop}
            />,
        );
        const meter = screen.getByRole('link', {
            name: 'View tickets resolved in the last 30 days',
        });
        expect(meter).toHaveTextContent('2 resolved · none fully measured');
        expect(within(meter).queryByText('0%')).not.toBeInTheDocument();
    });

    it('shows a stale watchdog independently of measured ticket health', () => {
        const stale = { ...watchdog, state: 'stale' as const };
        render(
            <>
                <ItHero
                    summary={{
                        ...summary,
                        tickets: {
                            ...summary.tickets!,
                            sla_watchdog: stale,
                            sla_open: {
                                ...measured,
                                by_coverage: { none: 0, partial: 0, full: 2 },
                            },
                        },
                    }}
                    can={can}
                    onRaise={noop}
                    onLog={noop}
                />
                <SlaWatchdogNote watchdog={stale} />
            </>,
        );
        expect(screen.getByText('SLA watchdog stale')).toBeVisible();
        expect(screen.getByText(/Last successful check/)).toHaveTextContent(
            'Ticket clocks are calculated',
        );
    });

    it('keeps a knowledge-only header free of ticket metrics and request actions', () => {
        render(
            <ItHero
                summary={null}
                can={{ view: false, manage: false, request: false }}
                onRaise={noop}
                onLog={noop}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'IT & Support' }),
        ).toBeVisible();
        expect(screen.queryByText('Open tickets')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Log ticket' }),
        ).not.toBeInTheDocument();
    });
});
