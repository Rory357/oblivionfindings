/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as the earlier packages’ contract
 * pages: the rules (Main’s answers), today’s screens against P10, and every
 * state with a deep link. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { Shell } from '../shell';
import { hrefFor } from '../store';
import { DesignNote } from '../ui';

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
    return (
        <Card id={id} className="gap-3 p-5">
            <h2 className="text-section-title">{title}</h2>
            {children}
        </Card>
    );
}
function T({ head, rows }: { head: string[]; rows: ReactNode[][] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        {head.map((h) => (
                            <TableHead key={h}>{h}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((r, i) => (
                        <TableRow key={i}>
                            {r.map((c, j) => (
                                <TableCell key={j} className="align-top whitespace-normal">
                                    {c}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function Links({ children }: { children: ReactNode[] }) {
    return (
        <span className="flex flex-wrap gap-x-3 gap-y-1">
            {children.map((c, i) => (
                <span key={i}>{c}</span>
            ))}
        </span>
    );
}

export function ContractPage() {
    const L = (label: string, path: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
            {label}
        </a>
    );
    const A = '/emar/emergency-access';
    const M = '/emar/mar';
    const D = '/emar/downtime';
    const R = '/emar/reports';
    const S = '/emar/settings';
    const pm = { as: 'pm' };
    const hana = { as: 'clinical' };
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p10/contract' }, { title: 'P10 emergency access & downtime contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P10 decides: where emergency access lives and who sees it; how it starts, runs, extends and ends; how every grant is reviewed; the repeat-use flag and the daily report; its events in the audit trail; the downtime pack; and paper reconciliation (designed — its build needs Stephan’s OK). The policy tab is P11’s (B3), linked and not redesigned; P10 adds the second-person and review settings to it.</p>
            </DesignNote>

            <Section id="rules" title="1. Rules (Main’s answers under Stephan’s delegation, 1 October — all (A), with refinements)">
                <T
                    head={['Rule', 'P10']}
                    rows={[
                        ['Q1 — where it lives', 'Safety & oversight › Emergency access: Running now · To review · History. Holders of emergency access (medications.breakglass) see Running now and History and can start it. Reviewers (medications.audit.view — clinical leads, coordinators, managers, auditors) see To review and History, and Running now read-only. The page gate becomes breakglass or audit.view. Nobody else is told it exists. The policy is P11’s tab, linked.'],
                        ['Q2 — who holds it', 'No change: admins and provider managers. The copy is role-neutral — “Self-authorise (RN+)”, “Registered Nurse or above” and “within 48 hours” are gone. Relief and agency access stays with D2 (P00/P01). Personas: Rangi Parata (provider manager) starts it; Hana Kereama, Tomasi Vea and Mereana Walsh review.'],
                        ['Q3 — starting it', 'Five steps in the WizardShell, pre-filled from context: who it’s for (fixed when opened from a person) · why (a category and a line) · how long (only lengths up to the longest grant) · the second person · check and start (two required ticks — required on the server too). Its scope is said plainly. A second live grant for the same person is blocked — extend the one you have. Contextual starts: the person’s MAR, the Meds today blocked row and the record dialog, for holders only; everyone else is told the real route.'],
                        ['Q4 — the second person', 'Confirms with their own witness PIN (PIN-1) on the starter’s screen; the same eligibility as today. “Required” and nobody here: it can’t start — the screen gives the house’s on-call contact (P11), with no way around it (Main’s refinement).'],
                        ['Q5 — running, ending, extending', 'The person using it sees “Emergency access for Aroha — ends 9:31 am (19 min left)” on the page, the chart and the record dialog; amber at 10 minutes, when Extend appears (+30 minutes with a reason, never past 4 hours in all). If it ends mid-record, what was entered is kept and it says so plainly. “I’m done” ends it early (no reason, non-destructive). A clinical lead, coordinator or provider manager ending someone else’s gives a reason and confirms (destructive); the person is told — the new key medications.breakglass.end (Main, v1 decision), never the auditor. Build: expiry is recorded as an event at its time; the policy is stored on the grant.'],
                        ['Q6 — review (NF-12)', 'Every ended grant — ran out, ended early or ended by someone — joins To review, due within the policy’s time (default 2 days); overdue ones go to Follow-ups and the daily report. Never reviewed by the person who used it or the one who confirmed it. The review shows what was done, each linked. “Not justified” needs notes and offers the medication-error route. A review is never overwritten: a correction is added beside it, with a reason.'],
                        ['Q7 — told, flagged, reported', 'A start tells the house’s reviewers and the second person — not HR or finance (a routing rule with include_managers off). Repeat use (4 within 7 days) is a flag in History: acknowledged with a reason, back on new use, never blocking. The daily report covers the NZ day, includes ended grants and overdue reviews, goes to P11’s “Who gets the daily report”, and links here.'],
                        ['Q8 — the audit trail', 'Opened (as P09’s E-bg-1 reads), extended, closed (early · by someone · ran out), reviewed, review corrected, repeat use flagged and acknowledged — each an event in the house’s chain, kind “Emergency access”. Every dose or order under a grant names it. The history export is P09’s dialog, with a purpose, under medications.audit.export.'],
                        ['Q9 — the downtime pack', 'In P09’s Print & exports: one house, today or tomorrow — recording sheets built from the scheduled doses with blank boxes, as-needed limits, allergies, round sheets, controlled register pages and a “recording on paper” page; “Printed … for today only” on every page. Controlled register pages only for someone with controlled view; otherwise a line says to ask the house lead, and the rest prints (Main’s refinement). A second-person box for every dose whose current rules need one — controlled medicines and active medication rules (Aroha’s insulin, P01’s mr2), with a reading box for mr1 — and “—” only where none is needed (v1.1). Meds today, offline or out of date, opens the pack this device has; the offline banner says what can’t be saved offline.'],
                        ['Q10 — who prints it', 'Anyone with medications.reports.view for the house — house leads, clinical leads, coordinators, managers. The purpose “Downtime” is filled in and recorded. (The auditor only exports the audit trail, as P09 decided.)'],
                        ['Q11 — paper reconciliation (build: scope needs Stephan’s OK)', 'A lead records the downtime (when, why, the paper sheets). Every scheduled dose in it with nothing recorded is listed; as-needed doses the paper lists are added. Each is entered with the time on the paper and who gave it; the entry time is kept beside it — never silently backdated. A lead entering for someone asks them to confirm; a witnessed dose waits for its witness’s PIN; controlled entries go into the register in time order and the closing count checks it. Entries only inside a recorded downtime; ordinary late entries keep P01’s rules.'],
                        ['v1 decisions', 'An offline dose that syncs after its grant ended is accepted only when it was queued offline with its captured time inside the grant, and reviewers see “sent after the grant ended”; anything else is refused, as today. A dose that needs a second person can’t be saved offline at all (D7).'],
                        ['Not decided here', 'Office order authority under emergency access stays with Stephan’s end review. The review lists whatever orders were changed under a grant; the mockup neither adds nor removes order actions.'],
                    ]}
                />
            </Section>

            <Section id="screens" title="2. Today’s screens and P10">
                <T
                    head={['Today', 'P10', 'Try it']}
                    rows={[
                        ['/emar/emergency-access — breakglass only; Active cards with a ring countdown; Audit log; Flagged; Policy & settings', 'Safety & oversight › Emergency access: Running now · To review · History; the policy is P11’s tab', <Links key="a">{[L('Rangi — running now', A, pm), L('Hana — to review', A, hana), L('Mereana — history', A, { as: 'auditor', view: 'history' })]}</Links>],
                        ['_request-dialog.tsx — 4 steps, fixed 30/60/120/240, “RN+”, the co-signer picked from a list', 'Start emergency access — 5 steps, lengths up to the longest grant, a second person with their PIN', <Links key="b">{[L('From Mele’s MAR', M, { ...pm, person: 'mele' }), L('The wizard', A, { ...pm, open: 'request:mele' })]}</Links>],
                        ['_review-dialog.tsx — only on the breakglass page; self-review; overwrites', 'Review it (never your own) and Correct the review (added beside it)', <Links key="c">{[L('Hana reviews EA-11', A, { ...hana, open: 'review:EA-11' }), L('Tomasi corrects EA-8', A, { as: 'coord', view: 'history', open: 'correct:EA-8' })]}</Links>],
                        ['Extend — anyone who manages it, no reason, no event', 'Extend — the person using it, at 10 minutes or less, with a reason', L('Rangi, 8 minutes left', A, { ...pm, scn: 'ending' })],
                        ['Revoke — native confirm(), no reason', '“I’m done” (theirs) · “End their access” with a reason (others)', <Links key="d">{[L('I’m done', A, { ...pm, open: 'done:EA-13' }), L('Hana ends it', A, { ...hana, view: 'active', open: 'end:EA-13' })]}</Links>],
                        ['A save after expiry — “You do not have a current assignment for this medication action.”', 'The record dialog keeps what was entered and says it ended', L('Rangi, ran out at 9:10', M, { ...pm, person: 'aroha', scn: 'expired', open: 'record:aroha:o-insulin' })],
                        ['No audit events; P09 has no break-glass source', 'Every action is an event in the chain', L('Audit trail, emergency access', R, { ...pm, view: 'audit', kind: 'access' })],
                        ['Round sheet lists only recorded doses; no pack', 'The downtime pack', <Links key="e">{[L('Jordan makes it', R, { as: 'lead', view: 'exports', open: 'export:pack' }), L('Hana — no controlled pages', R, { ...hana, view: 'exports', open: 'export:pack' })]}</Links>],
                        ['Offline banner: “We’ll send anything you save when you’re back”', 'Says what can’t be saved offline, opens the pack', L('Priya, offline', '/meds/today', { as: 'sw', scn: 'offline' })],
                        ['No paper reconciliation', 'Downtime & paper records', <Links key="f">{[L('Sione — DT-4', D, { as: 'rimu', dt: 'DT-4' }), L('Enter Ben’s amlodipine', D, { as: 'rimu', dt: 'DT-4', open: 'paper:PI-1' })]}</Links>],
                    ]}
                />
            </Section>

            <Section id="index" title="3. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Running — the live strip; Extend not offered yet', <Links key="1">{[L('Emergency access', A, pm), L('Aroha’s MAR', M, { ...pm, person: 'aroha' }), L('Recording under it', M, { ...pm, person: 'aroha', open: 'record:aroha:o-insulin' })]}</Links>],
                        ['Ending soon — amber, Extend offered', <Links key="2">{[L('The strip', A, { ...pm, scn: 'ending' }), L('Extend', A, { ...pm, scn: 'ending', open: 'extend:EA-13' }), L('Extend “not yet” (deep link at 19 min)', A, { ...pm, open: 'extend:EA-13' })]}</Links>],
                        ['Grant expired mid-task', <Links key="3">{[L('The chart', M, { ...pm, person: 'aroha', scn: 'expired' }), L('The record dialog', M, { ...pm, person: 'aroha', scn: 'expired', open: 'record:aroha:o-insulin' }), L('To review', A, { ...hana, scn: 'expired' })]}</Links>],
                        ['Revoked — ended by someone else (EA-8)', <Links key="4">{[L('The grant', A, { ...hana, view: 'history', open: 'grant:EA-8' }), L('End Rangi’s access', A, { ...hana, view: 'active', open: 'end:EA-13' })]}</Links>],
                        ['Review — justified / not justified', <Links key="5">{[L('Review EA-11 (overdue)', A, { ...hana, open: 'review:EA-11' }), L('EA-10 justified', A, { ...hana, view: 'history', open: 'grant:EA-10' }), L('EA-8 not justified', A, { ...hana, view: 'history', open: 'grant:EA-8' })]}</Links>],
                        ['Reviewer rules — not your own, not one you confirmed', <Links key="6">{[L('Rangi opens a review of his own', A, { ...pm, view: 'review', open: 'review:EA-11' }), L('Hana, who confirmed EA-13', A, { ...hana, view: 'active', open: 'grant:EA-13' })]}</Links>],
                        ['Misuse flag — repeat use', <Links key="7">{[L('History', A, { ...hana, view: 'history' }), L('Acknowledge', A, { ...hana, view: 'history', open: 'ack:F-3' }), L('Rangi can’t acknowledge his own', A, { ...pm, view: 'history', open: 'ack:F-3' })]}</Links>],
                        ['Second person required · nobody here', <Links key="8">{[L('On-call contact', A, { ...pm, scn: 'required', open: 'request:mele' }), L('No on-call contact set', A, { ...pm, scn: 'oncallnone', open: 'request:mele' })]}</Links>],
                        ['A second grant for the same person', L('Rangi starts another for Aroha', A, { ...pm, open: 'request:aroha' })],
                        ['Not told about emergency access', <Links key="9">{[L('Priya — Aroha’s chart', M, { as: 'sw', person: 'aroha' }), L('Jordan — the page (not available)', A, { as: 'lead' }), L('Jordan — a start link', M, { as: 'lead', person: 'aroha', open: 'request:aroha' })]}</Links>],
                        ['The pack’s first page — second-person and reading boxes (v1.1)', L('Jordan', R, { as: 'lead', view: 'exports', open: 'export:pack' })],
                        ['The record dialog — P01’s reading and second person (v1.1)', L('Rangi, Aroha’s insulin', M, { ...pm, person: 'aroha', open: 'record:aroha:o-insulin' })],
                        ['The downtime pack — with and without controlled pages; offline', <Links key="10">{[L('Jordan', R, { as: 'lead', view: 'exports', open: 'export:pack' }), L('Hana', R, { ...hana, view: 'exports', open: 'export:pack' }), L('Offline', R, { as: 'lead', view: 'exports', scn: 'offline', open: 'export:pack' })]}</Links>],
                        ['Paper records — entering, for someone else, adding, finishing', <Links key="11">{[L('DT-4', D, { as: 'rimu', dt: 'DT-4' }), L('Enter for Ana', D, { as: 'rimu', dt: 'DT-4', open: 'paper:PI-2' }), L('Add from paper', D, { as: 'rimu', dt: 'DT-4', open: 'paperadd:DT-4' }), L('Record a downtime', D, { as: 'lead', open: 'declare' })]}</Links>],
                        ['P10’s additions to P11’s tab', <Links key="12">{[L('Rangi (edits)', S, { ...pm, view: 'alerts', sec: 'emergency' }), L('Hana (reads)', S, { ...hana, view: 'alerts', sec: 'emergency' })]}</Links>],
                        ['Loading · empty · couldn’t load · out of date · offline · event log can’t be written', <Links key="13">{[L('Loading', A, { ...pm, scn: 'loading' }), L('Empty', A, { ...hana, scn: 'empty' }), L('Couldn’t load', A, { ...pm, scn: 'unavailable' }), L('Out of date', A, { ...pm, scn: 'stale' }), L('Offline', A, { ...pm, scn: 'offline', open: 'request:mele' }), L('Can’t be written', A, { ...hana, scn: 'logdown', open: 'review:EA-11' })]}</Links>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
