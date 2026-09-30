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
    const C = '/emar/controlled';
    const S = '/emar/safety';
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p07b/contract' }, { title: 'P07b controlled register contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P07b decides: the append-only controlled register and its voids; register changes only from named, witnessed reasons; resolving discrepancies; losses; one destruction path; the Witness overrides view; witness checks; the NZ class; and who can do what. Counts, witness requests and controlled going out / coming back stay in P07a; the controlled receipt is P06’s.</p>
            </DesignNote>

            <Section id="rules" title="1. Rules (Main’s answers under Stephan’s delegation, 30 September)">
                <T
                    head={['Rule', 'P07b']}
                    rows={[
                        ['Q1 — pages', 'The controlled register at /emar/controlled (Register · Discrepancies · Losses · Destructions), reached from Stock & controlled drugs › Controlled. Witness overrides in Safety & oversight. /emar/destructions is controlled-only and redirects here.'],
                        ['Q2 — append-only', 'No entry is edited or deleted. A wrong one is voided with a reason and a witness PIN, stays struck through, and a correcting entry follows.'],
                        ['Q3 — no free adjustment', 'Every change outside doses, receipts and movements comes from a named reason — a discrepancy’s resolution, a loss, a breakage or spillage, a void — witnessed and linked.'],
                        ['Q4 — resolving a discrepancy', 'Recount matched · recording error (void and correct) · stock found · unexplained loss (a real loss report) · escalate to a manager (under review). Never by someone who counted or witnessed the count. A note goes on the linked incident; Medication errors (P08b) closes the incident. The misleading “blocked” and “logged against the incident” texts go.'],
                        ['Q5 — losses', 'A witnessed register entry, plus the incident, plus an append-only investigation; police and Medicines Control notifications recorded, also when told later (Main confirmed 30 Sep); a theft can’t close until the police are recorded; a manager closes it.'],
                        ['Q6 — destruction', 'One path. Return to the pharmacy by default (standard NZ practice), with a witness, and the pharmacist’s name and registration on receipt. On-site denaturing only where the organisation allows it, with two witnesses. Fixed reasons and methods, an optional photo, both witnesses on the entry. Voiding reverses the entry.'],
                        ['Q7 — witness overrides', 'Request → decision → doses given → the house lead’s witnessed count and sign-off (P07a Q4), with an overdue flag. controlled.override is only the key for granting overrides.'],
                        ['Q8 — witnesses', 'A restricted competency blocks witnessing. The recorder must be at the house. PIN-1’s lock applies everywhere. Declared relationships (where HR records one) are a build note.'],
                        ['Q9 — NZ class', 'Class A / B / C (Misuse of Drugs Act 1975) replaces “Schedule 2/3/4”. Existing values are flagged for a lead to review, not mapped automatically.'],
                        ['Q10 — who', 'A new key, medications.controlled.manage (grant migration: house leads and provider managers) — resolve, void, reasoned changes, destruction sign-off. Clinical leads hold no controlled keys (Main, 30 Sep). Managers close losses and grant overrides. Frontline staff record, witness and report losses. Support workers lose resolve and void.'],
                        ['Also decided', 'P07a: one count cadence (every shift change), a discrepancy starts from a count and doses are never blocked. P06 Q9: the controlled receipt is P06’s witnessed register entry.'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="2. Today’s screens and dialogs">
                <T
                    head={['Today', 'P07b', 'Try it']}
                    rows={[
                        ['/emar/controlled — PageHero, seven tabs (Audit Trail repeats Recent Entries)', 'The controlled register: meters, the rail, medicines with their class and balance, recent entries (voids struck through)', L('Jordan Tipene', C, { as: 'lead' })],
                        ['No void; the policy allows updates', 'Void an entry, with a correcting entry and a witness PIN', <span key="v">{L('Void Priya’s dose', C, { as: 'lead', open: 'void:en-mph-5' })} · {L('A voided entry', C, { as: 'lead', open: 'med:cd-mph' })}</span>],
                        ['A reason-less “adjustment” and a register “disposal”', 'Breakage or spillage (witnessed) — and no free adjustment', L('Breakage — Aroha’s methylphenidate', C, { as: 'sw', open: 'breakage:cd-mph' })],
                        ['ResolveDiscrepancyDialog (“logged against the linked incident”, “Loss report raised” as a label)', 'Resolve a discrepancy: outcome → details → witness → review', <span key="r">{L('Resolve D-14', C, { as: 'lead', view: 'discrepancies', open: 'resolve:D-14' })} · {L('Priya counted it', C, { view: 'discrepancies', open: 'resolve:D-14' })}</span>],
                        ['Report loss (JSON, no page; notes overwritten)', 'Report a loss · the loss and its investigation · add to it · close (manager)', <span key="l">{L('Report', C, { as: 'sw', view: 'losses', open: 'loss:new' })} · {L('L-7', C, { as: 'lead', view: 'losses', open: 'loss:L-7' })} · {L('Close L-7', C, { as: 'pm', view: 'losses', open: 'lossclose:L-7' })}</span>],
                        ['DestructionDialog (free-text authoriser; one witness on the entry) · void (reverses nothing)', 'Return for destruction · the pharmacist’s receipt · void (reverses the entry)', <span key="d">{L('Return', C, { as: 'lead', view: 'destructions', open: 'destroy:new' })} · {L('Receipt for DS-21', C, { as: 'lead', view: 'destructions', open: 'receipt:DS-21' })} · {L('Void DS-21', C, { as: 'lead', view: 'destructions', open: 'voiddest:DS-21' })}</span>],
                        ['No list of witness overrides', 'Safety & oversight › Witness overrides', <span key="o">{L('The view', S, { as: 'pm', view: 'overrides' })} · {L('OV-7 (overdue)', S, { as: 'lead', view: 'overrides', open: 'override:OV-7' })}</span>],
                        ['“Schedule 2/3/4”', 'Set the NZ class', L('Tama’s midazolam', C, { as: 'lead', open: 'class:cd-mdz' })],
                    ]}
                />
            </Section>

            <Section id="index" title="3. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Class to review', L('Show', C, { as: 'lead', show: 'class' })],
                        ['Two houses (manager)', L('Rangi Parata', C, { as: 'pm' })],
                        ['No controlled view (clinical lead, no controlled keys)', L('Hana Kereama', C, { as: 'clinical' })],
                        ['Read only (auditor with controlled view)', L('Mereana Walsh', C, { as: 'auditor', view: 'discrepancies' })],
                        ['Witness picker: restricted competency, locked PIN, yourself', L('Breakage as Jordan', C, { as: 'lead', open: 'breakage:cd-czp' })],
                        ['/emar/destructions redirects', L('Open it', '/emar/destructions', { as: 'lead' })],
                        ['Not found vs can’t do this', <span key="n">{L('Ben’s oxycodone from Kōwhai', C, { as: 'lead', open: 'med:cd-oxy' })} · {L('Support worker opens “void”', C, { open: 'void:en-mph-5' })}</span>],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', C, { as: 'lead', scn: 'loading' })} · {L('Empty', C, { as: 'lead', scn: 'empty' })} · {L('Couldn’t load', C, { as: 'lead', scn: 'unavailable' })} · {L('Out of date', C, { as: 'lead', scn: 'stale' })} · {L('Offline', C, { as: 'lead', scn: 'offline', open: 'breakage:cd-czp' })}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
