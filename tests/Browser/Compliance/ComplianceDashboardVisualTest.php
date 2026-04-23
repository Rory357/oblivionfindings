<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Dusk\Browser;

test('compliance dashboard shows the current audit events count in the hero stats', function () {
    $user = User::where('email', 'admin@test.com')->firstOrFail();

    $expectedAuditEvents = AuditLog::query()
        ->when(
            $user->organization_id && Schema::hasColumn('audit_logs', 'organization_id'),
            fn ($query) => $query->where('organization_id', $user->organization_id)
        )
        ->where('created_at', '>=', now()->subDays(30))
        ->count();

    $this->browse(function (Browser $browser) use ($user, $expectedAuditEvents) {
        $browser->loginAs($user)
            ->visit('/compliance')
            ->waitForText('Compliance Dashboard', 10);

        $heroAuditEvents = $browser->script(<<<'JS'
            const stat = Array.from(document.querySelectorAll('div')).find((element) => {
                const children = Array.from(element.children);

                return children.length === 2
                    && children[1]?.textContent?.trim() === 'Audit Events';
            });

            return stat?.children?.[0]?.textContent?.trim() ?? null;
        JS);

        expect($heroAuditEvents[0])->toBe((string) $expectedAuditEvents);
    });
});
