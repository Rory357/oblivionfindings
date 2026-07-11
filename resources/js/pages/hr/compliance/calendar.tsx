import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Ban, Bell, ChevronRight, ClipboardCheck, Clock, User } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    ComplianceContextMenu,
    RENEWAL_TYPE_BADGE,
    useContextMenu,
    type CtxItem,
} from './components/compliance-bits';
import { ComplianceHubHeader, type HeroPayload } from './components/compliance-hub-header';
import { ComplianceWizards, type ReqOption, type RoleOption, type WizardState } from './components/compliance-wizards';
import type { PersonOption } from '@/components/hr/people-picker';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';

interface RenewalEvent {
    id: string;
    entity_type: 'compliance' | 'vetting' | 'driver';
    entity_id: number;
    user_id: number | null;
    type: string;
    requirement: string;
    person: string;
    start: string;
    date: string;
    month: string;
    days: string;
    urgency: 'over' | 'soon' | 'far';
}

interface Props {
    hero: HeroPayload;
    events: RenewalEvent[];
    wizard: { people: PersonOption[]; requirements: ReqOption[]; roles: RoleOption[]; siteTypes: string[] };
    filters: { type: string };
    can: { manage: boolean; vetting_manage: boolean; driver_manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Staff compliance', href: '/hr/compliance' },
    { title: 'Renewals', href: '/hr/compliance/calendar' },
];

const HORIZONS = [
    { key: '30', label: 'Next 30d', max: 30 },
    { key: '60', label: '60d', max: 60 },
    { key: '90', label: '90d', max: 90 },
    { key: 'over', label: 'Overdue', max: 0 },
];
const TYPES = [
    { key: 'all', label: 'All' },
    { key: 'compliance', label: 'Compliance' },
    { key: 'vetting', label: 'Vetting' },
    { key: 'driver', label: 'Driver' },
];

function daysFromNow(iso: string): number {
    const ms = new Date(iso).getTime() - Date.now();
    return Math.round(ms / 86400000);
}

export default function ComplianceRenewals({ hero, events, wizard, can }: Props) {
    const [wz, setWz] = useState<WizardState>(null);
    const [horizon, setHorizon] = useState('30');
    const [typeFilter, setTypeFilter] = useState('all');
    const [sheet, setSheet] = useState<RenewalEvent | null>(null);
    const { ctx, open: openCtx, close: closeCtx } = useContextMenu();

    const filtered = useMemo(() => {
        return events.filter((e) => {
            if (typeFilter !== 'all' && e.type !== typeFilter) return false;
            const d = daysFromNow(e.start);
            if (horizon === 'over') return d < 0;
            const max = Number(horizon);
            return d >= 0 && d <= max;
        });
    }, [events, horizon, typeFilter]);

    // Group by month label (Overdue floats to the end).
    const groups = useMemo(() => {
        const map = new Map<string, RenewalEvent[]>();
        for (const e of filtered) {
            if (!map.has(e.month)) map.set(e.month, []);
            map.get(e.month)!.push(e);
        }
        return Array.from(map.entries()).sort(([a], [b]) => {
            if (a === 'Overdue') return 1;
            if (b === 'Overdue') return -1;
            return new Date(map.get(a)![0].start).getTime() - new Date(map.get(b)![0].start).getTime();
        });
    }, [filtered]);

    const remind = (e: RenewalEvent) => {
        router.post('/hr/compliance/renewals/remind', { type: e.entity_type, id: e.entity_id }, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Reminder sent to ${e.person}.`),
            onError: () => toast.error('Could not send reminder.'),
        });
    };
    const snooze = (e: RenewalEvent) => {
        router.post('/hr/compliance/renewals/snooze', { type: e.entity_type, id: e.entity_id, days: 7 }, {
            preserveScroll: true,
            onSuccess: () => toast.success('Snoozed 7 days.'),
            onError: () => toast.error('Could not snooze.'),
        });
    };
    const recordRenewal = (e: RenewalEvent) =>
        setWz({ type: 'record', preset: e.user_id ? { person: String(e.user_id) } : {} });
    const waive = (e: RenewalEvent) =>
        setWz({ type: 'waive', preset: e.user_id ? { person: String(e.user_id) } : {} });

    const rowMenu = (e: RenewalEvent): CtxItem[] => [
        { icon: User, label: 'Open person', onClick: () => e.user_id && router.visit(`/hr/compliance/staff/${e.user_id}`) },
        ...(can.manage ? [{ icon: ClipboardCheck, label: 'Record renewal', onClick: () => recordRenewal(e) }] : []),
        { icon: Bell, label: 'Remind', onClick: () => remind(e) },
        ...(can.manage ? [{ icon: Ban, label: 'Waive', onClick: () => waive(e) }] : []),
        ...(can.manage ? [{ icon: Clock, label: 'Snooze', onClick: () => snooze(e) }] : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Renewals" />
            <div className="space-y-4 px-4 py-4 lg:px-6">
                <ComplianceHubHeader hero={hero} active="calendar" can={{ manage: can.manage, vetting: can.vetting_manage, driver: can.driver_manage }} onWizard={(type) => setWz({ type })} />

                {/* Horizon + type filters */}
                <GuardrailCard unstyled className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-card p-2.5">
                    <span className="mr-1 text-xs font-semibold text-muted-foreground">Horizon</span>
                    {HORIZONS.map((h) => (
                        <Pill key={h.key} active={horizon === h.key} onClick={() => setHorizon(h.key)}>
                            {h.label}
                        </Pill>
                    ))}
                    <span className="mx-1 h-5 w-px bg-border" />
                    {TYPES.map((t) => (
                        <Pill key={t.key} active={typeFilter === t.key} onClick={() => setTypeFilter(t.key)}>
                            {t.label}
                        </Pill>
                    ))}
                </GuardrailCard>

                {groups.length === 0 ? (
                    <GuardrailCard unstyled className="rounded-xl border border-dashed border-border bg-card p-10 text-center text-muted-foreground">
                        <Clock className="mx-auto mb-2 h-8 w-8 opacity-40" />
                        Nothing due in this window. 🎉
                    </GuardrailCard>
                ) : (
                    groups.map(([month, items]) => (
                        <div key={month}>
                            <div className="mb-2 mt-1.5 flex items-center gap-2.5 px-0.5">
                                <h3 className="text-sm font-bold">{month}</h3>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">{items.length} due</span>
                            </div>
                            <GuardrailCard unstyled className="overflow-hidden rounded-xl border border-border bg-card">
                                {items.map((e) => {
                                    const tb = RENEWAL_TYPE_BADGE[e.type] ?? RENEWAL_TYPE_BADGE.compliance;
                                    return (
                                        <div
                                            key={e.id}
                                            onClick={() => setSheet(e)}
                                            onContextMenu={(ev) => openCtx(ev, rowMenu(e))}
                                            className="flex cursor-pointer items-center gap-3.5 border-b border-border px-3.5 py-3 last:border-0 hover:bg-muted/60"
                                        >
                                            <StatusBadge variant={tb.variant}>{tb.label}</StatusBadge>
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate font-semibold">{e.requirement}</div>
                                                <div className="truncate text-[11.5px] text-muted-foreground">{e.person}</div>
                                            </div>
                                            <div className="text-right">
                                                <div className="text-[13px] font-semibold">{e.date}</div>
                                                <div className={`text-[11px] font-semibold ${e.urgency === 'over' ? 'text-status-critical' : e.urgency === 'soon' ? 'text-status-warning' : 'text-muted-foreground'}`}>
                                                    {e.days}
                                                </div>
                                            </div>
                                            <ChevronRight className="h-4 w-4 text-muted-foreground" />
                                        </div>
                                    );
                                })}
                            </GuardrailCard>
                        </div>
                    ))
                )}
            </div>

            {/* Detail sheet */}
            <Sheet open={!!sheet} onOpenChange={(o) => !o && setSheet(null)}>
                <SheetContent side="right" className="w-[420px] sm:max-w-[420px]">
                    {sheet && (
                        <>
                            <SheetHeader>
                                <SheetTitle>{sheet.requirement}</SheetTitle>
                                <p className="text-[12.5px] text-muted-foreground">{sheet.person}</p>
                            </SheetHeader>
                            <div className="mt-4 flex items-center gap-2.5">
                                <StatusBadge variant={(RENEWAL_TYPE_BADGE[sheet.type] ?? RENEWAL_TYPE_BADGE.compliance).variant}>
                                    {(RENEWAL_TYPE_BADGE[sheet.type] ?? RENEWAL_TYPE_BADGE.compliance).label}
                                </StatusBadge>
                                <StatusBadge variant={sheet.urgency === 'over' ? 'critical' : 'warning'}>{sheet.days}</StatusBadge>
                            </div>
                            <div className="mt-4 rounded-xl bg-muted p-4">
                                <Fact label="Due date" value={sheet.date} />
                                <Fact label="Type" value={(RENEWAL_TYPE_BADGE[sheet.type] ?? RENEWAL_TYPE_BADGE.compliance).label} />
                                <Fact label="Owner" value={sheet.person} />
                            </div>
                            <div className="mt-5 flex gap-2">
                                {can.manage && (
                                    <GuardrailButton unstyled onClick={() => { recordRenewal(sheet); setSheet(null); }} className="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground">
                                        Record renewal
                                    </GuardrailButton>
                                )}
                                <GuardrailButton unstyled onClick={() => remind(sheet)} className="rounded-lg border border-border px-3 py-2 text-sm font-semibold">
                                    Remind
                                </GuardrailButton>
                                {can.manage && (
                                    <GuardrailButton unstyled onClick={() => { waive(sheet); setSheet(null); }} className="rounded-lg border border-border px-3 py-2 text-sm font-semibold">
                                        Waive
                                    </GuardrailButton>
                                )}
                            </div>
                        </>
                    )}
                </SheetContent>
            </Sheet>

            <ComplianceContextMenu ctx={ctx} onClose={closeCtx} />
            <ComplianceWizards state={wz} onClose={() => setWz(null)} people={wizard.people} requirements={wizard.requirements} roles={wizard.roles} siteTypes={wizard.siteTypes} />
        </AppLayout>
    );
}

function Pill({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <GuardrailButton unstyled
            type="button"
            onClick={onClick}
            className={`h-8 rounded-lg border px-3 text-[12.5px] font-semibold transition-colors ${
                active ? 'border-primary bg-accent text-primary' : 'border-border bg-background text-muted-foreground hover:text-foreground'
            }`}
        >
            {children}
        </GuardrailButton>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between py-1.5 text-[13px]">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-semibold">{value}</span>
        </div>
    );
}
