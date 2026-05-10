import {
    File as FileIcon,
    FileImage,
    FileSpreadsheet,
    FileText,
} from 'lucide-react';
import type { ComponentType } from 'react';

export type SiteDocumentRecord = {
    id: number;
    title?: string | null;
    category?: string | null;
    folder?: string | null;
    version?: string | null;
    effective_date?: string | null;
    expiry_date?: string | null;
    notes?: string | null;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    created_at?: string | null;
    uploaded_by?: { id: number; name: string; email: string } | null;
};

export const SITE_DOCUMENT_CATEGORIES = [
    {
        value: 'evacuation_plan',
        label: 'Evacuation Plan',
        color: 'bg-primary/10 text-primary',
    },
    {
        value: 'compliance_cert',
        label: 'Compliance Certificate',
        color: 'bg-status-info-bg text-status-info',
    },
    {
        value: 'insurance',
        label: 'Insurance',
        color: 'bg-status-warning-bg text-status-warning',
    },
    {
        value: 'lease',
        label: 'Lease / Tenancy',
        color: 'bg-muted text-foreground',
    },
    {
        value: 'safety',
        label: 'Health & Safety',
        color: 'bg-status-critical-bg text-status-critical',
    },
    {
        value: 'maintenance',
        label: 'Maintenance',
        color: 'bg-status-success-bg text-status-success',
    },
    {
        value: 'policy',
        label: 'Policy',
        color: 'bg-primary/10 text-primary',
    },
    { value: 'other', label: 'Other', color: 'bg-muted text-foreground' },
];

const FILE_ICONS: Record<
    string,
    { icon: ComponentType<{ className?: string }>; color: string; bg: string }
> = {
    pdf: {
        icon: FileText,
        color: 'text-status-critical',
        bg: 'bg-status-critical-bg',
    },
    doc: { icon: FileText, color: 'text-status-info', bg: 'bg-status-info-bg' },
    docx: {
        icon: FileText,
        color: 'text-status-info',
        bg: 'bg-status-info-bg',
    },
    xls: {
        icon: FileSpreadsheet,
        color: 'text-status-success',
        bg: 'bg-status-success-bg',
    },
    xlsx: {
        icon: FileSpreadsheet,
        color: 'text-status-success',
        bg: 'bg-status-success-bg',
    },
    csv: {
        icon: FileSpreadsheet,
        color: 'text-status-success',
        bg: 'bg-status-success-bg',
    },
    jpg: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
    jpeg: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
    png: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
    gif: {
        icon: FileImage,
        color: 'text-status-warning',
        bg: 'bg-status-warning-bg',
    },
};

export function getSiteDocumentFileInfo(name?: string | null) {
    const ext = (name ?? '').split('.').pop()?.toLowerCase() ?? '';

    return (
        FILE_ICONS[ext] ?? {
            icon: FileIcon,
            color: 'text-primary',
            bg: 'bg-primary/10',
        }
    );
}

export function getSiteDocumentCategory(value?: string | null) {
    return (
        SITE_DOCUMENT_CATEGORIES.find((category) => category.value === value) ??
        null
    );
}

export function formatSiteDocumentFileSize(bytes?: number | null) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';

    return (bytes / 1048576).toFixed(1) + ' MB';
}

export function isSiteDocumentExpired(date?: string | null) {
    if (!date) return false;

    return new Date(date) < new Date();
}

export function isSiteDocumentExpiringSoon(date?: string | null) {
    if (!date) return false;

    const expiry = new Date(date);
    const now = new Date();

    return expiry > now && expiry.getTime() - now.getTime() < 30 * 86400000;
}
