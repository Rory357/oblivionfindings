import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    NO_FILTERS,
    ON_OFF_OPTIONS,
    PROBLEM_OPTIONS,
    SILENT_AFTER_MINUTES,
    SOURCE_STATE_OPTIONS,
    WINDOW_STATUS_OPTIONS,
    areaOptions,
    filterOptions,
    filterQueues,
    filterRules,
    filterSources,
    groupRulesByArea,
    movesOn,
    ruleArea,
    severityOptions,
    sourceState,
    type OnOff,
    type SettingsFilterState,
} from '@/components/control-room/settings/settings-filters';
import {
    CancelWindowConfirm,
    DeleteOptionConfirm,
    DeleteRuleConfirm,
    MaintenanceWindowPopup,
    OptionPopup,
    RetryDeliveryConfirm,
    SignalRulePopup,
    TriageQueuePopup,
    TurnOffQueueConfirm,
    TurnOffRuleConfirm,
} from '@/components/control-room/settings/settings-popups';
import {
    DeliveryView,
    MaintenanceView,
    OptionsView,
    QueuesView,
    RoutingView,
    SourcesView,
} from '@/components/control-room/settings/settings-views';
import {
    OPTION_GROUPS,
    type ConfigOption,
    type ControlRoomSettingsProps,
    type MaintenanceWindowData,
    type OptionGroup,
    type SettingsTab,
    type SignalOutboxRow,
    type SignalRuleData,
    type TriageQueueData,
} from '@/components/control-room/settings/types';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    type PageHeaderProps,
} from '@/components/page';
import {
    Activity,
    ArrowRightLeft,
    Gauge,
    Layers,
    ListFilter,
    Plus,
    ToggleRight,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

const TABS: SettingsTab[] = [
    'rules',
    'queues',
    'sources',
    'maintenance',
    'signal-outbox',
    'ticket-options',
];

/** The server lists at most this many deliveries to check (ControlRoomSettingsController). */
const DELIVERY_LIMIT = 100;

type Open =
    | { kind: 'rule'; rule: SignalRuleData | null }
    | { kind: 'delete-rule'; rule: SignalRuleData }
    | { kind: 'rule-off'; rule: SignalRuleData }
    | { kind: 'queue'; queue: TriageQueueData | null }
    | { kind: 'queue-off'; queue: TriageQueueData }
    | { kind: 'window'; window: MaintenanceWindowData | null }
    | { kind: 'cancel-window'; window: MaintenanceWindowData }
    | { kind: 'retry'; row: SignalOutboxRow }
    | { kind: 'option'; group: OptionGroup; title: string }
    | { kind: 'delete-option'; option: ConfigOption; title: string }
    | null;

type Header = Pick<PageHeaderProps, 'subline' | 'actions' | 'meters' | 'filters'>;

/**
 * Control Room › Settings — routing, queues, sources, maintenance, delivery
 * recovery and options, each its own view tab on the header (with
 * Procedures and Response targets). Every tab has the same header shape —
 * one line, four numbers that filter this view, and its own filter row — so
 * the header doesn't jump between tabs. Every change opens a kit pop-up;
 * turning a rule or queue off is confirmed, because it can stop alerts.
 */
export default function ControlRoomSettings(props: ControlRoomSettingsProps) {
    const tab: SettingsTab = (TABS as string[]).includes(props.activeTab)
        ? (props.activeTab as SettingsTab)
        : 'rules';
    // A new tab starts with its filters clear.
    return <SettingsTabPage key={tab} tab={tab} {...props} />;
}

function SettingsTabPage({
    tab,
    signalRules,
    signalTypes,
    signalSources,
    triageQueues,
    maintenanceWindows,
    signalOutbox,
    playbooks,
    sites,
    configOptions,
}: ControlRoomSettingsProps & { tab: SettingsTab }) {
    const [open, setOpen] = useState<Open>(null);
    const [filters, setFilters] = useState<SettingsFilterState>(NO_FILTERS);
    const close = () => setOpen(null);
    const patch = (next: Partial<SettingsFilterState>) =>
        setFilters((current) => ({ ...current, ...next }));
    const clear = () => setFilters(NO_FILTERS);

    const shownRules = filterRules(signalRules, signalTypes, filters);
    const shownQueues = filterQueues(triageQueues, filters);
    const shownSources = filterSources(signalSources, filters);
    const shownWindows = maintenanceWindows.filter(
        (mw) => filters.windowStatus === 'all' || mw.status === filters.windowStatus,
    );
    const shownDeliveries = signalOutbox.filter(
        (row) => filters.problem === 'all' || row.status === filters.problem,
    );
    const shownOptions = filterOptions(configOptions, filters);

    const header: Header = (() => {
        switch (tab) {
            case 'queues':
                return queuesHeader(triageQueues, filters, patch, clear, () =>
                    setOpen({ kind: 'queue', queue: null }),
                );
            case 'sources':
                return sourcesHeader(signalSources, filters, patch, clear);
            case 'maintenance':
                return maintenanceHeader(maintenanceWindows, filters, patch, () =>
                    setOpen({ kind: 'window', window: null }),
                );
            case 'signal-outbox':
                return deliveryHeader(signalOutbox, filters, patch, clear);
            case 'ticket-options':
                return optionsHeader(configOptions, filters, patch);
            default:
                return routingHeader(signalRules, signalTypes, filters, patch, clear, () =>
                    setOpen({ kind: 'rule', rule: null }),
                );
        }
    })();

    return (
        <ControlRoomPage header={header}>
            {tab === 'rules' ? (
                <RoutingView
                    groups={groupRulesByArea(shownRules, signalTypes)}
                    total={signalRules.length}
                    onEdit={(rule) => setOpen({ kind: 'rule', rule })}
                    onDelete={(rule) => setOpen({ kind: 'delete-rule', rule })}
                    onTurnOff={(rule) => setOpen({ kind: 'rule-off', rule })}
                    onClearFilters={clear}
                />
            ) : null}
            {tab === 'queues' ? (
                <QueuesView
                    queues={shownQueues}
                    total={triageQueues.length}
                    onEdit={(queue) => setOpen({ kind: 'queue', queue })}
                    onTurnOff={(queue) => setOpen({ kind: 'queue-off', queue })}
                    onClearFilters={clear}
                />
            ) : null}
            {tab === 'sources' ? (
                <SourcesView sources={shownSources} total={signalSources.length} onClearFilters={clear} />
            ) : null}
            {tab === 'maintenance' ? (
                <MaintenanceView
                    windows={shownWindows}
                    total={maintenanceWindows.length}
                    sites={sites}
                    onEdit={(window) => setOpen({ kind: 'window', window })}
                    onCancel={(window) => setOpen({ kind: 'cancel-window', window })}
                    onClearFilters={clear}
                />
            ) : null}
            {tab === 'signal-outbox' ? (
                <DeliveryView
                    rows={shownDeliveries}
                    total={signalOutbox.length}
                    onRetry={(row) => setOpen({ kind: 'retry', row })}
                    onClearFilters={clear}
                />
            ) : null}
            {tab === 'ticket-options' ? (
                <OptionsView
                    options={shownOptions}
                    allOptions={configOptions}
                    only={filters.optionGroup}
                    onAdd={(group, title) => setOpen({ kind: 'option', group, title })}
                    onDelete={(option, title) => setOpen({ kind: 'delete-option', option, title })}
                />
            ) : null}

            {open?.kind === 'rule' ? (
                <SignalRulePopup
                    rule={open.rule}
                    signalTypes={signalTypes}
                    signalSources={signalSources}
                    playbooks={playbooks}
                    onClose={close}
                />
            ) : null}
            {open?.kind === 'delete-rule' ? <DeleteRuleConfirm rule={open.rule} onClose={close} /> : null}
            {open?.kind === 'rule-off' ? <TurnOffRuleConfirm rule={open.rule} onClose={close} /> : null}
            {open?.kind === 'queue' ? (
                <TriageQueuePopup queue={open.queue} allQueues={triageQueues} onClose={close} />
            ) : null}
            {open?.kind === 'queue-off' ? <TurnOffQueueConfirm queue={open.queue} onClose={close} /> : null}
            {open?.kind === 'window' ? (
                <MaintenanceWindowPopup
                    window={open.window}
                    signalSources={signalSources}
                    sites={sites}
                    onClose={close}
                />
            ) : null}
            {open?.kind === 'cancel-window' ? (
                <CancelWindowConfirm window={open.window} onClose={close} />
            ) : null}
            {open?.kind === 'retry' ? <RetryDeliveryConfirm row={open.row} onClose={close} /> : null}
            {open?.kind === 'option' ? (
                <OptionPopup group={open.group} groupTitle={open.title} onClose={close} />
            ) : null}
            {open?.kind === 'delete-option' ? (
                <DeleteOptionConfirm option={open.option} groupTitle={open.title} onClose={close} />
            ) : null}
        </ControlRoomPage>
    );
}

/* ------------------------------------------------------------------ */
/*  One header per tab — same shape: subline, 4 numbers, filter row    */
/* ------------------------------------------------------------------ */

type Patch = (next: Partial<SettingsFilterState>) => void;

const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

function Meter({
    label,
    value,
    of,
    caption,
    tone,
    pressed,
    onClick,
    href,
    ariaLabel,
}: {
    label: string;
    value: number | string;
    /** A share of the whole view: drawn as a bar, with "3/4" on the head row (DESIGN.md meter forms). */
    of?: number;
    caption: ReactNode;
    tone?: 'brand' | 'success' | 'warning' | 'critical';
    pressed?: boolean;
    ariaLabel: string;
} & ({ onClick: () => void; href?: undefined } | { href: string; onClick?: undefined })) {
    const share = of !== undefined && typeof value === 'number';
    return (
        <PageHeaderMeterBlock
            label={label}
            tone={tone}
            ariaLabel={ariaLabel}
            value={share ? `${value}/${of}` : undefined}
            {...(href ? { href } : { onClick: onClick!, pressed })}
        >
            <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
            {share ? <PageHeaderMeterBar percent={of ? Math.round((Number(value) / of) * 100) : 0} /> : null}
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
}

function OnOffFilter({ value, onChange }: { value: OnOff; onChange: (value: OnOff) => void }) {
    return (
        <PageHeaderFilterSelect
            icon={ToggleRight}
            label="On and off"
            value={value}
            options={ON_OFF_OPTIONS}
            onChange={(next) => onChange(next as OnOff)}
        />
    );
}

function routingHeader(
    rules: SignalRuleData[],
    signalTypes: ControlRoomSettingsProps['signalTypes'],
    filters: SettingsFilterState,
    patch: Patch,
    clear: () => void,
    onAdd: () => void,
): Header {
    const on = rules.filter((rule) => rule.is_active).length;
    const critical = rules.filter((rule) => rule.output_severity === 'critical').length;
    const areas = new Set(rules.map((rule) => ruleArea(rule, signalTypes))).size;
    const narrowed =
        filters.q.trim() !== '' ||
        filters.area !== 'all' ||
        filters.severity !== 'all' ||
        filters.rulesOn !== 'all';
    return {
        subline: 'How signals become alerts, grouped by the area they come from',
        actions: (
            <>
                <PageHeaderSearch
                    value={filters.q}
                    onChange={(q) => patch({ q })}
                    placeholder="Search rules"
                    ariaLabel="Search rules by name, signal type or source"
                />
                <PageHeaderPrimaryButton icon={Plus} onClick={onAdd}>
                    Add rule
                </PageHeaderPrimaryButton>
            </>
        ),
        meters: (
            <>
                <Meter
                    label="Rules"
                    value={rules.length}
                    caption={`in ${plural(areas, 'area')}`}
                    pressed={!narrowed}
                    onClick={clear}
                    ariaLabel="Show every routing rule"
                />
                <Meter
                    label="On"
                    value={on}
                    of={rules.length}
                    caption="routing new signals"
                    pressed={filters.rulesOn === 'on'}
                    onClick={() => patch({ rulesOn: 'on' })}
                    ariaLabel="Show routing rules that are on"
                />
                <Meter
                    label="Off"
                    value={rules.length - on}
                    tone={rules.length - on > 0 ? 'warning' : 'brand'}
                    caption="not routing signals"
                    pressed={filters.rulesOn === 'off'}
                    onClick={() => patch({ rulesOn: 'off' })}
                    ariaLabel="Show routing rules that are off"
                />
                <Meter
                    label="Critical"
                    value={critical}
                    caption="raise critical alerts"
                    pressed={filters.severity === 'critical'}
                    onClick={() => patch({ severity: 'critical' })}
                    ariaLabel="Show rules that raise critical alerts"
                />
            </>
        ),
        filters: (
            <>
                <PageHeaderFilterSelect
                    icon={Layers}
                    label="All areas"
                    value={filters.area}
                    options={areaOptions(rules, signalTypes)}
                    onChange={(area) => patch({ area })}
                />
                <PageHeaderFilterSelect
                    icon={Gauge}
                    label="All severities"
                    value={filters.severity}
                    options={severityOptions(rules)}
                    onChange={(severity) => patch({ severity })}
                />
                <OnOffFilter value={filters.rulesOn} onChange={(rulesOn) => patch({ rulesOn })} />
            </>
        ),
    };
}

function queuesHeader(
    queues: TriageQueueData[],
    filters: SettingsFilterState,
    patch: Patch,
    clear: () => void,
    onAdd: () => void,
): Header {
    const on = queues.filter((queue) => queue.is_active).length;
    const open = queues.reduce((sum, queue) => sum + queue.open_alert_count, 0);
    const moving = queues.filter(movesOn).length;
    return {
        subline: 'Where new alerts wait, and who picks them up',
        actions: (
            <PageHeaderPrimaryButton icon={Plus} onClick={onAdd}>
                Add queue
            </PageHeaderPrimaryButton>
        ),
        meters: (
            <>
                <Meter
                    label="Queues"
                    value={queues.length}
                    caption="set up"
                    pressed={filters.queuesOn === 'all' && filters.moveOn === 'all'}
                    onClick={clear}
                    ariaLabel="Show every queue"
                />
                <Meter
                    label="On"
                    value={on}
                    of={queues.length}
                    caption="taking new alerts"
                    pressed={filters.queuesOn === 'on'}
                    onClick={() => patch({ queuesOn: 'on' })}
                    ariaLabel="Show queues that are on"
                />
                <Meter
                    label="Open alerts"
                    value={open}
                    tone={open > 0 ? 'warning' : 'brand'}
                    caption="waiting in these queues"
                    href="/control-room"
                    ariaLabel="Open the alerts that need action in Live response"
                />
                <Meter
                    label="Move on set"
                    value={moving}
                    caption="pass unpicked alerts on"
                    pressed={filters.moveOn === 'yes'}
                    onClick={() => patch({ moveOn: 'yes' })}
                    ariaLabel="Show queues that move unpicked alerts on"
                />
            </>
        ),
        filters: (
            <>
                <OnOffFilter value={filters.queuesOn} onChange={(queuesOn) => patch({ queuesOn })} />
                <PageHeaderFilterSelect
                    icon={ArrowRightLeft}
                    label="Moves on or stays"
                    value={filters.moveOn}
                    options={[
                        { value: 'all', label: 'Moves on or stays' },
                        { value: 'yes', label: 'Moves alerts on' },
                        { value: 'no', label: 'Alerts stay here' },
                    ]}
                    onChange={(moveOn) => patch({ moveOn: moveOn as SettingsFilterState['moveOn'] })}
                />
            </>
        ),
    };
}

function sourcesHeader(
    sources: ControlRoomSettingsProps['signalSources'],
    filters: SettingsFilterState,
    patch: Patch,
    clear: () => void,
): Header {
    const now = Date.now();
    const count = (state: string) => sources.filter((source) => sourceState(source, now) === state).length;
    const signals = sources.reduce((sum, source) => sum + source.signal_count_24h, 0);
    const silent = count('silent');
    return {
        subline: `The systems sending signals, and whether they are reporting · Silent means no heartbeat for ${SILENT_AFTER_MINUTES} min`,
        meters: (
            <>
                <Meter
                    label="Sources"
                    value={sources.length}
                    caption="connected"
                    pressed={filters.sourceState === 'all'}
                    onClick={clear}
                    ariaLabel="Show every signal source"
                />
                <Meter
                    label="Reporting"
                    value={count('reporting')}
                    of={sources.length}
                    tone={count('reporting') > 0 ? 'success' : 'brand'}
                    caption={`heard in the last ${SILENT_AFTER_MINUTES} min`}
                    pressed={filters.sourceState === 'reporting'}
                    onClick={() => patch({ sourceState: 'reporting' })}
                    ariaLabel="Show sources that are reporting"
                />
                <Meter
                    label={`Silent over ${SILENT_AFTER_MINUTES} min`}
                    value={silent}
                    tone={silent > 0 ? 'warning' : 'brand'}
                    caption="on, but not heard from"
                    pressed={filters.sourceState === 'silent'}
                    onClick={() => patch({ sourceState: 'silent' })}
                    ariaLabel="Show sources that have gone silent"
                />
                <Meter
                    label="Signals in 24 h"
                    value={signals.toLocaleString('en-NZ')}
                    caption="open them in Signals"
                    href="/control-room/devices?activity=recent"
                    ariaLabel="Open the sources with a signal in the last 24 hours"
                />
            </>
        ),
        filters: (
            <PageHeaderFilterSelect
                icon={Activity}
                label="Every source"
                value={filters.sourceState}
                options={SOURCE_STATE_OPTIONS}
                onChange={(sourceState) =>
                    patch({ sourceState: sourceState as SettingsFilterState['sourceState'] })
                }
            />
        ),
    };
}

function maintenanceHeader(
    windows: MaintenanceWindowData[],
    filters: SettingsFilterState,
    patch: Patch,
    onAdd: () => void,
): Header {
    const count = (status: MaintenanceWindowData['status']) =>
        windows.filter((mw) => mw.status === status).length;
    const meter = (
        status: MaintenanceWindowData['status'],
        label: string,
        caption: string,
        ariaLabel: string,
        tone: 'brand' | 'warning' = 'brand',
    ) => (
        <Meter
            label={label}
            value={count(status)}
            tone={tone}
            caption={caption}
            pressed={filters.windowStatus === status}
            onClick={() => patch({ windowStatus: status })}
            ariaLabel={ariaLabel}
        />
    );
    return {
        subline: 'Planned work that should not raise alerts',
        actions: (
            <PageHeaderPrimaryButton icon={Plus} onClick={onAdd}>
                Schedule maintenance
            </PageHeaderPrimaryButton>
        ),
        meters: (
            <>
                {meter('scheduled', 'Scheduled', 'coming up', 'Show scheduled maintenance')}
                {meter(
                    'active',
                    'On now',
                    'alerts held back',
                    'Show maintenance that is on now',
                    count('active') > 0 ? 'warning' : 'brand',
                )}
                {meter('completed', 'Finished', 'in the last 7 days', 'Show finished maintenance')}
                {meter('cancelled', 'Cancelled', 'in the last 7 days', 'Show cancelled maintenance')}
            </>
        ),
        filters: (
            <PageHeaderFilterSelect
                icon={ListFilter}
                label="Every status"
                value={filters.windowStatus}
                options={WINDOW_STATUS_OPTIONS}
                onChange={(windowStatus) =>
                    patch({ windowStatus: windowStatus as SettingsFilterState['windowStatus'] })
                }
            />
        ),
    };
}

function deliveryHeader(
    rows: SignalOutboxRow[],
    filters: SettingsFilterState,
    patch: Patch,
    clear: () => void,
): Header {
    const count = (status: SignalOutboxRow['status']) => rows.filter((row) => row.status === status).length;
    const atLimit = rows.length >= DELIVERY_LIMIT;
    const meter = (status: SignalOutboxRow['status'], label: string, caption: string, ariaLabel: string, tone: 'warning' | 'critical') => (
        <Meter
            label={label}
            value={count(status)}
            tone={count(status) > 0 ? tone : 'brand'}
            caption={caption}
            pressed={filters.problem === status}
            onClick={() => patch({ problem: status })}
            ariaLabel={ariaLabel}
        />
    );
    return {
        subline: 'Signals that did not arrive, to check and try again',
        meters: (
            <>
                <Meter
                    label="To check"
                    value={atLimit ? `${rows.length}+` : rows.length}
                    tone={rows.length > 0 ? 'critical' : 'brand'}
                    caption={atLimit ? `the latest ${DELIVERY_LIMIT} are listed` : "signals that didn't arrive"}
                    pressed={filters.problem === 'all'}
                    onClick={clear}
                    ariaLabel="Show every signal to check"
                />
                {meter('failed', 'Failed', "a try didn't work", 'Show deliveries that failed', 'warning')}
                {meter('dead_letter', 'Gave up', 'after every retry', 'Show deliveries that gave up after retries', 'critical')}
                {meter('unroutable', 'Nowhere to send', 'no rule or queue took it', 'Show deliveries with nowhere to send them', 'critical')}
            </>
        ),
        filters: (
            <PageHeaderFilterSelect
                icon={ListFilter}
                label="Every problem"
                value={filters.problem}
                options={PROBLEM_OPTIONS}
                onChange={(problem) => patch({ problem: problem as SettingsFilterState['problem'] })}
            />
        ),
    };
}

function optionsHeader(
    options: Record<string, ConfigOption[]>,
    filters: SettingsFilterState,
    patch: Patch,
): Header {
    const items = (group: OptionGroup) => options?.[group] ?? [];
    const off = OPTION_GROUPS.reduce(
        (sum, group) => sum + items(group.key).filter((option) => !option.is_active).length,
        0,
    );
    return {
        subline: 'The lists people pick from on alerts and tasks',
        meters: (
            <>
                {OPTION_GROUPS.map((group) => (
                    <Meter
                        key={group.key}
                        label={group.title}
                        value={items(group.key).length}
                        caption={`${items(group.key).filter((option) => option.is_active).length} on`}
                        pressed={filters.optionGroup === group.key}
                        onClick={() => patch({ optionGroup: group.key })}
                        ariaLabel={`Show only ${group.title.toLowerCase()}`}
                    />
                ))}
                <Meter
                    label="Off"
                    value={off}
                    caption="not offered to people"
                    pressed={filters.optionsOn === 'off'}
                    onClick={() => patch({ optionsOn: 'off' })}
                    ariaLabel="Show options that are off"
                />
            </>
        ),
        filters: (
            <>
                <PageHeaderFilterSelect
                    icon={Layers}
                    label="All lists"
                    value={filters.optionGroup}
                    options={[
                        { value: 'all', label: 'All lists' },
                        ...OPTION_GROUPS.map((group) => ({ value: group.key, label: group.title })),
                    ]}
                    onChange={(optionGroup) =>
                        patch({ optionGroup: optionGroup as SettingsFilterState['optionGroup'] })
                    }
                />
                <OnOffFilter value={filters.optionsOn} onChange={(optionsOn) => patch({ optionsOn })} />
            </>
        ),
    };
}
