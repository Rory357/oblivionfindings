import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    ClipboardList,
    History,
    Pill,
    Users,
} from 'lucide-react';
import { useState } from 'react';

import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';

import {
    isConcealed,
    type MaybeConcealed,
    type RecordMedicine,
    type RecordMedicineDetail,
    type RecordSupportPlan,
} from './types';
import {
    ConcealedCaption,
    concealedIdentity,
    FactStrip,
    recordDate,
    SectionCard,
    Wrap,
} from './ui';
import { useRecordJson, type RecordLoad } from './use-record-json';

const SUPPORT = {
    administer: ['Administer', 'Staff give this medicine'],
    assist: ['Assist', 'Help with the agreed parts'],
    prompt: ['Prompt', 'Remind the person to take it'],
    independent: ['Self-managed', 'The person manages it'],
} as const;
const STATUS = {
    active: ['Active', 'success'],
    awaiting: ['Waiting to be checked', 'warning'],
    paused: ['Paused', 'neutral'],
    stopped: ['Stopped', 'neutral'],
} as const;

export function ReadingState({
    load,
    reload,
}: {
    load: RecordLoad;
    reload: () => void;
}) {
    if (load === 'loading')
        return (
            <Card className="p-5">
                <SkeletonTable rows={5} columns={4} />
            </Card>
        );
    if (load === 'ready') return null;
    return (
        <Card className="p-2">
            <ErrorState
                title={
                    load === 'forbidden'
                        ? 'This medication record is no longer available'
                        : 'We couldn’t load this part of the record'
                }
                message={
                    load === 'forbidden'
                        ? 'Access follows the person’s current house and your medication permissions.'
                        : 'Try again before relying on this information.'
                }
                onRetry={reload}
            />
        </Card>
    );
}

export function MedicinesSection({
    clientId,
    stopped,
    search,
    onMedicine,
}: {
    clientId: number;
    stopped: boolean;
    search: string;
    onMedicine: (id: number) => void;
}) {
    const { data, load, reload } = useRecordJson<{
        rows: MaybeConcealed<RecordMedicine>[];
        hidden: number;
    }>(
        `/emar/clients/${clientId}/record/medicines${stopped ? '?status=stopped' : ''}`,
    );
    if (load !== 'ready' || !data)
        return <ReadingState load={load} reload={reload} />;
    const query = search.trim().toLocaleLowerCase('en-NZ');
    const rows = data.rows.filter(
        (row) =>
            !query ||
            (!isConcealed(row) &&
                `${row.name} ${row.strength ?? ''}`
                    .toLocaleLowerCase('en-NZ')
                    .includes(query)),
    );
    return (
        <div className="flex min-w-0 flex-col gap-5">
            <ListCaption
                title={stopped ? 'Stopped medicines' : 'Current medicines'}
                caption={
                    <>
                        {rows.length} of {data.rows.length} shown ·{' '}
                        <ConcealedCaption n={data.hidden} />
                    </>
                }
            />
            <MedicineTable rows={rows} onMedicine={onMedicine} />
        </div>
    );
}

function MedicineTable({
    rows,
    onMedicine,
    supportPlan,
}: {
    rows: MaybeConcealed<RecordMedicine>[];
    onMedicine: (id: number) => void;
    supportPlan?: RecordSupportPlan;
}) {
    const context = useEntityContextMenu<MaybeConcealed<RecordMedicine>>();
    const open = (row: MaybeConcealed<RecordMedicine>) => {
        if (!isConcealed(row)) onMedicine(row.id);
    };
    const menu = (row: MaybeConcealed<RecordMedicine>): MenuItem[] =>
        isConcealed(row)
            ? [
                  {
                      label: 'Details need controlled-medicine access',
                      icon: Pill,
                      disabled:
                          'Controlled-medicine access is needed to open these details.',
                  },
              ]
            : [
                  {
                      label: 'Medicine details',
                      icon: Pill,
                      onClick: () => open(row),
                  },
              ];
    if (!rows.length)
        return (
            <Card className="p-2">
                <EmptyState
                    icon={Pill}
                    title="No medicines to show"
                    description="Try another view or clear the search."
                />
            </Card>
        );
    return (
        <>
            <EntityTable
                rows={rows}
                rowKey={(row) => row.key}
                identityLabel="Medicine"
                identityWidth="1.8fr"
                rowHeight="content"
                minWidth={900}
                identity={(row) =>
                    isConcealed(row)
                        ? concealedIdentity
                        : {
                              icon: Pill,
                              name: `${row.name} ${row.strength ?? ''}`.trim(),
                              subline: (
                                  <Wrap>
                                      {row.controlled ? 'Controlled · ' : ''}
                                      {row.witness
                                          ? 'Second person needed · '
                                          : ''}
                                      {row.high_risk ? 'High risk' : ''}
                                  </Wrap>
                              ),
                          }
                }
                columns={
                    supportPlan
                        ? [
                              {
                                  key: 'support',
                                  label: 'Support',
                                  width: '1fr',
                                  cell: (row) =>
                                      isConcealed(row) ? (
                                          '—'
                                      ) : (
                                          <StatusBadge variant="info">
                                              {SUPPORT[row.support][0]}
                                          </StatusBadge>
                                      ),
                              },
                              {
                                  key: 'means',
                                  label: 'What staff do',
                                  width: '1.4fr',
                                  cell: (row) =>
                                      isConcealed(row)
                                          ? '—'
                                          : SUPPORT[row.support][1],
                              },
                              {
                                  key: 'decided',
                                  label: 'Decided',
                                  width: '1.3fr',
                                  cell: () =>
                                      supportPlan.assessment
                                          ? `${recordDate(supportPlan.assessment.assessed)} · ${supportPlan.assessment.by ?? '—'}`
                                          : 'No assessment — staff give it',
                              },
                              {
                                  key: 'review',
                                  label: 'Reassess by',
                                  width: '1fr',
                                  cell: () =>
                                      recordDate(
                                          supportPlan.assessment?.reassess,
                                      ),
                              },
                          ]
                        : [
                              {
                                  key: 'dose',
                                  label: 'Amount & route',
                                  width: '1.1fr',
                                  cell: (row) =>
                                      isConcealed(row) ? (
                                          '—'
                                      ) : (
                                          <Wrap>
                                              {row.amount} · {row.route ?? '—'}
                                          </Wrap>
                                      ),
                              },
                              {
                                  key: 'when',
                                  label: 'When',
                                  width: '1.3fr',
                                  cell: (row) =>
                                      isConcealed(row) ? (
                                          '—'
                                      ) : (
                                          <Wrap>{row.when}</Wrap>
                                      ),
                              },
                              {
                                  key: 'support',
                                  label: 'Support',
                                  width: '1fr',
                                  cell: (row) =>
                                      isConcealed(row)
                                          ? '—'
                                          : SUPPORT[row.support][0],
                              },
                              {
                                  key: 'status',
                                  label: 'Order',
                                  width: '1.1fr',
                                  cell: (row) =>
                                      isConcealed(row) ? (
                                          '—'
                                      ) : (
                                          <StatusBadge
                                              variant={STATUS[row.status][1]}
                                          >
                                              {STATUS[row.status][0]}
                                          </StatusBadge>
                                      ),
                              },
                          ]
                }
                actionsFor={menu}
                onOpen={open}
                onRowContextMenu={context.open}
                mutedFor={(row) =>
                    !isConcealed(row) && row.status === 'stopped'
                }
            />
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    icon={Pill}
                    title={
                        isConcealed(context.ctx.record)
                            ? 'Controlled medicine'
                            : context.ctx.record.name
                    }
                    items={menu(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
        </>
    );
}

export function SupportSection({
    clientId,
    assessment,
    onMedicine,
}: {
    clientId: number;
    assessment: boolean;
    onMedicine: (id: number) => void;
}) {
    const { data, load, reload } = useRecordJson<RecordSupportPlan>(
        `/emar/clients/${clientId}/record/support`,
    );
    if (load !== 'ready' || !data)
        return <ReadingState load={load} reload={reload} />;
    const summary = data.assessment;
    if (assessment)
        return summary ? (
            <SectionCard
                title={summary.outcome}
                eyebrow="Self-administration assessment"
                icon={ClipboardList}
                right={
                    <Button variant="link" asChild>
                        <Link href={`/emar/self-admin?client_id=${clientId}`}>
                            Open Support & self-administration{' '}
                            <ArrowUpRight className="size-4" />
                        </Link>
                    </Button>
                }
            >
                <FactStrip
                    items={[
                        {
                            label: 'Assessed',
                            value: `${recordDate(summary.assessed)} · ${summary.by ?? '—'}`,
                        },
                        {
                            label: 'Reassess by',
                            value: recordDate(summary.reassess),
                        },
                        {
                            label: 'Agreement',
                            value: summary.agreement
                                ? `Signed ${recordDate(summary.agreement.signed)} · ${summary.agreement.by ?? '—'}`
                                : 'Not signed',
                        },
                    ]}
                />
                <p className="text-sm">
                    <span className="text-muted-foreground">Storage · </span>
                    {summary.storage ?? 'Not recorded'}
                </p>
                <p className="text-caption">
                    Support for each medicine comes from this assessment. It
                    changes through a reassessment.
                </p>
            </SectionCard>
        ) : (
            <Card className="p-2">
                <EmptyState
                    icon={ClipboardList}
                    title="No self-administration assessment recorded"
                    description="Until one is done, staff give every medicine (Administer)."
                />
            </Card>
        );
    return (
        <div className="flex min-w-0 flex-col gap-5">
            <ListCaption
                title="Support by medicine"
                caption={
                    <>
                        {data.rows.length} shown ·{' '}
                        <ConcealedCaption n={data.hidden} /> ·{' '}
                        {summary
                            ? `from the assessment of ${recordDate(summary.assessed)}`
                            : 'no assessment yet'}
                    </>
                }
            />
            <MedicineTable
                rows={data.rows}
                onMedicine={onMedicine}
                supportPlan={data}
            />
        </div>
    );
}

const DETAIL_SECTIONS = [
    { key: 'order', label: 'Order', blurb: 'Prescribed medicine', icon: Pill },
    {
        key: 'how',
        label: 'How it’s given',
        blurb: 'Instructions and support',
        icon: Users,
    },
    {
        key: 'doses',
        label: 'Recent doses',
        blurb: 'Last seven days',
        icon: History,
    },
] as const;

export function RecordMedicineDialog({
    clientId,
    medicationId,
    onClose,
}: {
    clientId: number;
    medicationId: number;
    onClose: () => void;
}) {
    const { data, load, reload } = useRecordJson<RecordMedicineDetail>(
        `/emar/clients/${clientId}/record/medicines/${medicationId}`,
    );
    const [section, setSection] = useState(0);
    const order = data?.medicine;
    return (
        <WizardShell
            frontline
            open
            onClose={onClose}
            title={order?.name ?? 'Medicine details'}
            description="Read the order, instructions and recent doses"
            railIcon={Pill}
            railTitle={order?.name ?? 'Medicine details'}
            railSub={order?.strength ?? ''}
            steps={DETAIL_SECTIONS}
            stepIndex={section}
            onStepClick={setSection}
            sequential={false}
            headerLabel={DETAIL_SECTIONS[section].label}
            footerEnd={<Button onClick={onClose}>Close</Button>}
        >
            {load !== 'ready' || !order ? (
                <ReadingState load={load} reload={reload} />
            ) : (
                <WizardStepPane>
                    {section === 0 ? (
                        <>
                            <ReviewRow
                                label="Medicine"
                                value={`${order.name} ${order.strength ?? ''}`}
                            />
                            <ReviewRow label="Amount" value={order.amount} />
                            <ReviewRow label="Route" value={order.route} />
                            <ReviewRow label="When" value={order.when} />
                            <ReviewRow label="For" value={order.indication} />
                            <ReviewRow
                                label="Prescriber"
                                value={order.prescriber}
                            />
                            <ReviewRow
                                label="Started"
                                value={recordDate(order.started)}
                            />
                            <ReviewRow
                                label="Review due"
                                value={recordDate(order.review)}
                            />
                            <ReviewRow
                                label="Order"
                                value={STATUS[order.status][0]}
                            />
                            <ReviewRow
                                label="Checked"
                                value={
                                    order.verified
                                        ? `${formatDateTime(order.verified.at)} · ${order.verified.by ?? '—'}`
                                        : 'Waiting to be checked'
                                }
                            />
                            {order.stopped ? (
                                <ReviewRow
                                    label="Stopped"
                                    value={`${formatDateTime(order.stopped.at)} · ${order.stopped.by ?? '—'} · ${order.stopped.reason ?? ''}`}
                                />
                            ) : null}
                            <Button variant="link" asChild>
                                <Link
                                    href={`/emar/orders?client_id=${clientId}`}
                                >
                                    Open Orders & reviews{' '}
                                    <ArrowUpRight className="size-4" />
                                </Link>
                            </Button>
                        </>
                    ) : null}
                    {section === 1 ? (
                        <>
                            <ReviewRow
                                label="Instructions"
                                value={order.instructions ?? 'Not recorded'}
                            />
                            <ReviewRow
                                label="Support"
                                value={SUPPORT[order.support][0]}
                            />
                            <ReviewRow
                                label="What staff do"
                                value={SUPPORT[order.support][1]}
                            />
                            <ReviewRow
                                label="Second person"
                                value={
                                    order.witness
                                        ? 'Required'
                                        : 'Follow the recording checks for this dose'
                                }
                            />
                            <p className="text-caption">
                                Recording checks come from the dose’s current
                                requirements.
                            </p>
                        </>
                    ) : null}
                    {section === 2 ? (
                        data.recent_doses.length ? (
                            data.recent_doses.map((dose) => (
                                <ReviewRow
                                    key={dose.id}
                                    label={formatDateTime(dose.at)}
                                    value={`${dose.status} · ${dose.by ?? '—'}`}
                                />
                            ))
                        ) : (
                            <EmptyState
                                icon={History}
                                title="No doses in the last seven days"
                                variant="compact"
                            />
                        )
                    ) : null}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
