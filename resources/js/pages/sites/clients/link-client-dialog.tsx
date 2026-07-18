import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { Link2, Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

type Option = {
    id: number;
    name: string;
};

export type PlacementClient = Option & {
    preferred_name?: string | null;
    status: string;
};

export type ClientPlacementOptions = {
    rooms: Array<Option & { notes?: string | null }>;
    service_contexts: Array<Option & { type?: string | null }>;
    key_workers: Option[];
};

export function LinkClientDialog({
    siteId,
    availableClients,
    options,
    isOpen,
    onClose,
    onPlaced,
}: {
    siteId: number;
    availableClients: PlacementClient[];
    options: ClientPlacementOptions;
    isOpen: boolean;
    onClose: () => void;
    onPlaced?: () => void;
}) {
    const form = useForm({
        client_id: '',
        service_context_id: '',
        room_id: '',
        key_worker_id: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/sites/${siteId}/clients/link`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onPlaced?.();
                onClose();
            },
        });
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Link2 className="h-5 w-5 text-primary" />
                            Place an existing client
                        </DialogTitle>
                        <DialogDescription>
                            Choose an unassigned client, then record their Site
                            placement in one transaction. Room and care details
                            are optional.
                        </DialogDescription>
                    </DialogHeader>

                    <PlacementSelect
                        id="placement-client"
                        label="Client"
                        placeholder="Choose an unassigned client"
                        value={form.data.client_id}
                        onChange={(value) => form.setData('client_id', value)}
                        options={availableClients}
                        error={form.errors.client_id}
                        required
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <PlacementSelect
                            id="placement-service"
                            label="Service context"
                            placeholder="No service selected"
                            value={form.data.service_context_id}
                            onChange={(value) =>
                                form.setData('service_context_id', value)
                            }
                            options={options.service_contexts}
                            error={form.errors.service_context_id}
                            allowNone
                        />
                        <PlacementSelect
                            id="placement-room"
                            label="Room"
                            placeholder="No room selected"
                            value={form.data.room_id}
                            onChange={(value) => form.setData('room_id', value)}
                            options={options.rooms}
                            error={form.errors.room_id}
                            allowNone
                        />
                        <PlacementSelect
                            id="placement-key-worker"
                            label="Key worker"
                            placeholder="No key worker selected"
                            value={form.data.key_worker_id}
                            onChange={(value) =>
                                form.setData('key_worker_id', value)
                            }
                            options={options.key_workers}
                            error={form.errors.key_worker_id}
                            allowNone
                        />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={!form.data.client_id || form.processing}
                        >
                            {form.processing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Link2 className="mr-2 h-4 w-4" />
                            )}
                            Place client
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PlacementSelect({
    id,
    label,
    placeholder,
    value,
    onChange,
    options,
    error,
    allowNone = false,
    required = false,
}: {
    id: string;
    label: string;
    placeholder: string;
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    error?: string;
    allowNone?: boolean;
    required?: boolean;
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>
                {label}
                {required ? (
                    <span className="text-status-critical"> *</span>
                ) : null}
            </Label>
            <Select
                value={value || undefined}
                onValueChange={(next) =>
                    onChange(next === '__none' ? '' : next)
                }
            >
                <SelectTrigger
                    id={id}
                    className="min-h-11"
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? `${id}-error` : undefined}
                >
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {allowNone ? (
                        <SelectItem value="__none">{placeholder}</SelectItem>
                    ) : null}
                    {options.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {error ? (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="text-xs text-status-critical"
                >
                    {error}
                </p>
            ) : null}
        </div>
    );
}
