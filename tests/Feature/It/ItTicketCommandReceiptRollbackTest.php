<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\It\Services\ItWorkTaskCommandService;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $site = Site::factory()->create();
    $this->actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
    HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $site->id,
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    $this->ticket = ItTicket::factory()->create(['site_id' => $site->id, 'status' => 'open', 'lock_version' => 1]);
});

test('rollback preserves every populated shared receipt outcome before any DDL', function (string $kind) {
    $uuid = (string) Str::uuid();
    if ($kind === 'task cancellation') {
        app(ItWorkTaskCommandService::class)->cancel($this->ticket, $this->actor, 'create', $uuid, $this->actor->id);
    } elseif ($kind === 'comment cancellation') {
        app(ItTicketInteractionService::class)->cancelCommentCommand($this->ticket, $this->actor, $uuid, false);
    } else {
        ItTicketCommandReceipt::query()->create(['actor_user_id' => $this->actor->id, 'channel' => 'browser',
            'operation' => 'task.reorder', 'request_uuid' => $uuid, 'request_hash' => hash('sha256', $uuid),
            'it_ticket_id' => $this->ticket->id,
            'committed_ticket_version' => $kind === 'version only' ? 1 : null,
            'result_metadata' => $kind === 'metadata only' ? ['state' => 'committed', 'changed' => false]
                : ($kind === 'empty metadata' ? [] : null)]);
    }
    $before = ItTicketCommandReceipt::query()->sole()->getRawOriginal();
    expect($this->ticket->comments()->count())->toBe(0)->and($this->ticket->fresh()->next_response_party)->toBeNull();
    $schema = Schema::getFacadeRoot();
    // Even a future broken guard cannot drop isolated test columns: any DDL
    // invocation itself fails this regression before reaching the database.
    Schema::shouldReceive('table')->never();
    try {
        $migration = require database_path('migrations/2026_09_09_000008_add_it_ticket_conversation_commands.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retain recorded conversation responsibility and command receipts.');
    } finally {
        Schema::swap($schema);
    }
    expect(ItTicketCommandReceipt::query()->sole()->getRawOriginal())->toBe($before)
        ->and(Schema::hasColumns('it_ticket_command_receipts', ['result_metadata', 'committed_ticket_version', 'it_ticket_comment_id']))->toBeTrue()
        ->and(Schema::hasColumn('it_tickets', 'next_response_party'))->toBeTrue();
})->with(['task cancellation', 'comment cancellation', 'version only', 'metadata only', 'empty metadata']);
