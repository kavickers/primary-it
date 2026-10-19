<?php

namespace Tests\Feature\SqlServer;

use App\Models\Asset;
use App\Models\Location;
use App\Models\ReportTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers App\Services\SqlServer: MySQL behaviors upstream Snipe-IT relies on
 * that SQL Server needs help with.
 */
class SqlServerCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'sqlsrv') {
            $this->markTestSkipped('SQL Server compatibility layer only.');
        }
    }

    public function test_ids_sql_server_cannot_convert_match_like_mysql()
    {
        $user = User::factory()->create();

        $this->assertNull(User::find('does-not-exist'));
        $this->assertNull(User::find('40596803548609346'));
        $this->assertSame($user->id, User::find((string) $user->id)?->id);
        $this->assertTrue(User::where('id', '!=', 'asset_tag')->whereKey($user->id)->exists());
        $this->assertTrue(User::where('id', 'nope')->orWhere('id', $user->id)->exists());
    }

    public function test_string_keyed_tables_compare_ids_normally()
    {
        DB::table('oauth_access_tokens')->insert([
            'id' => 'a1b2c3', 'client_id' => 1, 'revoked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(DB::table('oauth_access_tokens')->where('id', 'a1b2c3')->exists());
    }

    public function test_explicit_ids_can_be_inserted()
    {
        $location = Location::factory()->create(['id' => 4242]);

        $this->assertSame(4242, $location->id);
        $this->assertSame(4242, Location::findOrFail(4242)->id);
        $this->assertGreaterThan(4242, Location::factory()->create()->id);
    }

    public function test_inserts_over_the_parameter_limit_are_chunked()
    {
        $asset = Asset::factory()->create();
        $rows = array_map(fn ($i) => [
            'item_type' => Asset::class, 'item_id' => $asset->id, 'action_type' => 'update', 'note' => "row {$i}",
        ], range(1, 600));

        DB::table('action_logs')->insert($rows);

        $this->assertSame(600, DB::table('action_logs')->where('item_id', $asset->id)->where('action_type', 'update')->count());
    }

    public function test_bigint_ids_are_returned_as_integers()
    {
        $template = ReportTemplate::factory()->create();

        $this->assertIsInt(ReportTemplate::findOrFail($template->id)->id);
    }

    public function test_updating_not_null_columns_to_null_stores_the_implicit_default()
    {
        DB::table('settings')->update([
            'settings.login_remote_user_custom_logout_url' => null,
            'label2_enable' => null,
            'audit_interval' => null,
        ]);

        $settings = DB::table('settings')->first();
        $this->assertSame('', $settings->login_remote_user_custom_logout_url);
        $this->assertSame(0, (int) $settings->label2_enable);
        $this->assertNull($settings->audit_interval);
    }

    public function test_char_settings_are_returned_without_padding()
    {
        $this->settings->set(['digit_separator' => '1.234,56']);

        $this->assertSame('1.234,56', DB::table('settings')->value('digit_separator'));
    }
}
