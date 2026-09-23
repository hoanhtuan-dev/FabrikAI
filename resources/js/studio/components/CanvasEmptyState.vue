<script setup>
/**
 * CANVAS TRỐNG — MỘT LỜI MỜI NGẮN + HAI LỐI VÀO (2026-09-26 · đợt 38).
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26 — ĐỌC KHỐI NÀY TRƯỚC KHI "KHÔI PHỤC LẠI Ô MÔ TẢ CHO ĐỦ BỘ"]
 * Màn hình này TRƯỚC ĐÂY có nguyên một ô mô tả tạo ảnh: textarea · nút «Tạo ảnh» · ba gợi ý điền nhanh ·
 * hàng chọn biến thể/tỉ lệ · nút xuống dòng · nút ẩn/gọi lại ô mô tả (nhớ trong localStorage). Nay ô đó
 * BỊ GỠ HẲN khỏi đây và việc tạo ảnh CHUYỂN VÀO KHUNG CHAT (components/ChatModal.vue — thẻ «Tạo ảnh» mở
 * một ô mô tả ngay trong chat). Hai lý do THẬT, không phải để gọn mắt:
 *   · BA CHỖ VIẾT MỘT VIỆC — cùng một trường dữ liệu của kho dữ liệu (trường mô tả ảnh, xem
 *     store/actions/generation.js) đã có ô nhập ở card «Tạo ảnh» (ConceptCard) VÀ ở đây; thêm ô trong
 *     chat nữa là BA ô cho MỘT việc. Ba chỗ gõ được thì ba chỗ phải sửa mỗi lần đổi quy ước (biến thể ·
 *     tỉ lệ · phím tắt · cách chèn dòng) — và chúng sẽ lệch nhau.
 *   · CHỖ SAI — ô mô tả chỉ tồn tại KHI CANVAS TRỐNG (StudioApp render nó theo v-if). Vừa có ảnh trên
 *     canvas là mất chỗ viết mô tả cho ảnh tiếp theo, đúng lúc người dùng đã quen tay.
 * KHÔNG mất tính năng nào: bảng Prompt Tạo Ảnh ĐẦY ĐỦ (prefix · negative · phom dáng · mẫu việc) vẫn mở
 * được bằng nút thứ hai ở đây, và việc tạo ảnh nay nằm trong chat với ĐÚNG MỘT đường gọi hàm của kho
 * dữ liệu (generateImage() — đúng đường mà card «Tạo ảnh» đang gọi).
 *
 * [ĐỔI CHÍNH SÁCH CŨ HƠN — VẪN PHẢI GIỮ] Màn hình này TỪNG có thêm một TAB «Trò chuyện». Tab đó đã gỡ
 * vì nó chỉ mở được khi canvas trống, và vì hai khung chat là hai lịch sử mà người dùng không biết tin
 * bản nào. Chat nay là MODAL DÙNG CHUNG (components/ChatModal.vue; lối vào chính là NÚT NỔI
 * components/ChatFab.vue cộng một lệnh trong bảng lệnh). Ở đây chỉ còn ĐÚNG MỘT nút mở modal đó —
 * KHÔNG dựng bản chat thứ hai. KHÔNG thêm lối vào trang Agent Studio ở đây (đó là TRANG riêng, mở từ
 * thanh công cụ; tests/Feature/AgentStudioPageTest.php khoá điều này).
 *
 * Giữ nguyên: z-0 (dưới layer — nếu không nó che hiệu ứng mờ của layer đang tắt).
 */
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
</script>

<template>
  <!-- z-0 (không phải z-20): lớp này nằm DƯỚI các layer. Ẩn layer cuối cùng thì màn hình trống
       hiện ra ngay và không che hiệu ứng mờ của layer đang tắt. -->
  <div class="motion-fade-in absolute inset-0 z-0 overflow-y-auto bg-gradient-to-b from-ink-950/97 via-ink-950/94 to-ink-950/97 p-4 backdrop-blur-sm sm:p-6" role="region" aria-label="Canvas trống">
    <div class="mx-auto flex w-full max-w-xl flex-col items-center gap-3 py-12 text-center sm:py-20">
      <span class="grid h-12 w-12 place-items-center rounded-full bg-brand-600/15 text-brand-300">
        <StudioIcon name="sparkles" size="h-6 w-6" />
      </span>

      <!-- Lời mời NGẮN: nói màn hình này đang trống và việc đầu tiên là gì. KHÔNG liệt kê tính năng —
           danh sách việc nay nằm trong khung chat (thẻ chức năng); chép lại ở đây là bản sao thứ hai. -->
      <p class="text-base font-semibold text-cream-100">Canvas đang trống</p>
      <p class="max-w-md text-body leading-relaxed text-cream-300">
        Mở trợ lý để tả tấm ảnh bạn muốn — bạn viết mô tả ngay trong khung chat rồi bấm Tạo ảnh.
      </p>

      <div class="mt-1 flex flex-wrap items-center justify-center gap-2">
        <!-- Cầu nối sang MODAL TRỢ LÝ: mở ĐÚNG modal dùng chung, KHÔNG dựng khung chat thứ hai ở đây. -->
        <button type="button" class="btn-brand" data-chat-open title="Mở trợ lý thiết kế — hỏi đáp và tạo ảnh ngay trong khung chat" @click="store.chatOpen = true">
          <StudioIcon name="bot" size="h-4 w-4" /> Mở trợ lý &amp; tạo ảnh
        </button>
        <!-- ĐƯỜNG DỰ PHÒNG cho người quen chỉnh kỹ: bảng Prompt Tạo Ảnh đầy đủ (prefix · negative ·
             phom dáng · mẫu việc) vẫn là popup RIÊNG, mở bằng đúng cờ store.promptOpen. -->
        <button type="button" class="tool-btn" data-prompt-panel title="Mở bảng Prompt Tạo Ảnh đầy đủ: prefix, negative, phom dáng, mẫu việc" @click="store.promptOpen = true">
          <StudioIcon name="sliders" size="h-3.5 w-3.5" /> Bảng prompt đầy đủ
        </button>
      </div>
    </div>
  </div>
</template>
