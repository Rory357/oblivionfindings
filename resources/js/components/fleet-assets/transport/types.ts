import type { BookingRow } from '../vehicle-workspace/calendar-types';
export type WorkspaceView =
    | 'overview'
    | 'requests'
    | 'planner'
    | 'calendar'
    | 'journeys'
    | 'returns';
export type Person = { id: number; name: string };
export type Filters = {
    from: string;
    to: string;
    site: string;
    search: string;
};
export type TransportRecord = {
    id: number;
    reference: string;
    version: number;
    client_id: number;
    person: string;
    site: Person;
    purpose: string;
    pickup: string;
    destination: string;
    start: string;
    end: string | null;
    stage: string;
    stage_label: string;
    next_action: string;
    next_owner: string;
    required_seats: number | null;
    wheelchair_required: boolean | null;
    escort_required: boolean;
    return_trip: boolean;
    equipment_required: string[];
    operational_notes: string | null;
    information_required: string | null;
    escort: Person | null;
    key_pickup_room_id: number | null;
    key_return_room_id: number | null;
    key_delivery_arrangement: string | null;
    booking: {
        id: number;
        reference: string;
        status: string;
        version: number;
        start: string;
        end: string;
        driver: Person;
        vehicle: Person & { registration: string | null };
        returned_at: string | null;
        odometer_out: number | null;
        odometer_in: number | null;
        condition: string | null;
        approval_route: string;
        decision_reason: string | null;
        purpose: string;
        destination: string | null;
        passengers: number | null;
        notes: string | null;
        pickup_arrangement: string | null;
    } | null;
    journey: {
        id: number;
        status: string;
        departed_at: string;
        arrived_at: string | null;
        accounted_at: string | null;
    } | null;
    return_stage: string;
    return_next_owner: string;
    missing_items: string[];
    keys: {
        action: string;
        room_id: number | null;
        location: string;
        at: string | null;
    } | null;
    can: {
        manage: boolean;
        respond: boolean;
        note: boolean;
        depart: boolean;
        observe: boolean;
        approve: boolean;
    };
    links: {
        client: string;
        site: string;
        request: string;
        booking: string | null;
        journey: string | null;
        vehicle: string | null;
    };
    history?: {
        id: number;
        action: string;
        at: string;
        actor: string;
        message: string;
        items: string[];
    }[];
    rooms?: Person[];
    source_booking?: BookingRow | null;
    source_actions?: Pick<
        BookingRow['can'],
        'approve' | 'decline' | 'checkout' | 'return' | 'cancel'
    > | null;
    handovers?: { id: number; status: string }[];
};
export type PlanVehicle = Person & {
    registration: string;
    seats: number | null;
    wheelchair: boolean;
    fits: boolean;
    readiness: {
        can_proceed: boolean;
        status: string;
        reasons: { code: string; message: string; blocks_decision: boolean }[];
    };
    staff: Person[];
};
export type PlanOptions = { vehicles: PlanVehicle[]; rooms: Person[] };
export type Intent =
    | 'assess'
    | 'information'
    | 'respond'
    | 'cancel'
    | 'note'
    | 'depart'
    | 'arrive'
    | 'account'
    | 'complete'
    | 'receive_items'
    | 'store_keys'
    | 'approve'
    | 'decline'
    | 'out'
    | 'return'
    | 'cancel_booking';
