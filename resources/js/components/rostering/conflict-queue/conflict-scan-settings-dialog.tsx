import { Check, Settings } from 'lucide-react';
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
import { cn } from '@/lib/utils';

import { TYPE_META } from './types';

const SCAN_SCOPE_TYPES = [
    'staff_overlap',
    'client_overlap',
    'leave_clash',
    'tight_turnaround',
    'coverage_gap',
] as const;

type ScanScopeType = (typeof SCAN_SCOPE_TYPES)[number];

export interface ScanSettings {
    gap: string;
    nights: string;
    flagRatio: boolean;
    requireReason: boolean;
    scope: Record<ScanScopeType, boolean>;
    autoScan: boolean;
    interval: string;
}

const DEFAULT_SETTINGS: ScanSettings = {
    gap: '45',
    nights: '3',
    flagRatio: true,
    requireReason: true,
    scope: {
        staff_overlap: true,
        client_overlap: true,
        leave_clash: true,
        tight_turnaround: true,
        coverage_gap: true,
    },
    autoScan: true,
    interval: '15',
};

function Switch({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: (next: boolean) => void;
    label: string;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- ARIA switch toggle; a shadcn Button is not a role="switch" control.
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative h-5 w-9 shrink-0 rounded-full transition-colors',
                checked ? 'bg-primary' : 'bg-muted-foreground/30',
            )}
        >
            <span
                className={cn(
                    'absolute top-0.5 left-0.5 h-4 w-4 rounded-full bg-white transition-transform',
                    checked && 'translate-x-4',
                )}
            />
        </button>
    );
}

const SELECT_CLASS =
    'rounded-md border border-input bg-background px-2 py-1.5 text-sm focus:border-primary focus:outline-none';

export interface ConflictScanSettingsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSave: (settings: ScanSettings) => void;
}

export function ConflictScanSettingsDialog({
    open,
    onOpenChange,
    onSave,
}: ConflictScanSettingsDialogProps) {
    const [settings, setSettings] = useState<ScanSettings>(DEFAULT_SETTINGS);

    useEffect(() => {
        if (open) setSettings(DEFAULT_SETTINGS);
    }, [open]);

    const set = <K extends keyof ScanSettings>(
        key: K,
        value: ScanSettings[K],
    ) => setSettings((prev) => ({ ...prev, [key]: value }));
    const toggleScope = (key: ScanScopeType) =>
        setSettings((prev) => ({
            ...prev,
            scope: { ...prev.scope, [key]: !prev.scope[key] },
        }));
    const scopeOn = Object.values(settings.scope).filter(Boolean).length;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-status-info-bg text-status-info">
                            <Settings className="h-[18px] w-[18px]" />
                        </span>
                        Scan settings
                    </DialogTitle>
                    <DialogDescription>
                        How the conflict scan runs for this week
                    </DialogDescription>
                </DialogHeader>

                <div className="max-h-[60vh] space-y-4 overflow-y-auto py-1">
                    <section className="space-y-2">
                        <h3 className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Detection thresholds
                        </h3>
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <div className="text-sm font-medium">
                                    Tight turnaround minimum gap
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Flag back-to-back shifts closer than this
                                </div>
                            </div>
                            <select
                                className={SELECT_CLASS}
                                value={settings.gap}
                                onChange={(event) =>
                                    set('gap', event.target.value)
                                }
                            >
                                <option value="30">30 min</option>
                                <option value="45">45 min</option>
                                <option value="60">60 min</option>
                                <option value="90">90 min</option>
                            </select>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <div className="text-sm font-medium">
                                    Max consecutive night shifts
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Raise a fatigue conflict beyond this
                                </div>
                            </div>
                            <select
                                className={SELECT_CLASS}
                                value={settings.nights}
                                onChange={(event) =>
                                    set('nights', event.target.value)
                                }
                            >
                                <option value="2">2 nights</option>
                                <option value="3">3 nights</option>
                                <option value="4">4 nights</option>
                            </select>
                        </div>
                    </section>

                    <section className="space-y-2">
                        <h3 className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Policies
                        </h3>
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <div className="text-sm font-medium">
                                    Flag 1:1 client overlaps
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Warn when two staff cover a 1:1 client
                                </div>
                            </div>
                            <Switch
                                checked={settings.flagRatio}
                                onChange={(value) => set('flagRatio', value)}
                                label="Flag 1:1 client overlaps"
                            />
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <div className="text-sm font-medium">
                                    Require a reason to keep conflicts
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Acknowledging asks for a note
                                </div>
                            </div>
                            <Switch
                                checked={settings.requireReason}
                                onChange={(value) =>
                                    set('requireReason', value)
                                }
                                label="Require a reason to keep conflicts"
                            />
                        </div>
                    </section>

                    <section className="space-y-2">
                        <h3 className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Scan scope · {scopeOn} of {SCAN_SCOPE_TYPES.length}
                        </h3>
                        <div className="grid grid-cols-2 gap-1.5">
                            {SCAN_SCOPE_TYPES.map((type) => (
                                <Label
                                    key={type}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2 rounded-lg border px-2.5 py-2 text-[13px]',
                                        settings.scope[type]
                                            ? 'border-primary/40 bg-[color-mix(in_oklch,var(--primary)_6%,transparent)]'
                                            : 'border-border',
                                    )}
                                >
                                    <input
                                        type="checkbox"
                                        className="accent-primary"
                                        checked={settings.scope[type]}
                                        onChange={() => toggleScope(type)}
                                    />
                                    {TYPE_META[type].short}
                                </Label>
                            ))}
                        </div>
                    </section>

                    <section className="space-y-2">
                        <h3 className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Live scan
                        </h3>
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <div className="text-sm font-medium">
                                    Auto re-scan
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Re-run as the roster changes
                                </div>
                            </div>
                            <Switch
                                checked={settings.autoScan}
                                onChange={(value) => set('autoScan', value)}
                                label="Auto re-scan"
                            />
                        </div>
                        {settings.autoScan ? (
                            <div className="flex items-center justify-between gap-3">
                                <div className="text-sm font-medium">
                                    Re-scan interval
                                </div>
                                <select
                                    className={SELECT_CLASS}
                                    value={settings.interval}
                                    onChange={(event) =>
                                        set('interval', event.target.value)
                                    }
                                >
                                    <option value="5">Every 5 min</option>
                                    <option value="15">Every 15 min</option>
                                    <option value="30">Every 30 min</option>
                                </select>
                            </div>
                        ) : null}
                    </section>
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button onClick={() => onSave(settings)}>
                        <Check className="mr-1.5 h-4 w-4" />
                        Save settings
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default ConflictScanSettingsDialog;
