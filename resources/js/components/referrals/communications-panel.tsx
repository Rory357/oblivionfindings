import {
    EntityCard,
    EntityCardGrid,
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTimeLong } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    Ban,
    Eye,
    Mail,
    MessageSquareText,
    RefreshCw,
    RotateCcw,
    Send,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { channelLabels } from './message-templates';
import { SendMessageDialog } from './send-message-dialog';
import { Nested, NestedRow } from './settings-ui';
import { referralHref, type Page, type ReferralDetail } from './types';

type Communication = {
    id: number;
    kind: string;
    state: string;
    attempts: number;
    recipient: string | null;
    /** Staff messages: email or sms. Notices: email or in_app. */
    channel?: string;
    template?: string | null;
    subject?: string | null;
    body?: string | null;
    edited?: boolean;
    sender_name?: string | null;
    /** The recipient's role: The person, Referrer, Family or whānau, Professional… */
    recipient_role?: string | null;
    /** Held for the recipient's safe contact time; not sent before then. */
    scheduled_for?: string | null;
    /** A message to someone in People involved, seen without assessment access. */
    restricted?: boolean;
    created_at: string;
    available_at: string;
    sent_at: string | null;
    delivered_at: string | null;
    reason: string | null;
    retry_blocker: string | null;
};
type Projection = {
    communications: Page<Communication>;
    version: number;
    can_manage: boolean;
    /** Send approved templates, and retry or cancel staff messages. */
    can_send?: boolean;
    acknowledgement: { subject: string; body: string; sender: string };
};
const states: Record<string, string> = {
    pending: 'Queued',
    sending: 'Sending',
    sent: 'Accepted by mail service',
    delivered: 'In-app notice delivered',
    failed: 'Needs attention',
    delivery_unknown: 'Send result uncertain',
    cancelled: 'Cancelled',
};
/** Staff messages show the plain outcome: queued, sent, failed with its reason, or cancelled. */
const messageStates: Record<string, string> = {
    pending: 'Queued',
    sending: 'Sending',
    sent: 'Sent',
    failed: 'Failed',
    delivery_unknown: 'Send result uncertain',
    cancelled: 'Cancelled',
};
const tones: Record<string, StatusVariant> = {
    pending: 'info',
    sending: 'info',
    sent: 'success',
    delivered: 'success',
    failed: 'critical',
    delivery_unknown: 'warning',
    cancelled: 'neutral',
};
const kinds: Record<string, string> = {
    public_acknowledgement: 'Enquiry acknowledgement',
    intake_received: 'Intake staff notice',
    handover_committed: 'Onboarding handover notice',
    staff_message: 'Message',
};
const reasons: Record<string, string> = {
    transport_not_configured:
        'Email delivery needs a configured sender and SMTP connection. Ask an authorised administrator to finish setup.',
    contact_not_permitted:
        'Current contact permission is missing or the referral is closed.',
    safe_contact_changed:
        'The saved email or preferred channel changed. Do not send the old acknowledgement; review safe contact with the person.',
    safe_time_needs_review:
        'A safe contact time was requested. Arrange contact through the existing follow-up workflow; automatic email cannot interpret this preference.',
    acknowledgements_disabled:
        'Acknowledgements are disabled in the current published settings.',
    worker_interrupted:
        'Processing was interrupted after the send was claimed. Check the mail service before retrying.',
    transport_result_unknown:
        'The mail or text service result could not be confirmed. Check its records before retrying to avoid another copy.',
    transport_suppressed: 'The mail service did not accept the message.',
    no_eligible_recipient:
        'No current staff member can receive this intake notice. Review assignment and approved access.',
    referral_closed: 'The referral was closed before this was sent.',
    handover_no_longer_open:
        'The onboarding handover no longer needs this notice.',
    no_eligible_handover_owner:
        'The current onboarding owner cannot receive this handover notice. Review their assignment and approved access.',
    settings_not_published:
        'Referral settings are not published, so there is no approved wording to send.',
    no_contact: 'No contact details were recorded with this referral.',
    person_contact_missing:
        'Only the referrer’s contact details were given, so the person can’t be messaged directly.',
    no_separate_referrer:
        'They contacted us themselves, so there is no separate referrer.',
    messages_not_permitted:
        'They haven’t agreed to messages. Call them and log the contact instead.',
    email_not_preferred:
        'Their safe way to be contacted is now by phone, so the email was not sent.',
    sms_not_preferred:
        'Their safe way to be contacted is now by email, so the text was not sent.',
    email_missing: 'No valid email address is recorded.',
    mobile_missing: 'No phone number is recorded.',
    not_mobile:
        'The number doesn’t look like a mobile, so it can’t receive texts.',
    email_not_configured:
        'Email sending isn’t set up. Ask your system administrator.',
    sms_not_configured:
        'Text messaging isn’t set up. Ask your system administrator.',
    recipient_changed:
        'Their contact details or preferences changed after this was queued. Check them before sending a new message.',
    sender_access_changed:
        'The person who queued this can no longer send messages for this referral.',
    document_request_unavailable:
        'The secure document request was received, revoked or has expired. Create a new request and message.',
    sms_rejected:
        'The text service didn’t accept the message. Check the number with the person.',
    contact_unavailable:
        'This contact was withdrawn or archived, so the message was not sent.',
    contact_review_needed:
        'Their contact review isn’t current. Review them in People involved first.',
    contact_permission_missing:
        'Contact with them isn’t permitted. Review their contact permission in People involved.',
    no_direct_contact: 'They asked not to be contacted directly.',
    phone_messages_not_permitted:
        'They haven’t agreed to messages being left on their phone, so the text was not sent.',
    safe_time_changed:
        'Their safe contact time changed after this was queued. Check it and send a new message at a time that suits.',
};
const explanation = (code: string | null) =>
    code
        ? (reasons[code] ?? 'This communication needs an authorised review.')
        : '';
const isMessage = (row: Communication) => row.kind === 'staff_message';
const stateLabel = (row: Communication) =>
    isMessage(row) && row.state === 'pending' && row.scheduled_for
        ? 'Scheduled'
        : ((isMessage(row) ? messageStates : states)[row.state] ?? row.state);
const channelName = (row: Communication) =>
    row.channel === 'sms' ? channelLabels.sms : channelLabels.email;

export function CommunicationsPanel({
    referral,
}: {
    referral: ReferralDetail;
}) {
    const [data, setData] = useState<Projection | null>(null);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [selection, setSelection] = useState<{
        row: Communication;
        operation: 'retry' | 'cancel';
    } | null>(null);
    const [viewing, setViewing] = useState<Communication | null>(null);
    const [composing, setComposing] = useState(false);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setData(null);
        setSelection(null);
        setError('');
        axios
            .get<Projection>(
                `${referralHref(referral.reference)}/communications`,
                { params: { page }, signal: controller.signal },
            )
            .then(({ data }) => {
                if (!controller.signal.aborted) setData(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'Communications could not be loaded. Refresh to check current access.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [referral.reference, referral.version, page, revision]);
    const menu = useEntityContextMenu<Communication>();
    const recipientFor = (row: Communication) =>
        isMessage(row)
            ? `${channelName(row)} to ${row.recipient ?? 'the saved contact'}${row.recipient_role && !row.restricted ? ` (${row.recipient_role})` : ''}`
            : (row.recipient ??
              (row.kind === 'handover_committed'
                  ? 'Current eligible onboarding owner'
                  : 'Eligible staff in the current intake scope'));
    const canAct = (row: Communication) =>
        !!data?.can_manage || (isMessage(row) && !!data?.can_send);
    /** One MenuItem[] feeds each record's kebab and right-click menu. */
    const rowActions = (row: Communication): MenuItem[] => {
        const view: MenuItem[] = isMessage(row)
            ? [
                  {
                      label: 'View message',
                      icon: Eye,
                      onClick: () => setViewing(row),
                  },
              ]
            : [];
        if (!canAct(row)) return view;
        const queued = ['pending', 'failed', 'delivery_unknown'].includes(
            row.state,
        );
        return [
            ...view,
            {
                label: 'Review retry',
                icon: RotateCcw,
                onClick: () => setSelection({ row, operation: 'retry' }),
                disabled: !queued
                    ? 'Only an undelivered communication can be retried'
                    : row.state === 'pending'
                      ? row.scheduled_for
                          ? 'Scheduled; it is sent at the chosen time'
                          : 'Still queued; wait for the next delivery check'
                      : undefined,
            },
            {
                label: isMessage(row)
                    ? 'Cancel queued message'
                    : 'Cancel queued communication',
                icon: Ban,
                danger: true,
                onClick: () => setSelection({ row, operation: 'cancel' }),
                disabled: queued
                    ? undefined
                    : 'This communication is no longer queued',
            },
        ];
    };
    const iconFor = (row: Communication) =>
        isMessage(row) && row.channel === 'sms' ? MessageSquareText : Mail;
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-section-title">Communications</h2>
                    <p className="text-subtle">
                        Delivery failures do not mean the referral was lost.
                        Automatic notices and sent messages never count as a
                        human conversation.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        disabled={loading || !!selection}
                        onClick={() => setRevision((value) => value + 1)}
                    >
                        <RefreshCw className="size-4" />
                        Refresh status
                    </Button>
                    {data?.can_send && (
                        <Button
                            disabled={!!selection}
                            onClick={() => setComposing(true)}
                        >
                            <Send className="size-4" />
                            Send a message
                        </Button>
                    )}
                </div>
            </div>
            {loading && (
                <div role="status">
                    <LoadingState message="Checking communication status…" />
                </div>
            )}
            {error && (
                <ErrorState
                    title="Communications unavailable"
                    message={error}
                    onRetry={() => setRevision((value) => value + 1)}
                />
            )}
            {data && (
                <>
                    {!data.communications.data.length && (
                        <EmptyState
                            icon={Mail}
                            title="No messages or notices yet"
                            description={
                                data.can_send
                                    ? 'Send a message with an approved template. Contact attempts and conversations are recorded in Activity.'
                                    : 'Contact attempts and conversations are recorded in Activity. These delivery records are separate.'
                            }
                        />
                    )}
                    {data.communications.data.length > 0 && (
                        <EntityCardGrid>
                            {data.communications.data.map((row) => (
                                <EntityCard
                                    key={row.id}
                                    name={
                                        isMessage(row)
                                            ? (row.template ??
                                              kinds.staff_message)
                                            : (kinds[row.kind] ??
                                              'Communication')
                                    }
                                    icon={iconFor(row)}
                                    meridian={
                                        row.state === 'failed'
                                            ? 'critical'
                                            : row.state === 'delivery_unknown'
                                              ? 'warning'
                                              : row.state === 'sent' ||
                                                  row.state === 'delivered'
                                                ? 'success'
                                                : 'neutral'
                                    }
                                    subline={recipientFor(row)}
                                    actions={rowActions(row)}
                                    onOpen={
                                        isMessage(row)
                                            ? () => setViewing(row)
                                            : undefined
                                    }
                                    onContextMenu={(event) =>
                                        menu.open(event, row)
                                    }
                                    chips={
                                        <StatusBadge
                                            variant={
                                                tones[row.state] ?? 'neutral'
                                            }
                                        >
                                            {stateLabel(row)}
                                        </StatusBadge>
                                    }
                                    alerts={
                                        row.reason ||
                                        row.state === 'sent' ||
                                        row.state === 'pending' ? (
                                            <p className="text-subtle">
                                                {row.reason
                                                    ? explanation(row.reason)
                                                    : row.state === 'sent'
                                                      ? isMessage(row)
                                                          ? `Accepted by the ${row.channel === 'sms' ? 'text' : 'mail'} service. Delivery and reading are not confirmed.`
                                                          : 'Recipient delivery and reading are not confirmed.'
                                                      : row.scheduled_for
                                                        ? `Scheduled for ${formatDateTimeLong(row.scheduled_for)} NZ time, to suit their safe contact time. It can be cancelled until then.`
                                                        : `Next check ${formatDateTimeLong(row.available_at)} · ${row.attempts} processing attempts`}
                                            </p>
                                        ) : undefined
                                    }
                                    footer={{
                                        personIcon: isMessage(row)
                                            ? Send
                                            : Mail,
                                        primary: isMessage(row)
                                            ? `Queued by ${row.sender_name ?? 'a former colleague'} · ${formatDateTimeLong(row.created_at)}`
                                            : `Queued ${formatDateTimeLong(row.created_at)}`,
                                        secondary: row.delivered_at
                                            ? `In-app delivery ${formatDateTimeLong(row.delivered_at)}`
                                            : row.sent_at
                                              ? `${isMessage(row) ? 'Sent' : 'Accepted by mail service'} ${formatDateTimeLong(row.sent_at)}`
                                              : 'Not sent yet',
                                    }}
                                />
                            ))}
                        </EntityCardGrid>
                    )}
                    {data.communications.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3">
                            <Button
                                variant="outline"
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Previous
                            </Button>
                            <span className="text-caption">
                                Page {page} of {data.communications.last_page}
                            </span>
                            <Button
                                variant="outline"
                                disabled={page >= data.communications.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </>
            )}
            {menu.ctx && data && rowActions(menu.ctx.record).length ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={iconFor(menu.ctx.record)}
                    title={
                        isMessage(menu.ctx.record)
                            ? (menu.ctx.record.template ?? kinds.staff_message)
                            : (kinds[menu.ctx.record.kind] ?? 'Communication')
                    }
                    items={rowActions(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
            {viewing && (
                <MessageDialog row={viewing} onClose={() => setViewing(null)} />
            )}
            {composing && (
                <SendMessageDialog
                    referral={referral}
                    onClose={() => {
                        setComposing(false);
                        setRevision((value) => value + 1);
                    }}
                    onQueued={() =>
                        router.reload({
                            only: ['referral'],
                            preserveScroll: true,
                        })
                    }
                    onDenied={() => {
                        setComposing(false);
                        setData(null);
                        setError(
                            'Current access no longer allows sending messages for this referral. Private details have been hidden.',
                        );
                    }}
                />
            )}
            {selection && data && (
                <RecoveryDialog
                    key={`${selection.row.id}:${selection.operation}`}
                    projection={data}
                    selection={selection}
                    reference={referral.reference}
                    onClose={() => setSelection(null)}
                    onSaved={() => {
                        setSelection(null);
                        setRevision((value) => value + 1);
                        router.reload({
                            only: ['referral'],
                            preserveScroll: true,
                        });
                    }}
                    onDenied={() => {
                        setSelection(null);
                        setData(null);
                        setError(
                            'Current access no longer allows this communication. Private details have been hidden.',
                        );
                    }}
                />
            )}
        </div>
    );
}

/** A staff message as it was queued: who, how, the wording and its outcome. */
function MessageDialog({
    row,
    onClose,
}: {
    row: Communication;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[88vh] overflow-y-auto"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {row.template ?? kinds.staff_message}
                    </DialogTitle>
                    <DialogDescription>
                        {`${channelName(row)} · ${stateLabel(row)}`}
                        {row.reason ? ` · ${explanation(row.reason)}` : ''}
                    </DialogDescription>
                </DialogHeader>
                <Nested>
                    <NestedRow label="To" value={row.recipient} />
                    <NestedRow label="Their role" value={row.recipient_role} />
                    <NestedRow
                        label="Queued by"
                        value={`${row.sender_name ?? 'A former colleague'} · ${formatDateTimeLong(row.created_at)}`}
                    />
                    <NestedRow
                        label="Sent"
                        value={
                            row.sent_at
                                ? formatDateTimeLong(row.sent_at)
                                : row.scheduled_for
                                  ? `Scheduled for ${formatDateTimeLong(row.scheduled_for)} NZ time`
                                  : 'Not sent'
                        }
                    />
                    <NestedRow
                        label="Wording"
                        value={
                            row.edited
                                ? 'Edited for this message'
                                : 'As approved'
                        }
                    />
                    {row.subject && (
                        <NestedRow label="Subject" value={row.subject} />
                    )}
                    <p className="mt-3 rounded-md bg-card p-3 text-sm break-words whitespace-pre-wrap">
                        {row.restricted ? (
                            <span className="text-muted-foreground">
                                Messages to People involved are shown to staff
                                who can see assessment details.
                            </span>
                        ) : (
                            row.body
                        )}
                    </p>
                </Nested>
                <p className="text-caption">
                    Sent means the mail or text service accepted it. Delivery
                    and reading are not confirmed.
                </p>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RecoveryDialog({
    projection,
    selection,
    reference,
    onClose,
    onSaved,
    onDenied,
}: {
    projection: Projection;
    selection: { row: Communication; operation: 'retry' | 'cancel' };
    reference: string;
    onClose: () => void;
    onSaved: () => void;
    onDenied: () => void;
}) {
    const [reason, setReason] = useState('');
    const [acknowledge, setAcknowledge] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [stale, setStale] = useState(false);
    const pending = useRef<Record<string, unknown> | null>(null);
    const active = useRef(false);
    const retry = selection.operation === 'retry';
    const message = isMessage(selection.row);
    const duplicate = retry && selection.row.state === 'delivery_unknown';
    const blocked = retry ? selection.row.retry_blocker : null;
    const leave = useSettingsLeaveConfirmation(
        reason.length > 0 || uncertain || busy,
        'Leave this communication review?',
        'Your notes will be discarded. A submitted recovery may still finish. Review the current status before starting another request.',
        undefined,
        busy,
    );
    const save = async () => {
        if (active.current) return;
        active.current = true;
        setBusy(true);
        setError('');
        pending.current ??= {
            version: projection.version,
            request_key: crypto.randomUUID(),
            operation: selection.operation,
            reason,
            acknowledge_duplicate_risk: acknowledge,
        };
        try {
            await axios.post(
                `${referralHref(reference)}/communications/${selection.row.id}`,
                pending.current,
            );
            onSaved();
        } catch (failure) {
            if (
                axios.isAxiosError(failure) &&
                [401, 403, 404].includes(failure.response?.status ?? 0)
            ) {
                onDenied();
                return;
            }
            if (
                axios.isAxiosError(failure) &&
                [409, 422].includes(failure.response?.status ?? 0)
            ) {
                setStale(true);
                setError(
                    failure.response?.data?.message ??
                        'The record changed. Close this review and refresh before trying again.',
                );
            } else {
                setUncertain(true);
                setError(
                    'The result is uncertain. Retry the exact same request to recover its saved result.',
                );
            }
        } finally {
            active.current = false;
            setBusy(false);
        }
    };
    return (
        <>
            {leave.confirmation}
            <Dialog
                open
                onOpenChange={(open) =>
                    !open && !busy && !uncertain && onClose()
                }
            >
                <DialogContent
                    className="max-h-[88vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {retry
                                ? message
                                    ? 'Review message retry'
                                    : 'Review communication retry'
                                : message
                                  ? 'Cancel this message?'
                                  : 'Cancel this communication?'}
                        </DialogTitle>
                        <DialogDescription>
                            {retry
                                ? 'Check the recipient, current contact permission and previous delivery result before queueing another attempt.'
                                : `This stops unsent processing. It cannot recall a message already accepted by a ${message && selection.row.channel === 'sms' ? 'text' : 'mail'} service.`}
                        </DialogDescription>
                    </DialogHeader>
                    <p className="text-sm break-words">
                        Recipient:{' '}
                        {selection.row.recipient ?? 'Eligible intake staff'}
                    </p>
                    {retry &&
                        selection.row.kind === 'public_acknowledgement' && (
                            <div className="rounded-lg bg-muted p-4 text-sm">
                                <p className="break-words">
                                    From: {projection.acknowledgement.sender}
                                </p>
                                <p className="mt-2 font-medium">
                                    {projection.acknowledgement.subject}
                                </p>
                                <p className="mt-3 whitespace-pre-wrap">
                                    {projection.acknowledgement.body}
                                </p>
                            </div>
                        )}
                    {retry && message && (
                        <div className="rounded-lg bg-muted p-4 text-sm">
                            {selection.row.subject && (
                                <p className="font-medium">
                                    {selection.row.subject}
                                </p>
                            )}
                            <p className="mt-2 whitespace-pre-wrap">
                                {selection.row.body}
                            </p>
                        </div>
                    )}
                    {blocked && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {explanation(blocked)}
                            </AlertDescription>
                        </Alert>
                    )}
                    <Field label="Reason for this decision" required>
                        <Textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            disabled={busy || uncertain || stale}
                            maxLength={1000}
                        />
                    </Field>
                    {duplicate && (
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={acknowledge}
                                onCheckedChange={(checked) =>
                                    setAcknowledge(checked === true)
                                }
                                disabled={busy || uncertain || stale}
                            />
                            <span>
                                I checked the mail service result and understand
                                that retrying may send another copy.
                            </span>
                        </label>
                    )}
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={onClose}
                            disabled={busy || uncertain}
                        >
                            Close
                        </Button>
                        <Button
                            variant={
                                retry || uncertain ? 'default' : 'destructive'
                            }
                            onClick={() => void save()}
                            disabled={
                                busy ||
                                stale ||
                                !!blocked ||
                                (!uncertain &&
                                    (reason.trim().length < 10 ||
                                        (duplicate && !acknowledge)))
                            }
                        >
                            {busy
                                ? 'Saving…'
                                : uncertain
                                  ? 'Retry exact request'
                                  : retry
                                    ? 'Queue reviewed retry'
                                    : message
                                      ? 'Cancel message'
                                      : 'Cancel communication'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
