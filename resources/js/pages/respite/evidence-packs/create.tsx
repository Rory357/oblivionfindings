import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    stays: any[];
    stayId?: string;
};

export default function EvidencePackCreate({ stays, stayId }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        stay_id: stayId || '',
        title: '',
        description: '',
    });

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Evidence Packs', href: '/respite/evidence-packs' },
            { title: 'New Pack', href: '/respite/evidence-packs/create' },
        ]}>
            <Head title="New Evidence Pack" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">New Evidence Pack</h1>
                    <div className="mt-1 text-sm text-muted-foreground">
                        Create an evidence collection for a respite stay.
                    </div>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/respite/evidence-packs');
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Pack Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Stay</Label>
                                <Select value={data.stay_id} onValueChange={(v) => setData('stay_id', v)}>
                                    <SelectTrigger><SelectValue placeholder="Select a stay" /></SelectTrigger>
                                    <SelectContent>
                                        {stays.map((s: any) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.client?.first_name} {s.client?.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.stay_id && <div className="mt-1 text-xs text-status-critical">{errors.stay_id}</div>}
                            </div>
                            <div>
                                <Label>Title</Label>
                                <Input
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="Evidence pack title"
                                />
                                {errors.title && <div className="mt-1 text-xs text-status-critical">{errors.title}</div>}
                            </div>
                            <div>
                                <Label>Description</Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows={4}
                                />
                                {errors.description && <div className="mt-1 text-xs text-status-critical">{errors.description}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            Create Evidence Pack
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
