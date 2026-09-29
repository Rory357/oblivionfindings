/* Rostered medication tasks (P00 v4/v5, D2.2): one task per dose time per
 * house, for everyone rostered on a covering shift. Built from the same
 * schedule and states as Meds today; it completes by itself when every dose
 * has an outcome. Shared by My Day, All Tasks, My Calendar and the sidebar. */
import { StatusBadge } from '@/components/ui/status-badge';
import { CheckCircle2 } from 'lucide-react';
import { requirementsFor } from './contract';
import { PEOPLE, PERSONAS } from './data';
import { useStore } from './store';

/** `hidden`: people this surface doesn't show (My Day's privacy rule); their open doses are only counted as "more in Meds today". */
export function useMedTasks(hidden: string[] = []) {
    const s = useStore();
    const all = s.visibleDoses().filter((d) => d.support !== 'independent' && d.pid !== 'hine' && d.pid !== 'ben');
    const doses = all.filter((d) => !hidden.includes(d.pid));
    const slots = [...new Set(doses.map((d) => d.slot))];
    return slots.map((slot) => {
        const ds = doses.filter((d) => d.slot === slot);
        const st = ds.map((d) => s.stateOf(d));
        const late = st.filter((x) => x === 'late').length;
        const due = st.filter((x) => x === 'due').length;
        const openN = st.filter((x) => ['due', 'late', 'notdue', 'rejected', 'uncertain', 'queued', 'sending'].includes(x)).length;
        const hiddenOpen = all.filter((d) => d.slot === slot && hidden.includes(d.pid) && ['due', 'late', 'notdue'].includes(s.stateOf(d))).length;
        const blocked = ds.filter((d) => ['due', 'late'].includes(s.stateOf(d)) && (requirementsFor(d, s.ctx).blockAll || requirementsFor(d, s.ctx).blockGiven)).length;
        return {
            slot,
            slotMin: ds[0].slotMin,
            ds,
            late,
            due,
            blocked,
            hiddenOpen,
            done: ds.length - openN,
            total: ds.length,
            people: [...new Set(ds.map((d) => PEOPLE[d.pid].pref))],
            state: (openN === 0 ? 'done' : late ? 'late' : due ? 'due' : 'notdue') as 'done' | 'late' | 'due' | 'notdue',
        };
    });
}
export type MedTask = ReturnType<typeof useMedTasks>[number];

export function MedTaskBadge({ t }: { t: MedTask }) {
    return t.state === 'done' ? (
        <StatusBadge variant="success">
            <CheckCircle2 className="size-3" /> Done — all recorded
        </StatusBadge>
    ) : t.state === 'late' ? (
        <StatusBadge variant="warning">{t.late} late</StatusBadge>
    ) : t.state === 'due' ? (
        <StatusBadge variant="info">Due now</StatusBadge>
    ) : (
        <StatusBadge variant="neutral">Due {t.slot}</StatusBadge>
    );
}

/** Overdue items for the sidebar's All Tasks pill (critical pair). */
export function useOverdueTaskCount() {
    const s = useStore();
    const tasks = useMedTasks();
    const me = PERSONAS[s.route.persona].name;
    // Rostered tasks reach only people rostered at the house (the provider manager isn't).
    const lateTasks = s.route.persona === 'pm' ? 0 : tasks.filter((t) => t.state === 'late').length;
    const overdueFu = s.followUps.filter((f) => f.owner === me && f.state === 'overdue').length;
    const waiting = s.route.persona === 'pm' && s.override === 'waiting' ? 1 : 0;
    const confirm = Object.values(s.records).filter((r) => r.second?.forgot && !r.second.answer && r.second.name === me).length;
    return lateTasks + overdueFu + waiting + confirm;
}
