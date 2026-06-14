/* Audit log — single-pane viewer on the shared Add-Client wizard chrome.
 * Read-only window over the recent medication-administration audit trail (the
 * already-authorised recentActivity slice). Filter + immutable rows + links to
 * the full, permission-gated audit page and its CSV export. */
import { MedsWizardDialog } from '@/components/meds/wizard-shell';
import { StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SelectInput } from '@/components/wizard/primitives';
import { Link } from '@inertiajs/react';
import { Download, FileText, History } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

export type AuditActivityItem = {
    id: number;
    status: string;
    administered_at: string | null;
    client: { first_name: string; last_name: string } | null;
    medication: { name: string } | null;
    administered_by: { name: string } | null;
};

const STATUS_META: Record<string, { label: string; chip: string; mark: string }> = {
    given: { label: 'Given', chip: 'bg-status-success-bg text-status-success', mark: '✓' },
    refused: { label: 'Refused', chip: 'bg-status-warning-bg text-status-warning', mark: '✕' },
    withheld: { label: 'Withheld', chip: 'bg-muted text-muted-foreground', mark: '–' },
    missed: { label: 'Missed', chip: 'bg-status-critical-bg text-status-critical', mark: '!' },
    pending: { label: 'Pending', chip: 'bg-muted text-muted-foreground', mark: '•' },
};

export function AuditLogModal({
    open,
    onClose,
    activity,
}: {
    open: boolean;
    onClose: () => void;
    activity: AuditActivityItem[];
}) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');

    const q = search.trim().toLowerCase();
    const rows = activity.filter((a) => {
        if (status && a.status !== status) return false;
        if (!q) return true;
        const name = a.client ? `${a.client.first_name} ${a.client.last_name}`.toLowerCase() : '';
        return name.includes(q) || (a.medication?.name ?? '').toLowerCase().includes(q);
    });

    const footer = (
        <>
            <Button variant="ghost" onClick={onClose}>
                Close
            </Button>
            <div className="flex items-center gap-2">
                <Button variant="outline" onClick={() => (window.location.href = '/emar/audit/export')}>
                    <Download className="h-4 w-4" />
                    Export CSV
                </Button>
                <Button asChild>
                    <Link href="/emar/audit">
                        <FileText className="h-4 w-4" />
                        Open full audit log
                    </Link>
                </Button>
            </div>
        </>
    );

    return (
        <MedsWizardDialog
            open={open}
            onClose={onClose}
            title="Medication audit log"
            description="Read-only view of recent medication administration activity."
            railIcon={History}
            railTitle="Audit log"
            railSubtitle="Recent activity"
            steps={[{ key: 'log', label: 'Audit log', blurb: 'Recent medication activity', icon: History }]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={footer}
        >
            <StepHead icon={History} title="Recent medication activity" blurb="Immutable record of recent administrations. Filter below; the full history is on the audit page." />
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <div className="w-full sm:max-w-xs">
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search client or medication…" />
                </div>
                <div className="w-40">
                    <SelectInput
                        value={status}
                        onChange={setStatus}
                        placeholder="All outcomes"
                        options={[
                            { value: '', label: 'All outcomes' },
                            { value: 'given', label: 'Given' },
                            { value: 'refused', label: 'Refused' },
                            { value: 'withheld', label: 'Withheld' },
                            { value: 'missed', label: 'Missed' },
                        ]}
                    />
                </div>
            </div>
            <div className="overflow-hidden rounded-lg border border-border">
                {rows.length === 0 ? (
                    <p className="py-10 text-center text-sm text-muted-foreground">No matching activity.</p>
                ) : (
                    <ul className="divide-y divide-border">
                        {rows.map((a) => {
                            const meta = STATUS_META[a.status] ?? STATUS_META.pending;
                            return (
                                <li key={a.id} className="flex items-center gap-3 px-3 py-2.5">
                                    <span className={cn('grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-bold', meta.chip)}>
                                        {meta.mark}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-[13px] font-semibold">
                                            {a.client ? `${a.client.first_name} ${a.client.last_name}` : 'Client'} · {a.medication?.name ?? 'Medication'}
                                        </p>
                                        <p className="truncate text-[11px] text-muted-foreground">
                                            {meta.label}
                                            {a.administered_by?.name ? ` · by ${a.administered_by.name}` : ''}
                                        </p>
                                    </div>
                                    <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                        {a.administered_at ?? ''}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </MedsWizardDialog>
    );
}

export default AuditLogModal;
