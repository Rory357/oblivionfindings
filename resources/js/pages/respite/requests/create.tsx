import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    serviceContexts: Array<{ id: number; name: string }>;
    defaultClientId?: number | string | null;
};

export default function RespiteRequestCreate({
    clients,
    serviceContexts,
    defaultClientId,
}: Props) {
    const form = useForm({
        client_id: defaultClientId ? String(defaultClientId) : '',
        service_context_id: '',
        requested_start: '',
        requested_end: '',
        funding_reference: '',
        preference_notes: '',
    });

    const { data, setData, post, processing, errors } = form;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                {
                    title: 'New Booking Request',
                    href: '/respite/requests/create',
                },
            ]}
        >
            <Head title="New Respite Booking Request" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/requests"
                        title="New Booking Request"
                        description="Requests are reviewed and approved before a confirmed booking is created."
                    />
                }
            >
                <RespiteSubnav />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/respite/requests');
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Request Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Client *</Label>
                                    <Select
                                        value={data.client_id}
                                        onValueChange={(v) =>
                                            setData('client_id', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select client" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && (
                                        <div className="mt-1 text-xs text-status-critical">
                                            {errors.client_id}
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <Label>Service Context</Label>
                                    <Select
                                        value={data.service_context_id}
                                        onValueChange={(v) =>
                                            setData('service_context_id', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select context" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {serviceContexts.map((s) => (
                                                <SelectItem
                                                    key={s.id}
                                                    value={String(s.id)}
                                                >
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Requested Start *</Label>
                                    <Input
                                        type="datetime-local"
                                        value={data.requested_start}
                                        onChange={(e) =>
                                            setData(
                                                'requested_start',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.requested_start && (
                                        <div className="mt-1 text-xs text-status-critical">
                                            {errors.requested_start}
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <Label>Requested End *</Label>
                                    <Input
                                        type="datetime-local"
                                        value={data.requested_end}
                                        onChange={(e) =>
                                            setData(
                                                'requested_end',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {errors.requested_end && (
                                        <div className="mt-1 text-xs text-status-critical">
                                            {errors.requested_end}
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div>
                                <Label>Funding Reference</Label>
                                <Input
                                    value={data.funding_reference}
                                    onChange={(e) =>
                                        setData(
                                            'funding_reference',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Funder + reference number"
                                />
                            </div>

                            <div>
                                <Label>Preference Notes</Label>
                                <Textarea
                                    value={data.preference_notes}
                                    onChange={(e) =>
                                        setData(
                                            'preference_notes',
                                            e.target.value,
                                        )
                                    }
                                    rows={4}
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.history.back()}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Submitting...' : 'Submit Request'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
