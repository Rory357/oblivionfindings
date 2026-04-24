import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

type Option = { id: number; name: string; is_active?: boolean };

type FormState = {
    id: number;
    site_id: number | null;
    service_context_id: number | null;
    first_name: string;
    last_name: string;
    preferred_name: string;
    date_of_birth: string;
    gender: string;
    status: string;
    phone: string;
    email: string;
    address_line_1: string;
    address_line_2: string;
    suburb: string;
    city: string;
    postcode: string;
    funding_type: string;
    funding_notes: string;
};

const NONE = '__none';

export function ClientEditDialog({
    clientId,
    open,
    onOpenChange,
    siteSingular = 'Site',
}: {
    clientId: number | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    siteSingular?: string;
}) {
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState<FormState | null>(null);
    const [sites, setSites] = useState<Option[]>([]);
    const [serviceContexts, setServiceContexts] = useState<Option[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open || !clientId) {
            if (!open) {
                setData(null);
                setErrors({});
            }
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        setErrors({});
        fetch(`/operations/clients/${clientId}/edit?modal=1`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((r) => {
                if (!r.ok) throw new Error('Failed to load client');
                return r.json();
            })
            .then((json) => {
                const c = json.client ?? {};
                setData({
                    id: c.id,
                    site_id: c.site_id ?? null,
                    service_context_id: c.service_context_id ?? null,
                    first_name: c.first_name ?? '',
                    last_name: c.last_name ?? '',
                    preferred_name: c.preferred_name ?? '',
                    date_of_birth: c.date_of_birth ?? '',
                    gender: c.gender ?? '',
                    status: c.status ?? 'active',
                    phone: c.phone ?? '',
                    email: c.email ?? '',
                    address_line_1: c.address_line_1 ?? '',
                    address_line_2: c.address_line_2 ?? '',
                    suburb: c.suburb ?? '',
                    city: c.city ?? '',
                    postcode: c.postcode ?? '',
                    funding_type: c.funding_type ?? '',
                    funding_notes: c.funding_notes ?? '',
                });
                setSites(json.sites ?? []);
                setServiceContexts(json.serviceContexts ?? []);
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                console.error(err);
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [open, clientId]);

    function update<K extends keyof FormState>(key: K, value: FormState[K]) {
        setData((prev) => (prev ? { ...prev, [key]: value } : prev));
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!data) return;
        setProcessing(true);
        router.put(
            `/operations/clients/${data.id}`,
            { ...data, _modal: 1 },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    onOpenChange(false);
                },
                onError: (errs) => setErrors(errs as Record<string, string>),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Edit client</DialogTitle>
                </DialogHeader>

                {loading || !data ? (
                    <div className="flex items-center justify-center py-12">
                        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="First name" error={errors.first_name}>
                                <Input
                                    value={data.first_name}
                                    onChange={(e) =>
                                        update('first_name', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Last name" error={errors.last_name}>
                                <Input
                                    value={data.last_name}
                                    onChange={(e) =>
                                        update('last_name', e.target.value)
                                    }
                                />
                            </Field>
                        </div>

                        <Field
                            label="Preferred name"
                            error={errors.preferred_name}
                        >
                            <Input
                                value={data.preferred_name}
                                onChange={(e) =>
                                    update('preferred_name', e.target.value)
                                }
                            />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Date of birth"
                                error={errors.date_of_birth}
                            >
                                <Input
                                    type="date"
                                    value={data.date_of_birth}
                                    onChange={(e) =>
                                        update(
                                            'date_of_birth',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Gender" error={errors.gender}>
                                <Input
                                    value={data.gender}
                                    onChange={(e) =>
                                        update('gender', e.target.value)
                                    }
                                />
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field label="Status" error={errors.status}>
                                <Select
                                    value={data.status}
                                    onValueChange={(v) => update('status', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="inactive">
                                            Inactive
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                label={siteSingular}
                                error={errors.site_id}
                            >
                                <Select
                                    value={
                                        data.site_id
                                            ? String(data.site_id)
                                            : NONE
                                    }
                                    onValueChange={(v) =>
                                        update(
                                            'site_id',
                                            v === NONE ? null : Number(v),
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="—" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>—</SelectItem>
                                        {sites.map((s) => (
                                            <SelectItem
                                                key={s.id}
                                                value={String(s.id)}
                                            >
                                                {s.name}
                                                {s.is_active === false
                                                    ? ' (inactive)'
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                label="Service context"
                                error={errors.service_context_id}
                                hint="Residential / home support / respite"
                            >
                                <Select
                                    value={
                                        data.service_context_id
                                            ? String(data.service_context_id)
                                            : NONE
                                    }
                                    onValueChange={(v) =>
                                        update(
                                            'service_context_id',
                                            v === NONE ? null : Number(v),
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="—" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>—</SelectItem>
                                        {serviceContexts.map((sc) => (
                                            <SelectItem
                                                key={sc.id}
                                                value={String(sc.id)}
                                            >
                                                {sc.name}
                                                {sc.is_active === false
                                                    ? ' (inactive)'
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>

                        <fieldset className="space-y-4 rounded-lg border p-3">
                            <legend className="px-1 text-sm font-medium">
                                Contact
                            </legend>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Phone" error={errors.phone}>
                                    <Input
                                        value={data.phone}
                                        onChange={(e) =>
                                            update('phone', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="Email" error={errors.email}>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) =>
                                            update('email', e.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </fieldset>

                        <fieldset className="space-y-4 rounded-lg border p-3">
                            <legend className="px-1 text-sm font-medium">
                                Address
                            </legend>
                            <Field
                                label="Address line 1"
                                error={errors.address_line_1}
                            >
                                <Input
                                    value={data.address_line_1}
                                    onChange={(e) =>
                                        update(
                                            'address_line_1',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Address line 2"
                                error={errors.address_line_2}
                            >
                                <Input
                                    value={data.address_line_2}
                                    onChange={(e) =>
                                        update(
                                            'address_line_2',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Suburb" error={errors.suburb}>
                                    <Input
                                        value={data.suburb}
                                        onChange={(e) =>
                                            update('suburb', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="City" error={errors.city}>
                                    <Input
                                        value={data.city}
                                        onChange={(e) =>
                                            update('city', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Postcode"
                                    error={errors.postcode}
                                >
                                    <Input
                                        value={data.postcode}
                                        onChange={(e) =>
                                            update('postcode', e.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </fieldset>

                        <fieldset className="space-y-4 rounded-lg border p-3">
                            <legend className="px-1 text-sm font-medium">
                                Funding
                            </legend>
                            <Field
                                label="Funding type"
                                error={errors.funding_type}
                            >
                                <Input
                                    value={data.funding_type}
                                    onChange={(e) =>
                                        update('funding_type', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Funding notes"
                                error={errors.funding_notes}
                            >
                                <Textarea
                                    rows={4}
                                    value={data.funding_notes}
                                    onChange={(e) =>
                                        update(
                                            'funding_notes',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </fieldset>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving…' : 'Save changes'}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1">
            <Label className="text-sm font-medium">{label}</Label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {error && <p className="text-xs text-status-critical">{error}</p>}
        </div>
    );
}
