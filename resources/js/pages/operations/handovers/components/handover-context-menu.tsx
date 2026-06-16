/* Shared right-click context menu for handover cards + the rail's awaiting list.
 * Used by BOTH the Operations and eMAR handover surfaces (the cards/rail are
 * shared components), so the jumps go to global routes and the in-page actions
 * reuse the handlers the cards/detail already receive. Mirrors the rostering
 * ShiftContextMenu idiom copied from the PRN register's openRowCtx
 * (PrnRecords.tsx). Colours are semantic design tokens. */
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { router } from '@inertiajs/react';
import {
    Check,
    Clock,
    Eye,
    FilePenLine,
    Flag,
    Pill,
    Send,
    User,
    Users,
} from 'lucide-react';
import { type MouseEvent as ReactMouseEvent, useState } from 'react';

import { type Handover, clientName, fmtShiftRange, statusLabel } from './shared';

export type HandoverCtxHandlers = {
    onOpen: (h: Handover) => void;
    onSubmit?: (h: Handover) => void;
    onAcknowledge?: (h: Handover) => void;
    onEdit?: (h: Handover) => void;
};

// Status → context-menu header-tag colours, as semantic-token CSS variables so
// the tag matches the StatusPill without re-deriving Tailwind classes.
const STATUS_TAG: Record<string, { bg: string; color: string }> = {
    draft: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
    submitted: { bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' },
    acknowledged: {
        bg: 'var(--status-success-bg)',
        color: 'var(--status-success)',
    },
};

function buildItems(h: Handover, handlers: HandoverCtxHandlers): ShiftCtxItem[] {
    const client = h.client;
    const outShift = h.outgoing_shift;
    const outStaff = h.outgoing_staff;
    const incStaff = h.incoming_staff;

    const items: ShiftCtxItem[] = [
        {
            icon: <Eye className="h-3.5 w-3.5" />,
            label: 'View handover',
            sub: clientName(client),
            tone: 'primary',
            onClick: () => handlers.onOpen(h),
        },
    ];

    if (h.status === 'submitted' && h.can_acknowledge && handlers.onAcknowledge) {
        items.push({
            icon: <Check className="h-3.5 w-3.5" />,
            label: 'Acknowledge',
            sub: 'Read-back sign-off',
            onClick: () => handlers.onAcknowledge!(h),
        });
    }
    if (h.status === 'draft' && h.can_submit && handlers.onSubmit) {
        items.push({
            icon: <Send className="h-3.5 w-3.5" />,
            label: 'Submit to incoming',
            onClick: () => handlers.onSubmit!(h),
        });
    }
    if (h.can_edit && handlers.onEdit) {
        items.push({
            icon: <FilePenLine className="h-3.5 w-3.5" />,
            label: 'Edit handover',
            onClick: () => handlers.onEdit!(h),
        });
    }

    items.push({ sep: true });

    if (client) {
        items.push({
            icon: <User className="h-3.5 w-3.5" />,
            label: 'View client',
            sub: 'Care profile',
            onClick: () => router.visit(`/operations/clients/${client.id}`),
        });
    }
    if (outShift) {
        items.push({
            icon: <Clock className="h-3.5 w-3.5" />,
            label: 'View shift',
            sub: `${outShift.label} · ${fmtShiftRange(outShift)}`,
            onClick: () => router.visit(`/operations/shifts/${outShift.id}`),
        });
    }
    if (outStaff) {
        items.push({
            icon: <User className="h-3.5 w-3.5" />,
            label: `View ${outStaff.name.split(' ')[0]} · outgoing`,
            onClick: () => router.visit(`/staff/${outStaff.id}`),
        });
    }
    if (incStaff) {
        items.push({
            icon: <Users className="h-3.5 w-3.5" />,
            label: `View ${incStaff.name.split(' ')[0]} · incoming`,
            onClick: () => router.visit(`/staff/${incStaff.id}`),
        });
    }
    if (client) {
        items.push({
            icon: <Pill className="h-3.5 w-3.5" />,
            label: 'Open on MAR chart',
            onClick: () => router.visit(`/emar/mar?client_id=${client.id}`),
        });
        items.push({ sep: true });
        items.push({
            icon: <Flag className="h-3.5 w-3.5" />,
            label: 'Raise concern',
            sub: 'Log an incident',
            tone: 'critical',
            onClick: () => router.visit(`/clients/${client.id}/incidents`),
        });
    }

    return items;
}

/** Wire a right-click context menu for handover cards/rows. Returns the
 *  `openCtx(event, handover)` handler plus the menu element to render once. */
export function useHandoverContextMenu(handlers: HandoverCtxHandlers) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    const openCtx = (e: ReactMouseEvent, h: Handover) => {
        e.preventDefault();
        const tag = STATUS_TAG[h.status] ?? STATUS_TAG.draft;
        const meta = [
            clientName(h.client),
            h.outgoing_shift?.label,
            h.outgoing_staff?.name ?? 'Open shift',
        ]
            .filter(Boolean)
            .join(' · ');
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: statusLabel(h.status),
            tagBg: tag.bg,
            tagColor: tag.color,
            meta,
            items: buildItems(h, handlers),
        });
    };

    const menu = ctx ? (
        <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
    ) : null;

    return { openCtx, menu };
}
