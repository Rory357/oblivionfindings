/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as P01 / P07a / P08a / P03’s contract pages. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { STATE_BADGE, type OrderState } from '../model';
import { Shell } from '../shell';
import { hrefFor } from '../store';
import { DesignNote, OrderBadge } from '../ui';

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
const MEANS: Record<OrderState, string> = {
    allergy: 'A new or changed version matches a recorded allergy. It can’t be checked until a lead records that the prescriber confirmed it’s safe (who, when, how, what they said).',
    waiting: 'A new or changed version waits for someone other than the person who entered it. A new order can’t be given; a change leaves the last checked version in use.',
    sentBack: 'The checker sent the version back with a reason. Staff keep giving the last checked version.',
    written: 'A phone or verbal order was read back with a witness and checked. The prescriber’s written confirmation is due by the end of the next day.',
    lone: 'Checked alone because nobody else could, with a reason. Another lead’s second check is due by the end of the next day (a P08a follow-up).',
    ending: 'The order ends within 14 days (P11 v5 Q7) — ask the prescriber whether it continues.',
    active: 'The latest version is checked. Staff give from it.',
    stopped: 'Stopped, with who stopped it and why. Kept, never deleted.',
};

export function ContractPage() {
    const L = (label: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor('/emar/prescriptions', params)}>
            {label}
        </a>
    );
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p04/contract' }, { title: 'P04 orders, changes and reconciliation contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P04 decides: one order per medicine with kept versions; the independent check of every new or changed version; phone and verbal orders with a witnessed read-back and the prescriber’s written confirmation; the prescriber’s confirmation when an order matches an allergy; structured covert authorisations; and medicines reconciliation. Recording a dose stays P01’s dialog — it gives from the latest checked version.</p>
            </DesignNote>

            <Section id="states" title="1. Order states">
                <T head={['State', 'What it means', 'Badge']} rows={(Object.keys(STATE_BADGE) as OrderState[]).map((k) => [STATE_BADGE[k].label, MEANS[k], <OrderBadge key={k} state={k} />])} />
            </Section>

            <Section id="rules" title="2. Rules (Main’s answers under Stephan’s delegation, 30 September)">
                <T
                    head={['Rule', 'P04']}
                    rows={[
                        ['Q1 — one order', 'The chart entry is the order. Written, phone and verbal orders create it or make a new version; stopping ends it. Every version is kept. The separate prescriber-order list goes.'],
                        ['Q2 — the check', 'Someone with medications.orders.verify who didn’t enter it (and didn’t witness the read-back) checks every new or changed version before it can be given. If nobody else can, a lead checks it alone with a reason — a P08a second-check follow-up is due by the end of the next day. Saving with no change doesn’t undo a check.'],
                        ['Q3 — phone and verbal', 'Read back to the prescriber, with a colleague witnessing (their witness PIN, not a password). A lead attaches the prescriber’s written confirmation by the end of the next day (P00 setting). This replaces the internal “countersign on the prescriber’s authority”.'],
                        ['Q4 — allergy', 'A match with the health profile’s allergies blocks the check until “The prescriber confirmed it’s safe” (who, when, how, what they said) is on that version. Shown to staff at dose time. Unknown allergies are never “none”.'],
                        ['Q5 — reconciliation', 'Moving in, back from hospital, respite arriving or leaving, house move: sources → match each medicine (continue, change, stop, start, ask the GP) → changes become versions to check → a lead signs off. Due before the next affected dose (P08a follow-up). Flags the person’s support for reassessment (P03).'],
                        ['Q6 — covert', 'Per medicine: capacity assessment, guardian or EPOA consulted, the pharmacist’s advice (required), the GP’s signed authorisation, the method and a review date (3 months by default). A follow-up 14 days before; overdue blocks covert giving. Stopping it keeps the reason.'],
                        ['Q7 — the hub', 'Orders & reviews (P02 hub pattern): Orders · To check · Covert · Reconciliation · Medication reviews (link-only, P05).'],
                        ['Q8 — dispensing', 'The pharmacy’s dispensing record moves to Stock & pharmacy (P06). The order shows supply read only.'],
                        ['Also decided', 'P00 v5: a dose-only phone instruction is recorded in P01 and countersigned in P08a’s dialog (shown in To check). Main, 30 September: it also needs the prescriber’s written confirmation — at build, P08a’s Countersign gains “Attach the prescriber’s written confirmation”, and the item shows “Waiting for the prescriber’s written confirmation” until then (an approved change to P08a). P11 v5 Q7: warn 14 days before an end date. P03: a new order is Administer until its support is set.'],
                        ['Who', 'Enter orders, changes, stops, confirmations, covert and reconciliation: medications.orders.manage. Check: medications.orders.verify. Controlled medicines stay hidden without controlled-medicine access (P02 rule), counted in captions.'],
                        ['Offline', 'Orders, checks, confirmations and reconciliations need a connection; what’s typed is kept. Staff keep giving the last checked version.'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="3. Today’s dialogs and screens">
                <T
                    head={['Today', 'P04', 'Try it']}
                    rows={[
                        ['/emar/prescriptions — PageHero “Prescriptions & orders · live”, five tabs; orders are a paper trail, separate from the chart entry that allows a dose', 'Orders & reviews: header meters, the rail, one list of orders with state lines, and recent changes', L('Orders (Jordan Tipene)', { as: 'lead' })],
                        ['NewOrderDialog (4 steps, witness types their password) · AddMedicationDialog · EditMedicationDialog', 'Enter an order, or a change: where it came from → the medicine → read it back (phone/verbal) → review. Allergy match shown at entry.', <span key="n">{L('Enter an order', { as: 'lead', open: 'new' })} · {L('Change Tama’s levetiracetam', { as: 'lead', open: 'change:o-levetiracetam' })}</span>],
                        ['VerifyOrderDialog (“awaiting pharmacy sign-off”) · RejectOrderDialog · confirm with no step', 'Check a version: now vs to check, three ticks, or send it back; alone with a reason if nobody else can', <span key="c">{L('Hana checks Mele’s omeprazole', { as: 'clinical', view: 'check', open: 'check:o-omeprazole' })} · {L('Jordan entered it', { as: 'lead', view: 'check', open: 'check:o-omeprazole' })} · {L('Second check — Ben', { as: 'pm', view: 'check', open: 'check:o-paracetamol-ben' })}</span>],
                        ['CountersignDialog (“I confirm this order as the prescriber (or on their authority)”)', 'Attach the prescriber’s written confirmation', L('Tama’s loratadine', { as: 'lead', view: 'check', open: 'written:o-loratadine' })],
                        ['No prescriber confirmation for an allergy match; the order dialog always says “No recorded allergies” (AUDIT 3.2)', 'The prescriber confirmed it’s safe', <span key="a">{L('Enter amoxicillin for Aroha (penicillin)', { as: 'lead', open: 'new' })} · {L('Mele’s amoxicillin (confirmed)', { as: 'lead', open: 'order:o-amoxicillin' })}</span>],
                        ['OrderDetailDialog (read only, no actions)', 'The order: this order · versions · checks and confirmations · supply (P06, read only)', <span key="o">{L('Sam’s melatonin (sent back)', { as: 'lead', open: 'order:o-melatonin' })} · {L('Tama’s loratadine', { as: 'lead', open: 'order:o-loratadine' })}</span>],
                        ['DiscontinueDialog · CancelOrderDialog (reason kept in the audit log only)', 'Stop an order (who stopped it, why), with a destructive confirm', L('Stop Mele’s amoxicillin', { as: 'lead', open: 'stop:o-amoxicillin' })],
                        ['CovertDialog (answers merged into one text; legal basis fixed) · RevokeCovertDialog', 'Review or authorise covert giving; stop it with a kept reason', <span key="v">{L('Review Grace’s levothyroxine', { as: 'lead', view: 'covert', open: 'covert:o-levothyroxine' })} · {L('Overdue', { as: 'lead', view: 'covert', scn: 'covertOverdue' })} · {L('Stop covert giving', { as: 'lead', view: 'covert', open: 'revoke:o-levothyroxine' })}</span>],
                        ['Respite’s reconciliation modal (counts and free text, always “completed”, not linked to the chart)', 'Reconcile medicines', <span key="r">{L('Continue Hine’s (respite)', { as: 'lead', view: 'reconcile', open: 'reconcile:rec-hine' })} · {L('Start one', { as: 'lead', view: 'reconcile', open: 'reconcile:new' })} · {L('Grace — signed off', { as: 'lead', view: 'reconcile', open: 'reconcile:rec-grace' })}</span>],
                        ['P01’s dose-only phone instruction — P08a v1’s approved “Countersign a phone instruction”', 'Unchanged, opened from To check (a house or clinical lead, by the end of the next day)', L('Aroha’s insulin instruction', { as: 'lead', view: 'check', open: 'countersign:phone' })],
                        ['DispenseDialog · the Dispensing tab', 'Moves to Stock & pharmacy (P06); shown read only on the order', L('Aroha’s metformin › Supply', { as: 'lead', open: 'order:o-metformin' })],
                    ]}
                />
            </Section>

            <Section id="index" title="4. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['To check — all five kinds, and the phone instruction', L('To check (Jordan Tipene)', { as: 'lead', view: 'check' })],
                        ['Needs attention · ending in 14 days · stopped', <span key="f">{L('Needs attention', { as: 'lead', show: 'attention' })} · {L('Ending', { as: 'lead', show: 'ending' })} · {L('Stopped', { as: 'lead', show: 'stopped' })}</span>],
                        ['Two houses (manager)', L('Rangi Parata', { as: 'pm' })],
                        ['Controlled medicines concealed (clinical lead)', <span key="h">{L('Orders', { as: 'clinical' })} · {L('Grace’s clonazepam by link', { as: 'clinical', open: 'order:o-clonazepam' })}</span>],
                        ['Covert review due soon · overdue', <span key="c">{L('Due in 11 days', { as: 'lead', view: 'covert', scn: 'covertDue' })} · {L('Overdue', { as: 'lead', view: 'covert', scn: 'covertOverdue' })}</span>],
                        ['Read only (support worker, auditor)', <span key="r">{L('Priya Shah', { as: 'sw' })} · {L('Mereana Walsh', { as: 'auditor' })}</span>],
                        ['No access to do this vs not found', <span key="n">{L('Support worker opens “check”', { as: 'sw', open: 'check:o-omeprazole' })} · {L('Ben from Kōwhai House', { as: 'lead', open: 'order:o-amlodipine' })}</span>],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', { as: 'lead', scn: 'loading' })} · {L('No orders yet', { as: 'lead', scn: 'empty' })} · {L('Couldn’t load', { as: 'lead', scn: 'unavailable' })} · {L('Out of date', { as: 'lead', scn: 'stale' })} · {L('Offline', { as: 'lead', scn: 'offline', open: 'stop:o-amoxicillin' })}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
