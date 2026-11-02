<?php

namespace App\Console\Commands;

use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09FrameDecoder;
use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09ReferenceFrames;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

/**
 * Phase A walk step 3: run the alarm receiver's reading and replies against alarm reports from outside
 * this codebase, and show the result in plain words. Reads nothing from and writes nothing to the
 * database; opens no network port.
 */
final class CheckSiaDc09ReceiverCommand extends Command
{
    protected $signature = 'security-devices:sia-dc09-check';

    protected $description = 'Check the alarm receiver against reference SIA DC-09 alarm reports (no database, no network)';

    public function handle(SiaDc09FrameDecoder $decoder): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = [];
        $problems = 0;

        foreach (SiaDc09ReferenceFrames::all() as $reference) {
            $decoded = $decoder->decode("\n".$reference['frame']."\r", $reference['key']);

            if ($decoded['ok']) {
                $reply = ($decoded['encrypted'] ?? false)
                    ? SiaDc09FrameDecoder::buildEncryptedAck((string) $decoded['sequence'], $decoded['receiver'] ?? null, (string) $decoded['line_prefix'], (string) $decoded['account'], (string) $reference['key'], $now)
                    : SiaDc09FrameDecoder::buildAck((string) $decoded['sequence'], $decoded['receiver'] ?? null, (string) $decoded['line_prefix'], (string) $decoded['account']);
                $rows[] = [$reference['label'], 'Read OK', $decoded['account'], ($decoded['encrypted'] ?? false) ? 'Encrypted ACK' : 'ACK', trim($reply)];
            } elseif (($decoded['reason_code'] ?? '') === 'encryption_key_required') {
                // CRC passed and the envelope was read; only the key is unknown (pysiaalarm doesn't publish it).
                $rows[] = [$reference['label'], 'CRC OK, needs its key', (string) ($decoded['account'] ?? ''), '—', '—'];
            } else {
                $problems++;
                $rows[] = [$reference['label'], 'FAILED: '.$decoded['reason_code'], '', '', ''];
            }
        }

        $this->table(['Alarm report', 'Result', 'Account', 'Reply', 'Reply frame'], $rows);
        $this->line('Reference frames: pysiaalarm (MIT, Copyright (c) 2021 E.A. van Valkenburg), commit c445c15d.');

        if ($problems > 0) {
            $this->error("{$problems} reference report(s) were not read. The alarm receiver is not ready.");

            return self::FAILURE;
        }

        $this->info('Every reference report was read; each readable one got the right reply.');

        return self::SUCCESS;
    }
}
