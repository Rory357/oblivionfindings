import PageShell from '@/components/page-shell';
import { PageHero } from '@/components/page';
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

type Template = {
    id: number;
    name: string;
    description?: string | null;
    template_type?: string | null;
    is_active?: boolean;
    template_shifts?: Array<Record<string, unknown>>;
};

type Props = {
    template: Template;
    clients: Client[];
    staff: Staff[];
    serviceContexts: ServiceContext[];
};

export default function EditTemplate({
    template,
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
                    title: template.name,
                    href: `/operations/rostering/templates/${template.id}`,
                },
                {
                    title: 'Edit',
                    href: `/operations/rostering/templates/${template.id}/edit`,
                },
            ]}
        >
            <Head title={`Edit ${template.name}`} />
            <PageShell>
                <PageHero variant="compact"
                    title={`Edit ${template.name}`}
                    description="Keep the roster template aligned with the live shift schema and supported-living workflow."
                    backHref={`/operations/rostering/templates/${template.id}`}
                />

                <TemplateForm
                    mode="edit"
                    submitUrl={`/operations/rostering/templates/${template.id}`}
                    template={template}
                    clients={clients}
                    staff={staff}
                    serviceContexts={serviceContexts}
                />
            </PageShell>
        </AppLayout>
    );
}
