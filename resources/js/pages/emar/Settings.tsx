import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    Pencil,
    Plus,
    Settings2,
    ShieldCheck,
    Stethoscope,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

type Option = { value: string; label: string };

type Rule = {
    id: number;
    site_id: number | null;
    site_name: string | null;
    match_type: string;
    match_value: string;
    requires_countersign: boolean;
    required_observations: string[];
    active: boolean;
    created_by: string | null;
    created_at: string | null;
};

type Props = {
    rules: Rule[];
    sites: { id: number; name: string }[];
    observationOptions: Option[];
    matchTypes: Option[];
    can: { manage: boolean };
};

const GLOBAL_SITE = 'global';

type FormState = {
    site_id: string;
    match_type: string;
    match_value: string;
    requires_countersign: boolean;
    required_observations: string[];
    active: boolean;
};

function blankForm(matchTypes: Option[]): FormState {
    return {
        site_id: GLOBAL_SITE,
        match_type: matchTypes[0]?.value ?? 'medicine_name',
        match_value: '',
        requires_countersign: true,
        required_observations: [],
        active: true,
    };
}

export default function EmarSettings({
    rules,
    sites,
    observationOptions,
    matchTypes,
    can,
}: Props) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Rule | null>(null);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState<FormState>(blankForm(matchTypes));

    const matchTypeLabel = (value: string) =>
        matchTypes.find((t) => t.value === value)?.label ?? value;
    const observationLabel = (value: string) =>
        observationOptions.find((o) => o.value === value)?.label ?? value;

    function openCreate() {
        setEditing(null);
        setForm(blankForm(matchTypes));
        setOpen(true);
    }

    function openEdit(rule: Rule) {
        setEditing(rule);
        setForm({
            site_id: rule.site_id ? rule.site_id.toString() : GLOBAL_SITE,
            match_type: rule.match_type,
            match_value: rule.match_value,
            requires_countersign: rule.requires_countersign,
            required_observations: rule.required_observations ?? [],
            active: rule.active,
        });
        setOpen(true);
    }

    function toggleObservation(value: string) {
        setForm((current) => ({
            ...current,
            required_observations: current.required_observations.includes(value)
                ? current.required_observations.filter((o) => o !== value)
                : [...current.required_observations, value],
        }));
    }

    function submit() {
        if (!form.match_value.trim()) {
            toast.error(
                'Enter a keyword to match (e.g. Warfarin, Intravenous).',
            );
            return;
        }
        if (
            !form.requires_countersign &&
            form.required_observations.length === 0
        ) {
            toast.error(
                'A rule must require a countersignature and/or at least one observation.',
            );
            return;
        }

        const payload = {
            site_id: form.site_id === GLOBAL_SITE ? null : Number(form.site_id),
            match_type: form.match_type,
            match_value: form.match_value.trim(),
            requires_countersign: form.requires_countersign,
            required_observations: form.required_observations,
            active: form.active,
        };

        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            router.put(`/emar/settings/rules/${editing.id}`, payload, options);
        } else {
            router.post('/emar/settings/rules', payload, options);
        }
    }

    function remove(rule: Rule) {
        if (!window.confirm(`Remove the rule for "${rule.match_value}"?`))
            return;
        router.delete(`/emar/settings/rules/${rule.id}`, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Administration Rules" />
            <PageHero
                icon={Settings2}
                title="Medication Administration Rules"
                description="Require a countersignature or a clinical observation (BSL, pulse, blood pressure) when a medication name, route, or NZULM code matches a keyword. Rules apply automatically at the point of administration."
                backHref="/emar"
                backLabel="Back to eMAR"
            />
            <PageShell>
                <div className="mb-4 flex items-center justify-between gap-4">
                    <p className="text-sm text-muted-foreground">
                        {rules.length} rule{rules.length === 1 ? '' : 's'}{' '}
                        configured
                    </p>
                    {can.manage && (
                        <Button onClick={openCreate} size="sm">
                            <Plus className="mr-1.5 h-4 w-4" />
                            Add Rule
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">
                            Active &amp; inactive rules
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                        <th className="pr-4 pb-2">Match</th>
                                        <th className="pr-4 pb-2">Keyword</th>
                                        <th className="pr-4 pb-2">Scope</th>
                                        <th className="pr-4 pb-2">
                                            Requirements
                                        </th>
                                        <th className="pr-4 pb-2">Status</th>
                                        {can.manage && (
                                            <th className="pb-2 text-right">
                                                Actions
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rules.map((rule) => (
                                        <tr
                                            key={rule.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2.5 pr-4 font-medium">
                                                {matchTypeLabel(
                                                    rule.match_type,
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                {rule.match_value}
                                            </td>
                                            <td className="py-2.5 pr-4 text-muted-foreground">
                                                {rule.site_name ?? 'All sites'}
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <div className="flex flex-wrap gap-1.5">
                                                    {rule.requires_countersign && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="gap-1"
                                                        >
                                                            <ShieldCheck className="h-3 w-3" />{' '}
                                                            Countersign
                                                        </Badge>
                                                    )}
                                                    {rule.required_observations.map(
                                                        (obs) => (
                                                            <Badge
                                                                key={obs}
                                                                variant="outline"
                                                                className="gap-1"
                                                            >
                                                                <Stethoscope className="h-3 w-3" />
                                                                {observationLabel(
                                                                    obs,
                                                                )}
                                                            </Badge>
                                                        ),
                                                    )}
                                                    {!rule.requires_countersign &&
                                                        rule
                                                            .required_observations
                                                            .length === 0 && (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                </div>
                                            </td>
                                            <td className="py-2.5 pr-4">
                                                <Badge
                                                    variant={
                                                        rule.active
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                >
                                                    {rule.active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </td>
                                            {can.manage && (
                                                <td className="py-2.5 text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="h-8 w-8"
                                                            aria-label="Edit rule"
                                                            onClick={() =>
                                                                openEdit(rule)
                                                            }
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="h-8 w-8 text-status-critical"
                                                            aria-label="Remove rule"
                                                            onClick={() =>
                                                                remove(rule)
                                                            }
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </Button>
                                                    </div>
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                    {rules.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={can.manage ? 6 : 5}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                No administration rules yet. Add
                                                one to require countersigning or
                                                observations for matching
                                                medications.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Edit rule' : 'Add administration rule'}
                        </DialogTitle>
                        <DialogDescription>
                            Matching is case-insensitive. A name/route rule
                            matches when the medication contains the keyword; an
                            NZULM rule matches the exact code.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Match on</Label>
                            <div className="grid grid-cols-3 gap-2">
                                {matchTypes.map((type) => (
                                    <Button
                                        key={type.value}
                                        type="button"
                                        variant={
                                            form.match_type === type.value
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        onClick={() =>
                                            setForm((c) => ({
                                                ...c,
                                                match_type: type.value,
                                            }))
                                        }
                                    >
                                        {type.label}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="match-value">Keyword</Label>
                            <Input
                                id="match-value"
                                value={form.match_value}
                                onChange={(e) =>
                                    setForm((c) => ({
                                        ...c,
                                        match_value: e.target.value,
                                    }))
                                }
                                placeholder={
                                    form.match_type === 'route'
                                        ? 'e.g. Intravenous'
                                        : form.match_type === 'nzulm_code'
                                          ? 'e.g. a12345'
                                          : 'e.g. Warfarin'
                                }
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Applies to</Label>
                            <Select
                                value={form.site_id}
                                onValueChange={(value) =>
                                    setForm((c) => ({ ...c, site_id: value }))
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={GLOBAL_SITE}>
                                        All sites (global)
                                    </SelectItem>
                                    {sites.map((site) => (
                                        <SelectItem
                                            key={site.id}
                                            value={site.id.toString()}
                                        >
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex items-center justify-between rounded-md border p-3">
                            <div>
                                <p className="text-sm font-medium">
                                    Require countersignature
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    A second checker must authenticate at
                                    administration.
                                </p>
                            </div>
                            <Switch
                                checked={form.requires_countersign}
                                onCheckedChange={(checked) =>
                                    setForm((c) => ({
                                        ...c,
                                        requires_countersign: checked,
                                    }))
                                }
                            />
                        </div>

                        <div className="space-y-2 rounded-md border p-3">
                            <p className="text-sm font-medium">
                                Require observation at sign-off
                            </p>
                            {observationOptions.map((obs) => (
                                <label
                                    key={obs.value}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <Checkbox
                                        checked={form.required_observations.includes(
                                            obs.value,
                                        )}
                                        onCheckedChange={() =>
                                            toggleObservation(obs.value)
                                        }
                                    />
                                    {obs.label}
                                </label>
                            ))}
                        </div>

                        <div className="flex items-center justify-between rounded-md border p-3">
                            <p className="text-sm font-medium">Active</p>
                            <Switch
                                checked={form.active}
                                onCheckedChange={(checked) =>
                                    setForm((c) => ({ ...c, active: checked }))
                                }
                            />
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                            disabled={saving}
                        >
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={saving}>
                            {editing ? 'Save changes' : 'Add rule'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
