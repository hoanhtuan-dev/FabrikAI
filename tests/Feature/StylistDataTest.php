<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StylistDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_stylist_data_endpoints_return_seeded_data_for_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@fabrikai.shop')->first();
        $this->assertNotNull($admin, 'admin user should be seeded');
        $this->assertTrue($admin->isAdmin());

        $this->actingAs($admin);

        $data = $this->getJson('/api/stylist-data/data');
        $data->assertOk();
        $data->assertJsonCount(18, 'types');
        $data->assertJsonCount(8, 'questions');

        $types = $this->getJson('/api/stylist/types');
        $types->assertOk();
        $types->assertJsonCount(18, 'types');
        $this->assertArrayHasKey('thumb', $types->json('types.0'));
    }

    public function test_guest_can_read_stylist_data_but_cannot_write(): void
    {
        $this->seed();

        // READ endpoints vẫn công khai (card Trợ lý thiết kế tải được khi chưa login).
        $this->getJson('/api/stylist-data/data')->assertOk();
        $this->getJson('/api/stylist/presets')->assertOk();

        // WRITE endpoints phải bị chặn với guest (401 - chưa đăng nhập).
        $this->postJson('/api/stylist-data/types', [
            'slug' => 'guest-type', 'name' => 'Guest Type', 'emoji' => '👗', 'color' => '#4a7a90',
        ])->assertStatus(401);

        $this->deleteJson('/api/stylist-data/types/1')->assertStatus(401);
        $this->postJson('/api/stylist-data/questions', [
            'key' => 'guest_q', 'q' => 'Guest?', 'opts' => [],
        ])->assertStatus(401);
        $this->deleteJson('/api/stylist-data/questions/1')->assertStatus(401);
        $this->deleteJson('/api/stylist/presets/1')->assertStatus(401);
    }

    public function test_customer_cannot_write_stylist_data(): void
    {
        $this->seed();
        $customer = User::where('email', 'user@fabrikai.shop')->first();
        $this->actingAs($customer);

        $this->postJson('/api/stylist-data/types', [
            'slug' => 'cust-type', 'name' => 'Cust Type', 'emoji' => '👗', 'color' => '#4a7a90',
        ])->assertStatus(403);
    }

    public function test_admin_can_write_stylist_data(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@fabrikai.shop')->first();
        $this->actingAs($admin);

        $this->postJson('/api/stylist-data/types', [
            'slug' => 'admin-type', 'name' => 'Admin Type', 'emoji' => '👘', 'color' => '#559b78',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('stylist_garment_types', ['slug' => 'admin-type']);
    }
}
