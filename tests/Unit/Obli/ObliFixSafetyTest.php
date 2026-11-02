<?php

use App\Domain\Obli\Fixes\ObliFixSafety;

/*
 * S&D Phase I: the server's own checks on each action, whatever the model asked for. These are the
 * ways round the rules an independent review found: Start search and the Settings app with clicks,
 * terminals, the Run box, a window with no program name, and typed text that starts something.
 */

$picture = ['width' => 800, 'height' => 600];
$focus = fn (string $process, string $title = 'Window', bool $password = false): array => ['is_password' => $password, 'window_title' => $title, 'process' => $process];

it('does nothing in Settings, terminals, the Run box or a window with no program name', function () use ($picture, $focus): void {
    foreach ([['SystemSettings.exe', 'Settings'], ['ApplicationFrameHost.exe', 'Settings'], ['WindowsTerminal.exe', 'Ubuntu'], ['conhost.exe', 'C:\\Windows'], ['explorer.exe', 'Run'], ['', 'Anything']] as [$process, $title]) {
        expect(ObliFixSafety::check('left_click', ['coordinate' => [5, 5]], $picture, $focus($process, $title)))
            ->toMatchArray(['ok' => false, 'refusal' => 'blocked_window']);
    }
    expect(ObliFixSafety::check('left_click', ['coordinate' => [5, 5]], $picture, $focus('notepad.exe', 'Untitled - Notepad'))['ok'])->toBeTrue();
});

it('never types or presses keys in Start or its search', function () use ($picture, $focus): void {
    expect(ObliFixSafety::check('type', ['text' => 'notepad'], $picture, $focus('SearchHost.exe', 'Search')))->toMatchArray(['ok' => false, 'refusal' => 'launcher'])
        ->and(ObliFixSafety::check('key', ['text' => 'Return'], $picture, $focus('StartMenuExperienceHost.exe', 'Start')))->toMatchArray(['ok' => false, 'refusal' => 'launcher']);
});

it('never types text that would start a program, a script or a settings page', function () use ($picture, $focus): void {
    foreach (['powershell -w h -enc SQBFAFgA', 'cmd /c del x', 'ms-settings:windowsupdate', 'C:\\temp\\run.bat', 'mshta http://x', '%appdata%', '\\\\server\\share\\x', 'notepad.exe'] as $text) {
        expect(ObliFixSafety::check('type', ['text' => $text], $picture, $focus('explorer.exe', 'Documents')))->toMatchArray(['ok' => false]);
    }
    foreach (['HP LaserJet M404', 'Printer won’t print', 'https://intranet.example.test/printers', 'internet is slow', 'Send 100% of it'] as $text) {
        expect(ObliFixSafety::check('type', ['text' => $text], $picture, $focus('notepad.exe', 'Untitled - Notepad'))['ok'])->toBeTrue();
    }
});
