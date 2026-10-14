import type { ReportsProps } from './reports';
export function reportFixture(): ReportsProps {
    return {
        actor_id: 7,
        as_of: '2026-10-13T00:00:00Z',
        stats: { devices: 2010, events_90d: 230, maintenance: 42 },
        windowDays: 90,
    };
}
