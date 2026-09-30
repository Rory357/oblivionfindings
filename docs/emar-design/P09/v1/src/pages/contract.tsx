/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as the earlier packages’ contract
 * pages, with every number’s definition (Main: “every number’s definition on
 * the contract page”). */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { DEFS } from '../model';
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
const REPORT_OF: Record<string, string> = { due: 'Doses', given: 'Doses', refused: 'Doses', withheld: 'Doses', missed: 'Doses', notRecorded: 'Doses', late: 'Doses', roundOnTime: 'Rounds', roundLate: 'Rounds', roundNotCompleted: 'Rounds', roundNotStarted: 'Rounds', prnGiven: 'As needed', prnEffect: 'As needed', cdGiven: 'Controlled medicines', cdWitnessed: 'Controlled medicines', cdCounts: 'Controlled medicines', errReached: 'Medication errors', errHarm: 'Medication errors', errNear: 'Medication errors', revDue: 'Reviews', revDone: 'Reviews', revOverdue: 'Reviews', stockBelow: 'Stock', stockOut: 'Stock', stockExpiry: 'Stock', staffCurrent: 'Competency' };

export function ContractPage() {
    const L = (label: string, path: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
            {label}
        </a>
    );
    const R = '/emar/reports';
    const lead = { as: 'lead' };
    const pm = { as: 'pm' };
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p09/contract' }, { title: 'P09 reports & audit contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P09 decides: one Reports & audit hub; every number worked out once from the dose slots, by NZ date, with “Not applicable” for a zero denominator; who sees and exports what; controlled medicines in numbers; an append-only, chained medication event log; exports with a purpose, recorded; the report builder’s medication domain; the governance counts; SAC ratings; and how long records are kept. The dose-slot projection is built with P01; doses, errors, stock and reviews are P01, P08b, P06 and P05’s.</p>
            </DesignNote>

            <Section id="rules" title="1. Rules (Main’s answers under Stephan’s delegation, 1 October — all (A), with refinements)">
                <T
                    head={['Rule', 'P09']}
                    rows={[
                        ['Q1 — the hub', 'Reports & audit at /emar/reports: Standard reports · Report builder · Audit trail · Print & exports. Retired: the older /reports/medications and /medications/audit pages, the dashboard’s Reports and Audit log dialogs, and the controlled register’s Audit Trail tab. P11’s alert log stays in Settings (linked).'],
                        ['Q2 — one definition per number', 'Every number comes from one dose-slot projection — a P01 build deliverable and a precondition for P09’s build. Reports: Doses · Rounds · As needed · Controlled medicines · Medication errors · Reviews · Stock · Competency. Rows open the person’s MAR for the period.'],
                        ['Q3 — rounds (NF-15)', 'A round’s result is worked out from its doses in NZ time: on time · late · not completed · not started. No “missed” round status is written.'],
                        ['Q4 — time and “not applicable”', 'NZ calendar days: Today · Last 7 days · This month · Last month · Custom (up to 12 months). A zero denominator reads “Not applicable”, with why. “Not recorded” is its own number. File times are labelled NZDT or NZST.'],
                        ['Q5 — who', 'New medications.reports.view (provider_manager, coordinator, clinical_lead, team_lead, auditor), scoped to the reader’s people. reports.viewAny no longer opens eMAR reports. Export: medications.reports.export (provider_manager, coordinator). Finance: Stock only, no people, controlled lines hidden without controlled view. Auditor: everything, read-only; exports the audit trail only.'],
                        ['Q6 — controlled medicines in numbers', 'One number for everyone: totals include controlled doses. The breakdown by medicine, and any row naming one, needs controlled-medicine access. In the builder, controlled sources need it too.'],
                        ['Q7 — the event log', 'Append-only, hash-chained per house, written in the same transaction as the change (the house’s chain head locked last; retry on deadlock). If it can’t be written, the change rolls back with “Couldn’t save — try again”; offline queues retry. The screen and the export are the same list, paged on the server. Unrecorded doses cover the whole period. Retention: a P11 addition, “10 years after the person’s last service” by default; the 2-year clean-up leaves medication events alone.'],
                        ['Q8 — print & exports', 'Each export states its range and what’s in it; identifiable ones ask for a purpose; all are recorded in the audit trail. One CSV guard, shared with the builder. P08b’s in-error rule for the errors file. Without permission the action isn’t offered, and the view says who can export.'],
                        ['Q9 — the builder', 'The medication domain is drawn once in the real workspace; built after the dose-slot projection.'],
                        ['Q10 — governance counts', '“Medication errors that reached the person” with an organisation-set target (“Not configured” until set), and “Near misses reported” with none.'],
                        ['Q11 — SAC', 'A P11 setting, off by default. When on, the person closing an error confirms its SAC, chosen for them from the mapping — death → 1, moderate → 3, minor or no harm → 4; severe or permanent harm is theirs to choose (1 or 2); near misses get none. The errors report and file show the confirmed SAC.'],
                    ]}
                />
            </Section>

            <Section id="numbers" title="2. Every number, and how it’s counted">
                <T head={['Report', 'Number', 'How it’s counted', 'When it reads “Not applicable”']} rows={Object.entries(DEFS).map(([k, d]) => [REPORT_OF[k] ?? '—', d.name, d.counts, d.na ?? '—'])} />
                <p className="text-caption">All by NZ calendar day (Pacific/Auckland). A dose counts once, on the day it was due. Controlled doses are in everyone’s totals.</p>
            </Section>

            <Section id="screens" title="3. Today’s screens and P09">
                <T
                    head={['Today', 'P09', 'Try it']}
                    rows={[
                        ['/emar/reports — PageHero, “Live reporting · refreshed”, UTC dates, compliance 0 % on empty', 'Standard reports, with meters per report', <span key="r">{L('Jordan — Doses', R, lead)} · {L('Rounds, today (not applicable)', R, { ...lead, sub: 'rounds', period: 'today' })} · {L('Rangi — errors', R, { ...pm, sub: 'errors' })} · {L('Hana — controlled (locked)', R, { as: 'clinical', sub: 'controlled' })}</span>],
                        ['DrillDialog (repeats six counters, TODO)', 'The row opens the person’s MAR for the period', L('Doses by person', R, lead)],
                        ['ReportsModal (offers controlled exports to everyone)', 'Print & exports, with the purpose', <span key="x">{L('Rangi — make a MAR', R, { ...pm, view: 'exports', open: 'export:mar:aroha' })} · {L('Jordan — view only', R, { ...lead, view: 'exports' })} · {L('Finance — stock only', R, { as: 'finance', view: 'exports' })} · {L('Auditor — audit trail only', R, { as: 'auditor', view: 'exports' })}</span>],
                        ['AuditLogModal (“Immutable record”, the last 20 doses, ungated)', 'The audit trail: events, unrecorded doses, exports made', <span key="a">{L('Events (Hana)', R, { as: 'clinical', view: 'audit' })} · {L('Page 2', R, { as: 'clinical', view: 'audit', pg: '2' })} · {L('An event', R, { as: 'pm', view: 'audit', open: 'event:E-o-metformin-2026-09-25-08:00' })} · {L('Check the chain', R, { as: 'pm', view: 'audit', open: 'verify' })} · {L('Unrecorded doses', R, { as: 'pm', view: 'audit', sub: 'gaps' })}</span>],
                        ['No medication domain in the builder', 'The real workspace with the medication domain', <span key="b">{L('Rangi', '/emar/reports/builder', pm)} · {L('Hana (no controlled source)', '/emar/reports/builder', { as: 'clinical' })}</span>],
                        ['HCG-001 — near misses against a target of 0', 'Governance counts in Medication errors', L('Rangi', R, { ...pm, sub: 'errors' })],
                        ['No SAC anywhere', 'Settings › Records & reporting; closing with SAC (P08b addition)', <span key="s">{L('Settings (Hana)', '/emar/settings', { as: 'clinical', view: 'rules', sec: 'records' })} · {L('Close MED-0049, severe (Sione)', '/emar/errors', { as: 'rimu', sac: 'on', open: 'close:MED-0049' })} · {L('Close MED-0044, no harm (Rangi)', '/emar/errors', { ...pm, sac: 'on', open: 'close:MED-0044' })}</span>],
                        ['Audit rows pruned after 2 years', 'Keep medication records for — 10 years after the last service, by default', L('Settings (Hana)', '/emar/settings', { as: 'clinical', view: 'rules', sec: 'records' })],
                    ]}
                />
                <p className="text-caption">The viewer-only “sac=on” link parameter shows a close with SAC switched on without saving the setting first.</p>
            </Section>

            <Section id="index" title="4. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Not applicable — a person with only as-needed medicines (Hemi)', L('Sione — Doses', R, { as: 'rimu' })],
                        ['Not applicable — no round has ended yet today', L('Jordan — Rounds, today', R, { ...lead, sub: 'rounds', period: 'today' })],
                        ['Paginated results — the audit trail, 50 a page', L('Hana — events', R, { as: 'clinical', view: 'audit' })],
                        ['Controlled concealment — totals include them; the breakdown is locked; audit rows redacted', <span key="c">{L('Hana — Doses', R, { as: 'clinical' })} · {L('Hana — controlled', R, { as: 'clinical', sub: 'controlled' })} · {L('Hana — controlled events', R, { as: 'clinical', view: 'audit', kind: 'controlled' })}</span>],
                        ['Export permission denied — not offered, and who can', <span key="d">{L('Jordan', R, { ...lead, view: 'exports' })} · {L('Jordan opens a MAR link', R, { ...lead, view: 'exports', open: 'export:mar' })}</span>],
                        ['The purpose prompt', L('Rangi — doses CSV', R, { ...pm, view: 'exports', open: 'export:doses' })],
                        ['The event log can’t be written — “Couldn’t save — try again”', <span key="l">{L('The audit trail', R, { ...pm, view: 'audit', scn: 'logdown' })} · {L('Making an export', R, { ...pm, view: 'exports', scn: 'logdown', open: 'export:stock' })} · {L('Closing with SAC', '/emar/errors', { ...pm, sac: 'on', scn: 'logdown', open: 'close:MED-0044' })}</span>],
                        ['No access — support worker', L('Priya', R)],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', R, { ...lead, scn: 'loading' })} · {L('Empty', R, { ...lead, scn: 'empty' })} · {L('Couldn’t load', R, { ...lead, scn: 'unavailable' })} · {L('Out of date', R, { ...lead, scn: 'stale' })} · {L('Offline', R, { ...pm, view: 'exports', scn: 'offline', open: 'export:stock' })}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
