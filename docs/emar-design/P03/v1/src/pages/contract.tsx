/* The contract page — design documentation for reviewers (mockup viewer
 * content, not a product page), laid out as P01 / P07a / P08a’s contract pages. */
import { Card } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ReactNode } from 'react';
import { SUPPORT, SUPPORT_ORDER } from '../data';
import { Shell } from '../shell';
import { hrefFor } from '../store';
import { DesignNote, SupportChip } from '../ui';

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
const FROM: Record<string, string> = {
    selfmanaged: 'P00 v5 dose state “Self-managed”; today’s scope value self_managed; assessment Category 1',
    prompt: 'P00 v5 / P01 v2; today’s scope value prompted; assessment Category 2',
    assist: 'P00 v5 / P01 v2; assessment Category 3 (“supervised”) — no per-medicine value today',
    administer: 'P00 v5 / P01 v2; today’s scope value staff_given; assessment Category 4',
};

export function ContractPage() {
    const L = (label: string, path: string, params: Record<string, string> = {}) => (
        <a className="text-primary underline-offset-2 hover:underline" href={hrefFor(path, params)}>
            {label}
        </a>
    );
    const R = '/emar/self-admin';
    const M = '/emar/mar';
    const rec = (cid: string, extra: Record<string, string> = {}) => ({ client_id: cid, tab: 'support', ...extra });
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Design', href: '/p03/contract' }, { title: 'P03 support and self-administration contract' }]}>
            <DesignNote title="Mockup documentation — not a product page">
                <p>What P03 decides: the support each person gets with each medicine, in the four approved words; the self-administration assessment that sets the most independence allowed; the agreement when someone keeps or takes a medicine themselves; reassessment and the changes a person asks for. Recording a dose stays P01’s dialog — it reads the support set here.</p>
            </DesignNote>

            <Section id="words" title="1. The four support words (Main, 30 September: reuse P00 / P01’s set)">
                <T head={['Support', 'What staff do', 'How the dose is recorded (P00 / P01)', 'Where it comes from']} rows={SUPPORT_ORDER.map((k) => [<SupportChip key={k} support={k} />, SUPPORT[k].desc, SUPPORT[k].recorded, FROM[k]])} />
                <p className="text-caption">A medicine with no support set (a new order, or no assessment) is Administer — staff give it. Self-managed medicines are listed for information and never count as late or missed (P00 v5).</p>
            </Section>

            <Section id="rules" title="2. Rules (Main’s answers under Stephan’s delegation, 30 September)">
                <T
                    head={['Rule', 'P03']}
                    rows={[
                        ['Q1 — the word', '“Self-managed” everywhere for the fourth category. P01 / P02’s chip “Independent” becomes “Self-managed” at build.'],
                        ['Q2 — what sets support', 'Today’s score-based result is kept, shown as the most independence allowed (Category 1 → Self-managed, 2 → Prompt, 3 → Assist, 4 → Administer). Each medicine is set at or below it. The “Category n” label goes.'],
                        ['Q3 — the agreement', 'Needed when any medicine is Self-managed or Prompt. Records who agreed (the person, or a named welfare guardian or EPOA), how (signed form attached, or out loud with a staff witness), the staff member, ordering, and what each side does. Carries over on reassessment.'],
                        ['Q4 — reassessment', 'Every 3, 6 or 12 months (12 by default). Sooner — a follow-up for the house lead, due in 7 days — after a hospital stay, a medication error or incident, a new or changed order for a Self-managed or Prompt medicine, 3 refusals or missed doses in 7 days, the person asking, or a change in health or ability. Until then, support stays as it is.'],
                        ['Q5 — a change the person asks for', 'Asking staff to do more happens straight away (to Administer). Asking to do more themselves waits for a reassessment. Refusing one dose is a refusal, not a change of support.'],
                        ['Q6 — controlled medicines', 'Assist or Administer at most, so they stay in the controlled register and its counts (P07a).'],
                        ['Who', 'Assess, reassess, set support and record agreements: medications.orders.manage (today’s key — house leads, coordinators, clinical leads, managers). Anyone who records doses can record a change the person asks for.'],
                        ['Loosening', 'More independence happens only in a reassessment, whose review marks it “Loosens staff support”. Setting a new medicine above Administer is confirmed the same way (destructive confirm).'],
                        ['Offline', 'Assessments and agreements need a connection; values are kept. A change the person asks for saves on the device and sends later (D7).'],
                    ]}
                />
            </Section>

            <Section id="dialogs" title="3. Today’s dialogs and screens">
                <T
                    head={['Today', 'P03', 'Try it']}
                    rows={[
                        ['/emar/self-admin — PageHero “Self-administration oversight · live”, five tabs (Assessments, Reassessments due, Agreements, Per-medication scope, Activity)', 'MAR & medicines › Support & self-administration: one register grouped by what needs doing, and recent changes', L('The register (Jordan Tipene)', R, { as: 'lead' })],
                        ['AssessmentWizardDialog — five steps, scores out of 25, “Category 1–4”; reassessing drops scope and the agreement', 'Assess or reassess: wishes and who took part → what they can do → support for each medicine (at or below the result) → storage and next review → review and save', <span key="a">{L('Reassess Grace (back from hospital)', M, rec('204', { as: 'lead', open: 'assess:grace' }))} · {L('First assessment — Mele', M, rec('203', { as: 'lead', open: 'assess:mele' }))}</span>],
                        ['SignAgreementDialog — records only the staff member who clicked', 'Record the agreement: who agreed and how, what’s agreed, review', L('Sam — new agreement', M, rec('205', { as: 'lead', view: 'agreement', open: 'agreement:sam' }))],
                        ['MedScopeDialog — three values per medicine, any value for any category', 'Set support for a new medicine (within the cap), or give more staff support at any time, with a reason. More independence needs a reassessment (Q5, P02). Setting a new medicine above Administer is confirmed as “Loosens staff support”.', <span key="s">{L('Tama’s macrogol', M, rec('202', { as: 'lead', open: 'support:macrogol' }))} · {L('Aroha’s new order', M, rec('201', { as: 'lead', open: 'support:omega3' }))} · {L('Mele’s new order', M, rec('203', { as: 'lead', open: 'support:amoxicillin' }))}</span>],
                        ['ViewSelfAdminDialog — raw dates, Reassess / Sign / Set scope', 'The Support plan tab (By medicine · Assessment · Agreement · Changes); earlier assessments open read only', <span key="v">{L('Aroha’s assessment', M, rec('201', { view: 'assessment', as: 'lead' }))} · {L('An earlier one', M, rec('201', { view: 'assessment', as: 'lead', open: 'assessment:as-aroha-2025' }))}</span>],
                        ['No way to record a person’s change of mind', 'Record a change the person asked for', <span key="c">{L('As Priya Shah (support worker)', M, rec('201', { open: 'consent:aroha:metformin' }))}</span>],
                    ]}
                />
            </Section>

            <Section id="states" title="4. States index (deep links)">
                <T
                    head={['State', 'Link']}
                    rows={[
                        ['Mixed support across medicines', L('Sam (Self-managed, Prompt)', M, rec('205', { as: 'lead' }))],
                        ['Plan missing (no assessment)', L('Mele', M, rec('203', { as: 'lead' }))],
                        ['Plan expired (review date passed)', L('Sam', M, rec('205', { as: 'lead' }))],
                        ['Consent changed — asked staff to do more (lowered at once)', L('Aroha', M, rec('201', { as: 'lead' }))],
                        ['Consent changed — asked to do more (waits)', L('Tama', M, rec('202', { as: 'lead' }))],
                        ['Reassessment trigger (back from hospital)', L('Grace', M, rec('204', { as: 'lead' }))],
                        ['Agreement: person signed · verbal with witness · welfare guardian', <span key="g">{L('Sam', M, rec('205', { as: 'lead', view: 'agreement' }))} · {L('Aroha', M, rec('201', { as: 'lead', view: 'agreement' }))} · {L('Grace', M, rec('204', { as: 'lead', view: 'agreement' }))}</span>],
                        ['Controlled medicines concealed (clinical lead)', <span key="h">{L('Register', R, { as: 'clinical' })} · {L('Grace’s support plan', M, rec('204', { as: 'clinical' }))} · {L('Reassess Grace', M, rec('204', { as: 'clinical', open: 'assess:grace' }))}</span>],
                        ['Read only (support worker, auditor)', <span key="r">{L('Priya Shah', M, rec('201'))} · {L('Mereana Walsh', R, { as: 'auditor' })}</span>],
                        ['No access to change vs not found', <span key="n">{L('Support worker opens “assess”', M, rec('201', { open: 'assess:aroha' }))} · {L('Ben from Kōwhai House', M, { client_id: '207', tab: 'support', as: 'lead' })}</span>],
                        ['Loading · empty · couldn’t load · out of date · offline', <span key="p">{L('Loading', R, { as: 'lead', scn: 'loading' })} · {L('No assessments yet', R, { as: 'lead', scn: 'empty' })} · {L('Couldn’t load', R, { as: 'lead', scn: 'unavailable' })} · {L('Out of date', R, { as: 'lead', scn: 'stale' })} · {L('Offline', M, rec('201', { scn: 'offline', open: 'consent:aroha:metformin' }))}</span>],
                    ]}
                />
            </Section>
        </Shell>
    );
}
