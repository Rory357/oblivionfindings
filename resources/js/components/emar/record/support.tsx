import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { MedicationSupportPanel } from '@/pages/emar/support/support-panel';
import { CHECKS, SCORES, SUPPORT, type SupportMode, type SupportPlan } from '@/pages/emar/support/types';
import { Link } from '@inertiajs/react';
import { ClipboardList, History, Users } from 'lucide-react';
import { ReadingState } from './reading';
import { isConcealed, type MaybeConcealed } from './types';
import { concealedIdentity, recordDate, SectionCard } from './ui';
import { useRecordJson } from './use-record-json';

type Change = { key: string; medicine: string; mode: SupportMode; previous: SupportMode | null; at: string; by: string | null; reason: string; notes: string | null };
export function CanonicalSupportSection({ clientId, view }: { clientId: number; view: string }) {
    const { data, load, reload } = useRecordJson<{ plan: SupportPlan; changes: MaybeConcealed<Change>[] }>(`/emar/clients/${clientId}/record/support`);
    if (!data || load !== 'ready') return <ReadingState load={load} reload={reload} />;
    const { plan } = data;
    const assessment = plan.assessment;
    const link = <Button variant="outline" asChild><Link href={plan.url}>Open Support & self-administration</Link></Button>;
    return <div className="space-y-5">
        <div className="flex justify-end">{link}</div>
        {view === 'bymedicine' ? <MedicationSupportPanel plan={plan} /> : view === 'assessment' ? <SectionCard title="Current support assessment" icon={ClipboardList}>
            {!assessment ? <p className="text-sm">No assessment is recorded. Open Support & self-administration to record an assessment and any agreed changes.</p> : <div className="space-y-4">
                <ReviewRow label="Assessed" value={recordDate(assessment.assessment_date)} /><ReviewRow label="Reassess by" value={recordDate(assessment.reassessment_date)} />
                <ReviewRow label="Person’s wish" value={assessment.wishes_to_self_administer ? 'Wishes to manage medicines' : 'Staff support requested'} />
                <ReviewRow label="Maximum independence" value={SUPPORT[plan.cap].label} />
                {SCORES.map((score) => <ReviewRow key={score.key} label={score.label} value={assessment[score.key] == null ? 'Not recorded' : `${assessment[score.key]} of 5`} />)}
                {CHECKS.map((check) => <ReviewRow key={check.key} label={check.label} value={assessment[check.key] == null ? 'Not recorded' : assessment[check.key] ? 'Recorded yes' : 'Recorded no'} />)}
                <ReviewRow label="People involved" value={assessment.people_involved?.join(' · ') || 'Not recorded'} />
                <ReviewRow label="Storage" value={[assessment.storage_location, assessment.safe_storage_notes].filter(Boolean).join(' · ') || 'Not recorded'} />
                <ReviewRow label="Assessment notes" value={assessment.assessor_notes ?? 'Not recorded'} />
            </div>}
        </SectionCard> : view === 'agreement' ? <SectionCard title="Current agreement" icon={Users}>
            {!plan.agreement ? <p className="text-sm">No current agreement is recorded. Self-managed and Prompt choices wait for the agreement in the support workflow.</p> : <div className="space-y-4">
                <ReviewRow label="Agreed with" value={`${plan.agreement.agreed_by_name} · ${plan.agreement.agreed_by_role}`} />
                <ReviewRow label="Method" value={plan.agreement.method === 'signed' ? 'Signed attachment' : 'Verbal agreement with an independent witness'} />
                <ReviewRow label="Recorded" value={formatDateTime(plan.agreement.created_at)} />
                <ReviewRow label="Ordering medicines" value={plan.agreement.ordering_responsibility} /><ReviewRow label="Person’s responsibilities" value={plan.agreement.person_responsibilities} /><ReviewRow label="Staff responsibilities" value={plan.agreement.staff_responsibilities} /><ReviewRow label="Storage" value={plan.agreement.storage_notes ?? 'Not recorded'} />
                {plan.agreement.attachment_url && <Button variant="outline" asChild><a href={plan.agreement.attachment_url}>Open signed agreement</a></Button>}
            </div>}
        </SectionCard> : <SectionCard title="Recorded support changes" icon={History}>
            <p className="text-caption text-muted-foreground">Latest 100 changes · newest first · controlled medicines are redacted within this person’s record.</p>
            {data.changes.length ? <EntityTable rows={data.changes} rowKey={(row) => row.key} identityLabel="Medicine" identity={(row) => isConcealed(row) ? concealedIdentity : { icon: Users, name: row.medicine, subline: formatDateTime(row.at) }} minWidth={850}
                columns={[
                    { key: 'mode', label: 'Support', width: '1fr', cell: (row) => isConcealed(row) ? '—' : <StatusBadge variant="neutral">{SUPPORT[row.mode].label}</StatusBadge> },
                    { key: 'previous', label: 'Was', width: '1fr', cell: (row) => isConcealed(row) ? '—' : row.previous ? SUPPORT[row.previous].label : 'Not set yet' },
                    { key: 'reason', label: 'Reason', width: '2fr', cell: (row) => isConcealed(row) ? '—' : [row.reason, row.notes].filter(Boolean).join(' · ') },
                    { key: 'by', label: 'Recorded by', width: '1fr', cell: (row) => isConcealed(row) ? '—' : row.by ?? 'Not recorded' },
                ]} actionsFor={() => []} /> : <p className="text-sm">No support changes are recorded.</p>}
        </SectionCard>}
    </div>;
}
