import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    InfoCard,
    Ring,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Box,
    Camera,
    Car,
    CheckCircle2,
    Cloud,
    Eye,
    Hammer,
    HeartPulse,
    HelpCircle,
    Lock,
    MapPin,
    Plus,
    Search,
    ShieldAlert,
    Trash2,
    Truck,
    Users,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ComponentType } from 'react';

export type ReportMode = 'vehicle' | 'asset' | 'near_miss';

type FormOptions = {
    assets: Array<{
        id: number;
        name: string;
        registration_number: string | null;
        category: string | null;
    }>;
    users: Array<{ id: number; name: string }>;
    sites: Array<{ id: number; name: string }>;
    types: string[];
    severities: string[];
    injury_severities: string[];
    damage_classifications: string[];
};

type IconType = ComponentType<{ className?: string }>;

type ThirdParty = {
    name: string;
    contact: string;
    vehicle_rego: string;
    insurer: string;
    claim_ref: string;
    liability: string;
};
type WitnessRow = { name: string; contact: string };

type FleetReportForm = {
    asset_id: string;
    incident_type: string;
    severity: string;
    occurred_at: string;
    location: string;
    description: string;
    immediate_action_taken: string;

    driver_user_id: string;
    driver_on_duty: boolean;
    people_aboard_count: string;
    whanau_informed: boolean;
    third_party_involved: boolean;
    third_parties: ThirdParty[];
    witnesses: WitnessRow[];
    road_type: string;
    weather: string;
    lighting: string;
    traffic_conditions: string;
    speed_limit: string;
    estimated_speed: string;
    manoeuvre: string;
    road_hazard: string;
    damage_classification: string;
    is_drivable: boolean;
    tow_required: boolean;
    damage_details: { areas: string[]; estimated_cost: string };
    vehicle_off_road: boolean;
    injury_involved: boolean;
    injury_severity: string;
    traffic_crash_report_reference: string;
    acc_claim_lodged: boolean;
    acc_claim_reference: string;
    breath_test_administered: boolean;
    drug_test_administered: boolean;
    insurer_name: string;
    insurance_reference: string;
    insurance_excess: string;

    asset_serial_snapshot: string;
    asset_condition_before: string;
    asset_condition_after: string;
    warranty_status: string;
    replacement_cost: string;

    potential_severity: string;
    contributing_factors: string[];
};

const MODE_META: Record<
    ReportMode,
    { railTitle: string; railSub: string; icon: IconType }
> = {
    vehicle: {
        railTitle: 'Vehicle incident',
        railSub: 'Report a fleet vehicle incident',
        icon: Truck,
    },
    asset: {
        railTitle: 'Asset incident',
        railSub: 'Report an equipment incident',
        icon: Box,
    },
    near_miss: {
        railTitle: 'Near miss',
        railSub: 'Blame-free near-miss report',
        icon: Eye,
    },
};

const VEHICLE_TYPE_TILES = [
    { key: 'collision', label: 'Collision', icon: Car as IconType },
    { key: 'damage', label: 'Damage', icon: Hammer as IconType },
    { key: 'theft', label: 'Theft', icon: Lock as IconType },
    { key: 'vandalism', label: 'Vandalism', icon: ShieldAlert as IconType },
    { key: 'breakdown', label: 'Breakdown', icon: Wrench as IconType },
    { key: 'other', label: 'Other', icon: HelpCircle as IconType },
];
const ASSET_TYPE_TILES = [
    { key: 'damage', label: 'Damage', icon: Hammer as IconType },
    { key: 'theft', label: 'Theft', icon: Lock as IconType },
    { key: 'vandalism', label: 'Vandalism', icon: ShieldAlert as IconType },
    { key: 'breakdown', label: 'Fault / breakdown', icon: Wrench as IconType },
    { key: 'other', label: 'Other', icon: HelpCircle as IconType },
];
const SEVERITY_TILES = [
    {
        key: 'minor',
        label: 'Minor',
        description: 'Cosmetic / no injury',
        icon: CheckCircle2 as IconType,
    },
    {
        key: 'moderate',
        label: 'Moderate',
        description: 'Repairable / minor injury',
        icon: Activity as IconType,
    },
    {
        key: 'major',
        label: 'Major',
        description: 'Serious damage or injury',
        icon: AlertTriangle as IconType,
        accent: 'text-status-critical',
    },
    {
        key: 'critical',
        label: 'Critical',
        description: 'Write-off / life-threatening',
        icon: ShieldAlert as IconType,
        accent: 'text-status-critical',
    },
];
const INJURY_TILES = [
    {
        key: 'first_aid',
        label: 'First aid',
        description: 'Treated on scene',
        icon: HeartPulse as IconType,
    },
    {
        key: 'medical',
        label: 'Medical',
        description: 'GP / urgent care',
        icon: Activity as IconType,
    },
    {
        key: 'hospitalisation',
        label: 'Hospitalisation',
        description: 'Admitted to hospital',
        icon: AlertTriangle as IconType,
        accent: 'text-status-critical',
    },
    {
        key: 'death',
        label: 'Fatal',
        description: 'Fatality',
        icon: ShieldAlert as IconType,
        accent: 'text-status-critical',
    },
];
const DAMAGE_CLASS_TILES = [
    {
        key: 'light',
        label: 'Light',
        description: 'Cosmetic only',
        icon: CheckCircle2 as IconType,
    },
    {
        key: 'repairable',
        label: 'Repairable',
        description: 'Structurally sound',
        icon: Activity as IconType,
    },
    {
        key: 'write_off',
        label: 'Write-off',
        description: 'Beyond repair',
        icon: AlertTriangle as IconType,
        accent: 'text-status-critical',
    },
];

const yesNo = (yes: string, no: string) => [
    { value: 'yes', label: yes },
    { value: 'no', label: no },
];
const roadTypeOpts = [
    { value: 'urban', label: 'Urban' },
    { value: 'rural', label: 'Rural' },
    { value: 'motorway', label: 'Motorway' },
    { value: 'private', label: 'Private' },
];
const weatherOpts = [
    { value: 'clear', label: 'Clear' },
    { value: 'rain', label: 'Rain' },
    { value: 'fog', label: 'Fog' },
    { value: 'ice', label: 'Ice' },
];
const lightingOpts = [
    { value: 'daylight', label: 'Daylight' },
    { value: 'dusk', label: 'Dusk/Dawn' },
    { value: 'dark', label: 'Dark (lit)' },
    { value: 'dark_unlit', label: 'Dark (unlit)' },
];
const trafficOpts = [
    { value: 'light', label: 'Light' },
    { value: 'moderate', label: 'Moderate' },
    { value: 'heavy', label: 'Heavy' },
];
const conditionOpts = [
    { value: 'good', label: 'Good' },
    { value: 'fair', label: 'Fair' },
    { value: 'poor', label: 'Poor' },
    { value: 'destroyed', label: 'Destroyed' },
];
const warrantyOpts = [
    { value: 'in_warranty', label: 'In warranty' },
    { value: 'out_of_warranty', label: 'Out of warranty' },
    { value: 'unknown', label: 'Unknown' },
];
const FACTORS = [
    'Driver distraction',
    'Fatigue',
    'Road conditions',
    'Weather',
    'Mechanical fault',
    'Visibility',
    'Other vehicle',
    'Pedestrian',
    'Cargo shift',
    'Speed',
    'Signage',
];
const DAMAGE_AREAS = [
    'Front',
    'Rear',
    'Left side',
    'Right side',
    'Roof',
    'Undercarriage',
];

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
function localNow(): string {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
}

const VEHICLE_STEPS: WizardStep[] = [
    {
        key: 'vehicle_driver',
        label: 'Vehicle & driver',
        blurb: 'Which vehicle and who',
        icon: Truck,
    },
    {
        key: 'scene',
        label: 'What happened',
        blurb: 'Scene, conditions & narrative',
        icon: MapPin,
    },
    {
        key: 'people',
        label: 'People',
        blurb: 'Aboard, third parties, witnesses',
        icon: Users,
    },
    {
        key: 'damage',
        label: 'Damage & drivability',
        blurb: 'Condition & recovery',
        icon: AlertTriangle,
    },
    {
        key: 'police_reg',
        label: 'Police & regulatory',
        blurb: 'NZ s22 + ACC + tests',
        icon: ShieldAlert,
    },
    {
        key: 'review',
        label: 'Review & submit',
        blurb: 'Check and submit',
        icon: CheckCircle2,
    },
];
const ASSET_STEPS: WizardStep[] = [
    {
        key: 'asset_details',
        label: 'Asset details',
        blurb: 'Which asset and type',
        icon: Box,
    },
    {
        key: 'what',
        label: 'What happened',
        blurb: 'Description & date',
        icon: Search,
    },
    {
        key: 'condition',
        label: 'Condition & cost',
        blurb: 'Before/after & cost',
        icon: Activity,
    },
    {
        key: 'review',
        label: 'Review & submit',
        blurb: 'Check and submit',
        icon: CheckCircle2,
    },
];
const NEAR_MISS_STEPS: WizardStep[] = [
    {
        key: 'nm_vehicle',
        label: 'Vehicle & driver',
        blurb: 'Blame-free — thank you',
        icon: Eye,
    },
    {
        key: 'nm_what',
        label: 'What happened',
        blurb: 'What nearly happened',
        icon: Search,
    },
    {
        key: 'nm_could',
        label: 'Could have happened',
        blurb: 'Potential & hazard',
        icon: AlertTriangle,
    },
    {
        key: 'review',
        label: 'Review & submit',
        blurb: 'Check and submit',
        icon: CheckCircle2,
    },
];

/**
 * Branched report wizard (vehicle 6-step / asset 4-step / near-miss 4-step) on
 * WizardShell. Posts the full capture set to the existing `store`; photos are
 * attached from the detail view once the incident exists (the store has no record
 * id to attach to yet — same contract as the H&S incident wizard).
 */
export function FleetIncidentReportDialog({
    open,
    onClose,
    mode,
    formOptions,
    onOpenIncident,
    initialAssetId,
}: {
    open: boolean;
    onClose: () => void;
    mode: ReportMode;
    formOptions: FormOptions;
    onOpenIncident?: (id: number) => void;
    /** Pre-selects the asset when arriving via ?report=…&asset_id=… (dashboard / vehicle quick actions). */
    initialAssetId?: string | number | null;
}) {
    const page = usePage().props as {
        flash?: {
            created_fleet_incident_id?: number;
            created_fleet_incident_reference?: string;
            error?: string;
        };
    };
    const [stepIndex, setStepIndex] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const [assetSearch, setAssetSearch] = useState('');
    const [driverSearch, setDriverSearch] = useState('');
    const [assetOptions, setAssetOptions] = useState(formOptions.assets);
    const [driverOptions, setDriverOptions] = useState(formOptions.users);

    const form = useForm<FleetReportForm>({
        asset_id:
            initialAssetId != null && initialAssetId !== ''
                ? String(initialAssetId)
                : '',
        incident_type:
            mode === 'near_miss'
                ? 'near_miss'
                : mode === 'asset'
                  ? 'damage'
                  : 'collision',
        severity: mode === 'near_miss' ? 'minor' : 'moderate',
        occurred_at: localNow(),
        location: '',
        description: '',
        immediate_action_taken: '',
        driver_user_id: '',
        driver_on_duty: false,
        people_aboard_count: '',
        whanau_informed: false,
        third_party_involved: false,
        third_parties: [],
        witnesses: [],
        road_type: '',
        weather: '',
        lighting: '',
        traffic_conditions: '',
        speed_limit: '',
        estimated_speed: '',
        manoeuvre: '',
        road_hazard: '',
        damage_classification: '',
        is_drivable: true,
        tow_required: false,
        damage_details: { areas: [], estimated_cost: '' },
        vehicle_off_road: false,
        injury_involved: false,
        injury_severity: '',
        traffic_crash_report_reference: '',
        acc_claim_lodged: false,
        acc_claim_reference: '',
        breath_test_administered: false,
        drug_test_administered: false,
        insurer_name: '',
        insurance_reference: '',
        insurance_excess: '',
        asset_serial_snapshot: '',
        asset_condition_before: '',
        asset_condition_after: '',
        warranty_status: '',
        replacement_cost: '',
        potential_severity: '',
        contributing_factors: [],
    });
    const { data, setData, errors, processing } = form;

    useEffect(() => {
        const query = assetSearch.trim();
        if (query.length < 2) {
            setAssetOptions(formOptions.assets);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    `/fleet-assets/incidents/options/search?type=assets&q=${encodeURIComponent(query)}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (response.ok)
                    setAssetOptions((await response.json()).results ?? []);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') setAssetOptions([]);
            }
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [assetSearch, formOptions.assets]);

    useEffect(() => {
        const query = driverSearch.trim();
        if (query.length < 2) {
            setDriverOptions(formOptions.users);
            return;
        }
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    `/fleet-assets/incidents/options/search?type=users&q=${encodeURIComponent(query)}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (response.ok)
                    setDriverOptions((await response.json()).results ?? []);
            } catch (error) {
                if ((error as Error).name !== 'AbortError')
                    setDriverOptions([]);
            }
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [driverSearch, formOptions.users]);

    const steps = useMemo(
        () =>
            mode === 'vehicle'
                ? VEHICLE_STEPS
                : mode === 'asset'
                  ? ASSET_STEPS
                  : NEAR_MISS_STEPS,
        [mode],
    );
    const lastIndex = steps.length - 1;
    const stepKey = steps[stepIndex].key;

    const selectedAsset = [...assetOptions, ...formOptions.assets].find(
        (asset) => String(asset.id) === data.asset_id,
    );
    const selectedDriver = [...driverOptions, ...formOptions.users].find(
        (user) => String(user.id) === data.driver_user_id,
    );
    const visibleAssetOptions =
        selectedAsset &&
        !assetOptions.some((asset) => asset.id === selectedAsset.id)
            ? [selectedAsset, ...assetOptions]
            : assetOptions;
    const visibleDriverOptions =
        selectedDriver &&
        !driverOptions.some((user) => user.id === selectedDriver.id)
            ? [selectedDriver, ...driverOptions]
            : driverOptions;
    const vehicleOptions = visibleAssetOptions.map((a) => ({
        value: String(a.id),
        label: a.registration_number
            ? `${a.name} · ${a.registration_number}`
            : a.name,
    }));
    const assetOnlyOptions = visibleAssetOptions
        .filter((a) => a.category && a.category !== 'vehicle')
        .map((a) => ({ value: String(a.id), label: a.name }));
    const userOptions = visibleDriverOptions.map((u) => ({
        value: String(u.id),
        label: u.name,
    }));
    const requiresImmediateAction = ['major', 'critical'].includes(
        data.severity,
    );

    const tpField = (key: keyof ThirdParty, value: string) => {
        const existing: ThirdParty = data.third_parties[0] ?? {
            name: '',
            contact: '',
            vehicle_rego: '',
            insurer: '',
            claim_ref: '',
            liability: '',
        };
        setData('third_parties', [{ ...existing, [key]: value }]);
    };

    const pct = useMemo(() => {
        const checks =
            mode === 'vehicle'
                ? [
                      !!data.asset_id,
                      !!data.incident_type,
                      !!data.description.trim(),
                      !!data.severity,
                      !!data.occurred_at,
                      !requiresImmediateAction ||
                          !!data.immediate_action_taken.trim(),
                  ]
                : mode === 'asset'
                  ? [
                        !!data.asset_id,
                        !!data.incident_type,
                        !!data.description.trim(),
                        !!data.severity,
                        !requiresImmediateAction ||
                            !!data.immediate_action_taken.trim(),
                    ]
                  : [
                        !!data.asset_id,
                        !!data.description.trim(),
                        !!data.occurred_at,
                        !!data.potential_severity,
                    ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data, mode, requiresImmediateAction]);

    const stepValid = (key: string): boolean => {
        switch (key) {
            case 'vehicle_driver':
                return !!data.asset_id && !!data.incident_type;
            case 'scene':
                return (
                    !!data.description.trim() &&
                    !!data.occurred_at &&
                    !!data.severity &&
                    (!requiresImmediateAction ||
                        !!data.immediate_action_taken.trim())
                );
            case 'police_reg':
                return !data.injury_involved || !!data.injury_severity;
            case 'asset_details':
                return !!data.asset_id && !!data.incident_type;
            case 'what':
                return (
                    !!data.description.trim() &&
                    !!data.occurred_at &&
                    !!data.severity &&
                    (!requiresImmediateAction ||
                        !!data.immediate_action_taken.trim())
                );
            case 'nm_vehicle':
                return !!data.asset_id;
            case 'nm_what':
                return !!data.description.trim() && !!data.occurred_at;
            case 'nm_could':
                return !!data.potential_severity;
            default:
                return true;
        }
    };

    const canSubmit =
        mode === 'vehicle'
            ? !!data.asset_id &&
              !!data.incident_type &&
              !!data.description.trim() &&
              !!data.severity &&
              !!data.occurred_at &&
              (!requiresImmediateAction || !!data.immediate_action_taken.trim())
            : mode === 'asset'
              ? !!data.asset_id &&
                !!data.incident_type &&
                !!data.description.trim() &&
                !!data.severity &&
                (!requiresImmediateAction ||
                    !!data.immediate_action_taken.trim())
              : !!data.asset_id &&
                !!data.description.trim() &&
                !!data.occurred_at &&
                !!data.potential_severity;

    const submit = () => {
        form.transform((d) => ({
            ...d,
            asset_id: d.asset_id ? Number(d.asset_id) : null,
            driver_user_id: d.driver_user_id ? Number(d.driver_user_id) : null,
            people_aboard_count: d.people_aboard_count
                ? Number(d.people_aboard_count)
                : null,
            speed_limit: d.speed_limit ? Number(d.speed_limit) : null,
            estimated_speed: d.estimated_speed
                ? Number(d.estimated_speed)
                : null,
            insurance_excess: d.insurance_excess
                ? Number(d.insurance_excess)
                : null,
            replacement_cost: d.replacement_cost
                ? Number(d.replacement_cost)
                : null,
            damage_details: {
                areas: d.damage_details.areas,
                estimated_cost: d.damage_details.estimated_cost
                    ? Number(d.damage_details.estimated_cost)
                    : null,
            },
            third_parties: d.third_party_involved ? d.third_parties : [],
            witnesses: d.witnesses.filter((w) => w.name.trim()),
        }));
        form.post('/fleet-assets/incidents', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (!page.flash?.error) setSubmitted(true);
            },
        });
    };

    const reset = () => {
        form.reset();
        form.clearErrors();
        setStepIndex(0);
        setSubmitted(false);
    };

    const newId = page.flash?.created_fleet_incident_id;
    const newReference = page.flash?.created_fleet_incident_reference;
    const success = submitted ? (
        <WizardSuccessPane
            title="Fleet incident reported"
            blurb={
                <>
                    Recorded and routed to Health &amp; Safety + the Control
                    Room.
                    {newId ? (
                        <>
                            {' '}
                            Reference{' '}
                            <span className="font-semibold">
                                {newReference ??
                                    `FI-${String(newId).padStart(4, '0')}`}
                            </span>
                            .
                        </>
                    ) : null}
                </>
            }
            actions={
                <>
                    {newId && onOpenIncident ? (
                        <Button onClick={() => onOpenIncident(newId)}>
                            Open incident
                        </Button>
                    ) : null}
                    <Button variant="outline" onClick={reset}>
                        Report another
                    </Button>
                    <Button variant="ghost" onClick={onClose}>
                        Done
                    </Button>
                </>
            }
        />
    ) : undefined;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Report ${mode === 'near_miss' ? 'a near miss' : mode === 'asset' ? 'an asset incident' : 'a vehicle incident'}`}
            description="Capture a fleet or asset incident"
            railIcon={MODE_META[mode].icon}
            railTitle={MODE_META[mode].railTitle}
            railSub={MODE_META[mode].railSub}
            steps={steps}
            stepIndex={stepIndex}
            onStepClick={(i) => setStepIndex(i)}
            pct={submitted ? null : pct}
            footerStart={submitted ? undefined : <Ring pct={pct} size={40} />}
            footerEnd={
                submitted ? undefined : (
                    <div className="flex items-center gap-2">
                        {stepIndex > 0 ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setStepIndex((i) => Math.max(0, i - 1))
                                }
                            >
                                Back
                            </Button>
                        ) : null}
                        {stepIndex < lastIndex ? (
                            <Button
                                onClick={() =>
                                    setStepIndex((i) =>
                                        Math.min(lastIndex, i + 1),
                                    )
                                }
                                disabled={!stepValid(stepKey)}
                            >
                                Next
                            </Button>
                        ) : (
                            <Button
                                onClick={submit}
                                disabled={processing || !canSubmit}
                            >
                                Submit incident
                            </Button>
                        )}
                    </div>
                )
            }
            success={success}
        >
            <WizardStepPane>
                {/* ---- Vehicle branch ---- */}
                {stepKey === 'vehicle_driver' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Truck}
                            title="Vehicle & driver"
                            blurb="Which vehicle, and who was driving?"
                        />
                        <Field label="Vehicle" required error={errors.asset_id}>
                            <Input
                                value={assetSearch}
                                onChange={(event) =>
                                    setAssetSearch(event.target.value)
                                }
                                placeholder="Search vehicles..."
                                className="mb-2"
                            />
                            <SelectInput
                                value={data.asset_id}
                                onChange={(v) => setData('asset_id', v)}
                                placeholder="Select vehicle"
                                options={vehicleOptions}
                            />
                        </Field>
                        <Field label="Driver">
                            <Input
                                value={driverSearch}
                                onChange={(event) =>
                                    setDriverSearch(event.target.value)
                                }
                                placeholder="Search drivers..."
                                className="mb-2"
                            />
                            <SelectInput
                                value={data.driver_user_id}
                                onChange={(v) => setData('driver_user_id', v)}
                                placeholder="Select driver"
                                options={userOptions}
                            />
                        </Field>
                        <Field label="Duty status">
                            <Segmented
                                value={data.driver_on_duty ? 'yes' : 'no'}
                                onChange={(v) =>
                                    setData('driver_on_duty', v === 'yes')
                                }
                                options={yesNo(
                                    'On a roster shift',
                                    'Not on duty',
                                )}
                            />
                        </Field>
                        <Field label="Incident type" required>
                            <TilePicker
                                value={data.incident_type}
                                onChange={(v) => setData('incident_type', v)}
                                options={VEHICLE_TYPE_TILES}
                                cols={3}
                            />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'scene' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={MapPin}
                            title="What happened"
                            blurb="Describe the incident and the scene conditions."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Occurred at" required>
                                <Input
                                    type="datetime-local"
                                    value={data.occurred_at}
                                    onChange={(e) =>
                                        setData('occurred_at', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Location">
                                <Input
                                    value={data.location}
                                    onChange={(e) =>
                                        setData('location', e.target.value)
                                    }
                                    placeholder="Street / location"
                                />
                            </Field>
                        </div>
                        <Field label="Description" required>
                            <Textarea
                                rows={4}
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                                placeholder="What happened?"
                            />
                        </Field>
                        <Field label="Severity" required>
                            <TilePicker
                                value={data.severity}
                                onChange={(v) => setData('severity', v)}
                                options={SEVERITY_TILES}
                                cols={2}
                            />
                        </Field>
                        {requiresImmediateAction ? (
                            <>
                                <InfoCard icon={ShieldAlert} tone="warn">
                                    Record what was actually done immediately.
                                    If no control was possible, say that
                                    explicitly.
                                </InfoCard>
                                <Field
                                    label="Immediate action taken"
                                    required
                                    error={errors.immediate_action_taken}
                                >
                                    <Textarea
                                        rows={3}
                                        value={data.immediate_action_taken}
                                        onChange={(e) =>
                                            setData(
                                                'immediate_action_taken',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="What did you do immediately to protect the people involved?"
                                    />
                                </Field>
                            </>
                        ) : null}
                        <SubHead icon={Cloud}>Scene & conditions</SubHead>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Road type">
                                <Segmented
                                    value={data.road_type}
                                    onChange={(v) => setData('road_type', v)}
                                    options={roadTypeOpts}
                                />
                            </Field>
                            <Field label="Weather">
                                <Segmented
                                    value={data.weather}
                                    onChange={(v) => setData('weather', v)}
                                    options={weatherOpts}
                                />
                            </Field>
                            <Field label="Lighting">
                                <Segmented
                                    value={data.lighting}
                                    onChange={(v) => setData('lighting', v)}
                                    options={lightingOpts}
                                />
                            </Field>
                            <Field label="Traffic">
                                <Segmented
                                    value={data.traffic_conditions}
                                    onChange={(v) =>
                                        setData('traffic_conditions', v)
                                    }
                                    options={trafficOpts}
                                />
                            </Field>
                            <Field label="Speed limit (km/h)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.speed_limit}
                                    onChange={(e) =>
                                        setData('speed_limit', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Estimated speed (km/h)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.estimated_speed}
                                    onChange={(e) =>
                                        setData(
                                            'estimated_speed',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Manoeuvre">
                                <Input
                                    value={data.manoeuvre}
                                    onChange={(e) =>
                                        setData('manoeuvre', e.target.value)
                                    }
                                    placeholder="e.g. Turning right"
                                />
                            </Field>
                            <Field label="Road hazard">
                                <Input
                                    value={data.road_hazard}
                                    onChange={(e) =>
                                        setData('road_hazard', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    </div>
                ) : null}

                {stepKey === 'people' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Users}
                            title="People"
                            blurb="Who was aboard, and were others involved?"
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="People aboard (count)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.people_aboard_count}
                                    onChange={(e) =>
                                        setData(
                                            'people_aboard_count',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Whānau / next-of-kin informed?">
                                <Segmented
                                    value={data.whanau_informed ? 'yes' : 'no'}
                                    onChange={(v) =>
                                        setData('whanau_informed', v === 'yes')
                                    }
                                    options={yesNo('Yes', 'No / N/A')}
                                />
                            </Field>
                        </div>
                        <Field label="Third party involved?">
                            <Segmented
                                value={data.third_party_involved ? 'yes' : 'no'}
                                onChange={(v) =>
                                    setData('third_party_involved', v === 'yes')
                                }
                                options={yesNo('Yes — another party', 'No')}
                            />
                        </Field>
                        {data.third_party_involved ? (
                            <div className="flex flex-col gap-3 rounded-xl border border-border p-3">
                                <SubHead icon={Users}>Third party</SubHead>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Name">
                                        <Input
                                            value={
                                                data.third_parties[0]?.name ??
                                                ''
                                            }
                                            onChange={(e) =>
                                                tpField('name', e.target.value)
                                            }
                                        />
                                    </Field>
                                    <Field label="Contact">
                                        <Input
                                            value={
                                                data.third_parties[0]
                                                    ?.contact ?? ''
                                            }
                                            onChange={(e) =>
                                                tpField(
                                                    'contact',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Vehicle rego">
                                        <Input
                                            value={
                                                data.third_parties[0]
                                                    ?.vehicle_rego ?? ''
                                            }
                                            onChange={(e) =>
                                                tpField(
                                                    'vehicle_rego',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Insurer">
                                        <Input
                                            value={
                                                data.third_parties[0]
                                                    ?.insurer ?? ''
                                            }
                                            onChange={(e) =>
                                                tpField(
                                                    'insurer',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Claim ref">
                                        <Input
                                            value={
                                                data.third_parties[0]
                                                    ?.claim_ref ?? ''
                                            }
                                            onChange={(e) =>
                                                tpField(
                                                    'claim_ref',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Liability">
                                        <Input
                                            value={
                                                data.third_parties[0]
                                                    ?.liability ?? ''
                                            }
                                            onChange={(e) =>
                                                tpField(
                                                    'liability',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Third-party at fault"
                                        />
                                    </Field>
                                </div>
                            </div>
                        ) : null}
                        <Field label="Witnesses">
                            <div className="flex flex-col gap-2">
                                {data.witnesses.map((w, i) => (
                                    <div
                                        key={i}
                                        className="grid items-end gap-2 sm:grid-cols-[1fr_1fr_auto]"
                                    >
                                        <Input
                                            value={w.name}
                                            placeholder="Name"
                                            onChange={(e) =>
                                                setData(
                                                    'witnesses',
                                                    data.witnesses.map(
                                                        (x, j) =>
                                                            j === i
                                                                ? {
                                                                      ...x,
                                                                      name: e
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : x,
                                                    ),
                                                )
                                            }
                                        />
                                        <Input
                                            value={w.contact}
                                            placeholder="Contact"
                                            onChange={(e) =>
                                                setData(
                                                    'witnesses',
                                                    data.witnesses.map(
                                                        (x, j) =>
                                                            j === i
                                                                ? {
                                                                      ...x,
                                                                      contact:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : x,
                                                    ),
                                                )
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setData(
                                                    'witnesses',
                                                    data.witnesses.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="self-start"
                                    onClick={() =>
                                        setData('witnesses', [
                                            ...data.witnesses,
                                            { name: '', contact: '' },
                                        ])
                                    }
                                >
                                    <Plus className="mr-1.5 h-4 w-4" /> Add
                                    witness
                                </Button>
                            </div>
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'damage' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={AlertTriangle}
                            title="Damage & drivability"
                            blurb="Damage extent, recovery, and cost."
                        />
                        <InfoCard icon={Camera} tone="info">
                            Photos &amp; documents attach from the incident once
                            it&apos;s created — use the Photos tab in the
                            detail.
                        </InfoCard>
                        <Field label="Damage classification">
                            <TilePicker
                                value={data.damage_classification}
                                onChange={(v) =>
                                    setData('damage_classification', v)
                                }
                                options={DAMAGE_CLASS_TILES}
                                cols={3}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Still drivable?">
                                <Segmented
                                    value={data.is_drivable ? 'yes' : 'no'}
                                    onChange={(v) =>
                                        setData('is_drivable', v === 'yes')
                                    }
                                    options={yesNo('Yes', 'No')}
                                />
                            </Field>
                            <Field label="Tow required?">
                                <Segmented
                                    value={data.tow_required ? 'yes' : 'no'}
                                    onChange={(v) =>
                                        setData('tow_required', v === 'yes')
                                    }
                                    options={yesNo('Yes', 'No')}
                                />
                            </Field>
                        </div>
                        <Field label="Damage areas">
                            <ChipMulti
                                values={data.damage_details.areas}
                                onChange={(v) =>
                                    setData('damage_details', {
                                        ...data.damage_details,
                                        areas: v,
                                    })
                                }
                                options={DAMAGE_AREAS}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Estimated repair cost (NZD)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.damage_details.estimated_cost}
                                    onChange={(e) =>
                                        setData('damage_details', {
                                            ...data.damage_details,
                                            estimated_cost: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Vehicle off-road (VOR)?">
                                <Segmented
                                    value={data.vehicle_off_road ? 'yes' : 'no'}
                                    onChange={(v) =>
                                        setData('vehicle_off_road', v === 'yes')
                                    }
                                    options={yesNo('Yes — off road', 'No')}
                                />
                            </Field>
                        </div>
                    </div>
                ) : null}

                {stepKey === 'police_reg' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={ShieldAlert}
                            title="Police & regulatory"
                            blurb="NZ Land Transport Act s22, ACC, and testing."
                        />
                        <label className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-sm">
                            <input
                                type="checkbox"
                                checked={data.injury_involved}
                                onChange={(e) =>
                                    setData('injury_involved', e.target.checked)
                                }
                                className="h-4 w-4 rounded border-border"
                            />
                            <span className="font-medium">
                                Someone was injured in this incident
                            </span>
                        </label>
                        {data.injury_involved ? (
                            <>
                                <InfoCard icon={ShieldAlert} tone="warn">
                                    <span className="font-semibold">
                                        Land Transport Act 1998 s22:
                                    </span>{' '}
                                    an injury/fatal crash must be reported to NZ
                                    Police within{' '}
                                    <span className="font-semibold">
                                        24 hours
                                    </span>{' '}
                                    (105 / a Traffic Crash Report). The system
                                    tracks this deadline from the time it
                                    occurred.
                                </InfoCard>
                                <Field
                                    label="Injury severity"
                                    required
                                    error={errors.injury_severity}
                                >
                                    <TilePicker
                                        value={data.injury_severity}
                                        onChange={(v) =>
                                            setData('injury_severity', v)
                                        }
                                        options={INJURY_TILES}
                                        cols={2}
                                    />
                                </Field>
                                <Field
                                    label="Traffic Crash Report (TCR) reference"
                                    hint="If already filed"
                                >
                                    <Input
                                        value={
                                            data.traffic_crash_report_reference
                                        }
                                        onChange={(e) =>
                                            setData(
                                                'traffic_crash_report_reference',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Police / TCR reference"
                                    />
                                </Field>
                                <InfoCard icon={Activity} tone="info">
                                    WorkSafe NZ notifiability is flagged
                                    automatically from the injury severity and
                                    incident severity.
                                </InfoCard>
                            </>
                        ) : null}
                        <label className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-sm">
                            <input
                                type="checkbox"
                                checked={data.acc_claim_lodged}
                                onChange={(e) =>
                                    setData(
                                        'acc_claim_lodged',
                                        e.target.checked,
                                    )
                                }
                                className="h-4 w-4 rounded border-border"
                            />
                            <span className="font-medium">
                                ACC claim lodged
                            </span>
                        </label>
                        {data.acc_claim_lodged ? (
                            <Field label="ACC claim reference">
                                <Input
                                    value={data.acc_claim_reference}
                                    onChange={(e) =>
                                        setData(
                                            'acc_claim_reference',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        ) : null}
                        <SubHead icon={Activity}>Testing</SubHead>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.breath_test_administered}
                                    onChange={(e) =>
                                        setData(
                                            'breath_test_administered',
                                            e.target.checked,
                                        )
                                    }
                                    className="h-4 w-4 rounded border-border"
                                />
                                Breath test administered
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={data.drug_test_administered}
                                    onChange={(e) =>
                                        setData(
                                            'drug_test_administered',
                                            e.target.checked,
                                        )
                                    }
                                    className="h-4 w-4 rounded border-border"
                                />
                                Drug test administered
                            </label>
                        </div>
                    </div>
                ) : null}

                {/* ---- Asset branch ---- */}
                {stepKey === 'asset_details' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Box}
                            title="Asset details"
                            blurb="Which piece of equipment, and what happened?"
                        />
                        <Field label="Asset" required error={errors.asset_id}>
                            <Input
                                value={assetSearch}
                                onChange={(event) =>
                                    setAssetSearch(event.target.value)
                                }
                                placeholder="Search assets..."
                                className="mb-2"
                            />
                            <SelectInput
                                value={data.asset_id}
                                onChange={(v) => setData('asset_id', v)}
                                placeholder="Select asset"
                                options={assetOnlyOptions}
                            />
                        </Field>
                        <Field
                            label="Serial number / asset tag"
                            hint="Optional"
                        >
                            <Input
                                value={data.asset_serial_snapshot}
                                onChange={(e) =>
                                    setData(
                                        'asset_serial_snapshot',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field label="Incident type" required>
                            <TilePicker
                                value={data.incident_type}
                                onChange={(v) => setData('incident_type', v)}
                                options={ASSET_TYPE_TILES}
                                cols={3}
                            />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'what' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Search}
                            title="What happened"
                            blurb="Describe the incident."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Occurred at" required>
                                <Input
                                    type="datetime-local"
                                    value={data.occurred_at}
                                    onChange={(e) =>
                                        setData('occurred_at', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Severity" required>
                                <TilePicker
                                    value={data.severity}
                                    onChange={(v) => setData('severity', v)}
                                    options={SEVERITY_TILES}
                                    cols={2}
                                />
                            </Field>
                        </div>
                        <Field label="Description" required>
                            <Textarea
                                rows={4}
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                            />
                        </Field>
                        {requiresImmediateAction ? (
                            <>
                                <InfoCard icon={ShieldAlert} tone="warn">
                                    Record what was actually done immediately.
                                    If no control was possible, say that
                                    explicitly.
                                </InfoCard>
                                <Field
                                    label="Immediate action taken"
                                    required
                                    error={errors.immediate_action_taken}
                                >
                                    <Textarea
                                        rows={3}
                                        value={data.immediate_action_taken}
                                        onChange={(e) =>
                                            setData(
                                                'immediate_action_taken',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="What did you do immediately to protect the people involved?"
                                    />
                                </Field>
                            </>
                        ) : null}
                        <InfoCard icon={Camera} tone="info">
                            Photos attach from the incident once it&apos;s
                            created — use the Photos tab in the detail.
                        </InfoCard>
                    </div>
                ) : null}

                {stepKey === 'condition' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Activity}
                            title="Condition & cost"
                            blurb="Asset state before and after, and cost."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Condition before">
                                <Segmented
                                    value={data.asset_condition_before}
                                    onChange={(v) =>
                                        setData('asset_condition_before', v)
                                    }
                                    options={conditionOpts}
                                />
                            </Field>
                            <Field label="Condition after">
                                <Segmented
                                    value={data.asset_condition_after}
                                    onChange={(v) =>
                                        setData('asset_condition_after', v)
                                    }
                                    options={conditionOpts}
                                />
                            </Field>
                        </div>
                        <Field label="Warranty status">
                            <Segmented
                                value={data.warranty_status}
                                onChange={(v) => setData('warranty_status', v)}
                                options={warrantyOpts}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Estimated repair cost (NZD)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.damage_details.estimated_cost}
                                    onChange={(e) =>
                                        setData('damage_details', {
                                            ...data.damage_details,
                                            estimated_cost: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Replacement cost (NZD)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={data.replacement_cost}
                                    onChange={(e) =>
                                        setData(
                                            'replacement_cost',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    </div>
                ) : null}

                {/* ---- Near-miss branch ---- */}
                {stepKey === 'nm_vehicle' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Eye}
                            title="Vehicle & driver"
                            blurb="Blame-free — thanks for reporting. No harm was done."
                        />
                        <Field
                            label="Vehicle / asset"
                            required
                            error={errors.asset_id}
                        >
                            <Input
                                value={assetSearch}
                                onChange={(event) =>
                                    setAssetSearch(event.target.value)
                                }
                                placeholder="Search vehicles or assets..."
                                className="mb-2"
                            />
                            <SelectInput
                                value={data.asset_id}
                                onChange={(v) => setData('asset_id', v)}
                                placeholder="Select vehicle or asset"
                                options={vehicleOptions}
                            />
                        </Field>
                        <Field label="Driver (optional)">
                            <Input
                                value={driverSearch}
                                onChange={(event) =>
                                    setDriverSearch(event.target.value)
                                }
                                placeholder="Search drivers..."
                                className="mb-2"
                            />
                            <SelectInput
                                value={data.driver_user_id}
                                onChange={(v) => setData('driver_user_id', v)}
                                placeholder="Select driver"
                                options={userOptions}
                            />
                        </Field>
                        <InfoCard icon={Eye} tone="info">
                            Near-miss reporting is blame-free. The type is set
                            to Near miss automatically.
                        </InfoCard>
                    </div>
                ) : null}

                {stepKey === 'nm_what' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Search}
                            title="What happened (or nearly happened)"
                            blurb="Brief, factual description."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Occurred at" required>
                                <Input
                                    type="datetime-local"
                                    value={data.occurred_at}
                                    onChange={(e) =>
                                        setData('occurred_at', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Location">
                                <Input
                                    value={data.location}
                                    onChange={(e) =>
                                        setData('location', e.target.value)
                                    }
                                    placeholder="Where did it nearly happen?"
                                />
                            </Field>
                        </div>
                        <Field label="Description" required>
                            <Textarea
                                rows={4}
                                value={data.description}
                                onChange={(e) =>
                                    setData('description', e.target.value)
                                }
                                placeholder="What nearly happened?"
                            />
                        </Field>
                        <InfoCard icon={Camera} tone="info">
                            Photos attach from the record once it&apos;s
                            created.
                        </InfoCard>
                    </div>
                ) : null}

                {stepKey === 'nm_could' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={AlertTriangle}
                            title="What could have happened"
                            blurb="The potential is what makes near misses valuable."
                        />
                        <Field
                            label="Potential severity"
                            required
                            error={errors.potential_severity}
                        >
                            <TilePicker
                                value={data.potential_severity}
                                onChange={(v) =>
                                    setData('potential_severity', v)
                                }
                                options={SEVERITY_TILES}
                                cols={2}
                            />
                        </Field>
                        <Field
                            label="Contributing factors"
                            hint="Select all that apply"
                        >
                            <ChipMulti
                                values={data.contributing_factors}
                                onChange={(v) =>
                                    setData('contributing_factors', v)
                                }
                                options={FACTORS}
                            />
                        </Field>
                        <Field label="Hazard / contributing detail">
                            <Input
                                value={data.road_hazard}
                                onChange={(e) =>
                                    setData('road_hazard', e.target.value)
                                }
                                placeholder="e.g. Pothole, no warning sign"
                            />
                        </Field>
                    </div>
                ) : null}

                {/* ---- Review (all branches) ---- */}
                {stepKey === 'review' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={CheckCircle2}
                            title="Review & submit"
                            blurb="Check the details, then submit."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard
                                icon={MODE_META[mode].icon}
                                title="Asset"
                                onEdit={() => setStepIndex(0)}
                            >
                                <ReviewRow
                                    label="Asset"
                                    value={
                                        vehicleOptions.find(
                                            (o) => o.value === data.asset_id,
                                        )?.label
                                    }
                                />
                                <ReviewRow
                                    label="Type"
                                    value={titleCase(data.incident_type)}
                                />
                                {mode !== 'near_miss' ? (
                                    <ReviewRow
                                        label="Severity"
                                        value={titleCase(data.severity)}
                                    />
                                ) : (
                                    <ReviewRow
                                        label="Potential"
                                        value={titleCase(
                                            data.potential_severity,
                                        )}
                                    />
                                )}
                            </ReviewCard>
                            <ReviewCard
                                icon={MapPin}
                                title="When & where"
                                onEdit={() => setStepIndex(1)}
                            >
                                <ReviewRow
                                    label="Occurred"
                                    value={data.occurred_at}
                                />
                                <ReviewRow
                                    label="Location"
                                    value={data.location}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Search}
                                title="Description"
                                span
                                onEdit={() => setStepIndex(1)}
                            >
                                <p className="text-sm whitespace-pre-wrap text-foreground">
                                    {data.description || '—'}
                                </p>
                                {requiresImmediateAction ? (
                                    <ReviewRow
                                        label="Immediate action"
                                        value={data.immediate_action_taken}
                                    />
                                ) : null}
                            </ReviewCard>
                            {mode === 'vehicle' ? (
                                <ReviewCard
                                    icon={ShieldAlert}
                                    title="Regulatory"
                                    onEdit={() => setStepIndex(4)}
                                >
                                    <ReviewRow
                                        label="Injury"
                                        value={
                                            data.injury_involved
                                                ? titleCase(
                                                      data.injury_severity ||
                                                          'yes',
                                                  )
                                                : 'No'
                                        }
                                    />
                                    <ReviewRow
                                        label="TCR ref"
                                        value={
                                            data.traffic_crash_report_reference
                                        }
                                    />
                                    <ReviewRow
                                        label="ACC"
                                        value={
                                            data.acc_claim_lodged
                                                ? data.acc_claim_reference ||
                                                  'Lodged'
                                                : 'No'
                                        }
                                    />
                                </ReviewCard>
                            ) : null}
                        </div>
                        {mode === 'vehicle' ? (
                            <div className="rounded-xl border border-border p-3">
                                <SubHead icon={Activity}>
                                    Insurance (optional)
                                </SubHead>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Field label="Insurer">
                                        <Input
                                            value={data.insurer_name}
                                            onChange={(e) =>
                                                setData(
                                                    'insurer_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Claim reference">
                                        <Input
                                            value={data.insurance_reference}
                                            onChange={(e) =>
                                                setData(
                                                    'insurance_reference',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Excess (NZD)">
                                        <Input
                                            type="number"
                                            min="0"
                                            value={data.insurance_excess}
                                            onChange={(e) =>
                                                setData(
                                                    'insurance_excess',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}
