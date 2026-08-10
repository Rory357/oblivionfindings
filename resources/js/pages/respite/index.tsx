import type {
    RespiteCan,
    RespiteWorkspaceData,
} from '@/components/respite/types';
import { RespiteWorkspace } from '@/components/respite/workspace';
import AppLayout from '@/layouts/app-layout';
import { Head, usePage } from '@inertiajs/react';

/**
 * The single Respite workspace page — Referrals / Booking Requests / Approved
 * Bookings / Calendar / Stays are tabs inside <RespiteWorkspace>, not pages.
 */
export default function RespiteIndex(data: RespiteWorkspaceData) {
    const page = usePage<{ auth?: { can?: { respite?: RespiteCan } } }>();
    const can = page.props.auth?.can?.respite ?? {};

    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }]}>
            <Head title="Respite" />
            <RespiteWorkspace data={data} can={can} />
        </AppLayout>
    );
}
