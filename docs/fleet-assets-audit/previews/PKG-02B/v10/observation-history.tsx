import { Button } from '@/components/ui/button';
import { useState } from 'react';
import { Badge, Notice, Panel, Row } from './ui';
export const observations = [
    {
        id: 'latest',
        date: '21 Sep 2026 · 8:12 am',
        day: '21 Sep',
        lat: -41.2865,
        lng: 174.7762,
        label: 'Recorded vehicle observation',
        ref: 'OBS-DEMO-03',
        trip: 'TRIP-DEMO-12 · 8:00–8:12 am · 2.4 km',
    },
    {
        id: 'previous',
        date: '20 Sep 2026 · 5:08 pm',
        day: '20 Sep',
        lat: -41.2838,
        lng: 174.7743,
        label: 'Return to home site',
        ref: 'OBS-DEMO-02',
        trip: 'TRIP-DEMO-11 · 4:40–5:08 pm · 8.1 km',
    },
];
export function ObservationHistory({
    noTracker,
    selected,
    onSelect,
    onDetail,
}: {
    noTracker: boolean;
    selected: string;
    onSelect: (id: string) => void;
    onDetail: (title: string, rows: [string, string][]) => void;
}) {
    const [filter, setFilter] = useState('all');
    return (
        <div className="content-grid">
            <Panel
                title="Observation & trip history"
                sub="Permitted vehicle records only · no passenger history"
            >
                <div className="observation-filters">
                    <label htmlFor="observation-period">Recorded date</label>
                    <select
                        id="observation-period"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="all">20–21 Sep 2026</option>
                        <option value="21 Sep">21 Sep 2026</option>
                        <option value="20 Sep">20 Sep 2026</option>
                    </select>
                </div>
                {noTracker ? (
                    <Notice title="No tracker assigned">
                        No vehicle positions or trips are fabricated. Manual
                        mileage and maintenance remain available.
                    </Notice>
                ) : (
                    observations
                        .filter((o) => filter === 'all' || o.day === filter)
                        .map((o) => (
                            <div className="op-record" key={o.id}>
                                <Row
                                    title={o.label}
                                    sub={`${o.ref} · ${o.date}`}
                                    value={o.trip}
                                    badge={
                                        selected === o.id ? (
                                            <Badge tone="info">
                                                Shown on map
                                            </Badge>
                                        ) : undefined
                                    }
                                />
                                <div className="op-actions">
                                    <Button
                                        variant="outline"
                                        onClick={() => onSelect(o.id)}
                                    >
                                        Show {o.day} on map
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            onDetail('Trip record', [
                                                ['Reference', o.trip],
                                                ['Observation', o.ref],
                                                ['Vehicle', 'VH-014'],
                                                [
                                                    'Data source',
                                                    'TRACK-DEMO-14 · historical synthetic sample',
                                                ],
                                                [
                                                    'Privacy',
                                                    'Vehicle operational context only; no passenger details',
                                                ],
                                            ])
                                        }
                                    >
                                        Trip details
                                    </Button>
                                </div>
                            </div>
                        ))
                )}
            </Panel>
            <Panel title="Tracker & geofence context">
                <Row
                    title="Assignment"
                    value={
                        noTracker
                            ? 'None'
                            : 'TRACK-DEMO-14 · assigned to VH-014'
                    }
                />
                <Row
                    title="Health"
                    value={
                        noTracker
                            ? 'Not applicable'
                            : 'Historical sample · current health not verified'
                    }
                />
                <Row
                    title="Last recorded communication"
                    value={
                        noTracker ? 'Not available' : '21 Sep 2026 · 8:12 am'
                    }
                />
                <Row
                    title="Geofence event"
                    value={
                        noTracker
                            ? 'No events'
                            : '20 Sep · entered home-site boundary'
                    }
                    action={
                        noTracker
                            ? undefined
                            : () =>
                                  onDetail('Geofence event', [
                                      ['Reference', 'GEO-EVENT-DEMO-08'],
                                      [
                                          'Boundary',
                                          'Kōwhai House · GEO-DEMO-01',
                                      ],
                                      [
                                          'Event',
                                          'Entered · 20 Sep 2026 · 5:08 pm',
                                      ],
                                      ['Source', 'TRACK-DEMO-14'],
                                      [
                                          'Ownership',
                                          'Existing Fleet geofence configuration · permitted site only',
                                      ],
                                  ])
                    }
                />
                <p className="panel-footnote">
                    Tracker assignment and boundary changes remain centrally
                    managed. This profile shows source context and history.
                </p>
            </Panel>
        </div>
    );
}
