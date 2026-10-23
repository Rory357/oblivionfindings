import { ControlRoomPage } from '@/components/control-room/control-room-page';
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
import type {
    ConfigOption,
    ControlRoomSettingsProps,
    MaintenanceWindowData,
    OptionGroup,
    SettingsTab,
    SignalOutboxRow,
    SignalRuleData,
    TriageQueueData,
} from '@/components/control-room/settings/types';
import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

const TABS: SettingsTab[] = [
    'rules',
    'queues',
    'sources',
    'maintenance',
    'signal-outbox',
    'ticket-options',
];

const SUBLINE: Record<SettingsTab, string> = {
    rules: 'How signals become alerts',
    queues: 'Where new alerts wait, and who picks them up',
    sources: 'The systems sending signals, and whether they are reporting',
    maintenance: 'Planned work that should not raise alerts',
    'signal-outbox': 'Signals that did not arrive, to check and try again',
    'ticket-options': 'The lists people pick from on alerts and tasks',
};

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

/** A source is quiet when its heartbeat is over 10 minutes old (or never came). */
function isQuiet(at: string | null): boolean {
    return !at || Date.now() - new Date(at).getTime() > 10 * 60000;
}

/**
 * Control Room › Settings — routing, queues, sources, maintenance, delivery
 * recovery and options, each its own view tab on the header (with
 * Procedures and Response targets). Every change opens a kit pop-up; turning
 * a rule or queue off is confirmed, because it can stop alerts.
 */
export default function ControlRoomSettings({
    activeTab,
    signalRules,
    signalTypes,
    signalSources,
    triageQueues,
    maintenanceWindows,
    signalOutbox,
    playbooks,
    sites,
    configOptions,
}: ControlRoomSettingsProps) {
    const tab: SettingsTab = (TABS as string[]).includes(activeTab)
        ? (activeTab as SettingsTab)
        : 'rules';
    const [open, setOpen] = useState<Open>(null);
    const close = () => setOpen(null);
    const show = (next: SettingsTab) =>
        router.visit(`/control-room/settings?tab=${next}`, { preserveScroll: true });

    const rulesOn = signalRules.filter((rule) => rule.is_active).length;
    const quiet = signalSources.filter(
        (source) => source.status === 'active' && isQuiet(source.last_heartbeat_at),
    ).length;
    const upcoming = maintenanceWindows.filter(
        (mw) => mw.status === 'scheduled' || mw.status === 'active',
    ).length;

    const primary =
        tab === 'rules' ? (
            <PageHeaderPrimaryButton icon={Plus} onClick={() => setOpen({ kind: 'rule', rule: null })}>
                Add rule
            </PageHeaderPrimaryButton>
        ) : tab === 'queues' ? (
            <PageHeaderPrimaryButton icon={Plus} onClick={() => setOpen({ kind: 'queue', queue: null })}>
                Add queue
            </PageHeaderPrimaryButton>
        ) : tab === 'maintenance' ? (
            <PageHeaderPrimaryButton icon={Plus} onClick={() => setOpen({ kind: 'window', window: null })}>
                Schedule maintenance
            </PageHeaderPrimaryButton>
        ) : null;

    return (
        <ControlRoomPage
            header={{
                subline: SUBLINE[tab],
                actions: primary,
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Routing rules"
                            onClick={() => show('rules')}
                            pressed={tab === 'rules'}
                            ariaLabel="Show routing rules"
                        >
                            <PageHeaderMeterBig>{signalRules.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{rulesOn} on</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Sources not reporting"
                            tone={quiet > 0 ? 'warning' : 'brand'}
                            onClick={() => show('sources')}
                            pressed={tab === 'sources'}
                            ariaLabel="Show signal sources"
                        >
                            <PageHeaderMeterBig>{quiet}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                of {signalSources.length} silent over 10 min
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Maintenance"
                            onClick={() => show('maintenance')}
                            pressed={tab === 'maintenance'}
                            ariaLabel="Show maintenance windows"
                        >
                            <PageHeaderMeterBig>{upcoming}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>on now or coming up</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Didn't arrive"
                            tone={signalOutbox.length > 0 ? 'critical' : 'brand'}
                            onClick={() => show('signal-outbox')}
                            pressed={tab === 'signal-outbox'}
                            ariaLabel="Show signals that did not arrive"
                        >
                            <PageHeaderMeterBig>{signalOutbox.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>signals to check</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
            }}
        >
            {tab === 'rules' ? (
                <RoutingView
                    rules={signalRules}
                    onEdit={(rule) => setOpen({ kind: 'rule', rule })}
                    onDelete={(rule) => setOpen({ kind: 'delete-rule', rule })}
                    onTurnOff={(rule) => setOpen({ kind: 'rule-off', rule })}
                />
            ) : null}
            {tab === 'queues' ? (
                <QueuesView
                    queues={triageQueues}
                    onEdit={(queue) => setOpen({ kind: 'queue', queue })}
                    onTurnOff={(queue) => setOpen({ kind: 'queue-off', queue })}
                />
            ) : null}
            {tab === 'sources' ? <SourcesView sources={signalSources} /> : null}
            {tab === 'maintenance' ? (
                <MaintenanceView
                    windows={maintenanceWindows}
                    sites={sites}
                    onEdit={(window) => setOpen({ kind: 'window', window })}
                    onCancel={(window) => setOpen({ kind: 'cancel-window', window })}
                />
            ) : null}
            {tab === 'signal-outbox' ? (
                <DeliveryView rows={signalOutbox} onRetry={(row) => setOpen({ kind: 'retry', row })} />
            ) : null}
            {tab === 'ticket-options' ? (
                <OptionsView
                    options={configOptions}
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
