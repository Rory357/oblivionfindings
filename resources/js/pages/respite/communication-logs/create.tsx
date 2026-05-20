import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHero, PageLayout } from '@/components/page';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/react';

type Props = {
    stays: Array<{ id: number; client: { first_name: string; last_name: string } }>;
    stayId?: string;
    channels: Record<string, string>;
};

export default function CommunicationLogCreate({ stays, stayId, channels }: Props) {
    const hasStays = stays.length > 0;
    const { data, setData, post, processing, errors } = useForm({
        stay_id: stayId || '',
        channel: '',
        occurred_at: '',
        participants: [{ name: '', role: '' }] as Array<{ name: string; role: string }>,
        summary: '',
    });

    const addParticipant = () => {
        setData('participants', [...data.participants, { name: '', role: '' }]);
    };

    const removeParticipant = (index: number) => {
        setData('participants', data.participants.filter((_, i) => i !== index));
    };

    const updateParticipant = (index: number, field: 'name' | 'role', value: string) => {
        const updated = [...data.participants];
        updated[index] = { ...updated[index], [field]: value };
        setData('participants', updated);
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Communication Logs', href: '/respite/communication-logs' },
            { title: 'New Log', href: '/respite/communication-logs/create' },
        ]}>
            <Head title="New Communication Log" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/communication-logs"
                        title="New Communication Log"
                        description="Record a communication related to a respite stay."
                    />
                }
            >
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post('/respite/communication-logs');
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Log Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {!hasStays && (
                                <div className="rounded-md border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-sm text-status-warning">
                                    No respite stays are available yet. Create or admit a stay before logging communication.
                                </div>
                            )}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Stay</Label>
                                    <Select value={data.stay_id} onValueChange={(v) => setData('stay_id', v)} disabled={!hasStays}>
                                        <SelectTrigger><SelectValue placeholder="Select a stay" /></SelectTrigger>
                                        <SelectContent>
                                            {stays.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.client.first_name} {s.client.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.stay_id && <div className="mt-1 text-xs text-status-critical">{errors.stay_id}</div>}
                                </div>
                                <div>
                                    <Label>Channel</Label>
                                    <Select value={data.channel} onValueChange={(v) => setData('channel', v)}>
                                        <SelectTrigger><SelectValue placeholder="Select channel" /></SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(channels).map(([key, label]) => (
                                                <SelectItem key={key} value={key}>{label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.channel && <div className="mt-1 text-xs text-status-critical">{errors.channel}</div>}
                                </div>
                            </div>
                            <div>
                                <Label>Occurred At</Label>
                                <Input
                                    type="datetime-local"
                                    value={data.occurred_at}
                                    onChange={(e) => setData('occurred_at', e.target.value)}
                                />
                                {errors.occurred_at && <div className="mt-1 text-xs text-status-critical">{errors.occurred_at}</div>}
                            </div>
                            <div>
                                <Label>Summary</Label>
                                <Textarea
                                    value={data.summary}
                                    onChange={(e) => setData('summary', e.target.value)}
                                    rows={4}
                                />
                                {errors.summary && <div className="mt-1 text-xs text-status-critical">{errors.summary}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Participants</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {data.participants.map((p, index) => (
                                <div key={index} className="flex items-end gap-2">
                                    <div className="flex-1">
                                        <Label>Name</Label>
                                        <Input
                                            value={p.name}
                                            onChange={(e) => updateParticipant(index, 'name', e.target.value)}
                                            placeholder="Participant name"
                                        />
                                    </div>
                                    <div className="flex-1">
                                        <Label>Role</Label>
                                        <Input
                                            value={p.role}
                                            onChange={(e) => updateParticipant(index, 'role', e.target.value)}
                                            placeholder="Role"
                                        />
                                    </div>
                                    {data.participants.length > 1 && (
                                        <Button type="button" variant="outline" size="sm" onClick={() => removeParticipant(index)}>
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            ))}
                            {errors.participants && <div className="mt-1 text-xs text-status-critical">{errors.participants}</div>}
                            <Button type="button" variant="outline" size="sm" onClick={addParticipant}>
                                Add Participant
                            </Button>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing || !hasStays}>
                            Save Communication Log
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
