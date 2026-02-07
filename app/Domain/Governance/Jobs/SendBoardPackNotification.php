<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Notifications\BoardPackPublishedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBoardPackNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public BoardPack $pack,
        public BoardMember $boardMember
    ) {}

    public function handle(): void
    {
        $this->boardMember->user->notify(new BoardPackPublishedNotification($this->pack));
    }
}
