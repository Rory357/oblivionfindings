import { Briefcase, Moon, Phone, Plane, Split } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export type ShiftTypeKey =
    | 'standard'
    | 'sleepover'
    | 'on_call'
    | 'split'
    | 'travel';

export type ShiftTypeAccent = 'primary' | 'info' | 'warning' | 'success';

export type ShiftTypeMeta = {
    key: ShiftTypeKey;
    label: string;
    description: string;
    icon: LucideIcon;
    accent: ShiftTypeAccent;
};

export const SHIFT_TYPES: readonly ShiftTypeMeta[] = [
    {
        key: 'standard',
        label: 'Standard',
        description: 'Regular rostered shift.',
        icon: Briefcase,
        accent: 'primary',
    },
    {
        key: 'sleepover',
        label: 'Sleepover',
        description: 'Overnight cover.',
        icon: Moon,
        accent: 'info',
    },
    {
        key: 'on_call',
        label: 'On-call',
        description: 'Paid on activation.',
        icon: Phone,
        accent: 'warning',
    },
    {
        key: 'split',
        label: 'Split',
        description: 'Two blocks, same day.',
        icon: Split,
        accent: 'info',
    },
    {
        key: 'travel',
        label: 'Travel',
        description: 'Travel-only between visits.',
        icon: Plane,
        accent: 'success',
    },
] as const;

export const SHIFT_TYPE_BY_KEY: Record<ShiftTypeKey, ShiftTypeMeta> =
    SHIFT_TYPES.reduce(
        (acc, t) => {
            acc[t.key] = t;
            return acc;
        },
        {} as Record<ShiftTypeKey, ShiftTypeMeta>,
    );

export function shiftTypeMeta(key: string | null | undefined): ShiftTypeMeta {
    return SHIFT_TYPE_BY_KEY[(key ?? 'standard') as ShiftTypeKey] ?? SHIFT_TYPE_BY_KEY.standard;
}

export const SHIFT_TYPE_ACCENT_CLASSES: Record<
    ShiftTypeAccent,
    { fg: string; bg: string; ring: string }
> = {
    primary: {
        fg: 'text-primary',
        bg: 'bg-primary/10',
        ring: 'ring-primary/20',
    },
    info: {
        fg: 'text-status-info',
        bg: 'bg-status-info-bg',
        ring: 'ring-status-info/20',
    },
    warning: {
        fg: 'text-status-warning',
        bg: 'bg-status-warning-bg',
        ring: 'ring-status-warning/20',
    },
    success: {
        fg: 'text-status-success',
        bg: 'bg-status-success-bg',
        ring: 'ring-status-success/20',
    },
};
