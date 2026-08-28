import { describe, expect, it } from 'vitest';

import {
    reconcileTemplateContextId,
    retainedTemplateContextId,
    templateApplyBlockLines,
    templateContextsForClient,
    type TemplateClientOption,
    type TemplateServiceContextOption,
} from './template-dialogs';

const clients: TemplateClientOption[] = [
    { id: 10, name: 'North client', service_context_id: 101, site_id: 1 },
    { id: 20, name: 'South client', service_context_id: 201, site_id: 2 },
    { id: 30, name: 'North client without default', site_id: 1 },
];

const contexts: TemplateServiceContextOption[] = [
    { id: 100, name: 'Organisation wide', site_id: null },
    { id: 101, name: 'North residential', site_id: 1 },
    { id: 102, name: 'North retired', is_active: false, site_id: 1 },
    { id: 201, name: 'South residential', site_id: 2 },
];

describe('roster template Site-context contracts', () => {
    it('offers only global and selected-Client Site contexts', () => {
        expect(
            templateContextsForClient('10', clients, contexts).map(
                (context) => context.id,
            ),
        ).toEqual([100, 101]);
        expect(
            templateContextsForClient('', clients, contexts).map(
                (context) => context.id,
            ),
        ).toEqual([100]);
    });

    it('replaces a stale cross-Site context with the selected Client default', () => {
        expect(
            reconcileTemplateContextId('10', '201', clients, contexts),
        ).toBe('101');
        expect(
            reconcileTemplateContextId('10', '100', clients, contexts),
        ).toBe('100');
        expect(
            reconcileTemplateContextId('30', '102', clients, contexts),
        ).toBe('');
    });

    it('opens a legacy inactive context as blank so it can be corrected', () => {
        expect(
            retainedTemplateContextId('10', '102', clients, contexts),
        ).toBe('');
    });

    it('surfaces structural apply errors with preflight blocks', () => {
        expect(
            templateApplyBlockLines({
                template_shifts:
                    'Each template shift must be linked to a client.',
                preflight_blocks:
                    'Template apply is blocked.\nRow 1 - Staff conflict',
            }),
        ).toEqual([
            'Each template shift must be linked to a client.',
            'Template apply is blocked.',
            'Row 1 - Staff conflict',
        ]);
    });
});
