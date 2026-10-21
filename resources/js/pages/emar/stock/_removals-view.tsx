import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { formatDateOnly, formatTime } from '@/lib/datetime';
import { Eye, PackageMinus } from 'lucide-react';
import { amount, monthYear } from './_stock-row';
import type { Capabilities, ExpiryPack, Movement, Pager } from './_types';

/**
 * Approved P06 Removals (1440-16): expired packs still in the cabinet, then
 * everything removed or returned, with its reason, kept.
 */
export function RemovalsView({
    packs,
    moves,
    can,
    label,
    openMedicine,
    remove,
}: {
    packs: ExpiryPack[];
    moves: Pager<Movement>;
    can: Capabilities;
    label: (move: Movement) => string;
    openMedicine: (medicationId: number) => void;
    remove: (pack: ExpiryPack) => void;
}) {
    return (
        <div className="grid min-w-0 gap-5">
            <section className="grid min-w-0 gap-2.5" aria-label="To remove">
                <ListCaption
                    title="To remove"
                    caption={`${packs.length} shown · expired packs still in the cabinet`}
                />
                {packs.length ? (
                    <Card className="gap-0 p-0">
                        {packs.map((pack) => (
                            <div
                                key={pack.id}
                                className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 text-sm last:border-b-0"
                            >
                                <span>
                                    <span className="font-semibold">
                                        {pack.medication_name}
                                    </span>{' '}
                                    · {pack.client_name} — batch{' '}
                                    {pack.batch_number ?? 'not printed'},
                                    expired {monthYear(pack.expiry_date)},{' '}
                                    {amount(pack.quantity, pack.unit)}
                                </span>
                                {pack.controlled ? (
                                    <span className="text-caption">
                                        Destroyed through the controlled
                                        register
                                    </span>
                                ) : can.manage ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => remove(pack)}
                                    >
                                        Remove
                                    </Button>
                                ) : (
                                    <span className="text-caption">
                                        The house lead removes it
                                    </span>
                                )}
                            </div>
                        ))}
                    </Card>
                ) : (
                    <Card className="p-2">
                        <EmptyState
                            variant="compact"
                            icon={PackageMinus}
                            title="Nothing to remove"
                        />
                    </Card>
                )}
            </section>
            <section
                className="grid min-w-0 gap-2.5"
                aria-label="Removed and returned"
            >
                <ListCaption
                    title="Removed and returned"
                    caption={`${moves.data.length} shown · with the reason, kept`}
                />
                {moves.data.length ? (
                    <EntityTable<Movement>
                        rows={moves.data}
                        rowKey={(move) => move.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="When"
                        identityWidth="150px"
                        identity={(move) => ({
                            icon: PackageMinus,
                            name: formatDateOnly(move.recorded_at),
                            subline: formatTime(move.recorded_at),
                        })}
                        columns={[
                            {
                                key: 'medicine',
                                label: 'Medicine',
                                width: '1.1fr',
                                cell: (move) => (
                                    <span>
                                        <span className="font-semibold">
                                            {move.medication_name}
                                        </span>{' '}
                                        · {move.client_name}
                                    </span>
                                ),
                            },
                            {
                                key: 'reason',
                                label: 'Reason',
                                width: '2fr',
                                cell: (move) => (
                                    <span className="grid">
                                        <span className="font-semibold">
                                            {`${label(move)} · ${amount(move.quantity_display ?? move.quantity, move.unit ?? null)}`}
                                        </span>
                                        {move.reason ? (
                                            <span className="text-caption">
                                                {move.reason}
                                            </span>
                                        ) : null}
                                        {move.notes ? (
                                            <span className="text-caption">
                                                {move.notes}
                                            </span>
                                        ) : null}
                                    </span>
                                ),
                            },
                            {
                                key: 'by',
                                label: 'By',
                                width: '0.8fr',
                                cell: (move) =>
                                    move.recorded_by_name ?? 'Unknown',
                            },
                        ]}
                        actionsFor={(move) => [
                            {
                                label: 'Open the stock item',
                                icon: Eye,
                                onClick: () => openMedicine(move.medication_id),
                            },
                        ]}
                        onOpen={(move) => openMedicine(move.medication_id)}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState
                            variant="compact"
                            icon={PackageMinus}
                            title="Nothing removed yet"
                        />
                    </Card>
                )}
                <LaravelPagination
                    links={moves.links}
                    lastPage={moves.last_page}
                />
            </section>
        </div>
    );
}
