import ProtocolForm from '@/components/clinical/protocol-form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

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

            <div className="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
                <div className="flex items-center gap-4">
                    <Link href="/health-clinical/protocols">
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Create Protocol</h1>
                        <p className="text-sm text-muted-foreground">
                            Add a new clinical observation protocol for a client.
                        </p>
                    </div>
                </div>

                <ProtocolForm
                    mode="create"
                    submitUrl="/health-clinical/protocols"
                    cancelUrl="/health-clinical/protocols"
                    clients={form_options.clients}
                    observationTypes={form_options.observation_types}
                    frequencies={form_options.frequencies}
                />
            </div>
        </AppLayout>
    );
}
