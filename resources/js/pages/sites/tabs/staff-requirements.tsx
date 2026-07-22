import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import {
    Award,
    BadgeCheck,
    GraduationCap,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

type StaffRequirement = {
    id: number;
    name: string;
    category?: string | null;
    description?: string | null;
    certification_required: boolean;
    expiry_period_months?: number | null;
};

export type SiteStaffRequirementsData = {
    locked: boolean;
    can_manage: boolean;
    items: StaffRequirement[];
};

type RequirementForm = {
    requirement_name: string;
    category: 'mandatory' | 'recommended' | 'specialist';
    description: string;
    certification_required: boolean;
    expiry_period_months: string;
};

const EMPTY_FORM: RequirementForm = {
    requirement_name: '',
    category: 'mandatory',
    description: '',
    certification_required: false,
    expiry_period_months: '',
};

export function SiteProfileStaffRequirements({
    siteId,
    data,
}: {
    siteId: number;
    data: SiteStaffRequirementsData;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<StaffRequirement | null>(null);
    const [deleting, setDeleting] = useState<StaffRequirement | null>(null);
    const form = useForm<RequirementForm>(EMPTY_FORM);
    const grouped = useMemo(
        () =>
            data.items.reduce<Record<string, StaffRequirement[]>>(
                (groups, item) => {
                    const key = item.category || 'mandatory';
                    groups[key] = [...(groups[key] ?? []), item];
                    return groups;
                },
                {},
            ),
        [data.items],
    );

    if (data.locked) {
        return <SiteProfileLockedState label="Staff requirements" />;
    }

    const openCreate = () => {
        setEditing(null);
        form.setData(EMPTY_FORM);
        form.clearErrors();
        setDialogOpen(true);
    };

    const openEdit = (requirement: StaffRequirement) => {
        setEditing(requirement);
        form.setData({
            requirement_name: requirement.name,
            category: (requirement.category ||
                'mandatory') as RequirementForm['category'],
            description: requirement.description || '',
            certification_required: requirement.certification_required,
            expiry_period_months: requirement.expiry_period_months
                ? String(requirement.expiry_period_months)
                : '',
        });
        form.clearErrors();
        setDialogOpen(true);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                router.reload({
                    only: ['staffRequirementsData'],
                    preserveScroll: true,
                });
            },
        };
        if (editing) {
            form.put(
                `/sites/${siteId}/staff-requirements/${editing.id}`,
                options,
            );
        } else {
            form.post(`/sites/${siteId}/staff-requirements`, options);
        }
    };

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">
                        Staff requirements
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Skills and certifications staff need before working at
                        this Site.
                    </p>
                </div>
                {data.can_manage ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={openCreate}
                    >
                        <Plus className="mr-2 h-4 w-4" /> Add requirement
                    </Button>
                ) : null}
            </div>

            {data.items.length ? (
                <div className="space-y-4">
                    {(['mandatory', 'recommended', 'specialist'] as const).map(
                        (category) => {
                            const items = grouped[category] ?? [];
                            if (!items.length) return null;
                            return (
                                <Card key={category}>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base capitalize">
                                            {category === 'mandatory' ? (
                                                <BadgeCheck className="h-4 w-4" />
                                            ) : (
                                                <GraduationCap className="h-4 w-4" />
                                            )}
                                            {category}
                                            <Badge variant="outline">
                                                {items.length}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="divide-y">
                                        {items.map((requirement) => (
                                            <div
                                                key={requirement.id}
                                                className="flex flex-wrap items-start gap-3 py-3"
                                            >
                                                <span className="rounded-lg bg-primary/10 p-2 text-primary">
                                                    {requirement.certification_required ? (
                                                        <Award className="h-5 w-5" />
                                                    ) : (
                                                        <GraduationCap className="h-5 w-5" />
                                                    )}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-semibold">
                                                        {requirement.name}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {requirement.description ||
                                                            'No additional guidance recorded.'}
                                                    </p>
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        {requirement.certification_required ? (
                                                            <Badge variant="outline">
                                                                Certification
                                                                required
                                                            </Badge>
                                                        ) : null}
                                                        {requirement.expiry_period_months ? (
                                                            <Badge variant="outline">
                                                                Renew every{' '}
                                                                {
                                                                    requirement.expiry_period_months
                                                                }{' '}
                                                                months
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                </div>
                                                {data.can_manage ? (
                                                    <div className="flex gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="min-h-11"
                                                            onClick={() =>
                                                                openEdit(
                                                                    requirement,
                                                                )
                                                            }
                                                        >
                                                            <Pencil className="mr-2 h-4 w-4" />{' '}
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="min-h-11 text-status-critical"
                                                            onClick={() =>
                                                                setDeleting(
                                                                    requirement,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" />{' '}
                                                            Remove
                                                        </Button>
                                                    </div>
                                                ) : null}
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            );
                        },
                    )}
                </div>
            ) : (
                <SiteProfileEmptyState
                    icon={GraduationCap}
                    title="No staff requirements recorded"
                    description="Record mandatory, recommended, and specialist competency requirements."
                    action={
                        data.can_manage
                            ? { label: 'Add requirement', onClick: openCreate }
                            : undefined
                    }
                />
            )}

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editing
                                ? 'Edit staff requirement'
                                : 'Add staff requirement'}
                        </DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label htmlFor="staff-requirement-name">
                                Requirement name
                            </Label>
                            <Input
                                id="staff-requirement-name"
                                value={form.data.requirement_name}
                                onChange={(event) =>
                                    form.setData(
                                        'requirement_name',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            {form.errors.requirement_name ? (
                                <p className="text-xs text-status-critical">
                                    {form.errors.requirement_name}
                                </p>
                            ) : null}
                        </div>
                        <div>
                            <Label>Category</Label>
                            <Select
                                value={form.data.category}
                                onValueChange={(value) =>
                                    form.setData(
                                        'category',
                                        value as RequirementForm['category'],
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="mandatory">
                                        Mandatory
                                    </SelectItem>
                                    <SelectItem value="recommended">
                                        Recommended
                                    </SelectItem>
                                    <SelectItem value="specialist">
                                        Specialist
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="staff-requirement-description">
                                Description
                            </Label>
                            <Textarea
                                id="staff-requirement-description"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="flex items-center justify-between rounded-lg border p-3">
                            <Label htmlFor="staff-requirement-certification">
                                Certification required
                            </Label>
                            <Switch
                                id="staff-requirement-certification"
                                checked={form.data.certification_required}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'certification_required',
                                        checked,
                                    )
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="staff-requirement-expiry">
                                Expiry period (months)
                            </Label>
                            <Input
                                id="staff-requirement-expiry"
                                type="number"
                                min={1}
                                value={form.data.expiry_period_months}
                                onChange={(event) =>
                                    form.setData(
                                        'expiry_period_months',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => setDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={form.processing}
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : 'Save requirement'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={() => {
                    if (!deleting) return;
                    router.delete(
                        `/sites/${siteId}/staff-requirements/${deleting.id}`,
                        {
                            preserveScroll: true,
                            onSuccess: () =>
                                router.reload({
                                    only: ['staffRequirementsData'],
                                    preserveScroll: true,
                                }),
                        },
                    );
                }}
                title="Remove staff requirement?"
                description={`${deleting?.name ?? 'This requirement'} will no longer apply to this Site.`}
                confirmText="Remove requirement"
            />
        </div>
    );
}
