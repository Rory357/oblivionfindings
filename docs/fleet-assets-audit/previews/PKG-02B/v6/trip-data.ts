export type Journey = {
    id: string;
    day: string;
    start: string;
    end: string;
    minutes: number;
    distance: number;
    from: string;
    to: string;
    driver: string;
    source: string;
    odo: string;
    idle: number;
    braking: number;
    acceleration: number;
    coverage: number;
    max: number;
    overspeed?: {
        id: string;
        peak: number;
        threshold: number;
        tolerance: number;
        seconds: number;
        at: string;
        point: number;
        rule: string;
    };
    path: { lat: number; lng: number }[];
    speeds: number[];
    events: {
        point: number;
        at?: string;
        title: string;
        detail: string;
        kind: 'driving' | 'speed' | 'power' | 'journey';
    }[];
};
const house = { lat: -41.2838, lng: 174.7743 },
    pickup = { lat: -41.2865, lng: 174.7762 };
export const journeys: Journey[] = [
    {
        id: 'TRIP-DEMO-13',
        day: '2026-09-22',
        start: '9:00 am',
        end: '9:15 am',
        minutes: 15,
        distance: 6.4,
        from: 'Kōwhai House',
        to: 'Community centre',
        driver: 'Unassigned',
        source: 'GV500CG-DEMO-14 · buffered samples',
        odo: 'Dashboard readings not paired',
        idle: 2,
        braking: 1,
        acceleration: 0,
        coverage: 72,
        max: 48,
        path: [
            house,
            { lat: -41.2862, lng: 174.7735 },
            { lat: -41.292, lng: 174.775 },
            { lat: -41.3, lng: 174.777 },
            { lat: -41.304, lng: 174.778 },
        ],
        speeds: [0, 24, 48, 22, 0],
        events: [
            {
                point: 0,
                title: 'Ignition on',
                detail: 'Virtual ignition signal · journey start',
                kind: 'journey',
            },
            {
                point: 2,
                title: 'Missing report window',
                detail: 'Sparse recorded positions; route between points is unknown',
                kind: 'journey',
            },
            {
                point: 3,
                title: 'Harsh braking',
                at: '9:12 am',
                detail: 'Review circumstances · driver not assigned',
                kind: 'driving',
            },
            {
                point: 4,
                title: 'Ignition off',
                detail: 'Arrival inferred from recorded signal',
                kind: 'journey',
            },
        ],
    },
    {
        id: 'TRIP-DEMO-12',
        day: '2026-09-21',
        start: '8:00 am',
        end: '8:12 am',
        minutes: 12,
        distance: 2.4,
        from: 'Kōwhai House',
        to: 'Community pickup area',
        driver: 'Jamie Taylor',
        source: 'OBS-DEMO-03',
        odo: '82,458 → 82,460 km · manual records',
        idle: 4,
        braking: 1,
        acceleration: 1,
        coverage: 98,
        max: 68,
        overspeed: {
            id: 'SPEED-DEMO-12',
            peak: 68,
            threshold: 50,
            tolerance: 5,
            seconds: 42,
            at: '8:07 am',
            point: 2,
            rule: 'SPEED-DEMO-v1',
        },
        path: [
            house,
            { lat: -41.279, lng: 174.776 },
            { lat: -41.281, lng: 174.778 },
            { lat: -41.284, lng: 174.7775 },
            pickup,
        ],
        speeds: [0, 30, 68, 18, 0],
        events: [
            {
                point: 0,
                title: 'Ignition on',
                detail: 'Virtual ignition · departure',
                kind: 'journey',
            },
            {
                point: 1,
                title: 'Harsh braking',
                detail: '8:04 am · review sensor event in trip context',
                at: '8:04 am',
                kind: 'driving',
            },
            {
                point: 2,
                title: 'Harsh acceleration',
                at: '8:06 am',
                detail: '8:06 am · review sensor event in trip context',
                kind: 'driving',
            },
            {
                point: 2,
                title: 'Overspeed threshold',
                at: '8:07 am',
                detail: '68 km/h peak · 42 seconds above 55 km/h trigger · fleet threshold, road limit unverified',
                kind: 'speed',
            },
            {
                point: 4,
                title: 'Ignition off',
                detail: '8:12 am · arrived at pickup area',
                kind: 'journey',
            },
        ],
    },
    {
        id: 'TRIP-DEMO-11',
        day: '2026-09-20',
        start: '4:40 pm',
        end: '5:08 pm',
        minutes: 28,
        distance: 8.1,
        from: 'Community transport route',
        to: 'Kōwhai House',
        driver: 'Alex Morgan',
        source: 'OBS-DEMO-02',
        odo: '82,450 → 82,458 km · manual records',
        idle: 2,
        braking: 0,
        acceleration: 0,
        coverage: 97,
        max: 46,
        path: [
            { lat: -41.312, lng: 174.779 },
            { lat: -41.304, lng: 174.779 },
            { lat: -41.296, lng: 174.779 },
            { lat: -41.288, lng: 174.775 },
            house,
        ],
        speeds: [0, 32, 46, 20, 0],
        events: [
            {
                point: 0,
                title: 'Ignition on',
                detail: 'Virtual ignition · departure',
                kind: 'journey',
            },
            {
                point: 3,
                title: 'Low voltage signal',
                at: '5:02 pm',
                detail: 'Synthetic 11.6 V power observation · assess duration and configuration',
                kind: 'power',
            },
            {
                point: 4,
                title: 'Ignition off',
                detail: '5:08 pm · home site arrival',
                kind: 'journey',
            },
        ],
    },
];
export const tripScore = (j: Journey) =>
    j.coverage < 90
        ? null
        : Math.max(
              0,
              100 -
                  j.braking * 5 -
                  j.acceleration * 3 -
                  (j.overspeed ? 8 : 0) -
                  Math.round(j.idle * 0.5),
          );

export const drivingEventCount = (j: Journey) =>
    j.braking + j.acceleration + (j.overspeed ? 1 : 0);
export const speedEvidence = (j: Journey) =>
    j.overspeed
        ? `${j.overspeed.peak} km/h peak; ${j.overspeed.seconds} seconds above ${j.overspeed.threshold + j.overspeed.tolerance} km/h trigger (fleet threshold ${j.overspeed.threshold} + tolerance ${j.overspeed.tolerance}). Rule ${j.overspeed.rule}; minimum 15 seconds. Road speed limit unverified; one continuous episode, synthetic source evidence.`
        : '';
export const speedOrigin = (j: Journey) => ({
    id: j.overspeed!.id,
    source: j.id,
    observed: `${new Date(j.day + 'T12:00:00Z').toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Pacific/Auckland' })} · ${j.overspeed!.at}`,
    lat: j.path[j.overspeed!.point].lat,
    lng: j.path[j.overspeed!.point].lng,
    detail: speedEvidence(j),
});
