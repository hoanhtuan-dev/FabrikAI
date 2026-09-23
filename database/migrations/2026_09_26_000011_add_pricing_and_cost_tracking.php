<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LỚP KINH TẾ: giá bán theo MODEL · giá vốn nhà cung cấp · sổ chi phí thật.
     *
     * VÌ SAO CẦN (đo được, xem docs/CREDIT_GOI_VA_LOI_NHUAN.md §3–§5):
     *   · Hôm nay MỌI model đều tốn đúng 1 credit (plans.image_credit_cost), trong khi giá vốn giữa
     *     model rẻ nhất và đắt nhất lệch hơn 300 lần ($0,003/MP → $2,00/video). Hệ quả: ba đường
     *     đang LỖ THẬT (flux-pro/fill 2K −262 %, veo3 −189 %, flux-pro/fill 1K −45 %).
     *   · generations chỉ có credits_cost (KHÁCH trả) — KHÔNG có cột nào ghi TA trả bao nhiêu,
     *     nên câu hỏi "có lãi không" không trả lời được bằng số.
     *
     * BA BẢNG, ba câu hỏi khác nhau:
     *   · model_credit_cost  — BÁN: model này tốn bao nhiêu credit (chủ dự án sửa trong Quản trị).
     *   · provider_price     — VỐN: nhà cung cấp tính bao nhiêu cho một đơn vị.
     *   · provider_usage     — SỔ: từng lần gọi thật đã tốn bao nhiêu (ghi theo TỪNG LẦN GỌI,
     *                          không theo từng ảnh — chuỗi dự phòng có thể tiêu tiền ở 2–3 nhà
     *                          cung cấp cho MỘT ảnh).
     */
    public function up(): void
    {
        // ── BÁN: model → số credit ────────────────────────────────────────────────────────
        // Cố ý TÁCH khỏi plans: giá theo model là chuyện của SẢN PHẨM, không phải của gói.
        // Gói quyết định "bao nhiêu credit"; bảng này quyết định "một việc tốn mấy credit".
        Schema::create('model_credit_cost', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);          // fal | dashscope | gemini
            $table->string('model', 120);            // vd fal-ai/flux-pro/v1/fill
            $table->unsignedInteger('credits');      // số credit khách trả cho MỘT lượt
            // ── PHẠM VI ÁP DỤNG: cần CẢ HAI, vì giá vốn phụ thuộc vào CẢ HAI ──────────────
            //   · độ phân giải: 1K = cạnh dài 1024, 2K = 2048
            //   · tỉ lệ: fal tính tiền theo megapixel **LÀM TRÒN LÊN**, nên 1K 1:1 = 1,049 MP
            //     ⇒ bị tính **2 MP** — GẤP ĐÔI 1K 4:5 (0,839 MP ⇒ 1 MP).
            // Bỏ tỉ lệ đi thì phải lấy giá của tỉ lệ ĐẮT NHẤT cho mọi tỉ lệ, và như vậy ảnh 4:5
            // (tỉ lệ phổ biến nhất của ngành thời trang) bị thu gấp đôi giá vốn thật.
            // Rỗng ('') = "mọi cỡ" / "mọi tỉ lệ" — dùng làm dòng DỰ PHÒNG.
            $table->string('resolution', 8)->default('');
            $table->string('ratio', 8)->default('');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model', 'resolution', 'ratio'], 'model_credit_cost_unique');
        });

        // ── VỐN: nhà cung cấp tính bao nhiêu ─────────────────────────────────────────────
        Schema::create('provider_price', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('model', 120);
            // ĐƠN VỊ TÍNH TIỀN — đây là chỗ dễ sai nhất khi tích hợp:
            //   fal   → megapixel (làm tròn LÊN) hoặc image
            //   qwen  → image (DashScope tính theo ẢNH, KHÔNG theo megapixel)
            $table->string('unit', 16)->default('image');
            $table->decimal('unit_price_usd', 12, 6);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model'], 'provider_price_unique');
        });

        // ── SỔ: từng lần gọi thật ────────────────────────────────────────────────────────
        Schema::create('provider_usage', function (Blueprint $table) {
            $table->id();
            // NULL = lượt không thuộc generation nào (agent text/vision, dịch, phân tích ảnh).
            $table->foreignId('generation_id')->nullable()->constrained('generations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('model', 120);
            // Thứ tự trong chuỗi dự phòng (qwen → custom → flux → gemini). Cần để trả lời được
            // "chuỗi dự phòng đang ngốn bao nhiêu?" — hôm nay KHÔNG trả lời được.
            $table->unsignedTinyInteger('attempt')->default(1);
            // unknown_cost: biết đã gọi nhưng CHƯA chắc đơn giá. Thà thiếu một dòng còn hơn ghi
            // số sai — số sai làm mọi báo cáo sau đó vô nghĩa.
            $table->string('outcome', 16)->default('ok');
            $table->string('unit', 16)->default('image');
            $table->decimal('units', 12, 4)->default(1);           // số megapixel / số ảnh / số giây
            // CHỤP LẠI đơn giá tại thời điểm chạy: sổ kế toán không được đổi khi ta cập nhật bảng
            // giá. (Cùng nguyên tắc với balance_after trong credit_transactions.)
            $table->decimal('unit_price_usd', 12, 6)->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->decimal('fx_rate', 12, 2)->nullable();
            $table->decimal('cost_vnd', 16, 2)->nullable();
            $table->string('fal_request_id', 64)->nullable();
            // metrics.inference_time của fal — SỐ THẬT, dùng để đối chiếu.
            $table->unsignedInteger('inference_ms')->nullable();
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['provider', 'model']);
            $table->index(['generation_id']);
        });

        // ── Giá vốn gộp theo ảnh (đọc nhanh cho báo cáo lợi nhuận) ───────────────────────
        // Giữ ở generations để trang Thư viện/báo cáo không phải JOIN provider_usage mỗi dòng.
        // Đây là BẢN SAO của tổng provider_usage; nguồn sự thật vẫn là provider_usage.
        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('cost_vnd', 16, 2)->nullable()->after('credits_cost');
        });

        // ── GIỚI HẠN TẠO ẢNH theo gói (0 = không giới hạn) ───────────────────────────────
        // Vì sao cần dù đã có credit: credit chặn TỔNG chi tiêu, nhưng không chặn một tài khoản
        // đốt sạch credit trong 10 phút rồi bỏ — và cũng không chặn tài khoản free (100 credit
        // tặng) tạo 100 ảnh trong một phiên. Trần theo NGÀY là công cụ chống lạm dụng rẻ nhất.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('daily_image_limit')->default(0)->after('video_credit_cost');
        });

        // ── ĐỔ DỮ LIỆU CHO CÀI ĐẶT ĐANG CHẠY ─────────────────────────────────────────────
        // VÌ SAO Ở MIGRATION chứ không chỉ ở Seeder: seeder KHÔNG chạy ở mọi lần deploy, mà bảng
        // giá mới phải có hiệu lực ngay. Đây đúng là bài học đã ghi trong PlanSeeder (lần thêm cột
        // `seats`): khai ở cả hai chỗ, vì migrate fresh thì bảng rỗng còn deploy thật thì bảng có dữ liệu.
        //
        // CHỈ NÂNG, KHÔNG HẠ: không lấy mất thứ khách đang có. Gói nào đã được chủ dự án đặt số cao
        // hơn thì giữ nguyên (điều kiện `<`).
        foreach (['starter' => 155, 'pro' => 405, 'studio' => 1240] as $slug => $credits) {
            DB::table('plans')->where('slug', $slug)
                ->where('credits_per_month', '<', $credits)
                ->update(['credits_per_month' => $credits]);
        }

        // Trần tạo ảnh/ngày — chỉ đặt khi CHƯA có (0 = chưa cấu hình), để không đè số chủ dự án đã đặt.
        foreach (['free' => 20, 'starter' => 60, 'pro' => 150, 'studio' => 400, 'factory_season' => 400] as $slug => $limit) {
            DB::table('plans')->where('slug', $slug)
                ->where('daily_image_limit', 0)
                ->update(['daily_image_limit' => $limit]);
        }

        // ── ⚠️ GÓI "Xưởng theo vụ" — CHỖ DUY NHẤT PHẢI SỬA, và đã được test bất biến BẮT ĐƯỢC ──
        // Đo được: 3.290.000 ₫ ÷ 3.000 credit = **1.096,7 ₫/credit** ⇒ biên ở dòng đắt nhất
        // (video kling, 700 ₫/credit) chỉ còn **36,2 %** — DƯỚI ngưỡng 40 % đã chốt.
        //
        // Vì sao KHÔNG sửa bằng cách giảm credit: 3.000 credit/vụ là LỜI HỨA trên trang giá, và
        // giảm nó là lấy đi thứ đã hứa với khách đang trả tiền. Tăng giá chỉ ảnh hưởng lượt mua SAU.
        //
        // Ngưỡng: giá ÷ credit ≥ 1.200 ₫  ⇔  3.000 × 1.200 = **3.600.000 ₫**.
        // Đây là mức +9,4 %, nằm trong mức "tăng giá 15–20 %" đã dự liệu ở PRICING.md §4.
        //
        // PHƯƠNG ÁN KHÁC nếu chủ dự án muốn giữ giá 3.290.000: đặt credits_per_month = 2.740
        // (3.290.000 ÷ 2.740 = 1.200,7 ₫/credit) — đổi MỘT con số ở đây là xong, không phải sửa mã.
        // CHỈ sửa khi giá vẫn đúng con số cũ ⇒ không đè quyết định mới hơn của chủ dự án.
        DB::table('plans')->where('slug', 'factory_season')
            ->where('price_vnd', 3290000)
            ->update(['price_vnd' => 3600000]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('daily_image_limit');
        });
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('cost_vnd');
        });
        Schema::dropIfExists('provider_usage');
        Schema::dropIfExists('provider_price');
        Schema::dropIfExists('model_credit_cost');
    }
};
