import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, Pill } from 'lucide-react';
import type { MyDayMedDue } from '../lib/types';

const recorded = (row: MyDayMedDue) =>
    ['given', 'refused', 'withheld', 'missed'].includes(row.status);
const staffDose = (row: MyDayMedDue) =>
    !['away', 'pending_check', 'self_managed'].includes(
        row.state ?? row.status,
    );

/** Top of My Day's side column. Work is recorded in its canonical medicines source. */
export function MedicinesCard({
    rows,
    today,
    hidden = 0,
    hiddenLate = 0,
    unavailable = false,
}: {
    rows: MyDayMedDue[];
    today: string;
    hidden?: number;
    hiddenLate?: number;
    unavailable?: boolean;
}) {
    const url = `/meds/today?date=${encodeURIComponent(today)}`;
    const ctx = useEntityContextMenu<{ at: string; rows: MyDayMedDue[] }>();
    const staff = rows.filter(staffDose);
    const open = staff.filter((row) => !recorded(row));
    const due = open.filter((row) =>
        row.state ? row.state === 'due' : row.status === 'due',
    ).length;
    const late = open.filter((row) => row.status === 'overdue').length;
    const waiting = rows.filter(
        (row) => row.status === 'pending_check' && !recorded(row),
    ).length;
    const groups = Array.from(
        open.reduce((map, row) => {
            const group = map.get(row.scheduled_for) ?? [];
            map.set(row.scheduled_for, [...group, row]);
            return map;
        }, new Map<string, MyDayMedDue[]>()),
    )
        .sort(([a], [b]) => Date.parse(a) - Date.parse(b))
        .slice(0, 3)
        .map(([at, doses]) => ({ at, rows: doses }));
    const actions: MenuItem[] = [
        {
            label: 'Open Meds today',
            icon: ArrowRight,
            onClick: () => router.visit(url),
        },
    ];

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle className="text-section-title flex items-center gap-2">
                        <Pill className="size-4 text-primary" />
                        Medicines
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    {unavailable ? (
                        <p className="text-sm text-status-warning">
                            Medicines couldn’t be loaded. Open Meds today to
                            check the current work.
                        </p>
                    ) : (
                        <>
                            <div className="flex flex-wrap gap-2">
                                <StatusBadge
                                    variant={
                                        late + hiddenLate
                                            ? 'warning'
                                            : 'neutral'
                                    }
                                >
                                    {late + hiddenLate} late
                                </StatusBadge>
                                <StatusBadge variant={due ? 'info' : 'neutral'}>
                                    {due} shown due now
                                </StatusBadge>
                                {waiting > 0 && (
                                    <StatusBadge variant="warning">
                                        {waiting} waiting for an order check
                                    </StatusBadge>
                                )}
                            </div>
                            {groups.length ? (
                                <ul className="divide-y">
                                    {groups.map((group) => (
                                        <li
                                            key={group.at}
                                            className="flex min-w-0 items-center justify-between gap-2 py-3"
                                            onContextMenu={(event) =>
                                                ctx.open(event, group)
                                            }
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-semibold">
                                                    Medicines due{' '}
                                                    {formatTime(group.at)}
                                                </p>
                                                <p className="text-caption">
                                                    {group.rows.length} shown
                                                    staff dose
                                                    {group.rows.length === 1
                                                        ? ''
                                                        : 's'}
                                                </p>
                                            </div>
                                            <EntityKebab
                                                actions={actions}
                                                label={`Actions for medicines due ${formatTime(group.at)}`}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {waiting
                                        ? 'Order checks still need attention.'
                                        : hidden
                                          ? 'Some medicines work is not shown here.'
                                          : staff.length
                                            ? 'Every shown staff dose has an outcome.'
                                            : 'No staff doses are shown for this shift.'}
                                </p>
                            )}
                            {hidden > 0 && (
                                <p className="text-caption">
                                    {hidden} controlled-medicine dose
                                    {hidden === 1 ? ' is' : 's are'} not shown.
                                    Ask a colleague with controlled-medicine
                                    access to check them.
                                </p>
                            )}
                        </>
                    )}
                    <Button
                        className="frontline-tap w-full"
                        variant="outline"
                        asChild
                    >
                        <Link href={url}>
                            Open Meds today <ArrowRight className="size-4" />
                        </Link>
                    </Button>
                    <p className="text-caption">
                        For people whose medicines you may open. Recording
                        permission is checked again for each dose.
                    </p>
                </CardContent>
            </Card>
            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    title={`Medicines due ${formatTime(ctx.ctx.record.at)}`}
                    items={actions}
                    onClose={ctx.close}
                />
            )}
        </>
    );
}
