import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { exposureFactText } from '@/lib/health-safety/chemical-exposure-form';
import type { ExposureRecordEnvelope } from '@/lib/health-safety/chemical-exposure-read-contract';
import { exposureHref } from '@/lib/health-safety/chemical-exposure-navigation';
import { chemicalHref } from '@/lib/health-safety/chemical-navigation';
import { incidentWorkspaceHref } from '@/lib/incidents/navigation';
import { LockKeyhole } from 'lucide-react';
import type { ReactNode } from 'react';

const text = (value: string | number | null) => value === null || value === '' ? 'Not recorded' : String(value);
function Facts({ items }: { items: [string, ReactNode][] }) {
    return <dl className="grid gap-4 sm:grid-cols-2">{items.map(([label, value]) => <div key={label} className="min-w-0 space-y-1">
        <dt className="text-caption text-muted-foreground">{label}</dt><dd className="whitespace-pre-wrap break-words text-subtle">{value}</dd>
    </div>)}</dl>;
}
/** Shown only after the private workspace's current source projection; no local read grant. */
export function ExposureOverview({ detail, onCorrect, onReview }: { detail: ExposureRecordEnvelope; onCorrect?: () => void; onReview?: () => void }) {
    const record = detail.record;
    if (detail.exposure_access !== 'readable' || detail.source_integrity === 'repair_required' || !record || record.id !== detail.id || record.attribution_state === 'repair_required') {
        return <p role="status" className="text-subtle text-status-warning">Saved exposure details cannot currently be confirmed. Details and changes are withheld.</p>;
    }
    return <div className="space-y-5"><div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-section-title">Recorded exposure</h2>
        {detail.can.correct && detail.command_context?.root_type === 'exposure' && detail.command_context.root_id === record.id
            && Number.isSafeInteger(detail.command_context.expected_version) && detail.command_context.expected_version >= 0 && onCorrect &&
            <Button type="button" variant="outline" className="frontline-tap" onClick={onCorrect}>Correct recorded facts</Button>}
    </div>
        <StatusBadge variant={record.attribution_state === 'legacy_unknown' ? 'warning' : 'neutral'}>
            {record.attribution_state === 'legacy_unknown' ? 'Earlier provenance unconfirmed' : 'Recorded source'}
        </StatusBadge>
        <p className="text-subtle">This records what was reported. A submitted report does not confirm recovery, medical review or delivery to another team.</p>
        {record.response?.care_review_needed === true && record.response.harm_assessment === 'unassessed' && <div role="status" className="space-y-3">
            <StatusBadge variant="warning">Care answers need review</StatusBadge>
            <p className="text-subtle">The reported care answers are unknown or need clarification. A recorded review is separate from confirming the facts or a medical outcome.</p>
            {detail.can.review && detail.command_context?.root_type === 'exposure' && detail.command_context.root_id === record.id
                && Number.isSafeInteger(detail.command_context.expected_version) && detail.command_context.expected_version >= 0 && onReview &&
                <Button type="button" variant="outline" className="frontline-tap" onClick={onReview}>Record a review</Button>}
        </div>}
        <Facts items={[
            ['Worker', record.worker.name], ['Site', record.site.name], ['Chemical', record.substance.name],
            ['Exposure time', formatDateTime(record.exposed_at, 'Not recorded')], ['Exposure route', text(record.exposure_type?.replaceAll('_', ' ') ?? null)],
            ['Recorded duration', text(record.exposure_duration)], ['Circumstances', text(record.circumstances)], ['Reported symptoms', text(record.symptoms)],
            ['First aid given', text(record.first_aid_given)], ['Medical attention sought', exposureFactText(record, 'medical_attention_sought')],
            ['Recorded treatment', exposureFactText(record, 'medical_treatment')], ['Medical outcome', text(record.medical_outcome)],
            ['Incident reported', exposureFactText(record, 'incident_reported')], ['Recorded by', record.recorded_by?.name ?? 'Not recorded'],
            ['Recorded at', formatDateTime(record.created_at, 'Not recorded')], ['Last updated by', record.updated_by?.name ?? 'Not recorded'],
            ['Last recorded update', formatDateTime(record.updated_at, 'Not recorded')],
        ]} />
    </div>;
}
export function ExposureConnections({ detail }: { detail: ExposureRecordEnvelope }) {
    const record = detail.record;
    if (detail.exposure_access !== 'readable' || detail.source_integrity === 'repair_required' || !record || record.id !== detail.id || record.attribution_state === 'repair_required') {
        return <p role="status" className="text-subtle text-status-warning">Connected records cannot currently be confirmed.</p>;
    }
    const connection = record.incident_connection;
    const href = connection.state === 'recorded' && Number.isSafeInteger(connection.id) && Number(connection.id) > 0
        && connection.href === '/incidents/' + connection.id ? connection.href : null;
    return <div className="space-y-5"><h2 className="text-section-title">Connected records</h2>
        <div className="space-y-3 rounded-lg border border-border bg-card p-4"><h3 className="text-section-title">Chemical safety reference</h3>
            <p className="text-subtle">Open its precautions, safety data sheets and recorded storage. The chemical workspace checks its own current source access.</p>
            {Number.isSafeInteger(record.substance.id) && record.substance.id > 0 && record.substance.id <= 2147483647 &&
                <Button asChild variant="outline" className="frontline-tap"><a href={chemicalHref(record.substance.id, { returnTo: exposureHref(record.id, { section: 'connections' }) })}>Open chemical reference</a></Button>}
        </div>
        <div className="space-y-3 rounded-lg border border-border bg-card p-4">
            <h3 className="text-section-title">Related incident</h3>
            {href ? <Button asChild variant="outline" className="frontline-tap"><a href={incidentWorkspaceHref(connection.id!, exposureHref(record.id, { section: 'connections' }))}>Open related incident</a></Button> :
                <p className="text-subtle">{connection.state === 'absent' ? 'No related incident was retained on this source.' : 'The original incident association is unconfirmed. Its details are withheld.'}</p>}
        </div>
        <div className="space-y-3 rounded-lg border border-border bg-card p-4"><LockKeyhole aria-hidden className="size-5 text-muted-foreground" />
            <h3 className="text-section-title">Health & Safety and follow-up delivery</h3>
            <p role="status" className="text-subtle">Recorded source acceptance and reported delivery attempts do not confirm that another team received the record. Check current destination proof separately.</p>
        </div>
    </div>;
}
