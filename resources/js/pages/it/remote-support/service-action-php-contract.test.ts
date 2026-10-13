import { existsSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { servicePolicyReply } from './service-action-policy';
const path = process.env.OBLI_ACTION_POLICY_FIXTURE;
describe('actual PHP service action policy', () => {
    it.skipIf(!path || !existsSync(path))(
        'accepts the PHP policy DTO without adapting its fields',
        () => {
            const fixture = JSON.parse(readFileSync(path!, 'utf8'));
            const dto = fixture.policy_reply;
            const parsed = servicePolicyReply(dto, dto.actor_id, dto.device_id);
            expect(parsed).toEqual(dto);
            expect(parsed.catalogue.length).toBeGreaterThan(0);
            expect(parsed.catalogue_digest).toMatch(/^[a-f0-9]{64}$/);
        },
    );
});
