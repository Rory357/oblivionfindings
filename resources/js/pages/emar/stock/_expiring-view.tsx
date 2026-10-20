import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { CalendarClock, Eye } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { amount, monthYear } from './_stock-row';
import type { Capabilities, ExpiryPack } from './_types';

/** Approved P06 defaults (P11 Q5): warn within 30 days, "use first" within 7. */
const BANDS = [
    {
        key: 'expired',
        title: 'Expired — take out of use',
        caption: 'Not given, and not counted as stock',
        has: (days: number) => days < 0,
    },
    {
        key: 'seven',
        title: 'Within 7 days',
        caption: 'Use these packs first',
        has: (days: number) => days >= 0 && days <= 7,
    },
    {
        key: 'thirty',
        title: 'Within 30 days',
        caption: 'Doses come off these packs first',
        has: (days: number) => days > 7,
    },
];

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

/** "Expired 8 days ago", "Expires today", "In 7 days". */
export function expiryWhen(days: number): string {
    if (days < 0) return `Expired ${plural(-days, 'day')} ago`;
    return days === 0 ? 'Expires today' : `In ${plural(days, 'day')}`;
}

/** What happens to an expired pack, and who does it. */
export function RemoveStep({
    pack,
    can,
    remove,
}: {
    pack: ExpiryPack;
    can: Capabilities;
    remove: (pack: ExpiryPack) => void;
}): ReactNode {
    if (pack.controlled)
        return (
            <span className="text-caption text-right">
                Destroyed through the controlled register
            </span>
        );
    if (!can.manage)
        return (
            <span className="text-caption text-right">
                Set aside for the house lead
            </span>
        );
    return (
        <Button
            size="sm"
            variant="outline"
            onClick={(event: MouseEvent) => {
                event.stopPropagation();
                remove(pack);
            }}
        >
            Remove
        </Button>
    );
}

/**
 * Approved P06 Expiring (1440-15): one row per pack, banded into expired, within
 * 7 days and within 30 days, soonest first.
 */
export function ExpiringView({
    packs,
    more,
    can,
    open,
    remove,
}: {
    packs: ExpiryPack[];
    more: boolean;
    can: Capabilities;
    open: (pack: ExpiryPack) => void;
    remove: (pack: ExpiryPack) => void;
}) {
    if (!packs.length)
        return (
            <Card className="p-2">
                <EmptyState
                    icon={CalendarClock}
                    title="Nothing expiring"
                    description="Packs expiring within 30 days appear here."
                />
            </Card>
        );
    return (
        <div className="grid min-w-0 gap-5">
            {BANDS.map((band) => {
                const rows = packs.filter((pack) => band.has(pack.days));
                return (
                    <section
                        key={band.key}
                        className="grid min-w-0 gap-2.5"
                        aria-label={band.title}
                    >
                        <ListCaption
                            title={band.title}
                            caption={`${rows.length} shown · ${band.caption}`}
                        />
                        {rows.length ? (
                            <EntityTable<ExpiryPack>
                                rows={rows}
                                rowKey={(pack) => pack.id}
                                rowHeight="content"
                                minWidth={1000}
                                identityLabel="Pack"
                                identityWidth="1fr"
                                identity={(pack) => ({
                                    icon: CalendarClock,
                                    name:
                                        pack.batch_number ?? 'No batch printed',
                                    subline: `Expires ${monthYear(pack.expiry_date)}`,
                                })}
                                columns={[
                                    {
                                        key: 'medicine',
                                        label: 'Medicine',
                                        width: '1.2fr',
                                        cell: (pack) => (
                                            <span className="font-semibold">
                                                {pack.medication_name}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'person',
                                        label: 'Person',
                                        width: '0.8fr',
                                        cell: (pack) => pack.client_name,
                                    },
                                    {
                                        key: 'quantity',
                                        label: 'In the pack',
                                        width: '0.6fr',
                                        cell: (pack) =>
                                            amount(pack.quantity, pack.unit),
                                    },
                                    {
                                        key: 'when',
                                        label: 'When',
                                        width: '1.1fr',
                                        cell: (pack) => (
                                            <StatusBadge
                                                className="w-fit"
                                                variant={
                                                    pack.days < 0
                                                        ? 'critical'
                                                        : pack.days <= 7
                                                          ? 'warning'
                                                          : 'info'
                                                }
                                            >
                                                {expiryWhen(pack.days)}
                                            </StatusBadge>
                                        ),
                                    },
                                    {
                                        key: 'next',
                                        label: '',
                                        width: '160px',
                                        align: 'right',
                                        cell: (pack) =>
                                            pack.days < 0 ? (
                                                <RemoveStep
                                                    pack={pack}
                                                    can={can}
                                                    remove={remove}
                                                />
                                            ) : null,
                                    },
                                ]}
                                actionsFor={(pack) => [
                                    {
                                        label: 'Open the stock item',
                                        icon: Eye,
                                        onClick: () => open(pack),
                                    },
                                ]}
                                onOpen={open}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState
                                    variant="compact"
                                    icon={CalendarClock}
                                    title="None"
                                />
                            </Card>
                        )}
                    </section>
                );
            })}
            {more && (
                <p className="text-caption">
                    Showing the 200 packs expiring soonest. Search to find
                    another.
                </p>
            )}
        </div>
    );
}
