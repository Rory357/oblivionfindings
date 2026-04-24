import FleetHero from '@/components/fleet-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ClipboardCheck, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

type Assessment = {
    id: number;
    status: string;
    outcome: string | null;
    cognitive_capacity: number | null;
    physical_dexterity: number | null;
    vision_ability: number | null;
    swallowing_ability: number | null;
    understanding_score: number | null;
    can_identify_medications: boolean;
    can_read_labels: boolean;
    can_open_packaging: boolean;
    can_manage_timing: boolean;
    can_store_safely: boolean;
    willing_to_self_admin: boolean;
    assessment_date: string | null;
    reassessment_date: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    assessor: { id: number; name: string } | null;
};

type Props = {
    assessments: { data: Assessment[]; links: any };
    dueReassessments: Assessment[];
    clients: { id: number; first_name: string; last_name: string }[];
    filters: { client_id?: string };
};

const outcomeLabels: Record<string, { label: string; color: string }> = {
    independent: {
        label: 'Cat 1: Independent',
        color: 'bg-status-success-bg text-status-success',
    },
    prompted: {
        label: 'Cat 2: Prompted',
        color: 'bg-status-info-bg text-status-info',
    },
    supervised: {
        label: 'Cat 3: Supervised',
        color: 'bg-status-warning-bg text-status-warning',
    },
    administered: {
        label: 'Cat 4: Staff Administered',
        color: 'bg-status-critical-bg text-status-critical',
    },
};

const SCORE_CRITERIA = [
    { key: 'cognitive_capacity', label: 'Cognitive Capacity' },
    { key: 'physical_dexterity', label: 'Physical Dexterity' },
    { key: 'vision_ability', label: 'Vision Ability' },
    { key: 'swallowing_ability', label: 'Swallowing Ability' },
    { key: 'understanding_score', label: 'Understanding Score' },
] as const;

const BOOLEAN_CHECKS = [
    { key: 'can_identify_medications', label: 'Can identify medications' },
    { key: 'can_read_labels', label: 'Can read labels' },
    { key: 'can_open_packaging', label: 'Can open packaging' },
    { key: 'can_manage_timing', label: 'Can manage timing' },
    { key: 'can_store_safely', label: 'Can store safely' },
    { key: 'willing_to_self_admin', label: 'Willing to self-administer' },
] as const;

function computeOutcome(
    scores: Record<string, number>,
    booleans: Record<string, boolean>,
): string {
    const scoreValues = SCORE_CRITERIA.map((c) => scores[c.key] ?? 0);
    const avgScore =
        scoreValues.reduce((a, b) => a + b, 0) / scoreValues.length;
    const allBooleans = BOOLEAN_CHECKS.every((c) => booleans[c.key]);

    if (avgScore >= 4 && allBooleans) return 'independent';
    if (avgScore >= 3 && allBooleans) return 'prompted';
    if (avgScore >= 2) return 'supervised';
    return 'administered';
}

function ScoreBar({ value, max = 5 }: { value: number | null; max?: number }) {
    if (value === null) return <span className="text-muted-foreground">—</span>;
    const pct = (value / max) * 100;
    const color =
        pct >= 80
            ? 'bg-status-success'
            : pct >= 60
              ? 'bg-status-warning'
              : 'bg-status-critical';
    return (
        <div className="flex items-center gap-2">
            <div className="h-2 w-16 rounded-full bg-muted">
                <div
                    className={`h-2 rounded-full ${color}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="text-xs">
                {value}/{max}
            </span>
        </div>
    );
}

export default function SelfAdmin({
    assessments,
    dueReassessments,
    clients,
    filters,
}: Props) {
    const [open, setOpen] = useState(false);

    const form = useForm({
        client_id: '',
        cognitive_capacity: 3,
        physical_dexterity: 3,
        vision_ability: 3,
        swallowing_ability: 3,
        understanding_score: 3,
        can_identify_medications: false,
        can_read_labels: false,
        can_open_packaging: false,
        can_manage_timing: false,
        can_store_safely: false,
        willing_to_self_admin: false,
        risk_factors: '',
        support_needed: '',
        safe_storage_notes: '',
        assessor_notes: '',
        reassessment_date: '',
    });

    const computedOutcome = useMemo(() => {
        const scores: Record<string, number> = {};
        SCORE_CRITERIA.forEach((c) => {
            scores[c.key] = form.data[c.key] as number;
        });
        const booleans: Record<string, boolean> = {};
        BOOLEAN_CHECKS.forEach((c) => {
            booleans[c.key] = form.data[c.key] as boolean;
        });
        return computeOutcome(scores, booleans);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- Only these form fields participate in the computed outcome.
    }, [
        form.data.cognitive_capacity,
        form.data.physical_dexterity,
        form.data.vision_ability,
        form.data.swallowing_ability,
        form.data.understanding_score,
        form.data.can_identify_medications,
        form.data.can_read_labels,
        form.data.can_open_packaging,
        form.data.can_manage_timing,
        form.data.can_store_safely,
        form.data.willing_to_self_admin,
    ]);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post('/emar/self-admin', {
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    }

    return (
        <AppLayout>
            <Head title="eMAR - Self-Administration Assessments" />
            <div className="flex flex-col gap-6 p-6">
                <FleetHero
                    title="Self-Administration Assessments"
                    description="Assess client capacity for self-medication per NZ MOH medication support categories"
                    icon={<ClipboardCheck className="h-7 w-7 text-white" />}
                    backHref="/emar"
                    backLabel="Back"
                />
                {/* Due Reassessments */}
                {dueReassessments.length > 0 && (
                    <Card className="mb-6 border-status-warning/30 dark:border-status-warning/30">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base text-status-warning dark:text-status-warning">
                                <AlertTriangle className="h-4 w-4" />{' '}
                                Reassessments Due ({dueReassessments.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y">
                                {dueReassessments.map((a) => (
                                    <div
                                        key={a.id}
                                        className="flex items-center justify-between p-3"
                                    >
                                        <span className="font-medium">
                                            {a.client?.last_name},{' '}
                                            {a.client?.first_name}
                                        </span>
                                        <span className="text-xs text-status-warning">
                                            Due:{' '}
                                            {a.reassessment_date
                                                ? new Date(
                                                      a.reassessment_date,
                                                  ).toLocaleDateString('en-NZ')
                                                : '—'}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filter & New Assessment */}
                <div className="mb-4 flex items-center gap-3">
                    <Select
                        value={filters.client_id ?? ''}
                        onValueChange={(v) =>
                            router.get(
                                '/emar/self-admin',
                                { client_id: v || undefined },
                                { preserveState: true },
                            )
                        }
                    >
                        <SelectTrigger className="w-56">
                            <SelectValue placeholder="All clients" />
                        </SelectTrigger>
                        <SelectContent>
                            {clients.map((c) => (
                                <SelectItem key={c.id} value={c.id.toString()}>
                                    {c.last_name}, {c.first_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="ml-auto">
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button size="sm">
                                    <Plus className="mr-1 h-4 w-4" /> New
                                    Assessment
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                                <DialogHeader>
                                    <DialogTitle>
                                        New Self-Administration Assessment
                                    </DialogTitle>
                                    <DialogDescription>
                                        Assess the client&apos;s ability to
                                        self-administer medication and record
                                        any support needs.
                                    </DialogDescription>
                                </DialogHeader>
                                <form onSubmit={submit} className="space-y-5">
                                    {/* Client */}
                                    <div>
                                        <Label>Client</Label>
                                        <Select
                                            value={form.data.client_id}
                                            onValueChange={(v) =>
                                                form.setData('client_id', v)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select client" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {clients.map((c) => (
                                                    <SelectItem
                                                        key={c.id}
                                                        value={c.id.toString()}
                                                    >
                                                        {c.last_name},{' '}
                                                        {c.first_name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.client_id && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {form.errors.client_id}
                                            </p>
                                        )}
                                    </div>

                                    {/* Scored Criteria */}
                                    <div>
                                        <Label className="mb-2 block font-semibold">
                                            Scored Criteria (1-5)
                                        </Label>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {SCORE_CRITERIA.map((c) => (
                                                <div key={c.key}>
                                                    <Label
                                                        htmlFor={`score-${c.key}`}
                                                        className="text-xs"
                                                    >
                                                        {c.label}
                                                    </Label>
                                                    <Input
                                                        id={`score-${c.key}`}
                                                        type="number"
                                                        min={1}
                                                        max={5}
                                                        value={form.data[c.key]}
                                                        onChange={(e) =>
                                                            form.setData(
                                                                c.key as any,
                                                                parseInt(
                                                                    e.target
                                                                        .value,
                                                                ) || 1,
                                                            )
                                                        }
                                                    />
                                                    {(form.errors as any)[
                                                        c.key
                                                    ] && (
                                                        <p className="mt-1 text-xs text-status-critical">
                                                            {
                                                                (
                                                                    form.errors as any
                                                                )[c.key]
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Boolean Checks */}
                                    <div>
                                        <Label className="mb-2 block font-semibold">
                                            Capability Checks
                                        </Label>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {BOOLEAN_CHECKS.map((c) => (
                                                <label
                                                    key={c.key}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <Checkbox
                                                        checked={
                                                            form.data[
                                                                c.key
                                                            ] as boolean
                                                        }
                                                        onCheckedChange={(v) =>
                                                            form.setData(
                                                                c.key as any,
                                                                !!v,
                                                            )
                                                        }
                                                    />
                                                    {c.label}
                                                </label>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Auto-computed outcome */}
                                    <div className="rounded-md border p-3">
                                        <Label className="mb-1 block text-xs font-semibold text-muted-foreground">
                                            Computed Outcome
                                        </Label>
                                        {outcomeLabels[computedOutcome] && (
                                            <Badge
                                                className={`${outcomeLabels[computedOutcome].color}`}
                                            >
                                                {
                                                    outcomeLabels[
                                                        computedOutcome
                                                    ].label
                                                }
                                            </Badge>
                                        )}
                                    </div>

                                    {/* Text Areas */}
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <Label htmlFor="sa-risk">
                                                Risk Factors
                                            </Label>
                                            <Textarea
                                                id="sa-risk"
                                                rows={2}
                                                value={form.data.risk_factors}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'risk_factors',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="sa-support">
                                                Support Needed
                                            </Label>
                                            <Textarea
                                                id="sa-support"
                                                rows={2}
                                                value={form.data.support_needed}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'support_needed',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="sa-storage">
                                                Safe Storage Notes
                                            </Label>
                                            <Textarea
                                                id="sa-storage"
                                                rows={2}
                                                value={
                                                    form.data.safe_storage_notes
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'safe_storage_notes',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="sa-notes">
                                                Assessor Notes
                                            </Label>
                                            <Textarea
                                                id="sa-notes"
                                                rows={2}
                                                value={form.data.assessor_notes}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'assessor_notes',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>

                                    {/* Reassessment Date */}
                                    <div>
                                        <Label htmlFor="sa-redate">
                                            Reassessment Date
                                        </Label>
                                        <Input
                                            id="sa-redate"
                                            type="date"
                                            value={form.data.reassessment_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'reassessment_date',
                                                    e.target.value,
                                                )
                                            }
                                            className="w-48"
                                        />
                                        {form.errors.reassessment_date && (
                                            <p className="mt-1 text-xs text-status-critical">
                                                {form.errors.reassessment_date}
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex justify-end gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setOpen(false)}
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            Save Assessment
                                        </Button>
                                    </div>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                {/* Assessments */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b bg-muted/50">
                                    <th className="p-3 text-left font-medium">
                                        Client
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Date
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Outcome
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Cognitive
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Dexterity
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Vision
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Understanding
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Reassessment
                                    </th>
                                    <th className="p-3 text-left font-medium">
                                        Assessor
                                    </th>
                                    <th className="p-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {assessments.data.map((a) => {
                                    const outcomeCfg = a.outcome
                                        ? outcomeLabels[a.outcome]
                                        : null;
                                    return (
                                        <tr
                                            key={a.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="p-3 font-medium">
                                                {a.client?.last_name},{' '}
                                                {a.client?.first_name}
                                            </td>
                                            <td className="p-3 text-xs">
                                                {a.assessment_date
                                                    ? new Date(
                                                          a.assessment_date,
                                                      ).toLocaleDateString(
                                                          'en-NZ',
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="p-3">
                                                {outcomeCfg ? (
                                                    <Badge
                                                        className={`text-xs ${outcomeCfg.color}`}
                                                    >
                                                        {outcomeCfg.label}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-3">
                                                <ScoreBar
                                                    value={a.cognitive_capacity}
                                                />
                                            </td>
                                            <td className="p-3">
                                                <ScoreBar
                                                    value={a.physical_dexterity}
                                                />
                                            </td>
                                            <td className="p-3">
                                                <ScoreBar
                                                    value={a.vision_ability}
                                                />
                                            </td>
                                            <td className="p-3">
                                                <ScoreBar
                                                    value={
                                                        a.understanding_score
                                                    }
                                                />
                                            </td>
                                            <td className="p-3 text-xs">
                                                {a.reassessment_date
                                                    ? new Date(
                                                          a.reassessment_date,
                                                      ).toLocaleDateString(
                                                          'en-NZ',
                                                      )
                                                    : '—'}
                                                {a.reassessment_date &&
                                                    new Date(
                                                        a.reassessment_date,
                                                    ) < new Date() && (
                                                        <Badge
                                                            variant="destructive"
                                                            className="ml-1 text-[10px]"
                                                        >
                                                            Due
                                                        </Badge>
                                                    )}
                                            </td>
                                            <td className="p-3 text-xs">
                                                {a.assessor?.name ?? '—'}
                                            </td>
                                            <td className="p-3 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-status-critical hover:text-status-critical"
                                                    onClick={() =>
                                                        router.delete(
                                                            `/emar/self-admin/${a.id}`,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {assessments.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={10}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            No assessments found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
