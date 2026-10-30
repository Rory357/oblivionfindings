import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityTable,
    PersonDisc,
    type MenuItem,
} from '@/components/lists';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Activity,
    BatteryLow,
    Bell,
    MapPin,
    Radio,
    ShieldCheck,
} from 'lucide-react';
import type { MouseEvent } from 'react';
import {
    clockTime,
    labels,
    time,
    type Person,
    type ResponseAlert,
} from './model';
function PositionState({ person }: { person: Person }) {
    return (
        <StatusBadge
            size="sm"
            variant={
                person.positionState === 'stale'
                    ? 'warning'
                    : person.positionState === 'recent'
                      ? 'info'
                      : 'neutral'
            }
        >
            <MapPin className="size-3" />
            {labels[person.positionState]}
        </StatusBadge>
    );
}
export function PeopleView({
    rows,
    alerts,
    canReadAlerts,
    view,
    actions,
    onOpen,
    onContext,
}: {
    rows: Person[];
    alerts: ResponseAlert[];
    canReadAlerts: boolean;
    view: 'cards' | 'list';
    actions: (p: Person) => MenuItem[];
    onOpen: (p: Person) => void;
    onContext: (event: MouseEvent, person: Person) => void;
}) {
    const responses = (p: Person) =>
        canReadAlerts ? alerts.filter((a) => a.personId === p.id) : [];
    const responseLabel = (p: Person) => {
        const count = responses(p).length;
        return !canReadAlerts
            ? 'Response access unavailable'
            : count
              ? `${count} open source ${count === 1 ? 'response' : 'responses'}`
              : 'No open source responses';
    };
    const kind = (p: Person) => (p.kind === 'client' ? 'Client' : 'Staff');
    return (
        <div className="pl-ins-stack">
            {view === 'cards' ? (
                <EntityCardGrid>
                    {rows.map((p) => {
                        const active = responses(p),
                            tone = active.some((a) =>
                                ['critical', 'high'].includes(a.severity),
                            )
                                ? 'critical'
                                : active.length
                                  ? 'warning'
                                  : 'neutral';
                        return (
                            <EntityCard
                                key={p.id}
                                meridian={tone}
                                className={
                                    tone === 'neutral'
                                        ? 'pl-neutral-card'
                                        : undefined
                                }
                                mark={<PersonDisc name={p.name} size={40} />}
                                name={p.name}
                                subline={`${kind(p)} · ${p.site.name}`}
                                actions={actions(p)}
                                onContextMenu={(e) => onContext(e, p)}
                                onOpen={() => onOpen(p)}
                                openLabel="Location"
                                chips={
                                    <>
                                        <PositionState person={p} />
                                        <EntityChip icon={ShieldCheck}>
                                            {p.authority}
                                        </EntityChip>
                                        {p.battery != null && (
                                            <EntityChip icon={Radio}>
                                                {labels[p.power] ??
                                                    'Power not supplied'}
                                            </EntityChip>
                                        )}
                                    </>
                                }
                                metric={
                                    p.battery == null
                                        ? undefined
                                        : {
                                              label: `Battery · ${p.batteryAt ? clockTime(Date.parse(p.batteryAt)) : 'time not supplied'}`,
                                              value: `${p.battery}%`,
                                              percent: p.battery,
                                              tone:
                                                  p.battery <= 20
                                                      ? 'warning'
                                                      : 'brand',
                                          }
                                }
                                alerts={
                                    <>
                                        <StatusBadge size="sm" variant={tone}>
                                            <Bell className="size-3" />
                                            {responseLabel(p)}
                                        </StatusBadge>
                                        {p.battery == null && (
                                            <EntityChip icon={BatteryLow}>
                                                Battery not supplied
                                            </EntityChip>
                                        )}
                                        <EntityChip icon={Activity}>
                                            {p.motion === 'unknown'
                                                ? 'Movement not supplied'
                                                : (labels[p.motion] ??
                                                  p.motion)}
                                        </EntityChip>
                                    </>
                                }
                                footer={{
                                    personIcon: Radio,
                                    primary:
                                        p.sources.length > 1
                                            ? 'Choose a location source'
                                            : (p.sources[0]?.label ??
                                              'No source supplied'),
                                    secondary: p.position
                                        ? `Position ${time(p.position.timestamp)}`
                                        : `${p.reference} · no position supplied`,
                                }}
                            />
                        );
                    })}
                </EntityCardGrid>
            ) : (
                <EntityTable
                    rows={rows}
                    rowKey={(p) => p.id}
                    identityLabel="Person"
                    identityWidth="1.45fr"
                    identity={(p) => ({
                        name: p.name,
                        mark: <PersonDisc name={p.name} size={30} />,
                        subline: `${p.reference} · ${kind(p)}`,
                    })}
                    columns={[
                        {
                            key: 'site',
                            label: 'Site / source',
                            width: '1.1fr',
                            cell: (p) => (
                                <div className="pl-cell">
                                    <span>{p.site.name}</span>
                                    <small className="text-caption">
                                        {p.sources
                                            .map((s) => s.reference)
                                            .join(' · ') ||
                                            'No source supplied'}
                                    </small>
                                </div>
                            ),
                        },
                        {
                            key: 'position',
                            label: 'Recorded position',
                            width: '1.1fr',
                            cell: (p) => (
                                <div className="pl-cell">
                                    <PositionState person={p} />
                                    <small className="text-caption">
                                        {p.position
                                            ? time(p.position.timestamp)
                                            : 'No permitted coordinates'}
                                    </small>
                                </div>
                            ),
                        },
                        {
                            key: 'battery',
                            label: 'Battery / power',
                            width: '1fr',
                            cell: (p) => (
                                <div className="pl-cell">
                                    <span>
                                        {p.battery == null
                                            ? 'Not supplied'
                                            : `${p.battery}% · ${p.batteryAt ? clockTime(Date.parse(p.batteryAt)) : 'time not supplied'}`}
                                    </span>
                                    <small className="text-caption">
                                        {labels[p.power] ??
                                            'Power not supplied'}
                                    </small>
                                </div>
                            ),
                        },
                        {
                            key: 'response',
                            label: 'Source responses',
                            width: '1fr',
                            cell: (p) => (
                                <div className="pl-cell">
                                    <span>{responseLabel(p)}</span>
                                    <small className="text-caption">
                                        {p.motion === 'unknown'
                                            ? 'Movement not supplied'
                                            : (labels[p.motion] ?? p.motion)}
                                        {p.motionAt
                                            ? ` · ${clockTime(Date.parse(p.motionAt))}`
                                            : ''}
                                    </small>
                                </div>
                            ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={onOpen}
                    onRowContextMenu={onContext}
                    minWidth={1030}
                />
            )}
            <p className="text-caption">
                Source observations only. Recent contact does not refresh an
                older position; no open response is not a wellbeing assessment.
            </p>
        </div>
    );
}
