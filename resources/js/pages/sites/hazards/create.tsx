import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ShieldAlert, AlertTriangle, AlertCircle } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

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

const severityColors: Record<string, string> = {
    low: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
    medium: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
    high: 'border-orange-500/30 text-orange-400 bg-orange-500/10',
    critical: 'border-red-500/30 text-red-400 bg-red-500/10',
};

const likelihoodLabels: Record<string, string> = {
    rare: 'Rare',
    unlikely: 'Unlikely',
    possible: 'Possible',
    likely: 'Likely',
    almost_certain: 'Almost Certain',
};

export default function CreateHazard() {
    const { site, hazardTypes, severityOptions, likelihoodOptions } = usePage<Props>().props;

    const { data, setData, post, processing, errors } = useForm({
        hazard_type: '',
        custom_hazard_type: '',
        severity: 'medium' as string,
        likelihood: 'possible' as string,
        description: '',
        immediate_action_applied: false,
        immediate_action_taken: '',
        photo_paths: [] as string[],
    });

    const calculateRiskRating = (): string => {
        const matrix: Record<string, Record<string, string>> = {
            low: { rare: 'low', unlikely: 'low', possible: 'medium', likely: 'medium', almost_certain: 'high' },
            medium: { rare: 'low', unlikely: 'medium', possible: 'medium', likely: 'high', almost_certain: 'high' },
            high: { rare: 'medium', unlikely: 'medium', possible: 'high', likely: 'high', almost_certain: 'extreme' },
            critical: { rare: 'high', unlikely: 'high', possible: 'extreme', likely: 'extreme', almost_certain: 'extreme' },
        };
        return matrix[data.severity]?.[data.likelihood] || 'medium';
    };

    const riskRating = calculateRiskRating();

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/sites/${site.id}/hazards`);
    };

    const isCustomType = data.hazard_type === 'custom';

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Hazards', href: `/sites/${site.id}/hazards` }, { title: 'Log Hazard', href: `/sites/${site.id}/hazards/create` }]}>
            <Head title={`Log Hazard - ${site.name}`} />

            <div className="m-4 max-w-3xl">
                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="flex items-center gap-3">
                        <ShieldAlert className="w-6 h-6 text-red-400" />
                        <h1 className="text-xl font-semibold">Log New Hazard</h1>
                    </div>

                    {/* Hazard Type */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Hazard Identification</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Hazard Type *</Label>
                                <Select
                                    value={data.hazard_type || undefined}
                                    onValueChange={(v) => setData('hazard_type', v)}
                                >
                                    <SelectTrigger className="mt-1">
                                        <SelectValue placeholder="Select hazard type..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {hazardTypes.map((type) => (
                                            <SelectItem key={type.key} value={type.key}>
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.hazard_type && <div className="mt-1 text-sm text-red-400">{errors.hazard_type}</div>}
                            </div>

                            {isCustomType && (
                                <div>
                                    <Label>Custom Hazard Type *</Label>
                                    <Input
                                        value={data.custom_hazard_type}
                                        onChange={(e) => setData('custom_hazard_type', e.target.value)}
                                        className="mt-1"
                                        placeholder="Describe the hazard type"
                                    />
                                    {errors.custom_hazard_type && <div className="mt-1 text-sm text-red-400">{errors.custom_hazard_type}</div>}
                                </div>
                            )}

                            <div>
                                <Label>Description *</Label>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    className="mt-1"
                                    rows={4}
                                    placeholder="Describe the hazard in detail..."
                                />
                                {errors.description && <div className="mt-1 text-sm text-red-400">{errors.description}</div>}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Risk Assessment */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="w-5 h-5" />
                                Risk Assessment
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Severity *</Label>
                                    <Select
                                        value={data.severity}
                                        onValueChange={(v) => setData('severity', v)}
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {severityOptions.map((s) => (
                                                <SelectItem key={s} value={s}>
                                                    <span className="capitalize">{s}</span>
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Likelihood *</Label>
                                    <Select
                                        value={data.likelihood}
                                        onValueChange={(v) => setData('likelihood', v)}
                                    >
                                        <SelectTrigger className="mt-1">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {likelihoodOptions.map((l) => (
                                                <SelectItem key={l} value={l}>
                                                    {likelihoodLabels[l]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            {/* Risk Rating Display */}
                            <div className="p-4 rounded-lg border bg-muted/30">
                                <div className="text-sm text-slate-400 mb-2">Calculated Risk Rating</div>
                                <div className="flex items-center gap-3">
                                    <Badge 
                                        variant="outline" 
                                        className={`text-lg px-4 py-2 ${
                                            riskRating === 'extreme' ? 'border-red-500/50 text-red-400 bg-red-500/10' :
                                            riskRating === 'high' ? 'border-orange-500/50 text-orange-400 bg-orange-500/10' :
                                            riskRating === 'medium' ? 'border-yellow-500/50 text-yellow-400 bg-yellow-500/10' :
                                            'border-slate-500/30 text-slate-400'
                                        }`}
                                    >
                                        <AlertCircle className="w-4 h-4 mr-2" />
                                        {riskRating.toUpperCase()}
                                    </Badge>
                                    <span className="text-sm text-slate-400">
                                        {riskRating === 'extreme' && 'Immediate action required - H&S Officer must be assigned'}
                                        {riskRating === 'high' && 'Urgent action required'}
                                        {riskRating === 'medium' && 'Action required within reasonable timeframe'}
                                        {riskRating === 'low' && 'Routine management'}
                                    </span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Immediate Action */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Immediate Action</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    checked={data.immediate_action_applied}
                                    onCheckedChange={(checked) => setData('immediate_action_applied', checked as boolean)}
                                />
                                <Label className="font-normal">Immediate action was taken</Label>
                            </div>
                            {data.immediate_action_applied && (
                                <div>
                                    <Label>Action Taken</Label>
                                    <Textarea
                                        value={data.immediate_action_taken}
                                        onChange={(e) => setData('immediate_action_taken', e.target.value)}
                                        className="mt-1"
                                        rows={3}
                                        placeholder="Describe what immediate action was taken..."
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Logging...' : 'Log Hazard'}
                        </Button>
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
