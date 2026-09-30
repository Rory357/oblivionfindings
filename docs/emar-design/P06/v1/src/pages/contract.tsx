/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as P01 / P07a / P08a / P03 / P04’s contract pages. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { STATE_BADGE, type StockState } from '../model';
import { Shell } from '../shell';
import { hrefFor } from '../store';
import { DesignNote, StockBadge } from '../ui';

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
const MEANS: Record<StockState, string> = {
    out: 'Nothing usable left, and no delivery due.',
    expired: 'An open pack has expired. It isn’t given and isn’t counted as stock — take it out of use.',
    low: 'Under the days-of-supply threshold for regular doses, or at the reorder level for as-needed ones.',
    countDiff: 'A count found a difference; the house lead signs it off.',
    useFirst: 'Two or more packs, and the one to use next expires within 7 days.',
    expiring: 'A pack expires within 30 days.',
    arriving: 'The pharmacy has dispensed it; it’s due to be received.',
    none: 'On the chart, but nothing received or ordered yet.',
    ok: 'In stock, nothing to do.',
    selfManaged: 'The person manages it (P03) — not counted, and doses don’t come off stock.',
};

export function ContractPage() {
    const L = (label: string, path: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
            {label}
        </a>
    );
    const S = '/emar/stock';
    const M = '/meds/today';
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p06/contract' }, { title: 'P06 stock and pharmacy contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P06 decides: stock held in packs (lots), each with its batch, expiry, source and photo; doses taken from the pack that expires first; one supply record per pharmacy order; blind counts; reasoned movements; stock alerts; and the controlled receipt. Controlled counts, the register and destruction stay in P07.</p>
            </DesignNote>

            <Section id="states" title="1. Stock states">
                <T head={['State', 'What it means', 'Badge']} rows={(Object.keys(STATE_BADGE) as StockState[]).map((k) => [STATE_BADGE[k].label, MEANS[k], <StockBadge key={k} state={k} />])} />
            </Section>

            <Section id="rules" title="2. Rules (Main’s answers under Stephan’s delegation, 30 September)">
                <T
                    head={['Rule', 'P06']}
                    rows={[
                        ['EM-10 — lots', 'Stock is held in packs (a lots table: batch, expiry, quantity, source per pack). No delivery ever overwrites another pack’s batch or expiry. The migration is part of the P06 build.'],
                        ['Q1 — pages', 'The Stock & controlled drugs hub (P02 hub pattern) for leads and managers, and Meds today › Stock alerts for frontline staff.'],
                        ['Q2 — a pack', 'Created at each receipt. Batch and expiry are required, with an explicit “Not printed on the pack”. Expired stock can’t be received; within 7 days of expiry needs a reason. On hand is the sum of open, unexpired packs.'],
                        ['Q3 — doses use stock', 'Each dose comes off the pack that expires first, automatically. P01 says “Use the pack expiring first” when packs differ. Self-managed doses don’t come off stock.'],
                        ['Q4 — pharmacy orders', 'One supply record: draft → sent → dispensed (today’s DispenseDialog moves here) → received, part received, closed short or cancelled (with reasons). A received order is read only.'],
                        ['Q5 — who', 'medications.stock.receive (new, with a grant migration) for staff who give medicines: receive, photograph, count, going out and coming back. medications.stock.update for house leads and managers: orders, adjustments and removals, count sign-off. Finance’s stock.update is flagged for review.'],
                        ['Q6 — pack photo', 'Prompted, not required, per pack: when there’s no photo yet or the pack looks different. Stored privately; controlled medicines’ photos are concealed; history kept; the latest shows in the dose dialog.'],
                        ['Q7 — counts and movements', 'Blind counts; every difference has a reason and the house lead signs it off. Every movement is a record with a reason. Ordinary removals don’t need controlled permissions. Scheduled counts are an organisation setting, off by default.'],
                        ['Q8 — alerts', 'Days of supply for regular doses (7 days), the reorder level for as-needed ones; expiry at 30 and 7 days — all “Default — not yet reviewed” (P11). Alerts follow P11 Delivery and All Tasks › Stock. Discontinued medicines never alert. Day boundaries in NZ time.'],
                        ['Q9 — controlled receipt', 'A house lead receives a controlled delivery as a register entry: witness PIN, balance before and after, the pack. Everything else controlled stays in P07. The controlled batch/expiry edit closes.'],
                        ['Q10 — going out and coming back', 'Ordinary medicines going out with the person and coming back, as P07a’s movement without a witness.'],
                        ['Offline', 'Receiving, counts, movements and orders need a connection; what’s typed is kept. Keep deliveries aside, unopened, until received.'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="3. Today’s dialogs and screens">
                <T
                    head={['Today', 'P06', 'Try it']}
                    rows={[
                        ['/emar/stock — PageHero “Medication stock for {site}”, six tabs, one row per medicine with one batch and expiry', 'Stock & controlled drugs: meters, the rail, packs per medicine, recent movements', L('The hub (Jordan Tipene)', S, { as: 'lead' })],
                        ['No frontline stock view (P01 / P07a left Stock alerts link-only)', 'Meds today › Stock alerts: deliveries to receive, packs to use first, running low, expired, out with people', L('As Priya Shah', M, { view: 'stockalerts' })],
                        ['ReceiveStockDialog · “receive against order” · the controlled delivery', 'Receive a delivery: what arrived → packs → pack photo → (register entry) → review', <span key="r">{L('Cefalexin (PO-1040)', S, { as: 'lead', view: 'orders', open: 'receive:PO-1040' })} · {L('Brought in', S, { as: 'lead', open: 'receive:new' })} · {L('Controlled (PO-1036)', S, { as: 'lead', view: 'orders', open: 'receive:PO-1036' })}</span>],
                        ['CreatePharmacyOrderDialog · the forward-only “advance” button', 'Order from the pharmacy; the order record with its lifecycle', <span key="o">{L('New order', S, { as: 'lead', view: 'orders', open: 'order:new' })} · {L('PO-1039 (part received)', S, { as: 'lead', view: 'orders', open: 'po:PO-1039' })} · {L('PO-1042 (draft)', S, { as: 'lead', view: 'orders', open: 'po:PO-1042' })}</span>],
                        ['P04’s DispenseDialog (moved here, Q4)', 'What the pharmacy dispensed', L('PO-1041', S, { as: 'lead', view: 'orders', open: 'dispensed:PO-1041' })],
                        ['No cancel, partial or short state', 'Cancel the order · Close it short (reasons kept)', <span key="c">{L('Cancel PO-1041', S, { as: 'lead', view: 'orders', open: 'cancel:PO-1041' })} · {L('Close PO-1039 short', S, { as: 'lead', view: 'orders', open: 'short:PO-1039' })}</span>],
                        ['StockCountDialog (absolute adjust, “Physical stock count”) · scheduled count completion', 'Count stock — blind · Sign off a difference', <span key="n">{L('Count the house', S, { as: 'sw', view: 'counts', open: 'count:house' })} · {L('Sign off Grace’s sertraline', S, { as: 'lead', view: 'counts', open: 'signoff:cnt-12' })}</span>],
                        ['AdjustStockDialog (free-text reason, overwritten) · ordinary wastage via controlled destruction', 'Adjust or remove — reasons, pack, destructive confirm', <span key="a">{L('Remove Grace’s expired levothyroxine', S, { as: 'lead', view: 'expiring', open: 'adjust:st-levothyroxine:lot-lt0712' })} · {L('Adjust Mele’s paracetamol', S, { as: 'lead', open: 'adjust:st-paracetamol-mele' })}</span>],
                        ['No ordinary going out / coming back', 'Going out or coming back', <span key="m">{L('Tama’s levetiracetam coming back', M, { view: 'stockalerts', open: 'move:st-levetiracetam:back:mv-1' })} · {L('Going out', S, { as: 'lead', open: 'move:st-metformin' })}</span>],
                        ['A stock photo column nobody uses', 'Pack photos on the stock item', L('Levetiracetam › Pack photos', S, { as: 'lead', open: 'item:st-levetiracetam' })],
                    ]}
                />
            </Section>

            <Section id="index" title="4. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Needs attention · running low · self-managed', <span key="f">{L('Needs attention', S, { as: 'lead', show: 'attention' })} · {L('Running low', S, { as: 'lead', show: 'low' })} · {L('Self-managed', S, { as: 'lead', show: 'self' })}</span>],
                        ['Expiring · removals', <span key="e">{L('Expiring', S, { as: 'lead', view: 'expiring' })} · {L('Removals', S, { as: 'lead', view: 'removals' })}</span>],
                        ['Two houses (manager)', L('Rangi Parata', S, { as: 'pm' })],
                        ['Controlled medicines concealed (clinical lead)', <span key="h">{L('Stock', S, { as: 'clinical' })} · {L('Grace’s clonazepam by link', S, { as: 'clinical', open: 'item:st-clonazepam' })}</span>],
                        ['Read only (clinical lead, auditor)', <span key="r">{L('Hana Kereama', S, { as: 'clinical', view: 'orders' })} · {L('Mereana Walsh', S, { as: 'auditor' })}</span>],
                        ['No access to do this vs not found', <span key="n">{L('Support worker opens “order”', S, { open: 'order:new' })} · {L('Ben from Kōwhai House', S, { as: 'lead', open: 'item:st-amlodipine' })}</span>],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', S, { as: 'lead', scn: 'loading' })} · {L('No stock yet', S, { as: 'lead', scn: 'empty' })} · {L('Couldn’t load', S, { as: 'lead', scn: 'unavailable' })} · {L('Out of date', S, { as: 'lead', scn: 'stale' })} · {L('Offline', S, { as: 'lead', scn: 'offline', open: 'adjust:st-paracetamol-mele' })}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
