export interface Agent {
    id: number;
    name: string;
}
export interface RoutingAgent extends Agent {
    site_ids: number[];
    organisation_wide: boolean;
}
export interface TeamMember extends Agent {
    role: string;
}
export interface WorkloadTeam {
    open_tickets: number;
    open_tasks: number;
    queues: number;
    members: number;
}
export interface Team {
    configuration_version?: string;
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    manager: Agent | null;
    members: TeamMember[];
    workload: WorkloadTeam;
}
export interface QueueRules {
    routing_priority?: number;
    is_default?: boolean;
    work_types?: string[];
    categories?: string[];
    priorities?: string[];
    service_ids?: number[];
    site_ids?: number[];
    default_assignee_user_id?: number | null;
    cover_user_id?: number | null;
}
export interface Queue {
    configuration_version: string;
    id: number;
    key: string;
    name: string;
    description: string | null;
    is_active: boolean;
    team: Agent | null;
    filter_rules: QueueRules;
    readiness: {
        ready: boolean;
        gaps: string[];
        accountable_owner: Agent | null;
        cover: Agent | null;
    };
    workload: { open_tickets: number; unassigned: number; sla_risk: number };
}
export interface Service {
    configuration_version?: string;
    id: number;
    key: string;
    name: string;
    description: string | null;
    is_active: boolean;
    status: string;
    criticality: string;
    owner: Agent | null;
    workload: { open_tickets: number; sla_risk: number };
}
