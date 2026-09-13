type TicketView = {
    key: string;
    label: string;
    operational?: boolean;
    conversation?: boolean;
};

/** Server view keys are stable saved-URL contracts. Counts are scoped canonical totals. */
const views: TicketView[] = [
    { key: 'all_open', label: 'All open' },
    { key: 'unassigned', label: 'No technician' },
    { key: 'unowned', label: 'No accountable owner', operational: true },
    {
        key: 'waiting_requester',
        label: 'Waiting on requester',
        operational: true,
    },
    { key: 'waiting_vendor', label: 'Waiting on vendor', operational: true },
    {
        key: 'waiting_approver',
        label: 'Waiting on approver',
        operational: true,
    },
    { key: 'unmeasured', label: 'SLA unmeasured' },
    { key: 'mine', label: 'Mine' },
    { key: 'owned_by_me', label: 'Owned by me' },
    { key: 'my_team', label: "My team's work" },
    { key: 'breaching', label: 'Breaching soon' },
    { key: 'breached', label: 'Breached' },
    { key: 'awaiting_it', label: 'Awaiting IT', conversation: true },
    { key: 'awaiting_reply', label: 'Awaiting first reply' },
    { key: 'waiting', label: 'All waiting work' },
    { key: 'recently_resolved', label: 'Recently resolved' },
];

export const ticketViewOptions = (
    canManage: boolean,
    conversationReady: boolean,
) =>
    views.filter(
        (view) =>
            (!view.operational || canManage) &&
            (!view.conversation || conversationReady),
    );

export const ticketViewLabel = (
    view: TicketView,
    count: number | null | undefined,
) => (typeof count === 'number' ? `${view.label} (${count})` : view.label);
