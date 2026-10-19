<?php

namespace Tests\Feature\Console;

use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\Consumable;
use App\Models\User;
use Tests\TestCase;

class FixMismatchedAssetsAndLogsTest extends TestCase
{
    public function test_checkouts_of_other_item_types_with_the_same_id_are_ignored()
    {
        $holder = User::factory()->create();
        $otherUser = User::factory()->create();
        $asset = Asset::factory()->assignedToUser($holder)->create();

        Actionlog::factory()->create([
            'action_type' => 'checkout',
            'item_type' => Asset::class,
            'item_id' => $asset->id,
            'target_type' => User::class,
            'target_id' => $holder->id,
            'created_at' => now()->subDay(),
        ]);
        Actionlog::factory()->create([
            'action_type' => 'checkout',
            'item_type' => Consumable::class,
            'item_id' => $asset->id,
            'target_type' => User::class,
            'target_id' => $otherUser->id,
            'created_at' => now(),
        ]);

        $this->artisan('snipeit:fix-assets-and-logs')->assertExitCode(0);

        $this->assertSame($holder->id, $asset->fresh()->assigned_to);
    }
}
