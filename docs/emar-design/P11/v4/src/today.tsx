/* Meds today is P01’s page. P11 designs only its “My eligibility” meter (the worker’s own
 * view) and the Rounds link back to Settings for managers. Nothing else here is stubbed. */
import { PageHeader, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderRail, PageHeaderStatusChip } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Activity, ArrowUpRight, Clock, Flag, Package, Pill, Repeat, Shield } from 'lucide-react';
import type { ReactElement } from 'react';
import { ANSWERS, NOW_LABEL } from './data';
import { has, leadCap, mySelf, restrictMode, useStore } from './model';
import { settingsHref, useNav } from './nav';
import { Flash, Note } from './ui';

const TODAY_VIEWS = [
    { key: 'schedule', label: 'Schedule', icon: Clock, pkg: 'P01', ok: () => true },
    { key: 'rounds', label: 'Rounds', icon: Repeat, pkg: 'P01', ok: () => true },
    { key: 'asneeded', label: 'As-needed', icon: Pill, pkg: 'P01', ok: (p: Parameters<typeof has>[0]) => has(p, 'administer') },
    { key: 'followups', label: 'Follow-ups', icon: Flag, pkg: 'P08a', ok: (p: Parameters<typeof has>[0]) => has(p, 'administer') },
    { key: 'controlled', label: 'Controlled checks', icon: Shield, pkg: 'P07a', ok: (p: Parameters<typeof has>[0]) => has(p, 'cd.record') || has(p, 'cd.witness') },
    { key: 'stockalerts', label: 'Stock alerts', icon: Package, pkg: 'P06', ok: () => true },
    { key: 'activity', label: 'Activity', icon: Activity, pkg: 'P01', ok: () => true },
];

export function MedsTodayPage() {
    const { m } = useStore();
    const { route, go, open } = useNav();
    const p = m.persona, x = mySelf(m);
    const views = TODAY_VIEWS.filter((v) => v.ok(p));
    const view = views.find((v) => v.key === route.view) ?? views[0];
    const meter = (() => {
        if (!has(p, 'administer')) return { big: 'Not assessed', cap: 'You don’t record doses in this role', tone: 'brand' as const };
        switch (m.my) {
            case 'due': return { big: 'Renewal due', cap: `Ends ${x.until} · in ${x.days} days`, tone: 'warning' as const };
            case 'expired': return { big: 'Expired', cap: 'Competency ended 14 Sep 2026', tone: 'critical' as const };
            case 'restricted': return restrictMode(m) === 'cosigner' ? { big: 'Restricted', cap: 'Co-signer needed for given', tone: 'warning' as const } : { big: 'Restricted', cap: 'Can’t sign doses as given', tone: 'critical' as const };
            case 'exempt': return { big: 'Exemption', cap: 'Until 3 Oct 2026 · can’t witness', tone: 'warning' as const };
            case 'ack': return m.myAck ? { big: 'Current', cap: 'To 28 Sep 2027 · can witness', tone: 'success' as const } : { big: 'Acknowledge', cap: 'New assessment waiting for you', tone: 'critical' as const };
            case 'none': return { big: 'Not assessed', cap: 'Can’t record given doses yet', tone: 'critical' as const };
            default: return { big: 'Current', cap: 'To 14 Mar 2027 · can witness', tone: 'success' as const };
        }
    })();
    return (
        <div className="space-y-5">
            <PageHeader className="overflow-clip!" icon={Pill} title="Meds today" titleChip={<PageHeaderStatusChip variant="neutral">Kōwhai House</PageHeaderStatusChip>} subline={`Tuesday 29 September · your shift 7:00 am–3:00 pm · checked ${NOW_LABEL}`}
                meters={<PageHeaderMeterBlock className="max-w-[300px]" label="My eligibility" tone={meter.tone} ariaLabel={`View my eligibility: ${meter.big.toLowerCase()}`} onClick={() => open({ kind: 'me' })}><PageHeaderMeterBig>{meter.big}</PageHeaderMeterBig><PageHeaderMeterCaption>{meter.cap}</PageHeaderMeterCaption></PageHeaderMeterBlock>}
                rail={<PageHeaderRail items={views.map(({ key, label, icon }) => ({ key, label, icon }))} value={view.key} onSelect={(k) => go(`#/today/${k}`)} ariaLabel="Meds today views" />} />
            <Flash />
            <Note>This preview covers only the <b>My eligibility</b> block. Meds today’s other meter blocks (Due now, Late, Needs help, Recorded, Follow-ups) belong to P01 and are approved in P00 v5, so they aren’t repeated here.</Note>
            {view.key === 'rounds' && has(p, 'orders.manage') && has(p, 'settings.manage') ? (
                <Card className="flex flex-row flex-wrap items-center justify-between gap-4 p-4">
                    <div><p className="text-sm font-semibold">Round templates have moved to Settings</p><p className="text-caption mt-1">Add, change, pause or retire templates, and create rounds for a day, in Settings › Rounds &amp; timing. Today’s rounds stay here.</p></div>
                    <Button variant="link" onClick={() => go(settingsHref('rounds', 'templates'))}>Open round templates<ArrowUpRight className="size-4" /></Button>
                </Card>
            ) : null}
            <EmptyState icon={view.icon} title={`${view.label} is designed in ${view.pkg}`} description="It isn’t part of this preview. Use the My eligibility block above." />
            {leadCap(p) ? null : <p className="text-caption">Signed in as a support worker: leads see the full register in Safety &amp; oversight › Staff eligibility.</p>}
        </div>
    );
}

/* ── Preview guide (preview tooling, not app UI): where to see every state ── */
export function GuidePage() {
    const L = (href: string, t: string) => <a key={href + t} href={href} className="text-primary underline-offset-4 hover:underline">{t}</a>;
    const rows: [string, ReactElement[], string][] = [
        ['v4 additions (Stephan, 29 Sep)', [L('#/settings/alerts/log?as=clinical', 'Alert log'), L('#/settings/alerts/log?as=clinical&open=alertlog:l5', 'An after-hours alert nobody else heard about'), L('#/settings/alerts/delivery?as=clinical&dlv=1&oncall=1', 'Delivery: push, quiet hours, who can’t be reached'), L('#/settings/alerts/alerts?as=clinical', 'Push column'), L('#/settings/alerts/oncall?as=lead&oncall=1&open=oncall:kowhai', 'On-call: cellphone consent and leave'), L('#/settings/alerts/oncall?as=lead&open=consent', 'What staff see before their cellphone is shown'), L('#/settings/history/decide?as=clinical&open=reviewdefaults', 'Review the defaults one by one'), L('#/settings/history/changes?as=clinical&open=hist:h3', 'Put an earlier value back')], 'Push, the alert log, quiet hours, who can’t be reached, reviewing the defaults, restoring from history, and cellphone consent.'],
        ['Loading', [L('#/settings/rules/safety?as=clinical&sd=loading', 'Settings'), L('#/safety/eligibility?as=clinical&ed=loading', 'Staff eligibility')], 'Meters say “Loading…” with a dash, never 0; the body is the shared SkeletonTable.'],
        ['Empty', [L('#/settings/history/changes?as=clinical&sd=first', 'Change history — first use'), L('#/settings/rounds/templates?as=lead&tpl=empty', 'Round templates — none yet'), L('#/settings/rules/medicines?as=clinical&rules=empty', 'Medicine rules — none yet'), L('#/safety/eligibility/exemptions?as=clinical', 'Exemptions — none'), L('#/safety/eligibility?as=clinical&ed=empty', 'Nobody assessed yet')], 'EmptyState says what the absence means and what happens meanwhile.'],
        ['Not applicable', [L('#/safety/eligibility?as=clinical&ed=empty', '“n/a” meter'), L('#/safety/eligibility?as=clinical&open=av:aisha', 'Areas while given doses can’t be recorded')], 'A zero denominator reads n/a, never 0 %.'],
        ['No access', [L('#/settings/rules?as=finance', 'Settings as finance'), L('#/safety/eligibility?as=sw', 'Staff eligibility as a support worker')], 'Names the page, never its content, says who can help, and offers a page the person can open.'],
        ['Read-only by role', [L('#/settings/rules/safety?as=auditor', 'Auditor (answer 6)'), L('#/settings/rules/safety?as=lead', 'House lead on organisation rules'), L('#/settings/alerts/emergency?as=clinical', 'Clinical lead on the emergency access policy')], 'Everything visible; switches disabled; the save bar says why and who can change it.'],
        ['Not found', [L('#/safety/eligibility?as=lead&open=av:zz', 'A staff record'), L('#/settings/rounds/templates?as=lead&open=tpl:zz', 'A template link')], '“We can’t show this record” — a hidden record and a missing one look the same.'],
        ['Validation, values kept', [L('#/settings/rounds/timing?as=clinical&draft=bad&err=1', 'Dose timing'), L('#/settings/rounds/templates?as=lead&open=tpl:new&twerr=1', 'Template wizard'), L('#/safety/eligibility?as=clinical&open=aw:new&awstep=1&seed=partial&awerr=1', 'Assessment areas'), L('#/safety/eligibility/exemptions?as=clinical&open=xw:aisha&xstep=1&until=2026-11-30&xerr=1', 'Exemption too long'), L('#/settings/alerts/oncall?as=lead&open=oncall:kowhai&owerr=1', 'On-call phone')], 'Inline under the field (FieldErr), focus on the first error, everything else stays filled.'],
        ['Stale', [L('#/settings/rules/safety?as=clinical&sd=stale', 'Settings'), L('#/safety/eligibility?as=clinical&ed=stale', 'Staff eligibility')], 'Says when it was last updated (NZDT) and offers Refresh; unsaved changes are kept.'],
        ['Failure with retry', [L('#/settings/rules?as=clinical&sd=error', 'Settings couldn’t load'), L('#/safety/eligibility?as=clinical&ed=error', 'Register couldn’t load'), L('#/settings/rounds/timing?as=clinical&draft=1&save=fail&open=review:rounds', 'Save fails — Try again')], 'The rules still apply when doses are saved; nothing typed is lost.'],
        ['Conflict', [L('#/settings/rounds/timing?as=clinical&draft=1&save=conflict&open=review:rounds', 'Someone else saved first')], 'Names who and when; keeps your changes; refresh before saving again.'],
        ['Offline', [L('#/settings/rules/safety?as=clinical&sd=offline', 'Settings offline')], 'Read-only while offline; settings are never saved on the device.'],
        ['Success', [L('#/settings/rounds/timing?as=clinical&draft=1', 'Review and save dose timing'), L('#/safety/eligibility?as=clinical&open=aw:daniel:renew&awstep=4&seed=pass', 'Record an assessment'), L('#/today?as=sw&my=ack&open=ack', 'Acknowledge my assessment')], 'A status notice on the page plus a lasting sign: “Set by …”, “Just now” in Change history, the register row changes.'],
        ['Unsaved-changes guard', [L('#/settings/rounds/timing?as=clinical&draft=1', 'Drafts in 3 views — then use the sidebar'), L('#/settings/rules?as=clinical&draft=1&open=guard', 'The guard dialog')], 'Fleet’s “Leave with an unsaved draft?”. Drafts survive switching tabs and views.'],
        ['Interruption and resume', [L('#/safety/eligibility?as=clinical&open=aw:daniel:renew&awstep=1&seed=partial&discard=1', 'Discard this draft?')], 'Closing a half-filled wizard asks first (DiscardDraftDialog).'],
        ['Focus return', [L('#/settings/rounds/templates?as=lead', 'Open and close any dialog')], 'Radix Dialog traps focus, closes on Escape and returns focus to the control that opened it.'],
        ['Queued / rejected', [<span key="na">Not applicable</span>], 'Settings and assessments are never queued offline — they need a connection.'],
    ];
    return (
        <div className="space-y-5">
            <div><h1 className="text-page-title">Preview guide</h1><p className="text-subtle mt-1">Preview tooling, not part of the app. Every state, with a link that opens it. Synthetic data only.</p></div>
            <Card className="gap-0 overflow-hidden p-0">
                <div className="grid grid-cols-[180px_1.4fr_1.4fr] gap-4 border-b bg-muted/30 p-3 text-[12px] font-semibold text-muted-foreground"><span>State</span><span>Where</span><span>How it behaves</span></div>
                {rows.map(([s, links, how]) => (
                    <div key={s} className="grid grid-cols-[180px_1.4fr_1.4fr] gap-4 border-b p-3 text-[13px] last:border-0"><span className="font-semibold">{s}</span><span className="flex flex-wrap gap-x-3 gap-y-1">{links}</span><span className="text-subtle">{how}</span></div>
                ))}
            </Card>
            <div><h2 className="text-section-title">Stephan’s 21 answers, built in</h2><p className="text-subtle mt-1">Relayed on 29 September 2026. Behaviour and wording carry over from v1 unchanged; v2 changes the build method and the presentation.</p></div>
            <Card className="gap-0 overflow-hidden p-0">
                {ANSWERS.map(([n, t, a]) => <div key={n} className="grid grid-cols-[48px_220px_1fr] gap-4 border-b p-3 text-[13px] last:border-0"><StatusBadge variant="neutral" size="sm">{n}</StatusBadge><span className="font-semibold">{t}</span><span>{a}</span></div>)}
            </Card>
        </div>
    );
}
