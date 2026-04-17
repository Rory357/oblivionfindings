import { Head, Link } from '@inertiajs/react';
import {
    Clock,
    ClipboardList,
    FileText,
    Home,
    Info,
    ListTodo,
    Menu,
    Phone,
    Pill,
    ShieldAlert,
    Stethoscope,
    UserRound,
    Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import PrnSheet, { type PrnMedication } from '@/components/prn-sheet';
import ClientSafetyRibbon, {
    type ClientSafety,
} from '@/components/client-safety-ribbon';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import type { StaffBottomNavItem } from '@/components/staff-bottom-nav';
import StaffPageShell from '@/layouts/staff-page-shell';

/* -------------------------------------------------------------------------- */
/*  PR 14 — Consolidated frontline client page                                */
/* -------------------------------------------------------------------------- */
/*
 * The worker-facing landing for a single client. Replaces "which tab do I
 * open?" with a single scannable page: who the client is, key safety, key
 * care context, a practical PRN action, and a short list of deep links into
 * the existing admin surfaces for things that don't belong on the day-of-
 * shift view.
 *
 * Reuses, not replaces:
 *   - ClientSafetyRibbon — the canonical safety surface.
 *   - prn-sheet          — launched here with the client preselected so the
 *                          worker stays in client context.
 *   - EnhancedMarService — via POST /operations/clients/{id}/care/prn, so the
 *                          administration path is identical to /meds/today.
 *
 * Extension points preserved (not built):
 *   - witness-required PRN: the sheet already surfaces the witness hint for
 *     controlled/high-risk meds; the client-scoped POST handler is where a
 *     future witness step should live.
 *   - effect-check follow-up: the "Follow-up needed" card is an empty shell
 *     today; a later PR can populate it from a real follow-up queue without
 *     restructuring this page.
 */

type ClientSummary = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name: string | null;
    pronouns: string | null;
    photo_url: string | null;
    date_of_birth: string | null;
};

type ActiveRisk = {
    id: number;
    label: string;
    severity: string;
    controls: string | null;
    review_date: string | null;
};

type Condition = {
    id: number;
    label: string;
    severity: string | null;
    notes: string | null;
};

type EmergencyContact = {
    id: number;
    name: string;
    relationship: string | null;
    phone: string | null;
};

type Props = {
    client: ClientSummary;
    safety: ClientSafety | null;
    medical_notes: string | null;
    conditions: Condition[];
    active_risks: ActiveRisk[];
    emergency_contacts: EmergencyContact[];
    prn_medications: PrnMedication[];
    active_shift: { id: number; starts_at: string | null } | null;
    can: {
        record_prn: boolean;
        view_medical: boolean;
        view_risks: boolean;
    };
    links: {
        full_profile: string;
        medical: string;
        risks: string;
        mar: string;
    };
};

function severityTone(severity: string): {
    dot: string;
    label: string;
} {
    const s = severity.toLowerCase();
    if (s === 'critical') {
        return { dot: 'bg-red-500', label: 'Critical' };
    }
    if (s === 'high') {
        return { dot: 'bg-amber-500', label: 'High' };
    }
    if (s === 'medium') {
        return { dot: 'bg-sky-500', label: 'Medium' };
    }
    return { dot: 'bg-slate-400', label: 'Low' };
}

function ageFromDob(dob: string | null): number | null {
    if (!dob) return null;
    const birth = new Date(dob);
    if (Number.isNaN(birth.getTime())) return null;
    const now = new Date();
    let years = now.getFullYear() - birth.getFullYear();
    const m = now.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < birth.getDate())) {
        years -= 1;
    }
    return years >= 0 && years < 130 ? years : null;
}

export default function ClientCare({
    client,
    safety,
    medical_notes,
    conditions,
    active_risks,
    emergency_contacts,
    prn_medications,
    active_shift,
    can,
    links,
}: Props) {
    const getInitials = useInitials();
    const [prnOpen, setPrnOpen] = useState(false);

    const displayName = useMemo(() => {
        const preferred = client.preferred_name?.trim();
        if (preferred) return preferred;
        return `${client.first_name} ${client.last_name}`.trim();
    }, [client]);

    const fullName = `${client.first_name} ${client.last_name}`.trim();
    const age = ageFromDob(client.date_of_birth);

    const bottomNavItems = useMemo<StaffBottomNavItem[]>(
        () => [
            { key: 'home', label: 'Home', icon: Home, href: '/my-day' },
            { key: 'meds', label: 'Meds', icon: Pill, href: '/meds/today' },
            { key: 'clock', label: 'Clock', icon: Clock, href: '/my-day#clock' },
            {
                key: 'report',
                label: 'Report',
                icon: ClipboardList,
                href: '/incidents',
            },
            { key: 'more', label: 'More', icon: Menu, href: '/' },
        ],
        [],
    );

    const hasActiveShift = active_shift !== null;
    const prnCount = prn_medications.length;
    const prnDisabled = !can.record_prn || prnCount === 0;

    const subtitleBits = [
        client.pronouns,
        age !== null ? `${age} yrs` : null,
    ].filter(Boolean) as string[];

    return (
        <StaffPageShell
            title={displayName}
            subtitle={subtitleBits.join(' \u00b7 ') || fullName}
            backHref="/operations/clients"
            backLabel="Clients"
            bottomNavItems={bottomNavItems}
        >
            <Head title={`${displayName} \u00b7 Care`} />

            <div className="mx-auto w-full max-w-3xl space-y-4">
                {/* ── Identity card ─────────────────────────────────────── */}
                <div className="flex items-center gap-3 rounded-xl border bg-card p-3">
                    <Avatar className="h-14 w-14 shrink-0">
                        {client.photo_url ? (
                            <AvatarImage src={client.photo_url} alt={fullName} />
                        ) : null}
                        <AvatarFallback className="bg-muted text-base font-medium">
                            {getInitials(fullName || displayName)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-base font-semibold leading-tight">
                            {fullName}
                        </p>
                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                            {age !== null && <span>{age} yrs</span>}
                            {client.pronouns && (
                                <>
                                    {age !== null && <span aria-hidden>·</span>}
                                    <span>{client.pronouns}</span>
                                </>
                            )}
                            {hasActiveShift ? (
                                <>
                                    <span aria-hidden>·</span>
                                    <span className="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300">
                                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                        On shift
                                    </span>
                                </>
                            ) : null}
                        </div>
                    </div>
                </div>

                {/* ── Safety ribbon (canonical surface) ─────────────────── */}
                <ClientSafetyRibbon safety={safety} />

                {/* ── Primary worker action: Give as-needed med ─────────── */}
                <button
                    type="button"
                    onClick={() => setPrnOpen(true)}
                    disabled={prnDisabled}
                    className="group flex w-full items-center gap-3 rounded-xl border border-amber-300 bg-amber-50/70 p-4 text-left transition-shadow hover:shadow-sm disabled:cursor-not-allowed disabled:opacity-60 dark:border-amber-800/60 dark:bg-amber-950/20"
                >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-600 text-white">
                        <Zap className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold leading-tight">
                            Give as-needed med
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {prnCount === 0
                                ? 'No PRN meds on their profile yet'
                                : !can.record_prn
                                    ? 'You don\u2019t have permission to record medications'
                                    : `${prnCount} PRN med${prnCount === 1 ? '' : 's'} available \u00b7 quick record`}
                        </p>
                    </div>
                </button>

                {/* ── Null-shift context banner ─────────────────────────── */}
                {/* Explicit, not silent. If the worker isn't clocked in for
                    this client, saying so up-front prevents surprises when a
                    PRN gets recorded outside a shift. */}
                {!hasActiveShift && prnCount > 0 && can.record_prn && (
                    <div className="flex items-start gap-3 rounded-lg border border-sky-200 bg-sky-50/70 p-3 text-sm dark:border-sky-900 dark:bg-sky-950/20">
                        <Info className="mt-0.5 h-4 w-4 shrink-0 text-sky-700 dark:text-sky-300" />
                        <div className="min-w-0">
                            <p className="font-medium text-sky-900 dark:text-sky-100">
                                You&rsquo;re not on shift for this client right now
                            </p>
                            <p className="mt-0.5 text-xs text-sky-800 dark:text-sky-200">
                                You can still record a PRN from here. It will save without a
                                shift link, with a note so the record is clear.
                            </p>
                        </div>
                    </div>
                )}

                {/* ── What you need to know ─────────────────────────────── */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Stethoscope className="h-4 w-4" />
                            What you need to know
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4 pt-0 text-sm">
                        {/* Allergies live in the safety ribbon above — the
                            canonical surface — so we don't duplicate them
                            here and risk drift. */}

                        {/* Conditions */}
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                Conditions
                            </p>
                            {conditions.length === 0 ? (
                                <p className="mt-1 text-muted-foreground">
                                    No health conditions recorded.
                                </p>
                            ) : (
                                <ul className="mt-1.5 space-y-1.5">
                                    {conditions.slice(0, 6).map((c) => (
                                        <li
                                            key={c.id}
                                            className="flex items-start gap-2"
                                        >
                                            <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" />
                                            <div className="min-w-0">
                                                <span className="font-medium">{c.label}</span>
                                                {c.severity && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        ({c.severity})
                                                    </span>
                                                )}
                                                {c.notes && (
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {c.notes}
                                                    </p>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                    {conditions.length > 6 && (
                                        <li className="text-xs text-muted-foreground">
                                            +{conditions.length - 6} more on the medical page
                                        </li>
                                    )}
                                </ul>
                            )}
                        </div>

                        {/* Care notes */}
                        {medical_notes && (
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    Care notes
                                </p>
                                <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200">
                                    {medical_notes}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Risks (summary only; full register on /risks) ─────── */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center justify-between text-base">
                            <span className="flex items-center gap-2">
                                <ShieldAlert className="h-4 w-4" />
                                Risks
                            </span>
                            {active_risks.length > 0 && (
                                <Badge variant="outline" className="text-[10px]">
                                    {active_risks.length} active
                                </Badge>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="pt-0 text-sm">
                        {active_risks.length === 0 ? (
                            <p className="text-muted-foreground">No active risks recorded.</p>
                        ) : (
                            <ul className="space-y-2.5">
                                {active_risks.slice(0, 5).map((r) => {
                                    const tone = severityTone(r.severity);
                                    return (
                                        <li key={r.id} className="flex items-start gap-2.5">
                                            <span
                                                className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${tone.dot}`}
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                                    <span className="font-medium">{r.label}</span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {tone.label}
                                                    </span>
                                                </div>
                                                {r.controls && (
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {r.controls}
                                                    </p>
                                                )}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                        {can.view_risks && (
                            <div className="mt-3">
                                <Button asChild variant="outline" size="sm">
                                    <Link href={links.risks}>
                                        Open risk register
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Emergency contacts ────────────────────────────────── */}
                {emergency_contacts.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Phone className="h-4 w-4" />
                                Emergency contacts
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 pt-0 text-sm">
                            {emergency_contacts.map((c) => (
                                <div
                                    key={c.id}
                                    className="flex items-start justify-between gap-3"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">{c.name}</p>
                                        {c.relationship && (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {c.relationship}
                                            </p>
                                        )}
                                    </div>
                                    {c.phone && (
                                        <a
                                            href={`tel:${c.phone.replace(/\s+/g, '')}`}
                                            className="shrink-0 rounded-md border px-2.5 py-1 text-xs font-medium hover:bg-accent"
                                        >
                                            {c.phone}
                                        </a>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {/* ── Follow-up needed (extension point placeholder) ────── */}
                {/* Intentionally lightweight and empty by default. A later PR
                    will populate this from an effect-check follow-up queue
                    without restructuring the page. */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ListTodo className="h-4 w-4" />
                            Follow-up needed
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="pt-0 text-sm text-muted-foreground">
                        Nothing pending. PRN effect checks and witness follow-ups
                        will appear here.
                    </CardContent>
                </Card>

                {/* ── Deep links into admin surfaces ────────────────────── */}
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {can.view_medical && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={links.medical}>
                                <Stethoscope className="mr-1.5 h-4 w-4" />
                                Medical
                            </Link>
                        </Button>
                    )}
                    <Button variant="outline" size="sm" asChild>
                        <Link href={links.mar}>
                            <Pill className="mr-1.5 h-4 w-4" />
                            MAR
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={links.full_profile}>
                            <FileText className="mr-1.5 h-4 w-4" />
                            Full profile
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/my-day">
                            <UserRound className="mr-1.5 h-4 w-4" />
                            My Day
                        </Link>
                    </Button>
                </div>
            </div>

            <PrnSheet
                open={prnOpen}
                onOpenChange={setPrnOpen}
                medications={prn_medications}
                preselectedClient={{
                    id: client.id,
                    name: fullName,
                    hasActiveShift,
                }}
                submitUrl={`/operations/clients/${client.id}/care/prn`}
            />
        </StaffPageShell>
    );
}
