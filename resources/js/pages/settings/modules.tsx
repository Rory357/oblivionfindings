import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    Boxes,
    Building2,
    Car,
    ClipboardList,
    FlaskConical,
    Heart,
    MapPin,
    Pill,
    Shield,
    Siren,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Module {
    id: string;
    name: string;
    description: string;
    icon: LucideIcon;
    enabled: boolean;
}

interface BetaFeature {
    id: string;
    name: string;
    description: string;
    enabled: boolean;
}

type ModuleCatalogItem = Omit<Module, 'enabled'>;
type BetaFeatureCatalogItem = Omit<BetaFeature, 'enabled'>;

type Props = {
    module_states?: Record<string, boolean>;
    beta_feature_states?: Record<string, boolean>;
};

const moduleCatalog: ModuleCatalogItem[] = [
    { id: 'operations', name: 'Operations', description: 'Shifts, rosters, and service delivery management', icon: ClipboardList },
    { id: 'hr', name: 'HR & People', description: 'Staff records, leave, training, and compliance', icon: Users },
    { id: 'fleet', name: 'Fleet & Assets', description: 'Vehicle tracking, maintenance, and asset management', icon: Car },
    { id: 'governance', name: 'Governance', description: 'Board management, policies, and compliance oversight', icon: Shield },
    { id: 'incidents', name: 'Incidents', description: 'Incident reporting, investigation, and follow-up', icon: Siren },
    { id: 'emar', name: 'eMAR', description: 'Electronic medication administration records', icon: Pill },
    { id: 'sites', name: 'Sites & Locations', description: 'Manage physical locations and site configurations', icon: MapPin },
    { id: 'reporting', name: 'Reporting', description: 'Analytics, dashboards, and custom reports', icon: BarChart3 },
    { id: 'control-room', name: 'Control Room', description: 'Real-time monitoring and operational oversight', icon: Activity },
];

const betaFeatureCatalog: BetaFeatureCatalogItem[] = [
    { id: 'ai-docs', name: 'AI Documentation Assistant', description: 'Use AI to help draft care notes, incident reports, and documentation' },
    { id: 'family-portal', name: 'Family Portal', description: 'Allow family members to view updates about their loved ones' },
    { id: 'calendar-sync', name: 'Calendar Sync', description: 'Sync shifts and events with external calendar applications' },
    { id: 'custom-forms', name: 'Custom Forms', description: 'Build custom forms and checklists for your organisation' },
    { id: 'advanced-analytics', name: 'Advanced Analytics', description: 'Predictive analytics and advanced data visualisation tools' },
];

function buildModules(states: Record<string, boolean> = {}): Module[] {
    return moduleCatalog.map((module) => ({
        ...module,
        enabled: states[module.id] ?? (module.id !== 'emar' && module.id !== 'control-room'),
    }));
}

function buildBetaFeatures(states: Record<string, boolean> = {}): BetaFeature[] {
    return betaFeatureCatalog.map((feature) => ({
        ...feature,
        enabled: states[feature.id] ?? false,
    }));
}

export default function Modules({
    module_states = {},
    beta_feature_states = {},
}: Props) {
    const [modules, setModules] = useState(() => buildModules(module_states));
    const [betaFeatures, setBetaFeatures] = useState(() => buildBetaFeatures(beta_feature_states));
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);

    function toggleModule(id: string) {
        setModules((prev) => prev.map((m) => (m.id === id ? { ...m, enabled: !m.enabled } : m)));
    }

    function toggleBeta(id: string) {
        setBetaFeatures((prev) => prev.map((f) => (f.id === id ? { ...f, enabled: !f.enabled } : f)));
    }

    function handleSave() {
        setSaving(true);
        setSaved(false);

        router.put(
            '/settings/modules',
            {
                module_states: Object.fromEntries(modules.map((module) => [module.id, module.enabled])),
                beta_feature_states: Object.fromEntries(betaFeatures.map((feature) => [feature.id, feature.enabled])),
            },
            {
                preserveScroll: true,
                onSuccess: () => setSaved(true),
                onFinish: () => setSaving(false),
            },
        );
    }

    useEffect(() => {
        if (!saved) {
            return undefined;
        }

        const timeout = window.setTimeout(() => setSaved(false), 2500);

        return () => window.clearTimeout(timeout);
    }, [saved]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: 'Modules & Features' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Modules & Features" />
            <SettingsLayout>

            <div className="space-y-6">
                {/* Active Modules */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Boxes className="h-5 w-5 text-primary" />
                            <div>
                                <CardTitle>Active Modules</CardTitle>
                                <CardDescription>Enable or disable modules for your organisation</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {modules.map((mod) => {
                                const Icon = mod.icon;
                                return (
                                    <Card key={mod.id} className={`border transition-colors ${mod.enabled ? 'border-primary bg-primary/10/30' : 'border-muted'}`}>
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="flex items-start gap-3">
                                                    <div className={`rounded-lg p-2 ${mod.enabled ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'}`}>
                                                        <Icon className="h-5 w-5" />
                                                    </div>
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <p className="text-sm font-semibold">{mod.name}</p>
                                                            <Badge variant={mod.enabled ? 'default' : 'outline'} className="text-[10px]">
                                                                {mod.enabled ? 'Active' : 'Inactive'}
                                                            </Badge>
                                                        </div>
                                                        <p className="mt-0.5 text-xs text-muted-foreground">{mod.description}</p>
                                                    </div>
                                                </div>
                                                <Switch
                                                    dusk={`module-switch-${mod.id}`}
                                                    checked={mod.enabled}
                                                    onCheckedChange={() => toggleModule(mod.id)}
                                                />
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* Beta Features */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <FlaskConical className="h-5 w-5 text-primary" />
                            <div>
                                <CardTitle>Beta Features</CardTitle>
                                <CardDescription>Try new features before they are generally available</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            {betaFeatures.map((feature) => (
                                <div key={feature.id} className="flex items-center justify-between rounded-lg border p-4">
                                    <div className="flex items-center gap-3">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <p className="text-sm font-medium">{feature.name}</p>
                                                <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning">
                                                    Beta
                                                </Badge>
                                            </div>
                                            <p className="mt-0.5 text-xs text-muted-foreground">{feature.description}</p>
                                        </div>
                                    </div>
                                    <Switch
                                        dusk={`beta-switch-${feature.id}`}
                                        checked={feature.enabled}
                                        onCheckedChange={() => toggleBeta(feature.id)}
                                    />
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button
                        dusk="modules-save"
                        className="bg-primary hover:bg-primary"
                        onClick={handleSave}
                        disabled={saving}
                    >
                        {saving ? 'Saving...' : saved ? 'Saved' : 'Save Changes'}
                    </Button>
                </div>
            </div>
            </SettingsLayout>
        </AppLayout>
    );
}
