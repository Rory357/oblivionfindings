import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime, formatTime } from '@/lib/datetime';
import { ClipboardCheck } from 'lucide-react';
import type { MouseEvent } from 'react';
import type { Capabilities, Pager, StockCount } from './_types';

const when = (record: StockCount) => ({
    icon: ClipboardCheck,
    name: formatDateOnly(record.counted_at),
    subline: formatTime(record.counted_at),
});
const what = (record: StockCount) => (
    <span className="font-semibold">
        {record.medication_name} · {record.client_name}
    </span>
);

/**
 * Approved P06 Counts (1440-14): differences waiting for the house lead's
 * sign-off first, then the recent counts and how each ended. The server keeps
 * the differences first across pages.
 */
export function CountsView({
    pager,
    can,
    open,
}: {
    pager: Pager<StockCount>;
    can: Capabilities;
    open: (record: StockCount) => void;
}) {
    const paged = pager.last_page > 1;
    const waiting = pager.data.filter(
        (record) => record.state === 'needs_review',
    );
    const done = pager.data.filter((record) => record.state !== 'needs_review');
    const stop = (run: () => void) => (event: MouseEvent) => {
        event.stopPropagation();
        run();
    };
    return (
        <div className="grid min-w-0 gap-5">
            {(!paged || waiting.length > 0) && (
                <section
                    className="grid min-w-0 gap-2.5"
                    aria-label="Differences to sign off"
                >
                    <ListCaption
                        title="Differences to sign off"
                        caption={`${waiting.length} shown · the house lead signs off every difference`}
                    />
                    {waiting.length ? (
                        <EntityTable<StockCount>
                            rows={waiting}
                            rowKey={(record) => record.id}
                            rowHeight="content"
                            minWidth={1000}
                            identityLabel="Counted"
                            identityWidth="150px"
                            identity={when}
                            columns={[
                                {
                                    key: 'difference',
                                    label: 'Difference',
                                    width: '1.8fr',
                                    cell: (record) => (
                                        <span>
                                            {what(record)} — counted{' '}
                                            {record.counted_total ?? '—'},
                                            expected{' '}
                                            {record.expected_total ?? '—'}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'reason',
                                    label: 'Reason given',
                                    width: '1.8fr',
                                    cell: (record) => record.reason ?? '—',
                                },
                                {
                                    key: 'by',
                                    label: 'Counted by',
                                    width: '0.8fr',
                                    cell: (record) =>
                                        record.counted_by_name ?? 'Unknown',
                                },
                                {
                                    key: 'next',
                                    label: '',
                                    width: '150px',
                                    align: 'right',
                                    cell: (record) =>
                                        can.manage ? (
                                            <Button
                                                size="sm"
                                                onClick={stop(() =>
                                                    open(record),
                                                )}
                                            >
                                                Sign off
                                            </Button>
                                        ) : (
                                            <span className="text-caption text-right">
                                                The house lead signs it off
                                            </span>
                                        ),
                                },
                            ]}
                            actionsFor={(record) => [
                                {
                                    label: can.manage
                                        ? 'Sign off the difference'
                                        : 'Open the count',
                                    icon: ClipboardCheck,
                                    onClick: () => open(record),
                                },
                            ]}
                            onOpen={open}
                        />
                    ) : (
                        <Card className="p-2">
                            <EmptyState
                                variant="compact"
                                icon={ClipboardCheck}
                                title="No differences waiting"
                                description="A count that matches is done straight away."
                            />
                        </Card>
                    )}
                </section>
            )}
            {(!paged || done.length > 0) && (
                <section
                    className="grid min-w-0 gap-2.5"
                    aria-label="Recent counts"
                >
                    <ListCaption
                        title="Recent counts"
                        caption={`${done.length} shown · kept`}
                    />
                    {done.length ? (
                        <EntityTable<StockCount>
                            rows={done}
                            rowKey={(record) => record.id}
                            rowHeight="content"
                            minWidth={1000}
                            identityLabel="Counted"
                            identityWidth="150px"
                            identity={when}
                            columns={[
                                {
                                    key: 'what',
                                    label: 'What',
                                    width: '1.6fr',
                                    cell: what,
                                },
                                {
                                    key: 'result',
                                    label: 'Result',
                                    width: '1.6fr',
                                    cell: (record) => (
                                        <span className="grid justify-items-start gap-1">
                                            <StatusBadge
                                                className="w-fit"
                                                variant="success"
                                            >
                                                {record.state === 'reviewed'
                                                    ? 'Signed off'
                                                    : 'All matched'}
                                            </StatusBadge>
                                            {record.state === 'reviewed' ? (
                                                <span className="text-caption">
                                                    {`${record.reviewed_by_name ?? 'The house lead'}${record.reviewed_at ? ` ${formatDateTime(record.reviewed_at)}` : ''}${record.review_reason ? ` — ${record.review_reason}` : ''}`}
                                                </span>
                                            ) : null}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'by',
                                    label: 'Counted by',
                                    width: '0.8fr',
                                    cell: (record) =>
                                        record.counted_by_name ?? 'Unknown',
                                },
                            ]}
                            actionsFor={(record) => [
                                {
                                    label: 'Open the count',
                                    icon: ClipboardCheck,
                                    onClick: () => open(record),
                                },
                            ]}
                            onOpen={open}
                        />
                    ) : (
                        <Card className="p-2">
                            <EmptyState
                                variant="compact"
                                icon={ClipboardCheck}
                                title="No counts yet"
                            />
                        </Card>
                    )}
                </section>
            )}
            <LaravelPagination links={pager.links} lastPage={pager.last_page} />
        </div>
    );
}
