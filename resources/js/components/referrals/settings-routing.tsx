import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import axios from 'axios';
import { useState } from 'react';
import {
    fundingSituations,
    IntakePathwaySelect,
    reportedPathway,
} from './intake-funding';
import type { ReferralConfiguration } from './settings-types';
import { EditorFrame, OnOff } from './settings-ui';

const emptyRouting = {
    enabled: false,
    fallback_owner_ids: [],
    fallback_max_open: 50,
    rules: [],
};
type Routing = NonNullable<ReferralConfiguration['routing']>;
type Option = { id: number; name: string };

export function SettingsRouting({
    value,
    onChange,
    disabled,
    staff,
    sites,
    embedded = false,
}: {
    value: ReferralConfiguration;
    onChange: (next: ReferralConfiguration) => void;
    disabled: boolean;
    staff: Option[];
    sites: Option[];
    /** Inside a settings dialog: plain sections instead of cards. */
    embedded?: boolean;
}) {
    const routing = value.routing ?? emptyRouting;
    const [service, setService] = useState('unsure');
    const [region, setRegion] = useState('');
    const [fundingSituation, setFundingSituation] = useState('unsure');
    const [pathway, setPathway] = useState('');
    const pathways = value.pathways.filter((item) => item.enabled);
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<{
        reason: string;
        team: string;
        owner_id: number | null;
        skipped?: string[];
    } | null>(null);
    const [error, setError] = useState('');
    function edit(action: (next: Routing) => void) {
        const next = structuredClone(routing);
        action(next);
        setResult(null);
        onChange({ ...value, routing: next });
    }
    async function simulate() {
        setBusy(true);
        setError('');
        setResult(null);
        try {
            const configuration = structuredClone(value);
            configuration.routing?.rules.forEach((rule) => {
                rule.regions = rule.regions
                    .map((item) => item.trim())
                    .filter(Boolean);
            });
            const { data } = await axios.post(
                '/settings/referrals/routing-preview',
                {
                    configuration,
                    service,
                    region,
                    funding_situation: fundingSituation,
                    funding_pathway_id: reportedPathway(
                        pathways,
                        service,
                        pathway,
                    )?.id,
                },
            );
            setResult(data.result);
        } catch (caught) {
            const data = axios.isAxiosError(caught)
                ? caught.response?.data
                : null;
            setError(
                data?.errors
                    ? Object.values(data.errors).flat().join(' ')
                    : 'Routing could not be checked. Review access and configuration, then retry.',
            );
        } finally {
            setBusy(false);
        }
    }
    return (
        <div className="space-y-5">
            <EditorFrame
                embedded={embedded}
                header={
                    <>
                        <h2 className="text-section-title">
                            Website intake routing
                        </h2>
                        <p className="text-subtle">
                            Rules run in order. Eligible staff with the lowest
                            open referral workload are selected; approved leave
                            and workload limits are checked. Staff-recorded
                            phone and agency intake keeps its explicitly chosen
                            site and recording owner. Funding conditions use
                            reported information only and never establish
                            eligibility or approval. Funding rules need owners
                            who can view funding information.
                        </p>
                    </>
                }
            >
                <fieldset disabled={disabled || busy} className="space-y-5">
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-[13px] font-medium">
                            Automatically assign website enquiries
                        </span>
                        <OnOff
                            label="Automatically assign website enquiries"
                            checked={routing.enabled}
                            disabled={disabled || busy}
                            onChange={(checked) =>
                                edit((next) => {
                                    next.enabled = checked;
                                })
                            }
                        />
                    </div>
                    <Owners
                        title="Central-intake fallback owners"
                        selected={routing.fallback_owner_ids}
                        options={staff}
                        onChange={(ids) =>
                            edit((next) => {
                                next.fallback_owner_ids = ids;
                            })
                        }
                    />
                    <Field label="Fallback maximum open referrals per owner">
                        <Input
                            type="number"
                            min={1}
                            max={1000}
                            value={routing.fallback_max_open}
                            onChange={(event) =>
                                edit((next) => {
                                    next.fallback_max_open = Number(
                                        event.target.value,
                                    );
                                })
                            }
                        />
                    </Field>
                    <p className="text-subtle">
                        Fallback uses {value.intake.team}. Owners need
                        central-intake access. If all candidates are unavailable
                        or at capacity, the enquiry remains in central intake
                        for manual assignment.
                    </p>
                    {routing.rules.map((rule, index) => (
                        <section
                            key={rule.id}
                            className="space-y-4 rounded-lg border border-border p-4"
                            aria-label={`Routing rule ${index + 1}`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-section-title">
                                    {index + 1}. {rule.label || 'New rule'}
                                </h3>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        disabled={index === 0 || disabled}
                                        onClick={() =>
                                            edit((next) => {
                                                [
                                                    next.rules[index - 1],
                                                    next.rules[index],
                                                ] = [
                                                    next.rules[index],
                                                    next.rules[index - 1],
                                                ];
                                            })
                                        }
                                    >
                                        Move up
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={
                                            index ===
                                                routing.rules.length - 1 ||
                                            disabled
                                        }
                                        onClick={() =>
                                            edit((next) => {
                                                [
                                                    next.rules[index + 1],
                                                    next.rules[index],
                                                ] = [
                                                    next.rules[index],
                                                    next.rules[index + 1],
                                                ];
                                            })
                                        }
                                    >
                                        Move down
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            edit((next) => {
                                                next.rules.splice(index, 1);
                                            })
                                        }
                                    >
                                        Remove rule
                                    </Button>
                                </div>
                            </div>
                            <Field label="Rule name">
                                <Input
                                    value={rule.label}
                                    maxLength={120}
                                    onChange={(event) =>
                                        edit((next) => {
                                            next.rules[index].label =
                                                event.target.value;
                                        })
                                    }
                                />
                            </Field>
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-[13px] font-medium">
                                    Use this rule
                                </span>
                                <OnOff
                                    label={`Use rule ${index + 1}`}
                                    checked={rule.enabled}
                                    disabled={disabled}
                                    onChange={(checked) =>
                                        edit((next) => {
                                            next.rules[index].enabled = checked;
                                        })
                                    }
                                />
                            </div>
                            <fieldset className="space-y-2">
                                <legend className="text-subtle font-medium">
                                    Services (leave empty for any)
                                </legend>
                                {[
                                    ...value.services,
                                    { id: 'unsure', label: 'Not sure yet' },
                                ].map((option) => (
                                    <label
                                        key={option.id}
                                        className="flex items-center gap-3"
                                    >
                                        <Checkbox
                                            checked={rule.service_ids.includes(
                                                option.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                edit((next) => {
                                                    next.rules[
                                                        index
                                                    ].service_ids = checked
                                                        ? [
                                                              ...rule.service_ids,
                                                              option.id,
                                                          ]
                                                        : rule.service_ids.filter(
                                                              (id) =>
                                                                  id !==
                                                                  option.id,
                                                          );
                                                })
                                            }
                                        />
                                        {option.label}
                                    </label>
                                ))}
                            </fieldset>
                            <Field
                                label="Regions"
                                hint="One exact region name per line. Leave empty for any region; matching ignores case and surrounding spaces."
                            >
                                <Textarea
                                    value={rule.regions.join('\n')}
                                    onChange={(event) =>
                                        edit((next) => {
                                            next.rules[index].regions =
                                                event.target.value.split('\n');
                                        })
                                    }
                                />
                            </Field>
                            {[
                                {
                                    key: 'funding_situations' as const,
                                    title: 'Reported funding situations (leave empty for any)',
                                    options: fundingSituations,
                                },
                                {
                                    key: 'pathway_ids' as const,
                                    title: 'Reported pathways (leave empty for any)',
                                    options: value.pathways.map((item) => ({
                                        value: item.id,
                                        label:
                                            item.label +
                                            (!item.enabled
                                                ? ' · Disabled'
                                                : ''),
                                    })),
                                },
                            ].map((group) => (
                                <fieldset key={group.key} className="space-y-2">
                                    <legend className="text-subtle font-medium">
                                        {group.title}
                                    </legend>
                                    {group.options.map((option) => (
                                        <label
                                            key={option.value}
                                            className="flex items-center gap-3"
                                        >
                                            <Checkbox
                                                checked={(
                                                    rule[group.key] ?? []
                                                ).includes(option.value)}
                                                onCheckedChange={(checked) =>
                                                    edit((next) => {
                                                        next.rules[index][
                                                            group.key
                                                        ] =
                                                            checked === true
                                                                ? [
                                                                      ...(rule[
                                                                          group
                                                                              .key
                                                                      ] ?? []),
                                                                      option.value,
                                                                  ]
                                                                : (
                                                                      rule[
                                                                          group
                                                                              .key
                                                                      ] ?? []
                                                                  ).filter(
                                                                      (id) =>
                                                                          id !==
                                                                          option.value,
                                                                  );
                                                    })
                                                }
                                            />
                                            {option.label}
                                        </label>
                                    ))}
                                </fieldset>
                            ))}
                            <Field label="Owning site">
                                <SelectInput
                                    placeholder="Choose owning site"
                                    ariaLabel={`Owning site for rule ${index + 1}`}
                                    value={
                                        rule.site_id === null
                                            ? 'central'
                                            : String(rule.site_id)
                                    }
                                    options={[
                                        {
                                            value: 'central',
                                            label: 'Central intake',
                                        },
                                        ...sites.map((site) => ({
                                            value: String(site.id),
                                            label: site.name,
                                        })),
                                    ]}
                                    onChange={(id) =>
                                        edit((next) => {
                                            next.rules[index].site_id =
                                                id === 'central'
                                                    ? null
                                                    : Number(id);
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Team name">
                                <Input
                                    value={rule.team}
                                    maxLength={120}
                                    onChange={(event) =>
                                        edit((next) => {
                                            next.rules[index].team =
                                                event.target.value;
                                        })
                                    }
                                />
                            </Field>
                            <Owners
                                title="Eligible owner candidates"
                                selected={rule.owner_ids}
                                options={staff}
                                onChange={(ids) =>
                                    edit((next) => {
                                        next.rules[index].owner_ids = ids;
                                    })
                                }
                            />
                            <Field label="Maximum open referrals per owner">
                                <Input
                                    type="number"
                                    min={1}
                                    max={1000}
                                    value={rule.max_open}
                                    onChange={(event) =>
                                        edit((next) => {
                                            next.rules[index].max_open = Number(
                                                event.target.value,
                                            );
                                        })
                                    }
                                />
                            </Field>
                        </section>
                    ))}
                    {!disabled && (
                        <Button
                            variant="outline"
                            disabled={routing.rules.length >= 30}
                            onClick={() =>
                                edit((next) => {
                                    next.rules.push({
                                        id: `rule_${crypto.randomUUID().replaceAll('-', '')}`,
                                        label: 'New routing rule',
                                        enabled: true,
                                        service_ids: [],
                                        regions: [],
                                        site_id: null,
                                        team: value.intake.team,
                                        owner_ids: [],
                                        max_open: 50,
                                    });
                                })
                            }
                        >
                            Add routing rule
                        </Button>
                    )}
                </fieldset>
            </EditorFrame>
            <EditorFrame
                embedded={embedded}
                header={
                    <>
                        <h2 className="text-section-title">
                            Try a routing example
                        </h2>
                        <p className="text-subtle">
                            Uses current staff eligibility and workload. No
                            referral is created and no assignment is changed.
                        </p>
                    </>
                }
                contentClassName="space-y-4"
            >
                <Field label="Example service">
                    <SelectInput
                        placeholder="Choose example service"
                        ariaLabel="Example service"
                        disabled={busy}
                        value={service}
                        options={[
                            { value: 'unsure', label: 'Not sure yet' },
                            ...value.services.map((item) => ({
                                value: item.id,
                                label: item.label,
                            })),
                        ]}
                        onChange={(next) => {
                            setService(next);
                            setResult(null);
                        }}
                    />
                </Field>
                <Field label="Example region">
                    <Input
                        value={region}
                        disabled={busy}
                        maxLength={120}
                        onChange={(event) => {
                            setRegion(event.target.value);
                            setResult(null);
                        }}
                    />
                </Field>
                <Field label="Example funding situation">
                    <SelectInput
                        ariaLabel="Example funding situation"
                        placeholder="Choose funding situation"
                        disabled={busy}
                        value={fundingSituation}
                        options={fundingSituations}
                        onChange={(value) => {
                            setFundingSituation(value);
                            setResult(null);
                        }}
                    />
                </Field>
                <IntakePathwaySelect
                    label="Example reported pathway"
                    disabled={busy}
                    options={pathways}
                    service={service}
                    value={pathway}
                    onChange={(value) => {
                        setPathway(value);
                        setResult(null);
                    }}
                />
                <Button disabled={busy} onClick={() => void simulate()}>
                    {busy ? 'Checking routing…' : 'Preview routing'}
                </Button>
                {error && (
                    <Alert variant="warning">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {result && (
                    <Alert variant="info">
                        <AlertDescription>
                            <p>{result.reason}</p>
                            <p>
                                Team: {result.team}. Owner:{' '}
                                {result.owner_id === null
                                    ? 'Manual assignment needed'
                                    : (staff.find(
                                          (person) =>
                                              person.id === result.owner_id,
                                      )?.name ?? 'Eligible configured owner')}
                                .
                            </p>
                            {result.skipped?.map((message) => (
                                <p key={message}>{message}</p>
                            ))}
                        </AlertDescription>
                    </Alert>
                )}
            </EditorFrame>
        </div>
    );
}

function Owners({
    title,
    selected,
    options,
    onChange,
}: {
    title: string;
    selected: number[];
    options: Option[];
    onChange: (ids: number[]) => void;
}) {
    return (
        <fieldset className="space-y-2">
            <legend className="text-subtle font-medium">{title}</legend>
            <div className="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-border p-3">
                {options.map((person) => (
                    <label key={person.id} className="flex items-center gap-3">
                        <Checkbox
                            checked={selected.includes(person.id)}
                            onCheckedChange={(checked) =>
                                onChange(
                                    checked
                                        ? [...selected, person.id]
                                        : selected.filter(
                                              (id) => id !== person.id,
                                          ),
                                )
                            }
                        />
                        {person.name}
                    </label>
                ))}
                {!options.length && (
                    <p className="text-subtle">
                        No staff available in your current directory scope.
                    </p>
                )}
            </div>
        </fieldset>
    );
}
