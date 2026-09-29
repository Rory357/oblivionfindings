/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page). It states what P02 decides, the one place each
 * medication fact is edited, the personas, and deep links to every state. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { Shell } from '../shell';
import { hrefFor } from '../store';
import { DesignNote } from '../p01/ui';

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
const L = (label: string, path: string, params: Record<string, string> = {}) => (
    <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
        {label}
    </a>
);
const R = (label: string, params: Record<string, string> = {}) => L(label, '/emar/mar', { client_id: '201', ...params });

export function ContractPage() {
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p02/contract' }, { title: 'P02 person medication record contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P02 decides: one canonical medication record per person at /emar/mar?client_id=…, the client profile’s MAR tab as its summary and launch point, one place to edit each medication fact, and the states. The approved P00 v5 wording and the P01 v1 recording dialog are reused unchanged.</p>
            </DesignNote>

            <Section id="decides" title="1. What P02 decides">
                <T
                    head={['Decision', 'Detail', 'Grounding (today)']}
                    rows={[
                        ['The record mirrors the Fleet vehicle profile', 'Profile header (two-line identity, Find in this record, Print MAR, one primary “Record dose”, 4–6 linked meters, “As at … Pacific/Auckland”), the six sections on the rail with Find, sub-views on the tier-2 strip.', 'MarCharts.tsx is a PageHero with a “LIVE MEDICATION CHART” eyebrow, tabs below the hero and an eMAR › MAR Charts breadcrumb (AUDIT §2).'],
                        ['Chart', 'P00 dose words (Late, not “Overdue”/“Missed”); Day and Week; due cells open P01’s dialog; one-click “Mark given” only for P01’s simple doses; every cell has a menu (right-click, Shift+F10) and every row a ⋯.', 'mar-grid.tsx labels; two status models disagree (EC:1373 vs MedsBoardPayloadService); recorded cells reopen the wizard.'],
                        ['Medicines', 'Current · Stopped · Photos. Medicine details (MedicationDetailDialog) become a sectioned WizardShell. Orders change only in Orders & reviews.', 'MedicationDetailDialog lives only on /emar/medications; Edit/Discontinue aren’t permission-gated.'],
                        ['Support plan', 'Support per medicine from the self-administration assessment; changed only by reassessment (P03).', 'med_scope JSON on medication_self_admin_assessments; not shown on the profile today.'],
                        ['Allergies & alerts', 'Both of today’s allergy lists read (EM-07), “Not reviewed” state, edits only on the health profile; chart alerts as a list with a “shown when the chart opens” switch and a recorded read; pause dose alerts is a loosening (destructive confirm); interactions are recorded pairs only.', 'ClientAllergyRecordService; ManageAlertsDialog add-only; WarningsDialog acknowledge not stored; interactions leak controlled names (EC:1511-1546).'],
                        ['Clinical', 'INR: every result shown, linked when there’s a warfarin order; overdue test; entered-in-error keeps the row. Syringe driver: contents from orders, checks, finish. Observations taken with doses.', 'NF-23 (GSS:219-221, EC:576-580, MedicationOverviewService:1083); RecordInrDialog sends no medicine; Start driver can never succeed (EC:5230).'],
                        ['History', 'Doses (paginated, house shown after a move), the correction chain with the two-person rule, All changes (audit) with “Show on the chart”.', 'Correction store/approve rules (MedicationAdministrationCorrectionController 50-58, 156-186); no way to raise a correction from the MAR today; MedicationEventDrawer opens /emar/mar without the person.'],
                        ['Client profile › MAR tab', 'Summary and launch point from the same payload: allergies, chart alerts, last dose, due now, active medicines, Record dose (P01), Open medication record.', 'tabs/mar.tsx reads the health profile only and its own queries (ClientController), not the eMAR payload.'],
                    ]}
                />
            </Section>

            <Section id="one-place" title="2. One place to edit each medication fact">
                <T
                    head={['Fact', 'Edited only in', 'Shown in']}
                    rows={[
                        ['A dose outcome', 'P01’s recording dialog (from the chart, Meds today, the profile tab); a correction here', 'Chart, History, profile tab (last dose / due now), Meds today'],
                        ['An order (amount, times, stop)', 'Orders & reviews (P04)', 'Medicines, Chart, profile tab'],
                        ['Support for a medicine', 'Support & self-administration reassessment (P03)', 'Support plan, Chart chips, P01 dialog'],
                        ['Allergies and their review', 'Health profile — client profile › Health & safety › Medical (Stephan, 30 Sep)', 'Record header meter, Allergies & alerts, Chart, profile tab, every recording screen'],
                        ['Chart alerts and the dose-alert pause', 'Allergies & alerts on the record (house and clinical leads)', 'Chart notice, profile tab, alerts to staff'],
                        ['INR results, syringe driver checks', 'Clinical on the record', 'Header meter, Clinical, Overview action list'],
                        ['Pack photos', 'Taken at stock receipt (P06)', 'Medicines › Photos, medicine details, P01 dialog'],
                    ]}
                />
            </Section>

            <Section id="personas" title="3. Who sees what (seeded roles, plan §3)">
                <T
                    head={['Signed in as', 'Sees', 'Try it']}
                    rows={[
                        ['Priya Shah · support worker, Kōwhai', 'Records from the chart (P01 dialog), requests corrections, reads everything else; no Print, no alert or INR changes. Breadcrumb Home › Meds today.', R('Chart')],
                        ['Jordan Tipene · house lead, Kōwhai', 'Everything for Kōwhai House: approve corrections, alerts, pause, INR, review allergies on the health profile.', R('Chart', { as: 'lead' })],
                        ['Hana Kereama · clinical lead, no controlled-medicine access', 'Both houses; controlled medicines are redacted in the chart, lists, captions, history, dialogs and the printout.', R('Chart (concealed)', { as: 'clinical' })],
                        ['Mereana Walsh · auditor (read only)', 'Reads the record and All changes; no recording, editing, printing or approving; controlled medicines redacted.', R('History › All changes', { as: 'auditor', tab: 'history', view: 'changes' })],
                        ['Sione Taufa · house lead, Rimu', 'Ben moved to Rimu House at 8:30 am: his record with the move banner and the house column.', L('Ben’s record', '/emar/mar', { client_id: '207', as: 'rimu', tab: 'history' })],
                        ['Tui Morgan · HR, no medication access', 'No access (the page, 403).', L('No access', '/emar/mar', { client_id: '201', as: 'hr' })],
                    ]}
                />
            </Section>

            <Section id="states" title="4. Every state">
                <T
                    head={['Group', 'States (deep links)']}
                    rows={[
                        ['Chart', <span key="c" className="flex flex-wrap gap-x-3 gap-y-1">{R('Day')}{R('Week', { mode: 'week' })}{R('Earlier day (Sun 27, correction waiting)', { day: '2026-09-27' })}{R('As needed', { view: 'asneeded' })}{R('Record from the chart → P01 dialog', { open: 'record:r16' })}{R('Chart alerts to read', { dlg: 'warnings' })}</span>],
                        ['Medicines', <span key="m" className="flex flex-wrap gap-x-3 gap-y-1">{R('Current', { tab: 'medicines' })}{R('Medicine details', { tab: 'medicines', dlg: 'med:insulin' })}{R('Stopped', { tab: 'medicines', view: 'stopped' })}{R('Pack photos', { tab: 'medicines', view: 'photos' })}</span>],
                        ['Support plan', <span key="s" className="flex flex-wrap gap-x-3 gap-y-1">{R('By medicine', { tab: 'support' })}{R('Assessment', { tab: 'support', view: 'assessment' })}{L('No assessment (Mele)', '/emar/mar', { client_id: '203', tab: 'support', view: 'assessment' })}</span>],
                        ['Allergies', <span key="a" className="flex flex-wrap gap-x-3 gap-y-1">{R('Recorded, reviewed (Aroha)', { tab: 'allergies' })}{L('Recorded, not reviewed (Mele)', '/emar/mar', { client_id: '203', tab: 'allergies' })}{L('None recorded (Tama)', '/emar/mar', { client_id: '202', tab: 'allergies' })}{L('Couldn’t load (Grace)', '/emar/mar', { client_id: '204', tab: 'allergies' })}{L('No known allergies (Sam)', '/emar/mar', { client_id: '205', tab: 'allergies' })}{L('Review on the health profile', '/operations/clients/201', { tab: 'medical', as: 'lead', dlg: 'allergy-review' })}</span>],
                        ['Alerts', <span key="al" className="flex flex-wrap gap-x-3 gap-y-1">{R('Chart alerts', { as: 'lead', tab: 'allergies', view: 'alerts' })}{R('Add a chart alert', { as: 'lead', tab: 'allergies', view: 'alerts', dlg: 'alert:new' })}{R('Pause dose alerts', { as: 'lead', tab: 'allergies', view: 'alerts', dlg: 'pause' })}{R('Stop showing on open (loosens)', { as: 'lead', tab: 'allergies', view: 'alerts', dlg: 'onopen-off:a1' })}{R('Interactions', { tab: 'allergies', view: 'interactions' })}</span>],
                        ['Clinical', <span key="cl" className="flex flex-wrap gap-x-3 gap-y-1">{R('INR', { tab: 'clinical' })}{R('INR test overdue', { tab: 'clinical', state: 'inrStale' })}{R('INR saved without a medicine (NF-23)', { as: 'lead', tab: 'clinical', state: 'inrUnlinked' })}{R('Record INR', { as: 'lead', tab: 'clinical', dlg: 'inr:new' })}{R('Entered in error', { as: 'lead', tab: 'clinical', dlg: 'inr-error:i4' })}{L('Syringe driver (Grace)', '/emar/mar', { client_id: '204', as: 'lead', tab: 'clinical', view: 'driver' })}{L('Record a check', '/emar/mar', { client_id: '204', as: 'lead', tab: 'clinical', view: 'driver', dlg: 'check' })}{L('Start a driver', '/emar/mar', { client_id: '201', as: 'lead', tab: 'clinical', view: 'driver', dlg: 'driver:new' })}{R('Observations', { tab: 'clinical', view: 'observations' })}</span>],
                        ['History', <span key="h" className="flex flex-wrap gap-x-3 gap-y-1">{R('Doses', { tab: 'history' })}{R('Dose with a correction', { tab: 'history', dlg: 'dose:h-2026-09-23-1' })}{R('Request a correction', { tab: 'history', dlg: 'correct:h-2026-09-26-0' })}{R('Corrections', { as: 'lead', tab: 'history', view: 'corrections' })}{R('Review a correction', { as: 'lead', tab: 'history', view: 'corrections', dlg: 'correction:h-2026-09-27-3' })}{R('All changes', { as: 'lead', tab: 'history', view: 'changes' })}{R('A change', { as: 'lead', tab: 'history', view: 'changes', dlg: 'event:e8' })}</span>],
                        ['Page', <span key="p" className="flex flex-wrap gap-x-3 gap-y-1">{R('Loading', { state: 'loading' })}{R('No medicines', { state: 'empty' })}{R('Couldn’t load', { state: 'unavailable' })}{R('Out of date', { state: 'stale' })}{L('No access (page)', '/emar/mar', { client_id: '201', as: 'hr' })}{L('Not found (record)', '/emar/mar', { client_id: '999' })}{L('Moved house — old house sees “not found”', '/emar/mar', { client_id: '207' })}{L('Moved house — new house', '/emar/mar', { client_id: '207', as: 'rimu' })}</span>],
                        ['Client profile', <span key="cp" className="flex flex-wrap gap-x-3 gap-y-1">{L('MAR tab', '/operations/clients/201', { tab: 'mar' })}{L('MAR tab, controlled hidden', '/operations/clients/201', { tab: 'mar', as: 'clinical' })}{L('MAR tab, allergies not recorded', '/operations/clients/202', { tab: 'mar' })}{L('MAR tab, couldn’t load', '/operations/clients/201', { tab: 'mar', state: 'unavailable' })}{L('Medical › allergy card', '/operations/clients/201', { tab: 'medical', as: 'lead' })}</span>],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="5. The eight dialogs">
                <T
                    head={['Today', 'Where it lives today', 'In P02']}
                    rows={[
                        ['MedicationDetailDialog', '/emar/medications only', R('Medicine details (sectioned WizardShell)', { tab: 'medicines', dlg: 'med:metformin' })],
                        ['MedicationEventDrawer', 'Audit log only; “Open on MAR chart” drops the person', R('History › All changes › a change', { as: 'lead', tab: 'history', view: 'changes', dlg: 'event:e5' })],
                        ['ManageAlertsDialog', 'MAR page; add-only; wrong permission', R('Chart alerts list + Add/Edit + pause switch', { as: 'lead', tab: 'allergies', view: 'alerts' })],
                        ['WarningsDialog', 'MAR page; acknowledgement not saved', R('Chart alerts to read', { dlg: 'warnings' })],
                        ['RecordInrDialog', 'MAR page; never sends the medicine (NF-23)', R('Record INR', { as: 'lead', tab: 'clinical', dlg: 'inr:new' })],
                        ['SyringeDriverDialog', 'MAR page; server always refuses it', L('Start a syringe driver', '/emar/mar', { client_id: '201', as: 'lead', tab: 'clinical', view: 'driver', dlg: 'driver:new' })],
                        ['CorrectionsReviewDialog', 'MAR page; no before/after', R('Review a correction', { as: 'lead', tab: 'history', view: 'corrections', dlg: 'correction:h-2026-09-27-3' })],
                        ['InteractionsDialog', '/emar/medications only; no partner or note', R('Interactions', { tab: 'allergies', view: 'interactions' })],
                    ]}
                />
            </Section>

            <Section id="stephan" title="6. Stephan’s answers (30 September) and what’s still open">
                <T
                    head={['Question', 'Answer', 'Applied as']}
                    rows={[
                        ['INR result with no medicine (NF-23)', '“follow industry standard”', 'A test result is never hidden: every INR shows everywhere; Record INR pre-selects the warfarin order; unlinked results are labelled and a lead can link them. Confirm at approval.'],
                        ['Where allergies are edited', 'Health profile only', 'The Medical tab’s allergy card; the medication list merges into it; the record links there.'],
                        ['Who confirms the allergy list', 'Leads confirm it', 'House and clinical leads mark “reviewed” or “No known allergies”, with how they checked; “Not reviewed” until then.'],
                        ['Old house’s access after a move', '“follow industry standard”', 'Access follows the current house (need-to-know): old-house staff get “We can’t show this record”; each dose keeps the house it was given at. Confirm at approval.'],
                    ]}
                />
                <p className="text-caption">Open questions are in the README (Q1–Q7).</p>
            </Section>
        </Shell>
    );
}
