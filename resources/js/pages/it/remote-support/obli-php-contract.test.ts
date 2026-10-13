import { readFileSync } from 'node:fs';
import { expect, it } from 'vitest';
import { chatResult, diagnosticPreview, obliOptions } from './obli-transport';

const file = process.env.OBLI_TECHNICIAN_CONTRACT;
it.skipIf(!file)(
    'accepts actual isolated PHP DTOs and selected complete/incomplete provider context',
    () => {
        const fixture = JSON.parse(readFileSync(file!, 'utf8'));
        const scope = {
            actorId: fixture.options.actor_id,
            deviceId: fixture.options.device_id,
            ticketId: fixture.options.ticket_id,
        };
        const options = obliOptions(fixture.options, scope);
        const preview = diagnosticPreview(fixture.preview, scope);
        const result = chatResult(fixture.result, fixture.result.request_uuid);
        expect(options.mode).toBe('read_suggest');
        expect(result.status).toBe('completed');
        expect(preview.groups.length).toBeGreaterThan(0);
        const rendered = fixture.rendered_messages
            .map((message: { content: string }) => message.content)
            .join('\n');
        expect(rendered).toContain('Completeness: ');
        expect(rendered).not.toContain('private-hostname');
        expect(rendered).not.toContain('private-publisher');
        for (const group of preview.groups)
            expect(preview.snapshot[group]).toBeDefined();
    },
);
