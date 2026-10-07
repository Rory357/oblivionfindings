import { ConfirmDialog } from '@/components/confirm-dialog';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Field } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import axios from 'axios';
import { Send } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Toggle } from '../connected/_forms';
import { RecordPicker, useCommand } from '../connected/_shared';
import type { Connection } from './index';
type Dispatch = {
    id: number;
    uuid: string;
    state: string;
    label: string;
    attempt_count: number;
    sent_at: string | null;
    supplier_reference: string | null;
    acknowledgment_applied: boolean | null;
    acknowledgment_code: string | null;
};
type Status = {
    enabled: boolean;
    connections: Connection[];
    dispatch: Dispatch | null;
    can_send: boolean;
    can_retry: boolean;
    can_cancel: boolean;
    can_resolve_unknown: boolean;
    local_order_closed: boolean;
    notice: string | null;
};
export function PharmacyDispatch({
    orderId,
    onSaved,
    onStatusChanged,
}: {
    orderId: number;
    onSaved: () => void;
    onStatusChanged?: () => void;
}) {
    const [status, setStatus] = useState<Status | null>(null);
    const [loadError, setLoadError] = useState('');
    const statusCallback = useRef(onStatusChanged);
    const previousStatus = useRef<string | undefined>(undefined);
    useEffect(() => {
        statusCallback.current = onStatusChanged;
    }, [onStatusChanged]);
    useEffect(() => {
        if (!status) return;
        const signature = [
            status.dispatch?.id,
            status.dispatch?.state,
            status.dispatch?.acknowledgment_applied,
            status.local_order_closed,
        ].join(':');
        if (signature !== previousStatus.current) {
            previousStatus.current = signature;
            statusCallback.current?.();
        }
    }, [status]);
    const [connection, setConnection] = useState('');
    const [action, setAction] = useState<
        'send' | 'retry' | 'cancel' | 'resolve' | null
    >(null);
    const [reference, setReference] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [key, setKey] = useState(() => crypto.randomUUID());
    const startAction = (next: NonNullable<typeof action>) => {
        setKey(crypto.randomUUID());
        setAction(next);
    };
    const command = useCommand();
    const base = '/emar/stock/pharmacy-orders/' + orderId;
    const refresh = useCallback(
        async (signal?: AbortSignal) => {
            try {
                const { data } = await axios.get<Status>(base + '/connection', {
                    signal,
                    headers: { Accept: 'application/json' },
                });
                setStatus(data);
                setLoadError('');
            } catch (e) {
                if (!axios.isCancel(e)) {
                    setStatus(null);
                    setLoadError(
                        'Connection status is unavailable. Refresh before sending.',
                    );
                }
            }
        },
        [base],
    );
    useEffect(() => {
        const controller = new AbortController();
        void refresh(controller.signal);
        const timer = setInterval(() => void refresh(controller.signal), 15000);
        return () => {
            controller.abort();
            clearInterval(timer);
        };
    }, [refresh]);
    const run = async () => {
        if (!action) return;
        const path =
            action === 'send'
                ? base + '/dispatch'
                : base + '/dispatch/' + status?.dispatch?.id + '/' + action;
        const payload =
            action === 'send'
                ? { connection_id: Number(connection), request_uuid: key }
                : action === 'resolve'
                  ? {
                        request_uuid: key,
                        expected_state: status?.dispatch?.state,
                        confirmed_not_received: confirmed,
                        reference,
                    }
                  : {
                        request_uuid: key,
                        expected_state: status?.dispatch?.state,
                    };
        const result = await command.run(path, payload);
        if (result) {
            setAction(null);
            await refresh();
            onSaved();
        }
    };
    return (
        <ReviewCard icon={Send} title="Connected pharmacy delivery">
            {loadError && (
                <SettingsNotice role="alert">
                    {loadError}
                    <Button variant="link" onClick={() => void refresh()}>
                        Refresh status
                    </Button>
                </SettingsNotice>
            )}
            {!status && !loadError && (
                <p className="text-caption">Checking delivery status…</p>
            )}
            {status && (
                <div className="space-y-3">
                    {status.notice && (
                        <SettingsNotice>{status.notice}</SettingsNotice>
                    )}
                    {status.dispatch && (
                        <>
                            <ReviewRow
                                label="Delivery"
                                value={
                                    <StatusBadge
                                        status={status.dispatch.state}
                                        label={status.dispatch.label}
                                    />
                                }
                            />
                            <ReviewRow
                                label="Attempts"
                                value={status.dispatch.attempt_count}
                            />
                            <ReviewRow
                                label="Sent"
                                value={
                                    status.dispatch.sent_at
                                        ? formatDateTime(
                                              status.dispatch.sent_at,
                                          )
                                        : 'Not confirmed'
                                }
                            />
                            <ReviewRow
                                label="Pharmacy reference"
                                value={status.dispatch.supplier_reference}
                            />
                            {status.dispatch.acknowledgment_applied ===
                                false && (
                                <SettingsNotice>
                                    Pharmacy evidence was retained, but it could
                                    not update this supply record. Review the
                                    current order and house access.
                                </SettingsNotice>
                            )}
                        </>
                    )}
                    {status.can_send && (
                        <>
                            <RecordPicker
                                label="Pharmacy connection"
                                value={connection}
                                options={status.connections.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                }))}
                                onChange={setConnection}
                            />
                            <Button
                                disabled={
                                    !connection ||
                                    command.busy ||
                                    command.uncertain
                                }
                                onClick={() => startAction('send')}
                            >
                                Review and send order
                            </Button>
                        </>
                    )}
                    {status.can_resolve_unknown && (
                        <>
                            <SettingsNotice>
                                The delivery result is uncertain. Contact the
                                pharmacy before considering another send.
                            </SettingsNotice>
                            <Field label="Pharmacy check reference" required>
                                <Input
                                    value={reference}
                                    onChange={(e) =>
                                        setReference(e.target.value)
                                    }
                                />
                            </Field>
                            <Toggle
                                label="I checked with the pharmacy and they confirmed they did not receive this order"
                                checked={confirmed}
                                onChange={setConfirmed}
                            />
                            <Button
                                variant="outline"
                                disabled={
                                    !reference ||
                                    !confirmed ||
                                    command.busy ||
                                    command.uncertain
                                }
                                onClick={() => startAction('resolve')}
                            >
                                Record pharmacy check
                            </Button>
                        </>
                    )}
                    <div className="flex gap-2">
                        {status.can_retry && (
                            <Button
                                disabled={command.busy || command.uncertain}
                                onClick={() => startAction('retry')}
                            >
                                Review retry
                            </Button>
                        )}
                        {status.can_cancel && (
                            <Button
                                variant="outline"
                                disabled={command.busy || command.uncertain}
                                onClick={() => startAction('cancel')}
                            >
                                Stop sending
                            </Button>
                        )}
                    </div>
                    {status.dispatch &&
                        ['sending', 'sent', 'accepted', 'unknown'].includes(
                            status.dispatch.state,
                        ) && (
                            <SettingsNotice>
                                Closing our supply record does not cancel the
                                order at the pharmacy. Contact the pharmacy
                                separately to cancel their order. Count and
                                record received stock through the normal receipt
                                workflow.
                            </SettingsNotice>
                        )}
                </div>
            )}
            {command.error && (
                <SettingsNotice role="alert">{command.error}</SettingsNotice>
            )}
            <ConfirmDialog
                open={!!action}
                onClose={() => !command.busy && setAction(null)}
                onConfirm={() => void run()}
                processing={command.busy}
                variant="default"
                title={
                    action === 'send'
                        ? 'Send this pharmacy order?'
                        : action === 'retry'
                          ? 'Retry this delivery?'
                          : action === 'cancel'
                            ? 'Stop further sending?'
                            : 'Record confirmed non-receipt?'
                }
                description={
                    action === 'send'
                        ? 'The selected pharmacy will receive this person’s identity, medicine and supply details. This does not record stock receipt.'
                        : action === 'retry'
                          ? 'Retry the same dispatch using its original delivery identifier. The current order and permissions will be checked again.'
                          : action === 'cancel'
                            ? 'This stops further sending or retries from this record. Earlier delivery attempts stay in its history. Contact the pharmacy separately to cancel an order they received.'
                            : 'This records the pharmacy’s confirmed non-receipt. It does not send the order again.'
                }
                confirmText={
                    action === 'send'
                        ? 'Send order'
                        : action === 'retry'
                          ? 'Retry delivery'
                          : action === 'cancel'
                            ? 'Stop delivery'
                            : 'Save check'
                }
            />
        </ReviewCard>
    );
}
