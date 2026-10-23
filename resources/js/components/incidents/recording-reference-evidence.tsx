import { Note, Ring } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateLong, formatDateTimeLong, formatTime } from '@/lib/datetime';
import { AlertCircle, Camera, FileText, LockKeyhole } from 'lucide-react';
import { RecordIdentity, RecordTable } from './record-table';

export type RecordingReferenceEvidenceData = {
    schema_version: 1;
    incident_id: number;
    incident_evidence_version: number | null;
    window: {
        revision: number;
        start_at: string;
        end_at: string;
        rule_identifier: string;
        rule_revision: number;
        context_state: 'current' | 'changed';
    } | null;
    references: {
        items: Array<{
            attachment_uuid: string;
            window_revision: number;
            attached_evidence_version: number;
            recorded_at: string;
            requested_start_at: string;
            requested_end_at: string;
            access: 'readable' | 'restricted';
            reference: {
                reference_uuid: string;
                camera: { id: number; name: string };
                recorder: { id: number; name: string };
                site: { id: number; name: string };
                purpose: string;
                state: 'reference_only';
                preserved: false;
                window_coverage: 'not_verified';
                recording_verified_at: null;
                vendor_retention_until: null;
                current_source: {
                    state: 'ready' | 'changed' | 'withdrawn' | 'unavailable';
                    source_verified_at: string | null;
                };
            } | null;
        }>;
        page: number;
        per_page: 20;
        total: number;
        has_more: boolean;
        next_page: number | null;
        previous_page: number | null;
    };
};

const SOURCE_LABELS = {
    ready: 'Source available',
    changed: 'Source changed',
    withdrawn: 'Source withdrawn',
    unavailable: 'Source unavailable',
} as const;

/** Only renders the incident-bound server projection; never resolves a provider UUID. */
export function RecordingReferenceEvidence({
    incidentId,
    evidence,
    onPageChange,
    loading = false,
}: {
    incidentId: number;
    evidence: RecordingReferenceEvidenceData;
    onPageChange: (page: number) => void;
    loading?: boolean;
}) {
    if (evidence.schema_version !== 1 || evidence.incident_id !== incidentId) {
        return (
            <p role="alert" className="text-subtle text-muted-foreground">
                Recording reference information is unavailable for this incident.
                Reload the incident to check current access.
            </p>
        );
    }

    const { window, references } = evidence;
    type Item = RecordingReferenceEvidenceData['references']['items'][number];
    const range = (start: string, end: string) =>
        formatDateLong(start) === formatDateLong(end)
            ? `${formatTime(start)} – ${formatTime(end)}`
            : `${formatTime(start)} – ${formatDateTimeLong(end)}`;
    return (
        <RecordTable<Item>
            title="Recording references"
            ariaLabel="Recording references"
            sub="Linked camera references record a requested time window. They do not confirm that footage exists, has been viewed or has been preserved."
            rows={references.items}
            rowKey={(item) => item.attachment_uuid}
            className={loading ? 'opacity-80' : undefined}
            columns={[
                {
                    key: 'reference',
                    label: 'Reference',
                    cell: (item) => {
                        const reference =
                            item.access === 'readable' ? item.reference : null;
                        return reference ? (
                            <RecordIdentity
                                leading={<Camera aria-hidden="true" className="size-4" />}
                                title={<span className="break-words">{reference.camera.name}</span>}
                                sub={
                                    <>
                                        <span className="block break-words">
                                            {reference.site.name} · {reference.recorder.name}
                                        </span>
                                        <span className="block break-words text-foreground">
                                            {reference.purpose}
                                        </span>
                                    </>
                                }
                            />
                        ) : (
                            <RecordIdentity
                                leading={<LockKeyhole aria-hidden="true" className="size-4" />}
                                title="Restricted recording reference"
                                sub="Your current access does not include this source. Camera details and the reference purpose are hidden."
                            />
                        );
                    },
                },
                {
                    key: 'requested',
                    label: 'Requested window (NZ time)',
                    nowrap: true,
                    cell: (item) => (
                        <RecordIdentity
                            title={formatDateLong(item.requested_start_at)}
                            sub={range(item.requested_start_at, item.requested_end_at)}
                        />
                    ),
                },
                {
                    key: 'linked',
                    label: 'Linked to incident',
                    nowrap: true,
                    cell: (item) => (
                        <RecordIdentity
                            title={formatDateLong(item.recorded_at)}
                            sub={`${formatTime(item.recorded_at)} · Window ${item.window_revision}`}
                        />
                    ),
                },
                {
                    key: 'source',
                    label: 'Source',
                    cell: (item) => {
                        const reference =
                            item.access === 'readable' ? item.reference : null;
                        if (!reference) {
                            return (
                                <StatusBadge variant="neutral">
                                    <LockKeyhole aria-hidden="true" className="h-3.5 w-3.5" />
                                    Access restricted
                                </StatusBadge>
                            );
                        }
                        return (
                            <div className="grid max-w-[18rem] gap-1.5">
                                <div className="flex flex-wrap gap-1.5">
                                    <StatusBadge variant="neutral">
                                        <FileText aria-hidden="true" className="h-3.5 w-3.5" />
                                        Reference only
                                    </StatusBadge>
                                    <StatusBadge variant={reference.current_source.state === 'ready' ? 'info' : 'warning'}>
                                        <Camera aria-hidden="true" className="h-3.5 w-3.5" />
                                        {SOURCE_LABELS[reference.current_source.state]}
                                    </StatusBadge>
                                    <StatusBadge variant="neutral">Coverage not verified</StatusBadge>
                                    <StatusBadge variant="neutral">Preservation not confirmed</StatusBadge>
                                </div>
                                <p className="text-caption">
                                    Recording availability and retention are unknown.
                                    Source availability does not confirm recording coverage.
                                </p>
                            </div>
                        );
                    },
                },
            ]}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>
                        {references.total === 0
                            ? 'No recording references have been linked to this incident.'
                            : 'No recording references are on this page. Use the page controls to return to the recorded history.'}
                    </span>
                </p>
            }
            footer={
                references.total > 0 ? (
                    <>
                        <p className="text-subtle" aria-live="polite">
                            {loading ? 'Loading recording references…' : `Page ${references.page} · ${references.total} linked ${references.total === 1 ? 'reference' : 'references'}`}
                        </p>
                        <LaravelPagination
                            ariaLabel="Recording reference pages"
                            navigation={{
                                currentPage: references.page,
                                previousPage: references.previous_page,
                                nextPage: references.has_more ? references.next_page : null,
                                pending: loading,
                                onPageChange,
                            }}
                        />
                    </>
                ) : undefined
            }
        >
            <div className="grid gap-2 border-b border-border px-4 py-3">
                {window ? (
                    <>
                        <p className="text-subtle">
                            <span className="font-semibold text-foreground">Current evidence window:</span>{' '}
                            {formatDateTimeLong(window.start_at)} – {formatDateTimeLong(window.end_at)}
                            <span className="text-caption"> · Window {window.revision} · Rule revision {window.rule_revision}</span>
                        </p>
                        {window.context_state === 'changed' ? (
                            <Note tone="warning" icon={AlertCircle}>
                                Incident details changed after this window was
                                recorded. Review the window before linking more
                                references. Earlier links remain in the history.
                            </Note>
                        ) : null}
                    </>
                ) : (
                    <p className="text-subtle">No active evidence window is recorded.</p>
                )}
            </div>
        </RecordTable>
    );
}
