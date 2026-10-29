/**
 * DRAWING D3 — One accident, one place. The incident, its first aid, the safety review and any injury or restraint
 * read as sections of ONE record: one progress line, one "What happens next" card with one main button, one
 * timeline, closed once. At most six tabs; the rest under "More". The underlying records and their privacy rules
 * stay as they are — a section someone may not read shows as "Hidden — you don't have access".
 */
import { PageHeader, PageHeaderGlassButton, PageHeaderRail, PageHeaderStatusChip, PageLayout } from '@/components/page';
import { PageHeaderFacts, PageHeaderStages } from '@/components/page/page-header-strip';
import { Log, PeopleCards, RowList, SideBySide, SideColumn, Story, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Clock, FileText, HeartPulse, Lock, MapPin, Search, ShieldAlert, UserRound } from 'lucide-react';
import { ACCIDENT } from './_data';

type Tab = 'overview' | 'happened' | 'care' | 'investigation' | 'actions' | 'files' | 'history' | 'linked';

export default function DrawingAccidentRecord() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Health and safety', href: '/health-safety' }, { title: 'Incidents and safety reviews', href: '#' }, { title: ACCIDENT.title, href: '#' }]}>
            <Head title={ACCIDENT.title} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        wrapTitle
                        icon={ShieldAlert}
                        title={`${ACCIDENT.title} — ${ACCIDENT.person}`}
                        titleChip={<PageHeaderStatusChip variant="info" icon={Search}>Being investigated</PageHeaderStatusChip>}
                        subline={`${ACCIDENT.site} · ${ACCIDENT.when} · Ref ${ACCIDENT.ref}`}
                        actions={<PageHeaderGlassButton icon={FileText}>Print summary</PageHeaderGlassButton>}
                        facts={<PageHeaderFacts items={[
                            { value: 'Moderate', label: 'How bad', tone: 'warning' },
                            { value: 'Jordan Peri', label: 'Owner' },
                            { value: 'Not notifiable', label: 'WorkSafe, decided Tue 27 Oct', tone: 'success' },
                            { value: 'Bruised left hip', label: 'Injury' },
                        ]} />}
                        strip={<PageHeaderStages
                            ariaLabel="Where this accident is up to"
                            steps={[
                                { label: 'Reported', meta: 'Mere Tane · Mon 26 Oct', state: 'done' },
                                { label: 'Passed to Health and safety', meta: 'Rāwiri Ngata · Tue 27 Oct', state: 'done' },
                                { label: 'Investigated', meta: 'Due Fri 30 Oct', state: 'now' },
                                { label: 'Actions done', state: 'todo' },
                                { label: 'Checked it worked', state: 'todo' },
                                { label: 'Closed', state: 'todo' },
                            ]}
                            next={{ label: 'Next step · due Fri 30 Oct', text: 'Jordan Peri writes down what was found', meta: 'Then the actions to stop it happening again are agreed' }}
                        />}
                        rail={<PageHeaderRail<Tab>
                            value="overview"
                            onSelect={() => {}}
                            ariaLabel="Accident sections"
                            items={[
                                { key: 'overview', label: 'Overview' },
                                { key: 'happened', label: 'What happened' },
                                { key: 'care', label: 'Injury and first aid' },
                                { key: 'investigation', label: 'Investigation' },
                                { key: 'actions', label: 'Actions (2)' },
                                { key: 'files', label: 'Files (3)' },
                                { key: 'history', label: 'History' },
                                { key: 'linked', label: 'Linked records' },
                            ]}
                            maxVisible={6}
                        />}
                    />
                }
            >
                <SideBySide cols="minmax(0, 1.6fr) minmax(0, 1fr)">
                    <div className="grid min-w-0 gap-4">
                        <Surface title="What happens next" sub="One step at a time — this is the one that matters now">
                            <div className="grid gap-3 px-[18px] pt-1 pb-4">
                                <p className="text-sm text-foreground">
                                    <b>Write down what the investigation found.</b> Mere Tane and Rāwiri Ngata have given their accounts.
                                    Say why Aroha fell and what would stop it happening again.
                                </p>
                                <p className="text-subtle">Jordan Peri owns this · due Fri 30 Oct · then you agree the actions</p>
                                <div><Button><Search className="size-4" aria-hidden="true" />Write the findings</Button></div>
                            </div>
                        </Surface>
                        <Story
                            title="What happened"
                            sub="In the reporter’s words"
                            facts={[
                                { icon: MapPin, text: 'Lounge, Kauri House' },
                                { icon: Clock, text: 'Mon 26 Oct 2026, 7:40 pm' },
                                { icon: UserRound, text: 'Seen by Mere Tane' },
                            ]}
                            paras={[
                                { h: 'What happened', t: 'Aroha stood up from the couch to go to the bathroom without her walker and lost her balance on the edge of the rug. She landed on her left side.' },
                                { h: 'What we did straight away', t: 'Checked her where she lay, helped her up with the hoist, gave first aid and rang the on-call nurse. Moved the rug out of the lounge.' },
                            ]}
                        />
                        <RowList
                            title="Part of this accident"
                            sub="Each keeps its own record and who can see it"
                            items={[
                                { key: 1, title: 'First aid — cold pack on the left hip', sub: 'Given by Mere Tane · Mon 26 Oct, 7:50 pm', badge: <StatusBadge variant="success">Recorded</StatusBadge>, leading: <HeartPulse className="size-4" /> },
                                { key: 2, title: 'Falls risk assessment for Aroha', sub: 'Review brought forward to Fri 6 Nov', badge: <StatusBadge variant="warning">Review due</StatusBadge>, leading: <FileText className="size-4" /> },
                                { key: 3, title: 'GP visit notes', sub: 'Health record', badge: <StatusBadge variant="neutral">Hidden — you don’t have access</StatusBadge>, leading: <Lock className="size-4" /> },
                            ]}
                        />
                    </div>
                    <SideColumn>
                        <PeopleCards title="People" cols={1} items={[
                            { name: 'Aroha Kingi', sub: 'Resident · Kauri House', flag: <StatusBadge variant="warning">Bruised hip</StatusBadge> },
                            { name: 'Jordan Peri', sub: 'Owner · health and safety lead' },
                            { name: 'Mere Tane', sub: 'Reported it · support worker' },
                        ]} />
                        <Log
                            title="Timeline"
                            sub="Everything on this accident, newest first"
                            items={[
                                { key: 6, time: 'Wed 28 Oct', tone: 'info', title: 'Rāwiri Ngata gave his account', sub: 'Investigation' },
                                { key: 5, time: 'Tue 27 Oct', tone: 'success', title: 'Decided: WorkSafe doesn’t need to be told', sub: 'Jordan Peri · not a serious injury' },
                                { key: 4, time: 'Tue 27 Oct', tone: 'success', title: 'Passed to Health and safety', sub: 'Rāwiri Ngata → Jordan Peri' },
                                { key: 3, time: 'Mon 26 Oct', tone: 'success', title: 'GP checked Aroha — no fracture', sub: 'Health record' },
                                { key: 2, time: 'Mon 26 Oct', tone: 'success', title: 'First aid given', sub: 'Mere Tane' },
                                { key: 1, time: 'Mon 26 Oct', tone: 'success', title: 'Reported', sub: 'Mere Tane, 7:55 pm' },
                            ]}
                        />
                    </SideColumn>
                </SideBySide>
            </PageLayout>
        </AppLayout>
    );
}
