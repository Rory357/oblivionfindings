import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { Label } from '@/components/ui/label';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import axios from 'axios';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    FileText,
    RefreshCw,
    Upload,
} from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { label, referralHref, type Page } from './types';

export type ReferralDocument = {
    id: number;
    reference: string;
    title: string;
    audience: 'assessment' | 'funding';
    category: string;
    source: string | null;
    state: string;
    expires_at: string | null;
    expired: boolean;
    previous_version_id: number | null;
    superseded_by_id: number | null;
    size_bytes: number;
    created_at: string;
};

export function EvidencePicker({
    reference,
    audience,
    selected,
    onChange,
    allowQuarantined = false,
    refresh = 0,
    onReplace,
    onRescan,
}: {
    reference: string;
    audience: 'assessment' | 'funding';
    selected?: number[];
    onChange?: (ids: number[], changed?: ReferralDocument) => void;
    allowQuarantined?: boolean;
    refresh?: number;
    onReplace?: (document: ReferralDocument) => void;
    onRescan?: (document: ReferralDocument) => void;
}) {
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Page<ReferralDocument> | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [view, setView] = useState<ReferralDocument | null>(null);
    const id = useId();
    const menu = useEntityContextMenu<ReferralDocument>();
    const downloadHref = (document: ReferralDocument) =>
        `${referralHref(reference)}/documents/${document.id}/download`;
    /** One MenuItem[] feeds each document card's kebab and right-click menu. */
    const documentActions = (document: ReferralDocument): MenuItem[] => [
        {
            label: 'View document details',
            icon: Eye,
            onClick: () => setView(document),
        },
        {
            label: 'Download document',
            icon: Download,
            onClick: () => {
                window.open(
                    downloadHref(document),
                    '_blank',
                    'noopener,noreferrer',
                );
            },
            disabled:
                document.state === 'available'
                    ? undefined
                    : 'Downloads are blocked until malware checks pass',
        },
        ...(onReplace
            ? [
                  {
                      label: 'Upload new version',
                      icon: Upload,
                      onClick: () => onReplace(document),
                      disabled: document.superseded_by_id
                          ? 'A newer version has already replaced this one'
                          : undefined,
                  },
              ]
            : []),
        ...(onRescan && document.state === 'quarantined'
            ? [
                  {
                      label: 'Retry malware scan',
                      icon: RefreshCw,
                      onClick: () => onRescan(document),
                  },
              ]
            : []),
    ];
    useEffect(() => {
        const controller = new AbortController();
        setBusy(true);
        setError('');
        setData(null);
        axios
            .get<{ documents: Page<ReferralDocument> }>(
                `${referralHref(reference)}/documents`,
                { params: { audience, page }, signal: controller.signal },
            )
            .then((response) => setData(response.data.documents))
            .catch((caught) => {
                if (!axios.isCancel(caught))
                    setError(
                        'Documents could not be loaded. Check your access or try again.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [reference, audience, page, refresh, retry]);
    return (
        <div className="space-y-3">
            {selected && (
                <p className="text-subtle" role="status">
                    {selected.length} documents selected across all pages
                </p>
            )}
            {busy && (
                <div role="status">
                    <LoadingState message="Loading private documents…" />
                </div>
            )}
            {error && (
                <ErrorState
                    title="Documents unavailable"
                    message={error}
                    onRetry={() => setRetry((value) => value + 1)}
                />
            )}
            {!busy && data?.total === 0 && (
                <EmptyState
                    variant="compact"
                    icon={FileText}
                    title={`No ${label(audience).toLowerCase()} documents yet`}
                    description="Add documents from the Evidence section before linking them here."
                />
            )}
            {!selected && (
                <EntityCardGrid>
                    {data?.data.map((document) => (
                        <EntityCard
                            key={document.id}
                            name={document.title}
                            icon={FileText}
                            subline={`${label(document.category)} · ${Math.ceil(document.size_bytes / 1024)} KB`}
                            meridian={
                                document.expired ||
                                document.superseded_by_id ||
                                document.state !== 'available'
                                    ? 'warning'
                                    : 'neutral'
                            }
                            onOpen={() => setView(document)}
                            onContextMenu={(event) =>
                                menu.open(event, document)
                            }
                            actions={documentActions(document)}
                            chips={
                                <>
                                    <StatusBadge status={document.state} />
                                    {document.expired && (
                                        <EntityChip>Expired</EntityChip>
                                    )}
                                    {document.superseded_by_id && (
                                        <EntityChip>Replaced</EntityChip>
                                    )}
                                </>
                            }
                            footer={{
                                primary:
                                    document.source ?? 'Source not recorded',
                                secondary: formatDateTimeLong(
                                    document.created_at,
                                ),
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
            {selected &&
                data?.data.map((document) => {
                    const eligible =
                        !document.expired &&
                        !document.superseded_by_id &&
                        (document.state === 'available' ||
                            (allowQuarantined &&
                                document.state === 'quarantined'));
                    return (
                        <div
                            key={document.id}
                            className="space-y-3 rounded-lg bg-muted p-4"
                        >
                            <div className="flex items-start gap-3">
                                {selected && onChange && (
                                    <Checkbox
                                        id={`${id}-${document.id}`}
                                        checked={selected.includes(document.id)}
                                        disabled={
                                            !eligible &&
                                            !selected.includes(document.id)
                                        }
                                        onCheckedChange={(checked) =>
                                            onChange(
                                                checked === true
                                                    ? [...selected, document.id]
                                                    : selected.filter(
                                                          (value) =>
                                                              value !==
                                                              document.id,
                                                      ),
                                                document,
                                            )
                                        }
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    {selected ? (
                                        <Label
                                            htmlFor={`${id}-${document.id}`}
                                            className="flex items-center break-words"
                                        >
                                            {document.title}
                                        </Label>
                                    ) : (
                                        <p className="font-medium break-words">
                                            {document.title}
                                        </p>
                                    )}
                                    <p className="text-subtle">
                                        {label(document.category)} ·{' '}
                                        {formatDateTimeLong(
                                            document.created_at,
                                        )}{' '}
                                        ·{' '}
                                        {Math.ceil(document.size_bytes / 1024)}{' '}
                                        KB
                                    </p>
                                    {document.source && (
                                        <p className="text-subtle break-words">
                                            Source: {document.source}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={document.state} />
                                {document.superseded_by_id && (
                                    <span className="text-subtle">
                                        Replaced by a newer version
                                    </span>
                                )}
                                {document.expired && (
                                    <span className="text-subtle">Expired</span>
                                )}
                            </div>
                            {document.expires_at && (
                                <p className="text-subtle">
                                    Expires{' '}
                                    {formatDateTimeLong(document.expires_at)}
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {document.state === 'available' && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={`${referralHref(reference)}/documents/${document.id}/download`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Download className="size-4" />
                                            Download
                                        </a>
                                    </Button>
                                )}
                                {onReplace && !document.superseded_by_id && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => onReplace(document)}
                                    >
                                        Upload new version
                                    </Button>
                                )}
                                {onRescan &&
                                    document.state === 'quarantined' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => onRescan(document)}
                                        >
                                            Retry malware scan
                                        </Button>
                                    )}
                            </div>
                        </div>
                    );
                })}
            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={FileText}
                    title={menu.ctx.record.title}
                    items={documentActions(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
            <Dialog
                open={!!view}
                onOpenChange={(open) => {
                    if (!open) setView(null);
                }}
            >
                <DialogContent
                    style={{ width: 'min(92vw, 480px)' }}
                    className="max-h-[90vh] overflow-y-auto"
                >
                    <DialogHeader>
                        <DialogTitle>{view?.title}</DialogTitle>
                        <DialogDescription>
                            Private {label(audience).toLowerCase()} document
                        </DialogDescription>
                    </DialogHeader>
                    {view && (
                        <div className="space-y-4">
                            <StatusBadge status={view.state} />
                            <dl className="space-y-3 text-sm">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Source
                                    </dt>
                                    <dd className="break-words whitespace-pre-wrap">
                                        {view.source ?? 'Not recorded'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Category
                                    </dt>
                                    <dd>{label(view.category)}</dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Added
                                    </dt>
                                    <dd>
                                        {formatDateTimeLong(view.created_at)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Expiry
                                    </dt>
                                    <dd>
                                        {view.expires_at
                                            ? formatDateTimeLong(
                                                  view.expires_at,
                                              )
                                            : 'Not specified'}
                                    </dd>
                                </div>
                            </dl>
                            {view.superseded_by_id && (
                                <p className="text-subtle">
                                    This version is retained as history. Use the
                                    newer document for current decisions.
                                </p>
                            )}
                            {view.state === 'available' ? (
                                <Button variant="outline" asChild>
                                    <a
                                        href={`${referralHref(reference)}/documents/${view.id}/download`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Download className="size-4" />
                                        Download document
                                    </a>
                                </Button>
                            ) : (
                                <p className="text-subtle">
                                    Downloads are blocked until malware checks
                                    pass. Rejected files require a safe
                                    replacement.
                                </p>
                            )}
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setView(null)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between gap-3">
                    <Button
                        variant="outline"
                        disabled={busy || page <= 1}
                        onClick={() => setPage((value) => value - 1)}
                    >
                        <ChevronLeft className="size-4" />
                        Previous
                    </Button>
                    <span className="text-subtle">
                        Page {data.current_page} of {data.last_page}
                    </span>
                    <Button
                        variant="outline"
                        disabled={busy || page >= data.last_page}
                        onClick={() => setPage((value) => value + 1)}
                    >
                        Next
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}
