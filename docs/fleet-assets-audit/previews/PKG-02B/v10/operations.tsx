import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import type { CalendarItem } from '@/lib/calendar/recur';
import {
    ArrowRight,
    CalendarDays,
    Car,
    FileCheck2,
    FileText,
    Wrench,
} from 'lucide-react';
import React, { useEffect, useState } from 'react';
import { addMonths } from './calendar-months';
import {
    calendarAppointmentAction,
    snoozeReminderAction,
} from './calendar-workflows';
import { catalogField } from './catalog-fields';
import { CatalogPicker } from './catalog-picker';
import { useChecklists } from './checklist-library';
import {
    drivingActions,
    drivingSeed,
    type DrivingState,
} from './driving-workflows';
import {
    EvidenceField,
    PhotoDialog,
    readFiles,
    type EvidenceFile,
} from './evidence-field';
import { UploadFlow } from './flows';
import {
    financeRecords,
    financeSeed,
    recordActions,
    type FinanceState,
} from './record-model';
import { FinanceRecordDialog } from './record-workspaces';
import {
    trackerActions,
    trackerFacts,
    trackerSeed,
    type TrackerState,
    type VehicleAlert,
} from './telemetry-state';
import { journeys } from './trip-data';
import { Badge, Modal, Notice, Panel, Picker, Row } from './ui';

export const TODAY = '2026-09-22';
export const dateLabel = (v: string) =>
    v
        ? new Date(`${v.slice(0, 10)}T12:00:00`).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : 'Not recorded';
const km = (v: number) => `${v.toLocaleString('en-NZ')} km`;
const addDays = (v: string, days: number) => {
    const d = new Date(`${v}T12:00:00`);
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
type Schedule = {
    id: string;
    name: string;
    months: number;
    distance: number;
    due: string;
    dueKm: number;
    last: string;
    lastKm: number;
    owner: string;
    lead: number;
    leadKm: number;
};
type Work = {
    cancelledAt?: string;
    estimateStart?: string;
    estimateEnd?: string;
    id: string;
    title: string;
    source: string;
    sourceChecks?: import('./flows').Run[];
    schedule?: string;
    status: string;
    owner: string;
    target: string;
    provider: string;
    start: string;
    end: string;
    confirmed: string;
    unavailable: boolean;
    outcome: string;
    completed: string;
    odo: number;
    evidence: string;
    notes: string;
    quote: string;
    approval: string;
    parts: string;
    labour: string;
    feedback: string;
    history: string[];
};
type Booking = {
    approvalRoute?: string;
    exemptionReason?: string;
    id: string;
    start: string;
    end: string;
    requester: string;
    driver: string;
    purpose: string;
    pickup: string;
    status: string;
    outKm: number;
    returnKm: number;
    condition: string;
    history: string[];
    block?: boolean;
};
export type Reading = {
    corrects?: string;
    id: string;
    value: number;
    at: string;
    author: string;
    source: string;
    reason: string;
};
type Compliance = {
    id: string;
    name: string;
    due: string;
    applies: string;
    basis: string;
    outcome: string;
    evidence: string;
    low: number;
    high: number;
};
export type VehicleReminder = {
    repeatAnchor?: string;
    id: string;
    title: string;
    source: string;
    at: string;
    owner: string;
    backup: string;
    repeat: number;
    notes: string;
    status: string;
    history: string[];
};
export type Store = {
    finance: FinanceState;
    driving: DrivingState;
    tracker: TrackerState;
    alerts: VehicleAlert[];
    alertPlan: {
        speed: number;
        speedTolerance: number;
        speedDuration: number;
        speedCooldown: number;
        owner: string;
        backup: string;
        offline: number;
        voltage: number;
        duration: number;
        notes: string;
    };
    followups: VehicleReminder[];
    photo?: EvidenceFile;
    documents: EvidenceFile[];
    schedules: Schedule[];
    works: Work[];
    bookings: Booking[];
    readings: Reading[];
    compliance: Compliance[];
    released: boolean;
    restriction: boolean;
    reminders: Record<
        string,
        { status: string; channel: string; history: string[] }
    >;
    profile: {
        make: string;
        model: string;
        fuel: string;
        seats: string;
        insurer: string;
        insuranceDue: string;
        warrantyDue: string;
        owner: string;
        vin: string;
        category: string;
        insurance: string;
        warranty: string;
        lease: string;
        life: string;
        history: string[];
    };
    amendments: string[];
    checkDue: string;
    checkOwner: string;
    checkTemplate: string;
};
const makeWork = (id: string, title: string, source: string): Work => ({
    id,
    title,
    source,
    status: 'Awaiting assessment',
    owner: 'Kōwhai House Coordinator',
    target: '2026-09-24',
    provider: '',
    start: '',
    end: '',
    confirmed: '',
    unavailable: false,
    outcome: '',
    completed: '',
    odo: 0,
    evidence: '',
    notes: '',
    quote: '',
    approval: '',
    parts: '',
    labour: '',
    feedback: '',
    history: ['21 Sep · Alex Morgan · Record created'],
});
function seed(scenario: string): Store {
    const empty = scenario === 'empty',
        ready = scenario === 'ready',
        unknown = empty || scenario === 'unknown';
    const service = {
        ...makeWork('WO-0268', 'Routine service', 'SCH-DEMO-07'),
        schedule: 'SCH-DEMO-07',
        status: 'Internal plan',
        provider: 'Harbour Workshop · DEMO',
        start: '2026-09-24T09:00',
        end: '2026-09-24T11:00',
        unavailable: true,
    };
    const concern = {
        ...makeWork('WO-0264', 'Condition concern', 'CHK-0182'),
        ...(scenario === 'awaiting'
            ? {
                  status: 'Completed · awaiting release',
                  outcome: 'Passed',
                  completed: '2026-09-21',
                  odo: 82460,
                  evidence: 'EV-DEMO-264 · repair and retest report',
              }
            : {}),
    };
    return {
        finance: financeSeed(),
        tracker: trackerSeed(),
        driving: drivingSeed(),
        alerts: [],
        alertPlan: {
            speed: 50,
            speedTolerance: 5,
            speedDuration: 15,
            speedCooldown: 60,
            owner: 'Shift lead',
            backup: 'Operations Manager',
            offline: 30,
            voltage: 11.8,
            duration: 5,
            notes: 'Draft only: confirm occupant safety, correlate repeated signals, and follow the approved escalation procedure.',
        },
        documents: [],
        schedules: empty
            ? []
            : [
                  {
                      id: 'SCH-DEMO-07',
                      name: 'Routine service',
                      months: 6,
                      distance: 10000,
                      due: scenario === 'overdue' ? '2026-09-18' : '2026-09-24',
                      dueKm: scenario === 'overdue' ? 82000 : 85000,
                      last: '2026-03-24',
                      lastKm: 74800,
                      owner: 'Kōwhai House Coordinator',
                      lead: 7,
                      leadKm: 1000,
                  },
                  {
                      id: 'SCH-DEMO-08',
                      name: 'Passenger lift service',
                      months: 6,
                      distance: 0,
                      due: '2026-11-13',
                      dueKm: 0,
                      last: '2026-05-13',
                      lastKm: 0,
                      owner: 'Equipment Coordinator',
                      lead: 14,
                      leadKm: 0,
                  },
              ],
        works: empty
            ? []
            : [
                  ...(ready ? [] : [concern]),
                  service,
                  {
                      ...makeWork('WO-0188', 'Routine service', 'SCH-DEMO-07'),
                      schedule: 'SCH-DEMO-07',
                      status: 'Completed',
                      outcome: 'Passed',
                      completed: '2026-03-24',
                      odo: 74800,
                      evidence: 'EV-DEMO-0188 · service report',
                      provider: 'Harbour Workshop · DEMO',
                      notes: 'Oil, filter and recorded inspection completed.',
                      parts: 'Oil and filter · $145 example',
                      labour: '2 hours · $210 example',
                      approval: 'PO-DEMO-188',
                  },
                  {
                      ...makeWork(
                          'WO-0210',
                          'Cancelled provider visit',
                          'SCH-DEMO-07',
                      ),
                      status: 'Cancelled',
                      cancelledAt: '2026-09-18',
                      notes: 'Provider unavailable. No work performed. Replaced by WO-0268.',
                      history: [
                          '18 Sep · Coordinator · Cancelled: provider unavailable',
                      ],
                  },
              ],
        bookings: [],
        readings: empty
            ? []
            : [
                  {
                      id: 'ODO-DEMO-03',
                      value: 82460,
                      at:
                          scenario === 'stale'
                              ? '2026-09-02T16:15'
                              : '2026-09-21T08:10',
                      author: 'Alex Morgan',
                      source: 'CHK-0182 · manual reading',
                      reason: '',
                  },
                  {
                      id: 'ODO-DEMO-02',
                      value: 82418,
                      at:
                          scenario === 'stale'
                              ? '2026-09-01T17:10'
                              : '2026-09-20T17:10',
                      author: 'Jamie Taylor',
                      source: 'Return condition record',
                      reason: '',
                  },
                  {
                      id: 'ODO-DEMO-01',
                      value: 74800,
                      at: '2026-03-24T11:00',
                      author: 'Workshop report',
                      source: 'WO-0188',
                      reason: '',
                  },
              ],
        compliance: [
            {
                id: 'WOF-DEMO-14',
                name: 'WoF',
                due: unknown ? '' : '2027-04-09',
                applies: unknown ? 'Unknown' : 'Applicable',
                basis: unknown ? '' : 'Vehicle classification record · DEMO',
                outcome: unknown ? 'Needs assessment' : 'Passed',
                evidence: unknown ? '' : 'EV-WOF-14 · certificate',
                low: 0,
                high: 0,
            },
            {
                id: 'REG-DEMO-14',
                name: 'Registration',
                due: unknown ? '' : '2026-10-08',
                applies: 'Applicable',
                basis: 'Vehicle register',
                outcome: unknown ? 'Needs assessment' : 'Recorded',
                evidence: unknown ? '' : 'EV-REG-14 · licence',
                low: 0,
                high: 0,
            },
            {
                id: 'RUC-DEMO-14',
                name: 'RUC',
                due: '',
                applies: ready ? 'Applicable' : 'Unknown',
                basis: ready ? 'APP-DEMO-14 · example vehicle assessment' : '',
                outcome: ready ? 'Recorded' : 'Needs assessment',
                evidence: ready ? 'EV-RUC-14 · licence' : '',
                low: ready ? 80000 : 0,
                high: ready ? 90000 : 0,
            },
            {
                id: 'COF-DEMO-14',
                name: 'CoF',
                due: '',
                applies: ready ? 'Not applicable' : 'Unknown',
                basis: ready ? 'APP-DEMO-14 · example vehicle assessment' : '',
                outcome: ready ? 'Applicability recorded' : 'Needs assessment',
                evidence: '',
                low: 0,
                high: 0,
            },
        ],
        released: false,
        restriction: ['hold', 'awaiting', 'readonly', 'reportonly'].includes(
            scenario,
        ),
        reminders: empty
            ? {}
            : {
                  'SCH-DEMO-07': {
                      status: 'Delivery failed',
                      channel: 'In-app task + email example',
                      history: [
                          '21 Sep 09:00 · In-app task created · TASK-DEMO-07',
                          '21 Sep 09:01 · Email failed · retry available',
                      ],
                  },
                  'REG-DEMO-14': {
                      status: 'Scheduled',
                      channel: 'In-app task',
                      history: [],
                  },
              },
        profile: {
            make: 'Toyota',
            model: 'Hiace',
            fuel: 'Diesel',
            seats: '8',
            insurer: 'Example Fleet Insurance',
            insuranceDue: '2026-12-31',
            warrantyDue: '',
            owner: 'Kōwhai House Coordinator',
            vin: 'Not recorded',
            category: 'Passenger van',
            insurance: 'POL-DEMO-14',
            warranty: '',
            lease: 'Owned',
            life: 'In service',
            history: ['12 Jan 2024 · Vehicle added at Kōwhai House'],
        },
        followups: [],
        amendments: [],
        checkDue: scenario === 'overdue' ? '2026-09-18' : '2026-09-23',
        checkTemplate: 'condition',
        checkOwner: 'Shift lead',
    };
}
type Values = Record<string, string>;
export type Field = {
    key: string;
    label: string;
    type?:
        | 'record'
        | 'checklist'
        | 'catalog'
        | 'catalog-number'
        | 'number'
        | 'date'
        | 'datetime'
        | 'select'
        | 'text'
        | 'textarea'
        | 'check'
        | 'files'
        | 'approval'
        | 'person'
        | 'provider';
    options?: string[];
    records?: { id: string; name: string; detail: string }[];
    required?: boolean;
    hint?: string;
};
type Section = { title: string; description: string; fields: Field[] };
export type Action = {
    change?: (key: string, value: string, current: Values) => Values;
    extraCompletion?: (v: Values) => boolean[];
    validationSection?: (v: Values) => number;
    alternatives?: Values[];
    title: string;
    sections: Section[];
    values: Values;
    verb: string;
    note?: string;
    validate?: (v: Values) => string;
    save: (v: Values) => void;
    success?: string;
};
const f = (
    key: string,
    label: string,
    type: Field['type'] = 'text',
    required = true,
    hint?: string,
): Field => ({ key, label, type, required, hint });
const opt = (key: string, label: string, options: string[]): Field => ({
    ...f(key, label, 'select'),
    options,
});
const section = (
    title: string,
    description: string,
    fields: Field[],
): Section => ({ title, description, fields });
const ownerOptions = [
    'Kōwhai House Coordinator',
    'Equipment Coordinator',
    'Shift lead',
    'Alex Morgan',
    'Jamie Taylor',
    'Operations Manager',
];
const asValues = (o: object): Values =>
    Object.fromEntries(Object.entries(o).map(([k, v]) => [k, String(v ?? '')]));

export function useVehicleModel(
    scenario: string,
    fault: string,
    lastCheckOutcome?: string,
) {
    const checklistLibrary = useChecklists();
    const [data, setData] = useState(() => seed(scenario));
    const [uploadFor, setUploadFor] = useState('');
    const [photoOpen, setPhotoOpen] = useState(false);
    const [financeRecord, setFinanceRecord] = useState('');
    const [action, setAction] = useState<Action | null>(null),
        [record, setRecord] = useState<{
            title: string;
            rows: [string, string][];
        } | null>(null);
    const [message, setMessage] = useState(''),
        [undo, setUndo] = useState<Store | null>(null);
    useEffect(() => {
        setData(seed(scenario));
        setAction(null);
        setUploadFor('');
        setPhotoOpen(false);
        setFinanceRecord('');
        setRecord(null);
        setMessage('');
        setUndo(null);
    }, [scenario]);
    const canManage = !['readonly', 'reportonly', 'denied'].includes(scenario),
        canRequest = !['readonly', 'denied'].includes(scenario);
    const hold = data.restriction && !data.released;
    const tracker = trackerFacts(
        data,
        !['stale', 'empty', 'unknown', 'denied'].includes(scenario),
    );
    const odo = tracker.verified;
    const planningOdo = tracker.planning;
    const patch = (fn: (d: Store) => Store, msg: string) =>
        setData((d) => {
            setUndo(d);
            setMessage(msg);
            return fn(d);
        });
    const detail = (title: string, rows: [string, string][]) =>
        setRecord({ title, rows });
    const editWork = (id: string, fn: (w: Work) => Work, msg: string) =>
        patch(
            (d) => ({
                ...d,
                works: d.works.map((w) => (w.id === id ? fn(w) : w)),
            }),
            msg,
        );
    const open = (a: Action, request = false) => {
        if (request ? canRequest : canManage) setAction(a);
    };
    const telemetry = trackerActions(data, tracker.available, open, patch);
    const driving = drivingActions(data, open, patch);
    const recordTools = recordActions(data, open, patch);
    function schedule(id?: string) {
        const s = data.schedules.find((x) => x.id === id);
        open({
            extraCompletion: (v) => [
                +v.months > 0 || +v.distance > 0,
                !!v.due || +v.dueKm > 0,
            ],
            validationSection: (v) =>
                !(+v.months > 0 || +v.distance > 0) ? 0 : 1,
            title: s ? 'Manage service schedule' : 'Set up service schedule',
            verb: s ? 'Save schedule' : 'Create schedule',
            values: s
                ? asValues(s)
                : {
                      name: '',
                      months: '',
                      distance: '',
                      due: '',
                      dueKm: '',
                      owner: 'Kōwhai House Coordinator',
                      lead: '7',
                      leadKm: '1000',
                  },
            sections: [
                section(
                    'Service requirement',
                    'Use the approved vehicle or component service source. Example intervals are not policy.',
                    [
                        {
                            ...f('name', 'Service type', 'catalog'),
                            options: [
                                'Routine service',
                                'Oil & filter change',
                                'Tyres & alignment',
                                'Brake inspection',
                                'Accessibility lift service',
                                'Battery & electrical',
                                'Air conditioning',
                                'Safety inspection',
                            ],
                        },
                        {
                            ...f(
                                'months',
                                'Interval in months',
                                'catalog-number',
                                false,
                            ),
                            options: ['1', '3', '6', '12', '24'],
                        },
                        {
                            ...f(
                                'distance',
                                'Interval in kilometres',
                                'catalog-number',
                                false,
                            ),
                            options: ['5000', '10000', '15000', '20000'],
                        },
                    ],
                ),
                section(
                    'Next due & responsibility',
                    'The earlier applicable trigger requires action. A stale reading cannot prove remaining distance.',
                    [
                        f('due', 'Next due date', 'date', false),
                        f('dueKm', 'Next due odometer', 'number', false),
                        f('owner', 'Responsible person or role', 'person'),
                    ],
                ),
                section(
                    'Reminder plan',
                    'Demonstration settings owned by existing Tasks and notifications. Confirm organisational policy before implementation.',
                    [
                        f('lead', 'Days before due', 'number'),
                        f('leadKm', 'Kilometres before due', 'number'),
                        f('files', 'Service documents', 'files', false),
                    ],
                ),
            ],
            validate: (v) =>
                +v.months < 0 ||
                +v.distance < 0 ||
                (+v.months && !Number.isInteger(+v.months))
                    ? 'Use positive whole calendar months and kilometres.'
                    : !(+v.months > 0 || +v.distance > 0)
                      ? 'Record at least one positive interval.'
                      : !(v.due || +v.dueKm > 0)
                        ? 'Record at least one next-due trigger.'
                        : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((file) => ({
                                ...file,
                                owner:
                                    id ??
                                    `SCH-DEMO-${String(7 + d.schedules.length).padStart(2, '0')}`,
                            })),
                        ],
                        schedules: [
                            ...d.schedules.filter((x) => x.id !== id),
                            {
                                id:
                                    id ??
                                    `SCH-DEMO-${String(7 + d.schedules.length).padStart(2, '0')}`,
                                name: v.name,
                                months: +v.months,
                                distance: +v.distance,
                                due: v.due,
                                dueKm: +v.dueKm,
                                last: s?.last ?? '',
                                lastKm: s?.lastKm ?? 0,
                                owner: v.owner,
                                lead: +v.lead,
                                leadKm: +v.leadKm,
                            },
                        ],
                    }),
                    'Schedule and reminder plan updated.',
                ),
        });
    }
    function plan(id: string, isSchedule = false) {
        let w = data.works.find((x) =>
            isSchedule
                ? x.schedule === id &&
                  !['Completed', 'Cancelled'].includes(x.status)
                : x.id === id ||
                  (x.source === id &&
                      ![
                          'Completed',
                          'Completed · awaiting release',
                          'Cancelled',
                      ].includes(x.status)),
        );
        const s = data.schedules.find((x) => x.id === id);
        const c = data.compliance.find((x) => x.id === id);
        const fresh = !w;
        w = w ?? {
            ...makeWork(
                `WO-DEMO-${270 + data.works.length}`,
                s?.name ?? `${c?.name ?? 'Compliance'} appointment`,
                id,
            ),
            schedule: s?.id,
        };
        const chosen = w;
        open({
            title: w.start ? 'Manage appointment' : 'Plan appointment',
            verb: 'Save appointment',
            values: {
                ...asValues(w),
                start: w.start || '2026-09-24T09:00',
                end: w.end || '2026-09-24T11:00',
                unavailable: String(w.unavailable),
                changeReason: '',
                operation: 'Plan / reschedule',
            },
            sections: [
                section(
                    'Provider & owner',
                    `${w.title} · ${w.source} · VH-014`,
                    [
                        f('provider', 'Service provider', 'provider'),
                        f('owner', 'Responsible person or role', 'person'),
                        f('target', 'Target date', 'date'),
                    ],
                ),
                section(
                    'Appointment & vehicle use',
                    'An internal plan is separate from provider confirmation. Actual unavailable periods are projected onto the calendar.',
                    [
                        opt('operation', 'Appointment action', [
                            'Plan / reschedule',
                            'Cancel appointment',
                            'Record overrun',
                        ]),
                        f('start', 'Appointment start', 'datetime'),
                        f('end', 'Appointment end', 'datetime'),
                        f(
                            'unavailable',
                            'Vehicle unavailable during this appointment',
                            'check',
                            false,
                        ),
                        f(
                            'confirmed',
                            'Provider confirmation reference',
                            'text',
                            false,
                            'Leave blank until confirmation is recorded.',
                        ),
                        f(
                            'changeReason',
                            'Reason for change',
                            'textarea',
                            false,
                        ),
                    ],
                ),
                section(
                    'Booking impact',
                    'Busy-only bookings stay private. Any overlap requires the coordinator to arrange an alternative.',
                    [f('impact', 'Booking impact reviewed', 'check')],
                ),
            ],
            validate: (v) =>
                v.end <= v.start
                    ? 'End must follow start.'
                    : v.operation !== 'Plan / reschedule' &&
                        !v.changeReason.trim()
                      ? 'Record the cancellation or overrun reason.'
                      : '',
            note: '25 Sep 10:00–12:00 is busy-only. This preview sends no provider message or booking notification.',
            save: (v) => {
                const next = {
                    ...chosen,
                    provider: v.provider,
                    owner: v.owner,
                    target: v.target,
                    start: v.start,
                    end: v.end,
                    unavailable: v.unavailable === 'true',
                    confirmed: v.confirmed,
                    status:
                        v.operation === 'Cancel appointment'
                            ? 'Appointment cancelled'
                            : v.operation === 'Record overrun'
                              ? 'Overrun · assess bookings'
                              : v.confirmed
                                ? 'Provider confirmed'
                                : 'Internal plan',
                    history: [
                        ...chosen.history,
                        `22 Sep · Coordinator · ${v.operation}: ${v.changeReason || 'Plan recorded'} · ${v.confirmed || 'No provider confirmation'}`,
                    ],
                };
                patch(
                    (d) => ({
                        ...d,
                        works: fresh
                            ? [next, ...d.works]
                            : d.works.map((x) =>
                                  x.id === chosen.id ? next : x,
                              ),
                    }),
                    'Appointment and calendar updated; affected bookings require follow-up.',
                );
            },
        });
    }
    function assess(id: string) {
        const w = data.works.find((x) => x.id === id)!;
        open({
            title: 'Assess work & record costs',
            verb: 'Save assessment',
            values: asValues(w),
            sections: [
                section('Assessment', `${w.id} · ${w.title}`, [
                    f('owner', 'Responsible person or role', 'person'),
                    f('target', 'Target date', 'date'),
                    f('notes', 'Assessment and next action', 'textarea'),
                ]),
                section(
                    'Costs & approval references',
                    'Record existing Finance context. This action cannot approve spending or invoices.',
                    [
                        f('quote', 'Quote reference / estimate', 'text', false),
                        {
                            ...f(
                                'approval',
                                'Finance approval record',
                                'record',
                                false,
                            ),
                            records: [
                                {
                                    id: 'none',
                                    name: 'Not linked',
                                    detail: 'No approval recorded',
                                },
                                ...financeRecords
                                    .filter(
                                        (x) =>
                                            x.kind === 'Purchase order' &&
                                            x.status === 'Approved',
                                    )
                                    .map((x) => ({
                                        id: x.id,
                                        name: x.id + ' · ' + x.name,
                                        detail: x.status,
                                    })),
                            ],
                        },
                        f('parts', 'Parts and cost context', 'textarea', false),
                        f(
                            'labour',
                            'Labour and cost context',
                            'textarea',
                            false,
                        ),
                    ],
                ),
            ],
            save: (v) =>
                editWork(
                    id,
                    (w) =>
                        ({
                            ...w,
                            owner: v.owner,
                            target: v.target,
                            notes: v.notes,
                            quote: v.quote,
                            approval: v.approval,
                            parts: v.parts,
                            labour: v.labour,
                            status:
                                w.status === 'Awaiting assessment'
                                    ? 'Assessed'
                                    : w.status,
                            history: [
                                ...w.history,
                                '22 Sep · Coordinator · Assessment updated',
                            ],
                        }) as Work,
                    'Assessment recorded. Finance retains its own approval.',
                ),
        });
    }
    function complete(id: string) {
        const w = data.works.find((x) => x.id === id)!;
        const s = data.schedules.find((x) => x.id === w.schedule);
        open({
            change: (key, value, current) => ({
                ...current,
                [key]: value,
                ...(s && key === 'completed' && value && s.months
                    ? { due: addMonths(value, s.months) }
                    : {}),
                ...(s && key === 'odo' && s.distance
                    ? { dueKm: String(+value + s.distance) }
                    : {}),
            }),
            title: 'Record work outcome',
            verb: 'Record outcome',
            values: {
                outcome: 'Passed',
                completed: TODAY,
                odo: String(odo),
                evidence: '',
                notes: '',
                due: s ? addMonths(TODAY, s.months) : '',
                dueKm: s?.distance ? String(odo + s.distance) : '',
                feedback: '',
            },
            sections: [
                section(
                    'Actual work & evidence',
                    `${w.id} · ${w.title}. Preserve the original source and retest evidence.`,
                    [
                        opt('outcome', 'Outcome', [
                            'Passed',
                            'Failed',
                            'Needs retest',
                        ]),
                        f('completed', 'Actual completion date', 'date'),
                        f('odo', 'Completion odometer', 'number'),
                        f(
                            'evidence',
                            'Completion / retest evidence reference',
                            'text',
                            true,
                            'Use EV-DEMO-COMPLETE for a synthetic document reference.',
                        ),
                        f('notes', 'Work performed and outcome', 'textarea'),
                    ],
                ),
                section(
                    'Next service & follow-up',
                    s
                        ? 'Review the next triggers against the service source; these are suggestions from actual completion.'
                        : 'Record follow-up; this does not alter a service schedule.',
                    [
                        ...(s
                            ? [
                                  f('due', 'Following due date', 'date', false),
                                  f(
                                      'dueKm',
                                      'Following due odometer',
                                      'number',
                                      false,
                                  ),
                              ]
                            : []),
                        f('feedback', 'Feedback for the reporter', 'textarea'),
                        f(
                            'reviewed',
                            'Evidence and next action reviewed',
                            'check',
                        ),
                    ],
                ),
            ],
            validate: (v) =>
                +v.odo < odo
                    ? 'Completion odometer must not precede the selected reading. Record a correction first if needed.'
                    : v.completed > TODAY
                      ? 'Actual completion cannot be in the future relative to the preview date.'
                      : s &&
                          v.outcome === 'Passed' &&
                          ((v.due && v.due <= v.completed) ||
                              (+v.dueKm && +v.dueKm <= +v.odo))
                        ? 'Next service must follow actual completion.'
                        : s && v.outcome === 'Passed' && !v.due && !+v.dueKm
                          ? 'Review a following service trigger.'
                          : '',
            note: 'Failed or retest outcomes keep the schedule due and create a repair/retest next action. A passed outcome never releases a restriction.',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        works: d.works.map((x) =>
                            x.id === id
                                ? {
                                      ...x,
                                      outcome: v.outcome,
                                      completed: v.completed,
                                      odo: +v.odo,
                                      evidence: v.evidence,
                                      notes: v.notes,
                                      feedback: v.feedback,
                                      status:
                                          v.outcome === 'Passed'
                                              ? hold
                                                  ? 'Completed · awaiting release'
                                                  : 'Completed'
                                              : 'Repair / retest required',
                                      history: [
                                          ...x.history,
                                          `22 Sep · Coordinator · ${v.outcome} · ${v.evidence}`,
                                      ],
                                  }
                                : x,
                        ),
                        readings: [
                            {
                                id: `ODO-DEMO-${d.readings.length + 1}`,
                                value: +v.odo,
                                at: `${v.completed}T12:00`,
                                author: 'Work completion record',
                                source: id,
                                reason: 'Completion evidence observation',
                            },
                            ...d.readings,
                        ],
                        schedules: d.schedules.map((x) =>
                            x.id === s?.id && v.outcome === 'Passed'
                                ? {
                                      ...x,
                                      last: v.completed,
                                      lastKm: +v.odo,
                                      due: v.due,
                                      dueKm: +v.dueKm,
                                  }
                                : x,
                        ),
                        reminders:
                            s && v.outcome === 'Passed'
                                ? {
                                      ...d.reminders,
                                      [s.id]: {
                                          status: 'Resolved · next cycle scheduled',
                                          channel: 'In-app task',
                                          history: [
                                              ...(d.reminders[s.id]?.history ??
                                                  []),
                                              `22 Sep · Resolved by ${id}; next cycle uses reviewed triggers`,
                                          ],
                                      },
                                  }
                                : d.reminders,
                        restriction:
                            v.outcome === 'Failed' ||
                            v.outcome === 'Needs retest'
                                ? true
                                : d.restriction,
                        released: ['Failed', 'Needs retest'].includes(v.outcome)
                            ? false
                            : d.released,
                    }),
                    'Outcome recorded. Service history, reminders and next-due context updated.',
                ),
        });
    }
    function release() {
        open({
            title: 'Review authorised release',
            verb: 'Record release decision',
            values: {
                reviewer: 'Operations Manager',
                evidence: '',
                decision: 'Keep restricted',
                reason: '',
            },
            sections: [
                section(
                    'Independent review',
                    'Original failed check, repairs and retest remain linked. Completion alone is insufficient.',
                    [
                        f(
                            'reviewer',
                            'Independent authorised reviewer',
                            'person',
                        ),
                        f('evidence', 'Release assessment evidence reference'),
                        opt('decision', 'Decision', [
                            'Keep restricted',
                            'Release for use',
                        ]),
                        f('reason', 'Decision reason', 'textarea'),
                    ],
                ),
                section(
                    'Readiness review',
                    'Review applicable evidence and custody. Finance is separate.',
                    [
                        f(
                            'repairs',
                            'Required repairs and retests reviewed',
                            'check',
                        ),
                        f(
                            'compliance',
                            'Applicable compliance evidence reviewed',
                            'check',
                        ),
                        f(
                            'custody',
                            'Custody and equipment readiness reviewed',
                            'check',
                        ),
                    ],
                ),
            ],
            validate: (v) =>
                v.reviewer !== 'Operations Manager'
                    ? 'Choose the authorised independent reviewer in this example.'
                    : v.decision === 'Release for use' &&
                        data.works.some(
                            (w) => w.id === 'WO-0264' && w.outcome !== 'Passed',
                        )
                      ? 'Condition work requires a passed repair/retest outcome before release.'
                      : v.decision === 'Release for use' &&
                          data.works.some((w) =>
                              ['Failed', 'Needs retest'].includes(w.outcome),
                          )
                        ? 'Resolve failed or retest work before release.'
                        : v.decision === 'Release for use' &&
                            data.compliance.some(
                                (c) =>
                                    c.applies === 'Unknown' ||
                                    (c.applies === 'Applicable' &&
                                        (!c.evidence ||
                                            c.outcome === 'Failed' ||
                                            (c.due && c.due < TODAY))),
                            )
                          ? 'Resolve missing, failed or expired compliance evidence before release.'
                          : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        released: v.decision === 'Release for use',
                        works: d.works.map((w) =>
                            v.decision === 'Release for use' &&
                            w.status === 'Completed · awaiting release'
                                ? {
                                      ...w,
                                      status: 'Completed',
                                      history: [
                                          ...w.history,
                                          `22 Sep · Independent release ${v.evidence} recorded by ${v.reviewer}`,
                                      ],
                                  }
                                : w,
                        ),
                        restriction:
                            v.decision === 'Keep restricted'
                                ? true
                                : d.restriction,
                        profile: {
                            ...d.profile,
                            history: [
                                ...d.profile.history,
                                `22 Sep · ${v.reviewer} · ${v.decision} · ${v.reason} · ${v.evidence}`,
                            ],
                        },
                    }),
                    `Release review recorded: ${v.decision}.`,
                ),
        });
    }
    function compliance(id: string) {
        const c = data.compliance.find((x) => x.id === id)!;
        open({
            title: `Update ${c.name} evidence`,
            verb: 'Record evidence',
            values: { ...asValues(c), files: '[]' },
            sections: [
                section(
                    'Applicability',
                    'Confirm the vehicle-specific source; this preview does not determine legal applicability.',
                    [
                        opt('applies', 'Applicability', [
                            'Unknown',
                            'Applicable',
                            'Not applicable',
                        ]),
                        f('basis', 'Applicability basis / source', 'textarea'),
                    ],
                ),
                section(
                    'Evidence & next action',
                    'Retain the original certificate or licence; failed outcomes require repair/retest.',
                    [
                        opt('outcome', 'Evidence outcome', [
                            'Recorded',
                            'Passed',
                            'Failed',
                            'Needs assessment',
                        ]),
                        f('evidence', 'Evidence reference'),
                        f(
                            'files',
                            'Certificate / licence documents',
                            'files',
                            false,
                        ),
                        ...(c.name === 'RUC'
                            ? [
                                  f('low', 'Licence start odometer', 'number'),
                                  f('high', 'Licence end odometer', 'number'),
                              ]
                            : [
                                  f(
                                      'due',
                                      'Next due / expiry date',
                                      'date',
                                      false,
                                  ),
                              ]),
                    ],
                ),
            ],
            validate: (v) =>
                c.name === 'RUC' &&
                v.applies === 'Applicable' &&
                +v.high <= +v.low
                    ? 'Licence end must exceed its start.'
                    : v.applies === 'Applicable' && c.name !== 'RUC' && !v.due
                      ? 'Record the next due or expiry date.'
                      : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((file) => ({
                                ...file,
                                owner: id,
                                category: c.name,
                                status: 'Current',
                                expiry: v.due,
                            })),
                        ],
                        compliance: d.compliance.map((x) =>
                            x.id === id
                                ? { ...x, ...v, low: +v.low, high: +v.high }
                                : x,
                        ),
                        works:
                            v.outcome === 'Failed' &&
                            !d.works.some(
                                (w) =>
                                    w.source === id && w.outcome === 'Failed',
                            )
                                ? [
                                      {
                                          ...makeWork(
                                              `WO-DEMO-${270 + d.works.length}`,
                                              `${c.name} repair / retest`,
                                              id,
                                          ),
                                          outcome: 'Failed',
                                          status: 'Repair / retest required',
                                      },
                                      ...d.works,
                                  ]
                                : d.works,
                        restriction:
                            v.outcome === 'Failed' ? true : d.restriction,
                        released: v.outcome === 'Failed' ? false : d.released,
                    }),
                    `${c.name} evidence updated; any repair/retest remains separate.`,
                ),
        });
    }
    function mileage(correction?: Reading) {
        const id = 'ODO-DEMO-' + (data.readings.length + 1);
        open({
            title: correction
                ? 'Correct odometer reading'
                : 'Record odometer reading',
            verb: correction ? 'Save correction' : 'Save reading',
            values: {
                value: String(correction?.value ?? odo),
                at: correction?.at || '2026-09-22T09:30',
                source: correction?.source || 'Manual observation',
                reason: '',
                files: '[]',
            },
            sections: [
                section(
                    'Odometer observation',
                    correction
                        ? 'Correct ' +
                              correction.id +
                              ' with a reason. The original remains visible.'
                        : 'Record the dashboard reading and when it was observed. No tracker is required.',
                    [
                        f('value', 'Odometer in kilometres', 'number'),
                        f('at', 'Observed at', 'datetime'),
                    ],
                ),
                section(
                    'Source & evidence',
                    'Attach a dashboard photo or supporting document. Evidence stays with this reading.',
                    [
                        {
                            ...f('source', 'Reading source', 'record'),
                            records: [
                                {
                                    id: 'Manual observation',
                                    name: 'Manual observation',
                                    detail: 'Dashboard reading · this vehicle',
                                },
                                ...data.works.map((w) => ({
                                    id: w.id,
                                    name: w.title + ' · ' + w.id,
                                    detail: 'Maintenance · VH-014',
                                })),
                                ...data.bookings.map((b) => ({
                                    id: b.id,
                                    name: b.purpose + ' · ' + b.id,
                                    detail: 'Vehicle booking',
                                })),
                                ...(correction &&
                                ![
                                    'Manual observation',
                                    ...data.works.map((w) => w.id),
                                    ...data.bookings.map((b) => b.id),
                                ].includes(correction.source)
                                    ? [
                                          {
                                              id: correction.source,
                                              name: correction.source,
                                              detail: 'Original attribution retained',
                                          },
                                      ]
                                    : []),
                            ],
                        },
                        f(
                            'reason',
                            correction
                                ? 'Reason for correction'
                                : 'Observation notes',
                            'textarea',
                            !!correction,
                        ),
                        f(
                            'files',
                            'Odometer photo or document',
                            'files',
                            false,
                        ),
                    ],
                ),
            ],
            validate: (v) =>
                !Number.isFinite(+v.value) ||
                +v.value < 0 ||
                !Number.isInteger(+v.value)
                    ? 'Enter a non-negative whole kilometre reading.'
                    : v.at > '2026-09-22T23:59'
                      ? 'Choose an observation no later than the preview date.'
                      : !correction &&
                          data.readings
                              .filter(
                                  (r) =>
                                      !data.readings.some(
                                          (x) => x.corrects === r.id,
                                      ),
                              )
                              .some(
                                  (r) =>
                                      (r.at <= v.at && r.value > +v.value) ||
                                      (r.at > v.at && r.value < +v.value),
                              )
                        ? 'This conflicts with readings before or after this time. Review the source or use Correct reading with a reason.'
                        : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((file) => ({
                                ...file,
                                owner: id,
                            })),
                        ],
                        readings: [
                            {
                                id,
                                value: +v.value,
                                at: v.at,
                                author: 'Alex Morgan',
                                source: v.source,
                                reason: v.reason,
                                corrects: correction?.id,
                            },
                            ...d.readings,
                        ].sort((a, b) => b.at.localeCompare(a.at)),
                    }),
                    'Reading saved. Service and RUC distance updated; original evidence retained.',
                ),
        });
    }
    function followup(
        id?: string,
        source = 'Vehicle profile',
        start = '2026-09-23T09:00',
    ) {
        const r = data.followups.find((x) => x.id === id);
        const sources = [
            {
                id: 'check-due',
                name: 'Vehicle condition check',
                detail: 'Current check requirement',
            },
            ...journeys.map((t) => ({
                id: t.id,
                name: t.id + ' · ' + t.from + ' → ' + t.to,
                detail: 'Vehicle trip · ' + t.day,
            })),
            {
                id: 'Vehicle profile',
                name: 'Vehicle profile · VH-014',
                detail: 'General vehicle follow-up',
            },
            ...data.alerts.map((a) => ({
                id: a.id,
                name: a.kind + ' · ' + a.id,
                detail: 'Control Room response',
            })),
            ...data.schedules.map((s) => ({
                id: s.id,
                name: s.name + ' · ' + s.id,
                detail: 'Service schedule',
            })),
            ...data.compliance.map((c) => ({
                id: c.id,
                name: c.name + ' · ' + c.id,
                detail: 'Compliance evidence',
            })),
            ...data.works.map((w) => ({
                id: w.id,
                name: w.title + ' · ' + w.id,
                detail: 'Maintenance work',
            })),
        ];
        open({
            title: r ? 'Manage vehicle reminder' : 'Add vehicle reminder',
            verb: r ? 'Save reminder' : 'Create reminder',
            values: r
                ? asValues(r)
                : {
                      title: '',
                      source,
                      at: start,
                      owner: data.profile.owner,
                      backup: 'Operations Manager',
                      repeat: '0',
                      notes: '',
                      changeReason: '',
                  },
            sections: [
                section(
                    'Reminder & source',
                    'Link the follow-up to the vehicle or its owning record. A reminder never reserves the vehicle.',
                    [
                        f('title', 'Reminder title'),
                        {
                            ...f('source', 'Linked source', 'record'),
                            records: sources,
                        },
                        f('notes', 'Action to take', 'textarea'),
                    ],
                ),
                section(
                    'Timing & responsibility',
                    'Pacific/Auckland · in-app task. Email and SMS are not sent from this preview.',
                    [
                        f('at', 'Remind at', 'datetime'),
                        f('owner', 'Responsible person or role', 'person'),
                        f('backup', 'Backup owner', 'person'),
                        {
                            ...f(
                                'repeat',
                                'Repeat every (months)',
                                'catalog-number',
                                false,
                            ),
                            options: ['0', '1', '3', '6', '12'],
                            hint: '0 means one-off.',
                        },
                        ...(r
                            ? [
                                  f(
                                      'changeReason',
                                      'Reason for change',
                                      'textarea',
                                  ),
                              ]
                            : []),
                    ],
                ),
            ],
            validate: (v) =>
                v.at < '2026-09-22T09:30'
                    ? 'Choose a future reminder time.'
                    : +v.repeat < 0 || !Number.isInteger(+v.repeat)
                      ? 'Use a whole number of calendar months, or 0 for a one-off reminder.'
                      : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        followups: [
                            ...d.followups.filter((x) => x.id !== id),
                            {
                                id:
                                    id ||
                                    'REM-DEMO-' + (d.followups.length + 1),
                                title: v.title,
                                source: v.source,
                                at: v.at,
                                owner: v.owner,
                                backup: v.backup,
                                repeat: +v.repeat,
                                notes: v.notes,
                                status: 'Scheduled',
                                history: [
                                    ...(r?.history || []),
                                    '22 Sep · Alex Morgan · ' +
                                        (r
                                            ? 'Changed: ' + v.changeReason
                                            : 'Created'),
                                ],
                            },
                        ],
                    }),
                    'Reminder saved and linked to the vehicle calendar.',
                ),
        });
    }
    function followupAction(id: string, mode: string) {
        const r = data.followups.find((x) => x.id === id);
        if (!r) return;
        if (mode === 'snooze') {
            snoozeReminderAction(r, open, patch);
            return;
        }
        if (mode === 'detail') {
            detail(r.title, [
                ['Reference', r.id],
                ['Linked source', r.source],
                ['Reminder time', localDateTimeLabel(r.at)],
                ['Owner', r.owner],
                ['Backup', r.backup],
                ['Channel', 'In-app task · preview only'],
                [
                    'Repeat',
                    r.repeat ? r.repeat + ' calendar months' : 'One-off',
                ],
                ['Status', r.status],
                ['Action', r.notes],
                ['Delivery & activity', r.history.join('\n')],
            ]);
            return;
        }
        open({
            title:
                mode === 'complete'
                    ? 'Complete reminder follow-up'
                    : mode === 'pause'
                      ? 'Pause vehicle reminder'
                      : 'Acknowledge vehicle reminder',
            verb:
                mode === 'complete'
                    ? 'Record follow-up'
                    : mode === 'pause'
                      ? 'Pause reminder'
                      : 'Acknowledge reminder',
            values: { note: '' },
            sections: [
                section(
                    'Follow-up record',
                    r.title +
                        ' · This action does not complete linked work, evidence or release.',
                    [
                        f(
                            'note',
                            mode === 'complete'
                                ? 'Outcome and next action'
                                : 'Reason / owner note',
                            'textarea',
                        ),
                    ],
                ),
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        followups: d.followups.map((x) =>
                            x.id === id
                                ? {
                                      ...x,
                                      status:
                                          mode === 'complete'
                                              ? r.repeat
                                                  ? 'Scheduled'
                                                  : 'Completed'
                                              : mode === 'pause'
                                                ? 'Paused'
                                                : 'Acknowledged',
                                      at:
                                          mode === 'complete' && r.repeat
                                              ? addMonths(
                                                    (
                                                        r.repeatAnchor || r.at
                                                    ).slice(0, 10),
                                                    r.repeat,
                                                ) +
                                                (r.repeatAnchor || r.at).slice(
                                                    10,
                                                )
                                              : r.at,
                                      repeatAnchor:
                                          mode === 'complete'
                                              ? undefined
                                              : r.repeatAnchor,
                                      history: [
                                          ...x.history,
                                          '22 Sep · Alex Morgan · ' +
                                              mode +
                                              ' · ' +
                                              v.note,
                                      ],
                                  }
                                : x,
                        ),
                    }),
                    'Reminder activity recorded; linked obligation remains unchanged.',
                ),
        });
    }
    function reminder(id: string, mode: string) {
        const r = data.reminders[id];
        if (mode === 'detail') {
            detail('Reminder delivery history', [
                ['Obligation', id],
                [
                    'Owner',
                    data.schedules.find((s) => s.id === id)?.owner ??
                        'Kōwhai House Coordinator',
                ],
                ['Backup', 'Operations Manager · demo'],
                ['Channel', r?.channel ?? 'In-app task'],
                ['Status', r?.status ?? 'Scheduled'],
                [
                    'Delivery log',
                    r?.history.join('\n') || 'No delivery has been recorded.',
                ],
            ]);
            return;
        }
        open({
            title:
                mode === 'retry'
                    ? 'Retry failed reminder'
                    : 'Acknowledge reminder',
            verb:
                mode === 'retry' ? 'Retry delivery' : 'Record acknowledgement',
            values: { note: '' },
            sections: [
                section(
                    'Responsible follow-up',
                    `${id} · acknowledgement does not complete the obligation or clear a restriction.`,
                    [f('note', 'Follow-up action / owner note', 'textarea')],
                ),
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        reminders: {
                            ...d.reminders,
                            [id]: {
                                status:
                                    mode === 'retry'
                                        ? 'Delivered · acknowledgement due'
                                        : 'Acknowledged · action outstanding',
                                channel: r?.channel ?? 'In-app task',
                                history: [
                                    ...(r?.history ?? []),
                                    `22 Sep · Coordinator · ${mode === 'retry' ? 'Demo delivery succeeded' : 'Acknowledged'} · ${v.note}`,
                                ],
                            },
                        },
                    }),
                    'Reminder log updated. The obligation remains open.',
                ),
        });
    }
    function booking(start = '2026-09-23T09:00', id?: string, block = false) {
        const b = data.bookings.find((x) => x.id === id);
        const approvalBlocked =
            lastCheckOutcome !== 'Passed' ||
            (scenario === 'stale' && data.readings[0]?.id === 'ODO-DEMO-03') ||
            data.checkDue < TODAY ||
            data.schedules.some((s) => serviceStatus(s, planningOdo).overdue) ||
            hold ||
            data.profile.life !== 'In service' ||
            data.compliance.some(
                (c) =>
                    c.applies === 'Unknown' ||
                    (c.applies === 'Applicable' &&
                        (!c.evidence ||
                            c.outcome === 'Failed' ||
                            (c.due && c.due < TODAY) ||
                            (c.high > 0 && odo > c.high))),
            );
        const statusFor = (v: Values) =>
            block
                ? 'Unavailable'
                : v.approvalRoute === 'Approval not required' &&
                    canManage &&
                    !approvalBlocked
                  ? 'Confirmed'
                  : 'Pending approval';
        open(
            {
                title: block
                    ? 'Add unavailable period'
                    : b
                      ? 'Change booking'
                      : 'Request vehicle booking',
                verb: b
                    ? 'Save changes'
                    : block
                      ? 'Record block'
                      : 'Save booking request',
                alternatives: [1, 2, 3]
                    .map((n) => ({
                        start: addDays(start.slice(0, 10), n) + 'T09:00',
                        end: addDays(start.slice(0, 10), n) + 'T10:00',
                    }))
                    .filter((v) => !conflict(v.start, v.end, id)),
                values: {
                    start,
                    end:
                        (+start.slice(11, 13) === 23
                            ? addDays(start.slice(0, 10), 1) + 'T00'
                            : start.slice(0, 11) +
                              String(+start.slice(11, 13) + 1).padStart(
                                  2,
                                  '0',
                              )) +
                        ':' +
                        start.slice(14, 16),
                    requester: 'Alex Morgan',
                    driver: 'Jamie Taylor',
                    purpose: '',
                    pickup: 'Kōwhai House · key cabinet',
                    reason: '',
                    readyReview: 'false',
                    approvalRoute: 'Approval required',
                    exemptionReason: '',
                    files: '[]',
                    ...(b ? asValues(b) : {}),
                },
                sections: [
                    section(
                        'Vehicle & times',
                        'Kōwhai van · VH-014 · Kōwhai House · Pacific/Auckland',
                        [
                            f('start', 'Pickup / block start', 'datetime'),
                            f('end', 'Return / block end', 'datetime'),
                        ],
                    ),
                    section(
                        block ? 'Block reason' : 'People & purpose',
                        block
                            ? 'The unavailable interval appears on the calendar.'
                            : 'Only operational journey details; no passenger health information.',
                        block
                            ? [
                                  f(
                                      'purpose',
                                      'Reason for unavailable period',
                                      'textarea',
                                  ),
                              ]
                            : [
                                  f('requester', 'Requester', 'person'),
                                  f('driver', 'Driver', 'person'),
                                  f('purpose', 'Purpose', 'textarea'),
                                  f('pickup', 'Pickup / return and keys'),
                                  ...(b
                                      ? [
                                            f(
                                                'reason',
                                                'Reason for change',
                                                'textarea',
                                            ),
                                        ]
                                      : []),
                              ],
                    ),
                    ...(!block
                        ? [
                              section(
                                  'Approval & evidence',
                                  'Example approval routing. An authorised coordinator can record that approval is not required. Readiness and conflict checks still apply.',
                                  [
                                      f(
                                          'approvalRoute',
                                          'Booking approval',
                                          'approval',
                                      ),
                                      f(
                                          'exemptionReason',
                                          'Reason / policy authority for approval not required',
                                          'textarea',
                                          false,
                                          'Required when no evidence is attached for the approval-not-required path.',
                                      ),
                                      f(
                                          'readyReview',
                                          'Readiness and driver authority reviewed for approval not required',
                                          'check',
                                          false,
                                      ),
                                      f(
                                          'files',
                                          'Approval evidence',
                                          'files',
                                          false,
                                      ),
                                  ],
                              ),
                          ]
                        : []),
                ],
                validationSection: (v) =>
                    v.end <= v.start || !!conflict(v.start, v.end, id) ? 0 : 2,
                extraCompletion: (v) =>
                    v.approvalRoute === 'Approval not required'
                        ? [
                              Boolean(
                                  v.exemptionReason.trim() ||
                                  readFiles(v.files).length,
                              ),
                              !canManage || v.readyReview === 'true',
                          ]
                        : [],
                validate: (v) =>
                    !b && v.start < '2026-09-22T09:30'
                        ? 'Choose a future booking or unavailable period.'
                        : v.end <= v.start
                          ? 'Return must follow pickup.'
                          : conflict(v.start, v.end, id)
                            ? conflict(v.start, v.end, id) +
                              ' Choose another time; no conflicting booking will be submitted.'
                            : !block &&
                                v.approvalRoute === 'Approval not required' &&
                                !v.exemptionReason.trim() &&
                                !readFiles(v.files).length
                              ? 'Add a reason or upload evidence for approval not required.'
                              : !block &&
                                  canManage &&
                                  v.approvalRoute === 'Approval not required' &&
                                  v.readyReview !== 'true'
                                ? 'Review readiness and driver authority for the approval-not-required path.'
                                : '',
                note: block
                    ? 'This period marks the vehicle unavailable on the calendar. It does not create or clear a safety restriction.'
                    : hold
                      ? 'This vehicle is restricted. Requests remain pending until readiness is resolved.'
                      : canManage
                        ? 'Approval required → coordinator review. Approval not required → confirmed only when readiness and conflict checks pass. Keys, checkout and return are always recorded.'
                        : 'You can request the approval-not-required path with a reason or evidence. A coordinator must verify your authority before confirmation.',
                save: (v) => {
                    const bookingId =
                        b?.id ??
                        (block ? 'BLOCK' : 'BOOK') +
                            '-DEMO-' +
                            (data.bookings.length + 1);
                    const status = statusFor(v);
                    patch(
                        (d) => ({
                            ...d,
                            documents: [
                                ...d.documents,
                                ...readFiles(v.files).map((file) => ({
                                    ...file,
                                    owner: bookingId,
                                })),
                            ],
                            bookings: b
                                ? d.bookings.map((x) =>
                                      x.id === id
                                          ? {
                                                ...x,
                                                start: v.start,
                                                end: v.end,
                                                requester: v.requester,
                                                driver: v.driver,
                                                purpose: v.purpose,
                                                pickup: v.pickup,
                                                approvalRoute: v.approvalRoute,
                                                exemptionReason:
                                                    v.exemptionReason,
                                                status,
                                                history: [
                                                    ...x.history,
                                                    '22 Sep · Changed: ' +
                                                        v.reason,
                                                    'Approval path: ' +
                                                        v.approvalRoute +
                                                        ' · ' +
                                                        (v.exemptionReason ||
                                                            'Supporting evidence'),
                                                ],
                                            }
                                          : x,
                                  )
                                : [
                                      ...d.bookings,
                                      {
                                          id: bookingId,
                                          start: v.start,
                                          end: v.end,
                                          requester: v.requester,
                                          driver: v.driver,
                                          purpose: v.purpose,
                                          pickup: v.pickup,
                                          status,
                                          approvalRoute: v.approvalRoute,
                                          exemptionReason: v.exemptionReason,
                                          outKm: 0,
                                          returnKm: 0,
                                          condition: '',
                                          block,
                                          history: [
                                              '22 Sep · Created in preview',
                                              'Approval path: ' +
                                                  (v.approvalRoute ||
                                                      'Unavailable period') +
                                                  ' · ' +
                                                  (v.exemptionReason ||
                                                      'No exemption reason'),
                                              status === 'Confirmed'
                                                  ? 'Coordinator authority recorded; readiness and conflicts checked.'
                                                  : 'Coordinator review required.',
                                          ],
                                      },
                                  ],
                        }),
                        block
                            ? 'Unavailable period recorded.'
                            : status === 'Confirmed'
                              ? 'Booking confirmed with approval-not-required authority recorded.'
                              : 'Booking saved pending coordinator review.',
                    );
                },
            },
            !block,
        );
    }
    function conflict(
        start: string,
        end: string,
        id?: string,
        workId?: string,
    ) {
        if (
            scenario !== 'empty' &&
            start < '2026-09-25T12:00' &&
            end > '2026-09-25T10:00'
        )
            return 'Conflicts with a private booking (busy only).';
        if (
            data.bookings.some(
                (b) =>
                    b.id !== id &&
                    !['Cancelled', 'Returned', 'Declined'].includes(b.status) &&
                    start < b.end &&
                    end > b.start,
            )
        )
            return 'Conflicts with another booking or unavailable period.';
        if (
            data.works.some(
                (w) =>
                    w.id !== workId &&
                    w.unavailable &&
                    w.start &&
                    ![
                        'Appointment cancelled',
                        'Cancelled',
                        'Completed',
                        'Completed · awaiting release',
                    ].includes(w.status) &&
                    start < w.end &&
                    end > w.start,
            )
        )
            return 'Conflicts with a service unavailable period.';
        return '';
    }
    function bookingTransition(id: string, mode: string) {
        const b = data.bookings.find((x) => x.id === id)!;
        open({
            title:
                mode === 'approve'
                    ? 'Review booking request'
                    : mode === 'decline'
                      ? 'Decline booking request'
                      : mode === 'out'
                        ? 'Check out vehicle'
                        : mode === 'return'
                          ? 'Return vehicle'
                          : 'Cancel booking / block',
            verb:
                mode === 'approve'
                    ? 'Approve booking'
                    : mode === 'decline'
                      ? 'Decline request'
                      : mode === 'out'
                        ? 'Record checkout'
                        : mode === 'return'
                          ? 'Record return'
                          : 'Confirm cancellation',
            values: {
                reason: '',
                odo: String(odo),
                condition: 'No new concern',
                evidence: '',
                keys: 'false',
                ready: 'false',
            },
            sections: [
                section(
                    'Booking record',
                    `${b.id} · ${localDateTimeLabel(b.start)} to ${localDateTimeLabel(b.end)}`,
                    [
                        ...(mode === 'approve'
                            ? [
                                  f(
                                      'ready',
                                      'Readiness, driver authority and conflicts reviewed',
                                      'check',
                                  ),
                              ]
                            : mode === 'out' || mode === 'return'
                              ? [
                                    f('odo', 'Recorded odometer', 'number'),
                                    opt('condition', 'Condition', [
                                        'No new concern',
                                        'Concern recorded',
                                    ]),
                                    f(
                                        'evidence',
                                        'Condition check / evidence reference',
                                    ),
                                    f(
                                        'keys',
                                        mode === 'out'
                                            ? 'Keys handed to recorded driver'
                                            : 'Keys and vehicle received',
                                        'check',
                                    ),
                                ]
                              : []),
                        f('reason', 'Decision / custody notes', 'textarea'),
                    ],
                ),
            ],
            validate: (v) =>
                mode === 'approve' || mode === 'out'
                    ? lastCheckOutcome !== 'Passed'
                        ? 'Review the vehicle condition check before approval or checkout.'
                        : (scenario === 'stale' &&
                                data.readings[0]?.id === 'ODO-DEMO-03') ||
                            data.checkDue < TODAY ||
                            data.schedules.some(
                                (s) => serviceStatus(s, planningOdo).overdue,
                            )
                          ? 'Review stale readings or overdue service/check requirements before approval or checkout.'
                          : hold
                            ? 'Active restriction prevents approval or checkout.'
                            : data.profile.life !== 'In service'
                              ? 'Vehicle lifecycle does not permit use.'
                              : data.compliance.some(
                                      (c) =>
                                          c.applies === 'Unknown' ||
                                          (c.applies === 'Applicable' &&
                                              (!c.evidence ||
                                                  c.outcome === 'Failed' ||
                                                  (c.due && c.due < TODAY))),
                                  )
                                ? 'Resolve missing, failed or expired compliance evidence before approval.'
                                : mode === 'out' && +v.odo < odo
                                  ? 'Checkout reading must not precede the selected reading.'
                                  : mode === 'out' &&
                                      b.start > '2026-09-22T09:30'
                                    ? 'Pickup is in the future. Checkout requires a current booking.'
                                    : conflict(b.start, b.end, id)
                    : mode === 'return' && +v.odo < b.outKm
                      ? 'Return reading must not precede checkout.'
                      : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        bookings: d.bookings.map((x) =>
                            x.id === id
                                ? {
                                      ...x,
                                      status:
                                          mode === 'approve'
                                              ? 'Confirmed'
                                              : mode === 'decline'
                                                ? 'Declined'
                                                : mode === 'out'
                                                  ? 'Checked out'
                                                  : mode === 'return'
                                                    ? 'Returned'
                                                    : 'Cancelled',
                                      outKm: mode === 'out' ? +v.odo : x.outKm,
                                      returnKm:
                                          mode === 'return'
                                              ? +v.odo
                                              : x.returnKm,
                                      condition: v.condition,
                                      history: [
                                          ...x.history,
                                          `22 Sep · Coordinator · ${mode}: ${v.reason} · ${v.evidence || ''}`,
                                      ],
                                  }
                                : x,
                        ),
                        readings:
                            mode === 'return'
                                ? [
                                      {
                                          id: `ODO-DEMO-${d.readings.length + 1}`,
                                          value: +v.odo,
                                          at: '2026-09-22T09:30',
                                          author: 'Alex Morgan',
                                          source: id,
                                          reason: v.reason,
                                      },
                                      ...d.readings,
                                  ]
                                : d.readings,
                        works:
                            mode === 'return' &&
                            v.condition === 'Concern recorded'
                                ? [
                                      {
                                          ...makeWork(
                                              `WO-DEMO-${270 + d.works.length}`,
                                              'Return condition concern',
                                              id,
                                          ),
                                          outcome: 'Needs retest',
                                      },
                                      ...d.works,
                                  ]
                                : d.works,
                    }),
                    `Booking ${mode} recorded. ${mode === 'return' && v.condition === 'Concern recorded' ? 'A linked condition work record requires assessment.' : ''}`,
                ),
        });
    }
    const profile = recordTools.editProfile;
    function checkPlan() {
        open({
            title: 'Plan check requirement',
            verb: 'Save check plan',
            values: {
                due: data.checkDue,
                owner: data.checkOwner,
                template: data.checkTemplate,
            },
            sections: [
                section(
                    'Current requirement',
                    'Choose the reusable template for this vehicle requirement. New checks use its latest published demo version.',
                    [
                        f('template', 'Checklist template', 'checklist'),
                        f('due', 'Next check date', 'date'),
                        f('owner', 'Responsible role', 'person'),
                    ],
                ),
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        checkDue: v.due,
                        checkOwner: v.owner,
                        checkTemplate: v.template,
                    }),
                    'Check requirement updated.',
                ),
        });
    }
    function amendment(runId = 'CHK-0182') {
        open({
            title: 'Add amendment · ' + runId,
            verb: 'Record amendment',
            values: { note: '', source: runId },
            sections: [
                section(
                    'Attributable amendment',
                    'Original check ' +
                        runId +
                        ' remains immutable. A retest creates another run.',
                    [f('note', 'Amendment and reason', 'textarea')],
                ),
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        amendments: [
                            ...d.amendments,
                            `22 Sep · Alex Morgan · ${runId} · ${v.note}`,
                        ],
                    }),
                    'Amendment linked; original check preserved.',
                ),
        });
    }
    const calendarItems: CalendarItem[] = [];
    const event = (
        id: string,
        title: string,
        source: string,
        start: string,
        end: string | null,
        ref: string | null,
        allDay = false,
    ) =>
        calendarItems.push({
            id,
            title,
            source,
            start,
            end,
            ref,
            allDay,
            group: 'auto',
            status: 'scheduled',
            owner: null,
            site: null,
            room: null,
            link: null,
            editable: false,
        } as CalendarItem);
    if (hold)
        event(
            'restriction',
            'Restriction started · still active',
            'damage',
            '2026-09-21T00:00:00',
            null,
            'RST-DEMO-12',
            true,
        );
    if (scenario !== 'empty')
        event(
            'private',
            'Busy',
            'respite',
            '2026-09-25T10:00:00',
            '2026-09-25T12:00:00',
            null,
        );
    data.works
        .filter(
            (w) =>
                w.start &&
                !['Appointment cancelled', 'Cancelled'].includes(w.status),
        )
        .forEach((w) =>
            event(
                w.id,
                `${w.title} · ${w.status}`,
                'event',
                w.start,
                w.end,
                w.id,
            ),
        );
    data.schedules
        .filter((s) => s.due)
        .forEach((s) =>
            event(
                s.id,
                `${s.name} due · reminder`,
                'compliance',
                `${s.due}T00:00:00`,
                null,
                s.id,
                true,
            ),
        );
    data.compliance
        .filter((c) => c.due)
        .forEach((c) =>
            event(
                c.id,
                `${c.name} due · reminder`,
                'compliance',
                `${c.due}T00:00:00`,
                null,
                c.id,
                true,
            ),
        );
    if (scenario !== 'empty')
        event(
            'check-due',
            'Vehicle check due · reminder',
            'compliance',
            `${data.checkDue}T00:00:00`,
            null,
            'DEMO-3',
            true,
        );
    data.works
        .filter((w) => w.estimateStart && w.estimateEnd)
        .forEach((w) =>
            event(
                'estimate-' + w.id,
                'Estimated work · advisory',
                'asset',
                w.estimateStart + 'T00:00:00',
                addDays(w.estimateEnd!, 1) + 'T00:00:00',
                w.id,
                true,
            ),
        );
    data.followups
        .filter((r) => !['Completed', 'Paused'].includes(r.status))
        .forEach((r) =>
            event(
                r.id,
                r.title + ' · reminder',
                'compliance',
                r.at,
                null,
                r.id,
            ),
        );
    data.bookings
        .filter((b) => !['Cancelled', 'Declined'].includes(b.status))
        .forEach((b) =>
            event(
                b.id,
                b.block
                    ? `Unavailable · ${b.purpose}`
                    : `${b.purpose} · ${b.status}`,
                'respite',
                b.start,
                b.end,
                b.id,
            ),
        );
    const reportCreated = (
        id: string,
        source: string,
        report?: {
            check?: import('./flows').Run;
            title: string;
            notes: string;
            range: [string | null, string | null];
        },
    ) =>
        patch(
            (d) => ({
                ...d,
                works: d.works.some((w) => w.id === id)
                    ? d.works.map((w) =>
                          w.id === id
                              ? {
                                    ...w,
                                    sourceChecks: report?.check
                                        ? [
                                              ...(w.sourceChecks || []).filter(
                                                  (x) =>
                                                      x.id !== report.check!.id,
                                              ),
                                              report.check,
                                          ]
                                        : w.sourceChecks,
                                    notes: [w.notes, report?.notes]
                                        .filter(Boolean)
                                        .join('\n'),
                                    history: [
                                        ...w.history,
                                        `22 Sep · Alex Morgan · Linked report from ${source}`,
                                    ],
                                    estimateStart:
                                        report?.range[0] || w.estimateStart,
                                    estimateEnd:
                                        report?.range[1] || w.estimateEnd,
                                }
                              : w,
                      )
                    : [
                          {
                              ...makeWork(
                                  id,
                                  report?.title || 'Reported vehicle concern',
                                  source,
                              ),
                              sourceChecks: report?.check ? [report.check] : [],
                              notes: report?.notes || '',
                              estimateStart: report?.range[0] || '',
                              estimateEnd: report?.range[1] || '',
                          },
                          ...d.works,
                      ],
            }),
            'Report linked to work record.',
        );
    return {
        tracker,
        telemetry,
        driving,
        planningOdo,
        linkAlertExisting: (id: string) => {
            const alert = data.alerts.find((a) => a.id === id);
            if (
                !alert ||
                alert.work ||
                alert.decision !== 'Maintenance assessment required'
            )
                return;
            open({
                title: 'Link existing Maintenance work',
                verb: 'Link work record',
                values: { work: '', reason: '' },
                sections: [
                    section(
                        'Existing work',
                        'Preserve the Control Room source and avoid duplicate work.',
                        [
                            {
                                ...f('work', 'Open work record', 'record'),
                                records: data.works
                                    .filter(
                                        (w) =>
                                            ![
                                                'Completed',
                                                'Cancelled',
                                            ].includes(w.status),
                                    )
                                    .map((w) => ({
                                        id: w.id,
                                        name: w.id + ' · ' + w.title,
                                        detail: w.status,
                                    })),
                            },
                            f(
                                'reason',
                                'Why this work covers the alert',
                                'textarea',
                            ),
                        ],
                    ),
                ],
                save: (v) =>
                    patch(
                        (d) => ({
                            ...d,
                            alerts: d.alerts.map((a) =>
                                a.id === id
                                    ? {
                                          ...a,
                                          work: v.work,
                                          history: [
                                              ...a.history,
                                              `Linked existing ${v.work}: ${v.reason}`,
                                          ],
                                      }
                                    : a,
                            ),
                            works: d.works.map((w) =>
                                w.id === v.work
                                    ? {
                                          ...w,
                                          notes:
                                              w.notes +
                                              '\nControl Room ' +
                                              id +
                                              ': ' +
                                              v.reason,
                                          history: [
                                              ...w.history,
                                              'Control Room source linked: ' +
                                                  id,
                                          ],
                                      }
                                    : w,
                            ),
                        }),
                        'Existing Maintenance work linked; source preserved.',
                    ),
            });
        },
        linkAlertWork: (id: string) => {
            const alert = data.alerts.find((a) => a.id === id);
            if (
                !alert ||
                alert.work ||
                !canManage ||
                alert.decision !== 'Maintenance assessment required'
            )
                return;
            const workId =
                'WO-' +
                (Math.max(
                    268,
                    ...data.works.map(
                        (w) => Number(w.id.replace(/\D/g, '')) || 0,
                    ),
                ) +
                    1);
            patch(
                (d) => ({
                    ...d,
                    works: [
                        {
                            ...makeWork(workId, alert.kind + ' assessment', id),
                            notes:
                                'Control Room ' +
                                id +
                                ' · ' +
                                alert.correlation,
                        },
                        ...d.works,
                    ],
                    alerts: d.alerts.map((a) =>
                        a.id === id
                            ? {
                                  ...a,
                                  work: workId,
                                  history: [
                                      ...a.history,
                                      'Maintenance assessment linked: ' +
                                          workId,
                                  ],
                              }
                            : a,
                    ),
                }),
                'Control Room source linked to Maintenance assessment.',
            );
        },
        readingStale:
            scenario === 'stale' && data.readings[0]?.id === 'ODO-DEMO-03',
        nextSchedule: data.schedules
            .slice()
            .sort((a, b) =>
                (a.due || '9999').localeCompare(b.due || '9999'),
            )[0],
        ...recordTools,
        financeRecord,
        setFinanceRecord,
        canViewFinance: !['reportonly', 'denied'].includes(scenario),
        uploadFor,
        setUploadFor,
        photoOpen,
        setPhotoOpen,
        setPhoto: (photo: EvidenceFile) =>
            patch((d) => ({ ...d, photo }), 'Vehicle profile photo updated.'),
        attach: (files: EvidenceFile[]) =>
            patch(
                (d) => ({
                    ...d,
                    documents: [
                        ...d.documents,
                        ...files
                            .filter(
                                (f) => !d.documents.some((x) => x.id === f.id),
                            )
                            .map((f) => ({ ...f, owner: uploadFor })),
                    ],
                }),
                'Evidence retained with its owning record.',
            ),
        reportCreated,
        data,
        canManage,
        canRequest,
        hold,
        odo,
        action,
        setAction,
        record,
        setRecord,
        message,
        setMessage,
        undo: () => {
            if (undo) {
                setData(undo);
                setUndo(null);
                setMessage('Last change undone.');
            }
        },
        canUndo: !!undo,
        detail,
        schedule,
        plan,
        calendarAppointment: (start: string) =>
            calendarAppointmentAction(
                data,
                start,
                makeWork,
                (start, end, work) => conflict(start, end, undefined, work),
                open,
                patch,
            ),
        assess,
        complete,
        release,
        compliance,
        mileage,
        followup,
        followupAction,
        reminder,
        booking,
        bookingTransition,
        profile,
        checkPlan,
        amendment,
        calendarItems,
        fault,
        conflict,
    };
}
export type VehicleModel = ReturnType<typeof useVehicleModel>;

export function serviceStatus(s: Schedule, odo: number) {
    const days = Math.round(
        (new Date(`${s.due}T12:00:00`).getTime() -
            new Date(`${TODAY}T12:00:00`).getTime()) /
            86400000,
    );
    const remaining = s.dueKm && odo ? s.dueKm - odo : null;
    return {
        overdue: !!(s.due && days < 0) || (remaining !== null && remaining < 0),
        label: [
            s.due
                ? `${dateLabel(s.due)} · ${days < 0 ? `${-days} days overdue` : `in ${days} days`}`
                : 'No date trigger',
            s.dueKm
                ? `${km(s.dueKm)} · ${remaining === null ? 'reading required' : remaining < 0 ? `${km(-remaining)} overdue` : `${km(remaining)} remaining`}`
                : 'No distance trigger',
        ].join(' / '),
    };
}
const buttons = (children: React.ReactNode) => (
    <div className="op-actions">{children}</div>
);
export function VehicleSurface({
    model: m,
    view,
    sub,
    onNav,
    onWork,
    onCheck,
}: {
    model: VehicleModel;
    view: string;
    sub: string;
    onNav: (v: string, s?: string) => void;
    onWork: (id: string) => void;
    onCheck: () => void;
}) {
    const d = m.data,
        s = m.nextSchedule;
    const manage = (label: string, fn: () => void) => (
        <Button variant="outline" disabled={!m.canManage} onClick={fn}>
            {label}
        </Button>
    );
    const doc = (ref: string) =>
        m.detail('Document record', [
            ['Reference', ref],
            ['Vehicle', 'VH-014 · KWH014'],
            ['Custodian', d.profile.owner],
            [
                'Evidence status',
                'Synthetic reference only; no real document supplied.',
            ],
            ['Access', 'Permitted vehicle record; original source retained'],
        ]);
    if (view === 'overview' && sub === 'summary')
        return (
            <div className="content-grid">
                <div className="stack">
                    <Panel
                        title={
                            m.hold
                                ? 'Vehicle use restricted'
                                : 'Review readiness for the next use'
                        }
                        sub="Readiness is assessed again for the actual booking"
                    >
                        <Notice
                            title={
                                m.hold
                                    ? 'RST-DEMO-12 · Active restriction'
                                    : 'No active use restriction in this scenario'
                            }
                            tone={m.hold ? 'critical' : 'info'}
                        >
                            {m.hold
                                ? 'Coordinator owns assessment and repair; Operations Manager owns independent release. No automatic end is assumed.'
                                : 'Current evidence, driver authority, equipment and custody still need checking at checkout.'}
                        </Notice>
                        {buttons(
                            <>
                                <Button
                                    onClick={() => onWork('WO-0264')}
                                    disabled={
                                        !d.works.some((w) => w.id === 'WO-0264')
                                    }
                                >
                                    Review condition work
                                </Button>
                                {manage('Review release', m.release)}
                            </>,
                        )}
                        <Row
                            title="Next check"
                            sub={`${d.checkOwner} · Vehicle condition · DEMO-3`}
                            value={dateLabel(d.checkDue)}
                            action={() => onNav('checks')}
                        />
                        <Row
                            title="Current custody"
                            value={
                                d.bookings.find(
                                    (b) => b.status === 'Checked out',
                                )
                                    ? `With ${d.bookings.find((b) => b.status === 'Checked out')!.driver}`
                                    : 'At Kōwhai House · keys held at site'
                            }
                            action={() => onNav('calendar')}
                        />
                    </Panel>
                    <Panel
                        title="Next service"
                        sub="The first reached date or distance trigger needs action"
                        action={
                            <Button
                                variant="ghost"
                                onClick={() => onNav('compliance', 'schedules')}
                            >
                                All schedules <ArrowRight size={15} />
                            </Button>
                        }
                    >
                        {s ? (
                            <>
                                <Row
                                    title={s.name}
                                    sub={`${s.id} · Owner: ${s.owner}`}
                                    value={serviceStatus(s, m.odo).label}
                                    badge={
                                        <Badge
                                            tone={
                                                serviceStatus(s, m.odo).overdue
                                                    ? 'critical'
                                                    : 'warning'
                                            }
                                        >
                                            {serviceStatus(s, m.odo).overdue
                                                ? 'Overdue'
                                                : 'Upcoming'}
                                        </Badge>
                                    }
                                />
                                {buttons(
                                    <>
                                        {manage('Plan service', () =>
                                            m.plan(s.id, true),
                                        )}
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                onNav('compliance', 'reminders')
                                            }
                                        >
                                            Review reminders
                                        </Button>
                                    </>,
                                )}
                            </>
                        ) : (
                            <>
                                <p>No service schedule is recorded.</p>
                                {buttons(
                                    manage('Set up service schedule', () =>
                                        m.schedule(),
                                    ),
                                )}
                            </>
                        )}
                    </Panel>
                    <Panel title="Obligations needing a next action">
                        {d.compliance
                            .filter(
                                (c) => !c.evidence || c.name === 'Registration',
                            )
                            .map((c) => (
                                <Row
                                    key={c.id}
                                    title={c.name}
                                    sub={`Owner: ${d.profile.owner} · ${c.id}`}
                                    value={
                                        c.evidence
                                            ? `Renew before ${dateLabel(c.due)}`
                                            : 'Assess applicability and record evidence'
                                    }
                                    action={() => onNav('compliance')}
                                />
                            ))}
                    </Panel>
                </div>
                <div className="stack">
                    <Panel title="Vehicle context">
                        <div className="vehicle-tile">
                            <Car size={40} />
                            <div>
                                <strong>Toyota Hiace</strong>
                                <span>KWH014 · VH-014</span>
                            </div>
                        </div>
                        <Row title="Responsible role" value={d.profile.owner} />
                        <Row title="Home site" value="Kōwhai House" />
                        <Row
                            title="Passenger lift"
                            value="Separate service and check evidence"
                            action={() =>
                                m.detail('Passenger lift · EQ-DEMO-14', [
                                    ['Vehicle', 'VH-014'],
                                    [
                                        'Service schedule',
                                        'SCH-DEMO-08 · 12 Nov 2026',
                                    ],
                                    [
                                        'Last service',
                                        '13 May 2026 · EV-LIFT-05',
                                    ],
                                    [
                                        'Check evidence',
                                        'Before use · DEMO-LIFT · illustrative requirement',
                                    ],
                                    [
                                        'Responsible role',
                                        'Equipment Coordinator',
                                    ],
                                    ['Status', 'Assess before passenger use'],
                                ])
                            }
                        />
                        {buttons(
                            <Button
                                variant="outline"
                                onClick={() => onNav('overview', 'details')}
                            >
                                Vehicle details
                            </Button>,
                        )}
                    </Panel>
                    <Panel
                        title="Upcoming"
                        sub="Pacific/Auckland · only permitted record details"
                    >
                        {m.calendarItems
                            .filter((e) => e.id !== 'restriction')
                            .sort((a, b) =>
                                (a.start ?? '').localeCompare(b.start ?? ''),
                            )
                            .slice(0, 5)
                            .map((e) => (
                                <Row
                                    key={e.id}
                                    title={e.title}
                                    sub={dateLabel(e.start ?? '')}
                                    value={e.ref ?? 'Details restricted'}
                                    action={() => onNav('calendar')}
                                />
                            ))}
                        {buttons(
                            <Button
                                variant="outline"
                                onClick={() => onNav('calendar')}
                            >
                                Open vehicle calendar
                            </Button>,
                        )}
                    </Panel>
                </div>
            </div>
        );
    if (view === 'overview' && sub === 'details')
        return (
            <div className="content-grid">
                <div className="stack">
                    <Panel
                        title="Vehicle details"
                        action={manage('Edit vehicle details', m.profile)}
                    >
                        {[
                            ['Registration / asset', 'KWH014 · VH-014'],
                            ['Make / model', 'Toyota Hiace'],
                            ['Home site', 'Kōwhai House'],
                            ['Category', d.profile.category],
                            ['VIN / chassis', d.profile.vin],
                            ['Responsible role', d.profile.owner],
                            ['Lifecycle', d.profile.life],
                        ].map(([title, value]) => (
                            <Row key={title} title={title} value={value} />
                        ))}
                    </Panel>
                    <Panel title="Ownership & cover">
                        {[
                            ['Insurance', d.profile.insurance],
                            ['Warranty', d.profile.warranty],
                            ['Ownership / lease', d.profile.lease],
                        ].map(([title, value]) => (
                            <Row
                                key={title}
                                title={title}
                                value={value}
                                action={() => doc(value)}
                            />
                        ))}
                    </Panel>
                </div>
                <div className="stack">
                    <Panel
                        title="Photos & documents"
                        action={manage('Add photos / documents', () =>
                            m.setUploadFor('VH-014'),
                        )}
                        sub="Synthetic records · no real vehicle photos supplied"
                    >
                        <div className="vehicle-tile">
                            <Car size={52} />
                            <div>
                                <strong>Vehicle photo not supplied</strong>
                                <span>Add through the evidence workflow</span>
                            </div>
                        </div>
                        {d.documents
                            .filter((x) => x.owner === 'VH-014')
                            .map((x) => (
                                <Row
                                    key={x.id}
                                    title={x.name}
                                    value={x.id}
                                    action={() => doc(`${x.id} · ${x.name}`)}
                                />
                            ))}
                        <Row
                            title="Registration document"
                            value="EV-REG-14"
                            action={() => doc('EV-REG-14')}
                        />
                        <Row
                            title="Vehicle record pack"
                            value="DOC-DEMO-14"
                            action={() => doc('DOC-DEMO-14')}
                        />
                    </Panel>
                    <Panel title="Lifecycle history">
                        {d.profile.history.map((x, i) => (
                            <p className="op-log" key={i}>
                                {x}
                            </p>
                        ))}
                    </Panel>
                </div>
            </div>
        );
    if (view === 'compliance' && sub === 'schedules')
        return (
            <div className="stack">
                <Panel
                    title="Service schedules"
                    sub="Vehicle and component requirements · earlier applicable trigger wins"
                    action={manage('Add service schedule', () => m.schedule())}
                >
                    {!d.schedules.length ? (
                        <div className="empty">
                            <Wrench />
                            <strong>No service schedule recorded</strong>
                            <p>
                                Record the approved service requirement, next
                                triggers and responsible role.
                            </p>
                            {manage('Set up service schedule', () =>
                                m.schedule(),
                            )}
                        </div>
                    ) : (
                        d.schedules.map((s) => (
                            <div className="op-record" key={s.id}>
                                <Row
                                    title={s.name}
                                    sub={`${s.id} · ${s.owner}`}
                                    value={serviceStatus(s, m.odo).label}
                                    badge={
                                        <Badge
                                            tone={
                                                serviceStatus(s, m.odo).overdue
                                                    ? 'critical'
                                                    : 'info'
                                            }
                                        >
                                            {serviceStatus(s, m.odo).overdue
                                                ? 'Overdue'
                                                : 'Scheduled'}
                                        </Badge>
                                    }
                                />
                                <div className="op-meta">
                                    <span>
                                        Interval:{' '}
                                        {[
                                            s.months
                                                ? `${s.months} months`
                                                : null,
                                            s.distance ? km(s.distance) : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' or ')}
                                    </span>
                                    <span>
                                        Last completed: {dateLabel(s.last)}
                                        {s.lastKm ? ` · ${km(s.lastKm)}` : ''}
                                    </span>
                                    <span>
                                        Reminder: {s.lead} days
                                        {s.leadKm
                                            ? ` / ${km(s.leadKm)}`
                                            : ''}{' '}
                                        before due · example policy
                                    </span>
                                </div>
                                {buttons(
                                    <>
                                        {manage('Plan service', () =>
                                            m.plan(s.id, true),
                                        )}
                                        {manage('Manage schedule', () =>
                                            m.schedule(s.id),
                                        )}
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                onNav('compliance', 'reminders')
                                            }
                                        >
                                            Reminder history
                                        </Button>
                                    </>,
                                )}
                            </div>
                        ))
                    )}
                </Panel>
                <Notice title="Reading provenance">
                    {m.odo
                        ? `${km(m.odo)} · ${d.readings[0].source} · ${localDateTimeLabel(d.readings[0].at)}. ${m.readingStale ? 'Stale evidence: remaining distance is provisional; record a current observation.' : ''}`
                        : 'No odometer reading available. Record mileage before assessing distance-based service.'}
                </Notice>
            </div>
        );
    if (view === 'compliance' && sub === 'reminders')
        return (
            <div className="stack">
                <Panel
                    title="Reminders & follow-up"
                    sub="Existing Tasks and notifications own delivery · acknowledgement never resolves the obligation"
                >
                    {[
                        ...d.schedules.map((s) => ({
                            id: s.id,
                            name: s.name,
                            due: s.due,
                            owner: s.owner,
                            next: s.due ? addDays(s.due, -s.lead) : '',
                            basis: `${s.lead} days / ${s.leadKm} km before due · example`,
                        })),
                        ...d.compliance
                            .filter((c) => c.applies === 'Applicable')
                            .map((c) => ({
                                id: c.id,
                                name: c.name,
                                due: c.due,
                                owner: d.profile.owner,
                                next: c.due ? addDays(c.due, -7) : '',
                                basis: '7 days before due · example configuration',
                            })),
                    ].map((r) => (
                        <div className="op-record" key={r.id}>
                            <Row
                                title={r.name}
                                sub={`${r.id} · ${r.owner} · backup: Operations Manager`}
                                value={
                                    r.due
                                        ? `Due ${dateLabel(r.due)}`
                                        : 'Evidence / distance review needed'
                                }
                                badge={
                                    <Badge
                                        tone={
                                            d.reminders[r.id]?.status ===
                                            'Delivery failed'
                                                ? 'critical'
                                                : 'info'
                                        }
                                    >
                                        {d.reminders[r.id]?.status ??
                                            'Scheduled'}
                                    </Badge>
                                }
                            />
                            <div className="op-meta">
                                <span>
                                    Next cycle:{' '}
                                    {r.next && r.next >= TODAY
                                        ? dateLabel(r.next)
                                        : 'Due now · follow-up required'}
                                </span>
                                <span>{r.basis}</span>
                                <span>
                                    Channel:{' '}
                                    {d.reminders[r.id]?.channel ??
                                        'In-app task'}
                                </span>
                            </div>
                            {buttons(
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            m.reminder(r.id, 'detail')
                                        }
                                    >
                                        Delivery history
                                    </Button>
                                    {manage(
                                        d.reminders[r.id]?.status ===
                                            'Delivery failed'
                                            ? 'Retry delivery'
                                            : 'Acknowledge',
                                        () =>
                                            m.reminder(
                                                r.id,
                                                d.reminders[r.id]?.status ===
                                                    'Delivery failed'
                                                    ? 'retry'
                                                    : 'ack',
                                            ),
                                    )}
                                    {manage('Resolve obligation', () =>
                                        d.schedules.some((s) => s.id === r.id)
                                            ? m.plan(r.id, true)
                                            : m.compliance(r.id),
                                    )}
                                </>,
                            )}
                        </div>
                    ))}
                </Panel>
                <Notice title="Policy ownership">
                    Lead times and distance thresholds here are labelled
                    examples. Schedule reminder settings are edited with the
                    schedule. Delivery failure stays visible until retried;
                    acknowledgements preserve the underlying due item.
                </Notice>
            </div>
        );
    if (view === 'compliance' && sub === 'mileage')
        return (
            <Panel
                title="Mileage history"
                sub="Original observations are retained; corrections add attributable records"
                action={manage('Record mileage', () => m.mileage())}
            >
                {d.readings.length ? (
                    d.readings.map((r, i) => (
                        <div className="op-record" key={r.id}>
                            <Row
                                title={
                                    i === 0
                                        ? 'Latest selected reading'
                                        : 'Previous recorded reading'
                                }
                                sub={`${r.id} · ${localDateTimeLabel(r.at)} · ${r.author}`}
                                value={km(r.value)}
                                action={() =>
                                    m.detail('Mileage source', [
                                        ['Reference', r.id],
                                        ['Observed', localDateTimeLabel(r.at)],
                                        ['Source', r.source],
                                        ['Author', r.author],
                                        ['Reading', km(r.value)],
                                        [
                                            'Correction / notes',
                                            r.reason || 'Original observation',
                                        ],
                                    ])
                                }
                            />
                            <p className="muted">
                                {r.source}
                                {r.reason ? ` · ${r.reason}` : ''}
                            </p>
                            {i === 0 &&
                                buttons(
                                    manage('Correct reading', () =>
                                        m.mileage(r),
                                    ),
                                )}
                        </div>
                    ))
                ) : (
                    <p>
                        No readings recorded. Manual observations are supported
                        without a tracker.
                    </p>
                )}
            </Panel>
        );
    if (view === 'compliance' && sub === 'history')
        return <WorkList model={m} history onWork={onWork} />;
    if (view === 'compliance')
        return (
            <div className="stack">
                <Panel
                    title="Service & compliance"
                    sub="Recorded evidence and accountable next actions"
                    action={
                        <Button
                            onClick={() => onNav('compliance', 'schedules')}
                        >
                            Service schedules
                        </Button>
                    }
                >
                    {s && (
                        <div className="op-record">
                            <Row
                                title="Next service"
                                sub={`${s.id} · ${s.owner}`}
                                value={serviceStatus(s, m.odo).label}
                            />
                            {buttons(
                                <>
                                    {manage('Plan service', () =>
                                        m.plan(s.id, true),
                                    )}
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            onNav('compliance', 'reminders')
                                        }
                                    >
                                        Reminders & follow-up
                                    </Button>
                                </>,
                            )}
                        </div>
                    )}
                    {d.compliance.map((c) => (
                        <div className="op-record" key={c.id}>
                            <Row
                                title={c.name}
                                sub={`${c.id} · ${c.applies} · Owner: ${d.profile.owner}`}
                                value={
                                    c.name === 'RUC' && c.high
                                        ? `${km(c.low)}–${km(c.high)} · ${!m.odo ? 'Reading required' : m.odo > c.high ? `Exceeds recorded licence by ${km(m.odo - c.high)}` : `${km(c.high - m.odo)} remaining`}${m.readingStale ? ' · stale reading; recheck distance' : ''}`
                                        : c.due
                                          ? dateLabel(c.due)
                                          : c.applies === 'Not applicable'
                                            ? 'Basis recorded'
                                            : 'Evidence / applicability needed'
                                }
                                badge={
                                    <Badge
                                        tone={
                                            c.outcome === 'Failed'
                                                ? 'critical'
                                                : c.evidence
                                                  ? 'success'
                                                  : 'warning'
                                        }
                                    >
                                        {c.outcome}
                                    </Badge>
                                }
                            />
                            <p className="muted">
                                {c.basis ||
                                    'Vehicle-specific assessment not recorded.'}
                            </p>
                            {buttons(
                                <>
                                    {manage(
                                        c.name === 'RUC'
                                            ? 'Record renewal / evidence'
                                            : 'Update evidence',
                                        () => m.compliance(c.id),
                                    )}
                                    {manage('Plan appointment', () =>
                                        m.plan(c.id),
                                    )}
                                    <Button
                                        variant="ghost"
                                        onClick={() => doc(c.evidence || c.id)}
                                    >
                                        Source record
                                    </Button>
                                </>,
                            )}
                        </div>
                    ))}
                </Panel>
            </div>
        );
    if (view === 'maintenance')
        return (
            <WorkList model={m} history={sub === 'history'} onWork={onWork} />
        );
    return null;
}
function WorkList({
    model: m,
    history,
    onWork,
}: {
    model: VehicleModel;
    history: boolean;
    onWork: (id: string) => void;
}) {
    const [filter, setFilter] = useState('');
    const list = m.data.works.filter(
        (w) =>
            ['Completed', 'Cancelled', 'Completed · awaiting release'].includes(
                w.status,
            ) === history &&
            `${w.id} ${w.title} ${w.provider} ${w.source}`
                .toLowerCase()
                .includes(filter.toLowerCase()),
    );
    return (
        <Panel
            title={history ? 'Service & work history' : 'Open maintenance work'}
            sub="Source links, provider plans, outcomes and release remain distinct"
        >
            <Input
                aria-label="Filter work records"
                placeholder="Find reference, service type or provider…"
                value={filter}
                onChange={(e) => setFilter(e.target.value)}
                className="mb-4"
            />
            {list.length ? (
                list.map((w) => (
                    <button
                        className="record-row"
                        key={w.id}
                        onClick={() => onWork(w.id)}
                    >
                        <span className="record-icon">
                            <Wrench />
                        </span>
                        <span className="record-main">
                            <strong>{w.title}</strong>
                            <small>
                                {w.id} · {w.source} · {w.owner}
                            </small>
                        </span>
                        <Badge
                            tone={
                                w.outcome === 'Failed'
                                    ? 'critical'
                                    : w.status.startsWith('Completed')
                                      ? 'success'
                                      : 'info'
                            }
                        >
                            {w.status}
                        </Badge>
                        <ArrowRight size={16} />
                    </button>
                ))
            ) : (
                <div className="empty">
                    <Wrench />
                    <strong>No matching work records</strong>
                </div>
            )}
        </Panel>
    );
}
export function WorkRecord({
    model: m,
    id,
    onBack,
    onCheck,
}: {
    model: VehicleModel;
    id: string;
    onBack: () => void;
    onCheck: (id: string) => void;
}) {
    const w = m.data.works.find((w) => w.id === id);
    if (!w)
        return (
            <Panel title="Reported concern">
                <Notice title="Report captured">
                    The original report is retained in the report walkthrough.
                    Open an existing work record to review its plan and outcome.
                </Notice>
                <Button onClick={onBack}>Back to vehicle</Button>
            </Panel>
        );
    return (
        <>
            <div className="subnav-return">
                <Button variant="ghost" onClick={onBack}>
                    ← Back to vehicle profile
                </Button>
                <span className="muted">Maintenance · {w.id}</span>
            </div>
            <div className="content-grid work-grid">
                <div className="stack">
                    <Panel
                        title={w.title}
                        sub={`${w.id} · Source ${w.source}`}
                        action={
                            <Badge
                                tone={
                                    w.outcome === 'Failed' ? 'critical' : 'info'
                                }
                            >
                                {w.status}
                            </Badge>
                        }
                    >
                        <Row
                            title="Owner / next action"
                            value={`${w.owner} · ${w.status === 'Repair / retest required' ? 'Arrange repair and retest' : w.status.startsWith('Completed') ? 'Review independent release' : 'Assess, plan and record outcome'}`}
                        />
                        <Row title="Target" value={dateLabel(w.target)} />
                        <Row
                            title="Original source"
                            value={w.source}
                            action={() =>
                                w.source.startsWith('CHK-')
                                    ? onCheck(w.source)
                                    : m.detail('Source record', [
                                          ['Reference', w.source],
                                          ['Vehicle', 'VH-014'],
                                          ['Work relationship', w.id],
                                          [
                                              'Recorded evidence',
                                              m.data.compliance.find(
                                                  (c) => c.id === w.source,
                                              )?.evidence ||
                                                  w.evidence ||
                                                  'See owning schedule',
                                          ],
                                          [
                                              'Next due',
                                              dateLabel(
                                                  m.data.schedules.find(
                                                      (s) => s.id === w.source,
                                                  )?.due ||
                                                      m.data.compliance.find(
                                                          (c) =>
                                                              c.id === w.source,
                                                      )?.due ||
                                                      '',
                                              ),
                                          ],
                                          [
                                              'History',
                                              'Original source retained; later records are linked',
                                          ],
                                      ])
                            }
                        />
                        {buttons(
                            <>
                                {m.data.compliance.some(
                                    (c) => c.id === w.source,
                                ) && (
                                    <Button
                                        variant="outline"
                                        disabled={!m.canManage}
                                        onClick={() => m.compliance(w.source)}
                                    >
                                        Update source evidence
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    disabled={!m.canManage}
                                    onClick={() => m.assess(id)}
                                >
                                    Assess work & costs
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={
                                        !m.canManage || w.status === 'Cancelled'
                                    }
                                    onClick={() => m.plan(id)}
                                >
                                    Manage appointment
                                </Button>
                            </>,
                        )}
                        {w.notes && <p className="body-copy">{w.notes}</p>}
                    </Panel>
                    <Panel
                        title="Provider appointment"
                        sub="Internal planning and provider confirmation are separate"
                    >
                        <Row
                            title="Provider"
                            value={w.provider || 'Not selected'}
                        />
                        <Row
                            title="Appointment"
                            value={
                                w.start
                                    ? `${localDateTimeLabel(w.start)} → ${localDateTimeLabel(w.end)}`
                                    : 'Not planned'
                            }
                        />
                        <Row
                            title="Provider confirmation"
                            value={
                                w.confirmed ||
                                'Not recorded · internal plan only'
                            }
                        />
                        <Row
                            title="Vehicle unavailable"
                            value={
                                w.unavailable
                                    ? 'For recorded appointment interval'
                                    : 'No actual unavailable interval recorded'
                            }
                        />
                        <Row
                            title="Affected bookings"
                            value={
                                w.start < '2026-09-25T12:00' &&
                                w.end > '2026-09-25T10:00'
                                    ? 'Private booking · 25 Sep 10:00–12:00 · coordinator follow-up'
                                    : 'No overlap with the private example booking'
                            }
                        />
                        {m.data.bookings
                            .filter(
                                (b) =>
                                    w.start < b.end &&
                                    w.end > b.start &&
                                    b.status !== 'Cancelled',
                            )
                            .map((b) => (
                                <Row
                                    key={b.id}
                                    title={b.id}
                                    value={`${b.status} · arrange an alternative`}
                                />
                            ))}
                    </Panel>
                    <Panel
                        title="Outcome & evidence"
                        action={
                            <Button
                                variant="outline"
                                disabled={!m.canManage}
                                onClick={() => m.setUploadFor(id)}
                            >
                                Add evidence
                            </Button>
                        }
                    >
                        {m.data.documents
                            .filter((x) => x.owner === id)
                            .map((x) => (
                                <Row
                                    key={x.id}
                                    title={x.name}
                                    value={x.id}
                                    action={() =>
                                        m.detail('Attached evidence', [
                                            ['Reference', x.id],
                                            ['File', x.name],
                                            ['Owning work', id],
                                            [
                                                'Author',
                                                'Alex Morgan · preview upload',
                                            ],
                                        ])
                                    }
                                />
                            ))}
                        <Row
                            title="Outcome"
                            value={w.outcome || 'Not recorded'}
                        />
                        <Row
                            title="Actual completion"
                            value={
                                w.completed
                                    ? `${dateLabel(w.completed)} · ${km(w.odo)}`
                                    : 'Not recorded'
                            }
                        />
                        <Row
                            title="Evidence"
                            value={w.evidence || 'Required for completion'}
                            action={
                                w.evidence
                                    ? () =>
                                          m.detail('Completion evidence', [
                                              ['Reference', w.evidence],
                                              ['Work', w.id],
                                              ['Outcome', w.outcome],
                                              [
                                                  'Recorded',
                                                  dateLabel(w.completed),
                                              ],
                                              [
                                                  'Work performed',
                                                  w.notes ||
                                                      'See source report',
                                              ],
                                          ])
                                    : undefined
                            }
                        />
                        {buttons(
                            <>
                                <Button
                                    disabled={
                                        !m.canManage ||
                                        [
                                            'Cancelled',
                                            'Completed',
                                            'Completed · awaiting release',
                                        ].includes(w.status)
                                    }
                                    onClick={() => m.complete(id)}
                                >
                                    {['Failed', 'Needs retest'].includes(
                                        w.outcome,
                                    )
                                        ? 'Record repair / retest outcome'
                                        : 'Record work outcome'}
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={!m.canManage}
                                    onClick={m.release}
                                >
                                    Review release
                                </Button>
                            </>,
                        )}
                    </Panel>
                </div>
                <div className="stack">
                    <Panel title="Costs & Finance context">
                        <Row
                            title="Quote / estimate"
                            value={w.quote || 'Not recorded'}
                        />
                        <Row
                            title="Approval reference"
                            value={w.approval || 'No approval recorded'}
                            action={
                                w.approval
                                    ? () =>
                                          m.detail('Finance reference', [
                                              ['Reference', w.approval],
                                              ['Work', w.id],
                                              [
                                                  'Approval ownership',
                                                  'Finance · this profile cannot approve spend or an invoice',
                                              ],
                                              [
                                                  'Parts',
                                                  w.parts || 'Not recorded',
                                              ],
                                              [
                                                  'Labour',
                                                  w.labour || 'Not recorded',
                                              ],
                                          ])
                                    : undefined
                            }
                        />
                        <Row title="Parts" value={w.parts || 'Not recorded'} />
                        <Row
                            title="Labour"
                            value={w.labour || 'Not recorded'}
                        />
                    </Panel>
                    <Panel title="Reporter feedback">
                        <p>
                            {w.feedback ||
                                'No feedback recorded. Completion asks for the outcome and next action for the reporter.'}
                        </p>
                        <small className="muted">
                            Recorded here only · no message has been sent
                        </small>
                    </Panel>
                    <Panel title="Work history">
                        {w.history.map((x, i) => (
                            <p className="op-log" key={i}>
                                {x}
                            </p>
                        ))}
                    </Panel>
                    <Notice
                        title={
                            m.hold
                                ? 'Restriction remains active'
                                : 'Release and custody are separate'
                        }
                        tone={m.hold ? 'critical' : 'info'}
                    >
                        Work completion, provider confirmation and an
                        appointment ending do not release the vehicle or approve
                        Finance records.
                    </Notice>
                </div>
            </div>
        </>
    );
}
export function BookingPanel({ model: m }: { model: VehicleModel }) {
    return (
        <Panel
            title="Vehicle bookings & custody"
            sub="Exact vehicle · Kōwhai House · permitted booking details only"
            action={buttons(
                <>
                    <Button
                        disabled={!m.canRequest}
                        onClick={() => m.booking()}
                    >
                        Request booking
                    </Button>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={() => m.booking(undefined, undefined, true)}
                    >
                        Add unavailable period
                    </Button>
                </>,
            )}
        >
            {m.data.bookings.length ? (
                m.data.bookings.map((b) => (
                    <div className="op-record" key={b.id}>
                        <Row
                            title={b.purpose}
                            sub={`${b.id} · ${localDateTimeLabel(b.start)} → ${localDateTimeLabel(b.end)}`}
                            value={
                                b.block
                                    ? 'Manual unavailable period'
                                    : `${b.requester} · driver ${b.driver}`
                            }
                            badge={
                                <Badge
                                    tone={
                                        b.status === 'Checked out' &&
                                        b.end < '2026-09-22T09:30'
                                            ? 'critical'
                                            : 'info'
                                    }
                                >
                                    {b.status === 'Checked out' &&
                                    b.end < '2026-09-22T09:30'
                                        ? 'Overdue return · follow up'
                                        : b.status}
                                </Badge>
                            }
                        />
                        <p className="muted">
                            {b.pickup} {b.outKm ? ` · Out ${km(b.outKm)}` : ''}{' '}
                            {b.returnKm ? ` · Returned ${km(b.returnKm)}` : ''}
                        </p>
                        {buttons(
                            <>
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        m.detail('Booking record', [
                                            ['Reference', b.id],
                                            ['History', b.history.join('\n')],
                                            [
                                                'Condition',
                                                b.condition ||
                                                    'No custody observation yet',
                                            ],
                                            [
                                                'Ownership',
                                                'Vehicle bookings · busy-only records remain private',
                                            ],
                                        ])
                                    }
                                >
                                    History
                                </Button>
                                {![
                                    'Cancelled',
                                    'Returned',
                                    'Declined',
                                ].includes(b.status) && (
                                    <>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                !m.canManage ||
                                                b.status === 'Checked out'
                                            }
                                            onClick={() =>
                                                m.booking(
                                                    b.start,
                                                    b.id,
                                                    b.block,
                                                )
                                            }
                                        >
                                            Change times
                                        </Button>
                                        {!b.block && (
                                            <Button
                                                disabled={!m.canManage}
                                                onClick={() =>
                                                    m.bookingTransition(
                                                        b.id,
                                                        b.status ===
                                                            'Pending approval'
                                                            ? 'approve'
                                                            : b.status ===
                                                                'Confirmed'
                                                              ? 'out'
                                                              : 'return',
                                                    )
                                                }
                                            >
                                                {b.status === 'Pending approval'
                                                    ? 'Review & approve'
                                                    : b.status === 'Confirmed'
                                                      ? 'Check out'
                                                      : 'Record return'}
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            disabled={
                                                !m.canManage ||
                                                b.status === 'Checked out'
                                            }
                                            onClick={() =>
                                                m.bookingTransition(
                                                    b.id,
                                                    'cancel',
                                                )
                                            }
                                        >
                                            Cancel{' '}
                                            {b.block ? 'block' : 'booking'}
                                        </Button>
                                    </>
                                )}
                            </>,
                        )}
                    </div>
                ))
            ) : (
                <p>
                    No permitted booking requests created yet. Use Request
                    booking or a free calendar slot.
                </p>
            )}
            <Notice title="Private booking · busy only">
                25 Sep, 10:00 am–12:00 pm. Requester, purpose and destination
                are not exposed.
            </Notice>
        </Panel>
    );
}
export function CheckRequirements({
    model: m,
    onStart,
}: {
    model: VehicleModel;
    onStart: () => void;
}) {
    return (
        <Panel
            title="Current check requirement"
            sub="Fictional template DEMO-3 · original versions are preserved"
        >
            <Row
                title="Next check"
                value={dateLabel(m.data.checkDue)}
                sub={`Owner: ${m.data.checkOwner}`}
                badge={
                    <Badge tone={m.data.checkDue < TODAY ? 'critical' : 'info'}>
                        {m.data.checkDue < TODAY ? 'Overdue' : 'Scheduled'}
                    </Badge>
                }
            />
            {buttons(
                <>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={m.checkPlan}
                    >
                        Manage requirement
                    </Button>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={() => m.amendment()}
                    >
                        Add amendment
                    </Button>
                    <Button disabled={!m.canRequest} onClick={onStart}>
                        Start retest
                    </Button>
                </>,
            )}
            {m.data.amendments.map((a, i) => (
                <p className="op-log" key={i}>
                    {a}
                </p>
            ))}
        </Panel>
    );
}

export function OperationDialog({ model: m }: { model: VehicleModel }) {
    return (
        <>
            {m.photoOpen && (
                <PhotoDialog
                    current={m.data.photo}
                    onSave={m.setPhoto}
                    onClose={() => m.setPhotoOpen(false)}
                />
            )}
            {m.uploadFor && (
                <UploadFlow
                    work={m.uploadFor}
                    fail={m.fault === 'upload'}
                    onSaved={m.attach}
                    onClose={() => m.setUploadFor('')}
                />
            )}
            {m.action && (
                <ActionWizard
                    key={m.action.title}
                    action={m.action}
                    canCreateCatalog={m.canManage}
                    onClose={() => m.setAction(null)}
                    fail={m.fault === 'save'}
                />
            )}{' '}
            {m.financeRecord && m.canViewFinance && (
                <FinanceRecordDialog
                    model={m}
                    id={m.financeRecord}
                    onClose={() => m.setFinanceRecord('')}
                />
            )}
            {m.record && (
                <Modal
                    title={m.record.title}
                    description="VH-014 · Synthetic source record"
                    onClose={() => m.setRecord(null)}
                >
                    {m.record.rows.map(([k, v], i) => (
                        <Row
                            key={i}
                            title={k}
                            value={
                                <span className="whitespace-pre-line">{v}</span>
                            }
                        />
                    ))}
                </Modal>
            )}
        </>
    );
}
function ActionWizard({
    canCreateCatalog,
    action: a,
    onClose,
    fail,
}: {
    action: Action;
    canCreateCatalog: boolean;
    onClose: () => void;
    fail: boolean;
}) {
    const [values, setValues] = useState(a.values),
        [step, setStep] = useState(0),
        [error, setError] = useState(''),
        [saved, setSaved] = useState(false),
        [discard, setDiscard] = useState(false),
        [attempt, setAttempt] = useState(0),
        [saving, setSaving] = useState(false);
    const fields = a.sections.flatMap((s) => s.fields);
    const dirty = JSON.stringify(values) !== JSON.stringify(a.values);
    const close = () => {
        if (!saving) {
            if (dirty && !saved) setDiscard(true);
            else onClose();
        }
    };
    const validate = (all: boolean) => {
        const scoped = all ? fields : (a.sections[step]?.fields ?? []);
        const bad = scoped.find(
            (f) =>
                (f.required &&
                    (f.type === 'check'
                        ? values[f.key] !== 'true'
                        : f.type === 'files'
                          ? readFiles(values[f.key]).length === 0
                          : !values[f.key]?.trim())) ||
                (f.type === 'number' &&
                    values[f.key] &&
                    (Number.isNaN(+values[f.key]) || +values[f.key] < 0)) ||
                (f.type === 'datetime' &&
                    values[f.key] &&
                    !validLocalDateTime(values[f.key])),
        );
        if (bad) {
            setStep(a.sections.findIndex((s) => s.fields.includes(bad)));
            setError(`Review ${bad.label.toLowerCase()}.`);
            return false;
        }
        const e = all ? a.validate?.(values) : '';
        if (e) {
            setError(e);
            setStep(a.validationSection?.(values) ?? 0);
            return false;
        }
        setError('');
        return true;
    };
    const submit = () => {
        if (saving || saved || !validate(true)) return;
        setSaving(true);
        setTimeout(() => {
            setSaving(false);
            setAttempt((n) => n + 1);
            if (fail && attempt === 0) {
                setError(
                    'Save interrupted. Your draft is kept; retry this same submission.',
                );
                return;
            }
            a.save(values);
            setSaved(true);
        }, 350);
    };
    return (
        <>
            <WizardShell
                open
                title={a.title}
                description="Kōwhai van · VH-014 · Synthetic walkthrough"
                maxWidth="min(92vw, 1100px)"
                railIcon={Wrench}
                railTitle={a.title}
                railSub="KWH014 · Kōwhai House"
                steps={[
                    ...a.sections.map((s, i) => ({
                        key: String(i),
                        label: s.title,
                        blurb: s.description.split('.')[0],
                        icon: i ? CalendarDays : Wrench,
                    })),
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Confirm the resulting record',
                        icon: FileCheck2,
                    },
                ]}
                stepIndex={step}
                onStepClick={(i) => {
                    if (!saving) {
                        setStep(i);
                        setError('');
                    }
                }}
                pct={Math.round(
                    ((fields
                        .filter((f) => f.required)
                        .filter((f) =>
                            f.type === 'check'
                                ? values[f.key] === 'true'
                                : f.type === 'files'
                                  ? readFiles(values[f.key]).length > 0
                                  : !!values[f.key]?.trim(),
                        ).length +
                        (a.extraCompletion?.(values) ?? []).filter(Boolean)
                            .length) /
                        Math.max(
                            1,
                            fields.filter((f) => f.required).length +
                                (a.extraCompletion?.(values) ?? []).length,
                        )) *
                        100,
                )}
                onClose={close}
                footerStart={
                    <Button variant="outline" onClick={close} disabled={saving}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                                disabled={saving}
                            >
                                Back
                            </Button>
                        )}
                        <Button
                            disabled={saving}
                            onClick={() =>
                                step === a.sections.length
                                    ? submit()
                                    : validate(false) && setStep(step + 1)
                            }
                        >
                            {saving
                                ? 'Saving…'
                                : step === a.sections.length
                                  ? a.verb
                                  : 'Continue'}
                        </Button>
                    </>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Recorded in this preview"
                            blurb={
                                a.success ??
                                'Related profile records, reminders and calendar context now use this update. Reloading resets the synthetic data.'
                            }
                            actions={
                                <Button onClick={onClose}>
                                    Back to vehicle
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="flow-stack">
                        <div className="locked">
                            <strong>Kōwhai van</strong>
                            <span>VH-014 · KWH014 · Kōwhai House</span>
                        </div>
                        {error && (
                            <Notice
                                title="Review before continuing"
                                tone="critical"
                            >
                                {error}
                            </Notice>
                        )}
                        {error.startsWith('Conflicts') && a.alternatives && (
                            <div
                                className="op-actions"
                                aria-label="Alternative booking times"
                            >
                                {a.alternatives.map((v) => (
                                    <Button
                                        key={v.start}
                                        variant="outline"
                                        onClick={() => {
                                            setValues((x) => ({ ...x, ...v }));
                                            setError('');
                                            setStep(0);
                                        }}
                                    >
                                        Try {localDateTimeLabel(v.start)}
                                    </Button>
                                ))}
                            </div>
                        )}
                        {step < a.sections.length ? (
                            <>
                                <h2 className="text-section-title">
                                    {a.sections[step].title}
                                </h2>
                                <p className="muted">
                                    {a.sections[step].description}
                                </p>
                                <div className="op-fields">
                                    {a.sections[step].fields.map((field) => (
                                        <OperationField
                                            key={field.key}
                                            field={field}
                                            canCreateCatalog={canCreateCatalog}
                                            value={values[field.key] ?? ''}
                                            onChange={(v) =>
                                                setValues((x) =>
                                                    a.change
                                                        ? a.change(
                                                              field.key,
                                                              v,
                                                              x,
                                                          )
                                                        : {
                                                              ...x,
                                                              [field.key]: v,
                                                          },
                                                )
                                            }
                                        />
                                    ))}
                                </div>
                            </>
                        ) : (
                            <>
                                {a.sections.map((s, i) => (
                                    <ReviewCard
                                        icon={FileText}
                                        key={s.title}
                                        title={s.title}
                                        onEdit={() => setStep(i)}
                                    >
                                        {s.fields.map((f) => (
                                            <ReviewRow
                                                key={f.key}
                                                label={f.label}
                                                value={
                                                    f.type === 'record'
                                                        ? f.records?.find(
                                                              (r) =>
                                                                  r.id ===
                                                                  values[f.key],
                                                          )?.name ||
                                                          values[f.key] ||
                                                          'Not provided'
                                                        : f.type === 'files'
                                                          ? readFiles(
                                                                values[f.key],
                                                            )
                                                                .map(
                                                                    (file) =>
                                                                        file.name,
                                                                )
                                                                .join(', ') ||
                                                            'No files attached'
                                                          : f.type ===
                                                              'datetime'
                                                            ? localDateTimeLabel(
                                                                  values[f.key],
                                                              )
                                                            : f.type === 'date'
                                                              ? dateLabel(
                                                                    values[
                                                                        f.key
                                                                    ],
                                                                )
                                                              : f.type ===
                                                                  'check'
                                                                ? values[
                                                                      f.key
                                                                  ] === 'true'
                                                                    ? 'Reviewed / selected'
                                                                    : 'Not selected'
                                                                : values[
                                                                      f.key
                                                                  ] ||
                                                                  'Not provided'
                                                }
                                            />
                                        ))}
                                    </ReviewCard>
                                ))}
                                {a.note && (
                                    <Notice title="What this changes">
                                        {a.note}
                                    </Notice>
                                )}
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {discard && (
                <Modal
                    title="Discard this draft?"
                    description="Unsent changes will be removed."
                    onClose={() => setDiscard(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button variant="destructive" onClick={onClose}>
                                Discard draft
                            </Button>
                        </>
                    }
                >
                    <p>Saved records are kept in this preview session.</p>
                </Modal>
            )}
        </>
    );
}
function OperationField({
    canCreateCatalog,
    field: definition,
    value,
    onChange,
}: {
    field: Field;
    canCreateCatalog: boolean;
    value: string;
    onChange: (v: string) => void;
}) {
    const { templates } = useChecklists();
    const f = catalogField(definition);
    const id = `op-${f.key}`;
    return f.type === 'record' ? (
        <Picker
            label={f.label}
            value={value}
            onChange={onChange}
            options={f.records || []}
        />
    ) : f.type === 'checklist' ? (
        <Picker
            label={f.label}
            value={value}
            onChange={onChange}
            options={templates.map((t) => ({
                id: t.id,
                name: t.name,
                detail: t.version + ' · ' + t.scope,
            }))}
        />
    ) : f.type === 'catalog' || f.type === 'catalog-number' ? (
        <CatalogPicker
            label={f.label + (!f.required ? ' (optional)' : '')}
            value={value}
            onChange={onChange}
            options={f.options || []}
            numeric={f.type === 'catalog-number'}
            allowCreate={canCreateCatalog}
            unit={
                f.key === 'months' || f.key === 'repeat'
                    ? 'months'
                    : f.key === 'distance'
                      ? 'km'
                      : ''
            }
        />
    ) : f.type === 'files' ? (
        <div className="op-full">
            <EvidenceField label={f.label} value={value} onChange={onChange} />
        </div>
    ) : f.type === 'approval' ? (
        <fieldset className="approval-choice op-full">
            <legend>{f.label}</legend>
            {['Approval required', 'Approval not required'].map((option) => (
                <label
                    key={option}
                    className={value === option ? 'selected' : ''}
                >
                    <input
                        type="checkbox"
                        checked={value === option}
                        onChange={() => onChange(option)}
                    />
                    <span>
                        <strong>{option}</strong>
                        <small>
                            {option === 'Approval required'
                                ? 'Coordinator reviews before confirmation'
                                : 'Record the authority, reason or supporting evidence'}
                        </small>
                    </span>
                </label>
            ))}
        </fieldset>
    ) : f.type === 'datetime' ? (
        <DateTimeField
            id={id}
            label={f.label}
            value={value}
            onChange={onChange}
            hint={f.hint}
        />
    ) : f.type === 'person' || f.type === 'provider' ? (
        <Picker
            label={f.label}
            value={value}
            onChange={onChange}
            options={(f.type === 'person'
                ? ownerOptions
                : [
                      'Harbour Workshop · DEMO',
                      'City Inspection Centre · DEMO',
                      'Access Lift Services · DEMO',
                  ]
            ).map((name) => ({
                id: name,
                name,
                detail: 'Approved-site synthetic record',
            }))}
        />
    ) : (
        <div className="field">
            <label htmlFor={id}>
                {f.label}
                {!f.required && f.type !== 'check' ? ' (optional)' : ''}
            </label>
            {f.type === 'date' ? (
                <DatePicker
                    id={id}
                    label={f.label}
                    value={value}
                    onChange={onChange}
                />
            ) : f.type === 'textarea' ? (
                <Textarea
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            ) : f.type === 'select' ? (
                <select
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                >
                    <option value="">Choose…</option>
                    {f.options?.map((o) => (
                        <option key={o}>{o}</option>
                    ))}
                </select>
            ) : f.type === 'check' ? (
                <label className="op-check">
                    <input
                        id={id}
                        type="checkbox"
                        checked={value === 'true'}
                        onChange={(e) => onChange(String(e.target.checked))}
                    />
                    Confirm
                </label>
            ) : (
                <Input
                    id={id}
                    type={f.type === 'number' ? 'number' : 'text'}
                    min={f.type === 'number' ? 0 : undefined}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}{' '}
            {f.hint && <small className="muted">{f.hint}</small>}
        </div>
    );
}
