import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
};

export default function OnboardingCreate({ clients }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        client_id: '',
    });

    return (
        <AppLayout>
            <Head title="Create Onboarding Workflow" />
            <PageHeader
                title="Create Onboarding Workflow"
                description="Start a new onboarding workflow for a client who is entering service."
                backHref="/operations/onboarding"
            />
            <PageShell>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        post('/operations/onboarding');
                    }}
                >
                    <Card className="max-w-2xl">
                        <CardHeader>
                            <CardTitle className="text-base">Workflow Setup</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-2">
                                <Label>Client</Label>
                                <Select value={data.client_id} onValueChange={(value) => setData('client_id', value)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a client" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {clients.map((client) => (
                                            <SelectItem key={client.id} value={String(client.id)}>
                                                {client.first_name} {client.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.client_id && (
                                    <p className="text-xs text-destructive">{errors.client_id}</p>
                                )}
                            </div>

                            <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                                The workflow will be created with the default onboarding checklist so coordinators can update each step as progress is made.
                            </div>

                            <div className="flex items-center justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.get('/operations/onboarding')}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    Create Workflow
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </PageShell>
        </AppLayout>
    );
}
