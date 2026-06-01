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
    ClipboardCheck,
    Clock,
    Copy,
    FileCheck2,
    Loader2,
    Mail,
    Pencil,
    Phone,
    ShieldCheck,
    Star,
    Trash2,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import {
    DetailIconHeader,
    FilterSelect,
    formatDate,
    LockedSiteCard,
    SitePickerField,
    SiteTypeBadge,
    TilePicker,
    type FilterOption,
    type SiteOption,
    type TileOption,
} from '../_dialog-shared';

type VendorBaseValues = {
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

type VendorComplianceValues = {
    hs_induction_completed: boolean;
    hs_induction_date: string;
    qualifications_verified: boolean;
    qualifications_notes: string;
    insurance_verified: boolean;
    insurance_expiry: string;
    insurance_provider: string;
    insurance_policy_number: string;
    site_specific_hs_plan: string;
    hs_performance_rating: string;
    hs_last_reviewed_at: string;
};

export type VendorFormValues = VendorBaseValues & VendorComplianceValues;

export type VendorRecord = VendorBaseValues &
    Partial<VendorComplianceValues> & {
        id: number;
        is_active?: boolean;
        site_id?: number;
        site_name?: string | null;
        site_type?: string | null;
    };

const CONTACT_TILES: TileOption[] = [
    { key: 'phone', label: 'Phone', description: 'Daytime line', icon: Phone },
    {
        key: 'after_hours',
        label: 'After hours',
        description: 'Urgent / on-call',
        icon: Clock,
    },
    { key: 'email', label: 'Email', description: 'Non-urgent', icon: Mail },
];

const CONTACT_METHOD_LABEL: Record<string, string> = {
    phone: 'Phone (daytime)',
    after_hours: 'After-hours line',
    email: 'Email',
};

const HS_RATING_OPTIONS: FilterOption[] = [
    { value: '', label: 'No rating' },
    { value: 'excellent', label: 'Excellent' },
    { value: 'good', label: 'Good' },
    { value: 'watch', label: 'Watch' },
    { value: 'concern', label: 'Concern' },
];

const HS_RATING_LABEL: Record<string, string> = {
    excellent: 'Excellent',
    good: 'Good',
    watch: 'Watch',
    concern: 'Concern',
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
    const targetSiteId =
        siteId ?? (pickedSiteId === '' ? undefined : pickedSiteId);

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
        hs_induction_completed: false,
        hs_induction_date: '',
        qualifications_verified: false,
        qualifications_notes: '',
        insurance_verified: false,
        insurance_expiry: '',
        insurance_provider: '',
        insurance_policy_number: '',
        site_specific_hs_plan: '',
        hs_performance_rating: '',
        hs_last_reviewed_at: '',
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
                            <LockedSiteCard
                                site={lockedSite}
                                note="Vendor is scoped to this site."
                            />
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
                <Button
                    type="submit"
                    disabled={form.processing || !targetSiteId}
                >
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
        hs_induction_completed: !!vendor.hs_induction_completed,
        hs_induction_date: vendor.hs_induction_date ?? '',
        qualifications_verified: !!vendor.qualifications_verified,
        qualifications_notes: vendor.qualifications_notes ?? '',
        insurance_verified: !!vendor.insurance_verified,
        insurance_expiry: vendor.insurance_expiry ?? '',
        insurance_provider: vendor.insurance_provider ?? '',
        insurance_policy_number: vendor.insurance_policy_number ?? '',
        site_specific_hs_plan: vendor.site_specific_hs_plan ?? '',
        hs_performance_rating: vendor.hs_performance_rating ?? '',
        hs_last_reviewed_at: vendor.hs_last_reviewed_at ?? '',
    });

    const effectiveLockedSite =
        lockedSite ??
        (vendor.site_name
            ? {
                  id: vendor.site_id ?? siteId,
                  name: vendor.site_name,
                  type: vendor.site_type ?? '',
              }
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
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
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
    const hasComplianceNotes = Boolean(
        vendor?.hs_induction_completed ||
        vendor?.qualifications_verified ||
        vendor?.insurance_verified ||
        vendor?.insurance_expiry ||
        vendor?.insurance_policy_number ||
        vendor?.hs_performance_rating ||
        vendor?.site_specific_hs_plan,
    );

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 560px)' }}>
                {isOpen && vendor && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="sr-only">
                                {vendor.company_name}
                            </DialogTitle>
                            <DialogDescription className="sr-only">
                                Vendor contact details for {vendor.company_name}
                                .
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
                            {vendor.site_type ? (
                                <SiteTypeBadge type={vendor.site_type} />
                            ) : null}
                            {vendor.hs_induction_completed && (
                                <Badge
                                    variant="outline"
                                    className="gap-1 border-status-success/30 bg-status-success-bg text-status-success"
                                >
                                    <ClipboardCheck className="h-3 w-3" />
                                    Inducted
                                </Badge>
                            )}
                            {vendor.qualifications_verified && (
                                <Badge
                                    variant="outline"
                                    className="gap-1 border-status-success/30 bg-status-success-bg text-status-success"
                                >
                                    <FileCheck2 className="h-3 w-3" />
                                    Qualifications checked
                                </Badge>
                            )}
                            {vendor.insurance_verified && (
                                <Badge
                                    variant="outline"
                                    className="gap-1 border-status-success/30 bg-status-success-bg text-status-success"
                                >
                                    <ShieldCheck className="h-3 w-3" />
                                    Insurance checked
                                </Badge>
                            )}
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
                                        onCopy={() =>
                                            copy(vendor.after_hours_phone)
                                        }
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
                                <DetailRow label="Account #">
                                    {vendor.account_number}
                                </DetailRow>
                            ) : null}
                            <DetailRow label="Preferred method">
                                {CONTACT_METHOD_LABEL[
                                    vendor.preferred_contact_method
                                ] || 'Phone'}
                            </DetailRow>
                            <DetailRow label="H&S induction">
                                {vendor.hs_induction_completed ? (
                                    `Completed${vendor.hs_induction_date ? ` · ${formatDate(vendor.hs_induction_date)}` : ''}`
                                ) : (
                                    <Muted />
                                )}
                            </DetailRow>
                            <DetailRow label="Insurance">
                                {vendor.insurance_verified ||
                                vendor.insurance_expiry ? (
                                    [
                                        vendor.insurance_verified
                                            ? 'Verified'
                                            : 'Not verified',
                                        vendor.insurance_expiry
                                            ? `expires ${formatDate(vendor.insurance_expiry)}`
                                            : null,
                                        vendor.insurance_provider || null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                ) : (
                                    <Muted />
                                )}
                            </DetailRow>
                            <DetailRow label="Qualifications">
                                {vendor.qualifications_verified ? (
                                    'Verified'
                                ) : (
                                    <Muted />
                                )}
                            </DetailRow>
                            {vendor.hs_performance_rating ? (
                                <DetailRow label="H&S rating">
                                    {HS_RATING_LABEL[
                                        vendor.hs_performance_rating
                                    ] ?? vendor.hs_performance_rating}
                                    {vendor.hs_last_reviewed_at ? (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · reviewed{' '}
                                            {formatDate(
                                                vendor.hs_last_reviewed_at,
                                            )}
                                        </span>
                                    ) : null}
                                </DetailRow>
                            ) : null}
                            {hasComplianceNotes &&
                            vendor.insurance_policy_number ? (
                                <DetailRow label="Policy #">
                                    {vendor.insurance_policy_number}
                                </DetailRow>
                            ) : null}
                            {vendor.site_specific_hs_plan ? (
                                <DetailRow label="Site H&S plan" full>
                                    <span className="whitespace-pre-wrap">
                                        {vendor.site_specific_hs_plan}
                                    </span>
                                </DetailRow>
                            ) : null}
                            {vendor.qualifications_notes ? (
                                <DetailRow label="Qualification notes" full>
                                    <span className="whitespace-pre-wrap">
                                        {vendor.qualifications_notes}
                                    </span>
                                </DetailRow>
                            ) : null}
                            {vendor.notes ? (
                                <DetailRow label="Notes" full>
                                    <span className="whitespace-pre-wrap">
                                        {vendor.notes}
                                    </span>
                                </DetailRow>
                            ) : null}
                        </dl>

                        <DialogFooter className="mt-4 flex-wrap gap-2 sm:justify-between">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={!vendor.phone}
                                onClick={() =>
                                    vendor.phone &&
                                    (window.location.href = `tel:${vendor.phone}`)
                                }
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
    if (full) {
        // Full-width row: label on its own line, value spanning all 3 columns.
        return (
            <>
                <dt className="col-span-3 text-muted-foreground">{label}</dt>
                <dd className="col-span-3">{children}</dd>
            </>
        );
    }
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="col-span-2">{children}</dd>
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
                                <span className="font-medium">
                                    {vendor.company_name}
                                </span>{' '}
                                will be removed
                                {vendor.site_name ? (
                                    <>
                                        {' '}
                                        from{' '}
                                        <span className="font-medium">
                                            {vendor.site_name}
                                        </span>
                                    </>
                                ) : null}
                                . A vendor with linked credentials can't be
                                deleted until those credentials are removed.
                                This cannot be undone.
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

function VendorFields({
    form,
}: {
    form: ReturnType<typeof useForm<VendorFormValues>>;
}) {
    return (
        <>
            <div className="sm:col-span-2">
                <Label htmlFor="v-company">
                    Company name <span className="text-status-critical">*</span>
                </Label>
                <Input
                    id="v-company"
                    value={form.data.company_name}
                    onChange={(e) =>
                        form.setData('company_name', e.target.value)
                    }
                    placeholder="e.g. Capital Plumbing & Gas"
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
                    onChange={(e) =>
                        form.setData('service_type', e.target.value)
                    }
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
                    onChange={(e) =>
                        form.setData('contact_name', e.target.value)
                    }
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
                    onChange={(e) =>
                        form.setData('after_hours_phone', e.target.value)
                    }
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
                    onChange={(e) =>
                        form.setData('account_number', e.target.value)
                    }
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
                    onCheckedChange={(checked) =>
                        form.setData('is_preferred', !!checked)
                    }
                />
                <Label
                    htmlFor="v-preferred-flag"
                    className="text-sm font-normal"
                >
                    Mark as preferred vendor for this service
                </Label>
            </div>
            <div className="mt-1 border-t border-border pt-3 sm:col-span-2">
                <div className="flex items-center gap-2 text-sm font-medium">
                    <ShieldCheck className="h-4 w-4 text-primary" />
                    Compliance
                </div>
            </div>
            <div className="flex items-center gap-2">
                <Checkbox
                    id="v-hs-induction"
                    checked={form.data.hs_induction_completed}
                    onCheckedChange={(checked) =>
                        form.setData('hs_induction_completed', !!checked)
                    }
                />
                <Label htmlFor="v-hs-induction" className="text-sm font-normal">
                    Site induction completed
                </Label>
            </div>
            <div>
                <Label htmlFor="v-hs-induction-date">Induction date</Label>
                <Input
                    id="v-hs-induction-date"
                    type="date"
                    value={form.data.hs_induction_date}
                    onChange={(e) =>
                        form.setData('hs_induction_date', e.target.value)
                    }
                />
                <FieldError message={form.errors.hs_induction_date} />
            </div>
            <div className="flex items-center gap-2">
                <Checkbox
                    id="v-qualifications"
                    checked={form.data.qualifications_verified}
                    onCheckedChange={(checked) =>
                        form.setData('qualifications_verified', !!checked)
                    }
                />
                <Label
                    htmlFor="v-qualifications"
                    className="text-sm font-normal"
                >
                    Qualifications verified
                </Label>
            </div>
            <div className="flex items-center gap-2">
                <Checkbox
                    id="v-insurance"
                    checked={form.data.insurance_verified}
                    onCheckedChange={(checked) =>
                        form.setData('insurance_verified', !!checked)
                    }
                />
                <Label htmlFor="v-insurance" className="text-sm font-normal">
                    Insurance verified
                </Label>
            </div>
            <div>
                <Label htmlFor="v-insurance-provider">Insurance provider</Label>
                <Input
                    id="v-insurance-provider"
                    value={form.data.insurance_provider}
                    onChange={(e) =>
                        form.setData('insurance_provider', e.target.value)
                    }
                />
                <FieldError message={form.errors.insurance_provider} />
            </div>
            <div>
                <Label htmlFor="v-insurance-expiry">Insurance expiry</Label>
                <Input
                    id="v-insurance-expiry"
                    type="date"
                    value={form.data.insurance_expiry}
                    onChange={(e) =>
                        form.setData('insurance_expiry', e.target.value)
                    }
                />
                <FieldError message={form.errors.insurance_expiry} />
            </div>
            <div>
                <Label htmlFor="v-insurance-policy">Policy number</Label>
                <Input
                    id="v-insurance-policy"
                    value={form.data.insurance_policy_number}
                    onChange={(e) =>
                        form.setData('insurance_policy_number', e.target.value)
                    }
                />
                <FieldError message={form.errors.insurance_policy_number} />
            </div>
            <div>
                <Label>H&S performance</Label>
                <div className="mt-1">
                    <FilterSelect
                        value={form.data.hs_performance_rating}
                        onChange={(value) =>
                            form.setData('hs_performance_rating', value)
                        }
                        options={HS_RATING_OPTIONS}
                        widthClass="w-full"
                        aria-label="H&S performance rating"
                    />
                </div>
                <FieldError message={form.errors.hs_performance_rating} />
            </div>
            <div>
                <Label htmlFor="v-hs-reviewed">Last H&S review</Label>
                <Input
                    id="v-hs-reviewed"
                    type="date"
                    value={form.data.hs_last_reviewed_at}
                    onChange={(e) =>
                        form.setData('hs_last_reviewed_at', e.target.value)
                    }
                />
                <FieldError message={form.errors.hs_last_reviewed_at} />
            </div>
            <div className="sm:col-span-2">
                <Label htmlFor="v-site-hs-plan">Site-specific H&S plan</Label>
                <Textarea
                    id="v-site-hs-plan"
                    rows={2}
                    value={form.data.site_specific_hs_plan}
                    onChange={(e) =>
                        form.setData('site_specific_hs_plan', e.target.value)
                    }
                    placeholder="Access controls, lockout process, site risks..."
                />
                <FieldError message={form.errors.site_specific_hs_plan} />
            </div>
            <div className="sm:col-span-2">
                <Label htmlFor="v-qualification-notes">
                    Qualification notes
                </Label>
                <Textarea
                    id="v-qualification-notes"
                    rows={2}
                    value={form.data.qualifications_notes}
                    onChange={(e) =>
                        form.setData('qualifications_notes', e.target.value)
                    }
                    placeholder="Licences sighted, expiry notes, restrictions..."
                />
                <FieldError message={form.errors.qualifications_notes} />
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
