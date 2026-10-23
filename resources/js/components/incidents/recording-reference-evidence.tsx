import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { AlertCircle, Camera, FileText, LockKeyhole } from 'lucide-react';

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
    const headingId = `incident-recording-references-${incidentId}`;
    return (
        <Card role="region" aria-labelledby={headingId} aria-busy={loading}>
            <CardHeader>
                <h2 id={headingId} className="flex items-center gap-2 text-section-title">
                    <Camera aria-hidden="true" className="h-5 w-5 shrink-0" />
                    Recording references
                </h2>
                <p className="text-subtle text-muted-foreground">
                    Linked camera references record a requested time window.
                    They do not confirm that footage exists, has been viewed or
                    has been preserved.
                </p>
            </CardHeader>
            <CardContent className="space-y-5">
                {window ? (
                    <div className="space-y-2 rounded-lg border border-border p-4">
                        <h3 className="text-section-title">Current evidence window</h3>
                        <p className="text-subtle">
                            {formatDateTimeLong(window.start_at)} – {formatDateTimeLong(window.end_at)}
                        </p>
                        <p className="text-caption text-muted-foreground">
                            Window {window.revision} · Rule revision {window.rule_revision}
                        </p>
                        {window.context_state === 'changed' ? (
                            <p className="flex items-start gap-2 text-subtle text-status-warning-foreground">
                                <AlertCircle aria-hidden="true" className="mt-0.5 h-4 w-4 shrink-0" />
                                Incident details changed after this window was
                                recorded. Review the window before linking more
                                references. Earlier links remain in the history.
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <p className="text-subtle text-muted-foreground">
                        No active evidence window is recorded.
                    </p>
                )}

                {references.total === 0 ? (
                    <p className="rounded-lg border border-dashed border-border p-4 text-subtle text-muted-foreground">
                        No recording references have been linked to this incident.
                    </p>
                ) : references.items.length === 0 ? (
                    <p className="text-subtle text-muted-foreground">
                        No recording references are on this page. Use the page
                        controls to return to the recorded history.
                    </p>
                ) : (
                    <ul className="space-y-3" aria-label="Linked recording references">
                        {references.items.map((item) => {
                            const reference = item.access === 'readable' ? item.reference : null;
                            return (
                                <li key={item.attachment_uuid} className="space-y-3 rounded-lg border border-border p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <h3 className="min-w-0 break-words font-medium">
                                            {reference ? reference.camera.name : 'Restricted recording reference'}
                                        </h3>
                                        <StatusBadge variant="neutral">
                                            {reference ? <FileText aria-hidden="true" className="h-3.5 w-3.5" /> : <LockKeyhole aria-hidden="true" className="h-3.5 w-3.5" />}
                                            {reference ? 'Reference only' : 'Access restricted'}
                                        </StatusBadge>
                                    </div>
                                    <dl className="grid gap-3 text-subtle sm:grid-cols-2">
                                        <div>
                                            <dt className="text-muted-foreground">Requested window (NZ time)</dt>
                                            <dd>{formatDateTimeLong(item.requested_start_at)} – {formatDateTimeLong(item.requested_end_at)}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">Linked to incident</dt>
                                            <dd>{formatDateTimeLong(item.recorded_at)} · Window {item.window_revision}</dd>
                                        </div>
                                        {reference ? (
                                            <>
                                                <div><dt className="text-muted-foreground">Site</dt><dd className="break-words">{reference.site.name}</dd></div>
                                                <div><dt className="text-muted-foreground">Recorder</dt><dd className="break-words">{reference.recorder.name}</dd></div>
                                            </>
                                        ) : null}
                                    </dl>
                                    {reference ? (
                                        <>
                                            <p className="break-words text-subtle">{reference.purpose}</p>
                                            <div className="flex flex-wrap gap-2">
                                                <StatusBadge variant={reference.current_source.state === 'ready' ? 'info' : 'warning'}>
                                                    <Camera aria-hidden="true" className="h-3.5 w-3.5" />
                                                    {SOURCE_LABELS[reference.current_source.state]}
                                                </StatusBadge>
                                                <StatusBadge variant="neutral">Coverage not verified</StatusBadge>
                                                <StatusBadge variant="neutral">Preservation not confirmed</StatusBadge>
                                            </div>
                                            <p className="text-caption text-muted-foreground">
                                                Recording availability and retention are unknown.
                                                Source availability does not confirm recording coverage.
                                            </p>
                                        </>
                                    ) : (
                                        <p className="text-subtle text-muted-foreground">
                                            Your current access does not include
                                            this source. Camera details and the
                                            reference purpose are hidden.
                                        </p>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}

                {references.total > 0 ? (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-subtle text-muted-foreground" aria-live="polite">
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
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
