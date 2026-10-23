import { Button } from '@/components/ui/button';
import {
    AttachmentUploader,
    formatFileSize,
} from '@/components/ui/file-dropzone';
import {
    useSafetyDraftState,
    useSafetyForm,
} from '@/hooks/use-safety-workspace-drafts';
import { formatDateTimeLong } from '@/lib/datetime';
import { PROCEDURE_REGISTER } from '@/lib/health-safety/procedure-navigation';
import { useState } from 'react';
import type { ProcedureWorkspaceDetail } from './types';

export function ProcedureDocuments({
    detail: d,
}: {
    detail: ProcedureWorkspaceDetail;
}) {
    const [uploadState, setUploadState] = useState({
        dirty: false,
        busy: false,
    });
    useSafetyDraftState(uploadState);
    const [selected, setSelected] = useState<number | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const removal = useSafetyForm({});
    const attachment = d.attachments.find((item) => item.id === selected);
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Documents</h2>
            {d.documents_state !== 'available' && (
                <p className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning-foreground">
                    {d.documents_state === 'unrecorded'
                        ? 'The document list for this historical version was not recorded.'
                        : 'Some files recorded for this version are no longer available.'}
                </p>
            )}
            <p className="text-subtle">
                These documents belong to the version you are viewing. Editing a
                working revision keeps the published files and earlier versions
                intact.
            </p>
            {d.can.add_evidence && (
                <AttachmentUploader
                    frontline
                    endpoint={`${PROCEDURE_REGISTER}/${d.id}/attachments`}
                    noteField="description"
                    accept=".pdf,.doc,.docx,.xls,.xlsx,image/*"
                    hint="Documents or images · up to 20 MB each"
                    onStateChange={setUploadState}
                    requestData={{
                        expected_version: d.expected_version,
                        expected_lock_version: d.lock_version,
                    }}
                    nextRequestData={(page) => {
                        const detail = page.props.detail as
                            | ProcedureWorkspaceDetail
                            | undefined;
                        if (
                            !detail ||
                            detail.id !== d.id ||
                            !Number.isInteger(detail.expected_version) ||
                            !Number.isInteger(detail.lock_version)
                        )
                            return null;
                        return {
                            expected_version: detail.expected_version,
                            expected_lock_version: detail.lock_version,
                        };
                    }}
                />
            )}
            {attachment && d.can.remove_evidence && (
                <div className="space-y-3 rounded-lg border border-border bg-muted/20 p-4">
                    <p>
                        Remove <strong>{attachment.original_name}</strong> from
                        the working revision? Earlier published versions keep
                        their document.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        <Button
                            className="frontline-tap"
                            disabled={removal.processing}
                            onClick={() => {
                                if (
                                    removal.processing ||
                                    !d.can.remove_evidence
                                )
                                    return;
                                setFailure(null);
                                removal.transform(() => ({
                                    expected_version: d.expected_version,
                                    expected_lock_version: d.lock_version,
                                }));
                                let responded = false;
                                removal.delete(
                                    `${PROCEDURE_REGISTER}/${d.id}/attachments/${attachment.id}`,
                                    {
                                        preserveState: true,
                                        preserveScroll: true,
                                        onSuccess: (page) => {
                                            responded = true;
                                            const error = (
                                                page.props.flash as
                                                    | { error?: string }
                                                    | undefined
                                            )?.error;
                                            if (error) {
                                                setFailure(error);
                                                return;
                                            }
                                            setSelected(null);
                                        },
                                        onError: () => {
                                            responded = true;
                                        },
                                        onCancel: () => {
                                            responded = true;
                                            setFailure(
                                                'Removal was interrupted. Check this working revision before trying again.',
                                            );
                                        },
                                        onFinish: () => {
                                            if (!responded)
                                                setFailure(
                                                    'Document removal could not be confirmed. Check this working revision before trying again.',
                                                );
                                        },
                                    },
                                );
                            }}
                        >
                            Remove from working revision
                        </Button>
                        <Button
                            className="frontline-tap"
                            variant="outline"
                            disabled={removal.processing}
                            onClick={() => setSelected(null)}
                        >
                            Keep document
                        </Button>
                    </div>
                </div>
            )}
            {(failure || Object.keys(removal.errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(removal.errors).join(' ')}
                </p>
            )}
            {d.attachments.length ? (
                <ul className="space-y-3">
                    {d.attachments.map((item) => (
                        <li
                            key={item.id}
                            className="space-y-2 rounded-lg border border-border p-4"
                        >
                            <a
                                href={item.url}
                                className="frontline-tap frontline-focus inline-flex break-all text-primary underline"
                            >
                                {item.original_name}
                            </a>
                            <p className="text-subtle">
                                {formatFileSize(item.size ?? 0)}
                                {item.uploaded_by_name
                                    ? ` · ${item.uploaded_by_name}`
                                    : ''}
                                {item.created_at
                                    ? ` · ${formatDateTimeLong(item.created_at)}`
                                    : ''}
                            </p>
                            {item.description && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {item.description}
                                </p>
                            )}
                            {d.can.remove_evidence && (
                                <Button
                                    className="frontline-tap"
                                    variant="outline"
                                    onClick={() => setSelected(item.id)}
                                >
                                    Remove {item.original_name}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">
                    No documents are recorded for this version.
                </p>
            )}
        </div>
    );
}
