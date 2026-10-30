<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ChatNotificationPreferences;
use PHPUnit\Framework\TestCase;

class ChatNotificationPreferencesTest extends TestCase
{
    public function test_no_role_configuration_defaults_to_in_app_only(): void
    {
        self::assertSame(['enabled' => true, 'inapp' => true, 'email' => false, 'push' => false], ChatNotificationPreferences::resolve(null, []));
    }

    public function test_disabled_role_cannot_enable_a_channel_from_another_role(): void
    {
        $roles = [(object) ['enabled' => false, 'channel_inapp' => true], (object) ['enabled' => true, 'channel_inapp' => false]];
        self::assertSame(['enabled' => true, 'inapp' => false, 'email' => false, 'push' => false], ChatNotificationPreferences::resolve(null, $roles));
        $roles[1]->channel_inapp = true;
        self::assertTrue(ChatNotificationPreferences::resolve(null, $roles)['inapp']);
    }

    public function test_personal_off_wins_and_future_transports_do_not_become_enabled_by_legacy_rows(): void
    {
        $personal = (object) ['enabled' => false, 'channel_inapp' => false, 'channel_email' => true, 'channel_push' => true];
        self::assertSame(['enabled' => false, 'inapp' => false, 'email' => false, 'push' => false], ChatNotificationPreferences::resolve($personal, [(object) ['enabled' => true, 'channel_inapp' => true]]));
    }
}
