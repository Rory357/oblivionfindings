import { boundaryCentre } from '@/components/client-location/boundary-geometry';
import {
    geometryError,
    type Coordinate,
    type Geometry,
} from '@/components/client-location/types';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    ArrowLeft,
    ArrowRight,
    ClipboardCheck,
    Loader2,
    MapPin,
    Redo2,
    Save,
    Shapes,
    Tag,
    Undo2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AddressSearch } from './address-search';
import { base, query, request, RequestError } from './api';
import {
    type AddressCapabilities,
    type Boundary,
    type BoundaryRecord,
    type Impact,
    type SiteOption,
} from './data';
import { possibleOverlaps } from './geometry-model';
import {
    decodeGeometry,
    encodeGeometry,
    geometryMeasures,
    rectangleAt,
} from './geometry-tools';
import { BoundaryMap } from './map';
import { RemotePicker } from './remote-picker';
import { Button, ChoiceTiles, Field, Notice } from './ui';

const steps = [
    {
        key: 'location',
        label: 'Location',
        blurb: 'Choose a site or address',
        icon: MapPin,
    },
    {
        key: 'shape',
        label: 'Boundary',
        blurb: 'Draw and verify the area',
        icon: Shapes,
    },
    {
        key: 'uses',
        label: 'Name & uses',
        blurb: 'Name and permitted purposes',
        icon: Tag,
    },
    {
        key: 'review',
        label: 'Review & impact',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
];
const siteAddress = (site: SiteOption) =>
    [site.address_line_1, site.suburb, site.city].filter(Boolean).join(', ') ||
    site.name;
const siteShape = (site: SiteOption): Geometry | null => {
    if (site.latitude === null || site.longitude === null) return null;
    const lat = Number(site.latitude),
        lng = Number(site.longitude);
    return Number.isFinite(lat) &&
        Number.isFinite(lng) &&
        Math.abs(lat) <= 90 &&
        Math.abs(lng) <= 180
        ? { type: 'circle', center: { lat, lng }, radius_m: 180 }
        : null;
};
export function BoundaryWizard({
    existing,
    copy = false,
    point,
    boundaries,
    capabilities,
    initialSite,
    handoffToken,
    onClose,
    onSaved,
    onView,
}: {
    existing?: BoundaryRecord;
    copy?: boolean;
    point?: Coordinate;
    boundaries: Boundary[];
    capabilities: AddressCapabilities;
    initialSite?: SiteOption;
    handoffToken?: string;
    onClose: () => void;
    onSaved: (b: BoundaryRecord) => void;
    onView: (b: BoundaryRecord) => void;
}) {
    const edit = !!existing && !copy;
    const [step, setStep] = useState(0),
        [name, setName] = useState(
            existing ? existing.name + (copy ? ' — custom copy' : '') : '',
        ),
        [site, setSite] = useState<SiteOption | null>(
            initialSite ??
                (existing?.site_id
                    ? {
                          id: existing.site_id,
                          name: existing.site ?? 'Owning site',
                          address_line_1: existing.address,
                          suburb: null,
                          city: null,
                          latitude: null,
                          longitude: null,
                      }
                    : null),
        ),
        [address, setAddress] = useState(existing?.address ?? ''),
        [siteDetailsKnown, setSiteDetailsKnown] = useState(!!initialSite),
        [shape, setShape] = useState<Geometry | null>(
            existing?.geometry ??
                (point
                    ? { type: 'circle', center: point, radius_m: 180 }
                    : null),
        ),
        [verified, setVerified] = useState(false),
        [uses, setUses] = useState(existing?.uses ?? ['Vehicles', 'Assets']),
        [reason, setReason] = useState(''),
        [draw, setDraw] = useState<'none' | 'circle' | 'polygon'>('none'),
        [undo, setUndo] = useState<(Geometry | null)[]>([]),
        [redo, setRedo] = useState<(Geometry | null)[]>([]),
        [dirty, setDirty] = useState(false),
        [discard, setDiscard] = useState(false),
        [error, setError] = useState(''),
        [busy, setBusy] = useState(false),
        [saved, setSaved] = useState<BoundaryRecord | null>(null),
        [reviewed, setReviewed] = useState(false),
        [impact, setImpact] = useState<Impact | null>(null),
        [expected, setExpected] = useState(existing?.revision),
        [latest, setLatest] = useState<BoundaryRecord | null>(null),
        [fit, setFit] = useState(0),
        [transfer, setTransfer] = useState(''),
        [transferError, setTransferError] = useState(''),
        [requestKey, setRequestKey] = useState(() => crypto.randomUUID());
    useEffect(() => {
        if (initialSite && !site && !dirty) {
            setSite(initialSite);
            setSiteDetailsKnown(true);
        }
    }, [initialSite]);
    const change = (g: Geometry | null) => {
        setUndo((a) => [...a, shape]);
        setRedo([]);
        setShape(g);
        setVerified(false);
        setReviewed(false);
        setDirty(true);
    };
    const mark = () => {
        setDirty(true);
        setReviewed(false);
    };
    const consideredSite = useRef<number | null>(null);
    useEffect(() => {
        if (!site || consideredSite.current === site.id) return;
        consideredSite.current = site.id;
        // Owning Site and boundary location can differ. Prefill only a new,
        // untouched location; never move a drawn point or replace entered text.
        if (existing || point || shape || address.trim()) return;
        setAddress(siteAddress(site));
        setName((n) => n || site.name);
        setShape(siteShape(site));
        setVerified(false);
        setFit((n) => n + 1);
    }, [site, existing, point, shape, address]);
    const centre =
        shape && (shape.type === 'circle' || shape.coordinates.length)
            ? boundaryCentre(shape)
            : (point ?? { lat: -41.29, lng: 174.776 });
    const measures = geometryMeasures(shape),
        overlaps = possibleOverlaps(
            shape,
            boundaries.filter(
                (b) => Number(b.id) !== existing?.id && b.status !== 'Retired',
            ),
        );
    const close = () => {
        if (busy) return;
        if (dirty && !saved) setDiscard(true);
        else onClose();
    };
    const problem = (n: number) =>
        n === 0
            ? !site && !edit
                ? 'Select the owning site.'
                : !address.trim()
                  ? 'Enter a place or address.'
                  : null
            : n === 1
              ? geometryError(shape) ||
                (!verified
                    ? 'Verify the position and boundary before continuing.'
                    : null)
              : n === 2
                ? !name.trim()
                    ? 'Enter a name.'
                    : !uses.length
                      ? 'Choose a permitted use.'
                      : null
                : !reason.trim()
                  ? 'Record why this boundary is being saved.'
                  : !reviewed
                    ? 'Confirm the review before saving.'
                    : null;
    const inspect = async () => {
        if (!edit) return;
        const current = await request<{
            boundary: BoundaryRecord;
            impact: Impact;
        }>(base + '/' + existing!.id);
        setImpact(current.impact);
        return current;
    };
    const next = async () => {
        const p = problem(step);
        if (p) {
            setError(p);
            return;
        }
        setError('');
        if (step === 2) {
            setBusy(true);
            try {
                await inspect();
                setStep(3);
            } catch (e) {
                setError((e as Error).message);
            } finally {
                setBusy(false);
            }
        } else setStep(step + 1);
    };
    const save = async () => {
        for (let i = 0; i < 4; i++) {
            const p = problem(i);
            if (p) {
                setStep(i);
                setError(p);
                return;
            }
        }
        setBusy(true);
        setError('');
        try {
            const data = await request<{ boundary: BoundaryRecord }>(
                base + (edit ? '/' + existing!.id : ''),
                edit ? 'PUT' : 'POST',
                {
                    name,
                    address,
                    ...(site ? { site_id: site.id } : {}),
                    geometry: shape,
                    uses,
                    verified,
                    reason,
                    request_key: requestKey,
                    ...(handoffToken ? { handoff_token: handoffToken } : {}),
                    ...(edit ? { expected_revision: expected } : {}),
                    ...(copy && existing
                        ? {
                              copy_source: {
                                  id: existing.id,
                                  revision: existing.revision,
                              },
                          }
                        : {}),
                },
            );
            setSaved(data.boundary);
            setDirty(false);
            onSaved(data.boundary);
        } catch (e) {
            setError((e as Error).message);
            if (e instanceof RequestError && e.status === 409 && edit) {
                const current = await inspect().catch(() => null);
                setLatest(current?.boundary ?? null);
            }
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <WizardShell
                open
                title={
                    edit
                        ? 'Edit shared boundary'
                        : copy
                          ? 'Make a custom copy'
                          : 'Create boundary'
                }
                description="Define shared geometry. Saving does not activate tracking or alerts."
                onClose={close}
                railIcon={Shapes}
                railTitle="Shared boundary"
                railSub={
                    existing
                        ? 'BG-' +
                          existing.id +
                          ' · geometry v' +
                          existing.geometry_version
                        : 'New permitted area'
                }
                steps={steps}
                stepIndex={step}
                onStepClick={(n) => {
                    if (!busy) {
                        setStep(n);
                        setError('');
                    }
                }}
                maxWidth="min(92vw, 1100px)"
                maxHeight="min(88vh, 820px)"
                footerStart={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="ghost"
                                disabled={busy}
                                onClick={() => setStep(step - 1)}
                            >
                                <ArrowLeft />
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step < 3 ? (
                        <Button disabled={busy} onClick={next}>
                            Continue
                            <ArrowRight />
                        </Button>
                    ) : (
                        <Button disabled={busy} onClick={save}>
                            {busy ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <Save />
                            )}
                            Save boundary
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Boundary saved"
                            blurb={`${saved.name} · BG-${saved.id} · geometry v${saved.geometry_version}. No new monitoring has started.`}
                            actions={
                                <Button onClick={() => onView(saved)}>
                                    View boundary
                                    <ArrowRight />
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <fieldset
                        disabled={busy}
                        className="bnd-form flow-stack"
                        aria-busy={busy}
                    >
                        {error && <Notice title={error} tone="critical" />}
                        {latest && (
                            <Notice
                                title={
                                    'Current revision ' +
                                    latest.revision +
                                    ' · ' +
                                    latest.name
                                }
                                tone="warning"
                            >
                                Your draft is retained. The current shape is
                                shown below.
                                <BoundaryMap
                                    shape={latest.geometry}
                                    className="location-map"
                                />
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setExpected(latest.revision);
                                        setLatest(null);
                                        setRequestKey(crypto.randomUUID());
                                        setReviewed(false);
                                        setError('');
                                    }}
                                >
                                    I reviewed this version; keep my draft
                                </Button>
                            </Notice>
                        )}
                        {step === 0 && (
                            <>
                                <h2 className="text-section-title">
                                    Start with a place
                                </h2>
                                {edit ? (
                                    <Notice
                                        title={
                                            'Owned by ' +
                                            (site?.name ??
                                                'the source resource')
                                        }
                                    >
                                        Ownership stays with the existing
                                        record.
                                    </Notice>
                                ) : (
                                    <RemotePicker<SiteOption>
                                        label="Owning site"
                                        value={site?.name}
                                        url={(q) => query('/sites', { q })}
                                        describe={(s) => ({
                                            id: s.id,
                                            name: s.name,
                                            detail:
                                                s.address_line_1 ?? undefined,
                                        })}
                                        onSelect={(s) => {
                                            setSite(s);
                                            setSiteDetailsKnown(true);
                                            mark();
                                        }}
                                    />
                                )}
                                {site && siteDetailsKnown && (
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setAddress(siteAddress(site));
                                            setName((n) => n || site.name);
                                            change(siteShape(site));
                                            setFit((n) => n + 1);
                                        }}
                                    >
                                        Use site address
                                    </Button>
                                )}
                                {site &&
                                    siteDetailsKnown &&
                                    !siteShape(site) && (
                                        <Notice title="This site has no saved map position">
                                            Its address can still be used. Set
                                            and verify the boundary position on
                                            the map before saving.
                                        </Notice>
                                    )}
                                <AddressSearch
                                    capabilities={capabilities}
                                    onSelect={(h) => {
                                        setAddress(h.display_name);
                                        setName(
                                            (n) =>
                                                n ||
                                                h.display_name.split(',')[0],
                                        );
                                        change({
                                            type: 'circle',
                                            center: { lat: h.lat, lng: h.lng },
                                            radius_m: 180,
                                        });
                                        setFit((n) => n + 1);
                                    }}
                                />
                                <Field
                                    label="Address or place description"
                                    required
                                >
                                    <Input
                                        value={address}
                                        maxLength={500}
                                        onChange={(e) => {
                                            setAddress(e.target.value);
                                            mark();
                                        }}
                                    />
                                </Field>
                                <div className="two-fields">
                                    <Field label="Latitude">
                                        <Input
                                            type="number"
                                            step="0.000001"
                                            min={-90}
                                            max={90}
                                            value={centre.lat}
                                            onChange={(e) =>
                                                change({
                                                    type: 'circle',
                                                    center: {
                                                        ...centre,
                                                        lat: Number(
                                                            e.target.value,
                                                        ),
                                                    },
                                                    radius_m:
                                                        shape?.type === 'circle'
                                                            ? shape.radius_m
                                                            : 180,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label="Longitude">
                                        <Input
                                            type="number"
                                            step="0.000001"
                                            min={-180}
                                            max={180}
                                            value={centre.lng}
                                            onChange={(e) =>
                                                change({
                                                    type: 'circle',
                                                    center: {
                                                        ...centre,
                                                        lng: Number(
                                                            e.target.value,
                                                        ),
                                                    },
                                                    radius_m:
                                                        shape?.type === 'circle'
                                                            ? shape.radius_m
                                                            : 180,
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                                {shape && (
                                    <BoundaryMap
                                        shape={shape}
                                        fit={fit}
                                        className="location-map"
                                    />
                                )}
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <h2 className="text-section-title">
                                    Define the area
                                </h2>
                                <ChoiceTiles
                                    label="Boundary shape"
                                    value={shape?.type ?? ''}
                                    options={[
                                        {
                                            value: 'circle',
                                            label: 'Radius',
                                            description:
                                                'Centre and distance in metres',
                                            icon: MapPin,
                                        },
                                        {
                                            value: 'polygon',
                                            label: 'Polygon',
                                            description:
                                                'Draw and adjust corners',
                                            icon: Shapes,
                                        },
                                    ]}
                                    onChange={(v) => {
                                        change(
                                            v === 'circle'
                                                ? {
                                                      type: 'circle',
                                                      center: centre,
                                                      radius_m: 180,
                                                  }
                                                : {
                                                      type: 'polygon',
                                                      coordinates: [],
                                                  },
                                        );
                                        setDraw(v as 'circle' | 'polygon');
                                    }}
                                />
                                <div className="inline-row">
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            change(rectangleAt(centre));
                                            setDraw('none');
                                            setFit((n) => n + 1);
                                        }}
                                    >
                                        Start from rectangle
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setDraw('none');
                                        }}
                                    >
                                        Finish drawing
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={!undo.length}
                                        onClick={() => {
                                            setRedo((a) => [...a, shape]);
                                            setShape(undo.at(-1) ?? null);
                                            setUndo((a) => a.slice(0, -1));
                                            setVerified(false);
                                        }}
                                    >
                                        <Undo2 />
                                        Undo
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={!redo.length}
                                        onClick={() => {
                                            setUndo((a) => [...a, shape]);
                                            setShape(redo.at(-1) ?? null);
                                            setRedo((a) => a.slice(0, -1));
                                            setVerified(false);
                                        }}
                                    >
                                        <Redo2 />
                                        Redo
                                    </Button>
                                </div>
                                <BoundaryMap
                                    shape={shape}
                                    onShape={change}
                                    draw={draw}
                                    fit={fit}
                                    className="editor-map"
                                />
                                {shape?.type === 'circle' && (
                                    <Field label="Radius in metres" required>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={50000}
                                            value={shape.radius_m}
                                            onChange={(e) =>
                                                change({
                                                    ...shape,
                                                    radius_m: Number(
                                                        e.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                    </Field>
                                )}
                                {shape?.type === 'polygon' && (
                                    <div className="coordinate-grid">
                                        {shape.coordinates.map((p, i) => (
                                            <div className="inline-row" key={i}>
                                                <span>Corner {i + 1}</span>
                                                <Input
                                                    aria-label={
                                                        'Corner ' +
                                                        (i + 1) +
                                                        ' latitude'
                                                    }
                                                    type="number"
                                                    step="0.000001"
                                                    value={p.lat}
                                                    onChange={(e) =>
                                                        change({
                                                            ...shape,
                                                            coordinates:
                                                                shape.coordinates.map(
                                                                    (v, j) =>
                                                                        i === j
                                                                            ? {
                                                                                  ...v,
                                                                                  lat: Number(
                                                                                      e
                                                                                          .target
                                                                                          .value,
                                                                                  ),
                                                                              }
                                                                            : v,
                                                                ),
                                                        })
                                                    }
                                                />
                                                <Input
                                                    aria-label={
                                                        'Corner ' +
                                                        (i + 1) +
                                                        ' longitude'
                                                    }
                                                    type="number"
                                                    step="0.000001"
                                                    value={p.lng}
                                                    onChange={(e) =>
                                                        change({
                                                            ...shape,
                                                            coordinates:
                                                                shape.coordinates.map(
                                                                    (v, j) =>
                                                                        i === j
                                                                            ? {
                                                                                  ...v,
                                                                                  lng: Number(
                                                                                      e
                                                                                          .target
                                                                                          .value,
                                                                                  ),
                                                                              }
                                                                            : v,
                                                                ),
                                                        })
                                                    }
                                                />
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        change({
                                                            ...shape,
                                                            coordinates:
                                                                shape.coordinates.filter(
                                                                    (_, j) =>
                                                                        i !== j,
                                                                ),
                                                        })
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {measures && (
                                    <p>
                                        {(measures.area / 10000).toLocaleString(
                                            'en-NZ',
                                            { maximumFractionDigits: 2 },
                                        )}{' '}
                                        ha ·{' '}
                                        {Math.round(
                                            measures.perimeter,
                                        ).toLocaleString('en-NZ')}{' '}
                                        m perimeter
                                    </p>
                                )}
                                <details className="details-box">
                                    <summary>Import or copy GeoJSON</summary>
                                    <Field label="GeoJSON geometry">
                                        <textarea
                                            className="w-full rounded border p-3"
                                            value={transfer}
                                            onChange={(e) =>
                                                setTransfer(e.target.value)
                                            }
                                        />
                                    </Field>
                                    {transferError && (
                                        <p role="alert">{transferError}</p>
                                    )}
                                    <div className="inline-row">
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                try {
                                                    const decoded =
                                                        decodeGeometry(
                                                            transfer,
                                                        );
                                                    if (!decoded.shape)
                                                        throw new Error(
                                                            decoded.error,
                                                        );
                                                    change(decoded.shape);
                                                    setTransferError('');
                                                    setFit((n) => n + 1);
                                                } catch (e) {
                                                    setTransferError(
                                                        (e as Error).message,
                                                    );
                                                }
                                            }}
                                        >
                                            Use geometry
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={!shape}
                                            onClick={() =>
                                                shape &&
                                                setTransfer(
                                                    encodeGeometry(shape),
                                                )
                                            }
                                        >
                                            Show current GeoJSON
                                        </Button>
                                    </div>
                                </details>
                                <label className="check-row">
                                    <Checkbox
                                        checked={verified}
                                        onCheckedChange={(v) => {
                                            setVerified(v === true);
                                            mark();
                                        }}
                                    />
                                    I have verified the position, shape and
                                    radius or corners
                                </label>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <h2 className="text-section-title">
                                    Name & permitted uses
                                </h2>
                                <Field label="Boundary name" required>
                                    <Input
                                        value={name}
                                        maxLength={120}
                                        onChange={(e) => {
                                            setName(e.target.value);
                                            mark();
                                        }}
                                    />
                                </Field>
                                {['Vehicles', 'Assets'].map((use) => (
                                    <label className="check-row" key={use}>
                                        <Checkbox
                                            checked={uses.includes(use)}
                                            onCheckedChange={(checked) => {
                                                setUses((a) =>
                                                    checked
                                                        ? [...a, use]
                                                        : a.filter(
                                                              (x) => x !== use,
                                                          ),
                                                );
                                                mark();
                                            }}
                                        />
                                        {use}
                                    </label>
                                ))}
                                <Notice title="Person and client purposes have separate authority">
                                    Client Location checks eligibility, the
                                    current assignment and consent. Vehicle and
                                    asset use never grants personal tracking.
                                </Notice>
                                <Field label="Reason for saving" required>
                                    <textarea
                                        className="w-full rounded border p-3"
                                        maxLength={1000}
                                        value={reason}
                                        onChange={(e) => {
                                            setReason(e.target.value);
                                            mark();
                                        }}
                                    />
                                </Field>
                            </>
                        )}
                        {step === 3 && (
                            <>
                                <h2 className="text-section-title">
                                    Review & impact
                                </h2>
                                <ReviewCard icon={Shapes} title="Shared area">
                                    <ReviewRow label="Name" value={name} />
                                    <ReviewRow
                                        label="Owning site"
                                        value={site?.name ?? 'Source resource'}
                                    />
                                    <ReviewRow label="Place" value={address} />
                                    <ReviewRow
                                        label="Permitted uses"
                                        value={uses.join(', ')}
                                    />
                                    <ReviewRow label="Reason" value={reason} />
                                </ReviewCard>
                                <BoundaryMap
                                    shape={shape}
                                    className="location-map"
                                />
                                {overlaps.length > 0 && (
                                    <Notice title="Possible overlapping areas">
                                        {overlaps
                                            .slice(0, 5)
                                            .map((b) => b.name)
                                            .join(', ')}
                                        . This checks loaded areas only;
                                        overlapping purposes remain independent.
                                    </Notice>
                                )}
                                {impact?.geometry_blocked && (
                                    <Notice
                                        tone="warning"
                                        title="Shared geometry has protected or active dependencies"
                                    >
                                        Geometry changes require the owning
                                        workflow to review these dependencies.
                                        Private identities are not disclosed.
                                        You can make a custom copy.
                                    </Notice>
                                )}
                                {impact?.linked_assignments && (
                                    <Notice title="Linked assignments will need review">
                                        Source changes are detected without
                                        replacing their retained geometry or
                                        activating monitoring.
                                    </Notice>
                                )}
                                <label className="check-row">
                                    <Checkbox
                                        checked={reviewed}
                                        onCheckedChange={(v) =>
                                            setReviewed(v === true)
                                        }
                                    />
                                    I reviewed the area, permitted uses and
                                    impact
                                </label>
                            </>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                title="Discard this boundary draft?"
                description="Unsaved changes will be lost. The saved boundary remains available."
                confirmText="Discard draft"
                onConfirm={onClose}
            />
        </>
    );
}
