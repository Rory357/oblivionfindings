import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Field, SelectInput, StepHead } from '@/components/wizard/primitives';
import { StorageLocationFields } from '@/components/health-safety/storage-location-fields';
import { useForm } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { type FormEvent } from 'react';

export type StorageSubstanceOption = { id: number; name: string };

const todayStr = (): string => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

/**
 * Site-scoped "Add storage here" dialog: the site is fixed, the substance is
 * picked here, and it POSTs to the same /substances/{id}/storage-locations
 * endpoint the register uses (shared field set — the master record stays in the
 * Chemical register, so the two surfaces never diverge).
 */
export function SiteAddStorageDialog({
    open,
    onClose,
    siteId,
    siteName,
    substances,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    siteName: string;
    substances: StorageSubstanceOption[];
}) {
    const form = useForm<{
        substance_id: string;
        site_id: string;
        location_description: string;
        current_quantity: string;
        maximum_quantity: string;
        quantity_unit: string;
        container_type: string;
        properly_labelled: boolean;
        segregation_compliant: boolean;
        last_audit_date: string;
        storage_notes: string;
    }>({
        substance_id: '',
        site_id: String(siteId),
        location_description: '',
        current_quantity: '',
        maximum_quantity: '',
        quantity_unit: 'L',
        container_type: '',
        properly_labelled: true,
        segregation_compliant: true,
        last_audit_date: todayStr(),
        storage_notes: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.substance_id) {
            form.setError('substance_id', 'Choose the substance.');
            return;
        }
        if (!form.data.location_description.trim()) {
            form.setError('location_description', 'Describe where it is held.');
            return;
        }
        form.post(`/health-safety/substances/${form.data.substance_id}/storage-locations`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = page.props.flash as { error?: string } | undefined;
                if (!flash?.error) {
                    form.reset();
                    onClose();
                }
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogTitle className="sr-only">Add storage at {siteName}</DialogTitle>
                <DialogDescription className="sr-only">Record a hazardous substance stored at this site.</DialogDescription>
                <StepHead icon={MapPin} title="Add storage here" blurb={`Record a hazardous substance stored at ${siteName}. It is added to that substance's record in the Chemical register.`} />
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Field label="Substance" required error={form.errors.substance_id}>
                        <SelectInput
                            value={form.data.substance_id}
                            onChange={(v) => form.setData('substance_id', v)}
                            placeholder="Select substance"
                            options={substances.map((s) => ({ value: String(s.id), label: s.name }))}
                        />
                    </Field>
                    <StorageLocationFields values={form.data} set={(k, v) => form.setData(k as never, v as never)} errors={form.errors} />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Add storage
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
