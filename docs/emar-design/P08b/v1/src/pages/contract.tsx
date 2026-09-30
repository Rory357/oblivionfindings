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
    const E = '/emar/errors';
    const lead = { as: 'lead' };
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p08b/contract' }, { title: 'P08b medication errors contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P08b decides: where medication errors live and where they’re reported from, two plain questions for reach and harm, the medicine picked from the chart, a neutral summary everywhere outside the report, the stages with an owner and due dates, closing linked incidents through the Incidents module, who can do what, telling the person, duplicates and honest numbers. Doses and “More than ordered” stay P01’s; follow-ups and “Not right” are P08a’s; the discrepancy and loss flows are P07b’s; reports are P09’s.</p>
            </DesignNote>

            <Section id="rules" title="1. Rules (Main’s answers under Stephan’s delegation, 1 October — all (A), with refinements)">
                <T
                    head={['Rule', 'P08b']}
                    rows={[
                        ['Q1 — where', 'Safety & oversight › Medication errors at /emar/errors, with its own meters: To triage · Open with harm · Investigating · Actions due · Incidents to close · Closed. Tier-2 views of the same names, plus Trends. Reported from the dose menu (P01), “Not right” (P08a), the person’s record, Meds today and this page.'],
                        ['Q2 — reach and harm', 'Two plain questions: did it reach the person (no — a near miss / yes / not sure yet), and how much harm (none / minor or temporary / moderate / severe or permanent / death / not known yet). Replaces severity and the NCC-MERP letters. Reports (P09) map harm to the HQSC severity assessment code; the product copy doesn’t claim the scheme.'],
                        ['Q3 — the medicine', 'Picked from the person’s current orders; “It isn’t about one medicine” and “A medicine that isn’t on the chart” are allowed.'],
                        ['Q4 — free text', 'Outside the report only the generated summary is shown: “Medication error — {what went wrong} — {house}”. A controlled error’s medicine and words are shown only with controlled-medicine access. The “you don’t need to name the medicine” prompt runs only for reporters with that access. The CSV follows the same rule.'],
                        ['Q5 — stages', 'Reported → Triage (owner and due date) → Investigating (notes added, never edited) → Actions (owner and due date each) → Closed (close note, never by the reporter). Reopen with a reason. When it happened is recorded. The triage time is an organisation setting — an addition to P11, built with P08b.'],
                        ['Q6 — incidents', 'Made with the report at moderate harm or worse, or from “More than ordered”; carries the summary only. Closing the error closes the incident through the Incidents module’s review → close path and check; without that right, the incident gets the close note and shows “Ready to close — medication error closed”. P07b’s discrepancy and loss incidents are in “Incidents to close” too.'],
                        ['Q7 — who', 'New key medications.errors.manage (team_lead, clinical_lead, coordinator, provider_manager): triage, notes, actions, telling the person, close, reopen. Reporting stays administer.record. Support workers see their own reports. Clinical leads see controlled errors redacted and can’t act on them.'],
                        ['Q8 — telling the person', 'Structured: told (who, how, when) or not yet (why). Required before closing an error that reached the person.'],
                        ['Q9 — duplicates', '“Is this the same as MED-xxxx?” shows only the summary and the time — or “An open report about this person at this dose time” for a controlled match without controlled access. “Add my account” appends; nothing is counted twice.'],
                        ['Q10 — numbers', 'One number each, by NZ date of when it happened; a weekly table; the governance count shows near misses next to errors that reached the person; no reassurance copy; the server pages the list.'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="2. Today’s screens and dialogs">
                <T
                    head={['Today', 'P08b', 'Try it']}
                    rows={[
                        ['/emar/errors — PageHero, “A quiet register is a good sign”, tabs by severity, 300-row cap', 'Safety & oversight › Medication errors: meters and tier-2 views', <span key="p">{L('Jordan', E, lead)} · {L('Rangi (both houses)', E, { as: 'pm' })} · {L('Priya — her reports', E)} · {L('Mereana (read only)', E, { as: 'auditor' })}</span>],
                        ['ReportErrorModal — no medicine picker, free text copied everywhere', 'Report a medication error — one wizard', <span key="r">{L('From the page', E, { ...lead, open: 'report' })} · {L('From the dose (clonazepam — a match)', E, { open: 'report:dose:o-clonazepam' })} · {L('“Not right” (Mele)', E, { ...lead, open: 'report:notright:o-omeprazole' })} · {L('More than ordered (Aroha)', E, { open: 'report:more:o-metformin' })} · {L('From the person’s record (Sam)', E, { ...lead, open: 'report:person:sam' })}</span>],
                        ['Triage and Review dialogs — the status choice is ignored', 'Triage — check the report, owner and due date', L('Triage Grace’s MED-0048', E, { ...lead, open: 'triage:MED-0048' })],
                        ['Detail drawer — no owner, no due date, no history', 'The report — viewer with every section and its history', <span key="v">{L('MED-0045 (actions)', E, { ...lead, open: 'error:MED-0045' })} · {L('its incident', E, { ...lead, open: 'error:MED-0045:incident' })} · {L('history', E, { ...lead, open: 'error:MED-0045:history' })}</span>],
                        ['Resolve → close (no incident change, no audit)', 'Close the error — with its incident, both paths', <span key="c">{L('Jordan closes Sam’s MED-0044', E, { ...lead, sub: 'actions', open: 'close:MED-0044' })} · {L('Rangi closes MED-0044 with no incident', E, { as: 'pm', sub: 'actions', open: 'close:MED-0044' })}</span>],
                        ['Nothing closes an incident (MIS)', 'Incidents to close · Review and close', <span key="i">{L('Rangi’s list', E, { as: 'pm', sub: 'incidents' })} · {L('Review and close INC-2229', E, { as: 'pm', sub: 'incidents', open: 'incclose:INC-2229' })} · {L('Jordan’s list', E, { ...lead, sub: 'incidents' })} · {L('The Incidents queue', '/incidents', { as: 'pm', tab: 'awaiting' })}</span>],
                        ['open_disclosure na / pending / done, with no way to update it', 'Telling the person', <span key="d">{L('Ben (not yet)', E, { as: 'rimu', sub: 'investigating', open: 'disclose:MED-0046' })} · {L('Tama (told)', E, { ...lead, sub: 'investigating', open: 'error:MED-0047:telling' })}</span>],
                        ['No notes, actions, reopen or accounts', 'Add a note · add an action · mark done · reopen · add your account', <span key="n">{L('Note', E, { ...lead, sub: 'investigating', open: 'note:MED-0047' })} · {L('Action', E, { ...lead, sub: 'investigating', open: 'action:MED-0047' })} · {L('Mark A-31 done', E, { ...lead, sub: 'actions', open: 'done:MED-0045:A-31' })} · {L('Reopen MED-0043', E, { ...lead, sub: 'closed', open: 'reopen:MED-0043' })} · {L('Add an account', E, { open: 'account:MED-0045' })}</span>],
                        ['CSV copies every free-text field', 'Export — follows the in-report rule', <span key="x">{L('Jordan', E, { ...lead, open: 'export' })} · {L('Hana (controlled left out)', E, { as: 'clinical', open: 'export' })}</span>],
                        ['Hero “Resolved · 30d” from the 1st of the month; near misses against a target of 0', 'Trends — weekly by when it happened; the governance count', L('Trends', E, { as: 'pm', sub: 'trends' })],
                        ['No triage time anywhere', 'Settings › Alerts & access › Error triage (P11 addition)', <span key="s">{L('Hana', '/emar/settings', { as: 'clinical', view: 'alerts', sec: 'triage' })} · {L('Jordan (read only)', '/emar/settings', { ...lead, view: 'alerts', sec: 'triage' })} · {L('All changes', '/emar/settings', { as: 'clinical', view: 'history', sec: 'changes' })}</span>],
                    ]}
                />
            </Section>

            <Section id="index" title="3. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['A controlled error for the clinical lead — redacted, can’t act', <span key="h">{L('The list', E, { as: 'clinical' })} · {L('MED-0048', E, { as: 'clinical', open: 'error:MED-0048' })} · {L('Try to triage it', E, { as: 'clinical', open: 'triage:MED-0048' })}</span>],
                        ['A controlled duplicate for a reporter without controlled access', L('Hana reports from Grace’s chart', E, { as: 'clinical', open: 'report:person:grace' })],
                        ['The reporter never closes their own report', L('Sione tries to close MED-0046, which he reported', E, { as: 'rimu', open: 'close:MED-0046' })],
                        ['Not ready to close — what’s left', L('Rangi, Ben’s MED-0046', E, { as: 'pm', open: 'close:MED-0046' })],
                        ['Ready to close — P07b’s discrepancy', L('INC-2219', E, { as: 'pm', sub: 'incidents', open: 'incclose:INC-2219' })],
                        ['Not found vs can’t do this', <span key="n">{L('Ben’s report from Kōwhai', E, { ...lead, open: 'error:MED-0046' })} · {L('Priya opens someone else’s', E, { open: 'error:MED-0047' })} · {L('Mereana tries to report', E, { as: 'auditor', open: 'report' })}</span>],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', E, { ...lead, scn: 'loading' })} · {L('Empty', E, { ...lead, scn: 'empty' })} · {L('Empty (Priya)', E, { scn: 'empty' })} · {L('Couldn’t load', E, { ...lead, scn: 'unavailable' })} · {L('Out of date', E, { ...lead, scn: 'stale' })} · {L('Offline', E, { ...lead, scn: 'offline', open: 'note:MED-0047' })}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
