import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { formatDateTime } from '@/lib/datetime';
import {
    Activity,
    BookOpenCheck,
    Boxes,
    GitMerge,
    KeyRound,
    LayoutGrid,
    List,
    Network,
    Plus,
    Route,
    UsersRound,
} from 'lucide-react';
import type { Queue, Service, Team } from './_types';
export const SETUP_TABS = [
    { key: 'teams', label: 'Teams', icon: UsersRound },
    { key: 'queues', label: 'Queues', icon: Network },
    { key: 'services', label: 'Services', icon: Boxes },
    { key: 'catalogue', label: 'Catalogue', icon: BookOpenCheck },
    { key: 'provisioning', label: 'Workflows', icon: GitMerge },
    { key: 'api', label: 'API', icon: KeyRound },
    { key: 'operations', label: 'Operations', icon: Activity },
] as const;
export type SetupTab = (typeof SETUP_TABS)[number]['key'];
export function SetupHeader({
    tab,
    onNavigate,
    query,
    onQuery,
    state,
    layout,
    teams,
    queues,
    services,
    generatedAt,
    onCreate,
}: {
    tab: SetupTab;
    onNavigate: (
        tab: SetupTab,
        state?: string,
        layout?: 'cards' | 'table',
        clearQuery?: boolean,
    ) => void;
    query: string;
    onQuery: (value: string) => void;
    state: string;
    layout: 'cards' | 'table';
    teams: Team[];
    queues: Queue[];
    services: Service[];
    generatedAt?: string;
    onCreate?: () => void;
}) {
    const activeTeams = teams.filter((row) => row.is_active).length;
    const activeQueues = queues.filter((row) => row.is_active).length;
    const activeServices = services.filter((row) => row.is_active).length;
    const gaps = queues.filter((row) => row.readiness.gaps.length > 0).length;
    const title = SETUP_TABS.find((item) => item.key === tab)!.label;
    const registers = ['teams', 'queues', 'services'].includes(tab);
    return (
        <PageHeader
            className="overflow-clip!"
            icon={Route}
            title="IT setup"
            subline={
                <>
                    Ownership, routing and service configuration ·{' '}
                    {generatedAt ? (
                        <time dateTime={generatedAt}>
                            {formatDateTime(generatedAt)}
                        </time>
                    ) : (
                        'Snapshot from this page load'
                    )}
                </>
            }
            actions={
                <>
                    <PageHeaderSearch
                        value={query}
                        onChange={onQuery}
                        placeholder={`Search ${title.toLowerCase()}`}
                    />
                    {onCreate && (
                        <PageHeaderPrimaryButton icon={Plus} onClick={onCreate}>
                            New{' '}
                            {tab === 'teams'
                                ? 'team'
                                : tab === 'queues'
                                  ? 'queue'
                                  : 'service'}
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Active teams"
                        onClick={() =>
                            onNavigate('teams', 'active', undefined, true)
                        }
                    >
                        {teams.length ? (
                            <PageHeaderMeterDonut
                                percent={(activeTeams / teams.length) * 100}
                            />
                        ) : (
                            <PageHeaderMeterBig>0</PageHeaderMeterBig>
                        )}
                        <PageHeaderMeterCaption>
                            {teams.length
                                ? `${activeTeams} of ${teams.length} teams`
                                : 'No teams configured'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active queues"
                        onClick={() =>
                            onNavigate('queues', 'active', undefined, true)
                        }
                    >
                        {queues.length ? (
                            <PageHeaderMeterDonut
                                percent={(activeQueues / queues.length) * 100}
                            />
                        ) : (
                            <PageHeaderMeterBig>0</PageHeaderMeterBig>
                        )}
                        <PageHeaderMeterCaption>
                            {queues.length
                                ? `${activeQueues} of ${queues.length} queues`
                                : 'No queues configured'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Routing gaps"
                        tone={gaps ? 'warning' : 'brand'}
                        onClick={() =>
                            onNavigate('queues', 'attention', undefined, true)
                        }
                    >
                        <PageHeaderMeterBig>{gaps}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Queues with configuration gaps
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active services"
                        onClick={() =>
                            onNavigate('services', 'active', undefined, true)
                        }
                    >
                        {services.length ? (
                            <PageHeaderMeterDonut
                                percent={
                                    (activeServices / services.length) * 100
                                }
                            />
                        ) : (
                            <PageHeaderMeterBig>0</PageHeaderMeterBig>
                        )}
                        <PageHeaderMeterCaption>
                            {services.length
                                ? `${activeServices} of ${services.length} services`
                                : 'No services configured'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                registers ? (
                    <>
                        <PageHeaderFilterSelect
                            label="Availability"
                            value={state}
                            onChange={(value) => onNavigate(tab, value, layout)}
                            options={[
                                { value: 'all', label: 'All records' },
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                                {
                                    value: 'attention',
                                    label: 'Configuration needs attention',
                                },
                            ]}
                        />
                        <PageHeaderViewToggle
                            value={layout}
                            onChange={(value) => onNavigate(tab, state, value)}
                            ariaLabel="Setup layout"
                            options={[
                                {
                                    value: 'cards',
                                    label: 'Cards',
                                    icon: LayoutGrid,
                                },
                                { value: 'table', label: 'Table', icon: List },
                            ]}
                        />
                    </>
                ) : undefined
            }
            rail={
                <PageHeaderRail
                    items={[...SETUP_TABS]}
                    value={tab}
                    onSelect={(key) => onNavigate(key)}
                    ariaLabel="Service management setup"
                />
            }
        />
    );
}
