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
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { Loader2, Mail, Pencil, Phone, Star, Trash2 } from 'lucide-react';
import { useState, type ComponentType } from 'react';
import {
    CONTACT_TYPES,
    getContactType,
    type ContactTypeDef,
    type ContactTypeKey,
} from './_helpers';
import { Card as GuardrailCard } from '@/components/ui/card';

// Re-export so existing call sites that imported these from `_dialogs` keep
// working without changes. The single source of truth lives in `_helpers.ts`.
export { CONTACT_TYPES, getContactType };
export type { ContactTypeDef, ContactTypeKey };

// ── Form value shape ──────────────────────────────────────────────────────

export type ContactFormValues = {
    type: ContactTypeKey | string;
    name: string;
    role: string;
    phone: string;
    email: string;
    is_primary: boolean;
    notes: string;
};

export type ContactRecord = {
    id: number;
    type?: string | null;
    name: string;
    role?: string | null;
    phone?: string | null;
    email?: string | null;
    is_primary: boolean;
    notes?: string | null;
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

// ── Type picker (Send Kudos-style category grid) ──────────────────────────

function ContactTypePicker({
    value,
    onChange,
}: {
    value: string;
    onChange: (v: ContactTypeKey) => void;
}) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {CONTACT_TYPES.map((t) => {
                const Icon = t.icon;
                const active = value === t.key;
                return (
                    <Button unstyled
                        key={t.key}
                        type="button"
                        onClick={() => onChange(t.key)}
                        className={cn(
                            'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all',
                            'hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                    >
                        <span
                            className={cn(
                                'mt-0.5 shrink-0 rounded-lg p-1.5',
                                'bg-background/60',
                            )}
                        >
                            <Icon className={cn('h-4 w-4', t.accent)} />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">
                                {t.label}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {t.description}
                            </span>
                        </span>
                    </Button>
                );
            })}
        </div>
    );
}

// ── Shared field group ────────────────────────────────────────────────────

function ContactFields({
    form,
    lockType = false,
}: {
    form: ReturnType<typeof useForm<ContactFormValues>>;
    lockType?: boolean;
}) {
    const selectedType = getContactType(form.data.type);
    const SelectedIcon = selectedType.icon;

    return (
        <div className="space-y-4">
            <div>
                <Label className="mb-2 block">
                    Contact type{' '}
                    <span className="text-status-critical">*</span>
                </Label>
                {lockType ? (
                    <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                        <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                            <SelectedIcon
                                className={cn('h-4 w-4', selectedType.accent)}
                            />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-medium">
                                    {selectedType.label}
                                </span>
                                <Badge variant="outline" className="text-[10px]">
                                    From Overview
                                </Badge>
                            </div>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                This role is locked for the Overview row you
                                opened.
                            </p>
                        </div>
                    </div>
                ) : (
                    <ContactTypePicker
                        value={form.data.type}
                        onChange={(v) => form.setData('type', v)}
                    />
                )}
                <FieldError message={form.errors.type} />
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Label htmlFor="c-name">
                        Name <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="c-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Full name"
                        required
                    />
                    <FieldError message={form.errors.name} />
                </div>

                <div className="sm:col-span-2">
                    <Label htmlFor="c-role">Role / title</Label>
                    <Input
                        id="c-role"
                        value={form.data.role}
                        onChange={(e) => form.setData('role', e.target.value)}
                        placeholder="e.g. Service Manager, RN, Father"
                    />
                    <FieldError message={form.errors.role} />
                </div>

                <div>
                    <Label htmlFor="c-phone">Phone</Label>
                    <Input
                        id="c-phone"
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                        placeholder="+64 21 …"
                    />
                    <FieldError message={form.errors.phone} />
                </div>

                <div>
                    <Label htmlFor="c-email">Email</Label>
                    <Input
                        id="c-email"
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    <FieldError message={form.errors.email} />
                </div>

                <div className="flex items-center gap-2 sm:col-span-2">
                    <Checkbox
                        id="c-primary"
                        checked={form.data.is_primary}
                        onCheckedChange={(checked) =>
                            form.setData('is_primary', !!checked)
                        }
                    />
                    <Label
                        htmlFor="c-primary"
                        className="text-sm font-normal"
                    >
                        Mark as primary contact for this site
                    </Label>
                </div>

                <div className="sm:col-span-2">
                    <Label htmlFor="c-notes">Notes</Label>
                    <Textarea
                        id="c-notes"
                        rows={3}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder="Availability, languages, additional context…"
                    />
                    <FieldError message={form.errors.notes} />
                </div>
            </div>
        </div>
    );
}

// ── Add ───────────────────────────────────────────────────────────────────

export function AddContactDialog({
    siteId,
    isOpen,
    onClose,
    type,
    lockType = false,
}: {
    siteId: number;
    isOpen: boolean;
    onClose: () => void;
    type?: ContactTypeKey | string | null;
    lockType?: boolean;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && (
                    <AddContactBody
                        siteId={siteId}
                        onClose={onClose}
                        type={type}
                        lockType={lockType}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function AddContactBody({
    siteId,
    onClose,
    type,
    lockType,
}: {
    siteId: number;
    onClose: () => void;
    type?: ContactTypeKey | string | null;
    lockType: boolean;
}) {
    const form = useForm<ContactFormValues>({
        type: type ?? 'site_contact',
        name: '',
        role: '',
        phone: '',
        email: '',
        is_primary: false,
        notes: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${siteId}/contacts`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Star className="h-4 w-4 text-primary" />
                    New Site Contact
                </DialogTitle>
                <DialogDescription>
                    Pick a contact type, then fill in the details.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3">
                <ContactFields form={form} lockType={lockType} />
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Save contact
                </Button>
            </DialogFooter>
        </form>
    );
}

// ── Edit ──────────────────────────────────────────────────────────────────

export function EditContactDialog({
    siteId,
    contact,
    isOpen,
    onClose,
}: {
    siteId: number;
    contact: ContactRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-xl">
                {isOpen && contact && (
                    <EditContactBody
                        siteId={siteId}
                        contact={contact}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function EditContactBody({
    siteId,
    contact,
    onClose,
}: {
    siteId: number;
    contact: ContactRecord;
    onClose: () => void;
}) {
    const form = useForm<ContactFormValues>({
        type: (getContactType(contact.type).key ?? 'site_contact') as ContactTypeKey,
        name: contact.name ?? '',
        role: contact.role ?? '',
        phone: contact.phone ?? '',
        email: contact.email ?? '',
        is_primary: !!contact.is_primary,
        notes: contact.notes ?? '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/sites/${siteId}/contacts/${contact.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle>Edit contact</DialogTitle>
                <DialogDescription>
                    Update the contact's details.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3">
                <ContactFields form={form} />
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

// ── Show / Read-only ──────────────────────────────────────────────────────

export function ShowContactDialog({
    contact,
    isOpen,
    canManage,
    onClose,
    onEdit,
    onDelete,
}: {
    contact: ContactRecord | null;
    isOpen: boolean;
    canManage: boolean;
    onClose: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
}) {
    if (!contact) {
        return (
            <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="max-w-md" />
            </Dialog>
        );
    }
    const type = getContactType(contact.type);
    const Icon = type.icon;
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <div className="flex items-center gap-3">
                        <span className="rounded-xl border bg-background/60 p-2">
                            <Icon className={cn('h-5 w-5', type.accent)} />
                        </span>
                        <div className="min-w-0 flex-1">
                            <DialogTitle className="truncate">
                                {contact.name}
                            </DialogTitle>
                            <DialogDescription className="flex flex-wrap items-center gap-2">
                                <span>{type.label}</span>
                                {contact.role && (
                                    <span className="text-muted-foreground">
                                        · {contact.role}
                                    </span>
                                )}
                                {contact.is_primary && (
                                    <Badge
                                        variant="outline"
                                        className="border-status-success/30 text-status-success"
                                    >
                                        Primary
                                    </Badge>
                                )}
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="space-y-2 text-sm">
                    <ContactDetailRow
                        icon={Phone}
                        label="Phone"
                        value={contact.phone}
                        href={contact.phone ? `tel:${contact.phone}` : undefined}
                    />
                    <ContactDetailRow
                        icon={Mail}
                        label="Email"
                        value={contact.email}
                        href={contact.email ? `mailto:${contact.email}` : undefined}
                    />
                    {contact.notes && (
                        <div className="rounded-lg border bg-muted/30 p-3">
                            <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">
                                Notes
                            </p>
                            <p className="whitespace-pre-wrap">{contact.notes}</p>
                        </div>
                    )}
                </div>

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
                    <Button type="button" variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {canManage && onEdit && (
                        <Button type="button" onClick={onEdit}>
                            <Pencil className="mr-2 h-4 w-4" />
                            Edit
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ContactDetailRow({
    icon: Icon,
    label,
    value,
    href,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value?: string | null;
    href?: string;
}) {
    return (
        <GuardrailCard unstyled className="flex items-center gap-3 rounded-lg border bg-background/40 px-3 py-2">
            <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <p className="text-xs text-muted-foreground">{label}</p>
                {value ? (
                    href ? (
                        <a
                            href={href}
                            className="truncate text-sm hover:underline"
                        >
                            {value}
                        </a>
                    ) : (
                        <p className="truncate text-sm">{value}</p>
                    )
                ) : (
                    <p className="text-sm text-muted-foreground">—</p>
                )}
            </div>
        </GuardrailCard>
    );
}

// ── Confirm delete ────────────────────────────────────────────────────────

export function DeleteContactDialog({
    siteId,
    contact,
    isOpen,
    onClose,
}: {
    siteId: number;
    contact: ContactRecord | null;
    isOpen: boolean;
    onClose: () => void;
}) {
    const [submitting, setSubmitting] = useState(false);

    const handleDelete = () => {
        if (!contact) return;
        setSubmitting(true);
        router.delete(`/sites/${siteId}/contacts/${contact.id}`, {
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
                    <DialogTitle>Delete contact?</DialogTitle>
                    <DialogDescription>
                        {contact && (
                            <>
                                <span className="font-medium">
                                    {contact.name}
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
                        Delete contact
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
