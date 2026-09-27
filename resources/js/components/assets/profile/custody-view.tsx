import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate, formatDateTime } from '@/lib/fleet-utils';
import type { Props } from '@/pages/fleet-assets/assets/show';
import {
    ArrowRight,
    CircleAlert,
    History,
    Info,
    UserRound,
} from 'lucide-react';
import {
    ActionRow,
    Empty,
    Fact,
    Panel,
    SectionHeading,
    State,
    TextAction,
    human,
} from './presentation';
import type { Movement, ProfileAction, ProfileWorkspace } from './types';

export function AssetCustodyView({
    asset,
    data,
    section,
    onAction,
    onKit,
}: {
    asset: Props['asset'];
    data: ProfileWorkspace;
    section: string;
    onAction: (
        action: ProfileAction,
        movement?: Movement,
        itemId?: number,
    ) => void;
    onKit: () => void;
}) {
    const assignment = asset.assignments.find((item) => !item.returned_at);
    const pending = data.movements.find((item) =>
        ['pending_receipt', 'incomplete', 'disputed'].includes(item.state),
    );
    const loan = data.movements.find(
        (item) =>
            item.kind === 'loan' &&
            item.state === 'acknowledged' &&
            !item.returned_at,
    );
    const current = pending || loan || data.movements[0];
    const canManage =
        data.ready &&
        data.permissions.manageAssignments &&
        asset.status !== 'retired';
    const confirmed =
        current?.state === 'acknowledged'
            ? current.received_by
            : assignment?.assignee?.name;
    const transferAction = canManage && (
        <Button
            disabled={!!pending}
            onClick={() => onAction(loan ? 'return' : 'dispatch', loan)}
        >
            {loan ? 'Dispatch loan return' : 'Transfer or loan'}
            <ArrowRight />
        </Button>
    );
    return (
        <div className="space-y-5">
            <SectionHeading
                title={
                    section === 'movements'
                        ? 'Movement history'
                        : 'Accountable custody'
                }
                description="Placement, responsibility and physical receipt are recorded separately."
                actions={transferAction}
            />
            {section === 'current' ? (
                <>
                    <Card className="grid items-center gap-5 p-6 md:grid-cols-[1fr_auto_1fr_auto]">
                        <div>
                            <StatusBadge size="sm">
                                {current ? 'Origin' : 'Assigned location'}
                            </StatusBadge>
                            <h3 className="text-section-title mt-3">
                                {current?.origin ||
                                    asset.site?.name ||
                                    'Site not recorded'}
                            </h3>
                            <p className="text-subtle mt-1 text-muted-foreground">
                                {current
                                    ? `Dispatched ${formatDateTime(current.dispatched_at)}`
                                    : data.room ||
                                      asset.location ||
                                      'Room not recorded'}
                            </p>
                        </div>
                        <ArrowRight className="size-5 rotate-90 text-primary md:rotate-0" />
                        <div>
                            {current ? (
                                <State value={current.state} />
                            ) : (
                                <StatusBadge size="sm">
                                    No movement pending
                                </StatusBadge>
                            )}
                            <h3 className="text-section-title mt-3">
                                {current?.destination ||
                                    asset.site?.name ||
                                    'Site not recorded'}
                            </h3>
                            <p className="text-subtle mt-1 text-muted-foreground">
                                {current?.room ||
                                    data.room ||
                                    asset.location ||
                                    'Room not recorded'}
                            </p>
                            <p className="text-caption mt-1">
                                {current?.state === 'acknowledged'
                                    ? `Received by ${current.received_by || 'Recorded recipient'} · ${formatDateTime(current.received_at)}`
                                    : `Intended recipient: ${current?.recipient || 'Not recorded'}`}
                            </p>
                        </div>
                        {pending?.can_receive && canManage ? (
                            <Button
                                onClick={() => onAction('receive', pending)}
                            >
                                Review receipt
                            </Button>
                        ) : (
                            current && (
                                <span className="text-caption text-muted-foreground">
                                    {current.received_kit.length}/
                                    {current.kit.length} kit items confirmed
                                </span>
                            )
                        )}
                    </Card>
                    <div className="grid gap-5 lg:grid-cols-2">
                        <Panel
                            title="Responsibility"
                            icon={UserRound}
                            actions={
                                canManage && (
                                    <TextAction
                                        onClick={() =>
                                            onAction(
                                                assignment
                                                    ? 'release'
                                                    : 'assign',
                                                undefined,
                                                assignment?.id,
                                            )
                                        }
                                    >
                                        {assignment
                                            ? 'Release'
                                            : 'Assign responsibility'}
                                    </TextAction>
                                )
                            }
                        >
                            <dl className="space-y-5">
                                <Fact label="Assigned person">
                                    {assignment?.assignee?.name || 'Unassigned'}
                                </Fact>
                                <Fact label="Confirmed custodian">
                                    {confirmed || 'No confirmed custodian'}
                                </Fact>
                                <Fact label="Movement purpose">
                                    {current
                                        ? `${human(current.kind)} · ${current.reason || 'No reason recorded'}`
                                        : 'No movement recorded'}
                                </Fact>
                                <Fact label="Reference">
                                    {current
                                        ? `Movement #${current.id} · Origin and destination retained`
                                        : 'Not recorded'}
                                </Fact>
                                <Fact label="Expected return">
                                    {loan?.return_due_on
                                        ? formatDate(loan.return_due_on)
                                        : 'No outstanding loan'}
                                </Fact>
                            </dl>
                            <div className="flex gap-3 rounded-xl border border-status-info/20 bg-status-info-bg p-4">
                                <Info className="size-4 shrink-0 text-status-info" />
                                <div>
                                    <strong className="text-subtle">
                                        Scheduled end is not a return
                                    </strong>
                                    <p className="text-caption mt-1">
                                        Custody continues until an actual
                                        receipt or owned exception is recorded.
                                    </p>
                                </div>
                            </div>
                        </Panel>
                        <Panel
                            title="Exceptions & next action"
                            icon={CircleAlert}
                        >
                            <ActionRow
                                title={
                                    pending
                                        ? 'Kit receipt needs confirmation'
                                        : 'Review the recorded kit'
                                }
                                description={
                                    current
                                        ? `${current.received_kit.length} of ${current.kit.length} items confirmed for the latest movement.`
                                        : 'Check kit contents before the next transfer.'
                                }
                                action={
                                    <TextAction onClick={onKit}>Kit</TextAction>
                                }
                            />
                            <ActionRow
                                title="Wrong location, loss or damage"
                                description="Record the discrepancy and preserve the outgoing custodian."
                                action={
                                    canManage && (
                                        <TextAction
                                            onClick={() =>
                                                onAction('exception')
                                            }
                                        >
                                            Record exception
                                        </TextAction>
                                    )
                                }
                            />
                            {loan && (
                                <ActionRow
                                    title="Outstanding loan"
                                    description={`Expected return ${formatDate(loan.return_due_on)}. A due date does not record a return.`}
                                    action={
                                        canManage && (
                                            <TextAction
                                                onClick={() =>
                                                    onAction('return', loan)
                                                }
                                            >
                                                Review return
                                            </TextAction>
                                        )
                                    }
                                />
                            )}
                            {pending?.state === 'pending_receipt' &&
                                pending.can_receive &&
                                canManage && (
                                    <ActionRow
                                        title="Dispatch details changed"
                                        description="Cancel the pending dispatch before recording a different destination."
                                        action={
                                            <TextAction
                                                onClick={() =>
                                                    onAction(
                                                        'cancel_movement',
                                                        pending,
                                                    )
                                                }
                                            >
                                                Cancel dispatch
                                            </TextAction>
                                        }
                                    />
                                )}
                            {!pending && !loan && (
                                <p className="text-subtle text-muted-foreground">
                                    No unresolved receipt or outstanding loan is
                                    recorded.
                                </p>
                            )}
                        </Panel>
                    </div>
                </>
            ) : (
                <>
                    <Panel title="Transfers, loans & receipts" icon={History}>
                        {data.movements.length ? (
                            data.movements.map((move) => (
                                <article
                                    key={move.id}
                                    className="space-y-2 border-b pb-4 last:border-0 last:pb-0"
                                >
                                    <div className="flex flex-wrap justify-between gap-3">
                                        <strong className="text-subtle">
                                            {human(move.kind)} · {move.origin} →{' '}
                                            {move.destination}
                                        </strong>
                                        <State value={move.state} />
                                    </div>
                                    <p className="text-subtle text-muted-foreground">
                                        Movement #{move.id} · Dispatched{' '}
                                        {formatDateTime(move.dispatched_at)} ·
                                        Intended recipient:{' '}
                                        {move.recipient || 'Not recorded'}
                                    </p>
                                    {move.received_at && (
                                        <p className="text-subtle">
                                            Receipt{' '}
                                            {formatDateTime(move.received_at)} ·{' '}
                                            {move.received_kit.length}/
                                            {move.kit.length} kit items
                                            confirmed · {move.received_by}
                                        </p>
                                    )}
                                    {move.return_due_on && (
                                        <p className="text-subtle">
                                            Return expected{' '}
                                            {formatDate(move.return_due_on)}
                                            {move.returned_at
                                                ? ` · Returned ${formatDateTime(move.returned_at)}`
                                                : ''}
                                        </p>
                                    )}
                                    {move.receipt_note && (
                                        <p className="text-subtle">
                                            {move.receipt_note}
                                        </p>
                                    )}
                                    {canManage &&
                                        move.can_receive &&
                                        [
                                            'pending_receipt',
                                            'incomplete',
                                            'disputed',
                                        ].includes(move.state) && (
                                            <Button
                                                onClick={() =>
                                                    onAction('receive', move)
                                                }
                                            >
                                                Review receipt
                                            </Button>
                                        )}
                                </article>
                            ))
                        ) : (
                            <Empty>No transfers or loans recorded.</Empty>
                        )}
                    </Panel>
                    <Panel title="Assignment history" icon={UserRound}>
                        {asset.assignments.length ? (
                            asset.assignments.map((item) => (
                                <p
                                    key={item.id}
                                    className="text-subtle border-b pb-3 last:border-0"
                                >
                                    {item.assignee?.name || 'Recorded holder'} ·{' '}
                                    {formatDateTime(item.assigned_at)} →{' '}
                                    {item.returned_at
                                        ? formatDateTime(item.returned_at)
                                        : 'Current'}
                                    {item.purpose ? ` · ${item.purpose}` : ''}
                                </p>
                            ))
                        ) : (
                            <Empty>No assignment history.</Empty>
                        )}
                    </Panel>
                </>
            )}
        </div>
    );
}
