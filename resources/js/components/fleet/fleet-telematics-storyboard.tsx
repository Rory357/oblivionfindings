import { Button } from '@/components/ui/button';
import { Check, CheckCircle2, RadioTower, Truck, X, Zap } from 'lucide-react';
import { useEffect, useState, type ComponentType } from 'react';

type Tone = 'info' | 'warning' | 'critical' | 'primary';

type Step = {
    label: string;
    tone: Tone;
    icon: ComponentType<{ className?: string }>;
    title: string;
    body: string;
};

/**
 * Telematics crash-detection → Control Room confirm → drafts a FleetIncident.
 * PREP-LATER (Gap F2): design-only storyboard. Telemetry today is passive
 * (position/speed/odometer); this is wired when the wider Fleet & Assets
 * telematics build-out lands. Mirrors the client-incident `fall_detected` flow.
 */
const STEPS: Step[] = [
    {
        label: '1 · Sensor',
        tone: 'info',
        icon: Zap,
        title: 'Telematics detects a hard event',
        body: 'The vehicle device reports a sudden deceleration / impact spike (G-force over threshold) on the van at 07:48, near Penrose. Position, speed and odometer are captured automatically.',
    },
    {
        label: '2 · Triage',
        tone: 'warning',
        icon: RadioTower,
        title: 'Control Room is paged to confirm',
        body: 'A provisional alert lands in the Control Room. The operator sees the live location, the last trip, and which residents were aboard from the linked booking — and calls the driver to confirm.',
    },
    {
        label: '3 · Confirm',
        tone: 'critical',
        icon: CheckCircle2,
        title: 'Operator confirms — or dismisses',
        body: 'If it was a false trigger (pothole, kerb), the operator dismisses it and nothing is created. If it was real, one click drafts a FleetIncident — pre-filled with the vehicle, driver, location, time and residents aboard.',
    },
    {
        label: '4 · Draft',
        tone: 'primary',
        icon: Truck,
        title: 'A pre-filled incident draft opens',
        body: 'The report wizard opens at the People / Damage steps with the sensor data already in place — the responder only adds what the sensors cannot see (injuries, third party, damage, photos) and submits.',
    },
];

const TONE: Record<
    Tone,
    { text: string; bg: string; ring: string; dot: string }
> = {
    info: {
        text: 'text-status-info',
        bg: 'bg-status-info-bg',
        ring: 'border-status-info/40',
        dot: 'bg-status-info',
    },
    warning: {
        text: 'text-status-warning',
        bg: 'bg-status-warning-bg',
        ring: 'border-status-warning/40',
        dot: 'bg-status-warning',
    },
    critical: {
        text: 'text-status-critical',
        bg: 'bg-status-critical-bg',
        ring: 'border-status-critical/40',
        dot: 'bg-status-critical',
    },
    primary: {
        text: 'text-primary',
        bg: 'bg-primary/10',
        ring: 'border-primary/40',
        dot: 'bg-primary',
    },
};

export function FleetTelematicsStoryboard({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);

    useEffect(() => {
        if (open) setStep(0);
    }, [open]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    if (!open) return null;

    const last = STEPS.length - 1;
    const cur = STEPS[step];
    const tone = TONE[cur.tone];
    const Icon = cur.icon;

    return (
        <div
            className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:p-8"
            role="dialog"
            aria-modal="true"
            aria-label="Telematics crash-detection storyboard"
            onClick={onClose}
        >
            <div
                className="my-4 w-full max-w-2xl rounded-2xl border border-border bg-card shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                {/* Header */}
                <div className="flex items-start justify-between gap-4 border-b border-border p-5">
                    <div>
                        <div className="flex items-center gap-2">
                            <h2 className="text-lg font-bold text-foreground">
                                Telematics crash-detection
                            </h2>
                            <span className="inline-flex items-center rounded-full border border-dashed border-border px-2 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                Prep-later
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            Sensor → operator confirm → drafts a FleetIncident ·
                            wired when the telematics build-out lands. Mirrors
                            the client-incident fall-detected flow.
                        </p>
                    </div>
                    <Button
                        unstyled
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <X className="h-5 w-5" />
                    </Button>
                </div>

                {/* Step dots */}
                <div className="flex items-center gap-2 px-5 pt-4">
                    {STEPS.map((s, i) => {
                        const done = i < step;
                        const active = i === step;
                        const t = TONE[s.tone];
                        return (
                            <div
                                key={s.label}
                                className="flex flex-1 items-center gap-2"
                            >
                                <span
                                    className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold ${done ? 'bg-status-success text-white' : active ? `${t.dot} text-white` : 'bg-muted text-muted-foreground'}`}
                                >
                                    {done ? (
                                        <Check className="h-3.5 w-3.5" />
                                    ) : (
                                        i + 1
                                    )}
                                </span>
                                {i < last ? (
                                    <span
                                        className={`h-0.5 flex-1 rounded-full ${i < step ? 'bg-status-success' : 'bg-muted'}`}
                                    />
                                ) : null}
                            </div>
                        );
                    })}
                </div>

                {/* Active step */}
                <div className="p-5">
                    <div
                        className={`rounded-xl border ${tone.ring} ${tone.bg} p-5`}
                    >
                        <div className="flex items-start gap-3">
                            <span
                                className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-card ${tone.text}`}
                            >
                                <Icon className="h-5 w-5" />
                            </span>
                            <div>
                                <p
                                    className={`text-[11px] font-semibold tracking-wide uppercase ${tone.text}`}
                                >
                                    {cur.label}
                                </p>
                                <h3 className="mt-0.5 text-base font-bold text-foreground">
                                    {cur.title}
                                </h3>
                                <p className="mt-1 text-sm text-foreground/80">
                                    {cur.body}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Footer nav */}
                <div className="flex items-center justify-between gap-2 border-t border-border p-4">
                    <span className="text-xs text-muted-foreground">
                        Step {step + 1} of {STEPS.length}
                    </span>
                    <div className="flex items-center gap-2">
                        {step > 0 ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setStep((s) => Math.max(0, s - 1))
                                }
                            >
                                Back
                            </Button>
                        ) : null}
                        {step < last ? (
                            <Button
                                size="sm"
                                onClick={() =>
                                    setStep((s) => Math.min(last, s + 1))
                                }
                            >
                                Next step
                            </Button>
                        ) : (
                            <Button size="sm" onClick={onClose}>
                                Got it
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
