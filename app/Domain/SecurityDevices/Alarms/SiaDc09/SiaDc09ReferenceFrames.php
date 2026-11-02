<?php

namespace App\Domain\SecurityDevices\Alarms\SiaDc09;

/**
 * Alarm reports from outside this codebase, used to check the alarm receiver (Phase A, item A3).
 *
 * Source: pysiaalarm, the open SIA receiver used by Home Assistant (MIT, Copyright (c) 2021
 * E.A. van Valkenburg), tests/test_sia_package_cases.py and tests/test_utils.py at commit
 * c445c15d618cb67dad9ca210ab0825c44f3466ae. Only frames whose CRC is valid are listed.
 */
final class SiaDc09ReferenceFrames
{
    /** pysiaalarm's published test key; it decrypts the encrypted NULL frame below. */
    public const string TEST_KEY = 'AAAAAAAAAAAAAAAA';

    /**
     * @return list<array{label: string, frame: string, key: string|null}>
     */
    public static function all(): array
    {
        return [
            ['label' => 'Contact ID closing (ADM-CID)', 'frame' => '57150047"ADM-CID"0247R4F39L4F39#4F39[#4F39|3401 01 057]', 'key' => null],
            ['label' => 'SIA user code tamper restored', 'frame' => '97F90078"SIA-DCS"6002L0#AAA[|Nri2/JC12]_12:58:23,09-15-2021', 'key' => null],
            ['label' => 'SIA closing with extra data', 'frame' => '87570078"SIA-DCS"6002L0#AAA[|Nri1/CL501][M0026B9E4268B]_14:12:04,09-25-2019', 'key' => null],
            ['label' => 'SIA closing', 'frame' => 'E5D50078"SIA-DCS"6002L0#AAA[|Nri1/CL501]_14:12:04,09-25-2019', 'key' => null],
            ['label' => 'Encrypted "still here" (NULL)', 'frame' => '76D80055"*NULL"0000R0L0#AAAB[B4BC8B40D0E6D959D6BEA78E88CC0B2155741A3C44FBB96D476A3E557CAD64D9', 'key' => self::TEST_KEY],
            ['label' => 'Encrypted SIA (key not published)', 'frame' => '60AB0078"*SIA-DCS"5994L0#AAA[5AB718E008C616BF16F6468033A11326B0F7546CAB230910BCA10E4DEBA42283C436E4F8EFF50931070DDE36D5BB5F0C', 'key' => null],
            ['label' => 'Encrypted Contact ID (key not published)', 'frame' => 'D0850078"*ADM-CID"6879R0L0#123F[64F7ADD9BA9377DBF2C014BC709329BEEE345B5F20C60B7EB9D2C6B8C222666B13D0B4A114B448B0AC555FCC658DB6EE', 'key' => null],
        ];
    }
}
