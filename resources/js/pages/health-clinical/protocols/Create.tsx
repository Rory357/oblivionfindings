import ProtocolForm from '@/components/clinical/protocol-form';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Stethoscope } from 'lucide-react';

type ClientOption = {
    id: number;
    first_name: string;
    last_name: string;
};

type SelectOption = {
    value: string;
    label: string;
};

type Props = {
    form_options: {
        clients: ClientOption[];
        observation_types: SelectOption[];
        frequencies: SelectOption[];
    };
};

export default function CreateProtocol({ form_options }: Props) {
    return (
        <AppLayout>
            <Head title="Create Protocol — Health & Clinical" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/health-clinical/protocols"
                        icon={Stethoscope}
                        title="Create Protocol"
                        description="Add a new clinical observation protocol for a client."
                    />
                }
            >
                <ProtocolForm
                    mode="create"
                    submitUrl="/health-clinical/protocols"
                    cancelUrl="/health-clinical/protocols"
                    clients={form_options.clients}
                    observationTypes={form_options.observation_types}
                    frequencies={form_options.frequencies}
                />
            </PageLayout>
        </AppLayout>
    );
}
