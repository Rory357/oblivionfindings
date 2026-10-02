/* Small shared pieces for the medication board and its wizards: the dose
 * status pill, the CD badge, hue-tinted client avatar chips and the client
 * summary card used at the top of wizard steps. Extracted so the upcoming
 * eMAR page redesigns reuse one idiom. */
import { avatarHueStyle } from '@/components/rostering/avatar-hue';
import { Badge } from '@/components/ui/badge';
import { InfoCard } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { AlertTriangle, MapPin } from 'lucide-react';

import {
    clientHue,
    clientInitials,
    type ClientInfo,
    type CompetencyNotice,
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
        label: 'Missed (recorded)',
        pillClass:
            'border-status-critical/30 bg-status-critical-bg text-status-critical',
        tagBg: 'var(--status-critical-bg)',
        tagColor: 'var(--status-critical)',
    },
    pending_check: {
        label: 'Waiting for the order check',
        pillClass: 'border-status-info/30 bg-status-info-bg text-status-info',
        tagBg: 'var(--status-info-bg)',
        tagColor: 'var(--status-info)',
    },
    away: {
        label: 'Away',
        pillClass: 'border-status-info/30 bg-status-info-bg text-status-info',
        tagBg: 'var(--status-info-bg)',
        tagColor: 'var(--status-info)',
    },
};

/**
 * A dose status as words: "Away · Respite at another house (since Mon 15 Jun, 7:00 am)" when the
 * person is away (the reason is always shown), else the status label.
 */
export function doseStatusLabel(
    status: DoseStatus,
    awayReason?: string | null,
): string {
    const label = (DOSE_STATUS_META[status] ?? DOSE_STATUS_META.upcoming)
        .label;
    return status === 'away' && awayReason ? `${label} · ${awayReason}` : label;
}

/**
 * EM-12: a dose list tells a reader without controlled-medicine access how
 * many controlled doses it leaves out, and how many of those are overdue —
 * naming none — so it reconciles with the overdue badge, which counts them.
 * Null when nothing is left out.
 */
export function hiddenControlledCaption(
    count: number,
    overdue = 0,
): string | null {
    if (count <= 0) return null;
    const lead =
        count === 1
            ? '1 more controlled-medicine dose isn’t shown'
            : `${count} more controlled-medicine doses aren’t shown`;
    const late = overdue > 0 ? ` (${overdue} overdue)` : '';
    return `${lead}${late} — needs controlled-medicine access.`;
}

export function StatusPill({
    status,
    awayReason,
    className,
}: {
    status: DoseStatus;
    /** Shown after "Away · " when the person is away. */
    awayReason?: string | null;
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
            {doseStatusLabel(status, awayReason)}
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

/**
 * Allergy notice for a recording wizard. Reads the medication allergy
 * register and the health profile (server-side). An empty or unreadable
 * record is never shown as a confirmed "no known allergies".
 */
export function ClientAllergyNotice({
    client,
    fallbackName,
}: {
    client: ClientInfo | null | undefined;
    fallbackName: string;
}) {
    const name = client?.preferred ?? client?.name ?? fallbackName;

    if (client && client.allergies.length > 0) {
        return (
            <InfoCard icon={AlertTriangle} tone="crit">
                <strong>Allergies:</strong> {client.allergies.join(', ')}. Check
                the label against the allergy list before giving.
            </InfoCard>
        );
    }

    if (!client || client.allergy_status === 'unavailable') {
        return (
            <InfoCard icon={AlertTriangle} tone="warn">
                <strong>Allergy record couldn’t be loaded</strong> for {name}.
                Check the health profile before giving.
            </InfoCard>
        );
    }

    return (
        // Absence of a record is a safety surface: fixed amber, never the
        // brand tint (DESIGN.md non-negotiable #6).
        <InfoCard icon={AlertTriangle} tone="warn">
            No allergies recorded for {name} — check the health profile before
            giving.
        </InfoCard>
    );
}

/**
 * The signed-in worker's restricted-competency rule (NF-03), shown before
 * they sign so a server refusal is never a surprise.
 */
export function CompetencyRestrictionNotice({
    notice,
}: {
    notice: CompetencyNotice | null | undefined;
}) {
    if (!notice) return null;

    return (
        <InfoCard icon={AlertTriangle} tone={notice.blocked ? 'crit' : 'warn'}>
            <strong>
                {notice.blocked
                    ? 'You can’t sign doses as given.'
                    : 'Co-signer required.'}
            </strong>{' '}
            {notice.message}
        </InfoCard>
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
