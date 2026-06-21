/**
 * Client-side NEWS2 scorer for the live observation-wizard display. A faithful
 * port of app/Domain/Clinical/Services/News2Scorer.php — the server remains the
 * authoritative scorer on write; this only powers the live "as you type" badge.
 */

export type News2Band = 'low' | 'low_medium' | 'medium' | 'high';

export type Acvpu = 'A' | 'C' | 'V' | 'P' | 'U';

export type News2VitalsInput = {
    systolic?: number | string | null;
    diastolic?: number | string | null;
    pulse?: number | string | null;
    respiration_rate?: number | string | null;
    o2_saturation?: number | string | null;
    temperature?: number | string | null;
    consciousness?: string | null;
    on_oxygen?: boolean | null;
    spo2_scale?: number | string | null;
};

export type News2Result = {
    score: number;
    band: News2Band;
    bandLabel: string;
    redFlag: boolean;
    advice: string;
    breakdown: Record<string, number>;
};

export const NEWS2_BAND_LABEL: Record<News2Band, string> = {
    low: 'Low',
    low_medium: 'Low-medium',
    medium: 'Medium',
    high: 'High',
};

const BAND_ADVICE: Record<News2Band, string> = {
    low: 'Routine monitoring.',
    low_medium: 'A single parameter scored 3 — urgent review by a registered nurse.',
    medium: 'Urgent review by a clinician — consider escalation.',
    high: 'Emergency response — escalate to senior clinical decision-maker now.',
};

function num(v: unknown): number | null {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
}

function scoreRespiratoryRate(rr: number): number {
    if (rr <= 8) return 3;
    if (rr <= 11) return 1;
    if (rr <= 20) return 0;
    if (rr <= 24) return 2;
    return 3;
}

function scoreSpo2(spo2: number, scale: number, onOxygen: boolean): number {
    if (scale === 2) {
        if (onOxygen) {
            if (spo2 <= 83) return 3;
            if (spo2 <= 85) return 2;
            if (spo2 <= 87) return 1;
            if (spo2 <= 92) return 0;
            if (spo2 <= 94) return 1;
            if (spo2 <= 96) return 2;
            return 3;
        }
        if (spo2 <= 83) return 3;
        if (spo2 <= 85) return 2;
        if (spo2 <= 87) return 1;
        return 0;
    }
    if (spo2 <= 91) return 3;
    if (spo2 <= 93) return 2;
    if (spo2 <= 95) return 1;
    return 0;
}

function scoreSystolic(sys: number): number {
    if (sys <= 90) return 3;
    if (sys <= 100) return 2;
    if (sys <= 110) return 1;
    if (sys <= 219) return 0;
    return 3;
}

function scorePulse(pulse: number): number {
    if (pulse <= 40) return 3;
    if (pulse <= 50) return 1;
    if (pulse <= 90) return 0;
    if (pulse <= 110) return 1;
    if (pulse <= 130) return 2;
    return 3;
}

function scoreTemperature(temp: number): number {
    if (temp <= 35.0) return 3;
    if (temp <= 36.0) return 1;
    if (temp <= 38.0) return 0;
    if (temp <= 39.0) return 1;
    return 2;
}

/** Returns the NEWS2 result, or null when the measured vitals are insufficient. */
export function scoreNews2(data: News2VitalsInput): News2Result | null {
    const rr = num(data.respiration_rate);
    const spo2 = num(data.o2_saturation);
    const sys = num(data.systolic);
    const pulse = num(data.pulse);
    const temp = num(data.temperature);

    if (rr === null || spo2 === null || sys === null || pulse === null || temp === null) {
        return null;
    }

    const onOxygen = !!data.on_oxygen;
    const scale = Number(data.spo2_scale) === 2 ? 2 : 1;
    const consciousness = (data.consciousness ?? 'A') as Acvpu;

    const breakdown: Record<string, number> = {
        respiratory_rate: scoreRespiratoryRate(rr),
        spo2: scoreSpo2(spo2, scale, onOxygen),
        air_or_oxygen: onOxygen ? 2 : 0,
        systolic: scoreSystolic(sys),
        pulse: scorePulse(pulse),
        consciousness: consciousness === 'A' ? 0 : 3,
        temperature: scoreTemperature(temp),
    };

    const score = Object.values(breakdown).reduce((a, b) => a + b, 0);
    const redFlag = Object.values(breakdown).some((v) => v === 3);
    const band: News2Band = score >= 7 ? 'high' : score >= 5 ? 'medium' : redFlag ? 'low_medium' : 'low';

    return { score, band, bandLabel: NEWS2_BAND_LABEL[band], redFlag, advice: BAND_ADVICE[band], breakdown };
}

export const ACVPU_OPTIONS: { value: Acvpu; label: string }[] = [
    { value: 'A', label: 'Alert' },
    { value: 'C', label: 'New confusion' },
    { value: 'V', label: 'Responds to voice' },
    { value: 'P', label: 'Responds to pain' },
    { value: 'U', label: 'Unresponsive' },
];
