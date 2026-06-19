/* eslint-disable no-restricted-syntax -- Awaiting-signature cards, folder tiles
 * and the Review & sign action are bespoke surfaces sized to the design handoff. */
import { router } from '@inertiajs/react';
import {
    Award,
    Download,
    Eye,
    FileText,
    Folder,
    PenLine,
    ScrollText,
    ShieldCheck,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    MyHrEsignDialog,
    MyHrShell,
    type MyHrShellData,
    type PendingSignature,
} from '@/components/hr';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { Card } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

interface Doc {
    id: number;
    title: string | null;
    category: string | null;
    folder: string | null;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    expires_at: string | null;
    signed_by_employee: boolean;
    created_at: string;
}

interface Props {
    myHr: MyHrShellData;
    pendingSignatures: PendingSignature[];
    documents: Doc[];
    categories: string[];
}

const FOLDER_ICON: Record<string, { icon: typeof Folder; tone: string }> = {
    contracts: { icon: FileText, tone: 'bg-accent text-primary' },
    contract: { icon: FileText, tone: 'bg-accent text-primary' },
    compliance: { icon: ShieldCheck, tone: 'bg-status-info-bg text-status-info' },
    policy: { icon: ScrollText, tone: 'bg-status-warning-bg text-status-warning' },
    payslips: { icon: Wallet, tone: 'bg-status-success-bg text-status-success' },
    certificate: { icon: Award, tone: 'bg-status-warning-bg text-status-warning' },
    certificates: { icon: Award, tone: 'bg-status-warning-bg text-status-warning' },
};

function folderMeta(name: string) {
    return (
        FOLDER_ICON[name.toLowerCase()] ?? {
            icon: Folder,
            tone: 'bg-muted text-muted-foreground',
        }
    );
}

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** Expiry → StatusBadge. */
function expiry(iso: string | null): { label: string; variant: StatusVariant } | null {
    if (!iso) return null;
    const days = Math.round(
        (new Date(iso).getTime() - Date.now()) / 86_400_000,
    );
    if (days < 0) return { label: `Expired ${fmtDate(iso)}`, variant: 'critical' };
    if (days <= 60) return { label: `Expires ${fmtDate(iso)}`, variant: 'warning' };
    return { label: `Valid to ${new Date(iso).getFullYear()}`, variant: 'success' };
}

function downloadDoc(id: number) {
    const a = document.createElement('a');
    a.href = `/hr/my/documents/${id}/download`;
    document.body.appendChild(a);
    a.click();
    a.remove();
}

export default function MyDocuments({ myHr, pendingSignatures, documents }: Props) {
    const [signing, setSigning] = useState<PendingSignature | null>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    const folders = useMemo(() => {
        const map = new Map<string, number>();
        for (const d of documents) {
            const key = d.folder ?? (d.category ? titleCase(d.category) : 'Other');
            map.set(key, (map.get(key) ?? 0) + 1);
        }
        return Array.from(map.entries()).map(([name, count]) => ({ name, count }));
    }, [documents]);

    function openCtx(e: React.MouseEvent, d: Doc) {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: d.folder ?? d.category ?? 'Doc',
            tagBg: 'var(--muted)',
            tagColor: 'var(--muted-foreground)',
            meta: d.title ?? d.original_name,
            items: [
                {
                    icon: <Eye className="h-4 w-4" />,
                    label: 'View details',
                    onClick: () =>
                        toast.info(d.title ?? d.original_name, {
                            description: `${d.folder ?? titleCase(d.category ?? 'Document')} · added ${fmtDate(d.created_at)}`,
                        }),
                },
                {
                    icon: <Download className="h-4 w-4" />,
                    label: 'Download',
                    kbd: 'D',
                    onClick: () => downloadDoc(d.id),
                },
            ],
        });
    }

    return (
        <MyHrShell active="documents" myHr={myHr} title="Documents · My HR">
            <div className="flex flex-col gap-5">
                {/* Awaiting signature */}
                {pendingSignatures.length > 0 ? (
                    <div className="rounded-[16px] bg-gradient-to-br from-status-warning to-status-warning/70 p-[18px] text-white shadow-[var(--shadow-float)]">
                        <div className="flex items-center gap-2.5">
                            <PenLine className="h-[17px] w-[17px]" />
                            <h2 className="text-base font-bold">
                                Awaiting your signature
                            </h2>
                        </div>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {pendingSignatures.map((s) => (
                                <div
                                    key={s.id}
                                    className="flex items-center gap-3 rounded-[12px] bg-card p-3.5 text-card-foreground"
                                >
                                    <span className="grid h-[38px] w-[38px] shrink-0 place-items-center rounded-[10px] bg-status-warning-bg text-status-warning">
                                        <FileText className="h-[18px] w-[18px]" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] font-bold">
                                            {s.document_title}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {s.requested_by
                                                ? `Sent by ${s.requested_by}`
                                                : 'Sent by HR'}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setSigning(s)}
                                        className="shrink-0 rounded-[9px] bg-status-warning px-3.5 py-2 text-[12.5px] font-bold text-white"
                                    >
                                        Review &amp; sign
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                {/* Your folders */}
                {folders.length > 0 ? (
                    <div>
                        <h3 className="mb-3 text-sm font-bold">Your folders</h3>
                        <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
                            {folders.map((f) => {
                                const m = folderMeta(f.name);
                                const Icon = m.icon;
                                return (
                                    <Card key={f.name} className="p-4">
                                        <span
                                            className={cn(
                                                'grid h-[38px] w-[38px] place-items-center rounded-[10px]',
                                                m.tone,
                                            )}
                                        >
                                            <Icon className="h-[18px] w-[18px]" />
                                        </span>
                                        <div className="mt-2.5 text-[13.5px] font-bold">
                                            {titleCase(f.name)}
                                        </div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            {f.count} document{f.count === 1 ? '' : 's'}
                                        </div>
                                    </Card>
                                );
                            })}
                        </div>
                    </div>
                ) : null}

                {/* All documents */}
                <Card className="overflow-hidden p-0">
                    <div className="px-[18px] pb-2 pt-4 text-sm font-bold">
                        All documents
                    </div>
                    {documents.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                            <FileText className="h-8 w-8 text-muted-foreground/40" />
                            <div className="text-sm font-semibold">No documents yet</div>
                            <p className="max-w-sm text-[13px] text-muted-foreground">
                                Your contracts, certificates and letters from HR will appear
                                here.
                            </p>
                        </div>
                    ) : (
                        <div className="px-2 pb-2">
                            {documents.map((d) => {
                                const exp = expiry(d.expires_at);
                                return (
                                    <div
                                        key={d.id}
                                        onContextMenu={(e) => openCtx(e, d)}
                                        className="flex items-center gap-3 rounded-[11px] px-2.5 py-3 transition-colors hover:bg-muted"
                                    >
                                        <span className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[9px] bg-accent text-primary">
                                            <FileText className="h-4 w-4" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate text-[13px] font-semibold">
                                                {d.title ?? d.original_name}
                                            </div>
                                            <div className="text-[11.5px] text-muted-foreground">
                                                {d.folder ?? titleCase(d.category ?? 'Document')}{' '}
                                                · added {fmtDate(d.created_at)}
                                            </div>
                                        </div>
                                        {d.signed_by_employee ? (
                                            <StatusBadge variant="success" size="sm">
                                                Signed
                                            </StatusBadge>
                                        ) : null}
                                        {exp ? (
                                            <StatusBadge variant={exp.variant} size="sm">
                                                {exp.label}
                                            </StatusBadge>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Card>
            </div>

            <MyHrEsignDialog signature={signing} onClose={() => setSigning(null)} />
            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
        </MyHrShell>
    );
}
