import ConfirmDialog from '@/components/confirm-dialog';
import {
    EntityCard,
    EntityCardGrid,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Check,
    ClipboardCheck,
    FileText,
    FolderOpen,
    Paperclip,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import {
    ConfirmationDialog,
    DuplicateResolutionDialog,
    PaperEntryDialog,
    RequestErrors,
} from './_dialogs';
import type { Dose, Downtime, PaperEntry, PrnOrder, Staff } from './types';

type Props = {
    downtime: Downtime;
    doses: Dose[];
    entries: PaperEntry[];
    prn_orders: PrnOrder[];
    staff: Staff[];
    actor_id: number;
    open_paper_entry: number | null;
    can_manage: boolean;
    can_finish: boolean;
    controlled_notice: string | null;
    sheets_concealed: boolean;
    sheets: { id: number; mime_type: string; url: string }[];
};
const states = {
    giver_to_confirm: 'Actual giver to confirm',
    witness_to_confirm: 'Second person to confirm',
    ready_to_reconcile: 'Signed paper — to reconcile',
    entered_from_paper: 'Entered from paper',
};

export default function DowntimeShow({
    downtime,
    doses,
    entries,
    prn_orders,
    staff,
    actor_id,
    open_paper_entry,
    can_manage,
    can_finish,
    controlled_notice,
    sheets_concealed,
    sheets,
}: Props) {
    const [view, setView] = useState<'paper' | 'facts'>('paper');
    const [capture, setCapture] = useState<{ dose: Dose | null } | null>(null);
    const [selected, setSelected] = useState<PaperEntry | null>(
        entries.find((entry) => entry.id === open_paper_entry) ?? null,
    );
    const [duplicate, setDuplicate] = useState<Dose | null>(null);
    const [command, setCommand] = useState<{
        entry: PaperEntry;
        kind: 'giver' | 'witness' | 'reconcile';
    } | null>(null);
    const [finish, setFinish] = useState(false);
    const [finishing, setFinishing] = useState(false);
    const context = useEntityContextMenu<Dose>();
    const entryContext = useEntityContextMenu<PaperEntry>();
    const page = usePage();
    const missing = doses.filter((dose) => !dose.entry_id && !dose.resolution);
    const confirmed = entries.filter(
        (entry) => entry.reconciliation.state === 'ready_to_reconcile',
    );
    const posted = entries.filter(
        (entry) => entry.reconciliation.state === 'entered_from_paper',
    );
    function openDose(dose: Dose) {
        const entry = entries.find((row) => row.id === dose.entry_id);
        if (entry) setSelected(entry);
        else if (dose.resolution || dose.resolution_choices.length)
            setDuplicate(dose);
        else setCapture({ dose });
    }
    const doseActions = (dose: Dose): MenuItem[] => [
        {
            label: dose.entry_id
                ? 'Open paper entry'
                : dose.resolution
                  ? 'Open duplicate review'
                  : dose.resolution_choices.length
                    ? 'Review existing evidence'
                    : 'Enter paper facts',
            icon: FolderOpen,
            onClick: () => openDose(dose),
            disabled:
                !dose.entry_id && !dose.resolution && downtime.finished_at
                    ? 'Paper collection finished'
                    : undefined,
        },
    ];
    const entryActions = (entry: PaperEntry): MenuItem[] => [
        {
            label: 'Open paper evidence',
            icon: FileText,
            onClick: () => setSelected(entry),
        },
        ...(entry.can_confirm_giver
            ? [
                  {
                      label: 'Confirm as actual giver',
                      icon: Check,
                      onClick: () =>
                          setCommand({ entry, kind: 'giver' as const }),
                  },
              ]
            : []),
        ...(entry.can_confirm_witness
            ? [
                  {
                      label: 'Confirm with my witness PIN',
                      icon: Check,
                      onClick: () =>
                          setCommand({ entry, kind: 'witness' as const }),
                  },
              ]
            : []),
        ...(entry.reconciliation.can_reconcile
            ? [
                  {
                      label: 'Preview and apply paper entry',
                      icon: ClipboardCheck,
                      onClick: () =>
                          setCommand({ entry, kind: 'reconcile' as const }),
                  },
              ]
            : []),
    ];
    const status = (entry: PaperEntry) => (
        <StatusBadge
            variant={
                entry.reconciliation.state === 'entered_from_paper'
                    ? 'success'
                    : 'warning'
            }
        >
            {states[entry.reconciliation.state]}
        </StatusBadge>
    );
    const header = (
        <PageHeader
            variant="profile"
            icon={FileText}
            backHref="/emar/downtime"
            title={`DT-${downtime.id} · ${downtime.site}`}
            titleChip={
                <StatusBadge
                    variant={downtime.finished_at ? 'neutral' : 'warning'}
                >
                    {downtime.finished_at
                        ? 'Paper collected'
                        : 'Collecting paper'}
                </StatusBadge>
            }
            subline={`${formatDateTime(downtime.started_at)} to ${formatDateTime(downtime.ended_at)} · NZ time`}
            actions={
                can_manage && !downtime.finished_at ? (
                    <PageHeaderPrimaryButton
                        onClick={() => setCapture({ dose: null })}
                    >
                        <Plus className="size-4" /> Add as-needed paper dose
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Listed doses to enter"
                        tone="warning"
                        onClick={() => setView('paper')}
                    >
                        <PageHeaderMeterBig>
                            {missing.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Scheduled doses without paper facts
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Confirmations still due"
                        tone="warning"
                        onClick={() => setView('paper')}
                    >
                        <PageHeaderMeterBig>
                            {entries.length - confirmed.length - posted.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Giver or witness confirmation needed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Signed paper to reconcile"
                        onClick={() => setView('paper')}
                    >
                        <PageHeaderMeterBig>
                            {confirmed.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Confirmed facts awaiting reconciliation
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Entered from paper"
                        onClick={() => setView('paper')}
                    >
                        <PageHeaderMeterBig>{posted.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Reconciled paper entries
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={
                <PageHeaderRail
                    items={[
                        { key: 'paper', label: 'Paper records' },
                        { key: 'facts', label: 'Downtime & sheets' },
                    ]}
                    value={view}
                    onSelect={setView}
                />
            }
        />
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/emar' },
                { title: 'Downtime & paper records', href: '/emar/downtime' },
                {
                    title: `DT-${downtime.id}`,
                    href: `/emar/downtime/${downtime.id}`,
                },
            ]}
        >
            <Head title={`DT-${downtime.id} paper records`} />
            <PageLayout hero={header}>
                <div className="space-y-5">
                    <RequestErrors errors={page.props.errors} />
                    {controlled_notice && (
                        <p className="rounded-lg border bg-muted p-4">
                            {controlled_notice}
                        </p>
                    )}
                    {view === 'facts' ? (
                        <section className="space-y-4 rounded-xl border bg-card p-5">
                            <h2 className="text-section-title">
                                What went down
                            </h2>
                            <p className="whitespace-pre-wrap">
                                {downtime.description}
                            </p>
                            <p>
                                {formatDateTime(downtime.started_at)} to{' '}
                                {formatDateTime(downtime.ended_at)}
                            </p>
                            <h2 className="text-section-title">
                                Private paper sheets
                            </h2>
                            {sheets.map((sheet) => (
                                <Button
                                    key={sheet.id}
                                    variant="outline"
                                    className="frontline-tap"
                                    asChild
                                >
                                    <a href={sheet.url}>
                                        <Paperclip className="size-4" /> Open
                                        signed sheet {sheet.id}
                                    </a>
                                </Button>
                            ))}
                            {!sheets.length && (
                                <p className="text-subtle">
                                    {sheets_concealed
                                        ? 'Paper scans need oversight and controlled-medicine access because they may contain concealed medicines.'
                                        : 'No paper sheets attached. Keep the signed originals securely.'}
                                </p>
                            )}
                        </section>
                    ) : (
                        <>
                            {can_manage && (
                                <section className="space-y-3">
                                    <ListCaption
                                        title="Listed doses — check against paper"
                                        caption={`${missing.length} still to enter · no actual outcome assumed`}
                                    />
                                    <div className="hidden md:block">
                                        <EntityTable
                                            rows={doses}
                                            rowKey={(dose) => dose.id}
                                            identity={(dose) => ({
                                                icon: FileText,
                                                name: dose.snapshot.person,
                                                subline: `${dose.snapshot.medicine} · ${dose.snapshot.dosage}`,
                                            })}
                                            columns={[
                                                {
                                                    key: 'due',
                                                    label: 'Listed due time',
                                                    width: '1fr',
                                                    cell: (dose) =>
                                                        formatDateTime(
                                                            dose.scheduled_for,
                                                        ),
                                                },
                                                {
                                                    key: 'paper',
                                                    label: 'Paper facts',
                                                    width: '1fr',
                                                    cell: (dose) => (
                                                        <StatusBadge
                                                            variant={
                                                                dose.entry_id ||
                                                                dose.resolution
                                                                    ? 'neutral'
                                                                    : 'warning'
                                                            }
                                                        >
                                                            {dose.resolution
                                                                ? 'Duplicate reviewed'
                                                                : dose.entry_id
                                                                  ? 'Paper facts collected'
                                                                  : 'To enter'}
                                                        </StatusBadge>
                                                    ),
                                                },
                                                {
                                                    key: 'second',
                                                    label: 'Second person / readings',
                                                    width: '1.2fr',
                                                    cell: (dose) => (
                                                        <div>
                                                            <p>
                                                                {dose.snapshot
                                                                    .second_person_required
                                                                    ? 'Second person required if given'
                                                                    : '—'}
                                                            </p>
                                                            <p className="text-subtle">
                                                                {dose.snapshot.observation_keys
                                                                    .map(
                                                                        (key) =>
                                                                            key.replace(
                                                                                /_/g,
                                                                                ' ',
                                                                            ),
                                                                    )
                                                                    .join(', ')}
                                                            </p>
                                                        </div>
                                                    ),
                                                },
                                            ]}
                                            actionsFor={doseActions}
                                            onOpen={openDose}
                                            onRowContextMenu={context.open}
                                            rowHeight="content"
                                        />
                                    </div>
                                    <div className="md:hidden">
                                        <EntityCardGrid>
                                            {doses.map((dose) => (
                                                <EntityCard
                                                    key={dose.id}
                                                    meridian={
                                                        dose.entry_id
                                                            ? 'neutral'
                                                            : 'warning'
                                                    }
                                                    icon={FileText}
                                                    name={dose.snapshot.person}
                                                    subline={`${dose.snapshot.medicine} · ${dose.snapshot.dosage}`}
                                                    actions={doseActions(dose)}
                                                    onOpen={() =>
                                                        openDose(dose)
                                                    }
                                                    onContextMenu={(event) =>
                                                        context.open(
                                                            event,
                                                            dose,
                                                        )
                                                    }
                                                    chips={
                                                        <StatusBadge
                                                            variant={
                                                                dose.entry_id ||
                                                                dose.resolution
                                                                    ? 'neutral'
                                                                    : 'warning'
                                                            }
                                                        >
                                                            {dose.resolution
                                                                ? 'Duplicate reviewed'
                                                                : dose.entry_id
                                                                  ? 'Paper facts collected'
                                                                  : 'To enter'}
                                                        </StatusBadge>
                                                    }
                                                    footer={{
                                                        primary: formatDateTime(
                                                            dose.scheduled_for,
                                                        ),
                                                        secondary: dose.snapshot
                                                            .second_person_required
                                                            ? 'Second-person confirmation required if given'
                                                            : undefined,
                                                    }}
                                                />
                                            ))}
                                        </EntityCardGrid>
                                    </div>
                                    {!doses.length && (
                                        <p className="text-subtle">
                                            No available scheduled doses without
                                            an eMAR outcome were listed for this
                                            downtime.
                                        </p>
                                    )}
                                </section>
                            )}
                            <section className="space-y-3">
                                <ListCaption
                                    title="Paper evidence and reconciliation"
                                    caption={`${entries.length} visible paper entries`}
                                />
                                {!entries.length ? (
                                    <Card className="p-6">
                                        <p className="text-section-title">
                                            No paper facts collected yet
                                        </p>
                                        <p className="text-subtle">
                                            Enter what the signed paper actually
                                            says. Collected evidence waits for
                                            accountable confirmations and
                                            clinical reconciliation.
                                        </p>
                                    </Card>
                                ) : (
                                    <>
                                        <div className="hidden md:block">
                                            <EntityTable
                                                rows={entries}
                                                rowKey={(entry) => entry.id}
                                                identity={(entry) => ({
                                                    icon: FileText,
                                                    name: entry.snapshot.person,
                                                    subline: `${entry.snapshot.medicine} · ${entry.outcome}`,
                                                })}
                                                columns={[
                                                    {
                                                        key: 'time',
                                                        label: 'Actual time / giver on paper',
                                                        width: '1.2fr',
                                                        cell: (entry) => (
                                                            <div>
                                                                <p>
                                                                    {formatDateTime(
                                                                        entry.given_at,
                                                                    )}
                                                                </p>
                                                                <p className="text-subtle">
                                                                    {
                                                                        entry.given_by
                                                                    }{' '}
                                                                    (paper)
                                                                </p>
                                                            </div>
                                                        ),
                                                    },
                                                    {
                                                        key: 'entered',
                                                        label: 'Entered at / entered by',
                                                        width: '1.2fr',
                                                        cell: (entry) => (
                                                            <div>
                                                                <p>
                                                                    {formatDateTime(
                                                                        entry.entered_at,
                                                                    )}
                                                                </p>
                                                                <p className="text-subtle">
                                                                    {
                                                                        entry.entered_by
                                                                    }
                                                                </p>
                                                            </div>
                                                        ),
                                                    },
                                                    {
                                                        key: 'state',
                                                        label: 'State',
                                                        width: '1.3fr',
                                                        cell: status,
                                                    },
                                                ]}
                                                actionsFor={entryActions}
                                                onOpen={setSelected}
                                                onRowContextMenu={
                                                    entryContext.open
                                                }
                                                rowHeight="content"
                                            />
                                        </div>
                                        <div className="md:hidden">
                                            <EntityCardGrid>
                                                {entries.map((entry) => (
                                                    <EntityCard
                                                        key={entry.id}
                                                        meridian={
                                                            entry.reconciliation
                                                                .state ===
                                                            'entered_from_paper'
                                                                ? 'success'
                                                                : 'warning'
                                                        }
                                                        icon={FileText}
                                                        name={
                                                            entry.snapshot
                                                                .person
                                                        }
                                                        subline={`${entry.snapshot.medicine} · ${entry.outcome}`}
                                                        actions={entryActions(
                                                            entry,
                                                        )}
                                                        onOpen={() =>
                                                            setSelected(entry)
                                                        }
                                                        onContextMenu={(
                                                            event,
                                                        ) =>
                                                            entryContext.open(
                                                                event,
                                                                entry,
                                                            )
                                                        }
                                                        chips={status(entry)}
                                                        footer={{
                                                            primary: `${formatDateTime(entry.given_at)} · ${entry.given_by} (paper)`,
                                                            secondary: `Entered ${formatDateTime(entry.entered_at)} by ${entry.entered_by}`,
                                                        }}
                                                    />
                                                ))}
                                            </EntityCardGrid>
                                        </div>
                                    </>
                                )}
                            </section>
                            {can_manage && !downtime.finished_at && (
                                <Card className="flex flex-wrap items-center gap-4 p-4">
                                    <Button
                                        className="frontline-tap"
                                        variant="outline"
                                        disabled={!can_finish}
                                        onClick={() => setFinish(true)}
                                    >
                                        Finish paper collection
                                    </Button>
                                    <p className="text-subtle">
                                        {can_finish
                                            ? 'Confirmations and clinical reconciliation remain due afterwards.'
                                            : 'Every listed paper dose needs its actual facts first, including concealed entries for the authorised lead.'}
                                    </p>
                                </Card>
                            )}
                        </>
                    )}
                </div>
            </PageLayout>
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={context.ctx.record.snapshot.medicine}
                    icon={FileText}
                    items={doseActions(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {entryContext.ctx && (
                <EntityContextMenu
                    x={entryContext.ctx.x}
                    y={entryContext.ctx.y}
                    title={entryContext.ctx.record.snapshot.medicine}
                    icon={FileText}
                    items={entryActions(entryContext.ctx.record)}
                    onClose={entryContext.close}
                />
            )}
            {capture && (
                <PaperEntryDialog
                    downtimeId={downtime.id}
                    dose={capture.dose}
                    prnOrders={prn_orders}
                    staff={staff}
                    actorId={actor_id}
                    onClose={() => setCapture(null)}
                />
            )}
            {command && (
                <ConfirmationDialog
                    downtimeId={downtime.id}
                    entry={command.entry}
                    kind={command.kind}
                    onClose={() => setCommand(null)}
                />
            )}
            <Dialog
                open={!!selected}
                onOpenChange={(open) => {
                    if (!open) setSelected(null);
                }}
            >
                <DialogContent
                    className="flex max-h-[88vh] flex-col overflow-hidden p-0"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader className="shrink-0 border-b p-5 pr-12">
                        <DialogTitle>
                            Paper evidence — {selected?.snapshot.medicine}
                        </DialogTitle>
                        <DialogDescription>
                            Actual paper facts and today's reconciliation state
                            are shown separately.
                        </DialogDescription>
                    </DialogHeader>
                    {selected && (
                        <div className="min-h-0 space-y-4 overflow-y-auto px-5 pb-5">
                            {status(selected)}
                            <p>
                                {selected.snapshot.person} · {selected.outcome}{' '}
                                · {selected.dose_on_paper ?? 'Not given'}
                            </p>
                            <p>
                                Actual outcome{' '}
                                {formatDateTime(selected.given_at)} by{' '}
                                {selected.given_by} (paper).
                            </p>
                            <p>
                                Entered {formatDateTime(selected.entered_at)} by{' '}
                                {selected.entered_by}.
                            </p>
                            {selected.witness && (
                                <p>
                                    Second person on paper: {selected.witness}
                                </p>
                            )}
                            {selected.notes && (
                                <p className="whitespace-pre-wrap">
                                    {selected.notes}
                                </p>
                            )}
                            {selected.reconciliation.unavailable && (
                                <p className="rounded-lg border border-status-warning bg-status-warning-bg p-3 text-status-warning-foreground">
                                    {selected.reconciliation.unavailable}
                                </p>
                            )}
                            {selected.reconciliation.conflicts.map(
                                (conflict) => (
                                    <p
                                        key={conflict.kind}
                                        role="alert"
                                        className="text-status-critical"
                                    >
                                        {conflict.message}
                                    </p>
                                ),
                            )}
                            <div className="flex flex-wrap gap-2">
                                {entryActions(selected)
                                    .slice(1)
                                    .map((action) => (
                                        <Button
                                            key={action.label}
                                            className="frontline-tap"
                                            onClick={() => {
                                                setSelected(null);
                                                action.onClick?.();
                                            }}
                                        >
                                            {action.label}
                                        </Button>
                                    ))}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={finish}
                onClose={() => setFinish(false)}
                title="Finish collecting this paper?"
                description="Every listed dose has paper facts or an explicitly reviewed link to existing evidence. Accountable confirmations and clinical reconciliation remain due; no medication outcome or register balance is assumed."
                confirmText="Finish paper collection"
                variant="default"
                processing={finishing}
                onConfirm={() => {
                    setFinishing(true);
                    router.post(
                        `/emar/downtime/${downtime.id}/finish`,
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => setFinish(false),
                            onFinish: () => setFinishing(false),
                        },
                    );
                }}
            />
            {duplicate && (
                <DuplicateResolutionDialog
                    downtimeId={downtime.id}
                    dose={duplicate}
                    onClose={() => setDuplicate(null)}
                />
            )}
        </AppLayout>
    );
}
