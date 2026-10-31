/**
 * Private exposure — what was reported, the care answers and the connections (approved H&S drawings pg-hsn-129,
 * 129f — Stephan 23 Oct 2026). What was reported reads as a story; the care answers are a ring in the side column
 * (unknown answers ringed amber); the linked chemical and incident are cards; delivery runs as a line.
 *
 * Shown only after the private workspace's current source projection; no local read grant. Every privacy, recovery,
 * delivery and access line keeps the code's wording, and the reported care answers keep their exact states
 * (`exposureFactText`) — an unknown answer never reads as a no.
 */
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import { FactRows, Journey, Note, PeopleCards, RingCard, SideBySide, Story, Surface, type CheckItem, type PersonCardItem } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import { exposureFactText } from '@/lib/health-safety/chemical-exposure-form';
import type { ExposureRecord, ExposureRecordEnvelope, ExposureReportedFact } from '@/lib/health-safety/chemical-exposure-read-contract';
import { exposureHref } from '@/lib/health-safety/chemical-exposure-navigation';
import { chemicalHref } from '@/lib/health-safety/chemical-navigation';
import { incidentWorkspaceHref } from '@/lib/incidents/navigation';
import { Clock, Hand, Info, MapPin, Timer, TriangleAlert } from 'lucide-react';

const text = (value: string | number | null) => value === null || value === '' ? 'Not recorded' : String(value);
const pre = (value: string | null) => <span className="whitespace-pre-wrap break-words">{text(value)}</span>;
const sentence = (value: string) => { const words = value.replaceAll('_', ' '); return words.charAt(0).toUpperCase() + words.slice(1); };
function admittedRecord(detail: ExposureRecordEnvelope): ExposureRecord | null {
    const record = detail.record;
    return detail.exposure_access !== 'readable' || detail.source_integrity === 'repair_required' || !record || record.id !== detail.id || record.attribution_state === 'repair_required' ? null : record;
}

/** What was reported (pg-hsn-129): the care-review note when the server raises it, the story, then everything else on record. */
export function ExposureOverview({ detail }: { detail: ExposureRecordEnvelope }) {
    const record = admittedRecord(detail);
    if (!record) return <div role="status"><Note tone="warning">Saved exposure details cannot currently be confirmed. Details and changes are withheld.</Note></div>;
    const when = record.exposed_at && toDateInput(record.exposed_at) ? <><b>{formatDateOnly(toDateInput(record.exposed_at))}</b>, {formatTime(record.exposed_at)}</> : 'Exposure time not recorded';
    return <>
        {record.attribution_state === 'legacy_unknown' && <div role="status"><Note tone="warning">Earlier provenance unconfirmed</Note></div>}
        {record.response?.care_review_needed === true && record.response.harm_assessment === 'unassessed' && <div role="status"><Note tone="warning" icon={TriangleAlert}>
            <b>Care answers need review</b> — The reported care answers are unknown or need clarification. A recorded review is separate from confirming the facts or a medical outcome.</Note></div>}
        <Story title="What was reported" sub="This records what was reported. A submitted report does not confirm recovery, medical review or delivery to another team."
            facts={[{ icon: Clock, text: when }, { icon: MapPin, text: record.site.name },
                { icon: Hand, text: record.exposure_type ? sentence(record.exposure_type) : 'Exposure route not recorded' },
                { icon: Timer, text: record.exposure_duration === null || record.exposure_duration === '' ? 'Duration not recorded' : String(record.exposure_duration) }]}
            paras={[{ h: 'What happened', t: pre(record.circumstances) }, { h: 'Reported symptoms', t: pre(record.symptoms) }, { h: 'First aid given', t: pre(record.first_aid_given) }]} />
        <FactRows title="On record" rows={[
            ['Worker', record.worker.name], ['Chemical', record.substance.name],
            ['Medical attention sought', exposureFactText(record, 'medical_attention_sought')], ['Recorded treatment', exposureFactText(record, 'medical_treatment')],
            ['Medical outcome', pre(record.medical_outcome)], ['Incident reported', exposureFactText(record, 'incident_reported')],
            ['Recorded by', record.recorded_by?.name ?? 'Not recorded'], ['Recorded at', dateTimeWithYear(record.created_at, 'Not recorded')],
            ['Last updated by', record.updated_by?.name ?? 'Not recorded'], ['Last recorded update', dateTimeWithYear(record.updated_at, 'Not recorded')],
        ]} />
    </>;
}

/** A care answer is known only when it was reported as an actual answer. */
const known = (record: ExposureRecord, key: ExposureReportedFact) => record.fact_states?.[key]?.state === 'reported' && record[key] !== null && record[key] !== '';

/** Side column (every tab): the care answers as a ring, then who entered and last updated the record. */
export function ExposureRecordSide({ detail }: { detail: ExposureRecordEnvelope }) {
    const record = admittedRecord(detail);
    if (!record) return null;
    const checks: CheckItem[] = [
        { label: 'Incident reported', state: known(record, 'incident_reported') ? 'success' : 'warning', note: exposureFactText(record, 'incident_reported') },
        { label: 'Treatment', state: known(record, 'medical_treatment') ? 'success' : 'warning', note: exposureFactText(record, 'medical_treatment') },
        { label: 'Medical help sought', state: known(record, 'medical_attention_sought') ? 'success' : 'warning', note: exposureFactText(record, 'medical_attention_sought') },
        { label: 'Medical outcome', state: record.medical_outcome ? 'success' : 'warning', note: record.medical_outcome ? 'Recorded' : 'No outcome yet' },
    ];
    const count = checks.filter((check) => check.state === 'success').length;
    const people: PersonCardItem[] = [
        ...(record.recorded_by ? [{ id: `entered-${record.recorded_by.id}`, name: record.recorded_by.name, sub: 'Entered it', foot: dateTimeWithYear(record.created_at, 'Time not recorded') }] : []),
        ...(record.updated_by ? [{ id: `updated-${record.updated_by.id}`, name: record.updated_by.name, sub: 'Last updated it', foot: dateTimeWithYear(record.updated_at, 'Time not recorded') }] : []),
    ];
    return <>
        <RingCard value={count} of={checks.length} label={`Care answers · ${count} of ${checks.length} known`} sub={count < checks.length ? 'Unknown or unclear answers need checking' : 'As reported'} checks={checks} />
        {people.length ? <PeopleCards title="Record" cols={1} items={people} /> : null}
    </>;
}

/** Connections (pg-hsn-129f): the chemical and the incident as cards, then the delivery line with its caveat. */
export function ExposureConnections({ detail }: { detail: ExposureRecordEnvelope }) {
    const record = admittedRecord(detail);
    if (!record) return <div role="status"><Note tone="warning">Connected records cannot currently be confirmed.</Note></div>;
    const connection = record.incident_connection;
    const href = connection.state === 'recorded' && Number.isSafeInteger(connection.id) && Number(connection.id) > 0
        && connection.href === '/incidents/' + connection.id ? connection.href : null;
    const back = exposureHref(record.id, { section: 'connections' });
    return <>
        <SideBySide>
            <Surface title="Chemical safety reference" sub={record.substance.name}>
                <div className="grid gap-3 px-[18px] pt-2 pb-4">
                    <p className="text-subtle">Open its precautions, safety data sheets and recorded storage. The chemical workspace checks its own current source access.</p>
                    {Number.isSafeInteger(record.substance.id) && record.substance.id > 0 && record.substance.id <= 2147483647 &&
                        <div><Button asChild variant="outline" size="sm"><a href={chemicalHref(record.substance.id, { returnTo: back })}>Open chemical reference</a></Button></div>}
                </div>
            </Surface>
            <Surface title="Related incident">
                <div className="grid gap-3 px-[18px] pt-2 pb-4">
                    {href ? <div><Button asChild variant="outline" size="sm"><a href={incidentWorkspaceHref(connection.id!, back)}>Open related incident</a></Button></div>
                        : <p className="text-subtle">{connection.state === 'absent' ? 'No related incident was retained on this source.' : 'The original incident association is unconfirmed. Its details are withheld.'}</p>}
                </div>
            </Surface>
        </SideBySide>
        <Journey title="Health & Safety and follow-up delivery" sub="Each step is checked separately."
            steps={[{ label: 'Accepted', meta: 'Source recorded', state: 'done' }, { label: 'Received', meta: 'Not checked', state: 'todo' },
                { label: 'Read', meta: 'Not checked', state: 'todo' }, { label: 'Done', meta: 'Not checked', state: 'todo' }]}
            caption={<span className="inline-flex items-start gap-2"><Info aria-hidden="true" className="mt-0.5 size-4 shrink-0" /><span role="status">Recorded source acceptance and reported delivery attempts do not confirm that another team received the record. Check current destination proof separately.</span></span>} />
    </>;
}
