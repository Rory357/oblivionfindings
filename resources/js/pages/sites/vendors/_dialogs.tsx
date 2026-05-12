import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { router, useForm } from '@inertiajs/react';
import { Loader2, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';

export type VendorFormValues = {
    service_type: string;
    company_name: string;
    contact_name: string;
    phone: string;
    after_hours_phone: string;
    email: string;
    account_number: string;
    notes: string;
    preferred_contact_method: 'phone' | 'after_hours' | 'email';
    is_preferred: boolean;
};

export type VendorRecord = VendorFormValues & {
    id: number;
    is_active?: boolean;
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ── Add ───────────────────────────────────────────────────────────────────

export function AddVendorDialog({
    siteId,
    isOpen,
    onClose,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                {isOpen && <AddVendorBody siteId={siteId} onClose={onClose} />}
            </DialogContent>
        </Dialog>
    );
}

function AddVendorBody({
    siteId,
    onClose,
}: {
    siteId: number;
    onClose: () => void;
}) {
    const form = useForm<VendorFormValues>({
        service_type: '',
        company_name: '',
        contact_name: '',
        phone: '',
        after_hours_phone: '',
        email: '',
        account_number: '',
        notes: '',
        preferred_contact_method: 'phone',
        is_preferred: false,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${siteId}/vendors`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle>Add Vendor</DialogTitle>
                <DialogDescription>
                    Vendor will be associated with this site only.
                </DialogDescription>
            </DialogHeader>

            <VendorFields form={form} />

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save vendor
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Edit ──────────────────────────────────────────────────────────────────

export function EditVendorDialog({
    siteId,
    vendor,
    isOpen,
    onClose,
}: {
    siteId: number;
    vendor: VendorRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                {isOpen && vendor && (
                    <EditVendorBody
                        siteId={siteId}
                        vendor={vendor}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditVendorBody({
    siteId,
    vendor,
    onClose,
}: {
    siteId: number;
    vendor: VendorRecord;
    onClose: () => void;
}) {
    const form = useForm<VendorFormValues>({
        service_type: vendor.service_type ?? '',
        company_name: vendor.company_name ?? '',
        contact_name: vendor.contact_name ?? '',
        phone: vendor.phone ?? '',
        after_hours_phone: vendor.after_hours_phone ?? '',
        email: vendor.email ?? '',
        account_number: vendor.account_number ?? '',
        notes: vendor.notes ?? '',
        preferred_contact_method: vendor.preferred_contact_method ?? 'phone',
        is_preferred: !!vendor.is_preferred,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/sites/${siteId}/vendors/${vendor.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle>Edit Vendor</DialogTitle>
            </DialogHeader>

            <VendorFields form={form} />

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Show / Read-only ──────────────────────────────────────────────────────

export function ShowVendorDialog({
    vendor,
    isOpen,
    canManage,
    onClose,
    onEdit,
    onDelete,
}: {
    vendor: VendorRecord | null;
    isOpen: boolean;
    canManage: boolean;
    onClose: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                {isOpen && vendor && (
                    <>
                        <DialogHeader>
                            <DialogTitle>{vendor.company_name}</DialogTitle>
                            <DialogDescription>
                                {vendor.service_type}
                                {vendor.is_preferred && (
                                    <Badge
                                        variant="outline"
                                        className="ml-2 border-status-warning/30 text-status-warning"
                                    >
                                        Preferred
                                    </Badge>
                                )}
                            </DialogDescription>
                        </DialogHeader>

                        <dl className="grid grid-cols-3 gap-x-4 gap-y-2 text-sm">
                            <ReadOnlyRow
                                label="Contact"
                                value={vendor.contact_name}
                            />
                            <ReadOnlyRow label="Phone" value={vendor.phone} />
                            <ReadOnlyRow
                                label="After hours"
                                value={vendor.after_hours_phone}
                            />
                            <ReadOnlyRow label="Email" value={vendor.email} />
                            <ReadOnlyRow
                                label="Account #"
                                value={vendor.account_number}
                            />
                            <ReadOnlyRow
                                label="Preferred contact"
                                value={vendor.preferred_contact_method}
                            />
                            {vendor.notes && (
                                <ReadOnlyRow
                                    label="Notes"
                                    value={vendor.notes}
                                    full
                                />
                            )}
                        </dl>

                        <DialogFooter className="mt-2">
                            {canManage && onDelete && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-status-critical"
                                    onClick={onDelete}
                                >
                                    <Trash2 className="mr-2 h-4 w-4" />
                                    Delete
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onClose}
                            >
                                Close
                            </Button>
                            {canManage && onEdit && (
                                <Button type="button" onClick={onEdit}>
                                    <Pencil className="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                            )}
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function ReadOnlyRow({
    label,
    value,
    full,
}: {
    label: string;
    value?: string | null;
    full?: boolean;
}) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={full ? 'col-span-2 whitespace-pre-wrap' : 'col-span-2'}>
                {value ? value : <span className="text-muted-foreground">—</span>}
            </dd>
        </>
    );
}

// ── Confirm Delete ────────────────────────────────────────────────────────

export function DeleteVendorDialog({
    siteId,
    vendor,
    isOpen,
    onClose,
}: {
    siteId: number;
    vendor: VendorRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    const [submitting, setSubmitting] = useState(false);

    const handleDelete = () => {
        if (!vendor) return;
        setSubmitting(true);
        router.delete(`/sites/${siteId}/vendors/${vendor.id}`, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSubmitting(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete vendor?</DialogTitle>
                    <DialogDescription>
                        {vendor && (
                            <>
                                <span className="font-medium">
                                    {vendor.company_name}
                                </span>{' '}
                                will be removed from this site. This cannot be
                                undone.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={handleDelete}
                        disabled={submitting}
                    >
                        {submitting && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        Delete vendor
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ── Shared field group ────────────────────────────────────────────────────

function VendorFields({ form }: { form: ReturnType<typeof useForm<VendorFormValues>> }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <Label htmlFor="v-company">
                    Company name <span className="text-status-critical">*</span>
                </Label>
                <Input
                    id="v-company"
                    value={form.data.company_name}
                    onChange={(e) => form.setData('company_name', e.target.value)}
                    required
                />
                <FieldError message={form.errors.company_name} />
            </div>
            <div>
                <Label htmlFor="v-service">
                    Category / Service type{' '}
                    <span className="text-status-critical">*</span>
                </Label>
                <Input
                    id="v-service"
                    value={form.data.service_type}
                    onChange={(e) => form.setData('service_type', e.target.value)}
                    placeholder="electrician, plumber, ISP…"
                    required
                />
                <FieldError message={form.errors.service_type} />
            </div>
            <div>
                <Label htmlFor="v-contact">Contact name</Label>
                <Input
                    id="v-contact"
                    value={form.data.contact_name}
                    onChange={(e) => form.setData('contact_name', e.target.value)}
                />
                <FieldError message={form.errors.contact_name} />
            </div>
            <div>
                <Label htmlFor="v-phone">Phone</Label>
                <Input
                    id="v-phone"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                />
                <FieldError message={form.errors.phone} />
            </div>
            <div>
                <Label htmlFor="v-after">After-hours phone</Label>
                <Input
                    id="v-after"
                    value={form.data.after_hours_phone}
                    onChange={(e) =>
                        form.setData('after_hours_phone', e.target.value)
                    }
                />
                <FieldError message={form.errors.after_hours_phone} />
            </div>
            <div className="sm:col-span-2">
                <Label htmlFor="v-email">Email</Label>
                <Input
                    id="v-email"
                    type="email"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                />
                <FieldError message={form.errors.email} />
            </div>
            <div>
                <Label htmlFor="v-acct">Account number</Label>
                <Input
                    id="v-acct"
                    value={form.data.account_number}
                    onChange={(e) =>
                        form.setData('account_number', e.target.value)
                    }
                />
                <FieldError message={form.errors.account_number} />
            </div>
            <div>
                <Label htmlFor="v-preferred">Preferred contact method</Label>
                <Select
                    value={form.data.preferred_contact_method}
                    onValueChange={(v) =>
                        form.setData(
                            'preferred_contact_method',
                            v as VendorFormValues['preferred_contact_method'],
                        )
                    }
                >
                    <SelectTrigger id="v-preferred">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="phone">Phone</SelectItem>
                        <SelectItem value="after_hours">After hours</SelectItem>
                        <SelectItem value="email">Email</SelectItem>
                    </SelectContent>
                </Select>
                <FieldError message={form.errors.preferred_contact_method} />
            </div>
            <div className="flex items-center gap-2 sm:col-span-2">
                <Checkbox
                    id="v-preferred-flag"
                    checked={form.data.is_preferred}
                    onCheckedChange={(checked) =>
                        form.setData('is_preferred', !!checked)
                    }
                />
                <Label
                    htmlFor="v-preferred-flag"
                    className="text-sm font-normal"
                >
                    Mark as preferred vendor
                </Label>
            </div>
            <div className="sm:col-span-2">
                <Label htmlFor="v-notes">Notes</Label>
                <Textarea
                    id="v-notes"
                    rows={3}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
                <FieldError message={form.errors.notes} />
            </div>
        </div>
    );
}
