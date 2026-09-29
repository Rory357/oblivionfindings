/* One payload for the person (plan §4.1 acceptance: the client profile tab and
 * the record read the same thing). Today's cells come from P01's store — so a
 * dose recorded in P01's dialog shows here at once — and earlier days from the
 * P02 history fixtures. Corrections made in the preview are applied on top. */
import type { DoseState } from './p01/contract';
import { windowOf } from './p01/contract';
import { DOSES, type Dose } from './p01/data';
import { useStore as useStore01, type RuntimeRecord } from './p01/store';
import {
    FACTS,
    HISTORY,
    SLOT_ORDER,
    PLANNED,
    medsOf,
    type Admin,
    type HOutcome,
    type Medicine,
    type PersonId,
} from './data';
import { useP02 } from './store';

/** Doses already recorded at Rimu House today (Ben moved there at 8:30 am). P01's
 *  store only knows Kōwhai House, so these outcomes are P02 fixtures. */
const OVERLAY: Record<string, RuntimeRecord> = {
    r21: { outcome: 'given', at: '9:20 am', by: 'Sione Taufa', line: 'Given 9:20 am · Sione T. · at Rimu House', amount: '1 tablet (5 mg) as ordered' },
};

export interface TodayCell {
    key: string;
    med: Medicine;
    slot: string;
    dose?: Dose;
    state: DoseState;
    line: string;
    rec: RuntimeRecord | null;
    planned?: boolean;
}
const short = (name: string) => {
    const [a, b] = name.split(' ');
    return b ? `${a} ${b[0]}.` : a;
};
export function useToday(pid: PersonId) {
    return todayCells(pid, useStore01());
}
/** Pure form for lists of people (the hub board). */
export function todayCells(pid: PersonId, s01: ReturnType<typeof useStore01>) {
    const cells: TodayCell[] = [];
    for (const med of medsOf(pid).filter((m) => m.kind === 'scheduled' && m.status !== 'stopped')) {
        for (const id of med.doseIds) {
            if (PLANNED[id]) {
                cells.push({ key: id, med, slot: PLANNED[id].slot, state: 'notdue', line: PLANNED[id].line, rec: null, planned: true });
                continue;
            }
            const d = DOSES.find((x) => x.id === id) ?? null;
            if (!d) {
                // r21 (Ben) lives in P01's EXTRA_DOSES; rebuild the slot from the medicine.
                const rec = OVERLAY[id] ?? null;
                cells.push({ key: id, med, slot: med.slots[0], state: rec ? 'given' : 'due', line: rec ? rec.line : 'Due now', rec });
                continue;
            }
            const rec = OVERLAY[id] ?? s01.recordOf(d);
            const st: DoseState = OVERLAY[id] ? 'given' : s01.stateOf(d);
            const w = windowOf(d.slotMin);
            const line = rec
                ? rec.line
                : st === 'due'
                  ? `Due now · until ${w.label.split('–')[1]}`
                  : st === 'late'
                    ? `Due ${d.slot} · outside the window`
                    : st === 'notdue'
                      ? `Window opens ${w.label.split('–')[0]}`
                      : st === 'selfmanaged'
                        ? 'Self-managed · nothing to record'
                        : '';
            cells.push({ key: id, med, slot: d.slot, dose: d, state: st, line, rec });
        }
    }
    return cells;
}
export const slotsFor = (cells: { slot: string }[]) => SLOT_ORDER.filter((x) => cells.some((c) => c.slot === x));

const OUT_OF: Record<string, HOutcome> = { given: 'given', prompted: 'prompted', assisted: 'assisted', reoffered: 'reoffered', refused: 'refused', withheld: 'withheld', away: 'away', selfmanaged: 'selfmanaged' };
/** Today's recorded doses as history rows (same shape as earlier days). */
export function useAdmins(pid: PersonId): Admin[] {
    const s = useP02();
    const cells = useToday(pid);
    const today: Admin[] = cells
        .filter((c) => c.rec)
        .map((c) => ({
            id: `today-${c.key}`,
            pid,
            med: c.med.key,
            day: '2026-09-28',
            dayLabel: 'Mon 28',
            slot: c.slot,
            at: c.rec!.at,
            outcome: OUT_OF[c.rec!.outcome] ?? 'given',
            by: c.rec!.by,
            house: c.key === 'r21' ? 'rimu' : FACTS[pid].house,
            amount: c.rec!.amount,
            second: c.rec!.second ? `${c.rec!.second.name} (witness PIN)` : undefined,
            note: c.rec!.extra?.join(' · '),
        }));
    const all = [...today, ...HISTORY.filter((a) => a.pid === pid)];
    return all
        .map((a) => {
            const c = s.corrections[a.id];
            if (!c) return a;
            const merged = { ...a, correction: c };
            if (c.status === 'approved') return { ...merged, outcome: c.to.outcome, at: c.to.at, note: c.to.note ?? a.note };
            return merged;
        })
        .sort((x, y) => (x.day === y.day ? toMin(y.at) - toMin(x.at) : y.day.localeCompare(x.day)));
}
export function toMin(label: string) {
    const m = /^(\d{1,2}):(\d{2})\s*(am|pm)$/i.exec(label.trim());
    if (!m) return 0;
    let h = Number(m[1]) % 12;
    if (m[3].toLowerCase() === 'pm') h += 12;
    return h * 60 + Number(m[2]);
}
export const OUTCOME_LABEL: Record<HOutcome, string> = {
    given: 'Given',
    prompted: 'Taken with prompting',
    assisted: 'Taken with assistance',
    reoffered: 'Given after re-offer',
    refused: 'Refused',
    withheld: 'Withheld',
    away: 'Away',
    selfmanaged: 'Self-managed',
};
export { short };
