/* P07-owned Medication rules > Controlled drugs. Uses P11's persistent page
 * draft and Review changes; no independent save transport or policy inference
 * from live medicine data. Main supplies visible houses and server-authorised
 * editable house IDs, and wires this fragment into the shared Settings page. */
import { StatusBadge } from '@/components/ui/status-badge';
import { ClipboardCheck, FlaskConical, Users } from 'lucide-react';
import { useSettings } from './_context';
import {
    isDirty,
    isSiteDirty,
    savedValue,
    siteDraftValue,
    siteSlot,
    withDraft,
} from './_model';
import { NoMatches, useRow } from './_sections';
import {
    Choice,
    GroupGrid,
    GroupRow,
    NumberInput,
    OnOff,
    Section,
    SettingGroup,
    type RowState,
} from './_ui';

const WITNESS = 'controlled_witness';
const COUNTS = 'controlled_counts';
const DESTRUCTION = 'controlled_destruction';

const match = (query: string, ...values: string[]) =>
    !query ||
    values.some((value) => value.toLowerCase().includes(query.toLowerCase()));

/** Keep P11's Choice while giving its buttons a named group and clear focus. */
function PolicyChoice({
    label,
    value,
    options,
    disabled,
    onChange,
}: {
    label: string;
    value: string;
    options: [string, string][];
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className="[&_button]:focus-visible:ring-ring [&_button]:min-h-11 [&_button]:focus-visible:ring-2"
        >
            <Choice
                value={value}
                options={options}
                disabled={disabled}
                onChange={onChange}
            />
        </div>
    );
}
export function ControlledProductSettings({
    q,
    show,
    clear,
    houses,
    houseIds,
    readOnlyAudit = false,
}: {
    q: string;
    show: string;
    clear: () => void;
    /** Approved visible houses only; no hidden medicine counts are supplied. */
    houses: { id: number; name: string }[];
    /** The backend's current editable house IDs, not the alert-manager grant. */
    houseIds: number[];
    readOnlyAudit?: boolean;
}) {
    const { s, draft, setDraft, errors, clearError } = useSettings();
    const witness = useRow(WITNESS);
    const counts = useRow(COUNTS);
    const destruction = useRow(DESTRUCTION);
    const organisationRequired = witness.value('organisation') !== 'off';
    const cadence = counts.value('cadence');
    const cadenceDirty = isDirty(s, draft, COUNTS, 'cadence');
    const cadenceState: RowState =
        !cadenceDirty && savedValue(s, COUNTS, 'cadence') === ''
            ? 'nc'
            : counts.state('cadence');
    const editableHouseIds = new Set(houseIds);
    const houseDefinition = s.definitions[WITNESS]?.house;
    const options = (group: string, key: string): [string, string][] =>
        (s.definitions[group]?.[key]?.options ?? []).map((option) => [
            option.value,
            option.label,
        ]);
    const editHouse = (siteId: number, value: string) => {
        const slot = siteSlot('house', siteId);
        setDraft((previous) => withDraft(previous, WITNESS, slot, value));
        clearError(`${WITNESS}.${slot}`);
    };

    return (
        <Section
            id="sc-controlled-product"
            title="Controlled drugs"
            caption="Witnessing, counts and destruction"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="controlled-witness"
                    icon={Users}
                    title="Witness"
                    caption="A second person, on shift at the house, with a witness PIN"
                    wide
                >
                    {s.definitions[WITNESS]?.organisation ? (
                        <GroupRow
                            id="cd-witness-organisation"
                            label="Witness required for controlled drugs"
                            hint="The default for every house. An order can still require a witness. Counts and movements always need a witness."
                            state={witness.state('organisation')}
                            error={errors[`${WITNESS}.organisation`]}
                            errorId="cd-witness-organisation-error"
                            hidden={
                                !witness.shown(
                                    show,
                                    q,
                                    'organisation',
                                    'Witness required for controlled drugs',
                                    'Organisation default',
                                )
                            }
                            control={
                                <OnOff
                                    id="cd-witness-organisation"
                                    label="Witness required for controlled drugs"
                                    checked={organisationRequired}
                                    disabled={witness.disabled || readOnlyAudit}
                                    onChange={(enabled) => {
                                        witness.edit(
                                            'organisation',
                                            enabled ? 'on' : 'off',
                                        );
                                        clearError(`${WITNESS}.organisation`);
                                    }}
                                />
                            }
                        />
                    ) : null}
                    {houseDefinition
                        ? houses.map((house) => {
                              const slot = siteSlot('house', house.id);
                              const value = siteDraftValue(
                                  s,
                                  draft,
                                  WITNESS,
                                  'house',
                                  house.id,
                              );
                              const dirty = isSiteDirty(
                                  s,
                                  draft,
                                  WITNESS,
                                  'house',
                                  house.id,
                              );
                              const reviewed =
                                  s.site_reviewed[house.id]?.[WITNESS]?.house;
                              const required =
                                  value === 'org'
                                      ? organisationRequired
                                      : value !== 'off';
                              const canEdit =
                                  !readOnlyAudit &&
                                  (s.can_manage_organisation ||
                                      editableHouseIds.has(house.id));
                              const visible =
                                  (show === 'open'
                                      ? !reviewed
                                      : show === 'changed'
                                        ? dirty
                                        : true) &&
                                  match(
                                      q,
                                      house.name,
                                      'Witness at this house',
                                      'Controlled drugs',
                                  );
                              return (
                                  <GroupRow
                                      key={house.id}
                                      id={`cd-witness-house-${house.id}`}
                                      label={house.name}
                                      hint={
                                          value === 'org'
                                              ? `Follows the organisation: witness ${organisationRequired ? 'required' : 'not required'}. An order can still require one.`
                                              : 'This house’s choice. An order can still require a witness.'
                                      }
                                      state={
                                          dirty
                                              ? 'changed'
                                              : reviewed
                                                ? null
                                                : 'default'
                                      }
                                      error={errors[`${WITNESS}.${slot}`]}
                                      errorId={`cd-witness-house-${house.id}-error`}
                                      hidden={!visible}
                                      control={
                                          <StatusBadge
                                              variant={
                                                  required
                                                      ? 'success'
                                                      : 'warning'
                                              }
                                              size="sm"
                                          >
                                              {required
                                                  ? 'Witness required'
                                                  : 'Not required'}
                                          </StatusBadge>
                                      }
                                  >
                                      <PolicyChoice
                                          label={
                                              'Witness policy at ' + house.name
                                          }
                                          value={value}
                                          options={options(WITNESS, 'house')}
                                          disabled={!canEdit}
                                          onChange={(next) =>
                                              editHouse(house.id, next)
                                          }
                                      />
                                  </GroupRow>
                              );
                          })
                        : null}
                </SettingGroup>

                <SettingGroup
                    id="controlled-counts"
                    icon={ClipboardCheck}
                    title="Counts"
                    caption="The count follows each house’s roster changes"
                >
                    {s.definitions[COUNTS]?.cadence ? (
                        <GroupRow
                            id="cd-count-cadence"
                            label="How often controlled medicines are counted"
                            hint={
                                cadence === ''
                                    ? 'Recommended: Every shift change. Choose a cadence and review the change before counts show as due.'
                                    : cadence === 'week'
                                      ? 'Weekly timing is unavailable until its day and shift are configured.'
                                      : 'Due 30 minutes before the shift change. Counts always need a witness.'
                            }
                            state={cadenceState}
                            error={errors[`${COUNTS}.cadence`]}
                            errorId="cd-count-cadence-error"
                            hidden={
                                !counts.shown(
                                    show,
                                    q,
                                    'cadence',
                                    'How often controlled medicines are counted',
                                    'Every shift change morning daily weekly',
                                )
                            }
                        >
                            <PolicyChoice
                                label="How often controlled medicines are counted"
                                value={cadence}
                                options={options(COUNTS, 'cadence')}
                                disabled={counts.disabled || readOnlyAudit}
                                onChange={(value) => {
                                    counts.edit('cadence', value);
                                    clearError(`${COUNTS}.cadence`);
                                }}
                            />
                            {cadence === 'week' ? (
                                <StatusBadge variant="warning" size="sm">
                                    Timing not configured
                                </StatusBadge>
                            ) : null}
                        </GroupRow>
                    ) : null}
                    {s.definitions[COUNTS]?.overdue_minutes ? (
                        <GroupRow
                            id="cd-count-overdue"
                            label="Counts as overdue"
                            hint="Time after the rostered shift change. Recording a dose is never blocked by an open discrepancy."
                            state={counts.state('overdue_minutes')}
                            error={errors[`${COUNTS}.overdue_minutes`]}
                            errorId="cd-count-overdue-error"
                            hidden={
                                !counts.shown(
                                    show,
                                    q,
                                    'overdue_minutes',
                                    'Counts as overdue',
                                    'Minutes after shift change',
                                )
                            }
                        >
                            <NumberInput
                                id="cd-count-overdue"
                                label="Counts as overdue"
                                value={counts.value('overdue_minutes')}
                                unit="minutes after the shift change"
                                min={
                                    s.definitions[COUNTS].overdue_minutes
                                        .range?.[0]
                                }
                                max={
                                    s.definitions[COUNTS].overdue_minutes
                                        .range?.[1]
                                }
                                disabled={counts.disabled || readOnlyAudit}
                                error={errors[`${COUNTS}.overdue_minutes`]}
                                errorId="cd-count-overdue-error"
                                onChange={(value) => {
                                    counts.edit('overdue_minutes', value);
                                    clearError(`${COUNTS}.overdue_minutes`);
                                }}
                            />
                        </GroupRow>
                    ) : null}
                </SettingGroup>

                <SettingGroup
                    id="controlled-destruction"
                    icon={FlaskConical}
                    title="Destruction"
                    caption="Return to pharmacy is the default"
                >
                    {s.definitions[DESTRUCTION]?.onsite ? (
                        <GroupRow
                            id="cd-destruction-onsite"
                            label="Allow on-site denaturing with two witnesses"
                            hint="Only where the organisation allows it. Return to pharmacy remains available, with the pharmacist’s receipt."
                            state={destruction.state('onsite')}
                            error={errors[`${DESTRUCTION}.onsite`]}
                            errorId="cd-destruction-onsite-error"
                            hidden={
                                !destruction.shown(
                                    show,
                                    q,
                                    'onsite',
                                    'Allow on-site denaturing with two witnesses',
                                    'Destruction return to pharmacy',
                                )
                            }
                            control={
                                <OnOff
                                    id="cd-destruction-onsite"
                                    label="Allow on-site denaturing with two witnesses"
                                    checked={
                                        destruction.value('onsite') === 'on'
                                    }
                                    disabled={
                                        destruction.disabled || readOnlyAudit
                                    }
                                    onChange={(enabled) => {
                                        destruction.edit(
                                            'onsite',
                                            enabled ? 'on' : 'off',
                                        );
                                        clearError(`${DESTRUCTION}.onsite`);
                                    }}
                                />
                            }
                        />
                    ) : null}
                </SettingGroup>
            </GroupGrid>
        </Section>
    );
}
