import { SafeguardingRaiseWizard } from '@/components/safeguarding/raise-wizard';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

type Choice = { id: number; name: string };

/**
 * /safeguarding/create — raise a concern as a full page (approved drawings
 * pg-hsn-23 … 23e). Reporting doesn't need register access, so the register
 * crumb shows only to people who can open it; Close returns to the permitted
 * caller the server resolved.
 */
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
    const page = usePage<SharedData>();
    const canSeeRegister = !!(
        page.props.auth?.can as { safeguarding?: { viewAny?: boolean } } | undefined
    )?.safeguarding?.viewAny;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        ...(canSeeRegister
            ? [
                  { title: 'Health and safety', href: '/health-safety' },
                  { title: 'Safeguarding', href: '/safeguarding' },
              ]
            : []),
        { title: 'Raise a concern', href: '/safeguarding/create' },
    ];
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
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
