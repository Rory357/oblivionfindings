import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
    AlertTriangle,
    Flame,
    Droplets,
    Zap,
    HardHat,
    Bug,
    Car,
    Footprints,
    ShieldAlert,
    HelpCircle,
    MapPin,
    Camera,
    User,
    FileText,
} from 'lucide-react';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Props = {
    site: Site;
    hazardTypes: Array<{ key: string; label: string; default_severity?: string }>;
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
    slip_trip_fall: 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100',
    fire: 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100',
    chemical: 'border-primary bg-primary/10 text-primary hover:bg-primary/10',
    electrical: 'border-yellow-200 bg-yellow-50 text-yellow-700 hover:bg-yellow-100',
    manual_handling: 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100',
    biological: 'border-green-200 bg-green-50 text-green-700 hover:bg-green-100',
    vehicle: 'border-border bg-muted text-foreground hover:bg-muted',
    environmental: 'border-teal-200 bg-teal-50 text-teal-700 hover:bg-teal-100',
    custom: 'border-border bg-muted text-foreground hover:bg-muted',
};

const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low', color: 'border-emerald-300 bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500', selectedBg: 'bg-emerald-100 ring-2 ring-emerald-500' },
    { value: 'medium', label: 'Medium', color: 'border-amber-300 bg-amber-50 text-amber-700', dot: 'bg-amber-500', selectedBg: 'bg-amber-100 ring-2 ring-amber-500' },
    { value: 'high', label: 'High', color: 'border-orange-300 bg-orange-50 text-orange-700', dot: 'bg-orange-500', selectedBg: 'bg-orange-100 ring-2 ring-orange-500' },
    { value: 'critical', label: 'Critical', color: 'border-red-300 bg-red-50 text-red-700', dot: 'bg-red-500', selectedBg: 'bg-red-100 ring-2 ring-red-500' },
];

const LIKELIHOOD_OPTIONS = [
    { value: 'rare', label: 'Rare', color: 'border-emerald-300 bg-emerald-50 text-emerald-700', selectedBg: 'bg-emerald-100 ring-2 ring-emerald-500' },
    { value: 'unlikely', label: 'Unlikely', color: 'border-blue-300 bg-blue-50 text-blue-700', selectedBg: 'bg-blue-100 ring-2 ring-blue-500' },
    { value: 'possible', label: 'Possible', color: 'border-amber-300 bg-amber-50 text-amber-700', selectedBg: 'bg-amber-100 ring-2 ring-amber-500' },
    { value: 'likely', label: 'Likely', color: 'border-orange-300 bg-orange-50 text-orange-700', selectedBg: 'bg-orange-100 ring-2 ring-orange-500' },
    { value: 'almost_certain', label: 'Almost Certain', color: 'border-red-300 bg-red-50 text-red-700', selectedBg: 'bg-red-100 ring-2 ring-red-500' },
];

const RISK_MATRIX: Record<string, Record<string, string>> = {
    low: { rare: 'low', unlikely: 'low', possible: 'medium', likely: 'medium', almost_certain: 'high' },
    medium: { rare: 'low', unlikely: 'medium', possible: 'medium', likely: 'high', almost_certain: 'high' },
    high: { rare: 'medium', unlikely: 'medium', possible: 'high', likely: 'high', almost_certain: 'extreme' },
    critical: { rare: 'high', unlikely: 'high', possible: 'extreme', likely: 'extreme', almost_certain: 'extreme' },
};

const riskRatingColors: Record<string, { bg: string; text: string; border: string }> = {
    low: { bg: 'bg-emerald-100', text: 'text-emerald-800', border: 'border-emerald-300' },
    medium: { bg: 'bg-amber-100', text: 'text-amber-800', border: 'border-amber-300' },
    high: { bg: 'bg-orange-100', text: 'text-orange-800', border: 'border-orange-300' },
    extreme: { bg: 'bg-red-100', text: 'text-red-800', border: 'border-red-300' },
};

const riskRatingMessages: Record<string, string> = {
    extreme: 'Immediate action required - H&S Officer must be assigned',
    high: 'Urgent action required',
    medium: 'Action required within reasonable timeframe',
    low: 'Routine management',
};

export default function CreateHazard() {
    const { site, hazardTypes, severityOptions, likelihoodOptions } = usePage<Props>().props;

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

    const riskRating = RISK_MATRIX[data.severity]?.[data.likelihood] || 'medium';
    const riskColors = riskRatingColors[riskRating] ?? riskRatingColors.medium;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/sites/${site.id}/hazards`);
    };

    const isCustomType = data.hazard_type === 'custom';

    // Build a mini risk matrix visual
    const sevKeys = ['low', 'medium', 'high', 'critical'];
    const likKeys = ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'];
    const matrixCellColor = (rating: string) => {
        switch (rating) {
            case 'extreme': return 'bg-red-500 text-white';
            case 'high': return 'bg-orange-400 text-white';
            case 'medium': return 'bg-amber-300 text-amber-900';
            default: return 'bg-emerald-200 text-emerald-900';
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Hazards', href: `/sites/${site.id}/hazards` }, { title: 'Log Hazard', href: `/sites/${site.id}/hazards/create` }]}>
            <Head title={`Log Hazard - ${site.name}`} />

            <div className="mx-auto max-w-4xl space-y-6 pb-8">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-100 text-orange-600">
                            <AlertTriangle className="h-5 w-5" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold tracking-tight">Log new hazard</h1>
                            <p className="text-sm text-muted-foreground">Report a hazard at {site.name}</p>
                        </div>
                    </div>
                    <Link href={`/sites/${site.id}/hazards`} className="rounded-md border px-3 py-2 text-xs font-medium hover:bg-muted transition-colors">
                        Back
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Step 1: Hazard Identification */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">1</div>
                                <div>
                                    <CardTitle className="text-base">Hazard Identification</CardTitle>
                                    <p className="text-xs text-muted-foreground mt-0.5">Select the type of hazard observed</p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-4">
                            <div className="space-y-2">
                                <Label className="text-sm font-medium">Hazard type</Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                    {hazardTypes.map((type) => {
                                        const Icon = HAZARD_TYPE_ICONS[type.key] ?? AlertTriangle;
                                        const colorClass = HAZARD_TYPE_COLORS[type.key] ?? HAZARD_TYPE_COLORS.custom;
                                        const isSelected = data.hazard_type === type.key;
                                        return (
                                            <button
                                                key={type.key}
                                                type="button"
                                                onClick={() => {
                                                    setData('hazard_type', type.key);
                                                    if (type.default_severity) {
                                                        setData('severity', type.default_severity);
                                                    }
                                                }}
                                                className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-3 text-center transition-all ${
                                                    isSelected
                                                        ? `${colorClass} ring-1 ring-current shadow-sm`
                                                        : 'border-border bg-background text-muted-foreground hover:border-border/80 hover:bg-muted/50'
                                                }`}
                                            >
                                                <Icon className="h-5 w-5" />
                                                <span className="text-xs font-medium">{type.label}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                                {errors.hazard_type && <p className="text-sm text-red-600">{errors.hazard_type}</p>}
                            </div>

                            {isCustomType && (
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Custom hazard type</Label>
                                    <Input
                                        value={data.custom_hazard_type}
                                        onChange={(e) => setData('custom_hazard_type', e.target.value)}
                                        placeholder="Describe the hazard type"
                                    />
                                    {errors.custom_hazard_type && <p className="text-sm text-red-600">{errors.custom_hazard_type}</p>}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Step 2: Risk Assessment */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">2</div>
                                <div>
                                    <CardTitle className="text-base">Risk Assessment</CardTitle>
                                    <p className="text-xs text-muted-foreground mt-0.5">Assess severity, likelihood, and describe the hazard</p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-5">
                            {/* Severity buttons */}
                            <div className="space-y-2">
                                <Label className="text-sm font-medium">Severity</Label>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    {SEVERITY_OPTIONS.map((s) => {
                                        const isSelected = data.severity === s.value;
                                        return (
                                            <button
                                                key={s.value}
                                                type="button"
                                                onClick={() => setData('severity', s.value)}
                                                className={`flex items-center justify-center gap-2 rounded-lg border-2 px-4 py-3 font-medium transition-all ${
                                                    isSelected
                                                        ? `${s.selectedBg} ${s.color}`
                                                        : `${s.color} opacity-60 hover:opacity-80`
                                                }`}
                                            >
                                                <span className={`h-2.5 w-2.5 rounded-full ${s.dot}`} />
                                                <span className="text-sm">{s.label}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Likelihood buttons */}
                            <div className="space-y-2">
                                <Label className="text-sm font-medium">Likelihood</Label>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                    {LIKELIHOOD_OPTIONS.map((l) => {
                                        const isSelected = data.likelihood === l.value;
                                        return (
                                            <button
                                                key={l.value}
                                                type="button"
                                                onClick={() => setData('likelihood', l.value)}
                                                className={`rounded-lg border-2 px-3 py-2.5 text-center font-medium transition-all ${
                                                    isSelected
                                                        ? `${l.selectedBg} ${l.color}`
                                                        : `${l.color} opacity-60 hover:opacity-80`
                                                }`}
                                            >
                                                <span className="text-xs">{l.label}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Calculated Risk Rating */}
                            <div className={`rounded-lg border-2 p-4 ${riskColors.border} ${riskColors.bg}`}>
                                <div className="flex items-center gap-3">
                                    <div className={`flex h-12 w-12 items-center justify-center rounded-lg ${riskColors.bg} ${riskColors.text} font-bold text-lg border ${riskColors.border}`}>
                                        {riskRating.charAt(0).toUpperCase()}
                                    </div>
                                    <div>
                                        <div className={`text-sm font-semibold ${riskColors.text}`}>
                                            Risk Rating: {riskRating.toUpperCase()}
                                        </div>
                                        <div className="text-xs text-muted-foreground mt-0.5">
                                            {riskRatingMessages[riskRating]}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Mini risk matrix */}
                            <div className="space-y-2">
                                <Label className="text-xs text-muted-foreground">Risk Matrix</Label>
                                <div className="overflow-x-auto">
                                    <table className="text-[10px] border-collapse">
                                        <thead>
                                            <tr>
                                                <th className="p-1" />
                                                {likKeys.map((l) => (
                                                    <th key={l} className="p-1 text-center font-medium text-muted-foreground capitalize">
                                                        {l.replace('_', ' ').slice(0, 6)}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {[...sevKeys].reverse().map((s) => (
                                                <tr key={s}>
                                                    <td className="p-1 font-medium text-muted-foreground capitalize pr-2">{s}</td>
                                                    {likKeys.map((l) => {
                                                        const cellRating = RISK_MATRIX[s]?.[l] ?? 'low';
                                                        const isActive = s === data.severity && l === data.likelihood;
                                                        return (
                                                            <td
                                                                key={l}
                                                                className={`p-1 text-center rounded ${matrixCellColor(cellRating)} ${
                                                                    isActive ? 'ring-2 ring-offset-1 ring-slate-900 font-bold' : ''
                                                                }`}
                                                                style={{ width: 40, height: 24 }}
                                                            >
                                                                {cellRating.charAt(0).toUpperCase()}
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
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows={4}
                                    placeholder="Describe the hazard in detail..."
                                />
                                {errors.description && <p className="text-sm text-red-600">{errors.description}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 3: Location & Details */}
                    <Card className="overflow-hidden">
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">3</div>
                                <div>
                                    <CardTitle className="text-base">Location & Details</CardTitle>
                                    <p className="text-xs text-muted-foreground mt-0.5">Where the hazard was found and supporting information</p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-4">
                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <MapPin className="h-3.5 w-3.5 text-muted-foreground" />
                                    Location
                                </Label>
                                <Input
                                    value={data.location}
                                    onChange={(e) => setData('location', e.target.value)}
                                    placeholder="e.g. Kitchen, stairwell, car park..."
                                />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <Camera className="h-3.5 w-3.5 text-muted-foreground" />
                                    Photos
                                </Label>
                                <div className="rounded-lg border-2 border-dashed border-muted-foreground/20 p-6 text-center bg-muted/20">
                                    <Camera className="mx-auto h-8 w-8 text-muted-foreground/40" />
                                    <p className="mt-2 text-sm text-muted-foreground">Upload photos of the hazard</p>
                                    <p className="text-xs text-muted-foreground/70 mt-1">Drag and drop or click to upload</p>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="flex items-center gap-1.5 text-sm font-medium">
                                    <User className="h-3.5 w-3.5 text-muted-foreground" />
                                    Witnesses
                                </Label>
                                <Textarea
                                    value={data.witnesses}
                                    onChange={(e) => setData('witnesses', e.target.value)}
                                    rows={2}
                                    placeholder="Names and contact details of any witnesses..."
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Step 4: Immediate Action */}
                    <Card className={`overflow-hidden transition-colors ${data.immediate_action_applied ? 'border-blue-300 bg-blue-50/30' : ''}`}>
                        <CardHeader className="border-b bg-muted/30 pb-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">4</div>
                                <div>
                                    <CardTitle className="text-base">Immediate Action</CardTitle>
                                    <p className="text-xs text-muted-foreground mt-0.5">Record any immediate action taken to address the hazard</p>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-5 space-y-4">
                            <div className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3">
                                <Checkbox
                                    id="immediate_action"
                                    checked={data.immediate_action_applied}
                                    onCheckedChange={(checked) => setData('immediate_action_applied', checked as boolean)}
                                />
                                <div>
                                    <Label htmlFor="immediate_action" className="text-sm font-medium cursor-pointer">
                                        Immediate action was taken
                                    </Label>
                                    <p className="text-xs text-muted-foreground">Tick if corrective steps were taken on the spot</p>
                                </div>
                            </div>

                            {data.immediate_action_applied && (
                                <div className="space-y-1.5">
                                    <Label className="text-sm font-medium">Describe the action taken</Label>
                                    <Textarea
                                        value={data.immediate_action_taken}
                                        onChange={(e) => setData('immediate_action_taken', e.target.value)}
                                        rows={3}
                                        placeholder="Describe what immediate action was taken..."
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Errors */}
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-lg border border-red-300 bg-red-50 p-4">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="h-4 w-4 text-red-600" />
                                <p className="text-sm font-semibold text-red-800">Please fix the following errors:</p>
                            </div>
                            <ul className="mt-2 list-disc pl-6 text-sm text-red-700 space-y-0.5">
                                {Object.entries(errors).map(([field, message]) => (
                                    <li key={field}>{message}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {/* Submit */}
                    <div className="flex items-center justify-end gap-3 border-t pt-6">
                        <Link href={`/sites/${site.id}/hazards`} className="rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted transition-colors">
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
