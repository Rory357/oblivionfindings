import GeofenceDrawMap, { type GeofenceShape } from '@/components/geofence-draw-map';
import PageShell from '@/components/page-shell';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { Loader2, Save } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Props = {
    assets: Array<{ id: number; name: string; asset_tag: string | null; category: string | null }>;
    sites: Array<{ id: number; name: string; latitude: number | null; longitude: number | null }>;
    prefillSiteId?: string | null;
};

export default function GeofenceCreate({ assets, sites, prefillSiteId }: Props) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [assetId, setAssetId] = useState('');
    const [siteId, setSiteId] = useState(prefillSiteId ?? '');
    const [breachType, setBreachType] = useState('both');
    const [isActive, setIsActive] = useState(true);
    const [scope, setScope] = useState<'vehicle' | 'resident'>('vehicle');

    // Alert config
    const [alertOnEnter, setAlertOnEnter] = useState(true);
    const [alertOnExit, setAlertOnExit] = useState(true);
    const [alertOnSpeed, setAlertOnSpeed] = useState(false);
    const [alertSeverity, setAlertSeverity] = useState('medium');
    const [notifyControlRoom, setNotifyControlRoom] = useState(false);

    // Shape from map drawing
    const [shape, setShape] = useState<GeofenceShape | null>(null);
    const [mapCenter, setMapCenter] = useState<{ lat: number; lng: number }>({ lat: -36.8485, lng: 174.7633 });

    // Handle prefill from site
    useEffect(() => {
        if (prefillSiteId) {
            const site = (sites ?? []).find((s) => String(s.id) === String(prefillSiteId));
            if (site?.latitude && site?.longitude) {
                setMapCenter({ lat: site.latitude, lng: site.longitude });
                setShape({
                    type: 'circle',
                    center: { lat: site.latitude, lng: site.longitude },
                    radius_m: 200,
                });
                setName(`${site.name} Geofence`);
            }
        }
    }, [prefillSiteId, sites]);

    const handleSiteQuickFill = useCallback((val: string) => {
        setSiteId(val);
        const site = (sites ?? []).find((s) => String(s.id) === val);
        if (site?.latitude && site?.longitude) {
            setMapCenter({ lat: site.latitude, lng: site.longitude });
            setShape({
                type: 'circle',
                center: { lat: site.latitude, lng: site.longitude },
                radius_m: 200,
            });
            if (!name) setName(`${site.name} Geofence`);
        }
    }, [sites, name]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!name.trim()) {
            setErrors({ name: 'Name is required.' });
            return;
        }
        if (!shape) {
            setErrors({ shape: 'Please draw a geofence on the map first.' });
            return;
        }
        setProcessing(true);

        let shapeData: Record<string, any> = {};
        const shapeType = shape.type === 'rectangle' ? 'polygon' : shape.type; // backend stores rectangle as polygon
        if (shape.type === 'circle') {
            shapeData = { center: shape.center, radius_m: shape.radius_m };
        } else {
            shapeData = { coordinates: shape.coordinates };
        }

        router.post('/fleet-assets/geofences', {
            asset_id: assetId || null,
            site_id: siteId || null,
            name,
            type: shapeType,
            shape: shapeData,
            scope,
            breach_type: breachType,
            alert_config: {
                on_enter: alertOnEnter,
                on_exit: alertOnExit,
                on_speed: alertOnSpeed,
                severity: alertSeverity,
                notify_control_room: notifyControlRoom,
            },
            is_active: isActive,
        }, {
            onFinish: () => setProcessing(false),
            onError: (errs) => setErrors(errs),
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Geofences', href: '/fleet-assets/geofences' },
                { title: 'Create', href: '#' },
            ]}
        >
            <Head title="Create Geofence" />
            <PageShell>
                <FleetCompactHero
                    pill="Geofences · new boundary"
                    title="Create Geofence"
                    backHref="/fleet-assets/geofences"
                    backLabel="Geofences"
                />
                <p className="text-sm text-muted-foreground">
                    Draw a boundary on the map. Use the toolbar on the map to draw a circle or polygon.
                </p>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Drawing Map Component (includes mode tabs, controls, and status) */}
                    <Card>
                        <CardContent className="p-4">
                            <GeofenceDrawMap
                                center={mapCenter}
                                zoom={13}
                                height={420}
                                initialShape={shape}
                                onShapeChange={setShape}
                            />
                        </CardContent>
                    </Card>

                    {/* Details + Alert Config side by side */}
                    <div className="grid gap-4 lg:grid-cols-2">
                        {/* Left: Geofence Details */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Geofence Details</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                <div>
                                    <Label>Name *</Label>
                                    <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Kauri House Boundary" />
                                    {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Optional description..." rows={2} />
                                </div>
                                <div>
                                    <Label>Scope</Label>
                                    <div className="flex gap-2 mt-1">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setScope('vehicle')}
                                            className={cn(
                                                'h-auto gap-1.5 rounded-lg px-3 py-1.5',
                                                scope === 'vehicle'
                                                    ? 'border-primary bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70'
                                                    : 'border-border text-muted-foreground hover:bg-muted/50',
                                            )}
                                        >
                                            Vehicle
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setScope('resident')}
                                            className={cn(
                                                'h-auto gap-1.5 rounded-lg px-3 py-1.5',
                                                scope === 'resident'
                                                    ? 'border-primary bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70'
                                                    : 'border-border text-muted-foreground hover:bg-muted/50',
                                            )}
                                        >
                                            Resident
                                        </Button>
                                    </div>
                                    <p className="mt-1 text-[10px] text-muted-foreground">
                                        {scope === 'vehicle' ? 'Monitors vehicle movements' : 'Monitors resident tracker movements'}
                                    </p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Quick-fill from Site</Label>
                                        <Select value={siteId} onValueChange={handleSiteQuickFill}>
                                            <SelectTrigger><SelectValue placeholder="Select site..." /></SelectTrigger>
                                            <SelectContent>
                                                {(sites ?? []).map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <p className="mt-1 text-[10px] text-muted-foreground">Auto-draws 200m circle around site</p>
                                    </div>
                                    <div>
                                        <Label>Link to Asset</Label>
                                        <Select value={assetId} onValueChange={setAssetId}>
                                            <SelectTrigger><SelectValue placeholder="Select asset..." /></SelectTrigger>
                                            <SelectContent>
                                                {(assets ?? []).map((a) => (
                                                    <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <label className="flex items-center gap-2 text-sm cursor-pointer">
                                    <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} className="h-4 w-4 rounded border-border accent-purple-600" />
                                    Active
                                </label>
                            </CardContent>
                        </Card>

                        {/* Right: Alert Configuration */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">Alert Configuration</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <Label>Breach Type</Label>
                                        <Select value={breachType} onValueChange={setBreachType}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="enter">Enter Only</SelectItem>
                                                <SelectItem value="exit">Exit Only</SelectItem>
                                                <SelectItem value="both">Enter & Exit</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Severity</Label>
                                        <Select value={alertSeverity} onValueChange={setAlertSeverity}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="low">Low</SelectItem>
                                                <SelectItem value="medium">Medium</SelectItem>
                                                <SelectItem value="high">High</SelectItem>
                                                <SelectItem value="critical">Critical</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div>
                                    <Label className="mb-2 block">Alert Triggers</Label>
                                    <div className="space-y-2">
                                        {[
                                            { label: 'Alert on Enter', checked: alertOnEnter, set: setAlertOnEnter },
                                            { label: 'Alert on Exit', checked: alertOnExit, set: setAlertOnExit },
                                            { label: 'Alert on Speed Violation', checked: alertOnSpeed, set: setAlertOnSpeed },
                                            { label: 'Notify Control Room', checked: notifyControlRoom, set: setNotifyControlRoom },
                                        ].map((item) => (
                                            <label key={item.label} className="flex items-center gap-2 text-sm cursor-pointer rounded-lg border px-3 py-2 hover:bg-muted/50 transition-colors">
                                                <input type="checkbox" checked={item.checked} onChange={(e) => item.set(e.target.checked)} className="h-4 w-4 rounded border-border accent-purple-600" />
                                                {item.label}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {errors.shape && (
                        <p className="text-sm text-destructive font-medium">{errors.shape}</p>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={processing || !shape}>
                            {processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            Create Geofence
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/geofences">Cancel</Link>
                        </Button>
                        {!shape && (
                            <span className="text-xs text-muted-foreground ml-2">Draw a shape on the map first</span>
                        )}
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
