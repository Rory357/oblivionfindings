import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import SettingsLayout from '@/layouts/settings/layout';
import { Head } from '@inertiajs/react';
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
import { useState } from 'react';

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

const initialModules: Module[] = [
    { id: 'operations', name: 'Operations', description: 'Shifts, rosters, and service delivery management', icon: ClipboardList, enabled: true },
    { id: 'hr', name: 'HR & People', description: 'Staff records, leave, training, and compliance', icon: Users, enabled: true },
    { id: 'fleet', name: 'Fleet & Assets', description: 'Vehicle tracking, maintenance, and asset management', icon: Car, enabled: true },
    { id: 'governance', name: 'Governance', description: 'Board management, policies, and compliance oversight', icon: Shield, enabled: true },
    { id: 'incidents', name: 'Incidents', description: 'Incident reporting, investigation, and follow-up', icon: Siren, enabled: true },
    { id: 'emar', name: 'eMAR', description: 'Electronic medication administration records', icon: Pill, enabled: false },
    { id: 'sites', name: 'Sites & Locations', description: 'Manage physical locations and site configurations', icon: MapPin, enabled: true },
    { id: 'reporting', name: 'Reporting', description: 'Analytics, dashboards, and custom reports', icon: BarChart3, enabled: true },
    { id: 'control-room', name: 'Control Room', description: 'Real-time monitoring and operational oversight', icon: Activity, enabled: false },
];

const initialBetaFeatures: BetaFeature[] = [
    { id: 'ai-docs', name: 'AI Documentation Assistant', description: 'Use AI to help draft care notes, incident reports, and documentation', enabled: false },
    { id: 'family-portal', name: 'Family Portal', description: 'Allow family members to view updates about their loved ones', enabled: false },
    { id: 'calendar-sync', name: 'Calendar Sync', description: 'Sync shifts and events with external calendar applications', enabled: false },
    { id: 'custom-forms', name: 'Custom Forms', description: 'Build custom forms and checklists for your organisation', enabled: false },
    { id: 'advanced-analytics', name: 'Advanced Analytics', description: 'Predictive analytics and advanced data visualisation tools', enabled: false },
];

export default function Modules() {
    const [modules, setModules] = useState(initialModules);
    const [betaFeatures, setBetaFeatures] = useState(initialBetaFeatures);

    function toggleModule(id: string) {
        setModules((prev) => prev.map((m) => (m.id === id ? { ...m, enabled: !m.enabled } : m)));
    }

    function toggleBeta(id: string) {
        setBetaFeatures((prev) => prev.map((f) => (f.id === id ? { ...f, enabled: !f.enabled } : f)));
    }

    return (
        <SettingsLayout>
            <Head title="Modules & Features" />

            <div className="space-y-6">
                {/* Active Modules */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Boxes className="h-5 w-5 text-violet-600" />
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
                                    <Card key={mod.id} className={`border transition-colors ${mod.enabled ? 'border-violet-200 bg-violet-50/30' : 'border-muted'}`}>
                                        <CardContent className="p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="flex items-start gap-3">
                                                    <div className={`rounded-lg p-2 ${mod.enabled ? 'bg-violet-100 text-violet-600' : 'bg-muted text-muted-foreground'}`}>
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
                                                <Switch checked={mod.enabled} onCheckedChange={() => toggleModule(mod.id)} />
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
                            <FlaskConical className="h-5 w-5 text-violet-600" />
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
                                                <Badge variant="outline" className="border-amber-300 bg-amber-50 text-[10px] text-amber-700">
                                                    Beta
                                                </Badge>
                                            </div>
                                            <p className="mt-0.5 text-xs text-muted-foreground">{feature.description}</p>
                                        </div>
                                    </div>
                                    <Switch checked={feature.enabled} onCheckedChange={() => toggleBeta(feature.id)} />
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button className="bg-violet-600 hover:bg-violet-700">Save Changes</Button>
                </div>
            </div>
        </SettingsLayout>
    );
}
