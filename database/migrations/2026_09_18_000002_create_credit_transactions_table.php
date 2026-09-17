<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sổ cái credit (credit_transactions) — audit trail cho MỌI biến động credits_balance.
     *
     * Nguyên tắc: credits_balance là "số dư", bảng này là "sổ kế toán". Mọi đường cộng/trừ credit
     * (tiêu khi tạo ảnh, hoàn khi lỗi/huỷ, admin cấp/trừ, nạp gói) đều ghi đúng một dòng ở đây với
     * số dư SAU giao dịch. Số âm amount = trừ, số dương = cộng.
     */
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // purchase | grant | spend | refund | adjust | signup | renew
            $table->string('type', 30)->index();
            $table->integer('amount'); // có dấu: + cộng / - trừ
            $table->integer('balance_after');
            $table->string('reference_type', 40)->nullable()->index();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
