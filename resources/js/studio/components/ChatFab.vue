<script setup>
/**
 * NÚT NỔI (FAB) MỞ TRỢ LÝ THIẾT KẾ — góc DƯỚI–PHẢI của VÙNG CANVAS ([2026-09-26] · lần 2).
 *
 * [ĐỔI CHÍNH SÁCH 2026-09-26 — LẦN 2 · ĐỌC KHỐI NÀY TRƯỚC KHI "KHÔI PHỤC LẠI" NÚT Ở THANH TRÊN]
 * Lối vào modal trợ lý TRƯỚC ĐÂY là một nút icon 32px nằm trong cụm công cụ ở <header>
 * (data-header-action="chat"). Nay nó CHUYỂN thành NÚT NỔI ở đây. Ba lý do THẬT, không phải thẩm mỹ:
 *   · CHỖ SAI — cụm công cụ đó ẩn hẳn dưới lg (lg:flex), nên trên điện thoại "nút trợ lý" VỐN KHÔNG
 *     TỒN TẠI; phải bù bằng một mục thứ hai trong menu mobile ⇒ hai lối vào cho cùng một việc, ở hai
 *     chỗ khác nhau, và đó chính là kiểu lệch nhau mà tài liệu §3 cấm. Nút nổi thì có mặt ở MỌI bề
 *     rộng, nên chỉ còn ĐÚNG MỘT lối vào (cộng bảng lệnh — đường dành cho bàn phím, xem bên dưới).
 *   · KHÓ THẤY — "hỏi trợ lý" là việc dùng LIÊN TỤC trong lúc làm việc trên canvas, mà nút 32px nằm
 *     lẫn trong khay 5 nút cùng cỡ thì không có gì nói rằng nó quan trọng. Material dành đúng một
 *     thành phần cho tình huống này: FAB — tròn, to, nổi, ở góc vùng nội dung.
 *   · HỎI ĐƯỢC MỌI LÚC — chat đã tách khỏi màn hình canvas trống (đợt trước), nên lối vào phải luôn
 *     hiện, kể cả khi canvas đã có ảnh dày đặc.
 *
 * VÌ SAO NÚT NÀY KHÔNG NẰM Ở <header>, CŨNG KHÔNG PHẢI position:fixed Ở GÓC MÀN HÌNH
 *   · Neo vào MÀN HÌNH (fixed) là bị che: dock Outputs (store.outputDockOpen) và bảng Layers chiếm
 *     hai mép phải; thanh điều hướng dưới của điện thoại (data-dock-tab) chiếm đáy màn hình. Nút ở
 *     góc màn hình sẽ chui xuống dưới chúng, đúng thứ chủ dự án yêu cầu tránh.
 *   · Neo vào VÙNG CANVAS thì hai mép đó KHÔNG THỂ che được nữa: dock trái, dock Outputs, bảng Layers
 *     đều là anh/em HOẶC lớp phủ nằm NGOÀI phần tử này (xem chú thích ở StudioApp.vue, ngay chỗ
 *     render <ChatFab />).
 *   · KHÔNG dùng thành phần .fab có sẵn của daisyUI (dù app.css có nạp nó): đọc mã nguồn
 *     (node_modules/daisyui/components/fab.css) thì .fab là `position: fixed` ở góc MÀN HÌNH và là
 *     khay speed-dial (các con BUNG RA khi hover/focus). Cả hai tính chất đó đều ngược với yêu cầu
 *     ("góc vùng canvas", "một hành động, không bung menu").
 *
 * VỊ TRÍ — neo bằng bottom/right của CHÍNH khối này, KHÔNG thêm khối CSS riêng cho component (§10).
 * Mốc 0 là ĐÁY VÙNG CANVAS. Ba thứ đang nổi ở đó (đều NẰM GIỮA theo trục ngang), đo TỪ MÃ:
 *   · thanh ngữ cảnh mobile — nằm trong KHUNG canvas (bottom-12 + h-11), trừ thanh trạng thái 40px
 *     (CanvasStatusBar min-h-10) ⇒ 8–52px; chỉ hiện khi đang có công cụ;
 *   · dải biến thể (batch-slider: bottom-14 + py-1.5 + nút h-12) ⇒ 56–116px; chỉ hiện khi có ≥2 biến thể;
 *   · dải công cụ canvas (RegionTools: bottom-16 + nút h-10 + p-1) ⇒ 64–112px; LUÔN hiện dưới lg.
 *   · Dưới lg: bottom-32 · right-3 (128px · 12px), nút 48px ⇒ nằm TRÊN cả ba dải (mép cao nhất 116px)
 *     mà vẫn ở góc dưới–phải. VÌ SAO phải cao như vậy: dưới lg RegionTools luôn hiện và trên máy
 *     320–375px nó rộng ~268px — gần hết bề ngang canvas — nên đặt nút ở 16px là chồng lên nó.
 *   · Từ lg: lg:bottom-4 · lg:right-4 (16px — đúng chuẩn Material) vì hai dải ở đáy đều NẰM GIỮA và
 *     RegionTools đã chuyển thành CỘT DỌC bên trái, tức góc dưới–phải trống.
 * Đây là số ĐO TỪ MÃ, KHÔNG đo bằng trình duyệt: nếu sau này có ai đổi chiều cao nút của ba dải trên
 * (h-10 → h-12 chẳng hạn) thì phải đo lại — chúng là mốc để chọn con số này.
 *
 * ẨN/HIỆN — Material: FAB của một hộp thoại thì BIẾN MẤT khi chính hộp thoại đó đang mở (nếu không,
 * nó nằm chồng lên lớp phủ như một cái nút vô nghĩa). Đó là toàn bộ lý do của `v-if="!store.chatOpen"`.
 *
 * MỘT NGUỒN cho việc "mở chat": nút này KHÔNG tự ghi store.chatOpen. Việc mở chat còn phải ĐÓNG các
 * lớp phủ đang mở (popup prompt · ngăn kéo Outputs · menu mobile · ngăn kéo cài đặt) — chúng là biến
 * CỤC BỘ của StudioApp.vue nên chỉ nơi đó đóng được. Vì vậy: `emit('open')` → StudioApp gọi
 * openChat(). Hai chỗ cùng ghi một cờ là hai chỗ để lệch nhau (§3 "một nguồn chân lý").
 */

import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();
const emit = defineEmits(['open']);
</script>

<template>
  <!-- data-chat-fab: dấu nhận diện cho test (cùng lối với data-chat-open ở CanvasEmptyState.vue).
       (a) state-layer = LỚP TRẠNG THÁI Material (app.css): hover 8% · active 14% bằng currentColor,
           nên MỘT lớp chạy đúng ở cả hai theme — không cần hover:bg-* (mà bg-brand-500 lại ĐỔI SẮC
           theo theme: ở theme tối nó TỐI HƠN brand-600, hover thành ra "chìm").
       (b) absolute của Tailwind THẮNG position:relative của .state-layer vì app.css khai .state-layer
           trong @layer components còn tiện ích nằm ở @layer utilities — đã KIỂM trong CSS ĐÃ BUILD:
           quy tắc .state-layer đứng TRƯỚC .absolute, nên position:absolute của tiện ích thắng.
       (c) shadow-2xl = tầng nổi của Material (§2 "Tầng nổi"); vùng canvas có overflow:hidden nên
           bóng bị cắt vài px ở mép — chấp nhận được vì bóng là vệt mờ, không phải hình khối. -->
  <button
    v-if="!store.chatOpen"
    type="button"
    data-chat-fab
    class="state-layer absolute bottom-32 right-3 z-40 grid h-12 w-12 place-items-center rounded-full bg-brand-600 text-primary-content shadow-2xl lg:bottom-4 lg:right-4 lg:h-14 lg:w-14"
    aria-label="Trợ lý thiết kế"
    title="Trợ lý thiết kế — mở khung chat: hỏi đáp về bộ sưu tập đang làm, câu trả lời dựa trên hồ sơ shop và thông tin trợ lý tự tra"
    @click="emit('open')"
  >
    <!-- Icon `bot` lấy từ icons.json qua StudioIcon (§9) — không svg chép tay, không emoji.
         Cỡ nút: 48px ở màn hẹp và 56px từ lg — đúng chuẩn FAB Material (56 · 48–56), lớn hơn hẳn
         vùng chạm tối thiểu 24×24 của §8, và vẫn nằm trong tầm ngón tay cái. Cỡ ICON thì nhỏ hơn
         cỡ nút (nút 56px + icon 24px = tỉ lệ chuẩn; icon 56px sẽ chạm viền nút). -->
    <StudioIcon name="bot" size="h-5 w-5 lg:h-6 lg:w-6" />
  </button>
</template>
