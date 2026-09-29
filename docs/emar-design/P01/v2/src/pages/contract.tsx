/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page). It states the one recording contract, maps
 * every entry point onto it, and indexes deep links to every P01 state. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { AWAY_REASONS, BLOCKS, LATE_REASONS, WITHHELD_REASONS, type BlockKey } from '../contract';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
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
    const s = useStore();
    const L = (label: string, path: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
            {label}
        </a>
    );
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p01/contract' }, { title: 'P01 recording contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P01 decides: one recording contract for every place a dose is recorded. This page lists it, maps each entry point onto it and links to every state. The approved P00 v5 wording is reused unless a row below says otherwise.</p>
            </DesignNote>

            <Section id="contract" title="1. What every recording screen shows and checks">
                <T
                    head={['Step', 'Always shown or asked', 'Source (EM / NF)']}
                    rows={[
                        ['Safety checks', 'Person: private photo (or “No photo on file”), preferred and legal name, house, born, NHI. Medicine: name, strength, amount, route, the staff photo of the supplied pack with its date and pack (“Pack or brand changed — check the label”, “No photo — check the label”, “the picture is a guide only”), instructions, support mode, covert plan, due time and today’s window, order status, witness need, medication-rule readings and second person. Allergy status (recorded / none recorded / couldn’t be loaded / none known) and the specific match line. Every blocked reason with why and the next step.', 'EM-04, EM-07, EM-18, EM-24, EM-25, EM-30, NF-07'],
                        ['Record outcome', 'Outcomes by support mode: Given · Taken with prompting · Took it without a prompt · Taken with assistance · Given after re-offer · Refused · Withheld (reason) · Away (where). Time given with the approved date-time field (exact minute, zone shown, never in the future). A reason when it’s outside today’s window. Amount given: as ordered, chosen within a variable order, less (reason + second person if available), more (only “this already happened”), or the prescriber’s phone instruction. Rule readings. Second person by witness PIN; forgotten-PIN fallback except for controlled drugs. Optional note; follow-up owner and time for a refusal or as-needed dose.', 'EM-06, EM-08, NF-02, NF-06, NF-08, NF-11'],
                        ['Review & sign', 'Everything above, the entry point, and who is signing. Saving shows Sending → Recorded, or Not recorded (values kept), Not confirmed (check the chart), Already recorded (nothing new saved), or Saved on this device (offline).', 'EM-26'],
                    ]}
                />
            </Section>

            <Section id="entry" title="2. Every entry point uses it">
                <T
                    head={['Entry point', 'Today (verified in code)', 'P01', 'Try it']}
                    rows={[
                        ['Meds today › Schedule (also the MAR and the /emar dashboard)', 'RecordDoseWizard, shared by all three: no photo, no instructions/covert/rules, amount fixed to the order, offline silently does nothing', 'The shared dialog', L('Record Tama’s levetiracetam', '/meds/today', { open: 'record:r2' })],
                        ['Meds today › As-needed (also PRN records)', 'PrnWizard: allergy only at review, false success when blocked (fixed in P0)', 'The shared dialog (as-needed)', L('Aroha’s paracetamol', '/meds/today', { view: 'asneeded', open: 'prn:p1' })],
                        ['Guided round', 'Own dialog: one tick for identity, no time, no allergy, window always overridden', 'The round walks the same dialog dose by dose', L('9:00 am round', '/meds/today', { view: 'rounds', round: 'am9' })],
                        ['MAR one-click “Mark given”', 'Posts “given” with no safety display for non-CD, non-witness, non-observation doses', 'Only for simple doses (list below); otherwise “Record dose”', L('Aroha’s chart', '/emar/mar', { client: 'aroha' })],
                        ['Client profile › Record dose', 'emar-dialog (the live profile dialog): its own 9 reasons, no amount, no scheduled time, refuses every offline save', 'Choose the dose, then the shared dialog; emar-dialog retires', L('Aroha’s profile', '/operations/clients/201')],
                        ['Fleet transport › Administer (now “Record”)', 'Given only; no time, reasons, allergies or observations; prescribed dose as given', 'The shared dialog with the transport locked and the pack check kept; the row action says “Record” like every other entry point', L('Transport #12', '/fleet-assets/transports/12')],
                        ['My Day', 'Dormant one-tap routes (administer / refuse / snooze) with no screen (NF-14)', 'Retired. My Day shows counts and links; recording opens the shared dialog', L('My Day', '/my-day')],
                        ['Follow-up re-offer', 'Not possible: a later “given” is a duplicate (NF-11)', '“Record re-offer” linked to the refusal', L('Grace’s sertraline', '/meds/today', { open: 'reoffer:r4' })],
                        ['Mobile API', '/api/medications/* has its own validation', 'Must take the same requirements and outcomes (API contract, no screen — web only, D7)', '—'],
                    ]}
                />
            </Section>

            <Section id="simple" title="3. When the MAR offers one-click “Mark given”">
                <p className="text-sm">Only when every one of these is true; otherwise the menu says why and offers “Record dose”:</p>
                <ul className="list-disc space-y-1 pl-5 text-sm">
                    <li>The dose is due now (inside today’s window) and has no outcome yet.</li>
                    <li>Nothing blocks it (clocked in, on the person’s shift, house in your access, order verified, covert plan current, no allergy-rule block).</li>
                    <li>Your competency is current and not restricted.</li>
                    <li>Not a controlled medicine, no witness, no medication-rule reading or second person.</li>
                    <li>Support mode “Administer”, a fixed amount, not covert.</li>
                    <li>Allergies are recorded (or none known) and there is no match; the pack photo hasn’t changed brand.</li>
                </ul>
                <p className="text-caption">It records the ordered amount at the current time through the same server check; a refusal by the server shows “Not recorded” and asks for the full record.</p>
            </Section>

            <Section id="reasons" title="4. One reason list (the existing NotGivenReason values, in plain words)">
                <T head={['Outcome', 'Reasons offered']} rows={[['Withheld', WITHHELD_REASONS.map((r) => r.label).join(' · ')], ['Away', AWAY_REASONS.map((r) => r.label).join(' · ')], ['Outside the window', LATE_REASONS.join(' · ')]]} />
                <p className="text-caption">Self-administered is no longer a “not given” reason: a self-managed medicine never shows as due (EM-04). “Safety concern — not safe to give” maps to the existing “withheld” value with the note (question Q5).</p>
            </Section>

            <Section id="blocks" title="5. Blocked reasons (NF-07) — each says why and the next step">
                <T
                    head={['Reason', 'What can still be recorded', 'See it']}
                    rows={(
                        [
                            ['notClockedIn', 'Nothing', { scn: 'notClockedIn', open: 'why:r16' }],
                            ['notOnShift', 'Nothing', { scn: 'notOnShift', open: 'why:r20' }],
                            ['siteNotApproved', 'Nothing', { scn: 'siteNotApproved', open: 'why:r21' }],
                            ['competencyExpired', 'Refusal, withhold, absence', { scn: 'competencyExpired', open: 'why:r16' }],
                            ['restrictedBlock', 'Refusal, withhold, absence', { scn: 'restrictedBlock', open: 'why:r16' }],
                            ['awaitingVerification', 'Withheld', { open: 'why:r7' }],
                            ['covertMissing', 'Withheld', { scn: 'covertMissing', open: 'why:r13' }],
                            ['noWitness', 'Withheld; ask a manager for an override', { scn: 'cdNoWitness', open: 'why:r11' }],
                            ['allergyNotConfirmed', 'Withheld (always)', { allergy: 'confirm', open: 'why:r3' }],
                        ] as [BlockKey, string, Record<string, string>][]
                    ).map(([k, still, p]) => [BLOCKS[k].title.replace('{p}', 'Hine').replace('{med}', 'the medicine'), still, L('Open', '/meds/today', p)])}
                />
            </Section>

            <Section id="states" title="6. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Sending → Recorded (success pane, next due)', L('Losartan for Aroha', '/meds/today', { open: 'record:r16' })],
                        ['Not recorded — values kept', L('Server refuses the next save', '/meds/today', { scn: 'reject', open: 'record:r16' })],
                        ['Not confirmed', L('Save not confirmed', '/meds/today', { scn: 'uncertain', open: 'record:r16' })],
                        ['Already recorded (duplicate)', L('Daniel recorded it first', '/meds/today', { scn: 'duplicate', open: 'record:r2' })],
                        ['Saved on this device (offline)', L('Offline', '/meds/today', { scn: 'offline', open: 'record:r16' })],
                        ['Offline item refused when sent', L('App-wide banner', '/meds/today', { scn: 'offlineRefused' })],
                        ['Order changed mid-round', L('Morning round', '/meds/today', { scn: 'orderChanged', view: 'rounds', round: 'am8' })],
                        ['My Day doesn’t show someone → pointer to Meds today', L('My Day', '/my-day', { scn: 'myDayHidden' })],
                        ['Controlled dose — witness PIN', L('Grace’s clonazepam', '/meds/today', { open: 'record:r11' })],
                        ['No eligible witness → request → manager approves on one screen', L('Ask a manager', '/meds/today', { scn: 'cdNoWitness', open: 'why:r11' })],
                        ['Manager’s one-screen approval', L('As Rangi Parata', '/tasks', { scn: 'cdNoWitness', as: 'pm' })],
                        ['Medication rules: reading + second person', L('Aroha’s insulin', '/meds/today', { open: 'record:r12' })],
                        ['Less than ordered, nobody to confirm', L('Alone on shift', '/meds/today', { scn: 'alone', open: 'record:r16' })],
                        ['Rule needs a second person, nobody on shift → recorded “Not confirmed by a second person”', L('Aroha’s insulin, alone on shift', '/meds/today', { scn: 'alone', open: 'record:r12' })],
                        ['Forgotten PIN → “I was there / I wasn’t there”', L('As Daniel Ahn (after Priya names him)', '/my-day', { as: 'daniel' })],
                        ['Allergy match — warn (current), critical surface', L('Mele’s amoxicillin', '/meds/today', { open: 'record:r3' })],
                        ['Refusal while a block is active (NF-06)', L('Mele’s omeprazole', '/meds/today', { open: 'notgiven:r7' })],
                        ['Prompt support + brand changed', L('Aroha’s vitamin D', '/meds/today', { open: 'record:r6' })],
                        ['Covert plan shown', L('Grace’s levothyroxine', '/meds/today', { open: 'record:r13' })],
                        ['As-needed limit reached', L('Mele’s paracetamol', '/meds/today', { view: 'asneeded', open: 'prn:p4' })],
                        ['Loading · empty · couldn’t load · out of date', <span key="q">{L('Loading', '/meds/today', { scn: 'loading' })} · {L('No work left', '/meds/today', { scn: 'empty' })} · {L('Couldn’t load', '/meds/today', { scn: 'unavailable' })} · {L('Out of date', '/meds/today', { scn: 'stale' })}</span>],
                        ['No access (page) vs not found (record)', <span key="n">{L('No access: Orders & reviews as a support worker', '/emar/prescriptions')} · {L('Not found: Ben’s chart', '/emar/mar', { client: 'ben' })}</span>],
                    ]}
                />
            </Section>
            <p className="text-caption">Signed in as {s.route.persona === 'sw' ? 'Priya Shah (support worker)' : s.route.persona} · switch in the viewer bar.</p>
        </Shell>
    );
}
