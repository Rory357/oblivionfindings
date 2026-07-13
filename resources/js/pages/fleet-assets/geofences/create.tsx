import GeofenceDrawMap, {
    type GeofenceShape,
} from '@/components/geofence-draw-map';
import { Button } from '@/components/ui/button';
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
import {
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    Bell,
    ClipboardCheck,
    Loader2,
    MapPin,
    Save,
    Shapes,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

export type GeofenceAssetOption = {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
};

export type GeofenceSiteOption = {
    id: number;
    name: string;
    latitude: number | null;
    longitude: number | null;
};

export type EditableGeofence = {
    id: number;
    name: string;
    description?: string | null;
    scope: 'vehicle' | 'resident';
    breach_type: string;
    is_active: boolean;
    shape: GeofenceShape | null;
    time_rules?: { start?: string; end?: string } | null;
    alert_config?: {
        on_enter?: boolean;
        on_exit?: boolean;
        on_speed?: boolean;
        severity?: string;
        notify_control_room?: boolean;
    } | null;
    asset_id?: number | null;
    site_id?: number | null;
};

const steps = [
    {
        key: 'scope',
        label: 'Scope & name',
        blurb: 'Name and link the boundary',
        icon: Shapes,
    },
    {
        key: 'shape',
        label: 'Draw area',
        blurb: 'Draw the monitored boundary',
        icon: MapPin,
    },
    {
        key: 'alerts',
        label: 'Alerts & schedule',
        blurb: 'Choose triggers and active hours',
        icon: Bell,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before saving',
        icon: ClipboardCheck,
    },
] as const satisfies readonly WizardStep[];

export function GeofenceWizard({
    open,
    assets,
    sites,
    prefillSiteId,
    geofence,
    onClose,
}: {
    open: boolean;
    assets: GeofenceAssetOption[];
    sites: GeofenceSiteOption[];
    prefillSiteId?: string | null;
    geofence?: EditableGeofence | null;
    onClose: () => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [assetId, setAssetId] = useState('');
    const [siteId, setSiteId] = useState('');
    const [breachType, setBreachType] = useState('both');
    const [isActive, setIsActive] = useState(true);
    const [scope, setScope] = useState<'vehicle' | 'resident'>('vehicle');
    const [alertOnEnter, setAlertOnEnter] = useState(true);
    const [alertOnExit, setAlertOnExit] = useState(true);
    const [alertOnSpeed, setAlertOnSpeed] = useState(false);
    const [alertSeverity, setAlertSeverity] = useState('medium');
    const [notifyControlRoom, setNotifyControlRoom] = useState(false);
    const [scheduleStart, setScheduleStart] = useState('');
    const [scheduleEnd, setScheduleEnd] = useState('');
    const [shape, setShape] = useState<GeofenceShape | null>(null);
    const [mapCenter, setMapCenter] = useState({
        lat: -36.8485,
        lng: 174.7633,
    });

    const selectedSite = useMemo(
        () => sites.find((site) => String(site.id) === siteId) ?? null,
        [siteId, sites],
    );

    useEffect(() => {
        if (!open) return;

        const initialSiteId = geofence?.site_id
            ? String(geofence.site_id)
            : (prefillSiteId ?? '');
        const initialSite = sites.find(
            (site) => String(site.id) === initialSiteId,
        );

        setStepIndex(0);
        setErrors({});
        setName(geofence?.name ?? '');
        setDescription(geofence?.description ?? '');
        setAssetId(geofence?.asset_id ? String(geofence.asset_id) : '');
        setSiteId(initialSiteId);
        setBreachType(geofence?.breach_type ?? 'both');
        setIsActive(geofence?.is_active ?? true);
        setScope(geofence?.scope ?? 'vehicle');
        setAlertOnEnter(geofence?.alert_config?.on_enter ?? true);
        setAlertOnExit(geofence?.alert_config?.on_exit ?? true);
        setAlertOnSpeed(geofence?.alert_config?.on_speed ?? false);
        setAlertSeverity(geofence?.alert_config?.severity ?? 'medium');
        setNotifyControlRoom(
            geofence?.alert_config?.notify_control_room ?? false,
        );
        setScheduleStart(geofence?.time_rules?.start ?? '');
        setScheduleEnd(geofence?.time_rules?.end ?? '');
        setShape(geofence?.shape ?? null);

        if (geofence?.shape?.type === 'circle' && geofence.shape.center) {
            setMapCenter(geofence.shape.center);
        } else if (initialSite?.latitude && initialSite.longitude) {
            setMapCenter({
                lat: initialSite.latitude,
                lng: initialSite.longitude,
            });
            if (!geofence) {
                setShape({
                    type: 'circle',
                    center: {
                        lat: initialSite.latitude,
                        lng: initialSite.longitude,
                    },
                    radius_m: 200,
                });
                setName(`${initialSite.name} Geofence`);
            }
        }
    }, [geofence, open, prefillSiteId, sites]);

    const handleSiteQuickFill = useCallback(
        (value: string) => {
            setSiteId(value);
            const site = sites.find((item) => String(item.id) === value);
            if (site?.latitude && site.longitude) {
                setMapCenter({ lat: site.latitude, lng: site.longitude });
                setShape({
                    type: 'circle',
                    center: { lat: site.latitude, lng: site.longitude },
                    radius_m: 200,
                });
                if (!name) setName(`${site.name} Geofence`);
            }
        },
        [name, sites],
    );

    const canLeaveScope = name.trim().length > 0;
    const canReview = canLeaveScope && !!shape;

    const submit = () => {
        if (!canLeaveScope) {
            setErrors({ name: 'Name is required.' });
            setStepIndex(0);
            return;
        }
        if (!shape) {
            setErrors({ shape: 'Please draw a geofence on the map first.' });
            setStepIndex(1);
            return;
        }

        setProcessing(true);
        const shapeType = shape.type === 'rectangle' ? 'polygon' : shape.type;
        const shapeData =
            shape.type === 'circle'
                ? { center: shape.center, radius_m: shape.radius_m }
                : { coordinates: shape.coordinates };
        const payload = {
            asset_id: assetId || null,
            site_id: siteId || null,
            name,
            description: description || null,
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
            time_rules:
                scheduleStart || scheduleEnd
                    ? { start: scheduleStart || null, end: scheduleEnd || null }
                    : null,
            is_active: isActive,
        };
        const options = {
            preserveScroll: true,
            onSuccess: onClose,
            onFinish: () => setProcessing(false),
            onError: (nextErrors: Record<string, string>) =>
                setErrors(nextErrors),
        };

        if (geofence) {
            router.put(`/fleet-assets/geofences/${geofence.id}`, payload, options);
        } else {
            router.post('/fleet-assets/geofences', payload, options);
        }
    };

    const goNext = () => {
        if (stepIndex === 0 && !canLeaveScope) {
            setErrors({ name: 'Name is required.' });
            return;
        }
        if (stepIndex === 1 && !shape) {
            setErrors({ shape: 'Please draw a geofence on the map first.' });
            return;
        }
        setErrors({});
        setStepIndex((current) => Math.min(current + 1, steps.length - 1));
    };

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={geofence ? 'Edit geofence' : 'Create geofence'}
            description="Define the boundary, alert rules, and schedule, then review before saving."
            railIcon={MapPin}
            railTitle={geofence ? 'Edit geofence' : 'Create geofence'}
            railSub={selectedSite?.name ?? 'Fleet & Assets'}
            steps={steps}
            stepIndex={stepIndex}
            onStepClick={(index) => {
                if (index === 0 || (index === 1 && canLeaveScope) || canReview) {
                    setStepIndex(index);
                }
            }}
            pct={Math.round(((stepIndex + 1) / steps.length) * 100)}
            maxWidth="min(96vw, 1120px)"
            maxHeight="min(90vh, 820px)"
            footerStart={
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                <>
                    {stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStepIndex(stepIndex - 1)}
                        >
                            <ArrowLeft className="mr-2 h-4 w-4" /> Back
                        </Button>
                    ) : null}
                    {stepIndex < steps.length - 1 ? (
                        <Button type="button" onClick={goNext}>
                            Continue
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={processing || !canReview}
                        >
                            {processing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Save className="mr-2 h-4 w-4" />
                            )}
                            {geofence ? 'Save changes' : 'Create geofence'}
                        </Button>
                    )}
                </>
            }
        >
            <WizardStepPane>
                {stepIndex === 0 ? (
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <Label htmlFor="geofence-name">Name</Label>
                            <Input
                                id="geofence-name"
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                placeholder="e.g. Kauri House boundary"
                            />
                            {errors.name ? (
                                <p className="text-sm text-destructive">{errors.name}</p>
                            ) : null}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="geofence-description">Description</Label>
                            <Textarea
                                id="geofence-description"
                                value={description}
                                onChange={(event) =>
                                    setDescription(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Scope</Label>
                            <div className="flex gap-2">
                                {(['vehicle', 'resident'] as const).map((value) => (
                                    <Button
                                        key={value}
                                        type="button"
                                        variant="outline"
                                        onClick={() => setScope(value)}
                                        className={cn(
                                            scope === value &&
                                                'border-primary bg-primary/10 text-primary',
                                        )}
                                    >
                                        {value === 'vehicle' ? 'Vehicle' : 'Resident'}
                                    </Button>
                                ))}
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="geofence-site">Quick-fill from site</Label>
                                <Select value={siteId} onValueChange={handleSiteQuickFill}>
                                    <SelectTrigger id="geofence-site">
                                        <SelectValue placeholder="Select site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map((site) => (
                                            <SelectItem key={site.id} value={String(site.id)}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="geofence-asset">Link to asset</Label>
                                <Select value={assetId} onValueChange={setAssetId}>
                                    <SelectTrigger id="geofence-asset">
                                        <SelectValue placeholder="Select asset" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {assets.map((asset) => (
                                            <SelectItem key={asset.id} value={String(asset.id)}>
                                                {asset.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                ) : null}

                {stepIndex === 1 ? (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Draw a circle or polygon. This large map remains scroll-safe inside the wizard.
                        </p>
                        <GeofenceDrawMap
                            center={mapCenter}
                            zoom={13}
                            height={500}
                            initialShape={shape}
                            onShapeChange={setShape}
                        />
                        {errors.shape ? (
                            <p className="text-sm font-medium text-destructive">
                                {errors.shape}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                {stepIndex === 2 ? (
                    <div className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="geofence-breach">Breach type</Label>
                                <Select value={breachType} onValueChange={setBreachType}>
                                    <SelectTrigger id="geofence-breach"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="enter">Enter only</SelectItem>
                                        <SelectItem value="exit">Exit only</SelectItem>
                                        <SelectItem value="both">Enter and exit</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="geofence-severity">Severity</Label>
                                <Select value={alertSeverity} onValueChange={setAlertSeverity}>
                                    <SelectTrigger id="geofence-severity"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {['low', 'medium', 'high', 'critical'].map((value) => (
                                            <SelectItem key={value} value={value}>{value}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {[
                                ['Alert on enter', alertOnEnter, setAlertOnEnter],
                                ['Alert on exit', alertOnExit, setAlertOnExit],
                                ['Alert on speed violation', alertOnSpeed, setAlertOnSpeed],
                                ['Notify Control Room', notifyControlRoom, setNotifyControlRoom],
                            ].map(([label, checked, setter]) => (
                                <label key={String(label)} className="flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={Boolean(checked)}
                                        onChange={(event) =>
                                            (setter as (value: boolean) => void)(event.target.checked)
                                        }
                                    />
                                    {String(label)}
                                </label>
                            ))}
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="geofence-start">Active from</Label>
                                <Input id="geofence-start" type="time" value={scheduleStart} onChange={(event) => setScheduleStart(event.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="geofence-end">Active until</Label>
                                <Input id="geofence-end" type="time" value={scheduleEnd} onChange={(event) => setScheduleEnd(event.target.value)} />
                            </div>
                        </div>
                        <label className="flex min-h-11 items-center gap-3 rounded-lg border p-3 text-sm">
                            <input type="checkbox" checked={isActive} onChange={(event) => setIsActive(event.target.checked)} />
                            Geofence is active
                        </label>
                    </div>
                ) : null}

                {stepIndex === 3 ? (
                    <div className="space-y-4">
                        <h3 className="text-lg font-semibold">Review geofence</h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {[
                                ['Name', name],
                                ['Scope', scope],
                                ['Site', selectedSite?.name ?? 'Not linked'],
                                ['Shape', shape?.type ?? 'Not drawn'],
                                ['Breach alerts', breachType],
                                ['Severity', alertSeverity],
                                ['Schedule', scheduleStart || scheduleEnd ? `${scheduleStart || 'Any'}–${scheduleEnd || 'Any'}` : 'Always active'],
                                ['Status', isActive ? 'Active' : 'Inactive'],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-lg border bg-muted/20 p-3">
                                    <div className="text-xs text-muted-foreground">{label}</div>
                                    <div className="mt-1 text-sm font-semibold capitalize">{value}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}
