<?php

namespace App\Console\Commands;

use App\Models\ChatCall;
use App\Services\Chat\ChatCallReconciler;
use App\Services\Chat\SelfHostedCalling;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ReconcileChatCalls extends Command
{
    protected $signature = 'chat:reconcile-calls';

    protected $description = 'Close expired calls and revoke unavailable or unauthorised media participants';

    public function handle(ChatCallReconciler $reconciler, SelfHostedCalling $media): int
    {
        if (! $media->configured() || ! Schema::hasTable('chat_calls')) {
            return self::SUCCESS;
        }
        $failed = false;
        foreach (ChatCall::whereNull('ended_at')->cursor() as $call) {
            try {
                $reconciler->reconcile($call, deferMedia: true);
            } catch (\Throwable $error) {
                report($error);
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
