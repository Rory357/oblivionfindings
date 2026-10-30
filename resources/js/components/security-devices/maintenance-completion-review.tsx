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
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateOnly } from '@/lib/datetime';
import type { MaintenanceRow } from '@/pages/security-devices/maintenance';
import { router } from '@inertiajs/react';
import { ClipboardCheck, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
export function MaintenanceCompletionReview({
    row,
    actor,
    onClose,
    onAuthorityDenied,
    expectedComponent = 'security-devices/maintenance',
}: {
    row: MaintenanceRow;
    actor: number;
    onClose: () => void;
    onAuthorityDenied: () => void;
    expectedComponent?:
        | 'security-devices/maintenance'
        | 'security-devices/maintenance-health';
}) {
    const [checked, setChecked] = useState(false),
        [state, setState] = useState<
            'review' | 'pending' | 'rejected' | 'uncertain'
        >('review'),
        [notice, setNotice] = useState('');
    const live = useRef(true),
        sending = useRef(false),
        cancel = useRef<null | (() => void)>(null),
        timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const busy = state === 'pending',
        blocked = busy || state === 'uncertain' || state === 'rejected';
    const guard = useSettingsLeaveConfirmation(
        checked || busy || state === 'uncertain',
        'Discard this completion review?',
        'Your review choice will be cleared. A completion request already sent may still finish. Check the retained work record before retrying. Cancel to keep reviewing.',
    );
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
            cancel.current?.();
            if (timer.current) clearTimeout(timer.current);
        };
    }, []);
    const finish = useCallback(() => {
        sending.current = false;
        if (timer.current) clearTimeout(timer.current);
        timer.current = null;
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                if (!live.current || !sending.current) return;
                const response = event.detail.response;
                const configuredUrl = response.config.url;
                if (
                    response.config.method?.toLowerCase() !== 'post' ||
                    typeof configuredUrl !== 'string'
                )
                    return;
                let target: URL;
                try {
                    target = new URL(configuredUrl, window.location.origin);
                } catch {
                    return;
                }
                if (
                    target.origin !== window.location.origin ||
                    target.pathname !==
                        '/security-devices/maintenance/' + row.id + '/complete'
                )
                    return;
                event.preventDefault();
                finish();
                if ([401, 403, 404].includes(response.status)) {
                    onAuthorityDenied();
                    return;
                }
                setState(
                    response.status === 409 || response.status === 422
                        ? 'rejected'
                        : 'uncertain',
                );
                setNotice(
                    response.status === 409 || response.status === 422
                        ? 'This work changed or the completion was rejected. Refresh the retained record before trying again.'
                        : 'Completion was not confirmed. Check the retained work record before trying again.',
                );
            }),
        [finish, onAuthorityDenied, row.id],
    );
    const close = () => {
        if (!sending.current) guard.request(onClose);
    };
    const submit = () => {
        if (!checked || blocked || sending.current) return;
        sending.current = true;
        setState('pending');
        setNotice('');
        timer.current = setTimeout(() => {
            if (!live.current || !sending.current) return;
            sending.current = false;
            setState('uncertain');
            setNotice(
                'Completion was not confirmed. Check the retained work record before trying again.',
            );
            cancel.current?.();
        }, 30000);

        router.post(
            `/security-devices/maintenance/${row.id}/complete`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onCancelToken: (token) => {
                    if (!live.current) {
                        token.cancel();
                        return;
                    }
                    cancel.current = () => token.cancel();
                },
                onSuccess: (page) => {
                    if (!live.current || !sending.current) return;
                    finish();
                    const props = page.props as {
                        auth?: { user?: { id?: number } };
                        flash?: { success?: unknown };
                    };
                    if (
                        props.auth?.user?.id === actor &&
                        props.flash?.success ===
                            'Maintenance marked as complete.' &&
                        page.component === expectedComponent
                    ) {
                        onClose();
                        return;
                    }
                    setState('uncertain');
                    setNotice(
                        'The response did not confirm this completion. Refresh Maintenance and check the retained work record.',
                    );
                },
                onError: (errors) => {
                    if (!live.current || !sending.current) return;
                    finish();
                    setState('rejected');
                    setNotice(
                        typeof errors.status === 'string'
                            ? errors.status
                            : 'Completion could not be recorded. Keep this review open and check the work record.',
                    );
                },
                onCancel: () => {
                    if (!live.current || !sending.current) return;
                    finish();
                    setState('uncertain');
                    setNotice(
                        'Completion was not confirmed. Check the retained work record before trying again.',
                    );
                },
                onFinish: () => {
                    if (!live.current || !sending.current) return;
                    finish();
                    setState('uncertain');
                    setNotice(
                        'Completion was not confirmed. Check the retained work record before trying again.',
                    );
                },
            },
        );
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-x-hidden overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    onEscapeKeyDown={(event) => {
                        if (busy) event.preventDefault();
                    }}
                    onPointerDownOutside={(event) => {
                        if (busy) event.preventDefault();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Record work completion</DialogTitle>
                        <DialogDescription>
                            This records completed work against the existing
                            device. It does not confirm device health or change
                            monitoring suppression.
                        </DialogDescription>
                    </DialogHeader>
                    <ReviewCard
                        title="Reviewed maintenance work"
                        icon={ClipboardCheck}
                    >
                        <ReviewRow label="Work" value={`#${row.id}`} />
                        <ReviewRow
                            label="Description"
                            value={row.description}
                        />
                        <ReviewRow
                            label="Device"
                            value={`${row.device.name} · #${row.device.id}`}
                        />
                        {expectedComponent ===
                        'security-devices/maintenance' ? (
                            <ReviewRow
                                label="Site"
                                value={row.site?.name ?? 'No Site assigned'}
                            />
                        ) : null}
                        <ReviewRow
                            label="Scheduled"
                            value={formatDateOnly(
                                row.scheduled_for,
                                'Not scheduled',
                            )}
                        />
                        <ReviewRow
                            label="Reference"
                            value={row.vendor_reference ?? 'Not recorded'}
                        />
                    </ReviewCard>
                    <label className="frontline-tap flex items-start gap-3 rounded-lg border p-3">
                        <Checkbox
                            checked={checked}
                            disabled={blocked}
                            onCheckedChange={(value) => {
                                if (!blocked) setChecked(value === true);
                            }}
                        />
                        <span>I have checked that this work is complete.</span>
                    </label>
                    {notice ? (
                        <p
                            role="alert"
                            className="text-sm text-muted-foreground"
                        >
                            {notice}
                        </p>
                    ) : null}
                    {state === 'uncertain' || state === 'rejected' ? (
                        <Button
                            variant="outline"
                            onClick={() =>
                                guard.request(() => {
                                    onClose();
                                    router.reload({
                                        only:
                                            expectedComponent ===
                                            'security-devices/maintenance'
                                                ? ['workspace', 'auth']
                                                : [
                                                      'actor_id',
                                                      'stats',
                                                      'records',
                                                      'attentionDevices',
                                                      'lowBatteryDevices',
                                                      'filters',
                                                      'can',
                                                      'auth',
                                                  ],
                                    });
                                })
                            }
                        >
                            Refresh retained work
                        </Button>
                    ) : null}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={close}
                            disabled={busy}
                        >
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={!checked || blocked}>
                            {busy ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <ClipboardCheck className="size-4" />
                            )}
                            {busy ? 'Recording…' : 'Record completion'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {guard.confirmation}
        </>
    );
}
