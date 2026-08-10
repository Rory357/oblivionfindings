/* Embeddable Risk Assessments register — reused on the Client profile (risk_management
 * tab) and the Site profile (Risk Assessments tab). Self-contained: manages the context
 * menu, the seven workflow modals and the detail dialog (fetched on demand from the JSON
 * `show` endpoint), so a host page only passes the scoped rows + pickers + can + the
 * locked assessable. Create pre-attaches that entity. */
import { ShiftContextMenu, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { RaDetailDialog } from './ra-detail-dialog';
import { statusMeta } from './ra-kit';
import { buildRaCtxItems, RaTable, type RaCtxHandlers } from './ra-table';
import { RaWizardDialog } from './ra-wizard-dialog';
import type {
    LockedAssessable,
    RaDetail,
    RaModalKind,
    RaPickers,
    RaRow,
} from './types';

export function RaRegisterSection({
    assessments,
    pickers,
    canManage,
    lockedAssessable,
    title = 'Risk assessment register',
}: {
    assessments: RaRow[];
    pickers: RaPickers;
    canManage: boolean;
    lockedAssessable: LockedAssessable;
    title?: string;
}) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [modal, setModal] = useState<{
        kind: RaModalKind;
        detail: RaDetail | null;
    } | null>(null);
    const [detail, setDetail] = useState<RaDetail | null>(null);

    const fetchDetail = async (id: number): Promise<RaDetail | null> => {
        try {
            const res = await fetch(`/health-safety/risk-assessments/${id}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) return null;
            const json = (await res.json()) as { detail: RaDetail };
            return json.detail;
        } catch {
            return null;
        }
    };

    const openDetail = async (id: number) => {
        setCtx(null);
        const d = await fetchDetail(id);
        if (d) setDetail(d);
    };

    const openAction = async (kind: RaModalKind, id: number) => {
        setCtx(null);
        const d = await fetchDetail(id);
        if (d) setModal({ kind, detail: d });
    };

    const copyLink = (r: RaRow) => {
        const url = `${window.location.origin}/health-safety/risk-assessments?assessment=${r.id}`;
        void navigator.clipboard?.writeText(url);
        setCtx(null);
    };

    const handlers: RaCtxHandlers = {
        onView: (r) => openDetail(r.id),
        onEdit: (r) => openAction('edit', r.id),
        onApprove: (r) => openAction('approve', r.id),
        onReview: (r) => openAction('review', r.id),
        onResidual: (r) => openAction('residual', r.id),
        onSupersede: (r) => openAction('supersede', r.id),
        onArchive: (r) => openAction('archive', r.id),
        onCopyLink: copyLink,
        onOpenCurrent: (id) => openDetail(id),
    };

    const onCtx = (e: React.MouseEvent, row: RaRow) => {
        e.preventDefault();
        const meta = statusMeta(row.status);
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: meta.label.toUpperCase(),
            meta: `${row.reference_number} · ${row.title}`,
            items: buildRaCtxItems(row, canManage, handlers),
        });
    };

    const newBtn = (
        <Button
            type="button"
            size="sm"
            onClick={() => setModal({ kind: 'new', detail: null })}
        >
            <Plus className="h-4 w-4" /> New assessment
        </Button>
    );

    return (
        <div className="flex flex-col gap-3">
            {canManage ? (
                <div className="flex justify-end">{newBtn}</div>
            ) : null}

            <RaTable
                rows={assessments}
                title={title}
                countLabel={`${assessments.length} assessment${assessments.length === 1 ? '' : 's'}`}
                onOpen={openDetail}
                onCtx={onCtx}
                emptyCta={canManage ? newBtn : undefined}
            />

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            {modal ? (
                <RaWizardDialog
                    kind={modal.kind}
                    detail={modal.detail}
                    pickers={pickers}
                    lockedAssessable={lockedAssessable}
                    onClose={() => setModal(null)}
                    onSuccess={(id) => {
                        setModal(null);
                        if (id) void openDetail(id);
                    }}
                />
            ) : null}

            {detail ? (
                <RaDetailDialog
                    detail={detail}
                    open
                    onClose={() => setDetail(null)}
                    onAction={(kind) => {
                        setModal({ kind, detail });
                        setDetail(null);
                    }}
                    onOpenAssessment={(id) => {
                        setDetail(null);
                        void openDetail(id);
                    }}
                />
            ) : null}
        </div>
    );
}
