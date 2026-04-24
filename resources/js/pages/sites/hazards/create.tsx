import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bug,
    Camera,
    Car,
    Droplets,
    FileText,
    Flame,
    Footprints,
    HardHat,
    HelpCircle,
    MapPin,
    ShieldAlert,
    User,
    Zap,
} from 'lucide-react';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Props = {
    site: Site;
    hazardTypes: Array<{
        key: string;
        label: string;
        default_severity?: string;
    }>;
    severityOptions: string[];
    likelihoodOptions: string[];
};

const HAZARD_TYPE_ICONS: Record<string, typeof AlertTriangle> = {
    slip_trip_fall: Footprints,
    fire: Flame,
    chemical: Droplets,
    electrical: Zap,
    manual_handling: HardHat,
    biological: Bug,
    vehicle: Car,
    environmental: ShieldAlert,
    custom: HelpCircle,
};

const HAZARD_TYPE_COLORS: Record<string, string> = {
    slip_trip_fall:
        'border-status-warning/30 bg-status-warning-bg text-status-warning hover:bg-status-warning-bg',
    fire: 'border-status-critical/30 bg-status-critical-bg text-status-critical hover:bg-status-critical-bg',
    chemical: 'border-primary bg-primary/10 text-primary hover:bg-primary/10',
    electrical:
        'border-status-warning/30 bg-status-warning-bg text-status-warning hover:bg-status-warning-bg',
    manual_handling:
        'border-status-info/30 bg-status-info-bg text-status-info hover:bg-status-info-bg',
    biological:
        'border-status-success/30 bg-status-success-bg text-status-success hover:bg-status-success-bg',
    vehicle: 'border-border bg-muted text-foreground hover:bg-muted',
    environmental:
        'border-status-info/30 bg-status-info-bg text-status-info hover:bg-status-info-bg',
    custom: 'border-border bg-muted text-foreground hover:bg-muted',
};

const SEVERITY_OPTIONS = [
    {
        value: 'low',
        label: 'Low',
        color: 'border-status-success/30 bg-status-success-bg text-status-success',
        dot: 'bg-status-success',
        selectedBg: 'bg-status-success-bg ring-2 ring-status-success',
    },
    {
        value: 'medium',
        label: 'Medium',
        color: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
        selectedBg: 'bg-status-warning-bg ring-2 ring-status-warning',
    },
    {
        value: 'high',
        label: 'High',
        color: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
        selectedBg: 'bg-status-warning-bg ring-2 ring-status-warning',
    },
    {
        value: 'critical',
        label: 'Critical',
        color: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
        dot: 'bg-status-critical',
        selectedBg: 'bg-status-critical-bg ring-2 ring-status-critical',
    },
];

const LIKELIHOOD_OPTIONS = [
    {
        value: 'rare',
        label: 'Rare',
        color: 'border-status-success/30 bg-status-success-bg text-status-success',
        selectedBg: 'bg-status-success-bg ring-2 ring-status-success',
    },
    {
        value: 'unlikely',
        label: 'Unlikely',
        color: 'border-status-info/30 bg-status-info-bg text-status-info',
        selectedBg: 'bg-status-info-bg ring-2 ring-status-info',
    },
    {
        value: 'possible',
        label: 'Possible',
        color: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        selectedBg: 'bg-status-warning-bg ring-2 ring-status-warning',
    },
    {
        value: 'likely',
        label: 'Likely',
        color: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
        selectedBg: 'bg-status-warning-bg ring-2 ring-status-warning',
    },
    {
        value: 'almost_certain',
        label: 'Almost Certain',
        color: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
        selectedBg: 'bg-status-critical-bg ring-2 ring-status-critical',
    },
];

const RISK_MATRIX: Record<string, Record<string, string>> = {
    low: {
        rare: 'low',
        unlikely: 'low',
        possible: 'medium',
        likely: 'medium',
        almost_certain: 'high',
    },
    medium: {
        rare: 'low',
        unlikely: 'medium',
        possible: 'medium',
        likely: 'high',
        almost_certain: 'high',
    },
    high: {
        rare: 'medium',
        unlikely: 'medium',
        possible: 'high',
        likely: 'high',
        almost_certain: 'extreme',
    },
    critical: {
        rare: 'high',
        unlikely: 'high',
        possible: 'extreme',
        likely: 'extreme',
        almost_certain: 'extreme',
    },
};

const riskRatingColors: Record<
    string,
    { bg: string; text: string; border: string }
> = {
    low: {
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
        border: 'border-status-success/30',
    },
    medium: {
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning',
        border: 'border-status-warning/30',
    },
    high: {
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning',
        border: 'border-status-warning/30',
    },
    extreme: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        border: 'border-status-critical/30',
    },
};

const riskRatingMessages: Record<string, string> = {
    extreme: 'Immediate action required - H&S Officer must be assigned',
    high: 'Urgent action required',
    medium: 'Action required within reasonable timeframe',
    low: 'Routine management',
};

export default function CreateHazard() {
    const { site, hazardTypes, severityOptions, likelihoodOptions } =
        usePage<Props>().props;

    const { data, setData, post, processing, errors } = useForm({
        hazard_type: '',
        custom_hazard_type: '',
        severity: 'medium' as string,
        likelihood: 'possible' as string,
        description: '',
        location: '',
        witnesses: '',
        immediate_action_applied: false,
        immediate_action_taken: '',
        photo_paths: [] as string[],
    });

    const riskRating =
        RISK_MATRIX[data.severity]?.[data.likelihood] || 'medium';
    const riskColors = riskRatingColors[riskRating] ?? riskRatingColors.medium;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/sites/${site.id}/hazards`);
    };

    const isCustomType = data.hazard_type === 'custom';

    // Build a mini risk matrix visual
    const sevKeys = ['low', 'medium', 'high', 'critical'];
    const likKeys = [
        'rare',
        'unlikely',
        'possible',
        'likely',
        'almost_certain',
    ];
    const matrixCellColor = (rating: string) => {
        switch (rating) {
            case 'extreme':
                return 'bg-status-critical text-white';
            case 'high':
                return 'bg-status-warning text-white';
            case 'medium':
                return 'bg-status-warning-bg text-status-warning';
            default:
                return 'bg-status-success-bg text-status-success';
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Hazards', href: `/sites/${site.id}/hazards` },
                {
                    title: 'Log Hazard',
                    href: `/sites/${site.id}/hazards/create`,
                },
            ]}
        >
            <Head title={`Log Hazard - ${site.name}`} />

            <div className="mx-auto max-w-4xl space-y-6 pb-8">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                            <AlertTriangle className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">
                                Log new hazard
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Report a hazard at {site.name}
                            </p>
                        </div>
                    </div>
                    <Link
                        href={`/sites/${site.id}/hazards`}
                        className="rounded-md border px-3 py-2 text-xs font-medium transition-colors hover:bg-muted"
                    >
                        Back
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Step 1: Hazard Identification */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                                    1
                                </div>
                                <div>
                                    <CardTitle className="text-base">
                                        Hazard Identification
                                    </CardTitle>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Select the type of hazard observed
                                    </p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-5">
                            <div className="space-y-2">
                                <Label className="text-sm font-medium">
                                    Hazard type
                                </Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                    {hazardTypes.map((type) => {
                                        const Icon =
                                            HAZARD_TYPE_ICONS[type.key] ??
                                            AlertTriangle;
                                        const colorClass =
                                            HAZARD_TYPE_COLORS[type.key] ??
                                            HAZARD_TYPE_COLORS.custom;
                                        const isSelected =
                                            data.hazard_type === type.key;
                                        return (
                                            <Button
                                                key={type.key}
                                                type="button"
                                                variant="outline"
                                                onClick={() => {
                                                    setData(
                                                        'hazard_type',
                                                        type.key,
                                                    );
                                                    if (type.default_severity) {
                                                        setData(
                                                            'severity',
                                                            type.default_severity,
                                                        );
                                                    }
                                                }}
                                                className={`h-auto flex-col gap-1.5 rounded-lg border-2 p-3 text-center ${
                                                    isSelected
                                                        ? `${colorClass} shadow-sm ring-1 ring-current`
                                                        : 'border-border bg-background text-muted-foreground hover:border-border/80 hover:bg-muted/50'
                                                }`}
                                            >
                                                <Icon className="h-5 w-5" />
                                                <span className="text-xs font-medium">
                                                    {type.label}
                                                </span>
                                            </Button>
                                        );
                                    })}
                                </div>
                                {errors.hazard_type && (
                                    <p className="text-sm text-status-critical">
                                        {errors.hazard_type}
                                    </p>
                                )}
                            </div>

                            {isCustomType && (
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">
                                        Custom hazard type
                                    </Label>
                                    <Input
                                        value={data.custom_hazard_type}
                                        onChange={(e) =>
                                            setData(
                                                'custom_hazard_type',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Describe the hazard type"
                                    />
                                    {errors.custom_hazard_type && (
                                        <p className="text-sm text-status-critical">
                                            {errors.custom_hazard_type}
                                        </p>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Step 2: Risk Assessment */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                                    2
                                </div>
                                <div>
                                    <CardTitle className="text-base">
                                        Risk Assessment
                                    </CardTitle>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Assess severity, likelihood, and
                                        describe the hazard
                                    </p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-5 pt-5">
                            {/* Severity buttons */}
                            <div className="space-y-2">
                                <Label className="text-sm font-medium">
                                    Severity
                                </Label>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    {SEVERITY_OPTIONS.map((s) => {
                                        const isSelected =
                                            data.severity === s.value;
                                        return (
                                            <Button
                                                key={s.value}
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setData('severity', s.value)
                                                }
                                                className={`h-auto gap-2 rounded-lg border-2 px-4 py-3 font-medium ${
                                                    isSelected
                                                        ? `${s.selectedBg} ${s.color}`
                                                        : `${s.color} opacity-60 hover:opacity-80`
                                                }`}
                                            >
                                                <span
                                                    className={`h-2.5 w-2.5 rounded-full ${s.dot}`}
                                                />
                                                <span className="text-sm">
                                                    {s.label}
                                                </span>
                                            </Button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Likelihood buttons */}
                            <div className="space-y-2">
                                <Label className="text-sm font-medium">
                                    Likelihood
                                </Label>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                    {LIKELIHOOD_OPTIONS.map((l) => {
                                        const isSelected =
                                            data.likelihood === l.value;
                                        return (
                                            <Button
                                                key={l.value}
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setData(
                                                        'likelihood',
                                                        l.value,
                                                    )
                                                }
                                                className={`h-auto rounded-lg border-2 px-3 py-2.5 text-center font-medium ${
                                                    isSelected
                                                        ? `${l.selectedBg} ${l.color}`
                                                        : `${l.color} opacity-60 hover:opacity-80`
                                                }`}
                                            >
                                                <span className="text-xs">
                                                    {l.label}
                                                </span>
                                            </Button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Calculated Risk Rating */}
                            <div
                                className={`rounded-lg border-2 p-4 ${riskColors.border} ${riskColors.bg}`}
                            >
                                <div className="flex items-center gap-3">
                                    <div
                                        className={`flex h-12 w-12 items-center justify-center rounded-lg ${riskColors.bg} ${riskColors.text} border text-lg font-bold ${riskColors.border}`}
                                    >
                                        {riskRating.charAt(0).toUpperCase()}
                                    </div>
                                    <div>
                                        <div
                                            className={`text-sm font-semibold ${riskColors.text}`}
                                        >
                                            Risk Rating:{' '}
                                            {riskRating.toUpperCase()}
                                        </div>
                                        <div className="mt-0.5 text-xs text-muted-foreground">
                                            {riskRatingMessages[riskRating]}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Mini risk matrix */}
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">
                                    Risk Matrix
                                </Label>
                                <div className="overflow-x-auto">
                                    <table className="border-collapse text-[10px]">
                                        <thead>
                                            <tr>
                                                <th className="p-1" />
                                                {likKeys.map((l) => (
                                                    <th
                                                        key={l}
                                                        className="p-1 text-center font-medium text-muted-foreground capitalize"
                                                    >
                                                        {l
                                                            .replace('_', ' ')
                                                            .slice(0, 6)}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {[...sevKeys].reverse().map((s) => (
                                                <tr key={s}>
                                                    <td className="p-1 pr-2 font-medium text-muted-foreground capitalize">
                                                        {s}
                                                    </td>
                                                    {likKeys.map((l) => {
                                                        const cellRating =
                                                            RISK_MATRIX[s]?.[
                                                                l
                                                            ] ?? 'low';
                                                        const isActive =
                                                            s ===
                                                                data.severity &&
                                                            l ===
                                                                data.likelihood;
                                                        return (
                                                            <td
                                                                key={l}
                                                                className={`rounded p-1 text-center ${matrixCellColor(cellRating)} ${
                                                                    isActive
                                                                        ? 'font-bold ring-2 ring-ring ring-offset-1'
                                                                        : ''
                                                                }`}
                                                                style={{
                                                                    width: 40,
                                                                    height: 24,
                                                                }}
                                                            >
                                                                {cellRating
                                                                    .charAt(0)
                                                                    .toUpperCase()}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* Description */}
                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <FileText className="h-3.5 w-3.5 text-muted-foreground" />
                                    Description
                                </Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    rows={4}
                                    placeholder="Describe the hazard in detail..."
                                />
                                {errors.description && (
                                    <p className="text-sm text-status-critical">
                                        {errors.description}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 3: Location & Details */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                                    3
                                </div>
                                <div>
                                    <CardTitle className="text-base">
                                        Location & Details
                                    </CardTitle>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Where the hazard was found and
                                        supporting information
                                    </p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-5">
                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <MapPin className="h-3.5 w-3.5 text-muted-foreground" />
                                    Location
                                </Label>
                                <Input
                                    value={data.location}
                                    onChange={(e) =>
                                        setData('location', e.target.value)
                                    }
                                    placeholder="e.g. Kitchen, stairwell, car park..."
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <Camera className="h-3.5 w-3.5 text-muted-foreground" />
                                    Photos
                                </Label>
                                <div className="rounded-lg border-2 border-dashed border-muted-foreground/20 bg-muted/20 p-6 text-center">
                                    <Camera className="mx-auto h-8 w-8 text-muted-foreground/40" />
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        Upload photos of the hazard
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground/70">
                                        Drag and drop or click to upload
                                    </p>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <User className="h-3.5 w-3.5 text-muted-foreground" />
                                    Witnesses
                                </Label>
                                <Textarea
                                    value={data.witnesses}
                                    onChange={(e) =>
                                        setData('witnesses', e.target.value)
                                    }
                                    rows={2}
                                    placeholder="Names and contact details of any witnesses..."
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 4: Immediate Action */}
                    <Card
                        className={`overflow-hidden transition-colors ${data.immediate_action_applied ? 'border-status-info/30 bg-status-info-bg' : ''}`}
                    >
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                                    4
                                </div>
                                <div>
                                    <CardTitle className="text-base">
                                        Immediate Action
                                    </CardTitle>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        Record any immediate action taken to
                                        address the hazard
                                    </p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-5">
                            <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                <Checkbox
                                    id="immediate_action"
                                    checked={data.immediate_action_applied}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'immediate_action_applied',
                                            checked as boolean,
                                        )
                                    }
                                />
                                <div>
                                    <Label
                                        htmlFor="immediate_action"
                                        className="cursor-pointer text-sm font-medium"
                                    >
                                        Immediate action was taken
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Tick if corrective steps were taken on
                                        the spot
                                    </p>
                                </div>
                            </div>

                            {data.immediate_action_applied && (
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">
                                        Describe the action taken
                                    </Label>
                                    <Textarea
                                        value={data.immediate_action_taken}
                                        onChange={(e) =>
                                            setData(
                                                'immediate_action_taken',
                                                e.target.value,
                                            )
                                        }
                                        rows={3}
                                        placeholder="Describe what immediate action was taken..."
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Errors */}
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-4">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4 text-status-critical" />
                                <p className="text-sm font-semibold text-status-critical">
                                    Please fix the following errors:
                                </p>
                            </div>
                            <ul className="mt-2 list-disc space-y-0.5 pl-6 text-sm text-status-critical">
                                {Object.entries(errors).map(
                                    ([field, message]) => (
                                        <li key={field}>{message}</li>
                                    ),
                                )}
                            </ul>
                        </div>
                    )}

                    {/* Submit */}
                    <div className="flex items-center justify-end gap-3 border-t pt-6">
                        <Link
                            href={`/sites/${site.id}/hazards`}
                            className="rounded-md border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted"
                        >
                            Cancel
                        </Link>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={processing}
                            className="min-w-[140px]"
                        >
                            {processing ? 'Logging...' : 'Log Hazard'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
