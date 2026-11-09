<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChileTimezoneTest extends TestCase
{
    use DatabaseTransactions;

    public function test_today_follows_chile_when_utc_is_already_tomorrow(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 01:00:00', 'UTC'));

        $this->assertSame('America/Santiago', config('app.timezone'));
        $this->assertSame('2026-11-09', today()->toDateString());
    }

    public function test_database_session_uses_chile_time(): void
    {
        $this->assertSame('America/Santiago', DB::selectOne('show timezone')->TimeZone);
    }

    public function test_migration_converts_stored_utc_timestamps_to_chile(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['created_at' => '2026-11-09 03:00:00']);

        $migration = require database_path('migrations/2026_11_17_000000_convert_timestamps_from_utc_to_chile.php');

        $migration->up();
        $this->assertSame('2026-11-09 00:00:00', DB::table('users')->where('id', $user->id)->value('created_at'));

        $migration->down();
        $this->assertSame('2026-11-09 03:00:00', DB::table('users')->where('id', $user->id)->value('created_at'));
    }

    public function test_api_reports_the_same_instant_after_the_change(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['created_at' => '2026-11-09 00:00:00']);

        $this->assertSame('2026-11-09T03:00:00.000000Z', $user->fresh()->toArray()['created_at']);
    }
}
