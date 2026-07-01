import { router } from '@inertiajs/react';
import { AlertTriangle, Check, Info, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

export type WizardType =
    | 'createCourse'
    | 'editCourse'
    | 'session'
    | 'assign'
    | 'record'
    | 'claim';

export interface WizardLookups {
    staff: { id: number; name: string }[];
    sites: { value: string; label: string }[];
    roles: { value: string; label: string }[];
    requirements: { value: string; label: string }[];
    categories: string[];
}

export interface WizardCourse {
    id: number;
    title: string;
    code?: string | null;
    category?: string | null;
    delivery_method?: string;
    duration_hours?: number;
    provider?: string | null;
    cost?: number | null;
    is_mandatory?: boolean;
    is_active?: boolean;
    requires_renewal?: boolean;
    validity_period_months?: number | null;
    cpd_points?: number | null;
    pass_mark_percentage?: number | null;
}

interface FieldCfg {
    key: string;
    label?: string;
    type:
        | 'text'
        | 'number'
        | 'date'
        | 'time'
        | 'textarea'
        | 'select'
        | 'segmented'
        | 'chips'
        | 'people'
        | 'courses'
        | 'toggle'
        | 'file'
        | 'info';
    required?: boolean;
    half?: boolean;
    placeholder?: string;
    options?: { value: string; label: string }[];
    hint?: string;
    showWhen?: (form: Record<string, any>) => boolean;
}
interface StepCfg {
    key: string;
    label: string;
    blurb: string;
    review?: boolean;
    items?: boolean;
    fields: FieldCfg[];
}

const DELIVERY_OPTS = [
    { value: 'online', label: 'Online' },
    { value: 'in_person', label: 'In person' },
    { value: 'blended', label: 'Blended' },
    { value: 'self_paced', label: 'Self-paced' },
];

const NZD = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
    maximumFractionDigits: 0,
});
const fmtNzd = (n: number) => (n > 0 ? NZD.format(n) : 'Free');

const ENDPOINTS: Record<WizardType, string> = {
    createCourse: '/hr/training/courses',
    editCourse: '/hr/training/courses', // + /{id}
    session: '/hr/training/courses', // + /{id}/sessions
    assign: '/hr/training/assignments',
    record: '/hr/training/record',
    claim: '/hr/training/claims',
};

const TITLES: Record<WizardType, string> = {
    createCourse: 'New course',
    editCourse: 'Edit course',
    session: 'Add session',
    assign: 'Assign training',
    record: 'Record completion',
    claim: 'Claim course fee',
};
const SUCCESS_MSG: Record<WizardType, string> = {
    createCourse: 'Course created',
    editCourse: 'Changes saved',
    session: 'Session scheduled',
    assign: 'Training assigned',
    record: 'Completion recorded',
    claim: 'Claim submitted',
};
const FINAL_LABEL: Record<WizardType, string> = {
    createCourse: 'Create course',
    editCourse: 'Save changes',
    session: 'Schedule session',
    assign: 'Assign training',
    record: 'Record completion',
    claim: 'Submit claim',
};

function buildSteps(type: WizardType, lookups: WizardLookups, courses: WizardCourse[]): StepCfg[] {
    const cats = [
        { value: '', label: 'Select…' },
        ...lookups.categories.map((c) => ({ value: c, label: c })),
    ];
    if (type === 'createCourse' || type === 'editCourse') {
        return [
            {
                key: 'basics',
                label: 'Basics',
                blurb: 'Name, code & delivery',
                fields: [
                    { key: 'title', label: 'Course title', type: 'text', required: true, placeholder: 'e.g. First Aid (Level 2)' },
                    { key: 'code', label: 'Course code', type: 'text', required: true, half: true, placeholder: 'FA-201' },
                    { key: 'category', label: 'Category', type: 'select', half: true, options: cats },
                    { key: 'delivery_method', label: 'Delivery method', type: 'segmented', required: true, options: DELIVERY_OPTS },
                    { key: 'provider', label: 'Provider', type: 'text', half: true, placeholder: 'St John NZ / In-house' },
                    { key: 'is_active', label: 'Active in catalog', type: 'toggle' },
                ],
            },
            {
                key: 'content',
                label: 'Content',
                blurb: 'Outcomes & prerequisites',
                fields: [
                    { key: 'description', label: 'Description', type: 'textarea', placeholder: 'What this course covers…' },
                    { key: 'learning_outcomes', label: 'Learning outcomes', type: 'textarea', placeholder: 'One per line' },
                    { key: 'duration_hours', label: 'Duration (hours)', type: 'number', required: true, half: true, placeholder: '4' },
                    { key: 'max_participants', label: 'Max participants', type: 'number', half: true, placeholder: '20' },
                ],
            },
            {
                key: 'compliance',
                label: 'Compliance',
                blurb: 'Renewal, CPD & assessment',
                fields: [
                    { key: 'is_mandatory', label: 'Mandatory training', type: 'toggle' },
                    { key: 'requires_renewal', label: 'Requires renewal', type: 'toggle' },
                    { key: 'validity_period_months', label: 'Validity (months)', type: 'number', half: true, placeholder: '12', showWhen: (f) => !!f.requires_renewal },
                    { key: 'renewal_reminder_months', label: 'Reminder lead (months)', type: 'number', half: true, placeholder: '2', showWhen: (f) => !!f.requires_renewal },
                    { key: 'requires_assessment', label: 'Requires assessment', type: 'toggle' },
                    { key: 'pass_mark_percentage', label: 'Pass mark (%)', type: 'number', half: true, placeholder: '80', showWhen: (f) => !!f.requires_assessment },
                    { key: 'cpd_points', label: 'CPD points', type: 'number', half: true, placeholder: '4' },
                    { key: 'compliance_requirement_id', label: 'Linked H&S requirement', type: 'select', options: [{ value: '', label: 'None' }, ...lookups.requirements] },
                ],
            },
            {
                key: 'fee',
                label: 'Fee & finance',
                blurb: 'Cost & GL routing',
                fields: [
                    { key: 'cost', label: 'Course fee (NZD)', type: 'number', half: true, placeholder: '0' },
                    { key: 'org_pays_provider', label: 'Org pays provider (AP)', type: 'toggle' },
                    { key: 'staff_can_claim', label: 'Staff can claim back', type: 'toggle' },
                    { key: 'glinfo', type: 'info', hint: 'On completion the cost posts DR 6510 Training · CR 2000 Accounts Payable. If staff claim reimbursement, the provider posting is suppressed to avoid double counting.' },
                ],
            },
            { key: 'review', label: 'Review', blurb: 'Confirm & save', review: true, fields: [] },
        ];
    }
    if (type === 'session') {
        return [
            {
                key: 'details',
                label: 'Details',
                blurb: 'When & where',
                fields: [
                    { key: 'session_date', label: 'Date', type: 'date', required: true, half: true },
                    { key: 'start_time', label: 'Start time', type: 'time', half: true },
                    { key: 'end_time', label: 'End time', type: 'time', half: true },
                    { key: 'location', label: 'Location', type: 'text', placeholder: 'Training room A / Online' },
                    { key: 'online_link', label: 'Online link', type: 'text', placeholder: 'https://…' },
                    { key: 'trainer_id', label: 'Trainer', type: 'select', options: [{ value: '', label: 'Unassigned' }, ...lookups.staff.map((s) => ({ value: String(s.id), label: s.name }))] },
                ],
            },
            {
                key: 'capacity',
                label: 'Capacity',
                blurb: 'Seats & notes',
                fields: [
                    { key: 'max_participants', label: 'Capacity', type: 'number', half: true, placeholder: '20' },
                    { key: 'waitlist_enabled', label: 'Enable waitlist', type: 'toggle' },
                    { key: 'notes', label: 'Notes', type: 'textarea' },
                ],
            },
            { key: 'review', label: 'Review', blurb: 'Confirm & schedule', review: true, fields: [] },
        ];
    }
    if (type === 'assign') {
        return [
            {
                key: 'course',
                label: 'Course',
                blurb: 'What to assign',
                fields: [
                    { key: 'course_ids', label: 'Courses', type: 'courses', required: true },
                ],
            },
            {
                key: 'audience',
                label: 'Audience',
                blurb: 'Who must complete it',
                fields: [
                    {
                        key: 'audience_type', label: 'Assign by', type: 'segmented', required: true, options: [
                            { value: 'individuals', label: 'Individuals' },
                            { value: 'role', label: 'By role' },
                            { value: 'site', label: 'By site' },
                            { value: 'cohort', label: 'Cohort' },
                        ],
                    },
                    { key: 'user_ids', label: 'People', type: 'people', showWhen: (f) => (f.audience_type ?? 'individuals') === 'individuals' },
                    { key: 'role', label: 'Role', type: 'select', options: [{ value: '', label: 'Any role' }, ...lookups.roles], showWhen: (f) => f.audience_type === 'role' },
                    { key: 'site_id', label: 'Site', type: 'select', options: [{ value: '', label: 'All sites' }, ...lookups.sites], showWhen: (f) => f.audience_type === 'site' },
                ],
            },
            {
                key: 'schedule',
                label: 'Schedule',
                blurb: 'Due date & source',
                fields: [
                    { key: 'due_at', label: 'Due date', type: 'date', half: true },
                    {
                        key: 'source', label: 'Source', type: 'select', half: true, options: [
                            { value: 'manual', label: 'Manual' },
                            { value: 'role_rule', label: 'Role rule' },
                            { value: 'hs_requirement', label: 'H&S requirement' },
                        ],
                    },
                ],
            },
            { key: 'review', label: 'Review', blurb: 'Confirm & assign', review: true, fields: [] },
        ];
    }
    if (type === 'record') {
        const courseOpts = [{ value: '', label: 'Select…' }, ...courses.map((c) => ({ value: String(c.id), label: c.title }))];
        return [
            {
                key: 'people',
                label: 'People',
                blurb: 'Who completed it',
                fields: [
                    { key: 'course_id', label: 'Course', type: 'select', required: true, options: courseOpts },
                    { key: 'user_ids', label: 'People', type: 'people', required: true },
                ],
            },
            {
                key: 'result',
                label: 'Result',
                blurb: 'Score & date',
                fields: [
                    { key: 'completed_at', label: 'Completion date', type: 'date', required: true, half: true },
                    { key: 'score', label: 'Assessment score (%)', type: 'number', half: true, placeholder: '94' },
                    { key: 'notes', label: 'Notes', type: 'textarea' },
                ],
            },
            {
                key: 'cert',
                label: 'Certificate',
                blurb: 'Upload evidence',
                fields: [
                    { key: 'certificate', label: 'Certificate file', type: 'file' },
                    { key: 'certificate_number', label: 'Certificate number', type: 'text', half: true, placeholder: 'Optional' },
                    { key: 'cpdinfo', type: 'info', hint: 'Completing a course awards its CPD points and sets the renewal expiry, feeding the renewal queue.' },
                ],
            },
            { key: 'review', label: 'Review', blurb: 'Confirm & record', review: true, fields: [] },
        ];
    }
    if (type === 'claim') {
        const courseOpts = [{ value: '', label: 'Select…' }, ...courses.map((c) => ({ value: String(c.id), label: c.title }))];
        return [
            {
                key: 'details',
                label: 'Details',
                blurb: 'Claim header',
                fields: [
                    { key: 'title', label: 'Claim title', type: 'text', required: true, placeholder: 'First Aid (Level 2) course fee' },
                    { key: 'course_id', label: 'Course', type: 'select', options: courseOpts },
                    { key: 'notes', label: 'Notes', type: 'textarea' },
                ],
            },
            { key: 'items', label: 'Items', blurb: 'Receipts & amounts', items: true, fields: [] },
            { key: 'review', label: 'Review', blurb: 'Confirm & submit', review: true, fields: [] },
        ];
    }
    return [];
}

interface ClaimItem {
    description: string;
    category: string;
    amount: string;
    expense_date: string;
}

const inputCls =
    'h-[38px] w-full rounded-[9px] border border-input bg-background px-[11px] text-[13.5px] text-foreground outline-none focus:outline-2 focus:outline-ring focus:-outline-offset-1';
const textareaCls =
    'min-h-[78px] w-full resize-y rounded-[9px] border border-input bg-background px-[11px] py-[9px] text-[13.5px] text-foreground outline-none focus:outline-2 focus:outline-ring focus:-outline-offset-1';
const labelCls = 'mb-[5px] block text-[11.5px] font-semibold text-muted-foreground';

export function TrainingWizardDialog({
    type,
    course,
    lookups,
    courses,
    onClose,
    onSaved,
}: {
    type: WizardType | null;
    course: WizardCourse | null;
    lookups: WizardLookups;
    courses: WizardCourse[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState(0);
    const [form, setForm] = useState<Record<string, any>>({});
    const [items, setItems] = useState<ClaimItem[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [success, setSuccess] = useState(false);
    const [preview, setPreview] = useState<{ count: number; conflicts: number } | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const steps = useMemo(() => (type ? buildSteps(type, lookups, courses) : []), [type, lookups, courses]);
    const todayStr = new Date().toISOString().slice(0, 10);

    // Per-type initial state. Used both on open and by "Save & add another" so
    // every wizard restarts with the correct defaults (not just course forms).
    const initForType = (t: WizardType, c: WizardCourse | null) => {
        setStep(0);
        setErrors({});
        setSuccess(false);
        setPreview(null);
        setItems([]);
        if (t === 'editCourse' && c) {
            setForm({
                title: c.title ?? '',
                code: c.code ?? '',
                category: c.category ?? '',
                delivery_method: c.delivery_method ?? 'online',
                provider: c.provider ?? '',
                is_active: c.is_active ?? true,
                duration_hours: c.duration_hours != null ? String(c.duration_hours) : '',
                is_mandatory: !!c.is_mandatory,
                requires_renewal: !!c.requires_renewal,
                validity_period_months: c.validity_period_months != null ? String(c.validity_period_months) : '',
                cpd_points: c.cpd_points != null ? String(c.cpd_points) : '',
                cost: c.cost != null ? String(c.cost) : '',
            });
        } else if (t === 'createCourse') {
            setForm({ is_active: true, delivery_method: 'online' });
        } else if (t === 'assign') {
            setForm({ audience_type: 'individuals', course_ids: c ? [c.id] : [], user_ids: [], source: 'manual' });
        } else if (t === 'record') {
            setForm({ course_id: c ? String(c.id) : '', user_ids: [], completed_at: '' });
        } else if (t === 'claim') {
            setForm({ title: c ? `${c.title} course fee` : '', course_id: c ? String(c.id) : '' });
            setItems([{ description: c ? `${c.title} — enrolment` : '', category: 'training', amount: c?.cost != null ? String(c.cost) : '', expense_date: todayStr }]);
        } else if (t === 'session') {
            setForm({ max_participants: '20', waitlist_enabled: false });
        } else {
            setForm({});
        }
    };

    // (Re)initialise when the wizard opens / type changes.
    useEffect(() => {
        if (!type) return;
        initForType(type, course);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [type, course]);

    // Live audience preview for the assign wizard.
    useEffect(() => {
        if (type !== 'assign') return;
        const handle = setTimeout(() => {
            const params = new URLSearchParams();
            (form.course_ids ?? []).forEach((id: number) => params.append('course_ids[]', String(id)));
            params.set('audience_type', form.audience_type ?? 'individuals');
            (form.user_ids ?? []).forEach((id: number) => params.append('user_ids[]', String(id)));
            if (form.role) params.set('role', form.role);
            if (form.site_id) params.set('site_id', form.site_id);
            fetch(`/hr/training/assignments/preview?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((d) => d && setPreview(d))
                .catch(() => undefined);
        }, 300);
        return () => clearTimeout(handle);
    }, [type, form.course_ids, form.audience_type, form.user_ids, form.role, form.site_id]);

    if (!type) return null;
    const cur = steps[step];

    const setF = (key: string, val: any) => {
        setForm((p) => ({ ...p, [key]: val }));
        setErrors((p) => ({ ...p, [key]: '' }));
    };
    const toggleArr = (key: string, val: any) => {
        const arr: any[] = form[key] ?? [];
        setF(key, arr.includes(val) ? arr.filter((v) => v !== val) : [...arr, val]);
    };

    const visibleFields = (s: StepCfg) => s.fields.filter((f) => !f.showWhen || f.showWhen(form));

    const validate = (): boolean => {
        const errs: Record<string, string> = {};
        visibleFields(cur).forEach((f) => {
            if (!f.required) return;
            const v = form[f.key];
            const empty = Array.isArray(v) ? v.length === 0 : v === undefined || v === '' || v === null;
            if (empty) errs[f.key] = 'Required';
        });
        if (cur.items && !items.some((i) => i.amount)) errs._items = 'Add at least one item';
        setErrors(errs);
        return Object.keys(errs).length === 0;
    };

    const claimTotal = items.reduce((n, i) => n + (parseFloat(i.amount) || 0), 0);

    const totalReq = steps.reduce((n, s) => n + s.fields.filter((f) => f.required).length, 0);
    const filledReq = steps.reduce(
        (n, s) =>
            n +
            s.fields.filter((f) => {
                if (!f.required) return false;
                const v = form[f.key];
                return Array.isArray(v) ? v.length > 0 : v !== undefined && v !== '';
            }).length,
        0,
    );
    const completeness = totalReq ? Math.round((filledReq / totalReq) * 100) : 100;

    const buildPayload = () => {
        const f = { ...form };
        const num = (v: any) => (v === '' || v == null ? null : Number(v));
        if (type === 'createCourse' || type === 'editCourse') {
            return {
                title: f.title,
                code: f.code,
                category: f.category || null,
                delivery_method: f.delivery_method,
                provider: f.provider || null,
                description: f.description || null,
                learning_outcomes: f.learning_outcomes || null,
                duration_hours: num(f.duration_hours) ?? 0,
                max_participants: num(f.max_participants),
                is_active: !!f.is_active,
                is_mandatory: !!f.is_mandatory,
                requires_renewal: !!f.requires_renewal,
                validity_period_months: num(f.validity_period_months),
                renewal_reminder_months: num(f.renewal_reminder_months),
                requires_assessment: !!f.requires_assessment,
                pass_mark_percentage: num(f.pass_mark_percentage),
                cpd_points: num(f.cpd_points),
                cost: num(f.cost),
                org_pays_provider: !!f.org_pays_provider,
                staff_can_claim: !!f.staff_can_claim,
                compliance_requirement_id: f.compliance_requirement_id ? num(f.compliance_requirement_id) : null,
            };
        }
        if (type === 'session') {
            return {
                session_date: f.session_date,
                start_time: f.start_time || null,
                end_time: f.end_time || null,
                location: f.location || null,
                online_link: f.online_link || null,
                trainer_id: f.trainer_id ? num(f.trainer_id) : null,
                max_participants: num(f.max_participants),
                waitlist_enabled: !!f.waitlist_enabled,
                notes: f.notes || null,
            };
        }
        if (type === 'assign') {
            return {
                course_ids: f.course_ids ?? [],
                audience_type: f.audience_type ?? 'individuals',
                user_ids: f.user_ids ?? [],
                role: f.role || null,
                site_id: f.site_id ? num(f.site_id) : null,
                due_at: f.due_at || null,
                source: f.source || 'manual',
            };
        }
        if (type === 'record') {
            return {
                course_id: num(f.course_id),
                user_ids: f.user_ids ?? [],
                completed_at: f.completed_at,
                score: num(f.score),
                certificate_number: f.certificate_number || null,
                notes: f.notes || null,
                certificate: f.certificate || null,
            };
        }
        if (type === 'claim') {
            return {
                title: f.title,
                course_id: f.course_id ? num(f.course_id) : null,
                notes: f.notes || null,
                items: items
                    .filter((i) => i.amount)
                    .map((i) => ({ description: i.description, category: i.category, amount: i.amount, expense_date: i.expense_date })),
            };
        }
        return {};
    };

    const endpoint = () => {
        if (type === 'editCourse') return `${ENDPOINTS.editCourse}/${course?.id}`;
        if (type === 'session') return `${ENDPOINTS.session}/${course?.id}/sessions`;
        return ENDPOINTS[type];
    };

    const doSubmit = () => {
        if (submitting) return;
        setSubmitting(true);
        const payload = buildPayload();
        const opts = {
            preserveScroll: true,
            forceFormData: type === 'record',
            onSuccess: () => {
                setSuccess(true);
                onSaved();
            },
            onError: (e: Record<string, string>) => {
                toast.error(Object.values(e)[0] ?? 'Check the highlighted fields');
            },
            onFinish: () => setSubmitting(false),
        };
        if (type === 'editCourse') router.put(endpoint(), payload as any, opts);
        else router.post(endpoint(), payload as any, opts);
    };

    const next = () => {
        if (!validate()) {
            toast.error('Check the highlighted fields');
            return;
        }
        if (step >= steps.length - 1) doSubmit();
        else setStep(step + 1);
    };

    const reviewRows = (): { label: string; value: string }[] => {
        const f = form;
        const v = (x: any) => (x === undefined || x === '' || x == null ? '—' : String(x));
        if (type === 'createCourse' || type === 'editCourse')
            return [
                { label: 'Title', value: v(f.title) },
                { label: 'Code', value: v(f.code) },
                { label: 'Category', value: v(f.category) },
                { label: 'Delivery', value: v(DELIVERY_OPTS.find((d) => d.value === f.delivery_method)?.label) },
                { label: 'Provider', value: v(f.provider) },
                { label: 'Duration', value: f.duration_hours ? `${f.duration_hours} h` : '—' },
                { label: 'Mandatory', value: f.is_mandatory ? 'Yes' : 'No' },
                { label: 'Renewal', value: f.requires_renewal ? `${f.validity_period_months || '?'} months` : 'No renewal' },
                { label: 'CPD points', value: v(f.cpd_points) },
                { label: 'Fee', value: f.cost ? fmtNzd(parseFloat(f.cost)) : 'Free' },
            ];
        if (type === 'session')
            return [
                { label: 'Date', value: v(f.session_date) },
                { label: 'Time', value: `${f.start_time || '?'} – ${f.end_time || '?'}` },
                { label: 'Location', value: v(f.location) },
                { label: 'Capacity', value: v(f.max_participants) },
            ];
        if (type === 'assign')
            return [
                { label: 'Courses', value: String((f.course_ids ?? []).length) + ' selected' },
                { label: 'Assign by', value: v(f.audience_type) },
                { label: 'Audience size', value: `${preview?.count ?? 0} people` },
                { label: 'Conflicts', value: `${preview?.conflicts ?? 0} already assigned` },
                { label: 'Due date', value: v(f.due_at) },
            ];
        if (type === 'record')
            return [
                { label: 'Course', value: v(courses.find((c) => String(c.id) === String(f.course_id))?.title) },
                { label: 'People', value: String((f.user_ids ?? []).length) },
                { label: 'Completion date', value: v(f.completed_at) },
                { label: 'Score', value: f.score ? `${f.score}%` : '—' },
                { label: 'Certificate', value: f.certificate ? 'Attached' : 'None' },
            ];
        if (type === 'claim')
            return [
                { label: 'Title', value: v(f.title) },
                { label: 'Items', value: String(items.filter((i) => i.amount).length) },
                { label: 'Total', value: fmtNzd(claimTotal) },
            ];
        return [];
    };

    const renderField = (cfg: FieldCfg) => {
        const val = form[cfg.key];
        const err = errors[cfg.key];
        const colSpan = cfg.half ? 'col-span-1' : 'col-span-2';
        const showLabel = !['toggle', 'info'].includes(cfg.type);
        return (
            <div key={cfg.key} className={colSpan}>
                {showLabel && cfg.label && <label className={labelCls}>{cfg.label}</label>}
                {cfg.type === 'text' && (
                    <input className={inputCls} value={val ?? ''} placeholder={cfg.placeholder} onChange={(e) => setF(cfg.key, e.target.value)} />
                )}
                {cfg.type === 'number' && (
                    <input type="number" className={inputCls} value={val ?? ''} placeholder={cfg.placeholder} onChange={(e) => setF(cfg.key, e.target.value)} />
                )}
                {cfg.type === 'date' && <input type="date" className={inputCls} value={val ?? ''} onChange={(e) => setF(cfg.key, e.target.value)} />}
                {cfg.type === 'time' && <input type="time" className={inputCls} value={val ?? ''} onChange={(e) => setF(cfg.key, e.target.value)} />}
                {cfg.type === 'textarea' && (
                    <textarea className={textareaCls} value={val ?? ''} placeholder={cfg.placeholder} onChange={(e) => setF(cfg.key, e.target.value)} />
                )}
                {cfg.type === 'select' && (
                    <select className={inputCls} value={val ?? ''} onChange={(e) => setF(cfg.key, e.target.value)}>
                        {(cfg.options ?? []).map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                )}
                {cfg.type === 'segmented' && (
                    <div className="inline-flex flex-wrap overflow-hidden rounded-[9px] border border-border">
                        {(cfg.options ?? []).map((o) => {
                            const sel = val === o.value;
                            return (
                                <button
                                    key={o.value}
                                    type="button"
                                    onClick={() => setF(cfg.key, o.value)}
                                    className={`border-r border-border px-[13px] py-2 text-[12.5px] font-semibold last:border-r-0 ${sel ? 'bg-primary text-white' : 'bg-card text-foreground'}`}
                                >
                                    {o.label}
                                </button>
                            );
                        })}
                    </div>
                )}
                {cfg.type === 'people' && (
                    <div className="thin flex max-h-[150px] flex-wrap gap-[7px] overflow-y-auto rounded-[10px] border border-border p-[10px]">
                        {lookups.staff.map((p) => {
                            const sel = (val ?? []).includes(p.id);
                            return (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => toggleArr(cfg.key, p.id)}
                                    className={`inline-flex items-center gap-[6px] rounded-full border py-[5px] pr-[11px] pl-[6px] text-[12.5px] font-semibold ${sel ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground'}`}
                                >
                                    <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[9px] text-white">
                                        {p.name.split(' ').map((n) => n[0]).join('').slice(0, 2).toUpperCase()}
                                    </span>
                                    {p.name}
                                </button>
                            );
                        })}
                    </div>
                )}
                {cfg.type === 'courses' && (
                    <div className="thin flex max-h-[180px] flex-wrap gap-[7px] overflow-y-auto rounded-[10px] border border-border p-[10px]">
                        {courses.map((c) => {
                            const sel = (val ?? []).includes(c.id);
                            return (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => toggleArr(cfg.key, c.id)}
                                    className={`rounded-full border px-3 py-[6px] text-[12.5px] font-semibold ${sel ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground'}`}
                                >
                                    {c.title}
                                </button>
                            );
                        })}
                    </div>
                )}
                {cfg.type === 'toggle' && (
                    <button
                        type="button"
                        onClick={() => setF(cfg.key, !val)}
                        className="flex w-full items-center justify-between rounded-[10px] border border-border bg-card px-[13px] py-[10px]"
                    >
                        <span className="text-[13px] font-semibold">{cfg.label}</span>
                        <span className={`inline-flex h-[22px] w-[38px] rounded-full p-[2px] ${val ? 'justify-end bg-primary' : 'justify-start bg-border'}`}>
                            <span className="h-[18px] w-[18px] rounded-full bg-white" />
                        </span>
                    </button>
                )}
                {cfg.type === 'file' && (
                    <label className="flex cursor-pointer items-center gap-[10px] rounded-[10px] border border-dashed border-border p-4 text-[13px] text-muted-foreground">
                        <Upload className="h-[18px] w-[18px]" />
                        {val?.name ?? 'Drop certificate (PDF/JPG) or click to upload'}
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            className="hidden"
                            onChange={(e) => setF(cfg.key, e.target.files?.[0] ?? null)}
                        />
                    </label>
                )}
                {cfg.type === 'info' && (
                    <div className="flex items-start gap-[9px] rounded-[10px] bg-accent px-[13px] py-[11px]">
                        <Info className="mt-[1px] h-4 w-4 flex-none text-primary" />
                        <span className="text-[12px] text-accent-foreground">{cfg.hint}</span>
                    </div>
                )}
                {err && <div className="mt-[5px] text-[11.5px] text-status-critical">{err}</div>}
            </div>
        );
    };

    return (
        <div className="ovl fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-5" onClick={onClose}>
            <div
                className="pop flex h-[min(88vh,820px)] w-[min(960px,96vw)] overflow-hidden rounded-[18px] bg-card shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                {success ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-4 p-10 text-center">
                        <div className="flex h-[72px] w-[72px] items-center justify-center rounded-full bg-status-success-bg">
                            <Check className="h-[34px] w-[34px] text-status-success" strokeWidth={2.5} />
                        </div>
                        <div className="text-[21px] font-bold">{SUCCESS_MSG[type]}</div>
                        <div className="max-w-[340px] text-[13.5px] text-muted-foreground">All set. You can add another or close this dialog.</div>
                        <div className="mt-1 flex gap-[10px]">
                            <button
                                type="button"
                                onClick={() => {
                                    // After editing a course, "add another" starts a fresh create;
                                    // otherwise keep the same wizard + its course context.
                                    if (type === 'editCourse') initForType('createCourse', null);
                                    else initForType(type, course);
                                }}
                                className="rounded-[9px] border border-border bg-card px-4 py-[9px] text-[13px] font-semibold"
                            >
                                Save &amp; add another
                            </button>
                            <button type="button" onClick={onClose} className="rounded-[9px] bg-primary px-4 py-[9px] text-[13px] font-semibold text-white">
                                Done
                            </button>
                        </div>
                    </div>
                ) : (
                    <div className="flex min-w-0 flex-1">
                        {/* LEFT RAIL */}
                        <div className="flex w-[236px] flex-none flex-col border-r border-border bg-sidebar p-[22px_18px]">
                            <div className="text-[11px] font-bold tracking-[.08em] text-primary uppercase">{TITLES[type]}</div>
                            <div className="mt-[3px] text-[11.5px] text-muted-foreground">
                                Step {step + 1} of {steps.length}
                            </div>
                            <div className="mt-[18px] flex flex-1 flex-col gap-[3px]">
                                {steps.map((s, i) => {
                                    const done = i < step;
                                    const current = i === step;
                                    return (
                                        <button
                                            key={s.key}
                                            type="button"
                                            onClick={() => i <= step && setStep(i)}
                                            className={`flex items-center gap-[11px] rounded-[9px] p-[9px_10px] text-left ${current ? 'bg-accent text-accent-foreground' : 'bg-transparent text-muted-foreground'}`}
                                        >
                                            <span
                                                className={`flex h-6 w-6 flex-none items-center justify-center rounded-full border-[1.5px] text-[11.5px] font-bold ${
                                                    done
                                                        ? 'border-primary bg-primary text-white'
                                                        : current
                                                          ? 'border-primary bg-card text-primary'
                                                          : 'border-border bg-card text-muted-foreground'
                                                }`}
                                            >
                                                {done ? <Check className="h-3 w-3" strokeWidth={3} /> : i + 1}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-[13px] font-semibold">{s.label}</span>
                                                <span className="block text-[11px] text-muted-foreground">{s.blurb}</span>
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="mt-[14px]">
                                <div className="mb-[5px] flex justify-between text-[10.5px] font-semibold text-muted-foreground">
                                    <span>Completeness</span>
                                    <span>{completeness}%</span>
                                </div>
                                <div className="h-[6px] overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-primary transition-all" style={{ width: `${completeness}%` }} />
                                </div>
                            </div>
                            {type === 'assign' && (
                                <div className="mt-[14px] rounded-[10px] bg-accent p-[11px_12px]">
                                    <div className="text-[10.5px] font-bold tracking-[.06em] text-primary uppercase">Live preview</div>
                                    <div className="mt-[2px] text-2xl font-bold">{preview?.count ?? 0}</div>
                                    <div className="text-[11px] text-muted-foreground">people will be assigned</div>
                                </div>
                            )}
                        </div>

                        {/* RIGHT CONTENT */}
                        <div className="flex min-w-0 flex-1 flex-col">
                            <div className="h-[3px] bg-muted">
                                <div className="h-full bg-primary transition-all" style={{ width: `${Math.round(((step + 1) / steps.length) * 100)}%` }} />
                            </div>
                            <div className="thin flex-1 overflow-y-auto p-[26px_30px]">
                                <div className="text-[19px] font-bold">{cur.label}</div>
                                <div className="mt-[2px] mb-5 text-[13px] text-muted-foreground">{cur.blurb}</div>

                                {cur.review ? (
                                    <>
                                        <div className="overflow-hidden rounded-[14px] border border-border">
                                            {reviewRows().map((r) => (
                                                <div key={r.label} className="flex justify-between gap-4 border-b border-border px-4 py-[9px] text-[13px] last:border-b-0">
                                                    <span className="text-muted-foreground">{r.label}</span>
                                                    <span className="max-w-[60%] text-right font-semibold">{r.value}</span>
                                                </div>
                                            ))}
                                        </div>
                                        <div className="mt-[14px] flex items-start gap-[9px] rounded-[10px] bg-status-warning-bg p-[11px_13px]">
                                            <AlertTriangle className="mt-[1px] h-4 w-4 flex-none text-status-warning" />
                                            <span className="text-[12px] text-status-warning">Review carefully — this writes a record. You can edit any step from the rail before submitting.</span>
                                        </div>
                                    </>
                                ) : cur.items ? (
                                    <div className="flex flex-col gap-3">
                                        {items.map((it, i) => (
                                            <div key={i} className="rounded-[14px] border border-border p-[13px]">
                                                <div className="mb-2 flex justify-between">
                                                    <span className="text-[11.5px] font-semibold text-muted-foreground">Item {i + 1}</span>
                                                    {items.length > 1 && (
                                                        <button type="button" className="text-[11.5px] text-status-critical" onClick={() => setItems(items.filter((_, idx) => idx !== i))}>
                                                            Remove
                                                        </button>
                                                    )}
                                                </div>
                                                <div className="grid grid-cols-[1fr_110px_110px] items-end gap-[10px]">
                                                    <div className="col-span-3">
                                                        <label className={labelCls}>Description</label>
                                                        <input className={inputCls} value={it.description} placeholder="Course fee — enrolment" onChange={(e) => setItems(items.map((x, idx) => (idx === i ? { ...x, description: e.target.value } : x)))} />
                                                    </div>
                                                    <div>
                                                        <label className={labelCls}>Category</label>
                                                        <select className={inputCls} value={it.category} onChange={(e) => setItems(items.map((x, idx) => (idx === i ? { ...x, category: e.target.value } : x)))}>
                                                            <option value="training">Training</option>
                                                            <option value="travel">Travel</option>
                                                            <option value="supplies">Materials</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label className={labelCls}>Amount</label>
                                                        <input type="number" className={inputCls} value={it.amount} placeholder="0.00" onChange={(e) => setItems(items.map((x, idx) => (idx === i ? { ...x, amount: e.target.value } : x)))} />
                                                    </div>
                                                    <div className="col-span-3">
                                                        <label className={labelCls}>Date</label>
                                                        <input type="date" className={inputCls} value={it.expense_date} onChange={(e) => setItems(items.map((x, idx) => (idx === i ? { ...x, expense_date: e.target.value } : x)))} />
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                        {errors._items && <div className="text-[11.5px] text-status-critical">{errors._items}</div>}
                                        <button type="button" onClick={() => setItems([...items, { description: '', category: 'training', amount: '', expense_date: todayStr }])} className="self-start rounded-[9px] border border-dashed border-border bg-card px-[13px] py-2 text-[12.5px] font-semibold">
                                            + Add item
                                        </button>
                                        <div className="flex justify-end border-t border-border pt-3 text-[14px] font-bold">Total: {fmtNzd(claimTotal)}</div>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-2 gap-[14px_16px]">{visibleFields(cur).map(renderField)}</div>
                                )}
                            </div>

                            {/* FOOTER */}
                            <div className="flex items-center gap-[10px] border-t border-border p-[14px_22px]">
                                {step > 0 && (
                                    <button type="button" onClick={() => setStep(step - 1)} className="rounded-[9px] border border-border bg-card px-4 py-[9px] text-[13px] font-semibold">
                                        Back
                                    </button>
                                )}
                                <button type="button" onClick={onClose} className="border-0 bg-transparent text-[13px] font-semibold text-muted-foreground">
                                    Cancel
                                </button>
                                <div className="ml-auto flex gap-[9px]">
                                    <button type="button" disabled={submitting} onClick={next} className="rounded-[9px] bg-primary px-5 py-[9px] text-[13px] font-bold text-white disabled:opacity-60">
                                        {step >= steps.length - 1 ? FINAL_LABEL[type] : 'Continue'}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

export default TrainingWizardDialog;
