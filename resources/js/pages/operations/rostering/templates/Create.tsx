import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import TemplateForm from './TemplateForm';

type Client = {
    id: number;
    first_name: string;
    last_name: string;
    service_context_id?: number | null;
};

type Staff = {
    id: number;
    name: string;
    email?: string | null;
};

type ServiceContext = {
    id: number;
    name: string;
    type?: string | null;
    is_active?: boolean;
};

type Props = {
    clients: Client[];
    staff: Staff[];
    serviceContexts: ServiceContext[];
};

export default function CreateTemplate({
    clients,
    staff,
    serviceContexts,
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'Roster templates',
                    href: '/operations/rostering/templates',
                },
                {
                    title: 'Create',
                    href: '/operations/rostering/templates/create',
                },
            ]}
        >
            <Head title="Create roster template" />
            <PageShell>
                <PageHeader
                    title="Create roster template"
                    description="Build a repeatable roster pattern with the same operational fields used by live shifts."
                    backHref="/operations/rostering/templates"
                />

                <TemplateForm
                    mode="create"
                    submitUrl="/operations/rostering/templates"
                    clients={clients}
                    staff={staff}
                    serviceContexts={serviceContexts}
                />
            </PageShell>
        </AppLayout>
    );
}
