import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    EntityContextMenu,
    EntityTable,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Ban, FileText, Inbox, Plus, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    DocumentRequestDialog,
    type DocumentRequestRow,
} from './document-request-dialog';
import {
    referralHref,
    type Page,
    type Permissions,
    type ReferralDetail,
} from './types';

const REQUEST_STATES: Record<string, string> = {
    pending: 'Awaiting document',
    received: 'Received — review needed',
    locked: 'Locked after code attempts',
    expired: 'Expired',
    unavailable: 'Unavailable — review authority or access',
    revoked: 'Revoked',
};
type Data = {
    requests: Page<DocumentRequestRow>;
    can_issue: boolean;
    configuration: { enabled: boolean; maximum_days: number };
};
export function DocumentRequestsPanel({
    referral,
    audience,
    permissions,
    onReceivedChange,
}: {
    referral: ReferralDetail;
    audience: 'assessment' | 'funding';
    permissions: Permissions;
    onReceivedChange: () => void;
}) {
    const [data, setData] = useState<Data | null>(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [refresh, setRefresh] = useState(0);
    const [create, setCreate] = useState<number | null>(null);
    const [revoke, setRevoke] = useState<DocumentRequestRow | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const pending = useRef(false);
    const requestMenu = useEntityContextMenu<DocumentRequestRow>();
    const base = `${referralHref(referral.reference)}/document-requests`;
    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setError('');
        axios
            .get<Data>(base, {
                params: { audience, page },
                signal: controller.signal,
            })
            .then((response) => setData(response.data))
            .catch((caught) => {
                if (!controller.signal.aborted)
                    setError(
                        axios.isAxiosError(caught)
                            ? (caught.response?.data?.message ??
                                  'Document requests could not be loaded.')
                            : 'Document requests could not be loaded.',
                    );
            });
        return () => controller.abort();
    }, [base, audience, page, refresh, referral.version]);
    async function revokeRequest() {
        if (!revoke || pending.current) return;
        if (reason.trim().length < 5) {
            setError('Enter a reason for revoking this request.');
            return;
        }
        pending.current = true;
        setBusy(true);
        setError('');
        try {
            await axios.post(`${base}/${revoke.id}/revoke`, {
                version: referral.version,
                reason,
            });
            setRevoke(null);
            setReason('');
            setRefresh((v) => v + 1);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            if (axios.isAxiosError(caught) && caught.response?.status === 409) {
                router.reload({ only: ['referral'], preserveScroll: true });
            }
            setError(
                axios.isAxiosError(caught)
                    ? (caught.response?.data?.message ??
                          'Revocation was not confirmed. Refresh the current status.')
                    : 'Revocation was not confirmed.',
            );
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    const canRevoke =
        permissions.disclose &&
        permissions['evidence.manage'] &&
        permissions[`${audience}.manage`];
    const revocable = ['pending', 'expired', 'locked', 'unavailable'];
    /** One MenuItem[] feeds each request row's kebab and right-click menu. */
    const requestActions = (request: DocumentRequestRow): MenuItem[] =>
        canRevoke
            ? [
                  {
                      label: 'Revoke request',
                      icon: Ban,
                      danger: true,
                      onClick: () => {
                          setError('');
                          setReason('');
                          setRevoke(request);
                      },
                      disabled: revocable.includes(request.state)
                          ? undefined
                          : request.state === 'received'
                            ? 'A document was received; review it in the evidence list'
                            : 'This request is already closed',
                  },
              ]
            : [];
    return (
        <>
            <section
                className="space-y-4"
                aria-labelledby={`document-requests-${audience}`}
            >
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2
                            id={`document-requests-${audience}`}
                            className="text-section-title"
                        >
                            Document requests
                        </h2>
                        <p className="text-subtle">
                            Ask a verified person for one supporting document.
                            Staff share the link and code separately; no message
                            is sent automatically.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            onClick={() => {
                                setRefresh((v) => v + 1);
                                onReceivedChange();
                                router.reload({
                                    only: ['referral'],
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <RefreshCw className="size-4" />
                            Refresh requests
                        </Button>
                        {data?.configuration.enabled && data.can_issue && (
                            <Button
                                onClick={() =>
                                    setCreate(data.configuration.maximum_days)
                                }
                            >
                                <Plus className="size-4" />
                                Request a document
                            </Button>
                        )}
                    </div>
                </div>
                {error && !revoke && (
                    <ErrorState
                        title="Document requests unavailable"
                        message={error}
                        onRetry={() => setRefresh((v) => v + 1)}
                    />
                )}
                {!data && !error && (
                    <div role="status">
                        <SkeletonTable rows={3} />
                        <span className="sr-only">
                            Loading document requests…
                        </span>
                    </div>
                )}
                {data && !data.configuration.enabled && (
                    <p className="text-subtle">
                        Document requests are turned off in this referral’s
                        published settings.
                    </p>
                )}
                {data?.configuration.enabled && !data.can_issue && (
                    <p className="text-subtle">
                        Creating a request needs current contact and collection
                        authority, plus disclosure, communications, document and
                        audience permissions.
                    </p>
                )}
                {data && data.requests.data.length === 0 && (
                    <EmptyState
                        variant="compact"
                        icon={Inbox}
                        title="No document requests in this view"
                        description="Requests you create for this document access level appear here."
                    />
                )}
                {data && data.requests.data.length > 0 && (
                    <EntityTable
                        rows={data.requests.data}
                        rowKey={(request) => request.id}
                        identityLabel="Request"
                        identity={(request) => ({
                            icon: FileText,
                            name: request.title,
                            subline: `For ${request.recipient}`,
                        })}
                        columns={[
                            {
                                key: 'state',
                                label: 'Status',
                                width: '1.3fr',
                                cell: (request) => (
                                    <StatusBadge
                                        variant={
                                            request.state === 'received'
                                                ? 'success'
                                                : request.state === 'pending'
                                                  ? 'info'
                                                  : 'neutral'
                                        }
                                    >
                                        {REQUEST_STATES[request.state] ??
                                            'Revoked'}
                                    </StatusBadge>
                                ),
                            },
                            {
                                key: 'date',
                                label: 'Date',
                                width: '1.2fr',
                                cell: (request) => (
                                    <span className="text-[12.5px]">
                                        {request.received_at
                                            ? `Received ${formatDateTimeLong(request.received_at)}`
                                            : request.revoked_at
                                              ? `Revoked ${formatDateTimeLong(request.revoked_at)}`
                                              : `Expires ${formatDateTimeLong(request.expires_at)}`}
                                    </span>
                                ),
                            },
                        ]}
                        actionsFor={requestActions}
                        onRowContextMenu={requestMenu.open}
                        rowHeight="content"
                        minWidth={640}
                    />
                )}
                {data && data.requests.last_page > 1 && (
                    <div className="flex items-center justify-between gap-3">
                        <Button
                            variant="outline"
                            className="frontline-hit"
                            disabled={page <= 1}
                            onClick={() => setPage((v) => v - 1)}
                        >
                            Previous requests
                        </Button>
                        <span className="text-caption">
                            Page {page} of {data.requests.last_page}
                        </span>
                        <Button
                            variant="outline"
                            className="frontline-hit"
                            disabled={page >= data.requests.last_page}
                            onClick={() => setPage((v) => v + 1)}
                        >
                            Next requests
                        </Button>
                    </div>
                )}
            </section>
            {requestMenu.ctx &&
            requestActions(requestMenu.ctx.record).length ? (
                <EntityContextMenu
                    x={requestMenu.ctx.x}
                    y={requestMenu.ctx.y}
                    icon={FileText}
                    title={requestMenu.ctx.record.title}
                    items={requestActions(requestMenu.ctx.record)}
                    onClose={requestMenu.close}
                />
            ) : null}
            {create !== null && (
                <DocumentRequestDialog
                    referral={referral}
                    audience={audience}
                    maximumDays={create}
                    onClose={() => {
                        setCreate(null);
                        setRefresh((v) => v + 1);
                        router.reload({
                            only: ['referral'],
                            preserveScroll: true,
                        });
                    }}
                />
            )}
            <ConfirmDialog
                variant="destructive"
                open={!!revoke}
                title="Revoke this document request?"
                description={
                    <div className="space-y-4">
                        <p>
                            The link and code will stop accepting documents.
                            Received evidence is kept separately.
                        </p>
                        <Field label="Reason for revoking">
                            <Textarea
                                disabled={busy}
                                value={reason}
                                maxLength={2000}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                            />
                        </Field>
                        {error && <p role="alert">{error}</p>}
                    </div>
                }
                confirmText="Revoke request"
                processing={busy}
                onClose={() => {
                    if (!busy) setRevoke(null);
                }}
                onConfirm={() => void revokeRequest()}
            />
        </>
    );
}
