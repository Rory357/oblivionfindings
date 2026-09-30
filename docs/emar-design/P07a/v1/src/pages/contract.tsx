/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as P01 v1's contract page. It states
 * what P07a decides, maps the three existing dialogs, and deep-links every
 * state. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { CADENCE, PIN } from '../model';
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
    const L = (label: string, params: Record<string, string> = {}, path = '/meds/today') => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, { view: 'controlled', ...params })}>
            {label}
        </a>
    );
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p07a/contract' }, { title: 'P07a controlled checks contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P07a decides: the frontline Controlled checks view in Meds today — the shift-change count, witnessing, starting a discrepancy, witness requests, the frontline side of witness overrides and the house lead’s follow-up. Recording a dose stays P01’s approved dialog; resolving a discrepancy and the controlled register are P07b.</p>
            </DesignNote>

            <Section id="decides" title="1. What P07a decides">
                <T
                    head={['Rule', 'P07a', 'Source']}
                    rows={[
                        ['Who sees Controlled checks', 'People who can record or witness controlled medicines, for their approved houses only. Without controlled-medicine view: no tab, no rows, no counts, no search results, no bell items — no trace.', 'Plan §2.1; P00 v5 concealment; EM-12'],
                        ['How often', `${CADENCE.label}. Due 30 minutes before the change, overdue ${CADENCE.overdueAfterMin} minutes after it. Shift changes come from each house’s roster pattern (Kōwhai House: 7:00 am, 3:00 pm, 11:00 pm). Until an organisation sets it: “Not configured” — nothing shows as due or overdue (P00 v5 wording).`, `${CADENCE.decided}; P00 v5`],
                        ['Who counts', 'Anyone clocked in at the house with controlled-medicine record access. At a shift change the outgoing and incoming staff count together — one counts, the other witnesses. A count is never recorded without a witness.', 'Today: record permission only (no presence check) — AUDIT.md'],
                        ['Who can witness', 'A different person, clocked in at the house now, with the controlled drugs area passed and “can witness” ticked, not restricted, with a witness PIN set and not locked. The forgotten-PIN fallback is never allowed for controlled drugs.', 'P00 v5; P11 v5 answers 10–11; Stephan D8'],
                        ['A count that doesn’t match', 'Counted again first. If the second count matches, nothing is reported and the first count is kept with the record. If it still differs, a discrepancy starts automatically when saved, owned by the house lead, who is told straight away. “What you found” and “What you did straight away” are required. The register then shows what was counted, as today.', 'Stephan, 30 Sep 2026'],
                        ['Controlled doses while a discrepancy is open', 'Not blocked — doses are recorded as they happen; the row and the dose dialog say the medicine has an open discrepancy.', 'Stephan: never block recording what happened'],
                        ['Asking someone to witness', 'An in-app request to one eligible colleague on shift (only eligible people can be asked). They answer “On my way” or “Can’t come now” with a reason. The PIN is still typed on the recorder’s screen at the cupboard. A shift-change count closes its request.', 'Stephan, 30 Sep 2026'],
                        ['Witness override (frontline side)', 'P01 v1’s request and the manager’s one-screen approval, reused unchanged in fields and wording. The dose row shows waiting / approved until … / declined with the reason. Counts are never covered by an override.', 'P00 v5; P01 v1'],
                        ['House-lead follow-up', 'Every dose recorded under an override: a witnessed count of those medicines by the end of the next shift, then a sign-off of each dose. A shift-change count the lead takes part in covers the count step. Visible to everyone rostered and the house lead until signed off.', 'Stephan, 30 Sep 2026; D12'],
                        ['Movements', 'Frontline staff record only medicines going out with the person and coming back, with a count of what’s left and a witness. Doses, counts, deliveries, returns to the pharmacy and destructions are recorded elsewhere (below).', 'P07a'],
                        ['Offline', 'Counts and movements need a connection — a witness PIN is never saved on the device. Entered values are kept.', 'Today’s rule, kept (D7)'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="2. The three existing dialogs">
                <T
                    head={['Today', 'P07a', 'Try it']}
                    rows={[
                        ['BalanceCheckDialog — one medicine, “Expected (register)” editable, “Witness password or PIN”, mismatch raises a discrepancy and an incident immediately', 'The count: one WizardShell for the whole shift-change count or one medicine; register shown, not editable; count again before reporting; witness picker with reasons; PIN-1 wording', L('Start the 3:00 pm count', { open: 'count:all' })],
                        ['RecordCdEntryDialog — six movement types (receipt, administration, disposal, transfer in/out, adjustment), before/after typed by hand', 'Record a movement: going out with the person / coming back only; the register works out what should be left and you count it. Administration → the dose record (P01). Receipt → the house lead (P06). Disposal, adjustment → the controlled register (P07b).', L('Record a movement', { open: 'move' })],
                        ['ResolveDiscrepancyDialog — closes a discrepancy, no witness, says it’s “logged against the linked incident” (it isn’t)', 'Starting only, from a count. The frontline sees what was started (read-only). Resolution stays in the controlled register until P07b.', L('Discrepancy started at 7:04 am', { scn: 'discrepancyOpen', open: 'disc:disc-14' })],
                    ]}
                />
            </Section>

            <Section id="pin" title="3. Witness PIN wording (P01 v2, approved)">
                <T
                    head={['When', 'Wording']}
                    rows={[
                        ['Field', `${PIN.label} — “${PIN.help}”`],
                        ['Empty', PIN.blank],
                        ['Wrong PIN', PIN.incorrect('Jordan Tipene')],
                        ['Locked', PIN.locked('Jordan Tipene', '3:03 pm')],
                        ['No PIN set or locked (picker)', 'Mere Kahu — … no witness PIN set — can’t be chosen · Leilani Faleolo — … PIN locked — can’t be chosen'],
                        ['Forgotten PIN', PIN.forgottenNotAllowed],
                    ]}
                />
                <p className="text-caption">P01 v2’s approved wording, verbatim. The review session (30 Sep 2026): an approved design outranks PIN-1’s built server text, and the build aligns PIN-1’s messages to this wording.</p>
            </Section>

            <Section id="settings" title="4. Setting added to Medication › Settings (P11 Controlled drugs view)">
                <T
                    head={['Group · row', 'Control', 'Value']}
                    rows={[
                        ['Counts · How often controlled medicines are counted', 'Segmented choice (three real options): Every shift change · Once a day, at the morning shift change · Once a week', 'Every shift change (Stephan, 30 Sep 2026). Until saved: “Not configured”.'],
                        ['Counts · Counts as overdue', 'Number, minutes after the shift change', '60'],
                        ['Alerts · Controlled-drug balance check overdue', 'The existing P11 alert row; its sub-line follows this setting', '“A shift-change count is overdue (1 hour after the change)” — house lead, in-app, controlled-medicine access only'],
                    ]}
                />
                <p className="text-caption">Built into P11’s Controlled drugs view as a new titled group following its SettingGroup / GroupRow pattern (Stephan: “add settings in their own packages”). Loosening it (e.g. to once a week) is flagged “Loosens this check” with a destructive save, as P11 does. Not drawn here: P11 is frozen.</p>
            </Section>

            <Section id="states" title="5. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Shift-change count due (support worker)', L('Priya Shah', {})],
                        ['Count — all three medicines', L('Start the 3:00 pm count', { open: 'count:all' })],
                        ['Count — one medicine', L('Methylphenidate only', { open: 'count:cd1' })],
                        ['Witness picker: same person, not eligible (area, restricted, no access), no PIN, PIN locked', L('Open the count, then Continue', { open: 'count:all' })],
                        ['Count doesn’t match → count again → discrepancy starts', L('Open the count (enter 26 for methylphenidate, then 26 again)', { open: 'count:cd1' })],
                        ['Wrong PIN / locked PIN', L('Open the count (PIN 000000 / 999999)', { open: 'count:cd1' })],
                        ['The register changed during the count', L('A dose is recorded during the count', { scn: 'balanceChanged', open: 'count:all' })],
                        ['Count overdue', L('The 7:00 am count was missed', { scn: 'countOverdue' })],
                        ['Count cadence not configured', L('Not configured', { scn: 'cadenceNotSet' })],
                        ['Discrepancy open', L('Started at 7:04 am', { scn: 'discrepancyOpen' })],
                        ['Nobody on shift can witness', L('Why can’t I count?', { scn: 'noWitness', open: 'cantcount' })],
                        ['Witness override: request → waiting → manager approves or declines', <span key="o">{L('Ask a manager', { scn: 'noWitness', open: 'ovr-request:d8' })} · {L('As Rangi Parata (after the request)', { scn: 'noWitness', as: 'pm' })}</span>],
                        ['Asking someone to witness · their answer', <span key="a">{L('Ask', { open: 'ask:count' })} · {L('As Jordan Tipene', { as: 'lead', open: 'answer:ask-1' })}</span>],
                        ['House-lead follow-up: witnessed count, then sign off', L('As Jordan Tipene', { as: 'lead', open: 'followup' })],
                        ['Can’t witness yet (area not passed, no PIN)', L('As Mere Kahu', { as: 'mere' })],
                        ['Not clocked in', L('Not clocked in', { scn: 'notClockedIn' })],
                        ['Record a movement', L('Going out with the person', { open: 'move:cd1' })],
                        ['Loading · no controlled medicines · couldn’t load · out of date · offline', <span key="p">{L('Loading', { scn: 'loading' })} · {L('None at the house', { scn: 'noCd' })} · {L('Couldn’t load', { scn: 'unavailable' })} · {L('Out of date', { scn: 'stale' })} · {L('Offline', { scn: 'offline', open: 'count:cd1' })}</span>],
                        ['Concealment: no tab, no rows, no counts, no search result', <span key="c">{L('Tomasi Vea’s Schedule', { as: 'tomasi', view: 'schedule' })} · {L('Tomasi opens Controlled checks', { as: 'tomasi' })}</span>],
                        ['No access (page) vs not found (record)', <span key="n">{L('No access: Tomasi, Controlled checks', { as: 'tomasi' })} · {L('Not found: Tomasi opens a discrepancy link', { as: 'tomasi', open: 'disc:disc-14', scn: 'discrepancyOpen' })}</span>],
                        ['Manager’s view of two houses (Rimu House count overdue)', L('As Rangi Parata', { as: 'pm' })],
                    ]}
                />
            </Section>
        </Shell>
    );
}
