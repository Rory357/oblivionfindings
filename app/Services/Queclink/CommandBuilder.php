<?php

namespace App\Services\Queclink;

use InvalidArgumentException;

/**
 * Builds outbound AT+GTXXX command strings sent to devices.
 *
 * Format:
 *   AT+GT<CMD>=<password>,<arg1>,<arg2>,...,<serial>$
 *
 * Examples:
 *   AT+GTRTO=gv500cg,1,,,,,0001$   - Request current GPS position (RTO sub-command 1)
 *   AT+GTQSS=gv500cg,...,0001$     - Quick start settings
 *   AT+GTSRI=gv500cg,3,,1,<host>,<port>,...,0001$  - Backend server registration
 *
 * Default passwords (factory defaults — operators can change via AT+GTBSI):
 *   - GV500CG family: "gv500cg" (note: full model code, not just "gv500")
 *   - GL30M family:   "gl30m"
 */
class CommandBuilder
{
    public const FAMILY_GV500CG = 'gv500cg';
    public const FAMILY_GL30M = 'gl30m';

    public function __construct(
        protected SerialNumberAllocator $serials,
    ) {
    }

    /**
     * Build a raw command string ready to write to a device socket.
     *
     * @param  string  $commandWord  Without the GT prefix (e.g. "RTO", "FRI", "QSS").
     * @param  array<int|string, mixed>  $args  Positional arguments after the password.
     */
    public function build(string $family, string $commandWord, array $args, ?string $password = null): array
    {
        $commandWord = strtoupper(ltrim($commandWord, 'GT'));
        $password = $password ?? $this->defaultPassword($family);
        $serial = $this->serials->next();

        $parts = [$password];
        foreach ($args as $arg) {
            $parts[] = (string) $arg;
        }
        $parts[] = $serial;

        $raw = sprintf('AT+GT%s=%s$', $commandWord, implode(',', $parts));

        return [
            'command_word' => 'GT' . $commandWord,
            'raw' => $raw,
            'serial' => $serial,
        ];
    }

    /**
     * Pre-canned commands for the debug console one-click buttons.
     */
    public function requestLocation(string $family): array
    {
        // AT+GTRTO=password,1,,,,,serial$ — sub-command 1 = "request location".
        return $this->build($family, 'RTO', [1, '', '', '', '', '']);
    }

    public function reboot(string $family): array
    {
        // AT+GTRTO=password,3,,,,,serial$ — sub-command 3 = "reboot device".
        return $this->build($family, 'RTO', [3, '', '', '', '', '']);
    }

    public function setReportingInterval(string $family, int $seconds): array
    {
        if ($seconds < 5 || $seconds > 86400) {
            throw new InvalidArgumentException('Reporting interval must be 5..86400 seconds.');
        }
        // AT+GTFRI fields (after password), per @Track v5.01 §3.4.1:
        //   1=Mode, 2=DiscardNoFix, 3=CompressedReport, 4=PeriodMode,
        //   5=StartTime, 6=EndTime, 7=CheckInterval, 8=SendInterval,
        //   9=Distance, 10=Mileage, 11=Reserved, 12=CornerValue,
        //   13=IGFReportInterval, 14=ERIMask, 15=ContinueTime,
        //   16=Reserved, 17=WrapCornerPoint
        // Example from the spec:
        //   AT+GTFRI=gv500cg,1,1,,1,0000,0000,0,30,1000,1000,,0,600,00000000,0,,0,FFFF$
        return $this->build($family, 'FRI', [
            1,           // Mode = Fixed Time Report
            1,           // Discard No Fix = disable (avoid bogus 0,0 coords)
            '',          // Compressed Report
            1,           // Period Mode
            '0000',      // Start Time
            '0000',      // End Time
            0,           // Check Interval
            $seconds,    // Send Interval ← the field we actually want to set
            1000,        // Distance
            1000,        // Mileage
            '',          // Reserved
            0,           // Corner Value
            600,         // IGF Report Interval
            '00000000',  // ERI Mask
            0,           // Continue Time
            '',          // Reserved
            0,           // Wrap Corner Point
        ]);
    }

    /**
     * Build a raw AT+ command from a freeform string (debug-console REPL).
     * Validates basic shape and appends terminator if missing.
     */
    public function fromRaw(string $rawInput, string $family): array
    {
        $raw = trim($rawInput);
        if ($raw === '') {
            throw new InvalidArgumentException('Empty command');
        }
        if (! str_starts_with(strtoupper($raw), 'AT+GT')) {
            throw new InvalidArgumentException('Command must start with AT+GT');
        }
        if (! str_contains($raw, '=')) {
            throw new InvalidArgumentException('Command must contain "="');
        }
        if (! str_ends_with($raw, '$')) {
            $raw .= '$';
        }

        $afterEq = substr($raw, strpos($raw, '=') + 1, -1);
        $fields = explode(',', $afterEq);
        $serial = end($fields);
        if (! preg_match('/^[0-9A-F]{4}$/i', $serial)) {
            // Auto-append a serial if missing.
            $serial = $this->serials->next();
            $raw = substr($raw, 0, -1) . ',' . $serial . '$';
        }

        $commandStart = strpos($raw, '+GT') + 3;
        $commandEnd = strpos($raw, '=');
        $commandWord = 'GT' . substr($raw, $commandStart, $commandEnd - $commandStart);

        return [
            'command_word' => $commandWord,
            'raw' => $raw,
            'serial' => strtoupper($serial),
        ];
    }

    protected function defaultPassword(string $family): string
    {
        return match (strtolower($family)) {
            self::FAMILY_GV500CG => 'gv500cg',
            self::FAMILY_GL30M => 'gl30m',
            default => 'gv500cg',
        };
    }
}
