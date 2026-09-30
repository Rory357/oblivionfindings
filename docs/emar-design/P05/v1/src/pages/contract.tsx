/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as the earlier packages’ contract pages. */
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

export function ContractPage() {
    const L = (label: string, path: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
            {label}
        </a>
    );
    const R = '/emar/reviews';
    const G = '/emar/mar';
    const g = { client_id: '204', tab: 'clinical', view: 'reviews' };
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p05/contract' }, { title: 'P05 medication review contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P05 decides: where medication reviews live, how often they come round, what starts one, who does it and who took part, each medicine’s outcome and the prescriber’s decision before anything reaches Orders, watching after a change, moving and cancelling, and who can do what. Orders and their check stay P04’s; follow-ups are P08a’s; support reassessment is P03’s.</p>
            </DesignNote>

            <Section id="rules" title="1. Rules (Main’s answers under Stephan’s delegation, 30 September)">
                <T
                    head={['Rule', 'P05']}
                    rows={[
                        ['Q1 — pages', 'Orders & reviews › Medication reviews at /emar/reviews, with its own meters. The person record’s Clinical › Medication reviews. Actions & Reviews on the client profile and All Tasks show the same records.'],
                        ['Q2 — how often', 'One per-person interval, 1–12 months. An organisation default in Settings (“Default — not yet reviewed”, today’s 3 months) — an addition to P11, built with P05. Recording a regular review books the next one.'],
                        ['Q3 — what starts one', 'Regular, from the interval, or triggered from a fixed list: back from hospital or respite · a fall or injury · a medication error · a change in health or behaviour · the person, whānau or GP asked · the refusal pattern · other.'],
                        ['Q4 — who does it', 'A GP, pharmacist, nurse practitioner or specialist — recorded by name, role and practice. A staff member owns it and records the outcome. Their written review can be attached (private).'],
                        ['Q5 — who took part', 'The person (took part, or why not), whānau / welfare guardian / EPOA (took part, told afterwards, or why not), and how.'],
                        ['Q6 — outcome → P04', 'Each current order gets an outcome. A change is a recommendation until the prescriber decides. Agreed changes are entered in Orders and checked there; a decision by phone follows P04’s phone rule (written confirmation before the check).'],
                        ['Q7 — watching', '“Watch for something” opens a P08a follow-up for the house lead, shown on the person’s record while it’s open.'],
                        ['Q8 — move and cancel', 'Moving needs a reason; earlier dates are kept. Regular reviews can’t be cancelled, only moved; triggered ones can, with a reason. A person leaving the service closes their open reviews. Being away doesn’t pause the due date.'],
                        ['Q9 — who', 'medications.reviews.manage (team_lead, clinical_lead, coordinator, provider_manager): book, move, cancel, record, the prescriber’s decision. Entering a change stays orders.manage; checking it, orders.verify. The clinician’s summary only for reviews.manage; controlled medicines redacted without controlled view.'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="2. Today’s screens and dialogs">
                <T
                    head={['Today', 'P05', 'Try it']}
                    rows={[
                        ['/emar/reviews — PageHero, quarter chips, KPI cards, deprescribing board, “GP accept %”', 'Orders & reviews › Medication reviews: meters, To do · Changes · Booked · Recorded · Cancelled or closed', <span key="p">{L('Jordan', R, { as: 'lead' })} · {L('Changes', R, { as: 'lead', sub: 'changes' })} · {L('Priya (read only)', R)}</span>],
                        ['ScheduleReviewDialog and MedicationReviewModal (two, disagreeing)', 'Book a review — one wizard', L('Book', R, { as: 'lead', open: 'book' })],
                        ['ConductReviewDialog (free-text drug names; “I confirm…” never sent)', 'Record the outcome — who, who took part, each medicine, summary & letter, next review', <span key="r">{L('Mele’s review', R, { as: 'lead', open: 'record:R-27' })} · {L('Hana, controlled redacted', R, { as: 'clinical', open: 'record:R-31' })}</span>],
                        ['advanceReviewAction (sets “GP accepted” with no prescriber)', 'A change and where it’s at · the prescriber’s decision · enter it in Orders', <span key="c">{L('Clonazepam (waiting)', R, { as: 'lead', sub: 'changes', open: 'change:R-28:o-clonazepam' })} · {L('Decision', R, { as: 'lead', sub: 'changes', open: 'decision:R-28:o-clonazepam' })} · {L('Enter sertraline', R, { as: 'lead', sub: 'changes', open: 'enter:R-28:o-sertraline' })} · {L('Phone rule (Tama)', R, { as: 'lead', sub: 'changes', open: 'change:R-26:o-levetiracetam' })}</span>],
                        ['RescheduleReviewDialog (reason optional; date overwritten)', 'Move the review — reason required, history kept', L('Move Sam’s', R, { as: 'lead', open: 'move:R-24' })],
                        ['destroyReview (no UI)', 'Cancel a triggered review · a regular one can’t be', <span key="x">{L('Cancel Mele’s triggered', R, { as: 'lead', open: 'cancel:R-27' })} · {L('Try a regular one', R, { as: 'lead', open: 'cancel:R-31' })}</span>],
                        ['ReviewDetailDialog', 'The review — viewer with history', L('Grace’s R-28', R, { as: 'lead', open: 'review:R-28' })],
                        ['clients.chart_review_interval_months (no UI)', 'How often — on the person · the organisation default in Settings', <span key="i">{L('Grace', G, { ...g, as: 'lead', open: 'interval:grace' })} · {L('Settings (Hana)', '/emar/settings', { as: 'clinical', view: 'rounds', sec: 'reviews' })}</span>],
                        ['No medication work in Actions & Reviews (NF-05)', 'The real tab with the person’s reviews and changes', L('Grace’s Actions & Reviews', '/clients/profile', { client_id: '204', tab: 'actions_reviews', as: 'lead' })],
                    ]}
                />
            </Section>

            <Section id="index" title="3. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Overdue, away (NF-13)', L('Sam', R, { as: 'lead' })],
                        ['Outcome to record (the pharmacist came this morning)', L('Mele', R, { as: 'lead' })],
                        ['Closed automatically — left the service', L('Wiremu', R, { as: 'lead', sub: 'closed' })],
                        ['The person record, as a support worker (no summary)', L('Grace, Priya', G, g)],
                        ['The person record, as the clinical lead (controlled redacted)', L('Grace, Hana', G, { ...g, as: 'clinical' })],
                        ['Ben’s own 6-month interval (Rimu)', L('Ben, Sione', G, { client_id: '207', tab: 'clinical', view: 'reviews', as: 'rimu' })],
                        ['Not found vs can’t do this', <span key="n">{L('Ben’s review from Kōwhai', R, { as: 'lead', open: 'review:R-22' })} · {L('Priya opens “record”', R, { open: 'record:R-27' })}</span>],
                        ['Settings: read-only for a house lead · the change history', <span key="s">{L('Jordan', '/emar/settings', { as: 'lead', view: 'rounds', sec: 'reviews' })} · {L('All changes', '/emar/settings', { as: 'clinical', view: 'history', sec: 'changes' })}</span>],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', R, { as: 'lead', scn: 'loading' })} · {L('Empty', R, { as: 'lead', scn: 'empty' })} · {L('Couldn’t load', R, { as: 'lead', scn: 'unavailable' })} · {L('Out of date', R, { as: 'lead', scn: 'stale' })} · {L('Offline', R, { as: 'lead', scn: 'offline', open: 'move:R-24' })}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
