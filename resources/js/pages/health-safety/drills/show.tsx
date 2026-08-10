/* Deep-link / share fallback for a single drill — a thin shell that renders the
 * same DrillDetailDialog as the over-the-list modal on the register. */
import { DrillCompleteDialog } from '@/components/health-safety/drill-complete-dialog';
import { DrillDetailDialog } from '@/components/health-safety/drill-detail-dialog';
import AppLayout from '@/layouts/app-layout';
import type { DrillDetail } from '@/pages/health-safety/drills/shared';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function DrillShow({ detail }: { detail: DrillDetail }) {
    const [completeOpen, setCompleteOpen] = useState(false);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Emergency Drills', href: '/health-safety/drills' },
                {
                    title: detail.reference,
                    href: `/health-safety/drills/${detail.id}`,
                },
            ]}
        >
            <Head title={`Drill ${detail.reference}`} />

            <DrillDetailDialog
                detail={detail}
                open
                onClose={() => router.visit('/health-safety/drills')}
                onLaunchComplete={() => setCompleteOpen(true)}
            />

            {completeOpen ? (
                <DrillCompleteDialog
                    open
                    onClose={() => setCompleteOpen(false)}
                    drill={{
                        id: detail.id,
                        reference: detail.reference,
                        type_label: detail.type_label,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
