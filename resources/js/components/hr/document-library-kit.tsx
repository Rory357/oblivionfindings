/* Shared document-library primitives consumed by all three HR document
 * surfaces — the Documents hub (`pages/hr/documents/index.tsx`), a single
 * employee's file (`pages/hr/employees/documents.tsx`) and self-service
 * (`pages/hr/my/documents.tsx`). Centralises the folder taxonomy, file-type
 * and category iconography, date/size formatting and expiry classification so
 * the three pages stay visually consistent instead of each re-deriving them.
 * Presentational only (no layout) — every page keeps its own chrome. */
import {
    Award,
    File,
    FileImage,
    FileSpreadsheet,
    FileText,
    Folder,
    Mail,
    ScrollText,
    ShieldCheck,
    Wallet,
    type LucideIcon,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Folder taxonomy (single source of truth)                          */
/* ------------------------------------------------------------------ */

type FolderMeta = { icon: LucideIcon; tone: string };

/** Canonical folder metadata keyed by lower-cased folder name. */
const FOLDER_META: Record<string, FolderMeta> = {
    contracts: { icon: FileText, tone: 'bg-accent text-primary' },
    contract: { icon: FileText, tone: 'bg-accent text-primary' },
    compliance: { icon: ShieldCheck, tone: 'bg-status-info-bg text-status-info' },
    certificates: { icon: Award, tone: 'bg-status-warning-bg text-status-warning' },
    certificate: { icon: Award, tone: 'bg-status-warning-bg text-status-warning' },
    letters: { icon: Mail, tone: 'bg-status-success-bg text-status-success' },
    payslips: { icon: Wallet, tone: 'bg-status-success-bg text-status-success' },
    policies: { icon: ScrollText, tone: 'bg-status-warning-bg text-status-warning' },
    policy: { icon: ScrollText, tone: 'bg-status-warning-bg text-status-warning' },
    recruitment: { icon: FileText, tone: 'bg-status-info-bg text-status-info' },
};

export function folderMeta(name: string): FolderMeta {
    return (
        FOLDER_META[name.toLowerCase()] ?? {
            icon: Folder,
            tone: 'bg-muted text-muted-foreground',
        }
    );
}

/** Canonical folder for a category — mirrors the backend
 *  HrDocumentController::folderForCategory(). */
export function folderForCategory(category: string | null): string {
    switch (category) {
        case 'contract':
            return 'Contracts';
        case 'certificate':
            return 'Certificates';
        case 'letter':
        case 'offer':
            return 'Letters';
        case 'policy':
            return 'Policies';
        case 'payslip':
            return 'Payslips';
        default:
            return 'Compliance';
    }
}

/* ------------------------------------------------------------------ */
/*  File-type & category iconography                                  */
/* ------------------------------------------------------------------ */

const FILE_ICONS: Record<string, { icon: LucideIcon; color: string; bg: string }> = {
    pdf: { icon: FileText, color: 'text-status-critical', bg: 'bg-status-critical-bg' },
    doc: { icon: FileText, color: 'text-status-info', bg: 'bg-status-info-bg' },
    docx: { icon: FileText, color: 'text-status-info', bg: 'bg-status-info-bg' },
    xls: { icon: FileSpreadsheet, color: 'text-status-success', bg: 'bg-status-success-bg' },
    xlsx: { icon: FileSpreadsheet, color: 'text-status-success', bg: 'bg-status-success-bg' },
    csv: { icon: FileSpreadsheet, color: 'text-status-success', bg: 'bg-status-success-bg' },
    jpg: { icon: FileImage, color: 'text-status-warning', bg: 'bg-status-warning-bg' },
    jpeg: { icon: FileImage, color: 'text-status-warning', bg: 'bg-status-warning-bg' },
    png: { icon: FileImage, color: 'text-status-warning', bg: 'bg-status-warning-bg' },
    gif: { icon: FileImage, color: 'text-status-warning', bg: 'bg-status-warning-bg' },
};

export function fileTypeInfo(
    mime?: string | null,
    name?: string | null,
): { icon: LucideIcon; color: string; bg: string } {
    const ext = (name ?? '').split('.').pop()?.toLowerCase() ?? '';
    return FILE_ICONS[ext] ?? { icon: File, color: 'text-primary', bg: 'bg-primary/10' };
}

/** Icon map for a document by its business category (used by the hub table).
 *  Exported as a map (not a function) so consumers use an object lookup —
 *  calling a component-returning helper in render trips react-hooks lint. */
export const DOC_CATEGORY_ICON: Record<string, LucideIcon> = {
    contract: FileText,
    certificate: Award,
    letter: Mail,
    offer: Mail,
    policy: ScrollText,
    payslip: Wallet,
    other: FileText,
};

/** Category → badge tone classes (design-canonical palette). */
const CATEGORY_TONE: Record<string, string> = {
    contract: 'bg-status-info-bg text-status-info',
    policy: 'bg-status-warning-bg text-status-warning',
    certificate: 'bg-status-success-bg text-status-success',
    letter: 'bg-muted text-muted-foreground',
    offer: 'bg-status-info-bg text-status-info',
    payslip: 'bg-muted text-muted-foreground',
    other: 'bg-muted text-muted-foreground',
};

export function categoryTone(category: string | null): string {
    return CATEGORY_TONE[category ?? 'other'] ?? 'bg-muted text-muted-foreground';
}

/* ------------------------------------------------------------------ */
/*  Formatting                                                        */
/* ------------------------------------------------------------------ */

/** Title-case a snake/space label ("police_vetting" → "Police Vetting"). */
export function labelize(value: string | null | undefined): string {
    if (!value) return '';
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** NZ short date ("5 Jun 2026") with an em-dash fallback. */
export function formatDocDate(iso?: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          });
}

export function formatBytes(bytes?: number | null): string {
    const b = bytes ?? 0;
    if (b < 1024) return `${b} B`;
    if (b < 1_048_576) return `${(b / 1024).toFixed(0)} KB`;
    return `${(b / 1_048_576).toFixed(1)} MB`;
}

/* ------------------------------------------------------------------ */
/*  Expiry classification                                             */
/* ------------------------------------------------------------------ */

export type ExpiryState = 'valid' | 'expiring' | 'expired';
export type ExpiryVariant = 'success' | 'warning' | 'critical';
export type ExpiryInfo = { state: ExpiryState; variant: ExpiryVariant; label: string };

/**
 * Classify an expiry date. `warnDays` controls the "expiring soon" window
 * (design default 60). Labels match the self-service surface: expired →
 * "Expired <date>", expiring → "Expires <date>", valid → "Valid to <year>".
 */
export function expiryStatus(iso: string | null | undefined, warnDays = 60): ExpiryInfo | null {
    if (!iso) return null;
    const target = new Date(iso);
    if (isNaN(target.getTime())) return null;
    const days = Math.round((target.getTime() - Date.now()) / 86_400_000);
    if (days < 0) return { state: 'expired', variant: 'critical', label: `Expired ${formatDocDate(iso)}` };
    if (days <= warnDays) return { state: 'expiring', variant: 'warning', label: `Expires ${formatDocDate(iso)}` };
    return { state: 'valid', variant: 'success', label: `Valid to ${target.getFullYear()}` };
}
