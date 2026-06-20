/**
 * Browser port of the clinical assessment scorers (App\Domain\Clinical\Services\
 * Assessments\*). Used ONLY for the live score preview in the record wizard — the
 * server recomputes authoritatively on store, so this mirrors the PHP cut-points
 * exactly. Keep the two in lockstep when either changes.
 */
export type AssessmentTypeValue = 'falls_frat' | 'pressure_braden' | 'malnutrition_must' | 'dysphagia_iddsi';
export type RiskBandValue = 'minimal' | 'low' | 'medium' | 'high' | 'very_high';

export type BreakdownRow = { key: string; label: string; detail: string; points: number | null };
export type ScoreResult = {
    score: number | null;
    band: RiskBandValue | null;
    bandLabel: string | null;
    bandTone: string | null;
    summary: string;
    advice: string | null;
    breakdown: BreakdownRow[];
};

export type AssessmentInputs = Record<string, string | number | boolean | null | undefined>;

const BAND_META: Record<RiskBandValue, { label: string; tone: string }> = {
    minimal: { label: 'Minimal risk', tone: 'success' },
    low: { label: 'Low risk', tone: 'success' },
    medium: { label: 'Medium risk', tone: 'warning' },
    high: { label: 'High risk', tone: 'critical' },
    very_high: { label: 'Very high risk', tone: 'critical' },
};

export function bandLabel(band: RiskBandValue): string {
    return BAND_META[band].label;
}
export function bandTone(band: RiskBandValue | null): string {
    return band ? BAND_META[band].tone : 'neutral';
}

function num(value: unknown): number | null {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

// ── MUST (BAPEN) ──────────────────────────────────────────────────────────
function must(inputs: AssessmentInputs): ScoreResult {
    let bmi = num(inputs.bmi);
    if (bmi === null) {
        const h = num(inputs.height_cm);
        const w = num(inputs.weight_kg);
        if (h && w && h > 0) bmi = Math.round((w / Math.pow(h / 100, 2)) * 10) / 10;
    }
    const wl = num(inputs.weight_loss_percent) ?? 0;
    const acute = Boolean(inputs.acute_disease_effect);

    const bmiPts = bmi === null ? 0 : bmi > 20 ? 0 : bmi >= 18.5 ? 1 : 2;
    const wlPts = wl < 5 ? 0 : wl <= 10 ? 1 : 2;
    const acutePts = acute ? 2 : 0;
    const total = bmiPts + wlPts + acutePts;
    const band: RiskBandValue = total >= 2 ? 'high' : total === 1 ? 'medium' : 'low';

    return {
        score: total,
        band,
        bandLabel: BAND_META[band].label,
        bandTone: BAND_META[band].tone,
        summary: `MUST ${total} — ${BAND_META[band].label}`,
        advice:
            band === 'high'
                ? 'Treat — refer to dietitian, set goals and monitor.'
                : band === 'medium'
                  ? 'Observe — document dietary intake for 3 days, then re-screen.'
                  : 'Routine clinical care — re-screen per setting.',
        breakdown: [
            { key: 'bmi', label: 'BMI', detail: bmi === null ? 'Not provided' : `${bmi.toFixed(1)} kg/m²`, points: bmiPts },
            { key: 'weight_loss', label: 'Unplanned weight loss (3–6 months)', detail: `${wl}%`, points: wlPts },
            { key: 'acute_disease', label: 'Acute disease effect (no intake >5 days)', detail: acute ? 'Yes' : 'No', points: acutePts },
        ],
    };
}

// ── FRAT (Peninsula Health) ────────────────────────────────────────────────
const FRAT_MAPS: Record<string, Record<string, { points: number; label: string }>> = {
    recent_falls: {
        none_12mo: { points: 2, label: 'No falls in the last 12 months' },
        one_plus_3_12mo: { points: 4, label: 'One or more between 3 and 12 months ago' },
        one_plus_3mo: { points: 6, label: 'One or more in the last 3 months' },
        one_plus_3mo_resident: { points: 8, label: 'One or more in the last 3 months whilst a resident' },
    },
    medications: {
        none: { points: 1, label: 'Not taking any listed medications' },
        one: { points: 2, label: 'Taking one' },
        two: { points: 3, label: 'Taking two' },
        more_than_two: { points: 4, label: 'Taking more than two' },
    },
    psychological: {
        none: { points: 1, label: 'No apparent anxiety, depression or agitation' },
        mild: { points: 2, label: 'Mild' },
        moderate: { points: 3, label: 'Moderate' },
        severe: { points: 4, label: 'Severe' },
    },
    cognitive: {
        intact: { points: 1, label: 'Intact (AMTS 9–10)' },
        mild: { points: 2, label: 'Mildly impaired (AMTS 7–8)' },
        moderate: { points: 3, label: 'Moderately impaired (AMTS 5–6)' },
        severe: { points: 4, label: 'Severely impaired (AMTS ≤4)' },
    },
};
const FRAT_FACTORS = [
    { key: 'recent_falls', label: 'Recent falls' },
    { key: 'medications', label: 'Medications' },
    { key: 'psychological', label: 'Psychological' },
    { key: 'cognitive', label: 'Cognitive status' },
];

function frat(inputs: AssessmentInputs): ScoreResult {
    let total = 0;
    const breakdown: BreakdownRow[] = FRAT_FACTORS.map((f) => {
        const map = FRAT_MAPS[f.key];
        const chosen = typeof inputs[f.key] === 'string' ? map[inputs[f.key] as string] : undefined;
        const opt = chosen ?? map[Object.keys(map)[0]];
        total += opt.points;
        return { key: f.key, label: f.label, detail: chosen ? opt.label : 'Not specified', points: opt.points };
    });
    const band: RiskBandValue = total >= 16 ? 'high' : total >= 12 ? 'medium' : 'low';

    return {
        score: total,
        band,
        bandLabel: BAND_META[band].label,
        bandTone: BAND_META[band].tone,
        summary: `FRAT ${total}/20 — ${BAND_META[band].label}`,
        advice:
            band === 'high'
                ? 'High falls risk — implement a tailored falls-prevention plan.'
                : band === 'medium'
                  ? 'Medium falls risk — apply standard falls precautions.'
                  : 'Low falls risk — maintain universal falls precautions.',
        breakdown,
    };
}

// ── Braden ──────────────────────────────────────────────────────────────────
const BRADEN_SUBSCALES: { key: string; label: string; max: number; options: Record<number, string> }[] = [
    { key: 'sensory_perception', label: 'Sensory perception', max: 4, options: { 1: 'Completely limited', 2: 'Very limited', 3: 'Slightly limited', 4: 'No impairment' } },
    { key: 'moisture', label: 'Moisture', max: 4, options: { 1: 'Constantly moist', 2: 'Very moist', 3: 'Occasionally moist', 4: 'Rarely moist' } },
    { key: 'activity', label: 'Activity', max: 4, options: { 1: 'Bedfast', 2: 'Chairfast', 3: 'Walks occasionally', 4: 'Walks frequently' } },
    { key: 'mobility', label: 'Mobility', max: 4, options: { 1: 'Completely immobile', 2: 'Very limited', 3: 'Slightly limited', 4: 'No limitations' } },
    { key: 'nutrition', label: 'Nutrition', max: 4, options: { 1: 'Very poor', 2: 'Probably inadequate', 3: 'Adequate', 4: 'Excellent' } },
    { key: 'friction_shear', label: 'Friction & shear', max: 3, options: { 1: 'Problem', 2: 'Potential problem', 3: 'No apparent problem' } },
];

function braden(inputs: AssessmentInputs): ScoreResult {
    let total = 0;
    const breakdown: BreakdownRow[] = BRADEN_SUBSCALES.map((s) => {
        const raw = num(inputs[s.key]);
        const value = raw === null ? s.max : Math.max(1, Math.min(s.max, Math.trunc(raw)));
        total += value;
        return { key: s.key, label: s.label, detail: s.options[value] ?? String(value), points: value };
    });
    const band: RiskBandValue = total <= 9 ? 'very_high' : total <= 12 ? 'high' : total <= 14 ? 'medium' : total <= 18 ? 'low' : 'minimal';

    return {
        score: total,
        band,
        bandLabel: BAND_META[band].label,
        bandTone: BAND_META[band].tone,
        summary: `Braden ${total}/23 — ${BAND_META[band].label}`,
        advice:
            band === 'very_high' || band === 'high'
                ? 'Initiate a pressure-injury prevention plan.'
                : band === 'medium'
                  ? 'Apply preventive measures and reassess frequently.'
                  : 'Maintain routine skin care and reassess on change.',
        breakdown,
    };
}

// ── IDDSI (classification) ───────────────────────────────────────────────────
const IDDSI_DRINKS: Record<number, string> = { 0: 'Thin', 1: 'Slightly Thick', 2: 'Mildly Thick', 3: 'Moderately Thick', 4: 'Extremely Thick' };
const IDDSI_FOODS: Record<number, string> = { 3: 'Liquidised', 4: 'Pureed', 5: 'Minced & Moist', 6: 'Soft & Bite-Sized', 7: 'Regular' };

function iddsi(inputs: AssessmentInputs): ScoreResult {
    const d = num(inputs.drink_level);
    const f = num(inputs.food_level);
    const drink = d !== null && d in IDDSI_DRINKS ? d : null;
    const food = f !== null && f in IDDSI_FOODS ? f : null;
    const parts: string[] = [];
    if (drink !== null) parts.push(`Drinks L${drink} (${IDDSI_DRINKS[drink]})`);
    if (food !== null) parts.push(`Food L${food} (${IDDSI_FOODS[food]})`);

    return {
        score: null,
        band: null,
        bandLabel: null,
        bandTone: null,
        summary: parts.length ? `IDDSI · ${parts.join(' · ')}` : 'IDDSI — no levels specified',
        advice: 'Follow the SLT-recommended IDDSI textures; review on any change in swallowing.',
        breakdown: [
            { key: 'drink_level', label: 'Drinks', detail: drink === null ? 'Not specified' : `Level ${drink} · ${IDDSI_DRINKS[drink]}`, points: null },
            { key: 'food_level', label: 'Food', detail: food === null ? 'Not specified' : `Level ${food} · ${IDDSI_FOODS[food]}`, points: null },
        ],
    };
}

export function computeAssessment(type: AssessmentTypeValue, inputs: AssessmentInputs): ScoreResult {
    switch (type) {
        case 'malnutrition_must':
            return must(inputs);
        case 'falls_frat':
            return frat(inputs);
        case 'pressure_braden':
            return braden(inputs);
        case 'dysphagia_iddsi':
            return iddsi(inputs);
    }
}
