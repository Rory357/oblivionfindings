/**
 * DRAWING D1 — Health and safety Overview: the trimmed header and the role-based start (replaces the "Lens" switch).
 * One page, three starts: what a support worker, a team lead and the health and safety lead each see first.
 * Event Horizon header (one title, one subline, Report something + Board reports, five numbers, Site and period).
 */
import { PageHeader, PageHeaderFilterSelect, PageHeaderGlassButton, PageHeaderPrimaryButton, PageLayout } from '@/components/page';
import { PageHeaderNumber, PageHeaderNumbers } from '@/components/page/page-header-strip';
import { RowList, SectionHead, SideBySide, Surface, Tally } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { CalendarDays, ClipboardCheck, FileText, MapPin, Plus, ShieldCheck } from 'lucide-react';
import { ACCIDENT, ROLE_LABEL, type Role } from './_data';

function Start({ role }: { role: Role }) {
    if (role === 'support_worker') {
        return (
            <SideBySide cols="minmax(0, 1.4fr) minmax(0, 1fr)">
                <Surface title="Something happened?" sub="Tell us, and we'll take it from there.">
                    <div className="grid gap-2 p-4 sm:grid-cols-2">
                        {[
                            ['Someone was hurt or nearly hurt', 'Report an incident'],
                            ['Something could hurt someone', 'Report a hazard'],
                            ['You gave first aid', 'Record first aid'],
                            ['You’re working alone tonight', 'Start working alone'],
                        ].map(([q, a]) => (
                            <div key={q} className="rounded-xl border border-border bg-card p-3">
                                <div className="text-sm font-semibold text-foreground">{q}</div>
                                <div className="text-xs text-muted-foreground">{a}</div>
                            </div>
                        ))}
                    </div>
                </Surface>
                <RowList
                    title="Your health and safety tasks"
                    sub="2 to do"
                    items={[
                        { key: 1, title: 'Check the first aid kit in the van', sub: 'Kauri House · weekly check', badge: <StatusBadge variant="warning">Due today</StatusBadge> },
                        { key: 2, title: 'Read and confirm: Manual handling, version 3', sub: 'Safe work procedure', badge: <StatusBadge variant="neutral">This week</StatusBadge> },
                    ]}
                />
                <RowList
                    title="What you reported"
                    sub="Where each one is up to"
                    items={[
                        { key: 1, title: ACCIDENT.title + ' — ' + ACCIDENT.person, sub: 'Mon 26 Oct · Health and safety are looking into it', badge: <StatusBadge variant="info">Being investigated</StatusBadge> },
                        { key: 2, title: 'Wet floor by the laundry door', sub: 'Thu 22 Oct · Mat fitted', badge: <StatusBadge variant="success">Fixed</StatusBadge> },
                    ]}
                />
            </SideBySide>
        );
    }
    if (role === 'team_lead') {
        return (
            <>
                <RowList
                    title="Kauri House — needs you"
                    sub="3 things, most urgent first"
                    items={[
                        { key: 1, title: 'Pass the fall in the lounge to Health and safety', sub: `${ACCIDENT.person} · reported Mon 26 Oct by Mere Tane`, badge: <StatusBadge variant="warning">Waiting on you</StatusBadge> },
                        { key: 2, title: 'Fix the cause: replace the loose stair tread', sub: 'Action · owner you · due Fri 30 Oct', badge: <StatusBadge variant="warning">Due tomorrow</StatusBadge> },
                        { key: 3, title: 'Plan the term fire drill', sub: 'Last drill 14 Jul · due by 14 Nov', badge: <StatusBadge variant="neutral">Next 2 weeks</StatusBadge> },
                    ]}
                />
                <Tally
                    title="Kauri House at a glance"
                    sub="Last 30 days"
                    items={[
                        { value: 4, label: 'Incidents', sub: '1 waiting on you', tone: 'warning' },
                        { value: 2, label: 'Hazards open', sub: 'Controls in place' },
                        { value: 1, label: 'Actions overdue', tone: 'critical' },
                        { value: 0, label: 'Restraints', sub: 'None this month' },
                    ]}
                    keepZeros
                />
            </>
        );
    }
    return (
        <>
            <SideBySide cols="minmax(0, 1fr) minmax(0, 1fr)">
                <RowList
                    title="Needs an owner"
                    sub="New safety reviews no one has taken on"
                    items={[
                        { key: 1, title: ACCIDENT.title + ' — ' + ACCIDENT.person, sub: 'Kauri House · passed to Health and safety today', badge: <StatusBadge variant="warning">No owner yet</StatusBadge> },
                        { key: 2, title: 'Chemical splash to the eye — laundry', sub: 'Harbour Respite · yesterday', badge: <StatusBadge variant="warning">No owner yet</StatusBadge> },
                    ]}
                />
                <RowList
                    title="Decide if WorkSafe must be told"
                    sub="The law needs this decided straight away"
                    items={[
                        { key: 1, title: 'Back injury lifting a resident', sub: 'Rimu House · reported 2 days ago', badge: <StatusBadge variant="critical">Decide today</StatusBadge> },
                    ]}
                />
            </SideBySide>
            <SideBySide cols="minmax(0, 1fr) minmax(0, 1fr)">
                <RowList
                    title="Actions to check"
                    sub="Done by their owner — check they worked"
                    items={[
                        { key: 1, title: 'Anti-slip matting at the main entrance', sub: 'Harbour Respite · done Tue 27 Oct', badge: <StatusBadge variant="warning">Check it worked</StatusBadge> },
                        { key: 2, title: 'Re-train staff on medication double-checks', sub: 'Rimu House · done Mon 26 Oct', badge: <StatusBadge variant="warning">Check it worked</StatusBadge> },
                    ]}
                />
                <Tally
                    title="All sites"
                    sub="Last 30 days"
                    items={[
                        { value: 14, label: 'Incidents' },
                        { value: 3, label: 'Lost-time injuries', sub: '1.8 per 100,000 hours' },
                        { value: 9, label: 'Hazards open' },
                        { value: '92%', label: 'Drills done on time', tone: 'success' },
                    ]}
                />
            </SideBySide>
        </>
    );
}

export default function DrawingOverview({ role = 'hs_manager' as Role }: { role?: Role }) {
    const scope = role === 'hs_manager' ? 'All sites' : 'Kauri House';
    return (
        <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health and safety', href: '/health-safety' }]}>
            <Head title="Health and safety" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={ShieldCheck}
                        title="Health and safety"
                        subline={`${scope} · last 30 days · showing the start for ${ROLE_LABEL[role]}`}
                        actions={
                            <>
                                {role !== 'support_worker' ? <PageHeaderGlassButton icon={FileText}>Board reports</PageHeaderGlassButton> : null}
                                <PageHeaderPrimaryButton icon={Plus}>Report something</PageHeaderPrimaryButton>
                            </>
                        }
                        strip={
                            role === 'support_worker' ? undefined : (
                                <PageHeaderNumbers>
                                    <PageHeaderNumber value={role === 'team_lead' ? 1 : 2} label="Need an owner" caption="New safety reviews" tone="warning" href="#" />
                                    <PageHeaderNumber value={1} label="WorkSafe decisions" caption="To make now" tone="critical" href="#" />
                                    <PageHeaderNumber value={role === 'team_lead' ? 1 : 4} label="Actions overdue" caption="Past their due date" tone="warning" href="#" />
                                    <PageHeaderNumber value={2} label="Actions to check" caption="Done — check they worked" href="#" />
                                </PageHeaderNumbers>
                            )
                        }
                        filters={
                            role === 'support_worker' ? undefined : (
                                <>
                                    <PageHeaderFilterSelect icon={MapPin} label="Site" value="all" options={[{ value: 'all', label: scope }]} onChange={() => {}} />
                                    <PageHeaderFilterSelect icon={CalendarDays} label="Period" value="30" options={[{ value: '30', label: 'Last 30 days' }]} onChange={() => {}} />
                                </>
                            )
                        }
                    />
                }
            >
                <div className="grid gap-4">
                    <SectionHead
                        title={role === 'support_worker' ? 'Your start' : role === 'team_lead' ? 'Your site' : 'Your queue'}
                        sub={role === 'hs_manager' ? 'The work only you can move on, oldest first' : undefined}
                        action={{ label: role === 'support_worker' ? 'Everything you reported' : 'All safety reviews', href: '#' }}
                    />
                    <Start role={role} />
                    <p className="text-subtle flex items-center gap-1.5">
                        <ClipboardCheck className="size-4" aria-hidden="true" />
                        Drawing: the start follows the person’s role. No “Lens” switch; registers live under the six hubs in the sidebar.
                    </p>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
