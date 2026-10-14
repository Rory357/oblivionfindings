<?php

use App\Domain\Obli\ObliTicketNotificationDispatch;
use App\Jobs\DispatchItTicketNotifications;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);
it('defers canonical notification dispatch until commit and then after the HTTP response', function () {
    $callback = null;
    DB::shouldReceive('afterCommit')->once()->andReturnUsing(function ($action) use (&$callback) {
        $callback = $action;
    });
    Bus::fake();
    ObliTicketNotificationDispatch::schedule(71);
    Bus::assertNothingDispatched();
    $callback();
    Bus::assertDispatchedAfterResponse(DispatchItTicketNotifications::class, fn ($job) => $job->ticketId === 71);
});
it('keeps scheduling and dispatch failure private without invalidating the saved acknowledgement', function (bool $failureAtCommit) {
    Log::shouldReceive('warning')->once()->with($failureAtCommit ? 'Obli ticket notification scheduling deferred.' : 'Obli ticket notification dispatch deferred.');
    if ($failureAtCommit) {
        DB::shouldReceive('afterCommit')->once()->andThrow(new RuntimeException('private scheduling details'));
        ObliTicketNotificationDispatch::schedule(71);
    } else {
        DB::shouldReceive('afterCommit')->once()->andReturnUsing(fn ($callback) => $callback());
        Bus::shouldReceive('dispatchAfterResponse')->once()->andThrow(new RuntimeException('private dispatch details'));
        ObliTicketNotificationDispatch::schedule(71);
    }
    expect(true)->toBeTrue();
})->with([true, false]);
