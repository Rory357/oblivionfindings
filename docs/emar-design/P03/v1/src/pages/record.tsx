/* The canonical person medication record — /emar/mar?client_id=… (plan §2.2,
 * §4.1) — as a frame around the Support plan tab. The page top is P02 v1’s
 * approved record header, unchanged: profile PageHeader, the identity subline,
 * Find in this record, Record dose, the meters (P02’s reference numbers — P03
 * doesn’t model doses), the “As at” chip, the six-section rail with Find, and
 * the tier-2 strip. P03 designs the Support plan’s content and sub-views; the
 * other sections are P02’s and link-only here. */
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { AlertTriangle, ClipboardList, Clock3, FileSignature, History as HistoryIcon, Lock, Pill, RefreshCw, ShieldAlert, Stethoscope, Users, type LucideIcon } from 'lucide-react';
import { HOUSES, PEOPLE, PERSONAS, RECORD_REF, SUPPORT, SUPPORT_ORDER, frontline, medsOf, personByClientId, type PersonId } from '../data';
import { cdView } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice, PersonMark, SubTabs } from '../ui';
import { SupportTab } from './support-tab';

type Tab = 'chart' | 'medicines' | 'support' | 'allergies' | 'clinical' | 'history';
const RAIL: { key: Tab; label: string; icon: LucideIcon }[] = [
    { key: 'chart', label: 'Chart', icon: ClipboardList },
    { key: 'medicines', label: 'Medicines', icon: Pill },
    { key: 'support', label: 'Support plan', icon: Users },
    { key: 'allergies', label: 'Allergies & alerts', icon: ShieldAlert },
    { key: 'clinical', label: 'Clinical', icon: Stethoscope },
    { key: 'history', label: 'History', icon: HistoryIcon },
];
/** P02 approved the first two; P03 adds Agreement and Changes. */
export const SUPPORT_VIEWS: { key: string; label: string; icon: LucideIcon }[] = [
    { key: 'bymedicine', label: 'By medicine', icon: Users },
    { key: 'assessment', label: 'Assessment', icon: ClipboardList },
    { key: 'agreement', label: 'Agreement', icon: FileSignature },
    { key: 'changes', label: 'Changes', icon: HistoryIcon },
];

/* ───────────── boundaries: no access (page) vs not found (record) — P02 v1 wording ───────────── */
function NotFound({ moved }: { moved?: boolean }) {
    const s = useStore();
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }, { title: 'Medication record' }]}>
            <Card className="p-8 text-center">
                <p className="text-section-title">We can’t show this record</p>
                <p className="text-subtle mt-1">It may not exist, or it may not be available to you. Check the link, or go back.</p>
                <div className="mt-4">
                    <Button variant="outline" onClick={() => s.toast('info', 'Back — outside this preview.')}>
                        Go back
                    </Button>
                </div>
            </Card>
            <DesignNote title="Design note — not found (the record)">
                <p>A person outside your approved houses and a record that doesn’t exist look the same (404, no existence leak — P00 v5, P02 v1).{moved ? ' This is what Kōwhai House staff see for Ben, who lives at Rimu House.' : ''}</p>
            </DesignNote>
        </Shell>
    );
}

export function RecordPage() {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const pid = personByClientId(Number(s.route.q.get('client_id')));
    if (!pid || !me.houses.includes(PEOPLE[pid].house)) return <NotFound moved={pid === 'ben'} />;
    return <Record pid={pid} />;
}

function Record({ pid }: { pid: PersonId }) {
    const s = useStore();
    const r = s.route;
    const p = PEOPLE[pid];
    const ref = RECORD_REF[pid];
    const tab = (RAIL.some((x) => x.key === r.q.get('tab')) ? r.q.get('tab') : 'support') as Tab;
    const view = SUPPORT_VIEWS.some((v) => v.key === r.q.get('view')) ? r.q.get('view')! : 'bymedicine';
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const meds = medsOf(pid);
    const hidden = cdView(r.persona) ? 0 : meds.filter((m) => m.cd).length;
    const prn = meds.filter((m) => m.prn && (!m.cd || cdView(r.persona))).length;
    const go = (t: Tab, v?: string) => (t === 'support' ? s.set({ tab: 'support', view: v, open: undefined }) : s.toast('info', `${RAIL.find((x) => x.key === t)!.label} is P02’s approved section — outside this preview.`));
    const crumbs = frontline(r.persona)
        ? [{ title: 'Home', href: '/dashboard' }, { title: 'Meds today', href: '/meds/today' }, { title: p.legal }]
        : [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/mar' }, { title: 'MAR & medicines', href: hrefFor('/emar/self-admin', {}, r) }, { title: p.legal }];
    const asAt =
        scn === 'stale' ? (
            <PageHeaderFilterButton icon={AlertTriangle} active onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}>
                As at 8:40 am · couldn’t refresh — try again
            </PageHeaderFilterButton>
        ) : (
            <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                As at 9:12 am · Pacific/Auckland
            </PageHeaderFilterButton>
        );
    const header = (
        <PageHeader
            variant="profile"
            wrapTitle
            backHref={hrefFor(frontline(r.persona) ? '/meds/today' : '/emar/self-admin', {}, r)}
            mark={
                <span className="eh-mark-ring overflow-hidden p-0">
                    <PersonMark pid={pid} size={44} />
                </span>
            }
            title={p.legal}
            titleChip={<PageHeaderStatusChip variant="success">Active</PageHeaderStatusChip>}
            subline={
                <>
                    “{p.pref}” · {p.age} years · NHI {p.nhi} (test)
                    <br />
                    Medication record · {HOUSES[p.house]} · Supported living
                </>
            }
            actions={
                <>
                    <PageHeaderSearchTrigger placeholder="Find in this record…" onOpen={() => s.toast('info', 'Find in this record is P02’s — outside this preview.')} />
                    {PERSONAS[r.persona].perms.includes('administer') ? (
                        <PageHeaderPrimaryButton icon={Pill} onClick={() => s.toast('info', 'Opens P01’s approved “Record a dose” dialog — outside this preview.')}>
                            Record dose
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Due now" onClick={() => go('chart')} ariaLabel="View doses due now on the chart">
                        <PageHeaderMeterBig>{dash ?? ref.due}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{loading ? 'Loading…' : failed ? 'Try again below' : ref.dueCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Late" tone={!dash && ref.late ? 'warning' : 'brand'} onClick={() => go('chart')} ariaLabel="View late doses on the chart">
                        <PageHeaderMeterBig>{dash ?? ref.late}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : ref.lateCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Recorded today" value={dash || !ref.rec ? undefined : `${ref.rec[0]}/${ref.rec[1]}`} onClick={() => go('history')} ariaLabel="View today’s recorded doses in History">
                        {dash || !ref.rec ? <PageHeaderMeterBig>{dash ?? 'n/a'}</PageHeaderMeterBig> : <PageHeaderMeterDonut percent={Math.round((ref.rec[0] / ref.rec[1]) * 100)} caption="of doses due so far" />}
                        {dash || !ref.rec ? <PageHeaderMeterCaption>{dash ? '—' : 'No doses due yet'}</PageHeaderMeterCaption> : null}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Medicines" onClick={() => go('medicines')} ariaLabel="View medicines">
                        <PageHeaderMeterBig>{dash ?? meds.length - hidden}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : hidden ? `+${hidden} controlled hidden` : prn ? `${prn} as needed` : 'All checked'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Allergies" tone={ref.allergyTone} onClick={() => go('allergies')} ariaLabel="View allergies">
                        <PageHeaderMeterBig>{ref.allergy}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{ref.allergyCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {ref.inr ? (
                        <PageHeaderMeterBlock label="INR" value={ref.inr.on} onClick={() => go('clinical')} ariaLabel="View INR results">
                            <PageHeaderMeterBig>{dash ?? ref.inr.value}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{dash ? '—' : ref.inr.cap}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect icon={Users} label="Support" value={r.q.get('sup') ?? 'all'} allValue="all" onChange={(v) => s.set({ sup: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All support' }, ...SUPPORT_ORDER.map((k) => ({ value: k, label: SUPPORT[k].label }))]} />
                    {asAt}
                </>
            }
            rail={<PageHeaderRail<Tab> items={RAIL} value={tab} onSelect={(k) => go(k)} onFind={() => s.toast('info', 'Find is P02’s — outside this preview.')} ariaLabel="Medication record sections" />}
        />
    );

    let body: React.ReactNode;
    if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load this medication record" message="Nothing is shown rather than an incomplete support plan. Staff keep following the support shown in Meds today. Try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (tab !== 'support')
        body = (
            <Card className="p-2">
                <EmptyState icon={Lock} title={`${RAIL.find((x) => x.key === tab)!.label} is P02’s approved section`} description="This preview covers the Support plan only." />
            </Card>
        );
    else body = <SupportTab pid={pid} view={view} />;

    return (
        <Shell crumbs={crumbs}>
            {header}
            <SubTabs
                tabs={SUPPORT_VIEWS.map((v) => ({ key: v.key, label: v.label, icon: v.icon }))}
                active={view}
                onTab={(k) => s.set({ tab: 'support', view: k === 'bymedicine' ? undefined : k, open: undefined })}
                hrefOf={(k) => hrefFor(r.path, { client_id: String(p.clientId), tab: 'support', view: k === 'bymedicine' ? undefined : k }, r)}
                label="Support plan views"
            />
            <div id="p03-record-panel" role="tabpanel" className="flex min-w-0 flex-col gap-5">
                {scn === 'stale' ? (
                    <Notice tone="warning" icon={AlertTriangle} title="This record may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                        We couldn’t refresh it at 9:12 am. It shows what was known at 8:40 am. Check Meds today before changing support.
                    </Notice>
                ) : null}
                {body}
            </div>
        </Shell>
    );
}
