/**
 * DRAWING D4 — Hubs with connected tabs instead of card pages ("Safety registers" and "Reports & assurance" today
 * only hold links). Six hubs in the sidebar; inside each hub the sibling lists are tabs on the header rail, so you
 * move sideways between them without going back. Each tab keeps its own address, permissions and filters.
 * Shown: "Risks and prevention" on its Hazards tab, plus the six-hub map.
 */
import { PageHeader, PageHeaderFilterSelect, PageHeaderPrimaryButton, PageHeaderRail, PageHeaderSearch, PageLayout } from '@/components/page';
import { PageHeaderNumber, PageHeaderNumbers } from '@/components/page/page-header-strip';
import { RowList, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { AlertOctagon, MapPin, Plus } from 'lucide-react';

type Tab = 'hazards' | 'risk' | 'chemicals' | 'ppe' | 'procedures' | 'drills';

const HUBS: [string, string][] = [
    ['Overview', 'Your start · the numbers that matter'],
    ['Incidents and safety reviews', 'Incidents · Safety reviews · Actions · WorkSafe notifications'],
    ['Injuries and care', 'Workplace injuries · First aid · Restraints · Chemical exposures'],
    ['Risks and prevention', 'Hazards · Risk assessments · Chemicals · Protective gear (PPE) · Procedures · Drills'],
    ['People and participation', 'Health and safety representatives · Committees · Consultations · Working alone'],
    ['Reports', 'Trends · Board reports'],
];

export default function DrawingHub() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health and safety', href: '/health-safety' }, { title: 'Risks and prevention', href: '#' }, { title: 'Hazards', href: '#' }]}>
            <Head title="Hazards" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={AlertOctagon}
                        title="Hazards"
                        subline="Risks and prevention · things that could cause harm, and what’s been done about them"
                        actions={<>
                            <PageHeaderSearch value="" onChange={() => {}} placeholder="Search hazards…" ariaLabel="Search hazards" />
                            <PageHeaderPrimaryButton icon={Plus}>Report a hazard</PageHeaderPrimaryButton>
                        </>}
                        strip={<PageHeaderNumbers>
                            <PageHeaderNumber value={9} label="Open" caption="Not fixed yet" href="#" />
                            <PageHeaderNumber value={2} label="High or extreme" caption="Risk after controls" tone="critical" href="#" />
                            <PageHeaderNumber value={3} label="Reviews due" caption="In the next 2 weeks" tone="warning" href="#" />
                            <PageHeaderNumber value={14} label="Fixed this month" caption="Controls in place" href="#" />
                        </PageHeaderNumbers>}
                        filters={<PageHeaderFilterSelect icon={MapPin} label="Site" value="all" options={[{ value: 'all', label: 'All sites' }]} onChange={() => {}} />}
                        rail={<PageHeaderRail<Tab>
                            value="hazards"
                            onSelect={() => {}}
                            ariaLabel="Risks and prevention"
                            items={[
                                { key: 'hazards', label: 'Hazards' },
                                { key: 'risk', label: 'Risk assessments' },
                                { key: 'chemicals', label: 'Chemicals' },
                                { key: 'ppe', label: 'Protective gear' },
                                { key: 'procedures', label: 'Procedures' },
                                { key: 'drills', label: 'Drills' },
                            ]}
                        />}
                    />
                }
            >
                <div className="grid gap-4">
                    <RowList
                        title="Open hazards"
                        sub="Highest risk first"
                        items={[
                            { key: 1, title: 'Loose stair tread, main stairwell', sub: 'Kauri House · owner Rāwiri Ngata · fix due Fri 30 Oct', badge: <StatusBadge variant="critical">High</StatusBadge> },
                            { key: 2, title: 'Hoist sling fraying at the seam', sub: 'Rimu House · taken out of use · new sling ordered', badge: <StatusBadge variant="critical">High</StatusBadge> },
                            { key: 3, title: 'Hot water at the kitchen tap over 55°C', sub: 'Harbour Respite · plumber booked Mon 2 Nov', badge: <StatusBadge variant="warning">Medium</StatusBadge> },
                            { key: 4, title: 'Wet floor by the laundry door after rain', sub: 'Kauri House · mat fitted, check after next rain', badge: <StatusBadge variant="neutral">Low</StatusBadge> },
                        ]}
                    />
                    <Surface title="The six hubs in the sidebar" sub="One sidebar link per hub; the lists inside it are tabs like the ones above">
                        <ol className="grid gap-2 px-[18px] pt-1 pb-4 sm:grid-cols-2">
                            {HUBS.map(([hub, tabs], i) => (
                                <li key={hub} className="rounded-xl border border-border bg-card p-3">
                                    <div className="text-sm font-semibold text-foreground">{i + 1}. {hub}</div>
                                    <div className="text-xs text-muted-foreground">{tabs}</div>
                                </li>
                            ))}
                        </ol>
                    </Surface>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
