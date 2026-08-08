import GeofenceDrawMap, {
    type GeofenceShape,
} from '@/components/geofence-draw-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { useForm } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Loader2,
    MapPin,
    Shield,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export type SiteGeofenceRecord = {
    id: number;
    name: string;
    type: 'circle' | 'polygon';
    shape: {
        center?: { lat: number; lng: number };
        radius_m?: number;
        coordinates?: { lat: number; lng: number }[];
    } | null;
    breach_type: 'enter' | 'exit' | 'both';
    is_active?: boolean;
    asset_id?: number | null;
    assigned_asset_ids?: number[];
};

type SiteGeofenceAsset = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    status?: string | null;
};

type SiteGeofenceForm = {
    name: string;
    type: 'circle' | 'polygon';
    shape: Record<string, any> | null;
    breach_type: 'enter' | 'exit' | 'both';
    is_active: boolean;
    asset_ids: number[];
};

type Props = {
    isOpen: boolean;
    onClose: () => void;
    onOpenLocation: () => void;
    siteId: number;
    siteName: string;
    siteLat?: string | number | null;
    siteLng?: string | number | null;
    existing?: SiteGeofenceRecord | null;
    assets: SiteGeofenceAsset[];
};

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

function normalizeInitialShape(
    existing?: SiteGeofenceRecord | null,
): GeofenceShape | null {
    if (!existing?.shape) return null;

    if (
        existing.type === 'circle' &&
        existing.shape.center &&
        existing.shape.radius_m
    ) {
        return {
            type: 'circle',
            center: {
                lat: Number(existing.shape.center.lat),
                lng: Number(existing.shape.center.lng),
            },
            radius_m: Number(existing.shape.radius_m),
        };
    }

    if (
        existing.type === 'polygon' &&
        Array.isArray(existing.shape.coordinates)
    ) {
        return {
            type: 'polygon',
            coordinates: existing.shape.coordinates.map((point) => ({
                lat: Number(point.lat),
                lng: Number(point.lng),
            })),
        };
    }

    return null;
}

function serializeShape(shape: GeofenceShape | null): {
    type: 'circle' | 'polygon';
    shape: Record<string, any> | null;
} {
    if (!shape) {
        return { type: 'circle', shape: null };
    }

    if (shape.type === 'circle') {
        return {
            type: 'circle',
            shape: {
                center: shape.center,
                radius_m: shape.radius_m,
            },
        };
    }

    return {
        type: 'polygon',
        shape: {
            coordinates: shape.coordinates ?? [],
        },
    };
}

export default function SiteGeofenceDialog({
    isOpen,
    onClose,
    onOpenLocation,
    siteId,
    siteName,
    siteLat,
    siteLng,
    existing,
    assets,
}: Props) {
    const lat = siteLat != null && siteLat !== '' ? Number(siteLat) : null;
    const lng = siteLng != null && siteLng !== '' ? Number(siteLng) : null;
    const hasCoords =
        lat != null && lng != null && !Number.isNaN(lat) && !Number.isNaN(lng);
    const center = hasCoords ? { lat, lng } : null;

    const initialShape = useMemo(
        () => normalizeInitialShape(existing),
        [existing],
    );
    const [shape, setShape] = useState<GeofenceShape | null>(initialShape);
    const assignedAssetIds = existing?.assigned_asset_ids ?? [];

    const form = useForm<SiteGeofenceForm>({
        name: existing?.name ?? `${siteName} Geofence`,
        type: existing?.type ?? 'circle',
        shape: existing?.shape ?? null,
        breach_type: existing?.breach_type ?? 'both',
        is_active: existing?.is_active ?? true,
        asset_ids: assignedAssetIds,
    });

    useEffect(() => {
        if (!isOpen) return;

        const nextShape = normalizeInitialShape(existing);
        setShape(nextShape);
        form.setData({
            name: existing?.name ?? `${siteName} Geofence`,
            type: existing?.type ?? 'circle',
            shape: existing?.shape ?? null,
            breach_type: existing?.breach_type ?? 'both',
            is_active: existing?.is_active ?? true,
            asset_ids: existing?.assigned_asset_ids ?? [],
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, existing?.id, siteName]);

    const toggleAsset = (assetId: number, checked: boolean) => {
        const next = checked
            ? Array.from(new Set([...form.data.asset_ids, assetId]))
            : form.data.asset_ids.filter((id) => id !== assetId);

        form.setData('asset_ids', next);
    };

    const submit = () => {
        const serialized = serializeShape(shape);
        if (!serialized.shape) {
            form.setError('shape', 'Draw the site boundary on the map first.');
            return;
        }

        const data = {
            ...form.data,
            type: serialized.type,
            shape: serialized.shape,
        };

        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };

        if (existing?.id) {
            form.transform(() => data);
            form.put(`/sites/${siteId}/geofence/${existing.id}`, options);
            return;
        }

        form.transform(() => data);
        form.post(`/sites/${siteId}/geofence`, options);
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[92vh] overflow-y-auto sm:max-w-[min(64rem,calc(100vw-2rem))]"
                data-test="site-geofence-dialog"
            >
                <DialogHeader>
                    <DialogTitle>Site Geofence</DialogTitle>
                    <DialogDescription>
                        Draw the boundary used for this site and choose which
                        site assets it applies to.
                    </DialogDescription>
                </DialogHeader>

                {!hasCoords || !center ? (
                    <div className="rounded-lg border border-dashed border-border/70 bg-muted/20 p-6 text-center">
                        <MapPin className="mx-auto h-8 w-8 text-muted-foreground" />
                        <h3 className="mt-3 text-sm font-semibold">
                            Pick the site address first
                        </h3>
                        <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                            The map needs a saved latitude and longitude before
                            a site geofence can be drawn.
                        </p>
                        <Button
                            type="button"
                            className="mt-4"
                            onClick={onOpenLocation}
                        >
                            Open Edit Location
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                        <div className="min-w-0 space-y-2">
                            <GeofenceDrawMap
                                center={center}
                                zoom={16}
                                height={430}
                                initialShape={initialShape}
                                onShapeChange={setShape}
                            />
                            <FieldError message={form.errors.shape} />
                        </div>

                        <div className="min-w-0 space-y-4">
                            <div className="space-y-2">
                                <Label htmlFor="site-geofence-name">Name</Label>
                                <Input
                                    id="site-geofence-name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                />
                                <FieldError message={form.errors.name} />
                            </div>

                            <div className="space-y-2">
                                <Label>Breach Type</Label>
                                <Select
                                    value={form.data.breach_type}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'breach_type',
                                            value as SiteGeofenceForm['breach_type'],
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="enter">
                                            Enter only
                                        </SelectItem>
                                        <SelectItem value="exit">
                                            Exit only
                                        </SelectItem>
                                        <SelectItem value="both">
                                            Enter and exit
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <FieldError message={form.errors.breach_type} />
                            </div>

                            <div className="flex items-center justify-between rounded-lg border border-border/60 p-3">
                                <div>
                                    <Label htmlFor="site-geofence-active">
                                        Active
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        Active geofences count toward readiness.
                                    </p>
                                </div>
                                <Switch
                                    id="site-geofence-active"
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_active', checked)
                                    }
                                />
                            </div>

                            <div className="rounded-lg border border-border/60">
                                <div className="flex items-center justify-between border-b border-border/60 px-3 py-2">
                                    <div className="flex items-center gap-2 text-sm font-medium">
                                        <Shield className="h-4 w-4 text-primary" />
                                        Assignments
                                    </div>
                                    <Badge variant="outline">
                                        {form.data.asset_ids.length}/
                                        {assets.length}
                                    </Badge>
                                </div>

                                {assets.length === 0 ? (
                                    <div className="flex items-start gap-2 p-3 text-sm text-muted-foreground">
                                        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                        No assets are linked to this site yet.
                                    </div>
                                ) : (
                                    <div className="max-h-56 overflow-y-auto p-2">
                                        {assets.map((asset) => {
                                            const checked =
                                                form.data.asset_ids.includes(
                                                    asset.id,
                                                );

                                            return (
                                                <label
                                                    key={asset.id}
                                                    className="flex cursor-pointer items-start gap-3 rounded-md px-2 py-2 hover:bg-muted/50"
                                                >
                                                    <Checkbox
                                                        checked={checked}
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            toggleAsset(
                                                                asset.id,
                                                                Boolean(value),
                                                            )
                                                        }
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm font-medium">
                                                            {asset.name}
                                                        </span>
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {[
                                                                asset.asset_tag,
                                                                asset.category,
                                                                asset.status,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ') ||
                                                                'Site asset'}
                                                        </span>
                                                    </span>
                                                    {checked && (
                                                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                                                    )}
                                                </label>
                                            );
                                        })}
                                    </div>
                                )}
                                <FieldError message={form.errors.asset_ids} />
                            </div>
                        </div>
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    {hasCoords && (
                        <Button
                            type="button"
                            onClick={submit}
                            disabled={form.processing}
                            data-test="site-geofence-save"
                        >
                            {form.processing && (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            )}
                            Save Geofence
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
