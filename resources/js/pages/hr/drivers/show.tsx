import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ChevronLeft, Pencil } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { DRIVER_BADGE } from '@/pages/hr/compliance/components/compliance-bits';
import { CompactHeroBand, HeroGhostButton, HeroInitials, HeroSolidButton } from '@/pages/hr/compliance/components/compliance-hero';

interface Driver {
    id: number;
    user_id: number;
    name: string;
    email: string | null;
    licence_number: string | null;
    licence_class: string | null;
    licence_endorsements: string[];
    licence_expires_at: string | null;
    incident_free_since: string | null;
    can_drive_clients: boolean;
    status: string;
    raw_status: string;
    suspension_reason: string | null;
    notes: string | null;
    last_reviewed_at: string | null;
    next_review_at: string | null;
}

interface HistoryItem {
    title: string;
    date: string;
    tone: 'neutral' | 'success' | 'critical';
}

interface Props {
    driver: Driver;
    history: HistoryItem[];
    can: { manage: boolean };
}

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

export default function DriverShow({ driver, history, can }: Props) {
    const [suspendOpen, setSuspendOpen] = useState(false);
    const [reason, setReason] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Drivers', href: '/hr/compliance/drivers' },
        { title: driver.name, href: `/hr/compliance/drivers/${driver.id}` },
    ];

    const badge = DRIVER_BADGE[driver.status] ?? DRIVER_BADGE.none;

    const approve = () =>
        router.post(`/hr/compliance/drivers/${driver.id}/approve`, {}, { preserveScroll: true, onSuccess: () => toast.success('Driver approved.'), onError: () => toast.error('Could not approve.') });

    const doSuspend = () => {
        router.post(`/hr/compliance/drivers/${driver.id}/suspend`, { suspension_reason: reason }, {
            preserveScroll: true,
            onSuccess: () => toast.success('Driving privileges suspended.'),
        });
        setSuspendOpen(false);
        setReason('');
    };

    const facts: { label: string; value: string }[] = [
        { label: 'Licence number', value: driver.licence_number ?? '—' },
        { label: 'Class', value: driver.licence_class ? `Class ${driver.licence_class}` : '—' },
        { label: 'Endorsements', value: driver.licence_endorsements.length ? driver.licence_endorsements.join(', ') : 'None' },
        { label: 'Expiry', value: fmtDate(driver.licence_expires_at) },
        { label: 'Incident-free since', value: fmtDate(driver.incident_free_since) },
        { label: 'Can drive clients', value: driver.can_drive_clients ? 'Yes' : 'No' },
        { label: 'Next review', value: fmtDate(driver.next_review_at) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${driver.name} — Driver`} />
            <div className="space-y-4 px-4 py-4 lg:px-6">
                <Link href="/hr/compliance/drivers" className="inline-flex w-max items-center gap-1.5 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground">
                    <ChevronLeft className="h-4 w-4" /> Drivers
                </Link>

                <CompactHeroBand>
                    <div className="flex items-center gap-4">
                        <HeroInitials name={driver.name} size={58} />
                        <div>
                            <h1 className="text-[23px] font-bold tracking-tight">{driver.name}</h1>
                            <p className="mt-1 text-[13px] text-primary-foreground/75">
                                Class {driver.licence_class ?? '—'} · {driver.licence_number ?? '—'}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <StatusBadge variant={badge.variant}>{badge.label}</StatusBadge>
                        {can.manage && (
                            <>
                                {driver.raw_status !== 'eligible' && (
                                    <HeroSolidButton icon={CheckCircle2} onClick={approve}>
                                        Approve
                                    </HeroSolidButton>
                                )}
                                <HeroGhostButton onClick={() => setSuspendOpen(true)}>Suspend</HeroGhostButton>
                            </>
                        )}
                    </div>
                </CompactHeroBand>

                {driver.status === 'suspended' && driver.suspension_reason && (
                    <div className="rounded-xl border border-status-critical/35 bg-status-critical-bg p-3.5 text-[13px] text-status-critical-foreground">
                        <span className="font-bold">Suspended:</span> {driver.suspension_reason}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div className="rounded-xl border border-border bg-card p-4">
                        <h3 className="mb-3 flex items-center gap-2 text-sm font-bold">
                            <Pencil className="h-4 w-4 text-primary" /> Licence
                        </h3>
                        {facts.map((f) => (
                            <div key={f.label} className="flex justify-between border-b border-border py-2 text-[13px] last:border-0">
                                <span className="text-muted-foreground">{f.label}</span>
                                <span className="text-right font-semibold">{f.value}</span>
                            </div>
                        ))}
                    </div>

                    <div className="rounded-xl border border-border bg-card p-4">
                        <h3 className="mb-3 text-sm font-bold">History</h3>
                        {history.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No history recorded.</p>
                        ) : (
                            history.map((h, i) => (
                                <div key={i} className="flex gap-2.5 border-b border-border py-2.5 last:border-0">
                                    <span
                                        className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${
                                            h.tone === 'success' ? 'bg-status-success' : h.tone === 'critical' ? 'bg-status-critical' : 'bg-primary'
                                        }`}
                                    />
                                    <div>
                                        <div className="text-[13px] font-semibold">{h.title}</div>
                                        <div className="text-[11.5px] text-muted-foreground">{h.date}</div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>

            <AlertDialog open={suspendOpen} onOpenChange={setSuspendOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Suspend {driver.name}?</AlertDialogTitle>
                        <AlertDialogDescription>Record a reason. This removes client-transport eligibility.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <Textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for suspension…" className="min-h-[88px]" />
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button variant="destructive" disabled={!reason.trim()} onClick={doSuspend}>
                            Suspend
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
