/**
 * Access › People (drawing pg-sdr-19b): the "To match" next step, then everyone who can get in and
 * how, problems first. Residents show their fob and never when they came and went (D19).
 */
import { EntityTable, type MenuItem } from '@/components/lists';
import { NextStep } from '@/components/security-devices/device-blocks';
import { KnowList, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { House, Link as LinkIcon, UserRound, UserRoundSearch } from 'lucide-react';
import type { MouseEvent } from 'react';
import { BlockHead, ListPager } from '../devices/_parts';
import { Bold, lineText, StatusCell } from './_parts';
import type { PeopleData, PersonRow } from './_types';

export function PeopleView({
    data,
    canManage,
    filtered,
    actionsFor,
    onOpen,
    onContextMenu,
    onMatch,
    onPage,
    now,
}: {
    data: PeopleData;
    canManage: boolean;
    filtered: boolean;
    actionsFor: (row: PersonRow) => MenuItem[];
    onOpen: (row: PersonRow) => void;
    onContextMenu: (event: MouseEvent, row: PersonRow) => void;
    onMatch: () => void;
    onPage: (page: number) => void;
    now: Date;
}) {
    const toMatch = data.to_match;
    const sub = `Problems first · ${data.can_get_in === 1 ? '1 can get in' : `${data.can_get_in} can get in`}${data.turned_off > 0 ? `, plus ${data.turned_off} turned off` : ''}`;

    return (
        <div className="flex flex-col gap-5">
            {toMatch.count > 0 ? (
                <NextStep
                    tone="warning"
                    label="To match"
                    title={toMatch.count === 1 ? '1 person in UniFi isn’t linked to your staff yet' : `${toMatch.count} people in UniFi aren’t linked to your staff yet`}
                    sub={
                        <>
                            {toMatch.lines.map((line, index) => (
                                <span key={index}>
                                    <Bold text={line} />
                                    {index < toMatch.lines.length - 1 ? ', and ' : '. '}
                                </span>
                            ))}
                            Until they’re matched, their cards won’t turn off by themselves when they leave.
                        </>
                    }
                    actions={
                        canManage ? (
                            <Button type="button" size="sm" onClick={onMatch}>
                                <LinkIcon aria-hidden="true" />
                                Match people
                            </Button>
                        ) : undefined
                    }
                />
            ) : null}

            <section className="flex flex-col gap-3">
                <BlockHead title="People" sub={sub} />
                {data.rows.length === 0 ? (
                    <QuietLine text={filtered ? 'Nobody matches. Try another search, Site or status.' : 'Nobody is in UniFi Access at these Sites yet.'} />
                ) : (
                    <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <EntityTable<PersonRow>
                            className="rounded-none border-0 shadow-none"
                            rows={data.rows}
                            rowKey={(row) => row.id}
                            identityLabel="Person"
                            identity={(row) => ({
                                icon: row.kind === 'resident' ? House : row.kind === 'unmatched' ? UserRoundSearch : UserRound,
                                name: row.name,
                                subline: row.sub,
                                linkLabel: `Actions for ${row.name}`,
                            })}
                            columns={[
                                { key: 'gets_in', label: 'Gets in with', width: '1.1fr', cell: (row) => row.gets_in },
                                { key: 'doors', label: 'Doors', width: '1.3fr', cell: (row) => <span className="py-1">{row.doors}</span> },
                                { key: 'status', label: 'Status', width: '1.5fr', cell: (row) => <StatusCell badge={row.badge} note={row.note} now={now} /> },
                                { key: 'last_used', label: 'Last used', width: '1fr', cell: (row) => <span className="py-1">{lineText(row.last_used, now)}</span> },
                            ]}
                            actionsFor={actionsFor}
                            onOpen={onOpen}
                            onRowContextMenu={onContextMenu}
                            rowHeight="content"
                            minWidth={940}
                        />
                        <ListPager meta={data.meta} noun="people" onPage={onPage} />
                    </div>
                )}
            </section>

            <KnowList
                title="How cards work here"
                items={[
                    { tone: 'info', text: <>People are matched to staff by <b className="font-semibold">employee number</b>. Their cards and PINs turn off by themselves at the end of their last day, from HR</> },
                    { tone: 'warning', text: <><b className="font-semibold">Touch Passes cost money</b>, so Oblivion never buys them. Set one up in UniFi</> },
                    { tone: 'info', text: <>Residents’ fobs open their own home any time and are never used for clock-ins. Their comings and goings show as “Resident fob”</> },
                    { tone: 'none', text: 'Faces and fingerprints can’t be added from Oblivion: UniFi doesn’t offer that to other systems' },
                ]}
            />
        </div>
    );
}
