/* Small shared pieces for the medication board and its wizards: the dose
 * status pill, the CD badge, hue-tinted client avatar chips and the client
 * summary card used at the top of wizard steps. Extracted so the upcoming
 * eMAR page redesigns reuse one idiom. */
import { avatarHueStyle } from '@/components/rostering/avatar-hue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { MapPin } from 'lucide-react';

import {
    clientHue,
    clientInitials,
    type ClientInfo,
    type DoseStatus,
} from '@/pages/meds/today/types';

export const DOSE_STATUS_META: Record<
    DoseStatus,
    { label: string; pillClass: string; tagBg: string; tagColor: string }
> = {
    overdue: {
        label: 'Overdue',
        pillClass:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        tagBg: 'var(--status-critical-bg)',
        tagColor: 'var(--status-critical)',
    },
    due: {
        label: 'Due',
        pillClass:
            'border-status-warning/30 bg-status-warning-bg text-status-warning',
        tagBg: 'var(--status-warning-bg)',
        tagColor: 'var(--status-warning)',
    },
    upcoming: {
        label: 'Later',
        pillClass: 'border-border bg-muted text-foreground',
        tagBg: 'var(--muted)',
        tagColor: 'var(--muted-foreground)',
    },
    given: {
        label: 'Given',
        pillClass:
            'border-status-success/30 bg-status-success-bg text-status-success',
        tagBg: 'var(--status-success-bg)',
        tagColor: 'var(--status-success)',
    },
    refused: {
        label: 'Refused',
        pillClass:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        tagBg: 'var(--status-critical-bg)',
        tagColor: 'var(--status-critical)',
    },
    withheld: {
        label: 'Withheld',
        pillClass: 'border-border bg-muted text-foreground',
        tagBg: 'var(--muted)',
        tagColor: 'var(--muted-foreground)',
    },
    missed: {
        label: 'Missed',
        pillClass:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        tagBg: 'var(--status-critical-bg)',
        tagColor: 'var(--status-critical)',
    },
};

export function StatusPill({
    status,
    className,
}: {
    status: DoseStatus;
    className?: string;
}) {
    const meta = DOSE_STATUS_META[status] ?? DOSE_STATUS_META.upcoming;
    return (
        <span
            className={cn(
                'shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                meta.pillClass,
                className,
            )}
        >
            {meta.label}
        </span>
    );
}

/** Outline "CD" controlled-drug marker (same markup as the mobile board). */
export function CdBadge({ className }: { className?: string }) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'shrink-0 border-primary text-[10px] tracking-wide text-primary uppercase',
                className,
            )}
        >
            CD
        </Badge>
    );
}

/** Hue-tinted initials chip for a client (rostering avatar idiom). */
export function ClientAvatar({
    name,
    clientId,
    className,
}: {
    name: string;
    clientId: number;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'grid shrink-0 place-items-center rounded-full font-semibold',
                className ?? 'h-8 w-8 text-[11px]',
            )}
            style={avatarHueStyle(clientHue(clientId))}
        >
            {clientInitials(name)}
        </span>
    );
}

/** Client identity card used at the top of wizard safety/review steps. */
export function ClientSummaryCard({
    client,
    fallbackName,
}: {
    client: ClientInfo | null | undefined;
    fallbackName: string;
}) {
    const name = client?.name ?? fallbackName;
    return (
        <div className="flex items-center gap-3.5 rounded-lg border border-border bg-muted/40 p-3.5">
            <ClientAvatar
                name={name}
                clientId={client?.id ?? 0}
                className="h-12 w-12 text-sm"
            />
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-bold">{name}</span>
                    {client?.nhi ? (
                        <Badge
                            variant="outline"
                            className="text-[10.5px] tracking-wide uppercase"
                        >
                            NHI {client.nhi}
                        </Badge>
                    ) : null}
                </div>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                    {client?.dob ? (
                        <span>
                            {client.dob}
                            {client.age !== null ? ` · ${client.age} yrs` : ''}
                        </span>
                    ) : null}
                    {client?.dob && client?.site_name ? (
                        <span aria-hidden="true">·</span>
                    ) : null}
                    {client?.site_name ? (
                        <span className="inline-flex items-center gap-1">
                            <MapPin className="h-3 w-3" />
                            {client.site_name}
                        </span>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
