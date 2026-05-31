import {
    AlertTriangle,
    Check,
    Layers,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { TYPE_META, type QueueItem } from './types';

export type ConflictConfirmKind =
    | 'acknowledge'
    | 'cancel'
    | 'ratio'
    | 'dismiss';

export interface ConflictConfirmResult {
    note?: string;
    reason?: string;
    ratio?: '1:1' | '2:1';
}

type DialogConfig = {
    title: string;
    icon: LucideIcon;
    tile: string;
    confirmLabel: string;
    danger: boolean;
};

const CONFIG: Record<ConflictConfirmKind, DialogConfig> = {
    acknowledge: {
        title: 'Keep both — acknowledge',
        icon: Check,
        tile: 'bg-status-warning-bg text-status-warning',
        confirmLabel: 'Acknowledge & keep',
        danger: false,
    },
    cancel: {
        title: 'Cancel approved leave',
        icon: AlertTriangle,
        tile: 'bg-status-critical-bg text-status-critical',
        confirmLabel: 'Cancel leave',
        danger: true,
    },
    ratio: {
        title: 'Adjust staffing ratio',
        icon: Users,
        tile: 'bg-status-warning-bg text-status-warning',
        confirmLabel: 'Update ratio',
        danger: false,
    },
    dismiss: {
        title: 'Dismiss coverage gap',
        icon: Layers,
        tile: 'bg-status-warning-bg text-status-warning',
        confirmLabel: 'Dismiss gap',
        danger: false,
    },
};

export interface ConflictConfirmDialogProps {
    open: boolean;
    kind: ConflictConfirmKind;
    item: QueueItem | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (result: ConflictConfirmResult) => void;
}

export function ConflictConfirmDialog({
    open,
    kind,
    item,
    onOpenChange,
    onConfirm,
}: ConflictConfirmDialogProps) {
    const [reason, setReason] = useState('');
    const [ratio, setRatio] = useState<'1:1' | '2:1'>('1:1');

    useEffect(() => {
        if (open) {
            setReason('');
            setRatio('1:1');
        }
    }, [open, kind, item?.id]);

    if (!item) return null;

    const config = CONFIG[kind];
    const Icon = config.icon;
    const trimmed = reason.trim();
    const canConfirm =
        kind === 'cancel'
            ? trimmed.length > 0
            : kind === 'dismiss'
              ? trimmed.length > 0
              : kind === 'ratio'
                ? ratio === '2:1'
                    ? trimmed.length > 0
                    : true
                : true;

    const submit = () => {
        if (!canConfirm) return;
        if (kind === 'acknowledge') onConfirm({ note: trimmed || undefined });
        else if (kind === 'cancel') onConfirm({ reason: trimmed });
        else if (kind === 'dismiss') onConfirm({ reason: trimmed });
        else if (kind === 'ratio')
            onConfirm({ ratio, reason: ratio === '2:1' ? trimmed : undefined });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2.5">
                        <span
                            className={cn(
                                'flex h-9 w-9 items-center justify-center rounded-xl',
                                config.tile,
                            )}
                        >
                            <Icon className="h-[18px] w-[18px]" />
                        </span>
                        {config.title}
                    </DialogTitle>
                    <DialogDescription>
                        {TYPE_META[item.type].label} · {item.who}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3 py-1">
                    {kind === 'acknowledge' ? (
                        <>
                            <div className="rounded-lg bg-status-warning-bg px-3 py-2 text-[12.5px] text-status-warning">
                                Both shifts stay on the roster and the conflict
                                is marked acknowledged.
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="conflict-note">
                                    Note{' '}
                                    <span className="text-muted-foreground">
                                        (optional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="conflict-note"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    rows={3}
                                    maxLength={2000}
                                    placeholder="Why are you keeping both shifts?"
                                />
                            </div>
                        </>
                    ) : null}

                    {kind === 'cancel' ? (
                        <>
                            <div className="rounded-lg bg-status-critical-bg px-3 py-2 text-[12.5px] text-status-critical">
                                This notifies {item.who} and keeps the rostered
                                shift in place. Approved leave will be
                                withdrawn.
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="conflict-reason">
                                    Reason{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Textarea
                                    id="conflict-reason"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    rows={3}
                                    maxLength={2000}
                                    placeholder="Reason for cancelling approved leave"
                                />
                            </div>
                        </>
                    ) : null}

                    {kind === 'dismiss' ? (
                        <>
                            <div className="rounded-lg bg-status-warning-bg px-3 py-2 text-[12.5px] text-status-warning">
                                Hides this gap from the queue. Add a reason for
                                the audit trail.
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="conflict-reason">
                                    Reason{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Textarea
                                    id="conflict-reason"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    rows={3}
                                    maxLength={2000}
                                    placeholder="Why is this gap being dismissed?"
                                />
                            </div>
                        </>
                    ) : null}

                    {kind === 'ratio' ? (
                        <>
                            <div className="rounded-lg bg-[color-mix(in_oklch,var(--primary)_6%,transparent)] px-3 py-2 text-[12.5px] text-foreground">
                                {item.recommended}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Funded staffing ratio</Label>
                                <div className="space-y-1.5">
                                    <RatioOption
                                        active={ratio === '1:1'}
                                        onClick={() => setRatio('1:1')}
                                        label="1:1 — keep one shift, drop the overlap"
                                    />
                                    <RatioOption
                                        active={ratio === '2:1'}
                                        onClick={() => setRatio('2:1')}
                                        label="2:1 — keep both (needs funding exception)"
                                    />
                                </div>
                            </div>
                            {ratio === '2:1' ? (
                                <div className="space-y-1.5">
                                    <Label htmlFor="conflict-reason">
                                        Exception reason{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Textarea
                                        id="conflict-reason"
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        rows={3}
                                        maxLength={2000}
                                        placeholder="Why is a 2:1 ratio approved for this window?"
                                    />
                                </div>
                            ) : null}
                        </>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant={config.danger ? 'destructive' : 'default'}
                        disabled={!canConfirm}
                        onClick={submit}
                    >
                        {!config.danger ? (
                            <Check className="mr-1.5 h-4 w-4" />
                        ) : null}
                        {config.confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RatioOption({
    active,
    onClick,
    label,
}: {
    active: boolean;
    onClick: () => void;
    label: string;
}) {
    return (
        <Button
            type="button"
            variant="outline"
            onClick={onClick}
            className={cn(
                'h-auto w-full justify-start px-3 py-2 text-left text-[13px] font-normal whitespace-normal',
                active &&
                    'border-primary bg-[color-mix(in_oklch,var(--primary)_8%,transparent)]',
            )}
            aria-pressed={active}
        >
            <span
                className={cn(
                    'mr-2 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border',
                    active ? 'border-primary' : 'border-muted-foreground/40',
                )}
            >
                {active ? (
                    <span className="h-2 w-2 rounded-full bg-primary" />
                ) : null}
            </span>
            {label}
        </Button>
    );
}

export default ConflictConfirmDialog;
