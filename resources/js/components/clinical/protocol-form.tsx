import InputError from '@/components/input-error';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

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
    mode: 'create' | 'edit';
    submitUrl: string;
    cancelUrl: string;
    clients: ClientOption[];
    observationTypes: SelectOption[];
    frequencies: SelectOption[];
    protocol?: Protocol;
    canEditStructure?: boolean;
};

export default function ProtocolForm({
    mode,
    submitUrl,
    cancelUrl,
    clients,
    observationTypes,
    frequencies,
    protocol,
    canEditStructure = true,
}: Props) {
    const { data, setData, post, put, processing, errors } = useForm({
        client_id: protocol ? String(protocol.client_id) : '',
        name: protocol?.name ?? '',
        observation_type: protocol?.observation_type ?? '',
        frequency: protocol?.frequency ?? '',
        custom_frequency_hours: protocol?.custom_frequency_hours ? String(protocol.custom_frequency_hours) : '',
        instructions: protocol?.instructions ?? '',
        alert_if_missed_hours: String(protocol?.alert_if_missed_hours ?? 24),
        is_active: protocol?.is_active ?? true,
        starts_at: protocol?.starts_at ?? '',
        ends_at: protocol?.ends_at ?? '',
    });

    const structuralLocked = mode === 'edit' && !canEditStructure;
    const clientName = protocol?.client
        ? `${protocol.client.first_name} ${protocol.client.last_name}`
        : '';
    const showCustomFrequencyHours = data.frequency === 'custom'
        || (structuralLocked && Boolean(protocol?.custom_frequency_hours));

    const handleSubmit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
        };

        if (mode === 'create') {
            post(submitUrl, options);
            return;
        }

        put(submitUrl, options);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Protocol Details</CardTitle>
                </CardHeader>
                <CardContent className="space-y-6">
                    {structuralLocked ? (
                        <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm text-status-warning">
                            Observation type and frequency are locked because this protocol already has schedule history.
                        </div>
                    ) : null}

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="name">Protocol Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                placeholder="e.g. Daily weight monitoring"
                                maxLength={255}
                            />
                            <InputError message={errors.name} />
                        </div>

                        {mode === 'create' ? (
                            <div className="space-y-1.5">
                                <Label>Client</Label>
                                <Select
                                    value={data.client_id}
                                    onValueChange={(value) => setData('client_id', value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select client" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {clients.map((client) => (
                                            <SelectItem key={client.id} value={String(client.id)}>
                                                {client.first_name} {client.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.client_id} />
                            </div>
                        ) : (
                            <div className="space-y-1.5">
                                <Label htmlFor="client_name">Client</Label>
                                <Input id="client_name" value={clientName} disabled />
                            </div>
                        )}
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>Observation Type</Label>
                            <Select
                                value={data.observation_type}
                                onValueChange={(value) => setData('observation_type', value)}
                                disabled={structuralLocked}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select observation type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {observationTypes.map((type) => (
                                        <SelectItem key={type.value} value={type.value}>
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.observation_type} />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Frequency</Label>
                            <Select
                                value={data.frequency}
                                onValueChange={(value) => {
                                    setData('frequency', value);
                                    if (value !== 'custom') {
                                        setData('custom_frequency_hours', '');
                                    }
                                }}
                                disabled={structuralLocked}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select frequency" />
                                </SelectTrigger>
                                <SelectContent>
                                    {frequencies.map((frequency) => (
                                        <SelectItem key={frequency.value} value={frequency.value}>
                                            {frequency.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.frequency} />
                        </div>
                    </div>

                    {showCustomFrequencyHours ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="custom_frequency_hours">Custom Frequency Hours</Label>
                            <Input
                                id="custom_frequency_hours"
                                type="number"
                                min={1}
                                max={8760}
                                value={data.custom_frequency_hours}
                                onChange={(event) => setData('custom_frequency_hours', event.target.value)}
                                disabled={structuralLocked}
                            />
                            <InputError message={errors.custom_frequency_hours} />
                        </div>
                    ) : null}

                    <div className="space-y-1.5">
                        <Label htmlFor="instructions">Instructions</Label>
                        <Textarea
                            id="instructions"
                            rows={4}
                            value={data.instructions}
                            onChange={(event) => setData('instructions', event.target.value)}
                            placeholder="Add frontline guidance for recording staff."
                        />
                        <InputError message={errors.instructions} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Monitoring Window</CardTitle>
                </CardHeader>
                <CardContent className="space-y-6">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="alert_if_missed_hours">Alert If Missed (Hours)</Label>
                            <Input
                                id="alert_if_missed_hours"
                                type="number"
                                min={1}
                                max={8760}
                                value={data.alert_if_missed_hours}
                                onChange={(event) => setData('alert_if_missed_hours', event.target.value)}
                            />
                            <InputError message={errors.alert_if_missed_hours} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="starts_at">Starts On</Label>
                            <Input
                                id="starts_at"
                                type="date"
                                value={data.starts_at}
                                onChange={(event) => setData('starts_at', event.target.value)}
                            />
                            <InputError message={errors.starts_at} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="ends_at">Ends On</Label>
                            <Input
                                id="ends_at"
                                type="date"
                                value={data.ends_at}
                                onChange={(event) => setData('ends_at', event.target.value)}
                            />
                            <InputError message={errors.ends_at} />
                        </div>
                    </div>

                    <div className="flex items-center justify-between rounded-lg border p-4">
                        <div className="space-y-1">
                            <Label htmlFor="is_active" className="text-sm font-medium">
                                Active Protocol
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                Inactive protocols stay visible in the register but no longer count as active.
                            </p>
                        </div>
                        <Switch
                            id="is_active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked)}
                        />
                    </div>
                    <InputError message={errors.is_active} />

                    {protocol ? (
                        <div className="grid gap-3 rounded-lg border bg-muted/20 p-4 text-sm md:grid-cols-3">
                            <div>
                                <p className="font-medium">Schedule history</p>
                                <p className="text-muted-foreground">
                                    {protocol.schedule_counts.total} items created
                                </p>
                            </div>
                            <div>
                                <p className="font-medium">Pending / overdue</p>
                                <p className="text-muted-foreground">
                                    {protocol.schedule_counts.pending} pending, {protocol.schedule_counts.overdue} overdue
                                </p>
                            </div>
                            <div>
                                <p className="font-medium">Completed (30d)</p>
                                <p className="text-muted-foreground">
                                    {protocol.schedule_counts.completed_30d} completed
                                </p>
                            </div>
                        </div>
                    ) : null}
                </CardContent>
            </Card>

            <div className="flex justify-end gap-3">
                <Link href={cancelUrl}>
                    <Button type="button" variant="outline">
                        Cancel
                    </Button>
                </Link>
                <Button type="submit" disabled={processing}>
                    {processing
                        ? (mode === 'create' ? 'Creating...' : 'Saving...')
                        : (mode === 'create' ? 'Create Protocol' : 'Save Protocol')}
                </Button>
            </div>
        </form>
    );
}
