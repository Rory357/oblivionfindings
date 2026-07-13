/* eslint-disable no-restricted-syntax -- The Documents hub is a bespoke command
 * surface: an underline tab strip with right-click menu, employee→folder drill
 * tiles, a filterable table with a bulk bar, a slide-over viewer with an audit
 * trail, and sender-side signature cards. These are custom layouts (raw
 * <button>/<table>/<select>) rather than shadcn <Button>/<Card> cases; every
 * colour stays a semantic design token. */
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    Award,
    Bell,
    Check,
    ChevronRight,
    Clock,
    Copy,
    Download,
    Eye,
    FileCheck,
    FileText,
    Folder,
    FolderOpen,
    FolderPlus,
    Lock,
    Mail,
    MoreHorizontal,
    Pencil,
    PenLine,
    Plus,
    RotateCcw,
    Scroll,
    Search,
    Send,
    Shield,
    Trash2,
    Upload,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { DOC_CATEGORY_ICON } from '@/components/hr/document-library-kit';
import { DocumentsHero, type DocsHeroNeed } from '@/components/hr/documents-hero';
import { useLeaveContextMenu, type LeaveCtxItem } from '@/components/hr/leave-context-menu';
import { TextPromptDialog } from '@/components/hr/text-prompt-dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';

/** Payload for the shared confirm dialog used across the Documents hub. */
type ConfirmRequest = { title: string; body: string; confirmLabel: string; onConfirm: () => void };
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

import {
    GenerateWizard,
    SendWizard,
    TemplateWizard,
    UploadWizard,
    type WizDoc,
    type WizEmployee,
    type WizTemplate,
} from './documents-wizards';

/* ------------------------------------------------------------------ */
/*  Types                                                             */
/* ------------------------------------------------------------------ */

type Expiry = { date: string; status: 'valid' | 'expiring' | 'expired'; label: string };

interface DocRow {
    id: number;
    title: string;
    category: string;
    folder: string;
    version: number;
    employee: { id: number; user_id: number | null; name: string; initials: string } | null;
    signature: 'signed' | 'pending' | 'declined' | null;
    expiry: Expiry | null;
    is_restricted: boolean;
    generated_from_template: boolean;
    mime_type: string | null;
    original_name: string | null;
    size_bytes: number | null;
    created_at: string | null;
    created_by: string;
    has_signed_pdf: boolean;
}

interface SigSigner {
    id: number;
    user_id: number;
    name: string;
    initials: string;
    status: 'pending' | 'signed' | 'declined';
}

interface SigRequest {
    document_id: number;
    title: string;
    category: string;
    status: 'awaiting' | 'signed' | 'declined';
    order: string;
    sent: string | null;
    due: string | null;
    overdue: boolean;
    requested_by: string;
    progress: string;
    signed_count: number;
    signer_count: number;
    signed_at: string | null;
    decline_reason: string | null;
    has_signed_pdf: boolean;
    signers: SigSigner[];
}

interface Template {
    id: number;
    name: string;
    category: string;
    version: number;
    is_active: boolean;
    approval_required: boolean;
    merge_fields: string[];
    updated_at: string | null;
}

interface Policy {
    id: number;
    name: string;
    version: number;
    owner: string;
    requires_attestation: boolean;
    attested: number;
    total: number;
    overdue: number;
}

interface Props {
    documents: DocRow[];
    signatureRequests: SigRequest[];
    templates: Template[];
    policies: Policy[];
    employees: WizEmployee[];
    categories: string[];
    stats: { on_file: number; awaiting: number; expiring: number; templates: number; declined: number };
    signatureCompletion: { signed: number; total: number; requests: number };
    recent: DocRow[];
    can: {
        manage: boolean;
        policies_view: boolean;
        policies_manage: boolean;
        signatures_manage: boolean;
    };
}

type Tab = 'library' | 'signatures' | 'templates' | 'policies';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Documents', href: '/hr/documents' },
];

const FOLDER_META: Record<string, { icon: LucideIcon; bg: string; fg: string }> = {
    Contracts: { icon: FileText, bg: 'bg-accent', fg: 'text-primary' },
    Compliance: { icon: Shield, bg: 'bg-status-info-bg', fg: 'text-status-info' },
    Certificates: { icon: Award, bg: 'bg-status-warning-bg', fg: 'text-status-warning' },
    Letters: { icon: Mail, bg: 'bg-status-success-bg', fg: 'text-status-success' },
    Payslips: { icon: FileText, bg: 'bg-status-success-bg', fg: 'text-status-success' },
    Policies: { icon: Scroll, bg: 'bg-status-warning-bg', fg: 'text-status-warning' },
};

/* ------------------------------------------------------------------ */
/*  Small shared bits                                                 */
/* ------------------------------------------------------------------ */

type Tone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

const TONE_CLASS: Record<Tone, string> = {
    success: 'border-status-success/30 bg-status-success-bg text-status-success',
    warning: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    critical: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
    info: 'border-status-info/30 bg-status-info-bg text-status-info',
    neutral: 'border-border bg-muted text-muted-foreground',
};

function Badge({
    tone,
    children,
    icon: Icon,
    sm,
}: {
    tone: Tone;
    children: React.ReactNode;
    icon?: LucideIcon;
    sm?: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 whitespace-nowrap rounded-full border font-semibold',
                TONE_CLASS[tone],
                sm ? 'px-1.5 py-0.5 text-[10.5px]' : 'px-2 py-0.5 text-[11.5px]',
            )}
        >
            {Icon ? <Icon className={sm ? 'h-3 w-3' : 'h-3.5 w-3.5'} /> : null}
            {children}
        </span>
    );
}

const TYPE_TONE: Record<string, Tone> = {
    contract: 'info',
    policy: 'warning',
    certificate: 'success',
    letter: 'neutral',
    offer: 'info',
    payslip: 'neutral',
    other: 'neutral',
};

function TypeBadge({ category }: { category: string }) {
    return (
        <Badge tone={TYPE_TONE[category] ?? 'neutral'} sm>
            {category.charAt(0).toUpperCase() + category.slice(1)}
        </Badge>
    );
}

function SigBadge({ status }: { status: DocRow['signature'] }) {
    if (status === 'signed') return <Badge tone="success" icon={Check} sm>Signed</Badge>;
    if (status === 'pending') return <Badge tone="warning" icon={Clock} sm>Awaiting</Badge>;
    if (status === 'declined') return <Badge tone="critical" icon={AlertTriangle} sm>Declined</Badge>;
    return <span className="text-[11.5px] text-muted-foreground">—</span>;
}

function Avatar({ initials, size = 26 }: { initials: string; size?: number }) {
    return (
        <span
            className="grid flex-none place-items-center rounded-full bg-primary/15 font-bold text-primary"
            style={{ width: size, height: size, fontSize: size * 0.38 }}
        >
            {initials}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                              */
/* ------------------------------------------------------------------ */

export default function DocumentsIndex(props: Props) {
    const { documents, signatureRequests, templates, policies, employees, stats, can } = props;

    const [tab, setTab] = useState<Tab>('library');
    const [defaultTab, setDefaultTab] = useState<Tab>('library');
    const [pinned, setPinned] = useState<Tab[]>([]);

    // Library state
    const [emp, setEmp] = useState<string | null>(null);
    const [folder, setFolder] = useState<string | null>(null);
    const [q, setQ] = useState('');
    const [category, setCategory] = useState('all');
    const [expiry, setExpiry] = useState('all');
    const [restrictedOnly, setRestrictedOnly] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);

    const [sigSeg, setSigSeg] = useState<'awaiting' | 'signed' | 'declined' | 'all'>('awaiting');
    const [viewer, setViewer] = useState<DocRow | null>(null);

    // Wizards
    const [wizard, setWizard] = useState<null | 'upload' | 'generate' | 'send' | 'template'>(null);
    const [sendDocId, setSendDocId] = useState<number | null>(null);
    const [genTemplateId, setGenTemplateId] = useState<number | null>(null);

    const ctx = useLeaveContextMenu();

    // Deep-link ?tab= + persisted default/pinned
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const urlTab = params.get('tab') as Tab | null;
        const storedDefault = window.localStorage.getItem('hrDocs.defaultTab') as Tab | null;
        const storedPinned = window.localStorage.getItem('hrDocs.pinned');
        if (storedDefault) setDefaultTab(storedDefault);
        if (storedPinned) {
            try {
                setPinned(JSON.parse(storedPinned));
            } catch {
                /* ignore */
            }
        }
        const valid: Tab[] = ['library', 'signatures', 'templates', 'policies'];
        if (urlTab && valid.includes(urlTab)) setTab(urlTab);
        else if (storedDefault && valid.includes(storedDefault)) setTab(storedDefault);
    }, []);

    const goTab = (t: Tab) => {
        setTab(t);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', t);
        window.history.replaceState({}, '', url);
    };

    /* ---- derived ---- */
    const filteredDocs = useMemo(() => {
        return documents.filter((d) => {
            const key = d.employee ? `e${d.employee.id}` : 'org';
            if (emp && key !== emp) return false;
            if (folder && d.folder !== folder) return false;
            if (category !== 'all' && d.category !== category) return false;
            if (restrictedOnly && !d.is_restricted) return false;
            if (expiry !== 'all') {
                if (!d.expiry) return false;
                if (expiry === 'expiring' && d.expiry.status !== 'expiring') return false;
                if (expiry === 'expired' && d.expiry.status !== 'expired') return false;
                if (expiry === 'valid' && d.expiry.status !== 'valid') return false;
            }
            if (q) {
                const hay = `${d.title} ${d.employee?.name ?? ''}`.toLowerCase();
                if (!hay.includes(q.toLowerCase())) return false;
            }
            return true;
        });
    }, [documents, emp, folder, category, expiry, restrictedOnly, q]);

    const needs: DocsHeroNeed[] = [];
    if (stats.awaiting > 0)
        needs.push({ key: 'await', label: `${stats.awaiting} awaiting signature`, icon: PenLine, onClick: () => goTab('signatures') });
    if (stats.declined > 0)
        needs.push({ key: 'declined', label: `${stats.declined} declined`, icon: AlertTriangle, onClick: () => { setSigSeg('declined'); goTab('signatures'); } });
    if (stats.expiring > 0)
        needs.push({ key: 'exp', label: `${stats.expiring} expiring / expired`, icon: Clock, onClick: () => { setExpiry('expiring'); setEmp(null); setFolder(null); goTab('library'); } });

    const openWizard = (kind: 'upload' | 'generate' | 'send' | 'template') => {
        if (kind === 'send') setSendDocId(null);
        if (kind === 'generate') setGenTemplateId(null);
        setWizard(kind);
    };

    /* ---- counts ---- */
    const counts: Record<Tab, number> = {
        library: documents.length,
        signatures: signatureRequests.filter((s) => s.status === 'awaiting').length,
        templates: templates.length,
        policies: policies.length,
    };

    const wizEmployees: WizEmployee[] = employees;
    const wizTemplates: WizTemplate[] = templates;
    const wizDocs: WizDoc[] = documents.map((d) => ({
        id: d.id,
        title: d.title,
        folder: d.folder,
        category: d.category,
        signature: d.signature,
    }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Documents" />
            <div className="flex flex-col gap-0 p-4 sm:p-6">
                <DocumentsHero
                    stats={stats}
                    signatureCompletion={props.signatureCompletion}
                    recent={props.recent}
                    canManage={can.manage}
                    needs={needs}
                    handlers={{
                        onUpload: () => openWizard('upload'),
                        onGenerate: () => openWizard('generate'),
                        onSend: () => openWizard('send'),
                        onTemplate: () => openWizard('template'),
                        onStatOnFile: () => goTab('library'),
                        onStatAwaiting: () => goTab('signatures'),
                        onStatExpiring: () => { setExpiry('expiring'); setEmp(null); setFolder(null); goTab('library'); },
                        onStatTemplates: () => goTab('templates'),
                        onViewDoc: (id) => {
                            const d = documents.find((x) => x.id === id);
                            if (d) setViewer(d);
                        },
                    }}
                />

                {/* Tab strip */}
                <div className="mb-[18px] mt-[22px] flex items-end gap-1 border-b border-border">
                    <TabBtn id="library" label="Library" count={counts.library} active={tab === 'library'} pinned={pinned.includes('library')} isDefault={defaultTab === 'library'} onClick={() => goTab('library')} onCtx={ctx.open} setPinned={setPinned} setDefault={setDefaultTab} />
                    <TabBtn id="signatures" label="Signatures" count={counts.signatures} active={tab === 'signatures'} pinned={pinned.includes('signatures')} isDefault={defaultTab === 'signatures'} onClick={() => goTab('signatures')} onCtx={ctx.open} setPinned={setPinned} setDefault={setDefaultTab} />
                    <TabBtn id="templates" label="Templates" count={counts.templates} active={tab === 'templates'} pinned={pinned.includes('templates')} isDefault={defaultTab === 'templates'} onClick={() => goTab('templates')} onCtx={ctx.open} setPinned={setPinned} setDefault={setDefaultTab} />
                    <TabBtn id="policies" label="Policies" count={counts.policies} active={tab === 'policies'} pinned={pinned.includes('policies')} isDefault={defaultTab === 'policies'} onClick={() => goTab('policies')} onCtx={ctx.open} setPinned={setPinned} setDefault={setDefaultTab} />
                </div>

                {/* Body */}
                {tab === 'library' ? (
                    <LibraryTab
                        documents={documents}
                        filteredDocs={filteredDocs}
                        emp={emp}
                        setEmp={setEmp}
                        folder={folder}
                        setFolder={setFolder}
                        q={q}
                        setQ={setQ}
                        category={category}
                        setCategory={setCategory}
                        expiry={expiry}
                        setExpiry={setExpiry}
                        restrictedOnly={restrictedOnly}
                        setRestrictedOnly={setRestrictedOnly}
                        selected={selected}
                        setSelected={setSelected}
                        canManage={can.manage}
                        onView={(d) => setViewer(d)}
                        onSend={(d) => { setSendDocId(d.id); setWizard('send'); }}
                        openCtx={ctx.open}
                    />
                ) : null}

                {tab === 'signatures' ? (
                    <SignaturesTab
                        requests={signatureRequests}
                        seg={sigSeg}
                        setSeg={setSigSeg}
                        canManage={can.signatures_manage}
                        onSend={() => openWizard('send')}
                        openCtx={ctx.open}
                    />
                ) : null}

                {tab === 'templates' ? (
                    <TemplatesTab
                        templates={templates}
                        canManage={can.manage}
                        onNew={() => openWizard('template')}
                        onGenerate={(id) => { setGenTemplateId(id); setWizard('generate'); }}
                        openCtx={ctx.open}
                    />
                ) : null}

                {tab === 'policies' ? (
                    <PoliciesTab policies={policies} canView={can.policies_view} canManage={can.policies_manage} />
                ) : null}
            </div>

            {ctx.element}

            {/* Inline viewer */}
            <DocViewer doc={viewer} onClose={() => setViewer(null)} onSend={(d) => { setViewer(null); setSendDocId(d.id); setWizard('send'); }} />

            {/* Wizards */}
            <UploadWizard open={wizard === 'upload'} onClose={() => setWizard(null)} employees={wizEmployees} />
            <GenerateWizard open={wizard === 'generate'} onClose={() => setWizard(null)} employees={wizEmployees} templates={wizTemplates} initialTemplateId={genTemplateId} />
            <SendWizard open={wizard === 'send'} onClose={() => setWizard(null)} employees={wizEmployees} documents={wizDocs} initialDocId={sendDocId} />
            <TemplateWizard open={wizard === 'template'} onClose={() => setWizard(null)} />
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Tab button                                                        */
/* ------------------------------------------------------------------ */

function TabBtn({
    id,
    label,
    count,
    active,
    pinned,
    isDefault,
    onClick,
    onCtx,
    setPinned,
    setDefault,
}: {
    id: Tab;
    label: string;
    count: number;
    active: boolean;
    pinned: boolean;
    isDefault: boolean;
    onClick: () => void;
    onCtx: (items: LeaveCtxItem[]) => (e: React.MouseEvent) => void;
    setPinned: React.Dispatch<React.SetStateAction<Tab[]>>;
    setDefault: React.Dispatch<React.SetStateAction<Tab>>;
}) {
    const menu: LeaveCtxItem[] = [
        {
            kind: 'item',
            label: 'Set as default view',
            icon: Check,
            onSelect: () => {
                setDefault(id);
                window.localStorage.setItem('hrDocs.defaultTab', id);
                toast.success(`Default view set to ${label}`);
            },
        },
        { kind: 'item', label: 'Open', icon: FolderOpen, onSelect: onClick },
        {
            kind: 'item',
            label: pinned ? 'Unpin tab' : 'Pin tab',
            icon: Clock,
            onSelect: () =>
                setPinned((prev) => {
                    const next = prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id];
                    window.localStorage.setItem('hrDocs.pinned', JSON.stringify(next));
                    return next;
                }),
        },
    ];

    return (
        <button
            type="button"
            onClick={onClick}
            onContextMenu={onCtx(menu)}
            className={cn(
                '-mb-px inline-flex items-center gap-2 px-4 pb-3 pt-2.5 text-[13.5px] font-semibold transition-colors',
                active
                    ? 'border-b-2 border-primary text-primary'
                    : 'border-b-2 border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            {pinned ? <Clock className="h-3 w-3 text-[color:var(--hr-amber)]" /> : null}
            {label}
            <span
                className={cn(
                    'inline-flex min-w-[20px] justify-center rounded-full px-1.5 py-px text-[11px] font-bold',
                    active ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground',
                )}
            >
                {count}
            </span>
            {isDefault ? <span className="h-[5px] w-[5px] rounded-full bg-primary" title="Default view" /> : null}
        </button>
    );
}

/* ------------------------------------------------------------------ */
/*  Library tab                                                       */
/* ------------------------------------------------------------------ */

function LibraryTab({
    documents,
    filteredDocs,
    emp,
    setEmp,
    folder,
    setFolder,
    q,
    setQ,
    category,
    setCategory,
    expiry,
    setExpiry,
    restrictedOnly,
    setRestrictedOnly,
    selected,
    setSelected,
    canManage,
    onView,
    onSend,
    openCtx,
}: {
    documents: DocRow[];
    filteredDocs: DocRow[];
    emp: string | null;
    setEmp: (v: string | null) => void;
    folder: string | null;
    setFolder: (v: string | null) => void;
    q: string;
    setQ: (v: string) => void;
    category: string;
    setCategory: (v: string) => void;
    expiry: string;
    setExpiry: (v: string) => void;
    restrictedOnly: boolean;
    setRestrictedOnly: (v: boolean) => void;
    selected: number[];
    setSelected: React.Dispatch<React.SetStateAction<number[]>>;
    canManage: boolean;
    onView: (d: DocRow) => void;
    onSend: (d: DocRow) => void;
    openCtx: (items: LeaveCtxItem[]) => (e: React.MouseEvent) => void;
}) {
    // Employee buckets (level 0)
    const buckets = useMemo(() => {
        const map = new Map<string, { key: string; name: string; initials: string; org: boolean; docs: DocRow[] }>();
        for (const d of documents) {
            const key = d.employee ? `e${d.employee.id}` : 'org';
            if (!map.has(key)) {
                map.set(key, {
                    key,
                    name: d.employee ? d.employee.name : 'Organisation-wide',
                    initials: d.employee ? d.employee.initials : 'ALL',
                    org: !d.employee,
                    docs: [],
                });
            }
            map.get(key)!.docs.push(d);
        }
        return Array.from(map.values()).sort((a, b) => (a.org ? 1 : 0) - (b.org ? 1 : 0));
    }, [documents]);

    const empBucket = emp ? buckets.find((b) => b.key === emp) : null;
    const folderCounts = useMemo(() => {
        if (!empBucket) return [] as { name: string; count: number }[];
        const m = new Map<string, number>();
        for (const d of empBucket.docs) m.set(d.folder, (m.get(d.folder) ?? 0) + 1);
        return Array.from(m.entries()).map(([name, count]) => ({ name, count }));
    }, [empBucket]);

    const allSelected = filteredDocs.length > 0 && filteredDocs.every((d) => selected.includes(d.id));

    const bulkPost = (url: string, extra: Record<string, unknown> = {}, msg?: string) => {
        router.post(url, { ids: selected, ...extra }, {
            preserveScroll: true,
            onSuccess: () => { setSelected([]); if (msg) toast.success(msg); },
        });
    };

    // Native window.confirm()/prompt() break the hub's design language — route
    // destructive confirms through AlertDialog and the folder prompt through the
    // kit TextPromptDialog. One confirm state serves the bulk bar and every row.
    const [confirmState, setConfirmState] = useState<ConfirmRequest | null>(null);
    const [moveOpen, setMoveOpen] = useState(false);

    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0">
            {/* Folder heading + breadcrumb */}
            <div className="mb-3 flex items-center justify-between">
                <h3 className="flex items-center gap-2 text-[13px] font-bold">
                    <Crumb label="Employees" onClick={() => { setEmp(null); setFolder(null); }} last={!emp} />
                    {emp ? <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" /> : null}
                    {emp ? <Crumb label={empBucket?.name ?? ''} onClick={() => setFolder(null)} last={!folder} /> : null}
                    {folder ? <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" /> : null}
                    {folder ? <Crumb label={folder} last /> : null}
                </h3>
            </div>

            {/* Tiles */}
            <div className="mb-[22px] grid gap-[11px]" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(204px,1fr))' }}>
                {!emp
                    ? buckets.map((b) => {
                          const exp = b.docs.filter((d) => d.expiry && d.expiry.status !== 'valid').length;
                          const pend = b.docs.filter((d) => d.signature === 'pending').length;
                          const sub = `${b.docs.length} doc${b.docs.length === 1 ? '' : 's'}${exp ? ` · ${exp} expiring` : pend ? ` · ${pend} to sign` : ''}`;
                          return (
                              <Tile
                                  key={b.key}
                                  active={false}
                                  onClick={() => { setEmp(b.key); setFolder(null); }}
                                  leading={
                                      b.org ? (
                                          <span className="grid h-[38px] w-[38px] flex-none place-items-center rounded-xl bg-status-warning-bg text-status-warning">
                                              <Users className="h-[19px] w-[19px]" />
                                          </span>
                                      ) : (
                                          <Avatar initials={b.initials} size={38} />
                                      )
                                  }
                                  title={b.name}
                                  sub={sub}
                              />
                          );
                      })
                    : folderCounts.map((f) => {
                          const m = FOLDER_META[f.name] ?? { icon: Folder, bg: 'bg-muted', fg: 'text-muted-foreground' };
                          const Icon = m.icon;
                          const active = folder === f.name;
                          return (
                              <Tile
                                  key={f.name}
                                  active={active}
                                  onClick={() => setFolder(active ? null : f.name)}
                                  leading={
                                      <span className={cn('grid h-[38px] w-[38px] flex-none place-items-center rounded-xl', m.bg, m.fg)}>
                                          <Icon className="h-[19px] w-[19px]" />
                                      </span>
                                  }
                                  title={f.name}
                                  sub={`${f.count} document${f.count === 1 ? '' : 's'}`}
                              />
                          );
                      })}
            </div>

            {/* Toolbar */}
            <div className="mb-3.5 flex flex-wrap items-center gap-2.5">
                <div className="relative min-w-[220px] max-w-[340px] flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="text"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search title, file or employee…"
                        className="h-[38px] w-full rounded-[10px] border border-border bg-card pl-[34px] pr-3 text-[13px] outline-none focus:border-ring focus:ring-2 focus:ring-ring/20"
                    />
                </div>
                <select value={category} onChange={(e) => setCategory(e.target.value)} aria-label="Type filter" className="h-[38px] rounded-[10px] border border-border bg-card px-2.5 text-[13px]">
                    <option value="all">All types</option>
                    {['contract', 'certificate', 'policy', 'letter', 'offer', 'payslip'].map((c) => (
                        <option key={c} value={c}>{c.charAt(0).toUpperCase() + c.slice(1)}</option>
                    ))}
                </select>
                <select value={expiry} onChange={(e) => setExpiry(e.target.value)} aria-label="Expiry filter" className="h-[38px] rounded-[10px] border border-border bg-card px-2.5 text-[13px]">
                    <option value="all">Any expiry</option>
                    <option value="valid">Valid</option>
                    <option value="expiring">Expiring ≤60d</option>
                    <option value="expired">Expired</option>
                </select>
                <button
                    type="button"
                    onClick={() => setRestrictedOnly(!restrictedOnly)}
                    className={cn(
                        'inline-flex h-[38px] items-center gap-1.5 rounded-[10px] border px-3 text-[13px] font-semibold',
                        restrictedOnly ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground',
                    )}
                >
                    <Lock className="h-3.5 w-3.5" /> Restricted only
                </button>
                <div className="flex-1" />
                <a
                    href={`/hr/documents/export${category !== 'all' ? `?category=${category}` : ''}`}
                    className="inline-flex h-[38px] items-center gap-1.5 rounded-[10px] border border-border bg-card px-3.5 text-[13px] font-semibold text-foreground hover:bg-muted"
                >
                    <Download className="h-3.5 w-3.5" /> Export
                </a>
            </div>

            {/* Bulk bar */}
            {selected.length > 0 && canManage ? (
                <div className="mb-3 flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/[0.06] px-3.5 py-2.5 motion-safe:animate-in motion-safe:zoom-in-95">
                    <span className="text-[13px] font-bold text-primary">{selected.length} selected</span>
                    <span className="h-[18px] w-px bg-border" />
                    <BulkBtn icon={Download} label="Download" onClick={() => { window.location.href = `/hr/documents/bulk-download?` + selected.map((id) => `ids[]=${id}`).join('&'); }} />
                    <BulkBtn icon={Folder} label="Move" onClick={() => setMoveOpen(true)} />
                    <BulkBtn icon={PenLine} label="Send" onClick={() => onSend(filteredDocs.find((d) => d.id === selected[0]) ?? filteredDocs[0])} />
                    <BulkBtn icon={Archive} label="Archive" onClick={() => setConfirmState({ title: 'Archive documents?', body: `Move ${selected.length} document(s) to the Archive folder while retaining their files and history?`, confirmLabel: 'Archive', onConfirm: () => bulkPost('/hr/documents/bulk-delete', {}, 'Archived') })} />
                    <div className="flex-1" />
                    <button type="button" onClick={() => setSelected([])} className="text-xs text-muted-foreground hover:text-foreground">Clear</button>
                </div>
            ) : null}

            {/* Table */}
            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                <div className="grid items-center gap-3 border-b border-border bg-muted px-4 py-3 text-[11px] font-bold uppercase tracking-wide text-muted-foreground" style={{ gridTemplateColumns: '38px 1fr 150px 130px 150px 70px 40px' }}>
                    <CheckBox checked={allSelected} onClick={() => setSelected(allSelected ? [] : filteredDocs.map((d) => d.id))} />
                    <span>Document</span>
                    <span>Employee</span>
                    <span>Signature</span>
                    <span>Expiry</span>
                    <span>Version</span>
                    <span />
                </div>
                {filteredDocs.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-5 py-[52px] text-center">
                        <Search className="h-7 w-7 text-muted-foreground/40" />
                        <div className="font-bold">No documents match</div>
                        <div className="max-w-[320px] text-[13px] text-muted-foreground">Try clearing a filter, or upload a new document.</div>
                    </div>
                ) : (
                    filteredDocs.map((d, i) => {
                        const sel = selected.includes(d.id);
                        const Icon = DOC_CATEGORY_ICON[d.category] ?? FileText;
                        const rowMenu = buildRowMenu(d, { onView, onSend, canManage, requestConfirm: setConfirmState });
                        return (
                            <div
                                key={d.id}
                                onContextMenu={openCtx(rowMenu)}
                                onClick={() => onView(d)}
                                className={cn(
                                    'grid cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-muted',
                                    i > 0 && 'border-t border-border',
                                    sel && 'bg-primary/[0.05]',
                                )}
                                style={{ gridTemplateColumns: '38px 1fr 150px 130px 150px 70px 40px' }}
                            >
                                <CheckBox
                                    checked={sel}
                                    onClick={(e) => { e?.stopPropagation(); setSelected((prev) => (prev.includes(d.id) ? prev.filter((x) => x !== d.id) : [...prev, d.id])); }}
                                />
                                <div className="flex min-w-0 items-center gap-3">
                                    <span className="grid h-[34px] w-[34px] flex-none place-items-center rounded-[9px] bg-accent text-primary">
                                        <Icon className="h-[17px] w-[17px]" />
                                    </span>
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-1.5">
                                            <span className="truncate text-[13.5px] font-semibold">{d.title}</span>
                                            {d.is_restricted ? <Lock className="h-3 w-3 text-muted-foreground" /> : null}
                                        </div>
                                        <div className="mt-0.5 flex items-center gap-1.5">
                                            <TypeBadge category={d.category} />
                                            <span className="truncate text-[11px] text-muted-foreground">{d.folder} · {d.created_by}</span>
                                        </div>
                                    </div>
                                </div>
                                {d.employee ? (
                                    <div className="flex min-w-0 items-center gap-2">
                                        <Avatar initials={d.employee.initials} />
                                        <span className="truncate text-[12.5px] font-medium">{d.employee.name}</span>
                                    </div>
                                ) : (
                                    <span className="text-[12px] text-muted-foreground">All staff</span>
                                )}
                                <div><SigBadge status={d.signature} /></div>
                                <div>
                                    {d.expiry ? (
                                        <Badge tone={d.expiry.status === 'expired' ? 'critical' : d.expiry.status === 'expiring' ? 'warning' : 'success'} sm>
                                            {d.expiry.status === 'expired' ? 'Expired ' : d.expiry.status === 'expiring' ? 'Expires ' : 'Valid to '}
                                            {d.expiry.status === 'valid' ? d.expiry.date.slice(0, 4) : d.expiry.label}
                                        </Badge>
                                    ) : (
                                        <span className="text-[11.5px] text-muted-foreground">No expiry</span>
                                    )}
                                </div>
                                <span className="text-[12px] text-muted-foreground">v{d.version}</span>
                                <button
                                    type="button"
                                    onClick={(e) => { e.stopPropagation(); openCtx(rowMenu)(e); }}
                                    aria-label="Row actions"
                                    className="grid h-[30px] w-[30px] place-items-center rounded-lg text-muted-foreground hover:bg-accent hover:text-primary"
                                >
                                    <MoreHorizontal className="h-[17px] w-[17px]" />
                                </button>
                            </div>
                        );
                    })
                )}
            </div>

            <AlertDialog open={confirmState !== null} onOpenChange={(o) => !o && setConfirmState(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{confirmState?.title}</AlertDialogTitle>
                        <AlertDialogDescription>{confirmState?.body}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => { confirmState?.onConfirm(); setConfirmState(null); }}>
                            {confirmState?.confirmLabel ?? 'Confirm'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <TextPromptDialog
                open={moveOpen}
                onClose={() => setMoveOpen(false)}
                onSubmit={(folder) => bulkPost('/hr/documents/move', { folder }, 'Documents moved')}
                title="Move documents"
                label="Destination folder"
                placeholder="e.g. Contracts"
                submitLabel="Move"
            />
        </div>
    );
}

function buildRowMenu(
    d: DocRow,
    { onView, onSend, canManage, requestConfirm }: { onView: (d: DocRow) => void; onSend: (d: DocRow) => void; canManage: boolean; requestConfirm: (req: ConfirmRequest) => void },
): LeaveCtxItem[] {
    const items: LeaveCtxItem[] = [
        { kind: 'item', label: 'Preview', icon: Eye, onSelect: () => onView(d) },
        { kind: 'item', label: 'Download', icon: Download, onSelect: () => { window.location.href = `/hr/documents/${d.id}/download`; } },
    ];
    if (d.has_signed_pdf)
        items.push({ kind: 'item', label: 'Download signed PDF', icon: FileCheck, onSelect: () => { window.location.href = `/hr/documents/${d.id}/signed`; } });
    if (canManage)
        items.push({ kind: 'item', label: 'Send for signature…', icon: PenLine, onSelect: () => onSend(d) });
    items.push({ kind: 'item', label: 'Copy link', icon: Copy, onSelect: () => { navigator.clipboard?.writeText(`${window.location.origin}/hr/documents/${d.id}/download`); toast.success('Link copied'); } });
    if (canManage) {
        items.push({ kind: 'divider' });
        items.push({ kind: 'item', label: 'Archive', icon: Archive, onSelect: () => requestConfirm({ title: 'Archive document?', body: `Move "${d.title}" to the Archive folder while retaining its file and history?`, confirmLabel: 'Archive', onConfirm: () => router.delete(`/hr/documents/${d.id}`, { preserveScroll: true }) }) });
    }
    return items;
}

/* ------------------------------------------------------------------ */
/*  Signatures tab (sender side)                                       */
/* ------------------------------------------------------------------ */

function SignaturesTab({
    requests,
    seg,
    setSeg,
    canManage,
    onSend,
    openCtx,
}: {
    requests: SigRequest[];
    seg: 'awaiting' | 'signed' | 'declined' | 'all';
    setSeg: (v: 'awaiting' | 'signed' | 'declined' | 'all') => void;
    canManage: boolean;
    onSend: () => void;
    openCtx: (items: LeaveCtxItem[]) => (e: React.MouseEvent) => void;
}) {
    const count = (k: typeof seg) => (k === 'all' ? requests.length : requests.filter((r) => r.status === k).length);
    const rows = requests.filter((r) => seg === 'all' || r.status === seg);
    const [confirmState, setConfirmState] = useState<ConfirmRequest | null>(null);

    const segs: { k: typeof seg; label: string }[] = [
        { k: 'awaiting', label: 'Awaiting signature' },
        { k: 'signed', label: 'Signed' },
        { k: 'declined', label: 'Declined' },
        { k: 'all', label: 'All' },
    ];

    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2.5">
                <div className="inline-flex gap-0.5 rounded-xl bg-muted p-1">
                    {segs.map((s) => (
                        <button
                            key={s.k}
                            type="button"
                            onClick={() => setSeg(s.k)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12.5px] font-semibold transition-colors',
                                seg === s.k ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {s.label}
                            <span className={cn('text-[11px] font-bold', seg === s.k ? 'text-primary' : 'text-muted-foreground')}>{count(s.k)}</span>
                        </button>
                    ))}
                </div>
                {canManage ? (
                    <button type="button" onClick={onSend} className="inline-flex h-[38px] items-center gap-1.5 rounded-[10px] bg-primary px-4 text-[13px] font-semibold text-primary-foreground">
                        <PenLine className="h-3.5 w-3.5" /> Send for signature
                    </button>
                ) : null}
            </div>

            {rows.length === 0 ? (
                <div className="rounded-[14px] border border-border bg-card px-5 py-12 text-center text-[13px] text-muted-foreground">
                    No signature requests in this view.
                </div>
            ) : (
                <div className="flex flex-col gap-2.5">
                    {rows.map((r) => (
                        <SigCard key={r.document_id} r={r} canManage={canManage} openCtx={openCtx} requestConfirm={setConfirmState} />
                    ))}
                </div>
            )}

            <AlertDialog open={confirmState !== null} onOpenChange={(o) => !o && setConfirmState(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{confirmState?.title}</AlertDialogTitle>
                        <AlertDialogDescription>{confirmState?.body}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep it</AlertDialogCancel>
                        <AlertDialogAction onClick={() => { confirmState?.onConfirm(); setConfirmState(null); }}>
                            {confirmState?.confirmLabel ?? 'Confirm'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

function SigCard({ r, canManage, openCtx, requestConfirm }: { r: SigRequest; canManage: boolean; openCtx: (items: LeaveCtxItem[]) => (e: React.MouseEvent) => void; requestConfirm: (req: ConfirmRequest) => void }) {
    const TypeIcon = r.category === 'policy' ? Scroll : r.category === 'offer' ? Mail : FileText;
    const firstPending = r.signers.find((s) => s.status === 'pending');

    const nudge = () => firstPending && router.post(`/hr/signatures/${firstPending.id}/nudge`, {}, { preserveScroll: true });
    const resendDeclined = () => {
        const dec = r.signers.find((s) => s.status === 'declined');
        if (dec) router.post(`/hr/signatures/${dec.id}/resend`, {}, { preserveScroll: true });
    };

    const menu: LeaveCtxItem[] = [
        { kind: 'item', label: 'Open document', icon: FolderOpen, onSelect: () => { window.location.href = `/hr/documents/${r.document_id}/download`; } },
    ];
    if (canManage) {
        if (r.status === 'awaiting') menu.push({ kind: 'item', label: 'Nudge signer', icon: Bell, onSelect: nudge });
        if (r.status === 'declined') menu.push({ kind: 'item', label: 'Resend request', icon: RotateCcw, onSelect: resendDeclined });
    }
    if (r.has_signed_pdf) menu.push({ kind: 'item', label: 'Download signed PDF', icon: Download, onSelect: () => { window.location.href = `/hr/documents/${r.document_id}/signed`; } });
    if (canManage && r.status === 'awaiting') {
        menu.push({ kind: 'divider' });
        menu.push({ kind: 'item', label: 'Cancel request', icon: X, tone: 'critical', onSelect: () => requestConfirm({ title: 'Cancel signature request?', body: `Withdraw the outstanding signature request for "${r.title}"? Signers will no longer be able to sign.`, confirmLabel: 'Cancel request', onConfirm: () => router.post(`/hr/signatures/document/${r.document_id}/cancel`, {}, { preserveScroll: true }) }) });
    }

    return (
        <div className="rounded-[14px] border border-border bg-card px-[17px] py-[15px]">
            <div className="flex items-start gap-3">
                <span className="grid h-10 w-10 flex-none place-items-center rounded-xl bg-accent text-primary">
                    <TypeIcon className="h-[19px] w-[19px]" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[14.5px] font-bold">{r.title}</span>
                        {r.status === 'signed' ? (
                            <Badge tone="success" icon={Check}>Signed{r.signed_at ? ` ${r.signed_at}` : ''}</Badge>
                        ) : r.status === 'declined' ? (
                            <Badge tone="critical" icon={AlertTriangle}>Declined</Badge>
                        ) : r.overdue ? (
                            <Badge tone="critical" icon={Clock}>Overdue{r.due ? ` · due ${r.due}` : ''}</Badge>
                        ) : (
                            <Badge tone="warning" icon={Clock}>{r.due ? `Due ${r.due}` : 'Awaiting'}</Badge>
                        )}
                        <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-muted-foreground">
                            <RotateCcw className="h-3 w-3" /> {r.order}
                        </span>
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-3.5">
                        <div className="flex items-center">
                            {r.signers.map((s, i) => (
                                <span key={s.id} className="rounded-full ring-2 ring-card" style={{ marginLeft: i ? -7 : 0 }}>
                                    <Avatar initials={s.initials} size={28} />
                                </span>
                            ))}
                        </div>
                        <span className="text-[12.5px] text-muted-foreground">{r.progress}</span>
                        <span className="text-[12px] text-muted-foreground">Sent {r.sent} by {r.requested_by}</span>
                    </div>
                    {r.decline_reason ? (
                        <div className="mt-2.5 flex items-start gap-2 rounded-[10px] bg-status-critical-bg px-3 py-2.5 text-[12.5px] text-status-critical">
                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 flex-none" />
                            <span>Declined — “{r.decline_reason}”</span>
                        </div>
                    ) : null}
                </div>
                <div className="flex flex-none gap-1.5">
                    {r.status === 'signed' && r.has_signed_pdf ? (
                        <GhostBtn icon={Download} label="Signed PDF" onClick={() => { window.location.href = `/hr/documents/${r.document_id}/signed`; }} />
                    ) : r.status === 'declined' && canManage ? (
                        <GhostBtn icon={RotateCcw} label="Resend" onClick={resendDeclined} />
                    ) : r.status === 'awaiting' && canManage ? (
                        <GhostBtn icon={Bell} label="Nudge" onClick={nudge} />
                    ) : null}
                    <button
                        type="button"
                        onClick={openCtx(menu)}
                        aria-label="Request actions"
                        className="grid h-[34px] w-[34px] place-items-center rounded-[9px] border border-border bg-card text-muted-foreground hover:bg-muted"
                    >
                        <MoreHorizontal className="h-4 w-4" />
                    </button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Templates tab                                                     */
/* ------------------------------------------------------------------ */

function TemplatesTab({
    templates,
    canManage,
    onNew,
    onGenerate,
    openCtx,
}: {
    templates: Template[];
    canManage: boolean;
    onNew: () => void;
    onGenerate: (id: number) => void;
    openCtx: (items: LeaveCtxItem[]) => (e: React.MouseEvent) => void;
}) {
    return (
        <div className="flex flex-col gap-2.5 motion-safe:animate-in motion-safe:fade-in-0">
            {canManage ? (
                <div className="flex justify-end">
                    <button type="button" onClick={onNew} className="inline-flex h-[38px] items-center gap-1.5 rounded-[10px] bg-primary px-4 text-[13px] font-semibold text-primary-foreground">
                        <Plus className="h-3.5 w-3.5" /> New template
                    </button>
                </div>
            ) : null}
            {templates.length === 0 ? (
                <div className="rounded-[14px] border border-border bg-card px-5 py-12 text-center text-[13px] text-muted-foreground">No templates yet.</div>
            ) : (
                templates.map((t) => {
                    const menu: LeaveCtxItem[] = [
                        { kind: 'item', label: 'Edit', icon: Pencil, onSelect: () => router.visit(`/hr/documents/templates/${t.id}/edit`) },
                        { kind: 'item', label: 'Generate from this', icon: FileText, onSelect: () => onGenerate(t.id) },
                        { kind: 'item', label: t.is_active ? 'Set inactive' : 'Set active', icon: Check, onSelect: () => router.post(`/hr/documents/templates/${t.id}/toggle-active`, {}, { preserveScroll: true }) },
                    ];
                    return (
                        <div key={t.id} onContextMenu={openCtx(menu)} className="rounded-[14px] border border-border bg-card px-[17px] py-[15px]">
                            <div className="flex items-start gap-3">
                                <span className="grid h-10 w-10 flex-none place-items-center rounded-xl bg-accent text-primary">
                                    <FileText className="h-[19px] w-[19px]" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-[14.5px] font-bold">{t.name}</span>
                                        <TypeBadge category={t.category} />
                                        {t.is_active ? <Badge tone="success" sm>Active</Badge> : <Badge tone="neutral" sm>Inactive</Badge>}
                                        {t.approval_required ? <Badge tone="warning" icon={Shield} sm>Approval required</Badge> : null}
                                    </div>
                                    <div className="my-2 flex items-center gap-2.5 text-[12px] text-muted-foreground">
                                        <span>Version {t.version}</span>
                                        {t.updated_at ? <><span>·</span><span>Updated {t.updated_at}</span></> : null}
                                    </div>
                                    {t.merge_fields.length > 0 ? (
                                        <div className="flex flex-wrap gap-1.5">
                                            {t.merge_fields.map((f) => (
                                                <span key={f} className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">{`{{ ${f} }}`}</span>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                                {canManage ? (
                                    <div className="flex flex-none gap-1.5">
                                        <button type="button" onClick={() => onGenerate(t.id)} className="inline-flex h-[34px] items-center gap-1.5 rounded-[9px] bg-primary px-3 text-[12.5px] font-semibold text-primary-foreground">
                                            <FileText className="h-3.5 w-3.5" /> Generate
                                        </button>
                                        <GhostBtn icon={Pencil} label="Edit" onClick={() => router.visit(`/hr/documents/templates/${t.id}/edit`)} />
                                        <button type="button" onClick={openCtx(menu)} aria-label="Template actions" className="grid h-[34px] w-[34px] place-items-center rounded-[9px] border border-border bg-card text-muted-foreground hover:bg-muted">
                                            <MoreHorizontal className="h-4 w-4" />
                                        </button>
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    );
                })
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Policies tab                                                      */
/* ------------------------------------------------------------------ */

function PoliciesTab({ policies, canView, canManage }: { policies: Policy[]; canView: boolean; canManage: boolean }) {
    return (
        <div className="flex flex-col gap-2.5 motion-safe:animate-in motion-safe:fade-in-0">
            <div className="flex justify-end gap-2">
                {canView ? (
                    <a href="/hr/documents/policies" className="inline-flex h-[38px] items-center gap-1.5 rounded-[10px] border border-border bg-card px-4 text-[13px] font-semibold text-foreground hover:bg-muted">
                        <FolderOpen className="h-3.5 w-3.5" /> Manage policies
                    </a>
                ) : null}
                {canManage ? (
                    <a href="/hr/documents/policies/create" className="inline-flex h-[38px] items-center gap-1.5 rounded-[10px] bg-primary px-4 text-[13px] font-semibold text-primary-foreground">
                        <Plus className="h-3.5 w-3.5" /> New policy
                    </a>
                ) : null}
            </div>
            {policies.length === 0 ? (
                <div className="rounded-[14px] border border-border bg-card px-5 py-12 text-center text-[13px] text-muted-foreground">No policies yet.</div>
            ) : (
                policies.map((p) => {
                    const pct = p.total > 0 ? Math.round((p.attested / p.total) * 100) : 100;
                    const tone = p.overdue === 0 ? 'bg-status-success' : p.overdue > 6 ? 'bg-status-critical' : 'bg-status-warning';
                    return (
                        <div key={p.id} className="flex items-center gap-4 rounded-[14px] border border-border bg-card px-[17px] py-[15px]">
                            <span className="grid h-10 w-10 flex-none place-items-center rounded-xl bg-status-warning-bg text-status-warning">
                                <Scroll className="h-[19px] w-[19px]" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-[14.5px] font-bold">{p.name}</span>
                                    <Badge tone="neutral" sm>v{p.version}</Badge>
                                    {!p.requires_attestation ? (
                                        <Badge tone="neutral" sm>No attestation</Badge>
                                    ) : p.overdue === 0 ? (
                                        <Badge tone="success" icon={Check} sm>All attested</Badge>
                                    ) : (
                                        <Badge tone={p.overdue > 6 ? 'critical' : 'warning'} icon={AlertTriangle} sm>{p.overdue} overdue</Badge>
                                    )}
                                </div>
                                <div className="my-1.5 text-[12px] text-muted-foreground">Owned by {p.owner}</div>
                                {p.requires_attestation ? (
                                    <div className="flex items-center gap-2.5">
                                        <div className="h-[7px] max-w-[320px] flex-1 overflow-hidden rounded-full bg-muted">
                                            <div className={cn('h-full rounded-full', tone)} style={{ width: `${pct}%` }} />
                                        </div>
                                        <span className="text-[12px] font-semibold text-muted-foreground">{p.attested} / {p.total} attested</span>
                                    </div>
                                ) : null}
                            </div>
                            <a href="/hr/documents/policies" className="inline-flex h-[34px] flex-none items-center gap-1.5 rounded-[9px] border border-border bg-card px-3 text-[12.5px] font-semibold text-foreground hover:bg-muted">
                                <Eye className="h-3.5 w-3.5" /> View
                            </a>
                        </div>
                    );
                })
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Inline viewer (slide-over)                                        */
/* ------------------------------------------------------------------ */

const AUDIT_ICON: Record<string, LucideIcon> = {
    upload: Upload,
    pencil: Pencil,
    trash: Trash2,
    send: Send,
    check: Check,
    alert: AlertTriangle,
};

type AuditEntry = { label: string; who: string; at: string | null; icon: string };

function DocViewer({ doc, onClose, onSend }: { doc: DocRow | null; onClose: () => void; onSend: (d: DocRow) => void }) {
    const [audit, setAudit] = useState<AuditEntry[] | null>(null);

    useEffect(() => {
        if (!doc) {
            setAudit(null);
            return;
        }
        let cancelled = false;
        setAudit(null);
        fetch(`/hr/documents/${doc.id}/audit`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data: { entries: AuditEntry[] }) => { if (!cancelled) setAudit(data.entries); })
            .catch(() => { if (!cancelled) setAudit([]); });
        return () => { cancelled = true; };
    }, [doc]);

    if (!doc) return null;
    const Icon = DOC_CATEGORY_ICON[doc.category] ?? FileText;

    // Real audit log (fetched) with a derived fallback while loading / on error.
    const derived: { label: string; who: string; at: string; icon: LucideIcon }[] = [
        { label: 'Uploaded', who: doc.created_by, at: doc.created_at ?? '', icon: Upload },
    ];
    if (doc.signature) derived.push({ label: 'Sent for signature', who: doc.created_by, at: '', icon: Send });
    if (doc.signature === 'signed') derived.push({ label: 'Signed', who: doc.employee?.name ?? 'Employee', at: '', icon: Check });
    if (doc.signature === 'declined') derived.push({ label: 'Declined', who: doc.employee?.name ?? 'Employee', at: '', icon: AlertTriangle });

    const timeline: { label: string; who: string; at: string; icon: LucideIcon }[] =
        audit && audit.length > 0
            ? audit.map((e) => ({ label: e.label, who: e.who, at: e.at ?? '', icon: AUDIT_ICON[e.icon] ?? FileText }))
            : derived;

    return (
        <Sheet open onOpenChange={(o) => !o && onClose()}>
            <SheetContent side="right" className="flex w-[480px] max-w-[94vw] flex-col p-0">
                <SheetTitle className="sr-only">{doc.title}</SheetTitle>
                <SheetDescription className="sr-only">Document preview and audit trail</SheetDescription>
                <div className="flex items-center justify-between border-b border-border p-[16px_18px]">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid h-9 w-9 flex-none place-items-center rounded-[10px] bg-accent text-primary">
                            <Icon className="h-[18px] w-[18px]" />
                        </span>
                        <div className="min-w-0">
                            <div className="truncate text-[14px] font-bold">{doc.title}</div>
                            <div className="text-[11.5px] text-muted-foreground">{doc.folder} · v{doc.version}</div>
                        </div>
                    </div>
                </div>
                <div className="flex-1 overflow-y-auto p-[18px]">
                    <div className="mb-3.5 flex flex-wrap gap-1.5">
                        <SigBadge status={doc.signature} />
                        {doc.expiry ? (
                            <Badge tone={doc.expiry.status === 'expired' ? 'critical' : doc.expiry.status === 'expiring' ? 'warning' : 'success'} sm>{doc.expiry.label}</Badge>
                        ) : null}
                        {doc.is_restricted ? <Badge tone="neutral" icon={Lock} sm>Restricted</Badge> : null}
                    </div>
                    {/* preview placeholder */}
                    <div className="overflow-hidden rounded-lg border border-border bg-white p-[34px_30px] shadow-sm" style={{ aspectRatio: '8.5 / 11' }}>
                        <div className="mb-5 h-[11px] w-[46%] rounded bg-primary/85" />
                        {[78, 92, 64, 88, 0, 84, 70].map((w, i) => (
                            <div key={i} className="mb-[11px] h-[7px] rounded" style={{ width: `${w}%`, background: w ? 'var(--muted)' : 'transparent' }} />
                        ))}
                        {doc.signature === 'signed' ? (
                            <div className="mt-3.5 text-[26px] text-primary" style={{ fontFamily: 'cursive', transform: 'rotate(-3deg)' }}>{doc.employee?.name ?? 'Signed'}</div>
                        ) : null}
                    </div>
                    <div className="mt-5">
                        <div className="mb-3 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Audit trail</div>
                        <div className="flex flex-col">
                            {timeline.map((t, i) => {
                                const TIcon = t.icon;
                                return (
                                    <div key={i} className="flex gap-3">
                                        <div className="flex flex-col items-center">
                                            <span className="grid h-7 w-7 flex-none place-items-center rounded-full bg-accent text-primary">
                                                <TIcon className="h-3.5 w-3.5" />
                                            </span>
                                            {i < timeline.length - 1 ? <span className="min-h-[18px] w-0.5 flex-1 bg-border" /> : null}
                                        </div>
                                        <div className="pb-4">
                                            <div className="text-[13px] font-semibold">{t.label}</div>
                                            <div className="text-[11.5px] text-muted-foreground">{t.who}{t.at ? ` · ${t.at}` : ''}</div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
                <div className="flex gap-2 border-t border-border p-[14px_18px]">
                    <a href={`/hr/documents/${doc.id}/download`} className="inline-flex h-10 flex-1 items-center justify-center gap-1.5 rounded-[10px] bg-primary text-[13px] font-semibold text-primary-foreground">
                        <Download className="h-3.5 w-3.5" /> Download
                    </a>
                    <button type="button" onClick={() => onSend(doc)} className="inline-flex h-10 flex-1 items-center justify-center gap-1.5 rounded-[10px] border border-border bg-card text-[13px] font-semibold text-foreground hover:bg-muted">
                        <PenLine className="h-3.5 w-3.5" /> Send for signature
                    </button>
                </div>
            </SheetContent>
        </Sheet>
    );
}

/* ------------------------------------------------------------------ */
/*  Tiny pieces                                                       */
/* ------------------------------------------------------------------ */

function Tile({ active, onClick, leading, title, sub }: { active: boolean; onClick: () => void; leading: React.ReactNode; title: string; sub: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex items-center gap-3 rounded-[14px] border bg-card p-[13px_14px] text-left transition-all hover:-translate-y-px hover:border-primary',
                active ? 'border-primary bg-primary/[0.06] shadow-md' : 'border-border',
            )}
        >
            {leading}
            <div className="min-w-0">
                <div className="truncate text-[13.5px] font-bold">{title}</div>
                <div className="text-[11.5px] text-muted-foreground">{sub}</div>
            </div>
        </button>
    );
}

function Crumb({ label, onClick, last }: { label: string; onClick?: () => void; last?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={last}
            className={cn('text-[13px] font-bold', last ? 'cursor-default text-foreground' : 'text-muted-foreground hover:text-foreground')}
        >
            {label}
        </button>
    );
}

function CheckBox({ checked, onClick }: { checked: boolean; onClick?: (e?: React.MouseEvent) => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={checked}
            aria-label="Select"
            className={cn('grid h-[18px] w-[18px] place-items-center rounded-[5px] border-[1.5px]', checked ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card')}
        >
            {checked ? <Check className="h-3 w-3" /> : null}
        </button>
    );
}

function BulkBtn({ icon: Icon, label, onClick, tone }: { icon: LucideIcon; label: string; onClick: () => void; tone?: 'critical' }) {
    return (
        <button type="button" onClick={onClick} className={cn('inline-flex items-center gap-1.5 text-[12.5px] font-semibold', tone === 'critical' ? 'text-status-critical' : 'text-foreground')}>
            <Icon className="h-3.5 w-3.5" /> {label}
        </button>
    );
}

function GhostBtn({ icon: Icon, label, onClick }: { icon: LucideIcon; label: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className="inline-flex h-[34px] items-center gap-1.5 rounded-[9px] border border-border bg-card px-3 text-[12.5px] font-semibold text-foreground hover:bg-muted">
            <Icon className="h-3.5 w-3.5" /> {label}
        </button>
    );
}
