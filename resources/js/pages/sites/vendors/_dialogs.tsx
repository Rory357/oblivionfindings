import { ConfirmDialog } from '@/components/confirm-dialog';
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
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
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

// ── Add / edit — shared entity wizard ────────────────────────────────────
type VendorEditorProps = {
    siteId?: number;
    lockedSite?: SiteOption | null;
    sites?: SiteOption[];
    onClose: () => void;
    vendor?: VendorRecord;
};
const VENDOR_STEPS = [
    { key: 'vendor', label: 'Vendor', blurb: 'Site and service', icon: Truck },
    {
        key: 'contact',
        label: 'Contact',
        blurb: 'How to reach them',
        icon: Phone,
    },
    {
        key: 'compliance',
        label: 'Compliance',
        blurb: 'Checks and site notes',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
];
const CONTACT_FIELDS = [
    'contact_name',
    'phone',
    'after_hours_phone',
    'email',
    'account_number',
    'preferred_contact_method',
    'is_preferred',
];
function vendorErrorStep(field: string) {
    if (['site_id', 'company_name', 'service_type'].includes(field)) return 0;
    return CONTACT_FIELDS.includes(field) ? 1 : 2;
}
export function AddVendorDialog({
    isOpen,
    ...props
}: VendorEditorProps & { isOpen: boolean }) {
    return isOpen ? <VendorEditor {...props} /> : null;
}
export function EditVendorDialog({
    isOpen,
    vendor,
    ...props
}: Omit<VendorEditorProps, 'vendor'> & {
    siteId: number;
    isOpen: boolean;
    vendor: VendorRecord | null;
}) {
    return isOpen && vendor ? (
        <VendorEditor {...props} vendor={vendor} />
    ) : null;
}
function VendorEditor({
    siteId,
    vendor,
    lockedSite,
    sites,
    onClose,
}: VendorEditorProps) {
    const [pickedSiteId, setPickedSiteId] = useState<number | ''>('');
    const [siteError, setSiteError] = useState('');
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const [saved, setSaved] = useState(false);
    const targetSiteId =
        siteId ?? (pickedSiteId === '' ? undefined : pickedSiteId);
    const form = useForm<VendorFormValues>({
        service_type: vendor?.service_type ?? '',
        company_name: vendor?.company_name ?? '',
        contact_name: vendor?.contact_name ?? '',
        phone: vendor?.phone ?? '',
        after_hours_phone: vendor?.after_hours_phone ?? '',
        email: vendor?.email ?? '',
        account_number: vendor?.account_number ?? '',
        notes: vendor?.notes ?? '',
        preferred_contact_method: vendor?.preferred_contact_method ?? 'phone',
        is_preferred: !!vendor?.is_preferred,
        hs_induction_completed: !!vendor?.hs_induction_completed,
        hs_induction_date: vendor?.hs_induction_date ?? '',
        qualifications_verified: !!vendor?.qualifications_verified,
        qualifications_notes: vendor?.qualifications_notes ?? '',
        insurance_verified: !!vendor?.insurance_verified,
        insurance_expiry: vendor?.insurance_expiry ?? '',
        insurance_provider: vendor?.insurance_provider ?? '',
        insurance_policy_number: vendor?.insurance_policy_number ?? '',
        site_specific_hs_plan: vendor?.site_specific_hs_plan ?? '',
        hs_performance_rating: vendor?.hs_performance_rating ?? '',
        hs_last_reviewed_at: vendor?.hs_last_reviewed_at ?? '',
    });
    const selectedSite =
        lockedSite ??
        sites?.find((site) => site.id === targetSiteId) ??
        (vendor?.site_name
            ? {
                  id: targetSiteId!,
                  name: vendor.site_name,
                  type: vendor.site_type ?? '',
              }
            : null);
    const dirty = !saved && (form.isDirty || pickedSiteId !== '');
    const close = () => {
        if (form.processing) return;
        if (dirty) setDiscard(true);
        else onClose();
    };
    const validateIdentity = () => {
        form.clearErrors('company_name', 'service_type');
        setSiteError(targetSiteId ? '' : 'Select a site for this vendor.');
        if (!form.data.company_name.trim())
            form.setError('company_name', 'Enter the company name.');
        if (!form.data.service_type.trim())
            form.setError('service_type', 'Enter the service type.');
        return (
            !!targetSiteId &&
            !!form.data.company_name.trim() &&
            !!form.data.service_type.trim()
        );
    };
    const save = () => {
        if (form.processing) return;
        if (!validateIdentity()) {
            setStep(0);
            return;
        }
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (vendor ? onClose() : setSaved(true)),
            onError: (errors: Record<string, string>) => {
                const firstStep = Math.min(
                    ...Object.keys(errors).map(vendorErrorStep),
                );
                setStep(Number.isFinite(firstStep) ? firstStep : 0);
            },
        };
        if (vendor)
            form.put(
                '/sites/' + targetSiteId + '/vendors/' + vendor.id,
                options,
            );
        else form.post('/sites/' + targetSiteId + '/vendors', options);
    };
    const filled = [
        targetSiteId,
        form.data.company_name.trim(),
        form.data.service_type.trim(),
        form.data.contact_name.trim(),
        form.data.phone.trim() ||
            form.data.email.trim() ||
            form.data.after_hours_phone.trim(),
        form.data.hs_induction_completed,
        form.data.qualifications_verified,
        form.data.insurance_verified,
    ].filter(Boolean).length;
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={vendor ? 'Edit vendor' : 'Add vendor'}
                description="Record the vendor, contact details and site compliance checks."
                railIcon={Truck}
                railTitle={vendor ? 'Edit vendor' : 'Add vendor'}
                railSub={selectedSite?.name ?? 'New service provider'}
                steps={VENDOR_STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!form.processing) setStep(index);
                }}
                pct={Math.round((filled / 8) * 100)}
                footerStart={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setStep(step - 1)}
                                disabled={form.processing}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step < 3 ? (
                        <Button
                            type="submit"
                            form="vendor-editor-form"
                            disabled={form.processing}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            type="submit"
                            form="vendor-editor-form"
                            disabled={form.processing}
                        >
                            {form.processing && (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            )}
                            {vendor ? 'Save changes' : 'Add vendor'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Vendor added"
                            blurb={
                                <>
                                    {form.data.company_name} is now available
                                    for{' '}
                                    {selectedSite?.name ?? 'the selected site'}.
                                    Contracts and private files can be added
                                    from the vendor record by someone with
                                    contract access.
                                </>
                            }
                            actions={
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            form.reset();
                                            form.clearErrors();
                                            setPickedSiteId('');
                                            setSiteError('');
                                            setStep(0);
                                            setSaved(false);
                                        }}
                                    >
                                        Add another
                                    </Button>
                                    <Button type="button" onClick={onClose}>
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <form
                    id="vendor-editor-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (step === 3) save();
                        else if (step !== 0 || validateIdentity())
                            setStep(step + 1);
                    }}
                >
                    <WizardStepPane key={step}>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {step === 0 && (
                                <div className="sm:col-span-2">
                                    {siteId ? (
                                        selectedSite ? (
                                            <LockedSiteCard
                                                site={selectedSite}
                                                note="This vendor belongs to this site."
                                            />
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                This vendor belongs to the
                                                current site.
                                            </p>
                                        )
                                    ) : (
                                        <SitePickerField
                                            sites={sites ?? []}
                                            value={pickedSiteId}
                                            onChange={(id) => {
                                                setPickedSiteId(id);
                                                setSiteError('');
                                            }}
                                            error={siteError}
                                            hint="Choose the site that owns this vendor record. Sharing can be managed from the vendor record."
                                        />
                                    )}
                                </div>
                            )}
                            {step < 3 && (
                                <VendorFields form={form} section={step} />
                            )}
                            {step === 3 && (
                                <>
                                    <ReviewCard
                                        icon={Truck}
                                        title="Vendor"
                                        onEdit={() => setStep(0)}
                                        span
                                    >
                                        <ReviewRow
                                            label="Site"
                                            value={
                                                selectedSite?.name ??
                                                (targetSiteId
                                                    ? 'Current site'
                                                    : 'Select a site')
                                            }
                                        />
                                        <ReviewRow
                                            label="Company"
                                            value={form.data.company_name}
                                        />
                                        <ReviewRow
                                            label="Service"
                                            value={form.data.service_type}
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Phone}
                                        title="Contact"
                                        onEdit={() => setStep(1)}
                                        span
                                    >
                                        <ReviewRow
                                            label="Contact"
                                            value={form.data.contact_name}
                                        />
                                        <ReviewRow
                                            label="Phone"
                                            value={form.data.phone}
                                        />
                                        <ReviewRow
                                            label="After hours"
                                            value={form.data.after_hours_phone}
                                        />
                                        <ReviewRow
                                            label="Email"
                                            value={form.data.email}
                                        />
                                        <ReviewRow
                                            label="Account number"
                                            value={form.data.account_number}
                                        />
                                        <ReviewRow
                                            label="Preferred contact"
                                            value={
                                                CONTACT_METHOD_LABEL[
                                                    form.data
                                                        .preferred_contact_method
                                                ]
                                            }
                                        />
                                        <ReviewRow
                                            label="Preferred vendor"
                                            value={
                                                form.data.is_preferred
                                                    ? 'Yes'
                                                    : 'No'
                                            }
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={ShieldCheck}
                                        title="Compliance"
                                        onEdit={() => setStep(2)}
                                        span
                                    >
                                        <ReviewRow
                                            label="Site induction"
                                            value={
                                                form.data.hs_induction_completed
                                                    ? 'Completed'
                                                    : 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Induction date"
                                            value={formatDate(
                                                form.data.hs_induction_date,
                                            )}
                                        />
                                        <ReviewRow
                                            label="Qualifications"
                                            value={
                                                form.data
                                                    .qualifications_verified
                                                    ? 'Verified'
                                                    : 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Qualification notes"
                                            value={
                                                form.data.qualifications_notes
                                            }
                                        />
                                        <ReviewRow
                                            label="Insurance"
                                            value={
                                                form.data.insurance_verified
                                                    ? 'Verified'
                                                    : 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Provider"
                                            value={form.data.insurance_provider}
                                        />
                                        <ReviewRow
                                            label="Policy number"
                                            value={
                                                form.data
                                                    .insurance_policy_number
                                            }
                                        />
                                        <ReviewRow
                                            label="Insurance expiry"
                                            value={formatDate(
                                                form.data.insurance_expiry,
                                            )}
                                        />
                                        <ReviewRow
                                            label="H&S performance"
                                            value={
                                                HS_RATING_LABEL[
                                                    form.data
                                                        .hs_performance_rating
                                                ]
                                            }
                                        />
                                        <ReviewRow
                                            label="Last H&S review"
                                            value={formatDate(
                                                form.data.hs_last_reviewed_at,
                                            )}
                                        />
                                        <ReviewRow
                                            label="Site H&S plan"
                                            value={
                                                form.data.site_specific_hs_plan
                                            }
                                        />
                                        <ReviewRow
                                            label="Notes"
                                            value={form.data.notes}
                                        />
                                    </ReviewCard>
                                </>
                            )}
                        </div>
                    </WizardStepPane>
                </form>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this draft?"
                description="Your unsaved vendor changes will be lost."
                confirmText="Discard draft"
            />
        </>
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
    section,
}: {
    section: number;
    form: ReturnType<typeof useForm<VendorFormValues>>;
}) {
    return (
        <>
            {section === 0 && (
                <>
                    <div className="sm:col-span-2">
                        <Label htmlFor="v-company">
                            Company name{' '}
                            <span className="text-status-critical">*</span>
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
                </>
            )}
            {section === 1 && (
                <>
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
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
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
                                form.setData(
                                    'after_hours_phone',
                                    e.target.value,
                                )
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
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
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
                        <FieldError
                            message={form.errors.preferred_contact_method}
                        />
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
                </>
            )}
            {section === 2 && (
                <>
                    <div className="sm:col-span-2">
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
                                form.setData(
                                    'hs_induction_completed',
                                    !!checked,
                                )
                            }
                        />
                        <Label
                            htmlFor="v-hs-induction"
                            className="text-sm font-normal"
                        >
                            Site induction completed
                        </Label>
                    </div>
                    <div>
                        <Label htmlFor="v-hs-induction-date">
                            Induction date
                        </Label>
                        <Input
                            id="v-hs-induction-date"
                            type="date"
                            value={form.data.hs_induction_date}
                            onChange={(e) =>
                                form.setData(
                                    'hs_induction_date',
                                    e.target.value,
                                )
                            }
                        />
                        <FieldError message={form.errors.hs_induction_date} />
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="v-qualifications"
                            checked={form.data.qualifications_verified}
                            onCheckedChange={(checked) =>
                                form.setData(
                                    'qualifications_verified',
                                    !!checked,
                                )
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
                        <Label
                            htmlFor="v-insurance"
                            className="text-sm font-normal"
                        >
                            Insurance verified
                        </Label>
                    </div>
                    <div>
                        <Label htmlFor="v-insurance-provider">
                            Insurance provider
                        </Label>
                        <Input
                            id="v-insurance-provider"
                            value={form.data.insurance_provider}
                            onChange={(e) =>
                                form.setData(
                                    'insurance_provider',
                                    e.target.value,
                                )
                            }
                        />
                        <FieldError message={form.errors.insurance_provider} />
                    </div>
                    <div>
                        <Label htmlFor="v-insurance-expiry">
                            Insurance expiry
                        </Label>
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
                        <Label htmlFor="v-insurance-policy">
                            Policy number
                        </Label>
                        <Input
                            id="v-insurance-policy"
                            value={form.data.insurance_policy_number}
                            onChange={(e) =>
                                form.setData(
                                    'insurance_policy_number',
                                    e.target.value,
                                )
                            }
                        />
                        <FieldError
                            message={form.errors.insurance_policy_number}
                        />
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
                        <FieldError
                            message={form.errors.hs_performance_rating}
                        />
                    </div>
                    <div>
                        <Label htmlFor="v-hs-reviewed">Last H&S review</Label>
                        <Input
                            id="v-hs-reviewed"
                            type="date"
                            value={form.data.hs_last_reviewed_at}
                            onChange={(e) =>
                                form.setData(
                                    'hs_last_reviewed_at',
                                    e.target.value,
                                )
                            }
                        />
                        <FieldError message={form.errors.hs_last_reviewed_at} />
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="v-site-hs-plan">
                            Site-specific H&S plan
                        </Label>
                        <Textarea
                            id="v-site-hs-plan"
                            rows={2}
                            value={form.data.site_specific_hs_plan}
                            onChange={(e) =>
                                form.setData(
                                    'site_specific_hs_plan',
                                    e.target.value,
                                )
                            }
                            placeholder="Access controls, lockout process, site risks..."
                        />
                        <FieldError
                            message={form.errors.site_specific_hs_plan}
                        />
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
                                form.setData(
                                    'qualifications_notes',
                                    e.target.value,
                                )
                            }
                            placeholder="Licences sighted, expiry notes, restrictions..."
                        />
                        <FieldError
                            message={form.errors.qualifications_notes}
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="v-notes">Notes</Label>
                        <Textarea
                            id="v-notes"
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            placeholder="Non-sensitive site or service notes…"
                        />
                        <FieldError message={form.errors.notes} />
                    </div>
                </>
            )}
        </>
    );
}
