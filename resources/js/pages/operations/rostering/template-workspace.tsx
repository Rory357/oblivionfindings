import { TemplateLibrary } from '@/components/rostering/template-library';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
export default function TemplateWorkspace() {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Workforce', href: '/operations/rostering' },
                { title: 'Rostering', href: '/operations/rostering' },
                { title: 'Templates', href: '/operations/rostering/templates' },
            ]}
        >
            <Head title="Roster templates" />
            <TemplateLibrary standalone />
        </AppLayout>
    );
}
