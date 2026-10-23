import { SafeguardingRaiseWizard } from '@/components/safeguarding/raise-wizard';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

type Choice = { id: number; name: string };

export default function SafeguardingCreate({
    clients,
    staff,
    sites,
    returnUrl,
}: {
    clients: Choice[];
    staff: Choice[];
    sites: Choice[];
    returnUrl: string;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: 'Raise a safeguarding concern',
                    href: '/safeguarding/create',
                },
            ]}
        >
            <Head title="Raise a safeguarding concern" />
            <SafeguardingRaiseWizard
                open
                presentation="page"
                clients={clients}
                staff={staff}
                sites={sites}
                onClose={() => router.visit(returnUrl)}
                onOpenConcern={(id) =>
                    router.visit(
                        `/safeguarding/${id}?${new URLSearchParams({ return_to: returnUrl })}`,
                    )
                }
            />
        </AppLayout>
    );
}
