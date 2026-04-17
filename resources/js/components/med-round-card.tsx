import { AlertTriangle, Clock, Pill, ShieldAlert, UserCircle2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';

/* -------------------------------------------------------------------------- */
/*  MedRoundCard — the calm "one med at a time" card                          */
/* -------------------------------------------------------------------------- */
/*
 * Used inside the guided medication round flow. The card is intentionally
 * spacious and reads top-down so a worker under pressure can answer one
 * question only: "what do I do with this dose?"
 *
 * It does not render the action buttons itself — those live in the parent
 * page so the layout can make them full-width / sticky on mobile without
 * cluttering the card's visual breathing room.
 */

export interface MedRoundCardProps {
    clientName: string;
    clientPhotoUrl?: string | null;
    medicationName: string;
    dose?: string | null;
    route?: string | null;
    form?: string | null;
    instructions?: string | null;
    scheduledFor: string; // ISO
    isControlled?: boolean;
    isHighRisk?: boolean;
    requiresWitness?: boolean;
}

function formatTime(iso: string): string {
    try {
        return new Date(iso).toLocaleTimeString('en-NZ', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        });
    } catch {
        return iso;
    }
}

export default function MedRoundCard({
    clientName,
    clientPhotoUrl,
    medicationName,
    dose,
    route,
    form,
    instructions,
    scheduledFor,
    isControlled,
    isHighRisk,
    requiresWitness,
}: MedRoundCardProps) {
    const safetyFlags: { label: string; tone: 'danger' | 'warn' }[] = [];
    if (isControlled) safetyFlags.push({ label: 'Controlled drug', tone: 'danger' });
    if (isHighRisk) safetyFlags.push({ label: 'High risk', tone: 'danger' });
    if (requiresWitness) safetyFlags.push({ label: 'Witness required', tone: 'warn' });

    return (
        <div className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            {/* Who the dose is for */}
            <div className="flex items-center gap-3">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted">
                    {clientPhotoUrl ? (
                        <img
                            src={clientPhotoUrl}
                            alt=""
                            className="h-full w-full object-cover"
                        />
                    ) : (
                        <UserCircle2 className="h-8 w-8 text-muted-foreground" />
                    )}
                </div>
                <div className="min-w-0">
                    <p className="truncate text-base font-semibold leading-tight">
                        {clientName}
                    </p>
                    <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                        <Clock className="h-3 w-3" />
                        Due {formatTime(scheduledFor)}
                    </p>
                </div>
            </div>

            {/* The medication itself */}
            <div className="mt-5 rounded-xl border bg-background p-4">
                <div className="flex items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                        <Pill className="h-5 w-5 text-primary" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-lg font-semibold leading-tight">
                            {medicationName}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {[dose, route, form].filter(Boolean).join(' · ') || 'Dose details not recorded'}
                        </p>
                    </div>
                </div>

                {instructions && (
                    <div className="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        <div className="flex gap-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <p className="whitespace-pre-line">{instructions}</p>
                        </div>
                    </div>
                )}
            </div>

            {/* Safety flags — colour is reinforced with an icon so this never
                relies on colour alone. */}
            {safetyFlags.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {safetyFlags.map((flag) => (
                        <Badge
                            key={flag.label}
                            variant="outline"
                            className={
                                flag.tone === 'danger'
                                    ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100'
                                    : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100'
                            }
                        >
                            <ShieldAlert className="mr-1 h-3 w-3" />
                            {flag.label}
                        </Badge>
                    ))}
                </div>
            )}
        </div>
    );
}
