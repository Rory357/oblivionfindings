/** Access › Schedules (drawing pg-sdr-19d): who can get in when — the door groups in UniFi Access, read only. */
import { EntityTable, type MenuItem } from '@/components/lists';
import { QuietLine } from '@/components/signature/blocks';
import { Users } from 'lucide-react';
import type { MouseEvent } from 'react';
import { BlockHead } from '../devices/_parts';
import type { ScheduleRow } from './_types';

export function SchedulesView({
    rows,
    filtered,
    actionsFor,
    onContextMenu,
}: {
    rows: ScheduleRow[];
    filtered: boolean;
    actionsFor: (row: ScheduleRow) => MenuItem[];
    onContextMenu: (event: MouseEvent, row: ScheduleRow) => void;
}) {
    return (
        <section className="flex flex-col gap-3">
            <BlockHead title="Who can get in when" sub="Door groups in UniFi Access · one person can be in more than one" />
            {rows.length === 0 ? (
                <QuietLine text={filtered ? 'No door groups match. Try another search or Site.' : 'No door groups in UniFi Access at these Sites yet.'} />
            ) : (
                <EntityTable<ScheduleRow>
                    rows={rows}
                    rowKey={(row) => row.id}
                    identityLabel="Group"
                    identity={(row) => ({ icon: Users, name: row.name, subline: row.sub, linkLabel: `Actions for ${row.name}` })}
                    columns={[
                        { key: 'doors', label: 'Doors', width: '1.8fr', cell: (row) => <span className="py-1">{row.doors}</span> },
                        { key: 'when', label: 'When', width: '1.2fr', cell: (row) => row.when },
                    ]}
                    actionsFor={actionsFor}
                    onRowContextMenu={onContextMenu}
                    rowHeight="content"
                    minWidth={760}
                />
            )}
        </section>
    );
}
