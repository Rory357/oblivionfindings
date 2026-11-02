<?php

namespace App\Domain\Obli\Fixes;

/**
 * S&D Phase I (protocol §3, J and O): every action is checked here before the tray gets it,
 * whatever the model asked for. The tray checks again on the computer (password fields, security
 * windows, secure desktop), so a fault on either side still fails safe.
 *
 * - Coordinates must be inside the picture the tray just sent.
 * - Nothing is typed or pressed while a password field has focus, or a sign-in window is in front
 *   (the session pauses and the person types it themselves).
 * - No action at all while a security window is in front (Windows Security, Firewall, BitLocker,
 *   User Accounts, Local Users and Groups, Group Policy, Registry Editor, and the like).
 * - Keys only from an allow-list: no Windows-key shortcuts, no Ctrl+Alt+Del, no Task Manager or
 *   Run box shortcuts, nothing that sends or closes without asking.
 * - No action at all in a command window or terminal, in the Settings app (Windows Update and
 *   protection live there and its window is only ever called "Settings"), in the Run box, or when the
 *   program in front can't be named; nothing typed into Start search; and no typed text that would
 *   start a program, a script or a settings page from an address bar.
 * - No text, plan step or request that would turn off protection or change who is an administrator.
 */
final class ObliFixSafety
{
    /** The computer-use members the tray can carry out (the rest are switched off in the request). */
    public const MEMBERS = ['screenshot', 'left_click', 'right_click', 'double_click', 'mouse_move', 'scroll', 'type', 'key', 'wait'];

    /** Members switched off in the computer-use toolset (the tray has no such action). */
    public const DISABLED_MEMBERS = ['zoom', 'middle_click', 'triple_click', 'left_click_drag', 'left_mouse_down', 'left_mouse_up', 'cursor_position', 'hold_key'];

    /** The tray's own block list (FixSafety.Blocked) is included, so the two sides agree. */
    private const SECURITY_PROCESSES = [
        'sechealthui.exe', 'securityhealthhost.exe', 'securityhealthsystray.exe', 'regedit.exe', 'regedt32.exe', 'netplwiz.exe',
        'useraccountcontrolsettings.exe', 'bitlockerwizard.exe', 'bitlockerwizardelev.exe', 'fvenotify.exe', 'manage-bde.exe',
        'gpedit.exe', 'secpol.exe', 'lusrmgr.exe', 'wf.exe', 'firewallcontrolpanel.exe', 'credwiz.exe',
        'mmc.exe', 'taskmgr.exe', 'msconfig.exe', 'mrt.exe', 'msmpeng.exe', 'wscript.exe', 'cscript.exe',
        'cmd.exe', 'powershell.exe', 'pwsh.exe', 'powershell_ise.exe',
        // Settings holds Windows Update, protection and accounts, and is only ever titled "Settings".
        'systemsettings.exe', 'systemsettingsadminflows.exe', 'control.exe',
    ];

    /** Start and its search run whatever is typed into them. */
    private const LAUNCHER_PROCESSES = ['searchhost.exe', 'searchapp.exe', 'searchui.exe', 'startmenuexperiencehost.exe', 'shellexperiencehost.exe'];

    /** Typed text that would start a program, a script or a settings page (from Start, Explorer's address bar or a browser). */
    private const LAUNCH_TEXT = '/\b(?:powershell|pwsh|cmd|command|conhost|wt|mshta|rundll32|regsvr32|wscript|cscript|certutil|bitsadmin|msiexec|wmic|schtasks|reg|sc|net|netsh|bcdedit|vssadmin|curl|wget|iwr|invoke-webrequest|start-process)(?:\.exe)?\b(?=\s|$|\/|-)|-e(?:nc(?:odedcommand)?)?\s+[a-z0-9+\/=]{8,}|\b(?:ms-settings|windowsdefender|shell|ms-msdt|search-ms|file|vbscript|javascript):|\.(?:ps1|psm1|bat|cmd|vbs|vbe|js|jse|wsf|hta|scr|msi|lnk|exe|com|reg)\b|\x5c\x5c[^\s\x5c]+\x5c|%[a-z_()]+%/iu';

    private const SIGN_IN_PROCESSES = ['credentialuibroker.exe', 'consent.exe', 'logonui.exe', 'lockapp.exe', 'microsoft.aad.brokerplugin.exe'];

    private const TERMINAL_PROCESSES = ['cmd.exe', 'powershell.exe', 'pwsh.exe', 'powershell_ise.exe', 'windowsterminal.exe', 'wt.exe', 'conhost.exe', 'openconsole.exe', 'wsl.exe', 'bash.exe', 'mintty.exe'];

    private const SECURITY_TITLE = '/windows security|windows defender|virus (?:&|and) threat|firewall|bitlocker|user accounts|local users and groups|group policy|registry editor|user account control|security policy|credential manager|tamper protection|sign-in options|family options|command prompt|windows powershell/iu';

    private const SIGN_IN_TITLE = '/\b(?:sign[\s-]?in|log[\s-]?in|enter (?:your )?password|password required|enter (?:your )?credentials|authentication required|verify (?:your|it\'s) (?:identity|you))\b/iu';

    /** Single keys and shortcuts Obli may press (normalised: modifiers ctrl, alt, shift in that order, then the key). */
    private const KEYS = [
        // Only names the tray knows (Update 2 Nov): ctrl, shift, alt, enter, tab, escape, a-z, 0-9, f2, f5, arrows,
        // home/end/pageup/pagedown, delete, backspace, space.
        'enter', 'escape', 'tab', 'backspace', 'delete', 'space', 'up', 'down', 'left', 'right', 'home', 'end', 'pageup', 'pagedown', 'f2', 'f5',
        'shift+tab', 'shift+up', 'shift+down', 'shift+left', 'shift+right', 'shift+home', 'shift+end',
        'ctrl+a', 'ctrl+c', 'ctrl+v', 'ctrl+x', 'ctrl+z', 'ctrl+y', 'ctrl+f', 'ctrl+p', 'ctrl+s', 'ctrl+n', 'ctrl+o', 'ctrl+t',
        'ctrl+tab', 'ctrl+shift+tab', 'ctrl+home', 'ctrl+end', 'ctrl+l', 'ctrl+0',
    ];

    private const KEY_ALIASES = [
        'return' => 'enter', 'kp_enter' => 'enter', 'esc' => 'escape', 'back_space' => 'backspace', 'del' => 'delete',
        'control' => 'ctrl', 'control_l' => 'ctrl', 'control_r' => 'ctrl', 'ctl' => 'ctrl', 'shift_l' => 'shift', 'shift_r' => 'shift',
        'alt_l' => 'alt', 'alt_r' => 'alt', 'page_up' => 'pageup', 'prior' => 'pageup', 'page_down' => 'pagedown', 'next' => 'pagedown',
        'arrowup' => 'up', 'arrowdown' => 'down', 'arrowleft' => 'left', 'arrowright' => 'right', '+' => 'plus', '=' => 'plus', '-' => 'minus',
    ];

    /** Plain words that mean "weaken protection or change administrators" (O), in requests, plans, commands and typed text. */
    private const UNSAFE = [
        '/\b(?:disable|turn(?:ing)?\s*off|switch(?:ing)?\s*off|stop|uninstall|remove|pause|kill|bypass)\W+(?:\w+\W+){0,2}(?:anti-?virus|defender|virus\s+protection|real-?time\s+protection|tamper\s+protection|malware\s+protection)\b/iu',
        '/\bset-mppreference\b[^\n]*-disable/iu', '/\badd-mppreference\b[^\n]*-exclusion/iu', '/\bdisable(?:antispyware|realtimemonitoring|behaviormonitoring|ioavprotection)\b/iu',
        '/\b(?:disable|turn(?:ing)?\s*off|switch(?:ing)?\s*off|stop|bypass)\W+(?:\w+\W+){0,2}firewall\b/iu', '/\bnetsh\s+(?:adv)?firewall\b[^\n]*\b(?:off|disable)\b/iu', '/\bset-netfirewallprofile\b[^\n]*-enabled\s+(?:false|0|\$false)/iu',
        '/\b(?:disable|turn(?:ing)?\s*off|suspend|decrypt|stop)\W+(?:\w+\W+){0,2}bitlocker\b/iu', '/\bmanage-bde\b[^\n]*(?:-off|-disable|-pause)/iu', '/\b(?:disable|suspend)-bitlocker\b/iu',
        '/\b(?:disable|turn(?:ing)?\s*off|switch(?:ing)?\s*off|stop|pause|block)\W+(?:\w+\W+){0,2}(?:windows\s+)?updates?\b/iu', '/\b(?:stop-service|sc(?:\.exe)?\s+(?:stop|config)|set-service)\b[^\n]*\b(?:wuauserv|usosvc|waasmedicsvc)\b/iu',
        '/\bnet\s+localgroup\s+administrators\b/iu', '/\b(?:add|remove)-localgroupmember\b/iu', '/\bnew-localuser\b/iu', '/\bnet\s+user\b[^\n]*\/(?:add|active)/iu',
        '/\b(?:make|add|give)\W+(?:\w+\W+){0,3}(?:an?\W+)?(?:local\W+)?admin(?:istrator)?s?\b/iu', '/\bchange\W+(?:\w+\W+){0,2}administrators?\b/iu',
        '/\benablelua\b/iu', '/\b(?:disable|turn(?:ing)?\s*off)\W+(?:\w+\W+){0,2}(?:uac|user\s+account\s+control)\b/iu',
        '/\bset-executionpolicy\s+(?:unrestricted|bypass)\b/iu', '/-executionpolicy\s+bypass\b/iu', '/\b(?:invoke-expression|iex)\s*[\(\$]/iu',
        '/\bmimikatz\b|\bsekurlsa\b|\blsass\b|reg(?:\.exe)?\s+save\s+hklm\\\\(?:sam|security|system)\b/iu',
    ];

    /** ok | sign_in | blocked: what is in front of the person right now (protocol J and O). */
    public static function window(array $focus): string
    {
        $process = self::process((string) ($focus['process'] ?? ''));
        $title = (string) ($focus['window_title'] ?? '');
        if (in_array($process, self::SIGN_IN_PROCESSES, true)) {
            return 'sign_in';
        }
        // Fail safe: a window whose program can't be named (often one run as an administrator) is left alone.
        if ($process === '' || in_array($process, self::SECURITY_PROCESSES, true) || in_array($process, self::TERMINAL_PROCESSES, true)) {
            return 'blocked';
        }
        if ($process === 'applicationframehost.exe' && preg_match('/^\s*settings\b/iu', $title)) {
            return 'blocked';
        }
        if ($process === 'explorer.exe' && preg_match('/^\s*run\s*$/iu', $title)) {
            return 'blocked'; // the Run box
        }
        if ($process === 'mmc.exe' && preg_match('/firewall|local users|group policy|security policy|computer management|certificates|bitlocker/iu', $title)) {
            return 'blocked';
        }
        if (preg_match(self::SECURITY_TITLE, $title)) {
            return 'blocked';
        }
        if (preg_match(self::SIGN_IN_TITLE, $title)) {
            return 'sign_in';
        }

        return 'ok';
    }

    public static function isTerminal(array $focus): bool
    {
        return in_array(self::process((string) ($focus['process'] ?? '')), self::TERMINAL_PROCESSES, true);
    }

    /** Start or its search box is in front: typing there runs things. */
    public static function isLauncher(array $focus): bool
    {
        return in_array(self::process((string) ($focus['process'] ?? '')), self::LAUNCHER_PROCESSES, true);
    }

    /** Text that would start a program, a script or a settings page if it reached an address bar or Start. */
    public static function launchText(string $text): bool
    {
        return preg_match(self::LAUNCH_TEXT, $text) === 1;
    }

    public static function unsafeText(string $text): bool
    {
        foreach (self::UNSAFE as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /** Normalised key list for the protocol (["ctrl","p"]), or null when it isn't allowed. */
    public static function keys(string $text): ?array
    {
        $text = strtolower(trim($text));
        if ($text === '' || strlen($text) > 40) {
            return null;
        }
        $parts = $text === '+' ? ['+'] : array_map('trim', explode('+', $text));
        $mods = [];
        $key = null;
        foreach ($parts as $part) {
            $part = self::KEY_ALIASES[$part] ?? $part;
            if (in_array($part, ['ctrl', 'alt', 'shift'], true)) {
                $mods[$part] = true;

                continue;
            }
            if ($key !== null || in_array($part, ['super', 'super_l', 'super_r', 'win', 'windows', 'meta', 'cmd', 'command', 'hyper'], true)) {
                return null;
            }
            $key = $part;
        }
        if ($key === null) {
            return null;
        }
        $normal = array_values(array_filter(['ctrl', 'alt', 'shift'], fn (string $mod): bool => isset($mods[$mod])));
        $normal[] = $key;

        return in_array(implode('+', $normal), self::KEYS, true) ? $normal : null;
    }

    /**
     * Check one computer-use call and turn it into the protocol's action.
     *
     * @param  array<string, mixed>  $input  the tool_use input
     * @param  array{width: int, height: int}  $picture
     * @param  array{is_password: bool, window_title: string, process: string}  $focus
     * @return array{ok: true, action: array<string, mixed>, text: ?string}|array{ok: false, refusal: string, detail: string}
     */
    public static function check(string $member, array $input, array $picture, array $focus): array
    {
        if (! in_array($member, self::MEMBERS, true)) {
            return self::refuse('not_supported', 'That kind of action isn’t available. Use click, double click, right click, move, scroll, type, key or wait.');
        }
        $window = self::window($focus);
        if ($window === 'blocked') {
            return self::refuse('blocked_window', 'A security window is in front. Obli never works in security settings.');
        }
        $point = function () use ($input, $picture): ?array {
            $coordinate = $input['coordinate'] ?? null;
            if (! is_array($coordinate) || ! array_is_list($coordinate) || count($coordinate) !== 2 || ! is_int($coordinate[0]) || ! is_int($coordinate[1])) {
                return null;
            }
            [$x, $y] = $coordinate;

            return $x >= 0 && $y >= 0 && $x < $picture['width'] && $y < $picture['height'] ? ['x' => $x, 'y' => $y] : null;
        };
        $modifiers = isset($input['text']) && is_string($input['text']) && trim($input['text']) !== '';

        switch ($member) {
            case 'screenshot':
                return ['ok' => true, 'action' => ['type' => 'wait', 'seconds' => 1], 'text' => null];
            case 'wait':
                $seconds = is_numeric($input['duration'] ?? null) ? (int) round((float) $input['duration']) : 1;

                return ['ok' => true, 'action' => ['type' => 'wait', 'seconds' => max(1, min(10, $seconds))], 'text' => null];
            case 'left_click':
            case 'right_click':
            case 'double_click':
            case 'mouse_move':
                if ($modifiers) {
                    return self::refuse('not_supported', 'Clicks with keys held down aren’t available.');
                }
                $at = $point();
                if ($at === null) {
                    return self::refuse('outside_picture', 'The point must be whole-pixel coordinates inside the picture ('.$picture['width'].' × '.$picture['height'].').');
                }
                $type = ['left_click' => 'click', 'right_click' => 'right_click', 'double_click' => 'double_click', 'mouse_move' => 'move'][$member];

                return ['ok' => true, 'action' => ['type' => $type, ...$at], 'text' => null];
            case 'scroll':
                if ($modifiers) {
                    return self::refuse('not_supported', 'Scrolling with keys held down isn’t available.');
                }
                $at = $point();
                if ($at === null) {
                    return self::refuse('outside_picture', 'Scroll needs whole-pixel coordinates inside the picture ('.$picture['width'].' × '.$picture['height'].').');
                }
                $direction = $input['scroll_direction'] ?? null;
                if (! in_array($direction, ['up', 'down'], true)) {
                    return self::refuse('not_supported', 'Only up and down scrolling is available.');
                }
                $amount = is_int($input['scroll_amount'] ?? null) ? $input['scroll_amount'] : 3;

                return ['ok' => true, 'action' => ['type' => 'scroll', ...$at, 'direction' => $direction, 'amount' => max(1, min(10, $amount))], 'text' => null];
            case 'type':
                if (($focus['is_password'] ?? true) !== false || $window === 'sign_in') {
                    return self::refuse('password_focus', 'A password or sign-in box has focus. Obli never types there; the person types it.');
                }
                if (self::isTerminal($focus)) {
                    return self::refuse('terminal', 'Obli doesn’t type into command windows. Ask IT (ask_it) for commands.');
                }
                if (self::isLauncher($focus)) {
                    return self::refuse('launcher', 'Obli doesn’t type into Start or its search: it runs what is typed. Open things by clicking instead.');
                }
                $text = $input['text'] ?? null;
                if (! is_string($text) || $text === '' || mb_strlen($text) > 500 || ! mb_check_encoding($text, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $text)) {
                    return self::refuse('bad_text', 'Type plain text of up to 500 characters on one line; press keys separately.');
                }
                if (self::unsafeText($text)) {
                    return self::refuse('unsafe', 'That text would weaken the computer’s protection or change administrators. Obli never does that.');
                }
                if (self::launchText($text)) {
                    return self::refuse('launch', 'That text would start a program, a script or a settings page. Obli doesn’t type those; ask IT (ask_it) if a command is needed.');
                }

                return ['ok' => true, 'action' => ['type' => 'type', 'text' => $text], 'text' => $text];
            case 'key':
                if (($focus['is_password'] ?? true) !== false || $window === 'sign_in') {
                    return self::refuse('password_focus', 'A password or sign-in box has focus. Obli never types there; the person types it.');
                }
                if (self::isTerminal($focus)) {
                    return self::refuse('terminal', 'Obli doesn’t press keys in command windows. Ask IT (ask_it) for commands.');
                }
                if (self::isLauncher($focus)) {
                    return self::refuse('launcher', 'Obli doesn’t press keys in Start or its search. Open things by clicking instead.');
                }
                if (isset($input['repeat']) && $input['repeat'] !== 1) {
                    return self::refuse('not_supported', 'Press one key at a time (no repeat).');
                }
                $keys = is_string($input['text'] ?? null) ? self::keys($input['text']) : null;
                if ($keys === null) {
                    return self::refuse('key_not_allowed', 'That key or shortcut isn’t allowed (no Windows-key shortcuts, Ctrl+Alt+Del, Task Manager, Run box, Alt+F4 or send shortcuts).');
                }

                return ['ok' => true, 'action' => ['type' => 'key', 'keys' => $keys], 'text' => null];
        }

        return self::refuse('not_supported', 'That kind of action isn’t available.');
    }

    private static function process(string $process): string
    {
        $process = strtolower(trim(str_replace('\\', '/', $process)));
        $process = basename($process);
        if ($process !== '' && ! str_ends_with($process, '.exe')) {
            $process .= '.exe';
        }

        return $process;
    }

    /** @return array{ok: false, refusal: string, detail: string} */
    private static function refuse(string $code, string $detail): array
    {
        return ['ok' => false, 'refusal' => $code, 'detail' => $detail];
    }
}
