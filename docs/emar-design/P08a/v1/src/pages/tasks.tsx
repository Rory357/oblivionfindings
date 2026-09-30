/* All Tasks — reference frame for the projection P08a adds (plan §2.4, NF-05).
 * All Tasks keeps its own page (P01 v2 drew its medication rows); P08a adds one
 * task per open follow-up, for its owner and for everyone rostered at the house
 * plus the house lead, opening the exact follow-up. Controlled-medicine
 * follow-ups are left out for roles without controlled-medicine access and
 * counted in the caption (P02’s rule for cross-person lists; EM-12). */
import { ListCaption } from '@/components/lists/list-caption';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Inbox } from 'lucide-react';
import { PERSONAS } from '../data';
import { isLead, isOpen } from '../model';
import { FollowUpTable } from '../rows';
import { Shell } from '../shell';
import { useStore } from '../store';
import { ConcealedCount, DesignNote } from '../ui';

export function TasksPage() {
    const s = useStore();
    const p = PERSONAS[s.route.persona];
    const lead = p.perms.includes('followups.manage');
    const rows = s
        .rows()
        .filter((r) => isOpen(r.state))
        .filter((r) => r.owner === p.name || (lead && isLead(r.f)) || (!!p.shift && p.houses.includes(r.f.house)))
        .sort((a, b) => a.f.dueMin - b.f.dueMin);
    // Controlled follow-ups this role can’t see: counted in the caption, never listed (P02 rule).
    const hidden = s
        .concealed()
        .filter((r) => isOpen(r.state))
        .filter((r) => (lead && isLead(r.f)) || (!!p.shift && p.houses.includes(r.f.house))).length;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'All Tasks' }]}>
            <DesignNote title="Reference frame">All Tasks keeps its own design (P01 v2 drew its rostered medication-task rows). This frame shows only the medication follow-up rows P08a adds: one per open follow-up, opening the same record as Meds today and Safety &amp; oversight.</DesignNote>
            <section className="flex flex-col gap-2.5" aria-label="Medication follow-ups">
                <ListCaption
                    title="Medication follow-ups"
                    caption={
                        <>
                            {rows.length} open{hidden ? <> · <ConcealedCount n={hidden} /></> : null} · yours{lead ? ', your houses’ lead sign-offs' : ''} and your house’s
                        </>
                    }
                />
                {rows.length ? (
                    <FollowUpTable rows={rows} showHouse={p.houses.length > 1} keyPrefix="tasks" />
                ) : (
                    <Card className="p-2">
                        <EmptyState icon={Inbox} title="No medication follow-ups" description="Nothing is open for you or your houses." />
                    </Card>
                )}
                {!p.perms.includes('cd.view') ? <p className="text-caption">Showing tasks your role can see. Controlled follow-ups are counted in the caption, not listed.</p> : null}
            </section>
        </Shell>
    );
}
