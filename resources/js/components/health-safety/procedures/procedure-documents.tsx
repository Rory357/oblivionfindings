/**
 * A procedure version's documents (pg-hsn-64e): the version note, the files as
 * download rows (kebab and right-click share one list), the removal check and
 * the upload box. Uploads and removals bind the expected revision and lock.
 */
import {
    EntityContextMenu,
    EntityKebab,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { Note, Ring, Surface } from '@/components/signature/blocks';
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
import { AlertTriangle, Download, FileText, Info, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ProcedureWorkspaceDetail } from './types';

type Attachment = ProcedureWorkspaceDetail['attachments'][number];

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
    const fileMenu = useEntityContextMenu<Attachment>();
    const menuFor = (item: Attachment): MenuItem[] =>
        compactMenu([
            {
                label: 'Download',
                icon: Download,
                onClick: () => window.location.assign(item.url),
            },
            d.can.remove_evidence && { separator: true },
            d.can.remove_evidence && {
                label: `Remove ${item.original_name}`,
                icon: Trash2,
                danger: true,
                onClick: () => setSelected(item.id),
            },
        ]);
    return (
        <>
            {d.documents_state !== 'available' && (
                <Note tone="warning" icon={AlertTriangle}>
                    {d.documents_state === 'unrecorded'
                        ? 'The document list for this historical version was not recorded.'
                        : 'Some files recorded for this version are no longer available.'}
                </Note>
            )}
            <Note icon={Info}>
                These documents belong to the version you are viewing. Editing a
                working revision keeps the published files and earlier versions
                intact.
            </Note>
            {attachment && d.can.remove_evidence && (
                <Surface
                    tone="critical"
                    title={`Remove ${attachment.original_name}?`}
                >
                    <p className="px-[18px] pt-2 pb-4">
                        Remove <strong>{attachment.original_name}</strong> from
                        the working revision? Earlier published versions keep
                        their document.
                    </p>
                    <div className="sg-actions">
                        <Button
                            variant="outline"
                            disabled={removal.processing}
                            onClick={() => setSelected(null)}
                        >
                            Keep document
                        </Button>
                        <Button
                            variant="destructive"
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
                    </div>
                </Surface>
            )}
            {(failure || Object.keys(removal.errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(removal.errors).join(' ')}
                </p>
            )}
            <Surface title={`Documents · version ${d.viewed_revision.version}`}>
                {d.attachments.length ? (
                    <ul className="sg-list mt-2">
                        {d.attachments.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-center"
                                onContextMenu={(event) =>
                                    fileMenu.open(event, item)
                                }
                            >
                                <a
                                    href={item.url}
                                    className="sg-li min-w-0 flex-1"
                                >
                                    <span
                                        className="sg-li-lead"
                                        aria-hidden="true"
                                    >
                                        <FileText className="size-4" />
                                    </span>
                                    <span className="sg-li-t">
                                        <b className="break-all">
                                            {item.original_name}
                                        </b>
                                        <small className="!whitespace-normal">
                                            {[
                                                formatFileSize(item.size ?? 0),
                                                item.uploaded_by_name,
                                                item.created_at
                                                    ? formatDateTimeLong(
                                                          item.created_at,
                                                      )
                                                    : null,
                                                item.description,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </small>
                                    </span>
                                </a>
                                <EntityKebab
                                    actions={menuFor(item)}
                                    label={`Actions for ${item.original_name}`}
                                    className="mr-3"
                                />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="sg-quiet">
                        <Ring tone="none" />
                        <span>No documents are recorded for this version.</span>
                    </p>
                )}
            </Surface>
            {d.can.add_evidence && (
                <Surface title="Add documents">
                    <div className="px-[18px] pt-3 pb-4">
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
                    </div>
                </Surface>
            )}
            {fileMenu.ctx ? (
                <EntityContextMenu
                    x={fileMenu.ctx.x}
                    y={fileMenu.ctx.y}
                    icon={FileText}
                    title={fileMenu.ctx.record.original_name}
                    items={menuFor(fileMenu.ctx.record)}
                    onClose={fileMenu.close}
                />
            ) : null}
        </>
    );
}
