import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { formatDateOnly, formatTime } from '@/lib/datetime';
import { ArrowLeftRight, Eye } from 'lucide-react';
import { amount } from './_stock-row';
import type { Movement } from './_types';

/**
 * Approved P06 Stock view: "Recent movements follow, newest first, kept"
 * (README §2). The full movements list stays one link away (?view=movements).
 */
export function RecentMovements({
    moves,
    label,
    open,
    viewAll,
}: {
    moves: Movement[];
    label: (move: Movement) => string;
    open: (move: Movement) => void;
    viewAll: () => void;
}) {
    const shown = moves.slice(0, 8);
    if (!shown.length) return null;
    return (
        <section className="grid min-w-0 gap-2.5" aria-label="Recent movements">
            <ListCaption
                title="Recent movements"
                caption={`${shown.length} shown · newest first · kept, never deleted`}
                right={
                    <Button variant="link" size="sm" onClick={viewAll}>
                        View all movements
                    </Button>
                }
            />
            <EntityTable<Movement>
                rows={shown}
                rowKey={(move) => move.id}
                rowHeight="content"
                minWidth={860}
                identityLabel="When"
                identityWidth="150px"
                identity={(move) => ({
                    icon: ArrowLeftRight,
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
                        key: 'what',
                        label: 'What happened',
                        width: '2fr',
                        cell: (move) => (
                            <span className="grid">
                                <span className="font-semibold">
                                    {`${label(move)} · ${amount(move.change_display ?? move.quantity_display ?? move.quantity, move.unit ?? null)}`}
                                </span>
                                {move.reason ? (
                                    <span className="text-caption">
                                        {move.reason}
                                    </span>
                                ) : null}
                            </span>
                        ),
                    },
                    {
                        key: 'by',
                        label: 'By',
                        width: '0.8fr',
                        cell: (move) => move.recorded_by_name ?? 'Unknown',
                    },
                ]}
                actionsFor={(move) => [
                    {
                        label: 'Open the stock item',
                        icon: Eye,
                        onClick: () => open(move),
                    },
                ]}
                onOpen={open}
            />
        </section>
    );
}
