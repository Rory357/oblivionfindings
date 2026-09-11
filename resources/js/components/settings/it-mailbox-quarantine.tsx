import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import type { MailboxConnection, MailboxProvider } from './it-mailbox-contract';
import {
    validQuarantinePage,
    validQuarantineRecord,
    type QuarantinePage,
    type QuarantineRecord,
} from './it-mailbox-quarantine-contract';

const labels: Record<QuarantineRecord['status'], string> = {
    pending: 'Retry requested',
    processed: 'Processed',
    quarantined: 'Quarantined',
    unmatched: 'Needs review',
    rejected: 'Rejected',
    duplicate: 'Duplicate',
};

export function MailboxQuarantinePanel({
    provider,
    connection,
    onAccessLost,
    onDirtyChange,
}: {
    provider: MailboxProvider;
    connection: MailboxConnection;
    onAccessLost: () => void;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const [page, setPage] = useState<QuarantinePage | null>(null);
    const [pending, setPending] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [message, setMessage] = useState('');
    const [confirm, setConfirm] = useState<QuarantineRecord | null>(null);
    const alive = useRef(true);
    const busy = useRef(false);
    const abort = useRef<AbortController | null>(null);
    const alert = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    useEffect(() => {
        onDirtyChange(pending || uncertain);
    }, [pending, uncertain, onDirtyChange]);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            abort.current?.abort();
        };
    }, []);
    useEffect(() => {
        if (message) alert.current?.focus();
    }, [message]);

    async function run(
        record: QuarantineRecord | null = null,
        before: number | null = null,
    ) {
        if (busy.current || connection.id === null || (record && uncertain))
            return;
        busy.current = true;
        const controller = new AbortController();
        abort.current = controller;
        setPending(true);
        setMessage('');
        try {
            const data = {
                connection_id: connection.id,
                expected_version: connection.version,
                ...(record
                    ? { expected_review_version: record.version }
                    : before
                      ? { before_id: before }
                      : {}),
            };
            const response = await axios.request({
                method: record ? 'post' : 'get',
                url: `/settings/it-mailbox/quarantine/${provider}${record ? `/${record.id}/retry` : ''}`,
                ...(record ? { data } : { params: data }),
                timeout: 15000,
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!alive.current) return;
            if (record) {
                const saved = response.data?.record;
                if (
                    response.data?.status !== 'requested' ||
                    !validQuarantineRecord(saved) ||
                    saved.id !== record.id ||
                    saved.version !== record.version + 1 ||
                    saved.status !== 'pending' ||
                    !saved.retry_requested_at
                )
                    throw new Error('Unconfirmed retry response.');
                setPage((current) =>
                    current
                        ? {
                              ...current,
                              records: current.records.map((item) =>
                                  item.id === saved.id ? saved : item,
                              ),
                          }
                        : null,
                );
                setMessage(
                    'Retry requested. Run the mailbox poll when available, then reload these records to review the outcome. No ticket creation is confirmed yet.',
                );
            } else {
                if (
                    !validQuarantinePage(response.data) ||
                    response.data.connection_id !== connection.id ||
                    response.data.connection_version !== connection.version
                )
                    throw new Error('Unconfirmed quarantine state.');
                setPage(response.data);
                setUncertain(false);
                setMessage(
                    'Current quarantine records loaded. Review the recorded status before requesting a retry.',
                );
            }
        } catch (error) {
            if (!alive.current) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            if ([401, 403, 419].includes(status ?? 0)) {
                setPage(null);
                setConfirm(null);
                onAccessLost();
            } else {
                setUncertain(true);
                setMessage(
                    status === 409
                        ? 'The mailbox or record changed, or another operation is running. Reload the saved mailbox state and these records before retrying.'
                        : status === 503
                          ? 'Quarantine review is unavailable until its database upgrade is applied. Existing quarantined records are retained.'
                          : record
                            ? 'The retry outcome is unknown. Reload these records before trying again. Stopping the wait does not undo a request already sent.'
                            : 'Quarantine records could not be loaded. Retry loading them before requesting any changes.',
                );
            }
        } finally {
            busy.current = false;
            if (alive.current) setPending(false);
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Quarantine review</CardTitle>
                <CardDescription>
                    Review intake decisions and request a recheck after
                    correcting account or site access. Message content and files
                    remain protected.
                </CardDescription>
            </CardHeader>
            <CardContent ref={content} tabIndex={-1} className="space-y-4">
                {connection.id === null ? (
                    <p className="text-subtle">
                        Connect a mailbox to review its inbound records.
                    </p>
                ) : (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={pending}
                                onClick={() => void run()}
                            >
                                {page
                                    ? 'Reload latest records'
                                    : 'Load quarantine records'}
                            </Button>
                            {page?.next_before_id && (
                                <Button
                                    variant="outline"
                                    disabled={pending}
                                    onClick={() =>
                                        void run(null, page.next_before_id)
                                    }
                                >
                                    Older records
                                </Button>
                            )}
                            {pending && (
                                <Button
                                    variant="outline"
                                    onClick={() => abort.current?.abort()}
                                >
                                    Stop waiting
                                </Button>
                            )}
                        </div>
                        {pending && (
                            <p role="status" className="text-subtle">
                                Waiting for the server. Stopping the wait does
                                not undo a request already sent.
                            </p>
                        )}
                        {message && (
                            <Alert ref={alert} tabIndex={-1} role="status">
                                <AlertTitle>
                                    {uncertain
                                        ? 'Review current state'
                                        : 'Quarantine update'}
                                </AlertTitle>
                                <AlertDescription>{message}</AlertDescription>
                            </Alert>
                        )}
                        {page &&
                            (page.records.length === 0 ? (
                                <p className="text-subtle">
                                    No quarantine or previously retried records
                                    were found for this mailbox.
                                </p>
                            ) : (
                                <ul
                                    className="divide-y rounded-lg border"
                                    aria-label="Quarantine records"
                                >
                                    {page.records.map((record) => (
                                        <li
                                            key={record.id}
                                            className="space-y-2 p-4"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        Inbound record{' '}
                                                        {record.id}
                                                    </span>
                                                    <StatusBadge
                                                        variant={
                                                            record.status ===
                                                            'quarantined'
                                                                ? 'warning'
                                                                : record.status ===
                                                                    'pending'
                                                                  ? 'info'
                                                                  : 'neutral'
                                                        }
                                                    >
                                                        {labels[record.status]}
                                                    </StatusBadge>
                                                </div>
                                                {record.can_retry && (
                                                    <Button
                                                        variant="outline"
                                                        disabled={
                                                            pending || uncertain
                                                        }
                                                        onClick={(event) => {
                                                            trigger.current =
                                                                event.currentTarget;
                                                            setConfirm(record);
                                                        }}
                                                        aria-label={`Request retry for inbound record ${record.id}`}
                                                    >
                                                        Request retry
                                                    </Button>
                                                )}
                                            </div>
                                            {record.reason && (
                                                <p className="font-medium">
                                                    {record.reason}
                                                </p>
                                            )}
                                            <p className="text-subtle">
                                                {record.guidance}
                                            </p>
                                            <p className="text-subtle">
                                                Received{' '}
                                                {record.received_at
                                                    ? formatDateTime(
                                                          record.received_at,
                                                      )
                                                    : 'time unavailable'}
                                                {record.retry_requested_at
                                                    ? ` · Retry requested ${formatDateTime(record.retry_requested_at)}`
                                                    : ''}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            ))}
                    </>
                )}
                <ConfirmDialog
                    open={confirm !== null}
                    onClose={() => setConfirm(null)}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        if (
                            trigger.current?.isConnected &&
                            !trigger.current.disabled
                        ) {
                            trigger.current.focus();
                        } else {
                            (alert.current ?? content.current)?.focus();
                        }
                    }}
                    onConfirm={() => {
                        if (confirm) void run(confirm);
                    }}
                    variant="default"
                    title={`Recheck inbound record ${confirm?.id ?? ''}?`}
                    confirmText="Request retry"
                    description="The next mailbox poll will retrieve the same provider message and recheck its identity, content, sender access and files. It may remain quarantined. This does not grant access or mark a file safe."
                />
            </CardContent>
        </Card>
    );
}
