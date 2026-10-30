/* Medication › Settings › Alerts & access › Delivery › Quiet hours (eMAR P11
 * v5, B2 C5). The organisation default, then each house follows it, sets its
 * own hours or has none (Stephan, Q12). During a house's quiet hours, email
 * and push for alerts without Follow up wait until they end; the bell shows
 * them straight away and Follow up alerts are never held. Times are New
 * Zealand wall-clock times, chosen with the approved time picker — no
 * default times. Everything goes into the page draft and applies when the
 * Alerts tab is reviewed and saved. */
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Field } from '@/components/wizard/primitives';
import { Moon } from 'lucide-react';
import { useSettings } from './_context';
import {
    TIME_OFF,
    encodeQuiet,
    fmtT,
    isSiteDirty,
    parseQuiet,
    quietAt,
    siteDraftValue,
    siteSavedValue,
    siteSlot,
    withDraft,
    type QuietHouse,
} from './_model';
import { useRow } from './_sections';
import { Choice, GroupRow, OnOff, SettingGroup } from './_ui';

const D = 'delivery';
const Q = 'quietHouse';
const TZ = 'Pacific/Auckland';

const match = (q: string, ...s: (string | null | undefined)[]) =>
    !q || s.some((x) => (x ?? '').toLowerCase().includes(q.toLowerCase()));

const isTime = (t: string) => /^([01]\d|2[0-3]):[0-5]\d$/.test(t);

export function QuietHoursGroup({
    q,
    show,
    houses,
    houseIds,
    readOnlyAudit,
}: {
    q: string;
    show: string;
    /** The houses this person sees. */
    houses: { id: number; name: string }[];
    /** Houses whose quiet hours this person changes. */
    houseIds: number[];
    readOnlyAudit: boolean;
}) {
    const { s, draft, setDraft, errors, clearError } = useSettings();
    const { value, edit, state, disabled, shown } = useRow(D);
    if (!s.definitions[D]?.quiet_from || !s.definitions[D]?.quiet_until)
        return null;
    const label =
        s.definitions[D].quiet_from.label ?? 'Hold non-urgent alerts overnight';
    const from = value('quiet_from');
    const until = value('quiet_until');
    const quietOn = from !== TIME_OFF || until !== TIME_OFF;
    const err = errors[`${D}.quiet_from`];
    const pairState =
        state('quiet_from') === 'changed' || state('quiet_until') === 'changed'
            ? 'changed'
            : state('quiet_from');
    const savedOf = (key: string) => s.values[D]?.[key] ?? TIME_OFF;
    // On asks for times: the saved ones, or empty pickers — never invented.
    const switchOn = (on: boolean) => {
        (['quiet_from', 'quiet_until'] as const).forEach((key) => {
            const was = savedOf(key);
            edit(key, on ? (was !== TIME_OFF ? was : '') : TIME_OFF);
        });
        clearError(`${D}.quiet_from`);
    };
    const between = (a: string, b: string) =>
        isTime(a) && isTime(b)
            ? `${fmtT(a)} to ${fmtT(b)}`
            : 'Times not chosen yet';
    return (
        <SettingGroup
            id="quiet"
            icon={Moon}
            title="Quiet hours"
            caption="Organisation default — each house can follow it, set its own, or have none"
        >
            <GroupRow
                id="dl-quieton"
                label={label}
                hint={
                    quietOn
                        ? `Email and push for alerts without Follow up wait until ${isTime(until) ? fmtT(until) : '…'}. They still show in the bell straight away. Alerts with Follow up are never held. Houses below can change this.`
                        : 'Off — every alert is sent straight away (today). Houses below can still set their own.'
                }
                state={pairState}
                error={err}
                errorId="dl-quiet-error"
                hidden={
                    !shown(
                        show,
                        q,
                        'quiet_from',
                        label,
                        'quiet overnight night',
                    )
                }
                control={
                    <OnOff
                        id="dl-quieton"
                        checked={quietOn}
                        disabled={disabled}
                        label={label}
                        onChange={switchOn}
                    />
                }
            >
                {quietOn ? (
                    disabled ? (
                        <p className="text-[13px]">{between(from, until)}</p>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                label="From"
                                hint={TZ}
                                htmlFor="dl-quietFrom"
                            >
                                <TimePicker
                                    compact
                                    id="dl-quietFrom"
                                    label="Quiet hours start"
                                    value={isTime(from) ? from : ''}
                                    invalid={!!err && !isTime(from)}
                                    describedBy={
                                        err ? 'dl-quiet-error' : undefined
                                    }
                                    onChange={(v) => {
                                        edit('quiet_from', v);
                                        clearError(`${D}.quiet_from`);
                                    }}
                                />
                            </Field>
                            <Field
                                label="Until"
                                hint={TZ}
                                htmlFor="dl-quietUntil"
                            >
                                <TimePicker
                                    compact
                                    id="dl-quietUntil"
                                    label="Quiet hours end"
                                    value={isTime(until) ? until : ''}
                                    invalid={!!err && !isTime(until)}
                                    describedBy={
                                        err ? 'dl-quiet-error' : undefined
                                    }
                                    onChange={(v) => {
                                        edit('quiet_until', v);
                                        clearError(`${D}.quiet_from`);
                                    }}
                                />
                            </Field>
                        </div>
                    )
                ) : null}
            </GroupRow>
            {s.definitions[Q]?.hours
                ? houses.map((h) => {
                      const slot = siteSlot('hours', h.id);
                      const qh: QuietHouse = parseQuiet(
                          siteDraftValue(s, draft, Q, 'hours', h.id),
                      ) ?? { mode: 'org', from: '', until: '' };
                      const can = !readOnlyAudit && houseIds.includes(h.id);
                      const eff = quietAt(s, draft, h.id);
                      const dirty = isSiteDirty(s, draft, Q, 'hours', h.id);
                      const herr = errors[`${Q}.${slot}`];
                      const setQ = (patch: Partial<QuietHouse>) => {
                          const next = { ...qh, ...patch };
                          // Back to its own hours: the saved times, if it had them.
                          if (
                              patch.mode === 'own' &&
                              !next.from &&
                              !next.until
                          ) {
                              const saved = parseQuiet(
                                  siteSavedValue(s, Q, 'hours', h.id),
                              );
                              if (saved?.mode === 'own') {
                                  next.from = saved.from;
                                  next.until = saved.until;
                              }
                          }
                          setDraft((d) =>
                              withDraft(d, Q, slot, encodeQuiet(next)),
                          );
                          clearError(`${Q}.${slot}`);
                      };
                      const visible =
                          (show === 'open'
                              ? false
                              : show === 'changed'
                                ? dirty
                                : true) &&
                          match(q, h.name, 'quiet', 'overnight');
                      return (
                          <GroupRow
                              key={h.id}
                              id={`dl-qh-${h.id}`}
                              label={h.name}
                              hint={
                                  qh.mode === 'org'
                                      ? eff
                                          ? `Follows the organisation: ${fmtT(eff.from)} to ${fmtT(eff.until)}.`
                                          : 'Follows the organisation: no quiet hours.'
                                      : qh.mode === 'off'
                                        ? 'No quiet hours — every alert is sent straight away here.'
                                        : `Its own quiet hours${can ? '' : ` — only someone who manages ${h.name} can change them`}.`
                              }
                              state={dirty ? 'changed' : null}
                              error={herr}
                              errorId={`dl-qh-${h.id}-error`}
                              hidden={!visible}
                          >
                              <Choice
                                  value={qh.mode}
                                  disabled={!can}
                                  onChange={(v) => setQ({ mode: v })}
                                  options={[
                                      ['org', 'Follow the organisation'],
                                      ['own', 'Own hours'],
                                      ['off', 'None'],
                                  ]}
                              />
                              {qh.mode === 'own' ? (
                                  can ? (
                                      <div className="grid gap-3 sm:grid-cols-2">
                                          <Field
                                              label="From"
                                              hint={TZ}
                                              htmlFor={`dl-qh-${h.id}-from`}
                                          >
                                              <TimePicker
                                                  compact
                                                  id={`dl-qh-${h.id}-from`}
                                                  label={`Quiet hours start at ${h.name}`}
                                                  value={qh.from}
                                                  invalid={!!herr && !qh.from}
                                                  describedBy={
                                                      herr
                                                          ? `dl-qh-${h.id}-error`
                                                          : undefined
                                                  }
                                                  onChange={(v) =>
                                                      setQ({ from: v })
                                                  }
                                              />
                                          </Field>
                                          <Field
                                              label="Until"
                                              hint={TZ}
                                              htmlFor={`dl-qh-${h.id}-until`}
                                          >
                                              <TimePicker
                                                  compact
                                                  id={`dl-qh-${h.id}-until`}
                                                  label={`Quiet hours end at ${h.name}`}
                                                  value={qh.until}
                                                  invalid={!!herr && !qh.until}
                                                  describedBy={
                                                      herr
                                                          ? `dl-qh-${h.id}-error`
                                                          : undefined
                                                  }
                                                  onChange={(v) =>
                                                      setQ({ until: v })
                                                  }
                                              />
                                          </Field>
                                      </div>
                                  ) : (
                                      <p className="text-[13px]">
                                          {between(qh.from, qh.until)}
                                      </p>
                                  )
                              ) : null}
                          </GroupRow>
                      );
                  })
                : null}
        </SettingGroup>
    );
}
