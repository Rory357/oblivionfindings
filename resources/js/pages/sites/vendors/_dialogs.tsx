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
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import {
    Clock,
    Copy,
    Loader2,
    Mail,
    Pencil,
    Phone,
    Star,
    Trash2,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import {
    DetailIconHeader,
    LockedSiteCard,
    SiteTypeBadge,
    SitePickerField,
    type SiteOption,
    TilePicker,
    type TileOption,
} from '../_dialog-shared';

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
    site_id?: number;
    site_name?: string | null;
    site_type?: string | null;
};

const CONTACT_TILES: TileOption[] = [
    { key: 'phone', label: 'Phone', description: 'Daytime line', icon: Phone },
    { key: 'after_hours', label: 'After hours', description: 'Urgent / on-call', icon: Clock },
    { key: 'email', label: 'Email', description: 'Non-urgent', icon: Mail },
];

const CONTACT_METHOD_LABEL: Record<string, string> = {
    phone: 'Phone (daytime)',
    after_hours: 'After-hours line',
    email: 'Email',
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ── Add ───────────────────────────────────────────────────────────────────

export function AddVendorDialog({
    siteId,
    lockedSite,
    sites,
    isOpen,
    onClose,
}: {
    /** When set, the vendor is locked to this site (site-context add). */
    siteId?: number;
    /** Optional richer locked-site card (name + type). */
    lockedSite?: SiteOption | null;
    /** Required when adding from the global view (no siteId): show a picker. */
    sites?: SiteOption[];
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 680px)' }}>
                {isOpen && (
                    <AddVendorBody
                        siteId={siteId}
                        lockedSite={lockedSite}
                        sites={sites}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AddVendorBody({
    siteId,
    lockedSite,
    sites,
    onClose,
}: {
    siteId?: number;
    lockedSite?: SiteOption | null;
    sites?: SiteOption[];
    onClose: () => void;
}) {
    const [pickedSiteId, setPickedSiteId] = useState<number | ''>('');
    const targetSiteId = siteId ?? (pickedSiteId === '' ? undefined : pickedSiteId);

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
        if (!targetSiteId) return;
        form.post(`/sites/${targetSiteId}/vendors`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Truck className="h-4 w-4 text-primary" />
                    Add vendor
                </DialogTitle>
                <DialogDescription>
                    Vendor will be associated with the selected site only.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    {siteId ? (
                        lockedSite ? (
                            <LockedSiteCard site={lockedSite} note="Vendor is scoped to this site." />
                        ) : null
                    ) : (
                        <SitePickerField
                            sites={sites ?? []}
                            value={pickedSiteId}
                            onChange={setPickedSiteId}
                        />
                    )}
                </div>
                <VendorFields form={form} />
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || !targetSiteId}>
                    {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
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
    lockedSite,
    isOpen,
    onClose,
}: {
    siteId: number;
    vendor: VendorRecord | null;
    lockedSite?: SiteOption | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 680px)' }}>
                {isOpen && vendor && (
                    <EditVendorBody
                        siteId={siteId}
                        vendor={vendor}
                        lockedSite={lockedSite}
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
    lockedSite,
    onClose,
}: {
    siteId: number;
    vendor: VendorRecord;
    lockedSite?: SiteOption | null;
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

    const effectiveLockedSite =
        lockedSite ??
        (vendor.site_name
            ? { id: vendor.site_id ?? siteId, name: vendor.site_name, type: vendor.site_type ?? '' }
            : null);

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
                <DialogTitle className="flex items-center gap-2">
                    <Pencil className="h-4 w-4 text-primary" />
                    Edit vendor
                </DialogTitle>
                <DialogDescription>
                    Update this service provider's contact details.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                {effectiveLockedSite ? (
                    <div className="sm:col-span-2">
                        <LockedSiteCard
                            site={effectiveLockedSite}
                            note="A vendor stays with its site — create a new one to move it."
                        />
                    </div>
                ) : null}
                <VendorFields form={form} />
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                    Save changes
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Show / Read-only (view-first; Edit is the RBAC-gated elevated action) ──

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
    const copy = (text?: string | null) => {
        if (!text) return;
        try {
            void navigator.clipboard.writeText(text);
        } catch {
            // clipboard may be blocked
        }
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 560px)' }}>
                {isOpen && vendor && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="sr-only">{vendor.company_name}</DialogTitle>
                            <DialogDescription className="sr-only">
                                Vendor contact details for {vendor.company_name}.
                            </DialogDescription>
                            <DetailIconHeader
                                icon={Truck}
                                title={
                                    <span className="flex items-center gap-2">
                                        {vendor.company_name}
                                        {vendor.is_preferred && (
                                            <Star className="h-4 w-4 fill-status-warning text-status-warning" />
                                        )}
                                    </span>
                                }
                                subtitle={
                                    <>
                                        <span>{vendor.service_type}</span>
                                        {vendor.site_name ? (
                                            <>
                                                <span>·</span>
                                                <span>{vendor.site_name}</span>
                                            </>
                                        ) : null}
                                    </>
                                }
                            />
                        </DialogHeader>

                        <div className="mt-3 flex flex-wrap gap-2">
                            <Badge
                                variant="outline"
                                className={
                                    vendor.is_active
                                        ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                        : 'border-border bg-muted text-muted-foreground'
                                }
                            >
                                {vendor.is_active ? 'Active' : 'Inactive'}
                            </Badge>
                            {vendor.is_preferred && (
                                <Badge
                                    variant="outline"
                                    className="gap-1 border-status-warning/30 bg-status-warning-bg text-status-warning"
                                >
                                    <Star className="h-3 w-3 fill-current" />
                                    Preferred vendor
                                </Badge>
                            )}
                            {vendor.site_type ? <SiteTypeBadge type={vendor.site_type} /> : null}
                        </div>

                        <dl className="mt-4 grid grid-cols-3 gap-x-4 gap-y-3 text-sm">
                            <DetailRow label="Contact">
                                {vendor.contact_name || <Muted />}
                            </DetailRow>
                            <DetailRow label="Phone">
                                {vendor.phone ? (
                                    <ContactValue
                                        href={`tel:${vendor.phone}`}
                                        icon={Phone}
                                        text={vendor.phone}
                                        onCopy={() => copy(vendor.phone)}
                                    />
                                ) : (
                                    <Muted />
                                )}
                            </DetailRow>
                            {vendor.after_hours_phone ? (
                                <DetailRow label="After-hours">
                                    <ContactValue
                                        href={`tel:${vendor.after_hours_phone}`}
                                        icon={Clock}
                                        text={vendor.after_hours_phone}
                                        onCopy={() => copy(vendor.after_hours_phone)}
                                    />
                                </DetailRow>
                            ) : null}
                            <DetailRow label="Email">
                                {vendor.email ? (
                                    <ContactValue
                                        href={`mailto:${vendor.email}`}
                                        icon={Mail}
                                        text={vendor.email}
                                        onCopy={() => copy(vendor.email)}
                                    />
                                ) : (
                                    <Muted />
                                )}
                            </DetailRow>
                            {vendor.account_number ? (
                                <DetailRow label="Account #">{vendor.account_number}</DetailRow>
                            ) : null}
                            <DetailRow label="Preferred method">
                                {CONTACT_METHOD_LABEL[vendor.preferred_contact_method] || 'Phone'}
                            </DetailRow>
                            {vendor.notes ? (
                                <DetailRow label="Notes" full>
                                    <span className="whitespace-pre-wrap">{vendor.notes}</span>
                                </DetailRow>
                            ) : null}
                        </dl>

                        <DialogFooter className="mt-4 flex-wrap gap-2 sm:justify-between">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={!vendor.phone}
                                onClick={() => vendor.phone && (window.location.href = `tel:${vendor.phone}`)}
                            >
                                <Phone className="mr-2 h-4 w-4" />
                                Call now
                            </Button>
                            <div className="flex flex-wrap items-center gap-2">
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
                                <Button type="button" variant="outline" onClick={onClose}>
                                    Close
                                </Button>
                                {canManage && onEdit && (
                                    <Button type="button" onClick={onEdit}>
                                        <Pencil className="mr-2 h-4 w-4" />
                                        Edit
                                    </Button>
                                )}
                            </div>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function DetailRow({
    label,
    children,
    full,
}: {
    label: string;
    children: React.ReactNode;
    full?: boolean;
}) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={full ? 'col-span-2' : 'col-span-2'}>{children}</dd>
        </>
    );
}

function Muted() {
    return <span className="text-muted-foreground">—</span>;
}

function ContactValue({
    href,
    icon: Icon,
    text,
    onCopy,
}: {
    href: string;
    icon: typeof Phone;
    text: string;
    onCopy: () => void;
}) {
    return (
        <span className="flex items-center gap-2">
            <a
                href={href}
                className="inline-flex items-center gap-1.5 text-primary hover:underline"
            >
                <Icon className="h-3.5 w-3.5" />
                <span className="truncate">{text}</span>
            </a>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-6 w-6 p-0"
                aria-label="Copy"
                onClick={onCopy}
            >
                <Copy className="h-3.5 w-3.5" />
            </Button>
        </span>
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
            <DialogContent style={{ maxWidth: 'min(92vw, 460px)' }}>
                <DialogHeader>
                    <DialogTitle>Delete vendor?</DialogTitle>
                    <DialogDescription>
                        {vendor && (
                            <>
                                <span className="font-medium">{vendor.company_name}</span> will be
                                removed{vendor.site_name ? <> from <span className="font-medium">{vendor.site_name}</span></> : null}.
                                Linked credentials are kept but unlinked. This cannot be undone.
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={handleDelete} disabled={submitting}>
                        {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
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
        <>
            <div className="sm:col-span-2">
                <Label htmlFor="v-company">
                    Company name <span className="text-status-critical">*</span>
                </Label>
                <Input
                    id="v-company"
                    value={form.data.company_name}
                    onChange={(e) => form.setData('company_name', e.target.value)}
                    placeholder="e.g. Capital Plumbing & Gas"
                    required
                />
                <FieldError message={form.errors.company_name} />
            </div>
            <div>
                <Label htmlFor="v-service">
                    Category / Service type <span className="text-status-critical">*</span>
                </Label>
                <Input
                    id="v-service"
                    value={form.data.service_type}
                    onChange={(e) => form.setData('service_type', e.target.value)}
                    placeholder="e.g. Plumbing"
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
                    placeholder="Primary contact"
                />
                <FieldError message={form.errors.contact_name} />
            </div>
            <div>
                <Label htmlFor="v-phone">Phone</Label>
                <Input
                    id="v-phone"
                    value={form.data.phone}
                    onChange={(e) => form.setData('phone', e.target.value)}
                    placeholder="+64 21 …"
                />
                <FieldError message={form.errors.phone} />
            </div>
            <div>
                <Label htmlFor="v-after">After-hours phone</Label>
                <Input
                    id="v-after"
                    value={form.data.after_hours_phone}
                    onChange={(e) => form.setData('after_hours_phone', e.target.value)}
                    placeholder="+64 27 …"
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
                    placeholder="jobs@company.co.nz"
                />
                <FieldError message={form.errors.email} />
            </div>
            <div>
                <Label htmlFor="v-acct">Account number</Label>
                <Input
                    id="v-acct"
                    value={form.data.account_number}
                    onChange={(e) => form.setData('account_number', e.target.value)}
                />
                <FieldError message={form.errors.account_number} />
            </div>
            <div className="sm:col-span-2">
                <Label>Preferred contact method</Label>
                <div className="mt-1">
                    <TilePicker
                        options={CONTACT_TILES}
                        value={form.data.preferred_contact_method}
                        onChange={(v) =>
                            form.setData(
                                'preferred_contact_method',
                                v as VendorFormValues['preferred_contact_method'],
                            )
                        }
                    />
                </div>
                <FieldError message={form.errors.preferred_contact_method} />
            </div>
            <div className="flex items-center gap-2 sm:col-span-2">
                <Checkbox
                    id="v-preferred-flag"
                    checked={form.data.is_preferred}
                    onCheckedChange={(checked) => form.setData('is_preferred', !!checked)}
                />
                <Label htmlFor="v-preferred-flag" className="text-sm font-normal">
                    Mark as preferred vendor for this service
                </Label>
            </div>
            <div className="sm:col-span-2">
                <Label htmlFor="v-notes">Notes</Label>
                <Textarea
                    id="v-notes"
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="Account number, SLA, access notes…"
                />
                <FieldError message={form.errors.notes} />
            </div>
        </>
    );
}
