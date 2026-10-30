import { expect, it } from 'vitest';
import {
    confirmsRoutingReceipt,
    INCIDENT_ROUTING_KEY,
    type RoutingCommand,
} from './incident-routing';

const input: RoutingCommand = {
    configuration: { schema_version: 1, routes: [] },
    expected_revision: 4,
    request_uuid: 'ed3a3e55-5f84-4b8d-bf36-b5424a90578d',
    reason: 'Explicit configuration review',
};
const receipt = {
    accepted: true,
    setting_key: INCIDENT_ROUTING_KEY,
    request_uuid: input.request_uuid,
    revision: 5,
    actor_id: 7,
    action: 'kept',
    configuration_digest: 'a'.repeat(64),
};

it('accepts only the original actor-bound routing command and next revision', () => {
    expect(confirmsRoutingReceipt(receipt, input, 7)).toBe(true);
    for (const changed of [
        { accepted: false },
        { request_uuid: 'another-command' },
        { setting_key: 'healthSafety.restraints.plan_approval_policy' },
        { revision: 4 },
        { actor_id: 99 },
        { action: 'pending' },
        { configuration_digest: 'not-a-proof' },
    ]) {
        expect(
            confirmsRoutingReceipt({ ...receipt, ...changed }, input, 7),
        ).toBe(false);
    }
});

it('does not treat a missing or malformed result as a saved configuration', () => {
    for (const unknown of [null, undefined, {}, [], 'saved'])
        expect(confirmsRoutingReceipt(unknown, input, 7)).toBe(false);
    expect(
        confirmsRoutingReceipt(
            receipt,
            { ...input, expected_revision: NaN },
            7,
        ),
    ).toBe(false);
});
