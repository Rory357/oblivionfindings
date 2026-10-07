import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
} from '@/components/page/page-header';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { Stethoscope, X } from 'lucide-react';
import { useState } from 'react';
import { Toggle } from './connected/_forms';
import {
    BoundedTable,
    ReviewWizard,
    ServerPages,
    SingleFile,
    multipart,
    useCommand,
    useWorkspaceView,
} from './connected/_shared';
import type { Proposal } from './connected/_types';
import type { Prescription } from './orders/_types';

type Medicine = Omit<Prescription, 'dose_times' | 'start_date'> & {
    dose_times: string[] | null;
    start_date: string | null;
    id: number;
    version: number;
    state: string;
    approval_status: string;
};
type Props = {
    clinician: {
        name: string;
        provider_name: string;
        registration_authority: string;
        registration_number: string;
        expires_at: string;
    };
    people: {
        id: number;
        name: string;
        date_of_birth: string;
        expires_at: string;
        can_propose: boolean;
        include_controlled: boolean;
    }[];
    selected_client: {
        id: number;
        name: string;
        date_of_birth: string;
        medications: Medicine[];
        allergies: { allergen: string; reaction: string; severity: string }[];
    } | null;
    proposals: Proposal[];
    pagination?: {
        proposals: { current_page: number; last_page: number; total: number };
    };
};
export default function ClinicalPortal(props: Props) {
    const [view, setView] = useWorkspaceView('chart', ['chart', 'requests']);
    const [request, setRequest] = useState<{
        kind: 'start' | 'change' | 'stop';
        medicine?: Medicine;
    } | null>(null);
    const person = props.selected_client;
    const grant = props.people.find((p) => p.id === person?.id);
    return (
        <>
            <Head title="Clinical portal" />
            <main className="min-h-screen bg-background p-5 text-foreground">
                <div className="mx-auto max-w-[1600px] space-y-5">
                    <PageHeader
                        wrapTitle
                        icon={Stethoscope}
                        title="Clinical portal"
                        subline={
                            props.clinician.name +
                            ' · ' +
                            props.clinician.provider_name +
                            ' · ' +
                            props.clinician.registration_authority +
                            ' ' +
                            props.clinician.registration_number
                        }
                        actions={
                            <>
                                <PageHeaderGlassButton asChild>
                                    <Link href="/settings/two-factor">
                                        Account security
                                    </Link>
                                </PageHeaderGlassButton>
                                <PageHeaderGlassButton
                                    onClick={() => router.post('/logout')}
                                >
                                    Sign out
                                </PageHeaderGlassButton>
                                {grant?.can_propose && (
                                    <PageHeaderPrimaryButton
                                        onClick={() =>
                                            setRequest({ kind: 'start' })
                                        }
                                    >
                                        Request a medicine
                                    </PageHeaderPrimaryButton>
                                )}
                            </>
                        }
                        filters={
                            <RecordPicker
                                variant="header"
                                label="Person"
                                value={person ? String(person.id) : ''}
                                options={props.people.map((p) => ({
                                    value: String(p.id),
                                    label: p.name,
                                    description: formatDateOnly(
                                        p.date_of_birth,
                                    ),
                                }))}
                                onChange={(id) =>
                                    router.get(
                                        '/clinical-portal',
                                        { client_id: id },
                                        { preserveState: false },
                                    )
                                }
                            />
                        }
                        rail={
                            <PageHeaderRail
                                value={view}
                                items={[
                                    {
                                        key: 'chart',
                                        label: 'Current chart',
                                        icon: Stethoscope,
                                    },
                                    {
                                        key: 'requests',
                                        label: 'My requests',
                                        icon: Stethoscope,
                                    },
                                ]}
                                onSelect={setView}
                                showFind={false}
                            />
                        }
                    />
                    {!person && view === 'chart' ? (
                        <SettingsNotice>
                            Select a person with a current named access grant.
                            If no person appears, ask the care organisation to
                            review your access.
                        </SettingsNotice>
                    ) : (
                        <>
                            {person && view === 'chart' && (
                                <p className="text-sm font-semibold">
                                    {person.name} ·{' '}
                                    {formatDateOnly(person.date_of_birth)}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        · access ends{' '}
                                        {formatDateTime(
                                            grant?.expires_at ?? '',
                                        )}
                                    </span>
                                </p>
                            )}
                            {view === 'chart' && person ? (
                                <>
                                    <SettingsNotice>
                                        {grant?.include_controlled
                                            ? 'Your grant includes controlled medicines.'
                                            : 'Controlled medicines may be withheld from this view; this is not a complete controlled-medicine chart.'}{' '}
                                        Review current source evidence with the
                                        care organisation before making a
                                        medication decision.
                                    </SettingsNotice>
                                    <Card>
                                        <CardContent className="p-4">
                                            <h2 className="text-section-heading">
                                                Recorded allergies
                                            </h2>
                                            {person.allergies.length ? (
                                                person.allergies.map((a, i) => (
                                                    <p
                                                        key={i}
                                                        className="mt-2 text-sm"
                                                    >
                                                        {a.allergen} ·{' '}
                                                        {a.reaction ||
                                                            'Reaction not recorded'}{' '}
                                                        ·{' '}
                                                        {a.severity ||
                                                            'Severity not recorded'}
                                                    </p>
                                                ))
                                            ) : (
                                                <p className="text-caption mt-2">
                                                    No allergy entries are
                                                    recorded here. Confirm
                                                    allergy status with the care
                                                    organisation.
                                                </p>
                                            )}
                                        </CardContent>
                                    </Card>
                                    <BoundedTable
                                        rows={person.medications}
                                        identity={(m) => ({
                                            name: m.name,
                                            subline: m.dosage + ' · ' + m.route,
                                        })}
                                        columns={[
                                            {
                                                key: 'schedule',
                                                label: 'Schedule',
                                                width: '1.5fr',
                                                cell: (m) => (
                                                    <>
                                                        {m.is_prn
                                                            ? 'As needed'
                                                            : m.frequency}
                                                        <p className="text-caption">
                                                            {(
                                                                m.dose_times ??
                                                                []
                                                            ).join(', ')}
                                                        </p>
                                                    </>
                                                ),
                                            },
                                            {
                                                key: 'instructions',
                                                label: 'Instructions',
                                                width: '2fr',
                                                cell: (m) =>
                                                    m.instructions || '—',
                                            },
                                            {
                                                key: 'state',
                                                label: 'State',
                                                width: '1fr',
                                                cell: (m) => (
                                                    <>
                                                        <StatusBadge
                                                            status={m.state}
                                                        />
                                                        <p className="text-caption">
                                                            {m.approval_status}{' '}
                                                            · version{' '}
                                                            {m.version}
                                                        </p>
                                                    </>
                                                ),
                                            },
                                            {
                                                key: 'request',
                                                label: 'Request',
                                                width: '180px',
                                                cell: (m) =>
                                                    grant?.can_propose ? (
                                                        <div className="flex gap-2">
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setRequest({
                                                                        kind: 'change',
                                                                        medicine:
                                                                            m,
                                                                    })
                                                                }
                                                            >
                                                                Change
                                                            </Button>
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setRequest({
                                                                        kind: 'stop',
                                                                        medicine:
                                                                            m,
                                                                    })
                                                                }
                                                            >
                                                                Stop
                                                            </Button>
                                                        </div>
                                                    ) : null,
                                            },
                                        ]}
                                    />
                                </>
                            ) : (
                                <>
                                    <SettingsNotice>
                                        My requests across all people I
                                        currently have access to. Each row names
                                        the person.
                                    </SettingsNotice>
                                    <BoundedTable
                                        rows={props.proposals}
                                        identity={(p) => ({
                                            name:
                                                p.prescription?.name ??
                                                'Stop request',
                                            subline:
                                                p.client_name +
                                                ' · ' +
                                                p.reason,
                                        })}
                                        columns={[
                                            {
                                                key: 'date',
                                                label: 'Submitted',
                                                width: '1fr',
                                                cell: (p) =>
                                                    formatDateTime(
                                                        p.submitted_at,
                                                    ),
                                            },
                                            {
                                                key: 'status',
                                                label: 'Review',
                                                width: '1fr',
                                                cell: (p) => (
                                                    <StatusBadge
                                                        status={p.status}
                                                    />
                                                ),
                                            },
                                            {
                                                key: 'decision',
                                                label: 'Decision note',
                                                width: '2fr',
                                                cell: (p) =>
                                                    p.decision_note ??
                                                    'Awaiting review',
                                            },
                                            {
                                                key: 'source',
                                                label: 'Source',
                                                width: '100px',
                                                cell: (p) =>
                                                    p.has_source_file ? (
                                                        <Button
                                                            variant="link"
                                                            asChild
                                                        >
                                                            <a
                                                                href={
                                                                    '/clinical-portal/proposals/' +
                                                                    p.id +
                                                                    '/source'
                                                                }
                                                            >
                                                                Open
                                                            </a>
                                                        </Button>
                                                    ) : null,
                                            },
                                        ]}
                                    />
                                </>
                            )}
                        </>
                    )}
                    {view === 'requests' && (
                        <ServerPages
                            meta={props.pagination?.proposals}
                            name="proposals"
                            path="/clinical-portal"
                        />
                    )}
                    {person && request && (
                        <ProposalWizard
                            person={person}
                            prescriber={props.clinician.name}
                            controlled={grant?.include_controlled ?? false}
                            {...request}
                            onClose={() => setRequest(null)}
                        />
                    )}
                </div>
            </main>
        </>
    );
}
function ProposalWizard({
    person,
    prescriber,
    controlled,
    kind,
    medicine,
    onClose,
}: {
    person: NonNullable<Props['selected_client']>;
    prescriber: string;
    controlled: boolean;
    kind: 'start' | 'change' | 'stop';
    medicine?: Medicine;
    onClose: () => void;
}) {
    const command = useCommand();
    const [saved, setSaved] = useState(false);
    const [key] = useState(() => crypto.randomUUID());
    const [reason, setReason] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [p, setP] = useState<Prescription>(
        medicine
            ? {
                  ...medicine,
                  dose_times: medicine.dose_times ?? [],
                  start_date: medicine.start_date?.slice(0, 10) ?? '',
                  end_date: medicine.end_date?.slice(0, 10) || null,
              }
            : {
                  name: '',
                  dosage: '',
                  dose_amount: null,
                  dose_unit: '',
                  frequency: '',
                  frequency_code: null,
                  dose_times: [],
                  is_prn: false,
                  route: '',
                  form: '',
                  instructions: '',
                  indication: '',
                  prn_reason: '',
                  max_per_day: null,
                  min_hours_between_doses: null,
                  start_date: toDateInput(new Date()),
                  end_date: null,
                  prescriber,
                  pharmacy: '',
                  controlled_drug: false,
                  high_risk: false,
                  witness_required: false,
              },
    );
    const set = <K extends keyof Prescription>(k: K, v: Prescription[K]) =>
        setP((s) => ({ ...s, [k]: v }));
    const valid =
        kind === 'stop' ||
        (!!(
            p.name &&
            p.dosage &&
            p.route &&
            p.frequency &&
            p.indication &&
            p.start_date
        ) &&
            (p.is_prn
                ? !!p.prn_reason &&
                  Number(p.max_per_day) > 0 &&
                  p.min_hours_between_doses !== null &&
                  String(p.min_hours_between_doses).trim() !== '' &&
                  Number(p.min_hours_between_doses) >= 0 &&
                  Number(p.min_hours_between_doses) <= 168
                : p.dose_times.length > 0 &&
                  p.dose_times.every((t) =>
                      /^([01]\d|2[0-3]):[0-5]\d$/.test(t),
                  )));
    return (
        <ReviewWizard
            title={
                kind === 'stop'
                    ? 'Request medication stop'
                    : kind === 'change'
                      ? 'Request medication change'
                      : 'Request a medicine'
            }
            description={person.name}
            onClose={onClose}
            saved={saved}
            busy={command.busy}
            error={command.error}
            disabled={command.uncertain}
            saveLabel="Send for review"
            success="Request submitted"
            successDetail="The care organisation must review the source and complete its medication checks. The chart has not changed."
            onSave={async () => {
                if (
                    await command.run(
                        '/clinical-portal/people/' + person.id + '/proposals',
                        multipart({
                            kind,
                            medication_id: medicine?.id,
                            expected_version: medicine?.version,
                            prescription: kind === 'stop' ? null : p,
                            reason,
                            request_key: key,
                            source_file: file,
                        }),
                    )
                ) {
                    setSaved(true);
                    router.reload();
                }
            }}
            steps={[
                ...(kind === 'stop'
                    ? []
                    : [
                          {
                              label: 'Medicine and dose',
                              valid,
                              content: (
                                  <div className="space-y-4">
                                      <div className="grid grid-cols-2 gap-4">
                                          {(
                                              [
                                                  [
                                                      'name',
                                                      'Medicine / product name',
                                                  ],
                                                  [
                                                      'dosage',
                                                      'Full dose instruction',
                                                  ],
                                                  [
                                                      'dose_amount',
                                                      'Numeric dose amount',
                                                  ],
                                                  ['dose_unit', 'Dose unit'],
                                                  ['route', 'Route'],
                                                  ['form', 'Form'],
                                                  [
                                                      'frequency',
                                                      'Frequency instruction',
                                                  ],
                                                  ['indication', 'Indication'],
                                                  ['prescriber', 'Prescriber'],
                                                  ['pharmacy', 'Pharmacy'],
                                              ] as const
                                          ).map(([k, label]) => (
                                              <Field key={k} label={label}>
                                                  <Input
                                                      value={p[k] ?? ''}
                                                      onChange={(e) =>
                                                          set(k, e.target.value)
                                                      }
                                                  />
                                              </Field>
                                          ))}
                                      </div>
                                      <Field label="Additional instructions">
                                          <Textarea
                                              value={p.instructions ?? ''}
                                              onChange={(e) =>
                                                  set(
                                                      'instructions',
                                                      e.target.value,
                                                  )
                                              }
                                          />
                                      </Field>
                                      <Toggle
                                          label="As-needed medicine"
                                          checked={p.is_prn}
                                          onChange={(v) => set('is_prn', v)}
                                      />
                                      {p.is_prn ? (
                                          <div className="grid grid-cols-2 gap-4">
                                              <Field label="When it may be given">
                                                  <Input
                                                      value={p.prn_reason ?? ''}
                                                      onChange={(e) =>
                                                          set(
                                                              'prn_reason',
                                                              e.target.value,
                                                          )
                                                      }
                                                  />
                                              </Field>
                                              <Field label="Maximum per day">
                                                  <Input
                                                      type="number"
                                                      min="0"
                                                      value={
                                                          p.max_per_day ?? ''
                                                      }
                                                      onChange={(e) =>
                                                          set(
                                                              'max_per_day',
                                                              e.target.value,
                                                          )
                                                      }
                                                  />
                                              </Field>
                                              <Field label="Minimum hours between doses">
                                                  <Input
                                                      type="number"
                                                      min="0"
                                                      step="any"
                                                      value={
                                                          p.min_hours_between_doses ??
                                                          ''
                                                      }
                                                      onChange={(e) =>
                                                          set(
                                                              'min_hours_between_doses',
                                                              e.target.value,
                                                          )
                                                      }
                                                  />
                                              </Field>
                                          </div>
                                      ) : (
                                          <div className="space-y-2">
                                              <h3 className="text-sm font-semibold">
                                                  Scheduled times ·
                                                  Pacific/Auckland
                                              </h3>
                                              {p.dose_times.map((t, i) => (
                                                  <div
                                                      key={i}
                                                      className="flex items-center gap-2"
                                                  >
                                                      <TimePicker
                                                          compact
                                                          id={
                                                              'proposal-time-' +
                                                              i
                                                          }
                                                          label={
                                                              'Dose time ' +
                                                              (i + 1)
                                                          }
                                                          value={t}
                                                          onChange={(v) =>
                                                              set(
                                                                  'dose_times',
                                                                  p.dose_times.map(
                                                                      (
                                                                          old,
                                                                          n,
                                                                      ) =>
                                                                          n ===
                                                                          i
                                                                              ? v
                                                                              : old,
                                                                  ),
                                                              )
                                                          }
                                                      />
                                                      <Button
                                                          variant="ghost"
                                                          size="icon"
                                                          aria-label={
                                                              'Remove dose time ' +
                                                              (i + 1)
                                                          }
                                                          onClick={() =>
                                                              set(
                                                                  'dose_times',
                                                                  p.dose_times.filter(
                                                                      (_, n) =>
                                                                          n !==
                                                                          i,
                                                                  ),
                                                              )
                                                          }
                                                      >
                                                          <X className="size-4" />
                                                      </Button>
                                                  </div>
                                              ))}
                                              <Button
                                                  variant="outline"
                                                  disabled={
                                                      p.dose_times.length >= 12
                                                  }
                                                  onClick={() =>
                                                      set('dose_times', [
                                                          ...p.dose_times,
                                                          '',
                                                      ])
                                                  }
                                              >
                                                  Add dose time
                                              </Button>
                                          </div>
                                      )}
                                      <div className="grid grid-cols-2 gap-4">
                                          <Field label="Start date" required>
                                              <DatePicker
                                                  compact
                                                  id="proposal-start"
                                                  label="Start date"
                                                  value={p.start_date}
                                                  onChange={(v) =>
                                                      set('start_date', v)
                                                  }
                                              />
                                          </Field>
                                          <Field label="End date (if prescribed)">
                                              <DatePicker
                                                  compact
                                                  allowClear
                                                  id="proposal-end"
                                                  label="End date (if prescribed)"
                                                  value={p.end_date ?? ''}
                                                  onChange={(v) =>
                                                      set('end_date', v || null)
                                                  }
                                              />
                                          </Field>
                                      </div>
                                      {controlled && (
                                          <Toggle
                                              label="Controlled medicine"
                                              checked={p.controlled_drug}
                                              onChange={(v) =>
                                                  set('controlled_drug', v)
                                              }
                                          />
                                      )}
                                      <Toggle
                                          label="High-risk medicine"
                                          checked={p.high_risk}
                                          onChange={(v) => set('high_risk', v)}
                                      />
                                      <Toggle
                                          label="Second person required"
                                          checked={p.witness_required}
                                          onChange={(v) =>
                                              set('witness_required', v)
                                          }
                                      />
                                  </div>
                              ),
                          },
                      ]),
                {
                    label: 'Reason and source',
                    valid: !!reason,
                    content: (
                        <div className="space-y-4">
                            <Field
                                label="Clinical reason and source context"
                                required
                            >
                                <Textarea
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                            </Field>
                            <SingleFile file={file} onChange={setFile} />
                            <SettingsNotice>
                                This submits a request for review. The care
                                organisation needs valid source evidence before
                                changing the chart.
                            </SettingsNotice>
                        </div>
                    ),
                },
            ]}
            review={[
                { label: 'Person', value: person.name },
                { label: 'Request', value: kind },
                { label: 'Medicine', value: p.name },
                { label: 'Dose', value: p.dosage },
                {
                    label: 'Route / frequency',
                    value: p.route + ' · ' + p.frequency,
                },
                {
                    label: 'Times',
                    value: p.is_prn ? 'As needed' : p.dose_times.join(', '),
                },
                { label: 'Reason', value: reason },
                { label: 'Source', value: file?.name ?? 'Not attached' },
            ]}
        />
    );
}
