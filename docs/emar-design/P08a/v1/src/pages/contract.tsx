/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as P01 / P07a’s contract pages. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { FROM_PACKAGE, TYPE_LABEL, type FuType } from '../data';
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
const OWNS: Record<FuType, [string, string, string]> = {
    effect: ['The person who gave the dose', 'The time they chose when recording (prefilled 1 hour after)', 'The owner or anyone rostered at the house'],
    reoffer: ['The person who recorded the refusal', 'The time they chose when recording', 'The owner or anyone rostered at the house'],
    confirm: ['The named colleague', '30 minutes after the dose (the PIN rules)', 'Only the named colleague'],
    unconfirmed: ['The house lead', 'The end of the next shift', 'House or clinical leads'],
    partial: ['The house lead', 'The end of the next shift', 'House or clinical leads'],
    disputed: ['The house lead', 'The end of the next shift', 'House or clinical leads'],
    override: ['The house lead', 'The end of the next shift (P07a)', 'House or clinical leads (P07a’s dialog)'],
    countersign: ['A house lead', 'The end of the next day', 'House or clinical leads'],
    handover: ['The house lead', '1 hour into the shift', 'House or clinical leads'],
};

export function ContractPage() {
    const L = (label: string, path: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
            {label}
        </a>
    );
    const M = '/meds/today';
    const S = '/emar/safety';
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p08a/contract' }, { title: 'P08a follow-ups and handover contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P08a decides: one follow-up record for everything a medication record leaves to do later — shown in Meds today, Safety &amp; oversight, All Tasks and the shift handover — with an owner, a due time, carry-over between shifts and a history. Recording a dose stays P01’s dialog; the witness-override count is P07a’s.</p>
            </DesignNote>

            <Section id="types" title="1. Every follow-up type the approved packages create">
                <T head={['Follow-up', 'Created by', 'Owner', 'Due', 'Who closes it']} rows={(Object.keys(TYPE_LABEL) as FuType[]).map((k) => [TYPE_LABEL[k], FROM_PACKAGE[k], OWNS[k][0], OWNS[k][1], OWNS[k][2]])} />
                <p className="text-caption">Everyone rostered at the house and the house lead see every follow-up until it’s done (Stephan, D12). Controlled-medicine follow-ups are left out for roles without controlled-medicine access (EM-12), and list captions count them: “1 controlled follow-up not shown — needs controlled-medicine access” (P02’s rule for cross-person lists).</p>
            </Section>

            <Section id="rules" title="2. Rules (Main’s answers under Stephan’s delegation, 30 September)">
                <T
                    head={['Rule', 'P08a']}
                    rows={[
                        ['Q1 — owner goes off shift', 'It carries over automatically. The next covering shift sees it; the incoming worker becomes the owner when they acknowledge the handover, or a lead assigns it. The original owner stays on the record.'],
                        ['Q2 — couldn’t check', 'Record a reason and a “check again at” time, up to the end of the shift; then it carries over under Q1. It never closes by itself.'],
                        ['Q3 — refusal form', 'Short by default (what happened → review). The fuller step — why, capacity, something else offered, GP and whānau told, what happens next — appears at the 3-in-7-days escalation (the P11 setting) or on a second refusal.'],
                        ['Q4 — closing and reassigning', 'Worker follow-ups: the owner or anyone rostered at the house. Lead follow-ups: house or clinical leads. Reassign: the owner or a lead, to someone rostered. New key medications.followups.manage for team_lead, coordinator, clinical_lead and provider_manager — needs a grant migration.'],
                        ['Q5 — handover acknowledgement', 'Only the incoming assigned worker acknowledges. It never blocks recording and closes no medication work. The house lead gets a heads-up if it’s not acknowledged 1 hour into the shift.'],
                        ['Q6 — effect-check time', 'The worker picks it when recording the dose, prefilled at 1 hour (today’s value). Overdue after that time.'],
                        ['Reminders and escalation', 'P11 v5 Delivery: off — “Not configured” — until a manager switches them on. The scenario “Reminders and escalation switched on” shows every 30 minutes, up to 3 times, then the house lead after 60 minutes, stopping when acknowledged.'],
                        ['Offline', 'Follow-ups have no witness PIN, so they’re saved on the device and sent later (D7), marked “Saved on this device”.'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="3. Today’s dialogs and screens">
                <T
                    head={['Today', 'P08a', 'Try it']}
                    rows={[
                        ['PrnEffectDialog (Meds today, “Did it help?”) and PrnEffectivenessDialog (/emar/prn, 3 steps) — both post /meds/today/prn/effect; the check time is hard-coded to 1 hour, with no overdue state', 'One “Did it help?” dialog: helped / helped a little / didn’t help / couldn’t check (reason + check again at); “someone else needs to know” with who and what was done', L('Tama’s ibuprofen (overdue, carried over)', M, { open: 'effect:fu-ibu' })],
                        ['PrnDetailDialog (/emar/prn, read-only)', 'The as-needed dose with its effect check and owner', L('Aroha’s paracetamol', M, { open: 'prn:fu-para-aroha' })],
                        ['RefusalFollowUpDialog — deleted on 29 Sep with the dead code; the backend (3 routes) has no UI and nothing creates a follow-up', '“Follow up a refusal”, short or full (Q3), on the existing routes', <span key="r">{L('Short — Aroha’s losartan', M, { open: 'refusal:fu-losartan' })} · {L('Full — Grace’s sertraline (3 in 7 days)', M, { open: 'refusal:fu-sertraline' })}</span>],
                        ['Handover “Meds, follow-ups & tasks” step — free-text lines with no owner or due time; “I’ve read this handover”', 'The medication lens: live follow-ups, doses with no outcome, the controlled count (P07a), supply (P06); acknowledging hands over ownership (Q1)', <span key="h">{L('Incoming — night handover', M, { open: 'handover:h-kow-am', scn: 'ackPending' })} · {L('Outgoing — draft', M, { open: 'handover-draft' })}</span>],
                        ['/emar/handovers — PageHero with “Kia ora …”', 'Safety & oversight › Handovers register', L('Handovers', S, { view: 'handovers', as: 'lead' })],
                        ['Nothing lists follow-ups for leads', 'Safety & oversight › Follow-ups', L('Follow-ups', S, { view: 'followups', as: 'lead' })],
                        ['All Tasks has no PRN or refusal rows', 'One task per open follow-up', L('All Tasks', '/tasks')],
                    ]}
                />
            </Section>

            <Section id="states" title="4. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Due', L('Aroha’s paracetamol check (9:50 am)', M, { open: 'fu:fu-para-aroha' })],
                        ['Overdue across midnight and shift change · carried over', L('Tama’s ibuprofen', M, { open: 'fu:fu-ibu' })],
                        ['Owner went off shift → carried over, owner set on acknowledgement', L('Before acknowledgement', M, { scn: 'ackPending' })],
                        ['Couldn’t check (unable to assess) · owner reassigned', L('Mele’s paracetamol', M, { open: 'fu:fu-para-mele' })],
                        ['Escalated with no acknowledgement', L('Reminders on', S, { view: 'followups', as: 'lead', scn: 'delivery' })],
                        ['Completed late', L('Done today', M, { open: 'fu:fu-done-late' })],
                        ['Were you there? (named colleague)', L('As Daniel Ahn', M, { as: 'daniel', open: 'confirm:fu-were' })],
                        ['Lead sign-offs: disputed · partial · countersign', <span key="l">{L('Disputed', S, { as: 'lead', open: 'signoff:fu-disputed' })} · {L('Partial', S, { as: 'lead', open: 'signoff:fu-partial' })} · {L('Countersign', S, { as: 'lead', open: 'countersign:fu-phone' })}</span>],
                        ['Handover not acknowledged — heads-up', L('As Rangi Parata', S, { as: 'pm', open: 'headsup:fu-rimu-handover' })],
                        ['Reassign', L('Mele’s paracetamol', M, { open: 'reassign:fu-para-mele', as: 'lead' })],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', M, { scn: 'loading' })} · {L('Nothing to follow up', M, { scn: 'empty' })} · {L('Couldn’t load', M, { scn: 'unavailable' })} · {L('Out of date', M, { scn: 'stale' })} · {L('Offline', M, { scn: 'offline', open: 'effect:fu-para-aroha' })}</span>],
                        ['Concealment: controlled follow-ups and counts left out, and counted in the captions', L('As Hana Kereama', S, { as: 'clinical' })],
                        ['No access (page) vs not found (record)', <span key="n">{L('No access: Safety & oversight as a support worker', S, { view: 'followups' })} · {L('Not found: a Rimu House follow-up as Priya', M, { open: 'fu:fu-rimu-unconf' })}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
