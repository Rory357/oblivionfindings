<?php

namespace Tests\Feature\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserSiteAccessSqliteCompatibilityTest extends TestCase
{
    private const CONNECTION = 'alert_scope_sqlite';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getSchemaBuilder()->create(
            'control_room_alerts',
            function (Blueprint $table): void {
                $table->id();
                $table->json('context')->nullable();
            },
        );
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    public function test_alert_context_site_expression_executes_on_sqlite(): void
    {
        DB::connection(self::CONNECTION)->table('control_room_alerts')->insert([
            'context' => json_encode([
                'shift_context' => ['site' => ['id' => 42]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $model = (new ControlRoomAlert)->setConnection(self::CONNECTION);
        $query = $model->newQuery();
        $siteAccess = new class extends UserSiteAccessService
        {
            public function contextSiteExpression(Builder $query): string
            {
                return $this->alertContextSiteExpression($query);
            }
        };

        $siteId = $query
            ->selectRaw($siteAccess->contextSiteExpression($query).' AS context_site_id')
            ->value('context_site_id');

        $this->assertSame(42, (int) $siteId);
    }
}
