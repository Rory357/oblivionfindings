import { Link, usePage } from '@inertiajs/react';

export default function RespiteSubnav() {
    const { auth } = usePage().props as any;
    const can = auth?.can?.respite ?? {};

    const items = [
        { href: '/respite', label: 'Referrals', show: true },
        { href: '/respite/requests', label: 'Booking Requests', show: true },
        {
            href: '/respite/bookings',
            label: 'Approved Bookings',
            show: !!can.bookingsManage,
        },
        {
            href: '/respite/resources',
            label: 'Resources',
            show: !!can.resourcesManage,
        },
        {
            href: '/respite/procedures',
            label: 'Procedures',
            show: !!can.proceduresManage,
        },
        {
            href: '/respite/procedure-runs',
            label: 'Procedure Runs',
            show: true,
        },
        { href: '/respite/tasks', label: 'Tasks', show: true },
        { href: '/respite/daily-notes', label: 'Daily Notes', show: true },
        {
            href: '/respite/handover-notes',
            label: 'Handover Notes',
            show: true,
        },
        { href: '/respite/communication-logs', label: 'Comms Log', show: true },
        {
            href: '/respite/evidence-packs',
            label: 'Evidence Packs',
            show: true,
        },
        {
            href: '/respite/risk-plan-activations',
            label: 'Risk Plans',
            show: true,
        },
        {
            href: '/respite/calendar',
            label: 'Calendar',
            show: !!can.calendarView,
        },
    ];

    return (
        <div className="flex flex-wrap gap-2">
            {items
                .filter((i) => i.show)
                .map((i) => (
                    <Link
                        key={i.href}
                        href={i.href}
                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                    >
                        {i.label}
                    </Link>
                ))}
        </div>
    );
}
