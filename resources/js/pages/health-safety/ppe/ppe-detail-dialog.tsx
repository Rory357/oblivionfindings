/**
 * PPE detail-as-modal — the rich item / allocation record. Composes `WizardShell`
 * (Add-Client shell family) with the rail-as-section-nav pattern (like
 * EventDetailDialog). Read + premium evidence upload (Pattern A AttachmentUploader)
 * + lifecycle action launchers; the actual action modals are orchestrated at the
 * page level (onAction), so they stack above this dialog and refresh it on success.
 */
import { Button } from '@/components/ui/button';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    Ban,
    ClipboardCheck,
    FileText,
    History,
    Info,
    Paperclip,
    Reply,
    ShieldCheck,
    User,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    PpeChip,
    catIcon,
    catLabel,
    condLabel,
    condTone,
    fmtDateNZ,
    fmtDateTimeNZ,
    formatBytes,
    inventoryFlags,
    statusLabel,
    statusTone,
    type AllocationDetail,
    type ItemDetail,
    type PpeAttachment,
    type PpeDetail,
} from './ppe-shared';

export type DetailAction =
    | {
          kind: 'allocate';
          itemId: number;
          label: string;
          category: string | null;
      }
    | { kind: 'inspect'; itemId: number; label: string }
    | { kind: 'condemn'; itemId: number; label: string }
    | { kind: 'dispose'; itemId: number; label: string }
    | {
          kind: 'return';
          allocationId: number;
          worker: string;
          itemLabel: string;
      }
    | { kind: 'acknowledge'; allocationId: number };

type SectionKey = 'overview' | 'allocation' | 'inspections' | 'history';

function AttachmentGrid({ attachments }: { attachments: PpeAttachment[] }) {
    if (!attachments.length) return null;
    return (
        <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
            {attachments.map((a) => (
                <a
                    key={a.id}
                    href={a.download_url}
                    className="overflow-hidden rounded-lg border border-border transition-colors hover:border-primary/40"
                >
                    {a.is_image ? (
                        <img
                            src={a.url}
                            alt={a.alt_text ?? a.original_name}
                            className="h-24 w-full object-cover"
                        />
                    ) : (
                        <span className="flex h-24 w-full items-center justify-center bg-muted text-muted-foreground">
                            <Paperclip className="h-5 w-5" />
                        </span>
                    )}
                    <div className="p-2">
                        <div className="truncate text-[12px] font-medium">
                            {a.original_name}
                        </div>
                        <div className="text-[11px] text-muted-foreground">
                            {formatBytes(a.size)}
                        </div>
                    </div>
                </a>
            ))}
        </div>
    );
}

function SectionShell({
    icon: Icon,
    title,
    blurb,
    children,
}: {
    icon: WizardStep['icon'];
    title: string;
    blurb: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-start gap-3">
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px] bg-accent text-primary">
                    <Icon className="h-[18px] w-[18px]" />
                </span>
                <div>
                    <h2 className="text-[17px] font-bold tracking-tight">
                        {title}
                    </h2>
                    <p className="text-[13px] text-muted-foreground">{blurb}</p>
                </div>
            </div>
            {children}
        </div>
    );
}

function EmptyBlock({
    icon: Icon,
    title,
    sub,
}: {
    icon: WizardStep['icon'];
    title: string;
    sub: string;
}) {
    return (
        <div className="flex flex-col items-center gap-1.5 rounded-xl border border-dashed border-border py-10 text-center">
            <Icon className="h-7 w-7 text-muted-foreground" />
            <div className="text-sm font-semibold">{title}</div>
            <div className="text-xs text-muted-foreground">{sub}</div>
        </div>
    );
}

// ───────────────────────── Item detail ─────────────────────────

function ItemDetailView({
    item,
    canManage,
    onClose,
    onAction,
}: {
    item: ItemDetail;
    canManage: boolean;
    onClose: () => void;
    onAction: (a: DetailAction) => void;
}) {
    const [section, setSection] = useState<SectionKey>('overview');
    const CatIcon = catIcon(item.ppe_type?.category);
    const label = `${item.ppe_type?.name ?? 'PPE item'}${item.serial_number ? ` (${item.serial_number})` : ''}`;
    const active = item.active_allocation;
    const flags = inventoryFlags(item);

    const SECTIONS: {
        key: SectionKey;
        label: string;
        blurb: string;
        icon: WizardStep['icon'];
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'Identity & specification',
            icon: Info,
        },
        {
            key: 'allocation',
            label: 'Allocation',
            blurb: active
                ? `Issued to ${active.user?.name ?? 'worker'}`
                : 'Available to issue',
            icon: User,
        },
        {
            key: 'inspections',
            label: 'Inspections',
            blurb: `${item.inspections.length} on record`,
            icon: ClipboardCheck,
        },
        {
            key: 'history',
            label: 'History',
            blurb: 'Full audit trail',
            icon: History,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );
    const isCondemned = item.status === 'condemned';
    const isDisposed = item.status === 'disposed';

    return (
        <WizardShell
            open
            onClose={onClose}
            title={`PPE item ${item.serial_number ?? item.id}`}
            description={`${item.ppe_type?.name ?? 'PPE'} — ${statusLabel(item.status)}`}
            railIcon={CatIcon}
            railTitle={item.serial_number ?? item.ppe_type?.name ?? 'PPE item'}
            railSub={`${item.ppe_type?.name ?? 'PPE'} · ${condLabel(item.condition)}`}
            steps={SECTIONS as readonly WizardStep[]}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            pct={null}
            footerStart={
                <div className="flex flex-wrap items-center gap-1.5">
                    <PpeChip tone={condTone(item.condition)}>
                        {condLabel(item.condition)}
                    </PpeChip>
                    <PpeChip tone={statusTone(item.status)}>
                        {statusLabel(item.status)}
                    </PpeChip>
                </div>
            }
            footerEnd={
                canManage ? (
                    <div className="flex flex-wrap items-center gap-2">
                        {!isCondemned && !isDisposed ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onAction({
                                        kind: 'allocate',
                                        itemId: item.id,
                                        label,
                                        category:
                                            item.ppe_type?.category ?? null,
                                    })
                                }
                            >
                                <User className="mr-1 h-3.5 w-3.5" /> Allocate
                            </Button>
                        ) : null}
                        {!isDisposed ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onAction({
                                        kind: 'inspect',
                                        itemId: item.id,
                                        label,
                                    })
                                }
                            >
                                <ClipboardCheck className="mr-1 h-3.5 w-3.5" />{' '}
                                Inspect
                            </Button>
                        ) : null}
                        {!isCondemned && !isDisposed ? (
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-status-critical/40 text-status-critical hover:text-status-critical"
                                onClick={() =>
                                    onAction({
                                        kind: 'condemn',
                                        itemId: item.id,
                                        label,
                                    })
                                }
                            >
                                <Ban className="mr-1 h-3.5 w-3.5" /> Condemn
                            </Button>
                        ) : null}
                        {isCondemned ? (
                            <Button
                                size="sm"
                                variant="outline"
                                className="border-status-critical/40 text-status-critical hover:text-status-critical"
                                onClick={() =>
                                    onAction({
                                        kind: 'dispose',
                                        itemId: item.id,
                                        label,
                                    })
                                }
                            >
                                <FileText className="mr-1 h-3.5 w-3.5" />{' '}
                                Dispose
                            </Button>
                        ) : null}
                    </div>
                ) : null
            }
        >
            {section === 'overview' ? (
                <SectionShell
                    icon={Info}
                    title="Overview"
                    blurb="Identity & specification"
                >
                    {flags.length ? (
                        <div className="flex flex-wrap gap-1.5">
                            {flags.map((f, i) => (
                                <PpeChip key={i} tone={f.tone} icon={f.icon}>
                                    {f.label}
                                </PpeChip>
                            ))}
                        </div>
                    ) : null}
                    <ReviewCard icon={ShieldCheck} title="Specification">
                        <ReviewRow
                            label="Category"
                            value={
                                item.ppe_type
                                    ? catLabel(item.ppe_type.category)
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Standard"
                            value={item.ppe_type?.standards_reference}
                        />
                        <ReviewRow
                            label="Brand / model"
                            value={
                                [item.brand, item.model]
                                    .filter(Boolean)
                                    .join(' ') || undefined
                            }
                        />
                        <ReviewRow label="Serial" value={item.serial_number} />
                        <ReviewRow
                            label="Quantity"
                            value={String(item.quantity)}
                        />
                        <ReviewRow label="Site" value={item.site?.name} />
                        <ReviewRow label="Location" value={item.location} />
                        <ReviewRow
                            label="Purchased"
                            value={
                                item.purchase_date
                                    ? fmtDateNZ(item.purchase_date)
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Expiry"
                            value={
                                item.expiry_date
                                    ? fmtDateNZ(item.expiry_date)
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Next inspection"
                            value={
                                item.next_inspection_due
                                    ? fmtDateNZ(item.next_inspection_due)
                                    : undefined
                            }
                        />
                    </ReviewCard>
                    {isCondemned || isDisposed ? (
                        <ReviewCard icon={Ban} title="Retirement">
                            <ReviewRow
                                label="Condemned"
                                value={
                                    item.condemned_at
                                        ? `${fmtDateTimeNZ(item.condemned_at)}${item.condemned_by ? ` · ${item.condemned_by.name}` : ''}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Reason"
                                value={item.condemned_reason}
                            />
                            <ReviewRow
                                label="Disposed"
                                value={
                                    item.disposed_at
                                        ? `${fmtDateTimeNZ(item.disposed_at)}${item.disposed_by ? ` · ${item.disposed_by.name}` : ''}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Method"
                                value={item.disposal_method}
                            />
                        </ReviewCard>
                    ) : null}
                    <div>
                        <div className="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                            <Paperclip className="h-3.5 w-3.5" /> Documents
                        </div>
                        {canManage ? (
                            <AttachmentUploader
                                endpoint={`/health-safety/ppe/inventory/${item.id}/attachments`}
                                noteField="notes"
                                accept="image/*,.pdf,.doc,.docx"
                                hint="Certificate, declaration of conformity, disposal evidence — up to 20 MB each"
                            />
                        ) : null}
                        <AttachmentGrid attachments={item.attachments} />
                    </div>
                </SectionShell>
            ) : null}

            {section === 'allocation' ? (
                <SectionShell
                    icon={User}
                    title="Allocation"
                    blurb={active ? 'Current issue' : 'Availability'}
                >
                    {active ? (
                        <>
                            <ReviewCard icon={User} title="Issued">
                                <ReviewRow
                                    label="Worker"
                                    value={active.user?.name}
                                />
                                <ReviewRow
                                    label="Allocated"
                                    value={
                                        active.allocated_at
                                            ? fmtDateTimeNZ(active.allocated_at)
                                            : undefined
                                    }
                                />
                                <ReviewRow
                                    label="Fit-test"
                                    value={
                                        active.fit_test_completed
                                            ? `Pass${active.fit_test_date ? ` · ${fmtDateNZ(active.fit_test_date)}` : ''}`
                                            : 'Not completed'
                                    }
                                />
                                <ReviewRow
                                    label="Training"
                                    value={
                                        active.training_completed
                                            ? 'Completed'
                                            : 'Outstanding'
                                    }
                                />
                                <ReviewRow
                                    label="Acknowledged"
                                    value={
                                        active.acknowledged
                                            ? `Yes${active.acknowledged_at ? ` · ${fmtDateNZ(active.acknowledged_at)}` : ''}`
                                            : 'Pending worker acknowledgement'
                                    }
                                />
                            </ReviewCard>
                            {canManage ? (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            onAction({
                                                kind: 'return',
                                                allocationId: active.id,
                                                worker:
                                                    active.user?.name ??
                                                    'worker',
                                                itemLabel: label,
                                            })
                                        }
                                    >
                                        <Reply className="mr-1 h-3.5 w-3.5" />{' '}
                                        Return PPE
                                    </Button>
                                    {!active.acknowledged ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                onAction({
                                                    kind: 'acknowledge',
                                                    allocationId: active.id,
                                                })
                                            }
                                        >
                                            <ClipboardCheck className="mr-1 h-3.5 w-3.5" />{' '}
                                            Mark acknowledged
                                        </Button>
                                    ) : null}
                                </div>
                            ) : null}
                            <div>
                                <div className="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                    <Paperclip className="h-3.5 w-3.5" />{' '}
                                    Fit-test & sign-off records
                                </div>
                                {canManage ? (
                                    <AttachmentUploader
                                        endpoint={`/health-safety/ppe/allocations/${active.id}/attachments`}
                                        noteField="notes"
                                        accept="image/*,.pdf,.doc,.docx"
                                        hint="Fit-test record (AS/NZS 1715), acknowledgement, training certificate"
                                    />
                                ) : null}
                            </div>
                        </>
                    ) : (
                        <EmptyBlock
                            icon={User}
                            title="Not currently allocated"
                            sub="This item is available to issue."
                        />
                    )}
                </SectionShell>
            ) : null}

            {section === 'inspections' ? (
                <SectionShell
                    icon={ClipboardCheck}
                    title="Inspections"
                    blurb="Checks & due dates"
                >
                    {item.next_inspection_due ? (
                        <div className="rounded-lg border border-border bg-muted/30 px-3 py-2 text-[13px]">
                            <span className="text-muted-foreground">
                                Next scheduled ·{' '}
                            </span>
                            <span className="font-semibold">
                                {fmtDateNZ(item.next_inspection_due)}
                            </span>
                        </div>
                    ) : null}
                    {item.inspections.length ? (
                        <div className="flex flex-col gap-2">
                            {item.inspections.map((ins) => (
                                <div
                                    key={ins.id}
                                    className="rounded-xl border border-border p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="text-[13px] font-semibold">
                                            {fmtDateNZ(ins.inspected_at)}
                                        </div>
                                        <PpeChip
                                            tone={
                                                ins.result === 'pass'
                                                    ? 'success'
                                                    : ins.result ===
                                                        'needs_repair'
                                                      ? 'warning'
                                                      : 'critical'
                                            }
                                        >
                                            {ins.result.replace('_', ' ')}
                                        </PpeChip>
                                    </div>
                                    <div className="mt-0.5 text-[12px] text-muted-foreground">
                                        {ins.inspector?.name
                                            ? `by ${ins.inspector.name}`
                                            : ''}
                                        {ins.condition_after
                                            ? ` · condition ${ins.condition_after}`
                                            : ''}
                                    </div>
                                    {ins.findings ? (
                                        <div className="mt-1.5 text-[13px]">
                                            {ins.findings}
                                        </div>
                                    ) : null}
                                    <AttachmentGrid
                                        attachments={ins.attachments}
                                    />
                                </div>
                            ))}
                        </div>
                    ) : (
                        <EmptyBlock
                            icon={ClipboardCheck}
                            title="No inspections yet"
                            sub="Record the first inspection from the footer."
                        />
                    )}
                </SectionShell>
            ) : null}

            {section === 'history' ? (
                <SectionShell
                    icon={History}
                    title="History"
                    blurb="Full audit trail"
                >
                    {item.history.length ? (
                        <ol className="relative flex flex-col gap-4 border-l border-border pl-5">
                            {item.history.map((h, i) => (
                                <li key={i} className="relative">
                                    <span className="absolute -left-[26px] grid h-5 w-5 place-items-center rounded-full bg-accent text-primary">
                                        <History className="h-3 w-3" />
                                    </span>
                                    <div className="text-[13px] font-semibold">
                                        {h.label}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">
                                        {fmtDateTimeNZ(h.at)}
                                        {h.actor ? ` · ${h.actor}` : ''}
                                    </div>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <EmptyBlock
                            icon={History}
                            title="No history yet"
                            sub="Lifecycle events will appear here."
                        />
                    )}
                </SectionShell>
            ) : null}
        </WizardShell>
    );
}

// ───────────────────────── Allocation detail ─────────────────────────

function AllocationDetailView({
    alloc,
    canManage,
    onClose,
    onAction,
}: {
    alloc: AllocationDetail;
    canManage: boolean;
    onClose: () => void;
    onAction: (a: DetailAction) => void;
}) {
    const item = alloc.inventory_item;
    const label = `${alloc.ppe_type?.name ?? 'PPE'}${item?.serial_number ? ` (${item.serial_number})` : ''}`;
    const active = !alloc.returned_at;

    return (
        <WizardShell
            open
            onClose={onClose}
            title={`Allocation — ${alloc.user?.name ?? 'worker'}`}
            description={`${alloc.ppe_type?.name ?? 'PPE'} issued to ${alloc.user?.name ?? 'worker'}`}
            railIcon={User}
            railTitle={alloc.user?.name ?? 'Worker'}
            railSub={alloc.ppe_type?.name ?? 'PPE'}
            steps={
                [
                    {
                        key: 'allocation',
                        label: 'Allocation',
                        blurb: 'Issue record',
                        icon: User,
                    },
                ] as readonly WizardStep[]
            }
            stepIndex={0}
            onStepClick={() => {}}
            pct={null}
            footerStart={
                <PpeChip tone={active ? 'info' : 'neutral'}>
                    {active ? 'Active issue' : 'Returned'}
                </PpeChip>
            }
            footerEnd={
                canManage && active ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                onAction({
                                    kind: 'return',
                                    allocationId: alloc.id,
                                    worker: alloc.user?.name ?? 'worker',
                                    itemLabel: label,
                                })
                            }
                        >
                            <Reply className="mr-1 h-3.5 w-3.5" /> Return PPE
                        </Button>
                        {!alloc.acknowledged ? (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onAction({
                                        kind: 'acknowledge',
                                        allocationId: alloc.id,
                                    })
                                }
                            >
                                <ClipboardCheck className="mr-1 h-3.5 w-3.5" />{' '}
                                Mark acknowledged
                            </Button>
                        ) : null}
                    </div>
                ) : null
            }
        >
            <SectionShell
                icon={User}
                title="Allocation"
                blurb="Issue record & sign-off"
            >
                <ReviewCard icon={User} title="Issue">
                    <ReviewRow label="Worker" value={alloc.user?.name} />
                    <ReviewRow label="Item" value={label} />
                    <ReviewRow label="Site" value={item?.site?.name} />
                    <ReviewRow
                        label="Allocated"
                        value={
                            alloc.allocated_at
                                ? fmtDateTimeNZ(alloc.allocated_at)
                                : undefined
                        }
                    />
                    <ReviewRow
                        label="Fit-test"
                        value={
                            alloc.fit_test_completed
                                ? `Pass${alloc.fit_test_date ? ` · ${fmtDateNZ(alloc.fit_test_date)}` : ''}`
                                : 'Not completed'
                        }
                    />
                    <ReviewRow
                        label="Training"
                        value={
                            alloc.training_completed
                                ? `Completed${alloc.training_date ? ` · ${fmtDateNZ(alloc.training_date)}` : ''}`
                                : 'Outstanding'
                        }
                    />
                    <ReviewRow
                        label="Acknowledged"
                        value={
                            alloc.acknowledged
                                ? `Yes${alloc.acknowledged_by ? ` · ${alloc.acknowledged_by.name}` : ''}`
                                : 'Pending'
                        }
                    />
                    <ReviewRow
                        label="Issued by"
                        value={alloc.issued_by?.name}
                    />
                    <ReviewRow
                        label="Returned"
                        value={
                            alloc.returned_at
                                ? fmtDateTimeNZ(alloc.returned_at)
                                : undefined
                        }
                    />
                    <ReviewRow label="Notes" value={alloc.notes} />
                </ReviewCard>
                <div>
                    <div className="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        <Paperclip className="h-3.5 w-3.5" /> Fit-test &
                        sign-off records
                    </div>
                    {canManage ? (
                        <AttachmentUploader
                            endpoint={`/health-safety/ppe/allocations/${alloc.id}/attachments`}
                            noteField="notes"
                            accept="image/*,.pdf,.doc,.docx"
                            hint="Fit-test record (AS/NZS 1715), acknowledgement, training certificate"
                        />
                    ) : null}
                    <AttachmentGrid attachments={alloc.attachments} />
                </div>
            </SectionShell>
        </WizardShell>
    );
}

// ───────────────────────── Entry point ─────────────────────────

export function PpeDetailDialog({
    detail,
    canManage,
    onClose,
    onAction,
}: {
    detail: PpeDetail;
    canManage: boolean;
    onClose: () => void;
    onAction: (a: DetailAction) => void;
}) {
    return detail.kind === 'item' ? (
        <ItemDetailView
            item={detail.item}
            canManage={canManage}
            onClose={onClose}
            onAction={onAction}
        />
    ) : (
        <AllocationDetailView
            alloc={detail.allocation}
            canManage={canManage}
            onClose={onClose}
            onAction={onAction}
        />
    );
}
