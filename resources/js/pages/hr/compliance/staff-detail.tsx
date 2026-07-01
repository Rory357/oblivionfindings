import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Check, ChevronLeft, ClipboardCheck, Clock, ShieldCheck, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { DriverChip, VettingChip } from './components/compliance-bits';
import { CompactHeroBand, HeroGhostButton, HeroInitials, HeroSolidButton } from './components/compliance-hero';
import { ComplianceWizards, type ReqOption, type RoleOption, type WizardState } from './components/compliance-wizards';
import type { PersonOption } from '@/components/hr/people-picker';

interface ComplianceStatus {
    id: number;
    requirement_id: number;
    requirement_name: string;
    requirement_type: string;
    renewal_period_months: number | null;
    status: 'compliant' | 'expiring_soon' | 'expired' | 'not_started';
    expiry_date: string | null;
    completed_date: string | null;
    evidence_url: string | null;
    evidence_notes: string | null;
    is_mandatory: boolean;
}

interface Props {
    staff: { id: number; name: string; email: string };
    complianceStatuses: ComplianceStatus[];
    summary: { compliant: number; expiring_soon: number; expired: number; not_started: number };
    hardStopFailures: { requirement: string; code: string; status: string }[];
    futureShiftsAffected: number;
    requirements: ReqOption[];
    wizard: { people: PersonOption[]; requirements: ReqOption[]; roles: RoleOption[]; siteTypes: string[] };
    vetting: { id: number; status: string; check_type: string; provider: string | null; reference_number: string | null; expires_at: string | null } | null;
    driver: { id: number; status: string; licence_class: string | null; licence_number: string | null; expires_at: string | null } | null;
    can: { manage: boolean; vetting: boolean; driver: boolean };
}

const GROUPS: { key: ComplianceStatus['status']; label: string; icon: typeof Check; color: string }[] = [
    { key: 'expired', label: 'Expired', icon: AlertTriangle, color: 'text-status-critical' },
    { key: 'expiring_soon', label: 'Expiring soon', icon: Clock, color: 'text-status-warning' },
    { key: 'not_started', label: 'Not started', icon: X, color: 'text-muted-foreground' },
    { key: 'compliant', label: 'Compliant', icon: Check, color: 'text-status-success' },
];

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

export default function StaffDetail({ staff, complianceStatuses, summary, hardStopFailures, futureShiftsAffected, wizard, vetting, driver, can }: Props) {
    const [wz, setWz] = useState<WizardState>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Staff compliance', href: '/hr/compliance' },
        { title: staff.name, href: `/hr/compliance/staff/${staff.id}` },
    ];

    const total = summary.compliant + summary.expiring_soon + summary.expired + summary.not_started;
    const pct = total > 0 ? Math.round((summary.compliant / total) * 100) : 0;
    const pctLabel = total > 0 ? `${pct}%` : '—';

    const record = (preset: Record<string, unknown> = {}) => setWz({ type: 'record', preset: { person: String(staff.id), ...preset } });
    const waive = (preset: Record<string, unknown> = {}) => setWz({ type: 'waive', preset: { person: String(staff.id), ...preset } });
    const remind = () =>
        router.post('/hr/compliance/bulk-remind', { user_ids: [staff.id] }, { preserveScroll: true, onSuccess: () => toast.success(`Reminder sent to ${staff.name}.`) });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={staff.name} />
            <div className="space-y-4 px-4 py-4 lg:px-6">
                <Link href="/hr/compliance" className="inline-flex w-max items-center gap-1.5 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground">
                    <ChevronLeft className="h-4 w-4" /> Staff compliance
                </Link>

                <CompactHeroBand>
                    <div className="flex items-center gap-4">
                        <HeroInitials name={staff.name} />
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">{staff.name}</h1>
                            <p className="mt-1 text-[13px] text-primary-foreground/75">{staff.email}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-5">
                        <div className="text-center">
                            <div className="text-3xl font-bold tabular-nums">{pctLabel}</div>
                            <div className="text-[10px] font-bold uppercase tracking-wide text-primary-foreground/60">Compliant</div>
                        </div>
                        {can.manage && (
                            <div className="flex flex-col gap-2">
                                <HeroSolidButton icon={ClipboardCheck} onClick={() => record()}>
                                    Record compliance
                                </HeroSolidButton>
                                <div className="flex gap-2">
                                    <HeroGhostButton onClick={() => waive()}>Waive</HeroGhostButton>
                                    <HeroGhostButton onClick={remind}>Remind</HeroGhostButton>
                                </div>
                            </div>
                        )}
                    </div>
                </CompactHeroBand>

                {hardStopFailures.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3 rounded-xl border border-status-critical/35 bg-status-critical-bg p-3.5">
                        <AlertTriangle className="h-5 w-5 shrink-0 text-status-critical" />
                        <div className="flex-1">
                            <div className="font-bold text-status-critical-foreground">
                                Blocked from shifts{futureShiftsAffected > 0 ? ` — ${futureShiftsAffected} upcoming affected` : ''}
                            </div>
                            <div className="text-xs text-status-critical-foreground">
                                Expired hard-stop requirement(s): {hardStopFailures.map((f) => f.requirement).join(', ')}
                            </div>
                        </div>
                        {can.manage && (
                            <div className="flex gap-2">
                                <button onClick={() => record()} className="rounded-lg bg-status-critical px-3 py-1.5 text-[12.5px] font-semibold text-white">
                                    Resolve
                                </button>
                                <button onClick={() => waive()} className="rounded-lg border border-border bg-card px-3 py-1.5 text-[12.5px] font-semibold">
                                    Waive
                                </button>
                            </div>
                        )}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_300px]">
                    {/* Requirement groups */}
                    <div className="flex flex-col gap-3.5">
                        {GROUPS.map((g) => {
                            const items = complianceStatuses.filter((s) => s.status === g.key);
                            if (items.length === 0) return null;
                            return (
                                <div key={g.key} className="overflow-hidden rounded-xl border border-border bg-card">
                                    <div className="flex items-center gap-2 border-b border-border px-3.5 py-3">
                                        <g.icon className={`h-4 w-4 ${g.color}`} />
                                        <span className="font-bold">{g.label}</span>
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">{items.length}</span>
                                    </div>
                                    {items.map((it) => (
                                        <div key={it.id} className="flex items-center gap-3 border-b border-border px-3.5 py-3 last:border-0 hover:bg-muted/60">
                                            <div className="min-w-0 flex-1">
                                                <div className="font-semibold">{it.requirement_name}</div>
                                                <div className="text-[11.5px] text-muted-foreground">
                                                    {it.requirement_type.replace(/_/g, ' ')}
                                                    {it.status === 'expired' && it.expiry_date ? ` · Expired ${fmtDate(it.expiry_date)}` : null}
                                                    {it.status === 'expiring_soon' && it.expiry_date ? ` · Expires ${fmtDate(it.expiry_date)}` : null}
                                                    {it.status === 'compliant' && it.expiry_date ? ` · Valid to ${fmtDate(it.expiry_date)}` : null}
                                                    {it.status === 'not_started' ? ' · Never recorded' : null}
                                                </div>
                                                {it.evidence_url ? (
                                                    <a href={it.evidence_url} target="_blank" rel="noreferrer" className="text-[11.5px] font-semibold text-primary hover:underline">
                                                        View evidence
                                                    </a>
                                                ) : null}
                                            </div>
                                            <StatusBadge variant={it.is_mandatory ? 'info' : 'neutral'}>{it.is_mandatory ? 'Required' : 'Optional'}</StatusBadge>
                                            {can.manage && (
                                                <div className="flex gap-1.5">
                                                    <button
                                                        onClick={() => record({ requirement: it.requirement_id, status: it.status })}
                                                        className="rounded-md bg-primary px-2.5 py-1.5 text-[12px] font-semibold text-primary-foreground"
                                                    >
                                                        Record
                                                    </button>
                                                    <button
                                                        onClick={() => waive({ requirement: it.requirement_id })}
                                                        className="rounded-md border border-border bg-card px-2.5 py-1.5 text-[12px] font-semibold"
                                                    >
                                                        Waive
                                                    </button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            );
                        })}
                        {complianceStatuses.length === 0 && (
                            <div className="rounded-xl border border-dashed border-border bg-card p-10 text-center text-muted-foreground">
                                <ShieldCheck className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                No requirements tracked for this person yet.
                            </div>
                        )}
                    </div>

                    {/* Vetting + driver panels */}
                    <div className="flex flex-col gap-3.5">
                        <SidePanel title="Vetting" onView={can.vetting ? () => router.visit('/hr/compliance/vetting') : undefined}>
                            {vetting ? (
                                <>
                                    <VettingChip status={vetting.status} />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {vetting.check_type.replace(/_/g, ' ')}
                                        {vetting.expires_at ? ` · valid to ${fmtDate(vetting.expires_at)}` : ''}
                                    </p>
                                </>
                            ) : (
                                <p className="text-xs text-muted-foreground">No vetting record.</p>
                            )}
                        </SidePanel>

                        <SidePanel
                            title="Driver eligibility"
                            onView={can.driver && driver ? () => router.visit(`/hr/compliance/drivers/${driver.id}`) : undefined}
                        >
                            {driver ? (
                                <>
                                    <DriverChip status={driver.status} />
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {driver.licence_class ? `Class ${driver.licence_class}` : 'Licence'}
                                        {driver.expires_at ? ` · expires ${fmtDate(driver.expires_at)}` : ''}
                                    </p>
                                </>
                            ) : (
                                <p className="text-xs text-muted-foreground">No driver record.</p>
                            )}
                        </SidePanel>
                    </div>
                </div>
            </div>

            <ComplianceWizards state={wz} onClose={() => setWz(null)} people={wizard.people} requirements={wizard.requirements} roles={wizard.roles} siteTypes={wizard.siteTypes} />
        </AppLayout>
    );
}

function SidePanel({ title, onView, children }: { title: string; onView?: () => void; children: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-border bg-card p-3.5">
            <div className="flex items-center justify-between">
                <span className="text-[13px] font-bold">{title}</span>
                {onView ? (
                    <button onClick={onView} className="text-[11.5px] font-semibold text-primary hover:underline">
                        View
                    </button>
                ) : null}
            </div>
            <div className="mt-2.5">{children}</div>
        </div>
    );
}
