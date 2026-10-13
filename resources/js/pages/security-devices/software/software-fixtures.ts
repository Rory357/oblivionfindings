import type { SoftwareIndex, SoftwarePackage } from './_contracts';
export const actor = 9001,
    device = 9100;
export function release(
    overrides: Partial<SoftwarePackage> = {},
): SoftwarePackage {
    return {
        uuid: '12345678-1234-4234-8234-123456789012',
        version: 1,
        status: 'published',
        status_version: 3,
        name: 'Document viewer',
        publisher: 'Example Software Limited',
        product_code: '{12345678-1234-4234-8234-123456789012}',
        product_version: '4.2.1',
        architecture: 'amd64',
        signer_sha256: 'a'.repeat(64),
        sha256: 'b'.repeat(64),
        size_bytes: 16,
        uploaded_by: { id: 9002, name: 'Taylor Example' },
        reviewed_by: { id: 9003, name: 'Morgan Example' },
        uploaded_at: '2026-10-08T00:00:00Z',
        submitted_at: '2026-10-08T00:01:00Z',
        reviewed_at: '2026-10-08T00:02:00Z',
        published_at: '2026-10-08T00:02:00Z',
        withdrawn_at: null,
        can_submit: false,
        can_review: false,
        can_withdraw: false,
        submit_href: null,
        review_href: null,
        withdraw_href: null,
        show_href:
            '/security-devices/software/12345678-1234-4234-8234-123456789012?expected_actor_id=9001',
        ...overrides,
    };
}
export function catalogue(deviceId: number | null = device): SoftwareIndex {
    return {
        actor_id: actor,
        available: false,
        max_upload_bytes: 536870912,
        can_upload: true,
        upload_href: '/security-devices/software',
        recovery_href_template:
            '/security-devices/software/uploads/{request_key}?expected_actor_id=9001',
        query: { device_id: deviceId, status: 'published', search: '' },
        summary: { published: 1, pending_review: 0, uploaded: 0, withdrawn: 0 },
        pagination: {
            page_key: 'c'.repeat(64),
            limit: 20,
            shown: 1,
            has_more: false,
            previous_href: null,
            next_href: null,
            reset_href:
                '/security-devices/software?expected_actor_id=9001' +
                (deviceId === null ? '' : '&device_id=' + deviceId) +
                '&status=published',
            position_reset: false,
        },
        packages: [release()],
    };
}
