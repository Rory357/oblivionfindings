/* Site and Client registers link to the permanent assessment workspace.
 * Creation remains pre-attached to the host entity; its unsaved work is guarded. */
import { ShiftContextMenu, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import {
    riskHref,
    riskReturn,
    type RiskCommand,
} from '@/lib/health-safety/risk-navigation';
import { router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
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
    const returnTo = riskReturn(usePage().url);
    const drafts = useSafetyWorkspaceDrafts();
    const hrefFor = (id: number) => riskHref(id, { returnTo });

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

    const openDetail = (id: number) => {
        setCtx(null);
        router.visit(hrefFor(id));
    };

    const openAction = async (kind: RaModalKind, id: number) => {
        setCtx(null);
        if (kind !== 'edit' && kind !== 'supersede' && kind !== 'new') {
            router.visit(
                riskHref(id, { returnTo, command: kind as RiskCommand }),
            );
            return;
        }
        const d = await fetchDetail(id);
        if (d) setModal({ kind, detail: d });
    };

    const copyLink = (r: RaRow) => {
        const url = `${window.location.origin}${hrefFor(r.id)}`;
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
        <SafetyFormDraftContext.Provider value={drafts.register}>
            <div className="flex flex-col gap-3">
                {canManage ? (
                    <div className="flex justify-end">{newBtn}</div>
                ) : null}

                <RaTable
                    rows={assessments}
                    title={title}
                    countLabel={`${assessments.length} assessment${assessments.length === 1 ? '' : 's'}`}
                    onOpen={openDetail}
                    hrefFor={hrefFor}
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
                        onClose={() => drafts.request(() => setModal(null))}
                        onSuccess={(id) => {
                            setModal(null);
                            if (id) void openDetail(id);
                        }}
                    />
                ) : null}

                {drafts.confirmation}
            </div>
        </SafetyFormDraftContext.Provider>
    );
}
