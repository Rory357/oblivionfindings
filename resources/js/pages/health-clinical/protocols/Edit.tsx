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

type Protocol = {
    id: number;
    client_id: number;
    name: string;
    observation_type: string;
    observation_type_label: string;
    frequency: string;
    frequency_label: string;
    custom_frequency_hours: number | null;
    instructions: string | null;
    alert_if_missed_hours: number;
    is_active: boolean;
    starts_at: string | null;
    ends_at: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    schedule_counts: {
        total: number;
        pending: number;
        overdue: number;
        completed_30d: number;
    };
};

type Props = {
    protocol: Protocol;
    can_edit_structure: boolean;
    form_options: {
        clients: ClientOption[];
        observation_types: SelectOption[];
        frequencies: SelectOption[];
    };
};

export default function EditProtocol({
    protocol,
    can_edit_structure,
    form_options,
}: Props) {
    return (
        <AppLayout>
            <Head title="Edit Protocol — Health & Clinical" />

            <div className="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
                <div className="flex items-center gap-4">
                    <Link href="/health-clinical/protocols">
                        <Button variant="ghost" size="icon">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Edit Protocol</h1>
                        <p className="text-sm text-muted-foreground">
                            Update frontline guidance and monitoring settings for {protocol.name}.
                        </p>
                    </div>
                </div>

                <ProtocolForm
                    mode="edit"
                    submitUrl={`/health-clinical/protocols/${protocol.id}`}
                    cancelUrl="/health-clinical/protocols"
                    clients={form_options.clients}
                    observationTypes={form_options.observation_types}
                    frequencies={form_options.frequencies}
                    protocol={protocol}
                    canEditStructure={can_edit_structure}
                />
            </div>
        </AppLayout>
    );
}
