import {
    act,
    fireEvent,
    render,
    renderHook,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    buildQueue,
    type ConflictsProps,
    type CoverageGap,
    type ShiftRow,
} from './build-queue';
import { ConflictConfirmDialog } from './conflict-confirm-dialog';
import { ConflictScanSettingsDialog } from './conflict-scan-settings-dialog';
import { confirmedCoverageReview, csvCell } from './coverage-review-result';
import { useConflictQueue } from './use-conflict-queue';
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
const duty = (id: number, worker = id, site = id): ShiftRow => ({
    id,
    user_id: worker,
    client_id: id,
    site_id: site,
    effective_site_id: site,
    client_name: 'Ari',
    staff_name: 'Mere',
    location: 'Matai House',
    status: 'scheduled',
    starts_at: '2026-10-19T11:00:00Z',
    ends_at: '2026-10-19T13:00:00Z',
    can: { view_shift: true, view_client: true, view_roster: true },
    urls: {
        shift: '/operations/shifts/' + id + '?return_to=queue',
        client: '/operations/clients/' + id,
        roster: '/operations/rostering?week=2026-10-19',
    },
});
const gap: CoverageGap = {
    finding_id: 'coverage:11:window',
    site_id: 3,
    site_name: 'Matai House',
    rule_id: 11,
    rule_name: 'Morning cover',
    window_label: 'Monday morning',
    starts_at: '2026-10-19T09:00:00+13:00',
    ends_at: '2026-10-19T11:00:00+13:00',
    required_staff: 2,
    assigned_staff: 1,
    missing_staff: 1,
    source_assessment: 'assessed',
    coverage_window_key: 'exact-offset-key',
    action_window: {
        site_id: 3,
        coverage_requirement_id: 11,
        window_starts_at: '2026-10-19T09:00:00+13:00',
        window_ends_at: '2026-10-19T11:00:00+13:00',
    },
    urls: {
        roster: '/operations/rostering?week=2026-10-19&site_id=3',
        ack: '/coverage/exact-offset-key/ack',
        dismiss: '/coverage/exact-offset-key/dismiss',
        clear: '/coverage/exact-offset-key/clear',
    },
    can: { acknowledge: true, dismiss: true, clear: true },
};
const base: ConflictsProps = {
    weekStart: '2026-10-19',
    weekEnd: '2026-10-26',
    workerTimezone: 'Pacific/Auckland',
    staffOverlaps: [],
    clientOverlaps: [],
    tightTurnarounds: [],
    timeOffConflicts: [],
    openShifts: [],
    activeReplacements: [],
    coverageGaps: [],
    recurringCoverageAlignment: { rule_drift: [], orphan_series: [] },
};
const overlaps = {
    ...base,
    staffOverlaps: [
        {
            pair_id: 'staff_overlap:1:2',
            staff_id: 21,
            staff_name: 'Mere',
            first: duty(1, 21, 31),
            second: duty(2, 21, 31),
        },
        {
            pair_id: 'staff_overlap:3:4',
            staff_id: 22,
            staff_name: 'Mere',
            first: duty(3, 22, 32),
            second: duty(4, 22, 32),
        },
    ],
};
const coverageItem = () =>
    buildQueue({ ...base, coverageGaps: [gap] }, true)[0];
const requestId = '830dc2cb-5ec8-4567-a30a-4b13521ab23e';
const receipt = (action: 'ack' | 'dismiss' | 'clear' = 'ack') => ({
    version: 1,
    scope: 'coverage_gap',
    actor_id: 7,
    request_id: requestId,
    action,
    window: {
        ...gap.action_window!,
        coverage_window_key: gap.coverage_window_key,
        window_starts_at: '2026-10-18T20:00:00.000000Z',
        window_ends_at: '2026-10-18T22:00:00.000000Z',
    },
    outcome: action === 'clear' ? 'cleared' : 'recorded',
    changed: true,
    state: action === 'clear' ? null : action === 'ack' ? 'acked' : 'dismissed',
    acknowledgement_id: action === 'clear' ? null : 91,
    cleared_ids: action === 'clear' ? [91] : [],
    reason: action === 'dismiss' ? 'Reviewed required cover' : null,
    audit_id: 101,
    committed_at: '2026-10-10T03:00:00.000000Z',
    staffing_resolved: false,
});

describe('Queue source identity and honest findings', () => {
    it('keeps duplicate staff and House names separate by their real record IDs', () => {
        const { result } = renderHook(() =>
            useConflictQueue(buildQueue(overlaps), base.weekStart),
        );
        expect(result.current.staffOptions).toEqual([
            { id: 21, name: 'Mere · #21' },
            { id: 22, name: 'Mere · #22' },
        ]);
        expect(result.current.siteOptions.map((row) => row.id)).toEqual([
            31, 32,
        ]);
        act(() => result.current.setStaffFilterById(22));
        expect(result.current.visible.map((row) => row.id)).toEqual([
            'staff_overlap:3:4',
        ]);
        act(() => result.current.setStaffFilterById(null));
        act(() => result.current.setSiteFilterById(31));
        expect(result.current.visible.map((row) => row.id)).toEqual([
            'staff_overlap:1:2',
        ]);
    });
    it('keeps a canonical filter and selection across a reordered response; current removals reset stale filters', () => {
        const original = buildQueue(overlaps);
        const { result, rerender } = renderHook(
            ({ items }) => useConflictQueue(items, base.weekStart),
            { initialProps: { items: original } },
        );
        act(() => {
            result.current.setStaffFilterById(22);
            result.current.setSelectedId('staff_overlap:3:4');
        });
        rerender({ items: [...original].reverse() });
        expect(result.current.selected?.id).toBe('staff_overlap:3:4');
        rerender({ items: [original[0]] });
        expect(result.current.staffFilterValue).toBeNull();
        expect(result.current.open).toHaveLength(1);
    });
    it('review-next changes selection without hiding records or implying a saved resolution', () => {
        const items = buildQueue(overlaps);
        const { result } = renderHook(() =>
            useConflictQueue(items, base.weekStart),
        );
        act(() => result.current.reviewNext());
        expect(result.current.selected?.id).toBe('staff_overlap:1:2');
        act(() => result.current.reviewNext());
        expect(result.current.selected?.id).toBe('staff_overlap:3:4');
        expect(result.current.open).toHaveLength(2);
        expect(result.current.counts.staff_overlap).toBe(2);
    });
    it('does not infer funding or a ratio exception from concurrent client duties', () => {
        const item = buildQueue({
            ...base,
            clientOverlaps: [
                {
                    pair_id: 'client_overlap:1:2',
                    client_id: 1,
                    client_name: 'Ari',
                    first: duty(1),
                    second: duty(2),
                },
            ],
        })[0];
        expect(item.recommended).toContain('planned multi-worker support');
        expect(item.recommended).not.toMatch(/usually funded|confirm a 2:1/);
        expect(item.actions.every((action) => Boolean(action.href))).toBe(true);
    });
    it('shows recorded training with its source window without claiming approved leave or offering HR cancellation', () => {
        const item = buildQueue({
            ...base,
            timeOffConflicts: [
                {
                    pair_id: 'time_off:1:9',
                    shift: duty(1),
                    time_off: {
                        id: 9,
                        user_id: 1,
                        user_name: 'Mere',
                        type: 'training',
                        label: null,
                        description: 'Approval is not assessed.',
                        starts_at: '2026-10-19T11:00:00Z',
                        ends_at: '2026-10-19T13:00:00Z',
                        can: { view_leave: false },
                        urls: { leave: null },
                    },
                },
            ],
        })[0];
        expect(item.summary).toBe('Duty overlaps recorded training');
        expect(item.context?.text).toContain('Tue 20 Oct');
        expect(
            item.actions.some(
                (action) => action.key === 'leave' || action.key === 'cancel',
            ),
        ).toBe(false);
    });
    it('does not turn withheld coverage supply into a zero shortage or offer source-dependent writes', () => {
        const item = buildQueue(
            {
                ...base,
                coverageGaps: [
                    {
                        ...gap,
                        source_assessment: 'not_assessed',
                        assigned_staff: null,
                        missing_staff: null,
                    },
                ],
            },
            true,
        )[0];
        expect(item.context?.text).toBe('Supply and shortage not assessed');
        expect(item.actions.map((action) => action.key)).toEqual(['roster']);
    });
    it('keeps an acknowledged staffing gap visible and makes clearing a real action', () => {
        const item = buildQueue(
            {
                ...base,
                coverageGaps: [
                    {
                        ...gap,
                        acknowledgement: {
                            id: 91,
                            state: 'acked',
                            since: null,
                        },
                    },
                ],
            },
            false,
        )[0];
        expect(item.summary).toContain('Acknowledged');
        expect(item.context?.text).toContain('1 short');
        expect(item.actions.map((action) => action.key)).toContain('clear');
        expect(item.actions.some((action) => action.key === 'create')).toBe(
            false,
        );
    });
    it('includes recurring drift and orphan patterns with canonical source links', () => {
        const items = buildQueue({
            ...base,
            recurringCoverageAlignment: {
                rule_drift: [
                    { ...gap, finding_id: 'recurring_alignment:11:window' },
                ],
                orphan_series: [
                    {
                        finding_id: 'orphan_series:9',
                        series_id: 9,
                        site_id: 3,
                        site_name: 'Matai House',
                        weekdays: ['mon'],
                        urls: {
                            roster: '/operations/rostering?tab=recurring&week=2026-10-19',
                        },
                    },
                ],
            },
        });
        expect(items.map((item) => item.id)).toEqual([
            'recurring_alignment:11:window',
            'orphan_series:9',
        ]);
        expect(items.every((item) => item.type === 'recurring_alignment')).toBe(
            true,
        );
        expect(items[1].actions[0].href).toContain('tab=recurring');
    });
});
describe('Actual scan criteria and draft recovery', () => {
    it('shows the installed criteria and real settings destination without simulated save or switches', () => {
        render(
            <ConflictScanSettingsDialog
                open
                onOpenChange={vi.fn()}
                assessment={{
                    scope: 'approved_sites',
                    interval_basis: 'worker_local_week',
                    publication_assessed: false,
                    visible_duty_count: 2,
                    actionable_duty_count: 2,
                    description: '',
                    categories: {},
                    scan_criteria: {
                        worker_timezone: 'Pacific/Auckland',
                        interval_end_exclusive: true,
                        turnaround_threshold_minutes: 30,
                        automatic_scan: false,
                        publication_assessed: false,
                    },
                    workflow_urls: {
                        workforce_settings: '/operations/workforce-settings',
                    },
                }}
            />,
        );
        expect(screen.getByText(/0–30 minutes/)).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Save settings/ }),
        ).toBeNull();
        expect(screen.queryByRole('switch')).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Open Workforce settings' }),
        ).toHaveAttribute('href', '/operations/workforce-settings');
    });
    it('retains the reason after an unsuccessful save and blocks a second submission while pending', () => {
        const confirm = vi.fn();
        const item = coverageItem();
        const { rerender } = render(
            <ConflictConfirmDialog
                open
                kind="dismiss"
                item={item}
                onOpenChange={vi.fn()}
                onConfirm={confirm}
            />,
        );
        fireEvent.change(screen.getByLabelText('Reason (required)'), {
            target: { value: 'Reviewed required cover' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Dismiss coverage review' }),
        );
        expect(confirm).toHaveBeenCalledWith({
            reason: 'Reviewed required cover',
        });
        rerender(
            <ConflictConfirmDialog
                open
                kind="dismiss"
                item={item}
                pending
                onOpenChange={vi.fn()}
                onConfirm={confirm}
            />,
        );
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(screen.getByLabelText('Reason (required)')).toHaveValue(
            'Reviewed required cover',
        );
        rerender(
            <ConflictConfirmDialog
                open
                kind="dismiss"
                item={item}
                error="Not confirmed"
                onOpenChange={vi.fn()}
                onConfirm={confirm}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent('Not confirmed');
        expect(screen.getByLabelText('Reason (required)')).toHaveValue(
            'Reviewed required cover',
        );
    });
    it('asks before discarding a review reason and allows keeping the draft', () => {
        const close = vi.fn();
        render(
            <ConflictConfirmDialog
                open
                kind="dismiss"
                item={coverageItem()}
                onOpenChange={close}
                onConfirm={vi.fn()}
            />,
        );
        fireEvent.change(screen.getByLabelText('Reason (required)'), {
            target: { value: 'Keep my draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('alertdialog')).toBeVisible();
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Reason (required)')).toHaveValue(
            'Keep my draft',
        );
    });
});
describe('Physical-commit confirmation', () => {
    it.each(['ack', 'dismiss', 'clear'] as const)(
        'confirms only the exact %s attempt and original offset window',
        (action) => {
            const value = receipt(action);
            expect(
                confirmedCoverageReview(value, {
                    gap,
                    action,
                    actorId: 7,
                    requestId,
                    reason:
                        action === 'dismiss'
                            ? 'Reviewed required cover'
                            : undefined,
                }),
            ).toEqual(value);
        },
    );
    it('recognises clear with no active record without inventing a staffing change', () => {
        const value = {
            ...receipt('clear'),
            outcome: 'unchanged',
            changed: false,
            cleared_ids: [],
        };
        expect(
            confirmedCoverageReview(value, {
                gap,
                action: 'clear',
                actorId: 7,
                requestId,
            }),
        ).toEqual(value);
    });
    it.each([
        ['missing result', null],
        ['another actor', { ...receipt(), actor_id: 8 }],
        ['another attempt', { ...receipt(), request_id: 'old-request' }],
        ['wrong action', { ...receipt(), action: 'dismiss' }],
        ['uncommitted', { ...receipt(), committed_at: null }],
        ['no audit', { ...receipt(), audit_id: null }],
        ['claims staffing fixed', { ...receipt(), staffing_resolved: true }],
        [
            'different Site',
            { ...receipt(), window: { ...receipt().window, site_id: 4 } },
        ],
        [
            'different offset instant',
            {
                ...receipt(),
                window: {
                    ...receipt().window,
                    window_starts_at: '2026-10-19T09:00:00Z',
                },
            },
        ],
        ['wrong reason', { ...receipt(), reason: 'other reason' }],
        [
            'wrong key',
            {
                ...receipt(),
                window: { ...receipt().window, coverage_window_key: 'other' },
            },
        ],
    ])('withholds success for %s', (_, value) => {
        expect(
            confirmedCoverageReview(value, {
                gap,
                action: 'ack',
                actorId: 7,
                requestId,
            }),
        ).toBeNull();
    });
    it.each(['=SUM(1,2)', '\t+1', '\u0000@cmd', '  -1'])(
        'neutralises formula-like report content %s',
        (value) => {
            expect(csvCell(value)).toBe('"\'' + value + '"');
        },
    );
    it('preserves quoted user text as one ordinary CSV cell', () => {
        expect(csvCell('Ari, "support"')).toBe('"Ari, ""support"""');
    });
});
