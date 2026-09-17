<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sổ cái credit — mọi biến động credits_balance phải để lại ĐÚNG MỘT dòng credit_transactions,
 * với amount có dấu và balance_after là số dư SAU giao dịch.
 */
class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(): User
    {
        return User::where('email', 'user@fabrikai.shop')->firstOrFail();
    }

    public function test_spend_writes_ledger_row_with_generation_reference(): void
    {
        $u = $this->customer();
        $this->actingAs($u);
        $before = (int) $u->fresh()->credits_balance;

        $r = $this->postJson('/api/generate', ['prompt' => 'áo sơ mi trắng'])->assertOk();
        $gid = (int) $r->json('items.0.generation_id');

        $tx = CreditTransaction::where('reference_type', 'generation')
            ->where('reference_id', $gid)->where('type', 'spend')->first();

        $this->assertNotNull($tx, 'Tạo ảnh phải ghi một dòng spend.');
        $this->assertSame(-1, (int) $tx->amount);
        $this->assertSame($before - 1, (int) $tx->balance_after);
        $this->assertSame($before - 1, (int) $u->fresh()->credits_balance);
    }

    public function test_refund_writes_ledger_row_and_restores_balance(): void
    {
        $u = $this->customer();
        $this->actingAs($u);
        $before = (int) $u->fresh()->credits_balance;

        $r = $this->postJson('/api/generate', ['prompt' => 'váy đỏ'])->assertOk();
        $gid = (int) $r->json('items.0.generation_id');

        $this->postJson('/api/generations/'.$gid.'/cancel')->assertOk();

        $tx = CreditTransaction::where('reference_type', 'generation')
            ->where('reference_id', $gid)->where('type', 'refund')->first();

        $this->assertNotNull($tx, 'Huỷ ảnh phải ghi một dòng refund.');
        $this->assertSame(1, (int) $tx->amount);
        $this->assertSame($before, (int) $u->fresh()->credits_balance, 'Hoàn credit đưa số dư về đúng ban đầu.');
    }

    public function test_apply_records_signed_amount_and_balance_after(): void
    {
        $u = User::factory()->create();
        $u->forceFill(['credits_balance' => 100])->save();
        $u = $u->fresh();

        $credit = app(CreditService::class);
        $credit->apply($u, 250, 'adjust', ['admin_id' => null, 'note' => 'cộng']);
        $credit->apply($u, -40, 'adjust', ['admin_id' => null, 'note' => 'trừ']);

        $this->assertSame(310, (int) $u->fresh()->credits_balance);

        $rows = CreditTransaction::where('user_id', $u->id)->where('type', 'adjust')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(250, (int) $rows[0]->amount);
        $this->assertSame(350, (int) $rows[0]->balance_after);
        $this->assertSame(-40, (int) $rows[1]->amount);
        $this->assertSame(310, (int) $rows[1]->balance_after);
    }

    public function test_signup_records_initial_balance(): void
    {
        $this->post('/dang-ky', [
            'name' => 'Đăng ký mới',
            'email' => 'ledger-signup@example.com',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
        ]);

        $u = User::where('email', 'ledger-signup@example.com')->firstOrFail();
        $tx = CreditTransaction::where('user_id', $u->id)->where('type', 'signup')->first();

        $this->assertNotNull($tx, 'Đăng ký phải ghi dòng signup cho số dư khởi tạo.');
        $this->assertSame((int) $u->credits_balance, (int) $tx->amount);
        $this->assertSame((int) $u->credits_balance, (int) $tx->balance_after);
    }
}
