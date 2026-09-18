/**
 * useDockResize — CƠ SỞ CHUNG cho MỌI dock co/giãn được của Studio.
 *
 * Vì sao có file này: trước đây bề rộng dock là hằng số nằm trong class Tailwind
 * (`w-72`, `w-[156px]`), nên (a) người dùng không tự chỉnh được, (b) mỗi dock muốn "co"
 * lại phải tự viết `v-if` + logic kéo riêng ⇒ hành vi lệch nhau (dock trái nhớ trạng thái,
 * dock phải thì không; bên kéo được, bên không). Nay mọi dock dùng CHUNG một bộ điều khiển:
 *
 *   · KÉO vách ngăn bằng chuột / bút / cảm ứng — có bắt pointer nên kéo lệch ra ngoài
 *     vách ngăn 7px, kéo ra ngoài cửa sổ vẫn dính, và không bôi đen văn bản dọc đường;
 *   · BÀN PHÍM ngay trên vách ngăn: ← → đổi bề rộng (Shift = bước lớn),
 *     Home/End = nhỏ nhất/lớn nhất, Enter/Space = ẩn/hiện dock, Esc = về mặc định;
 *   · NHẤP ĐÚP vách ngăn = về bề rộng mặc định;
 *   · bề rộng luôn bị KẸP trong [min, max]; `max` tự co theo bề rộng cửa sổ để dock
 *     không bao giờ ăn hết chỗ của canvas;
 *   · bề rộng là WRITABLE REF (thường trỏ vào store) ⇒ store vẫn là nguồn sự thật duy nhất,
 *     và chỉ GHI khi thao tác KẾT THÚC (onCommit) — không spam localStorage mỗi pixel kéo.
 *
 * Trả về một object `reactive` (ref đã unwrap) để template viết gọn: dock.panelStyle · dock.resizing.
 */
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { motionDurationMs } from '../motion.js';

/**
 * Thông số chuẩn của từng dock — MỘT chỗ duy nhất để biết dock nào rộng bao nhiêu.
 * (Trước đây ba nơi cùng biết: class Tailwind trong StudioApp, ghi chú trong store, và
 * cảm giác của người kéo.) Mặc định giữ ĐÚNG bề rộng đang dùng để vào trang không thấy lạ.
 */
export const DOCK_PRESETS = {
    left: {
        side: 'left',
        label: 'Bề rộng bảng công cụ bên trái',
        hint: 'Kéo để đổi bề rộng · nhấp đúp để về mặc định · Enter để ẩn/hiện bảng',
        defaultWidth: 288,   // = w-72
        minWidth: 200,
        maxWidth: 560,
        maxViewportFactor: 0.42,   // không chiếm quá 42% bề rộng cửa sổ
    },
    outputs: {
        side: 'right',
        label: 'Bề rộng dock Outputs',
        hint: 'Kéo để đổi bề rộng · nhấp đúp để về mặc định · Enter để ẩn/hiện dock',
        defaultWidth: 156,   // = w-[156px]
        minWidth: 140,
        maxWidth: 420,
        maxViewportFactor: 0.30,
    },
};

const hasDom = typeof window !== 'undefined' && typeof document !== 'undefined';

/** Số dương hữu hạn, nếu không thì lấy mặc định (chặn giá trị rác từ localStorage). */
function positive(value, fallback) {
    const n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : fallback;
}

export function useDockResize(options = {}) {
    const side = options.side === 'right' ? 'right' : 'left';
    /** Nguồn sự thật về bề rộng — writable ref/computed (thường trỏ vào store). */
    const width = options.width;
    /** Cờ thu gọn (nếu dock có) — Enter/Space trên vách ngăn bật tắt qua chính cờ này. */
    const collapsed = options.collapsed || null;

    const defaultWidth = positive(options.defaultWidth, 288);
    const minLimit = positive(options.minWidth, 160);
    const hardMax = Math.max(minLimit + 40, positive(options.maxWidth, 520));
    const viewportFactor = positive(options.maxViewportFactor, 0.4);
    const step = positive(options.step, 16);
    const bigStep = positive(options.bigStep, 48);
    const onCommit = typeof options.onCommit === 'function' ? options.onCommit : null;
    const onSettle = typeof options.onSettle === 'function' ? options.onSettle : null;
    /** id của panel (khớp aria-controls) — dùng để biết focus đang nằm TRONG dock hay không. */
    const panelId = String(options.panelId || '');
    /**
     * Nút MỞ LẠI dock: selector CSS hoặc hàm trả về phần tử. Bắt buộc phải có để không "bỏ rơi"
     * người dùng bàn phím — xem focusWasInsideDock().
     */
    const focusAfterCollapse = options.focusAfterCollapse || null;

    const resizing = ref(false);
    let stopDrag = null;   // hàm gỡ listener của phiên kéo đang chạy
    let settleTimer = 0;

    function viewportWidth() {
        return (hasDom && window.innerWidth) ? window.innerWidth : 1440;
    }
    /** Trần hiện hành = nhỏ hơn giữa trần cứng và trần theo bề rộng cửa sổ. */
    function maxLimit() {
        return Math.max(minLimit + step, Math.round(Math.min(hardMax, viewportWidth() * viewportFactor)));
    }
    function clamp(value) {
        const n = Math.round(Number(value));
        if (!Number.isFinite(n)) return minLimit;
        return Math.max(minLimit, Math.min(maxLimit(), n));
    }

    // Chuẩn hoá giá trị đến từ localStorage / từ màn hình khác: bề rộng rác (0 · 5000px)
    // không được phá bố cục — kẹp NGAY khi dựng, không chờ tới lần kéo đầu tiên.
    const initial = Number(width.value);
    width.value = clamp(Number.isFinite(initial) && initial > 0 ? initial : defaultWidth);

    // Ai GHI bề rộng từ bên ngoài (khôi phục cài đặt sau khi store nạp xong, màn hình đổi
    // kích thước…) đều bị kẹp lại ở đây. So sánh trước khi ghi ⇒ không lặp vô hạn.
    watch(width, (v) => { const c = clamp(v); if (c !== v) width.value = c; });

    /**
     * Hẹn "bố cục đã đứng yên" để nơi dùng ĐO LẠI (canvas · overlay xóa/vẽ bám ảnh).
     * Thời lượng đọc từ chính token CSS (--motion-dur-dock) ⇒ không có hai con số lệch nhau.
     */
    function scheduleSettle() {
        if (!onSettle) return;
        if (settleTimer) clearTimeout(settleTimer);
        const ms = motionDurationMs('dock');
        if (!ms) { onSettle(); return; }   // người dùng bật giảm chuyển động ⇒ co xong tức thì
        // +40ms: frame cuối của transition có thể tới sau mốc thời lượng danh nghĩa.
        settleTimer = setTimeout(() => { settleTimer = 0; onSettle(); }, ms + 40);
    }

    /** Phần tử sẽ nhận focus khi dock bị thu gọn (nút mở lại dock). */
    function reopenTarget() {
        if (!hasDom || !focusAfterCollapse) return null;
        try {
            if (typeof focusAfterCollapse === 'function') return focusAfterCollapse();
            return document.querySelector(focusAfterCollapse);
        } catch (e) {
            return null;
        }
    }

    /**
     * Focus có đang nằm TRONG dock (nút chevron bên trong panel) hoặc trên vách ngăn không?
     *
     * Phải gọi TRƯỚC khi DOM cập nhật: hễ panel bị inert/visibility:hidden là trình duyệt đẩy
     * focus về <body> ngay, và lúc đó KHÔNG còn cách nào biết người dùng vừa đứng ở đâu.
     * (Watcher của Vue chạy trước bước patch DOM nên đọc ở đây là còn nguyên.)
     */
    function focusWasInsideDock() {
        if (!hasDom) return false;
        const active = document.activeElement;
        if (!active || active === document.body) return false;   // focus ở nơi khác → không giành
        const panel = panelId ? document.getElementById(panelId) : null;
        if (panel && panel.contains(active)) return true;
        return !!(active.classList && active.classList.contains('dock-resizer'));
    }

    // Thu gọn có thể do NƠI KHÁC bật (activity bar · bảng lệnh · nút chevron trong panel)
    // ⇒ vẫn phải hẹn đo lại, dù người dùng không hề chạm vào vách ngăn.
    if (collapsed) watch(collapsed, (isCollapsed) => {
        scheduleSettle();
        // ĐỪNG BỎ RƠI NGƯỜI DÙNG BÀN PHÍM: vách ngăn bị gỡ khỏi DOM khi ẩn, còn nút chevron nằm
        // trong panel vừa bị inert — cả hai đều làm mất điểm dừng, người dùng phải Tab mò mới mở
        // lại được dock. Hễ focus đang ở trong dock/vách ngăn thì chuyển nó sang NÚT MỞ LẠI:
        // bấm Enter thêm một lần là quay về đúng trạng thái cũ.
        const wasInside = isCollapsed ? focusWasInsideDock() : false;
        if (!wasInside) return;
        // nextTick: focus chỉ chuyển được SAU khi DOM đã cập nhật (panel đã inert).
        nextTick(() => {
            const target = reopenTarget();
            if (target && typeof target.focus === 'function') target.focus();
        });
    });

    /** Ghi bề rộng + báo cho nơi dùng lưu bền (không ghi nếu giá trị không đổi). */
    function setWidth(value) {
        const next = clamp(value);
        if (next === width.value) return;
        width.value = next;
        if (onCommit) onCommit(next);
    }

    function toggleCollapsed() {
        if (!collapsed) return;
        collapsed.value = !collapsed.value;   // watcher ở trên lo phần hẹn đo lại
    }

    function onPointerDown(e) {
        if (!e || stopDrag) return;                                // đang kéo → bỏ pointer thứ hai
        if (e.pointerType === 'mouse' && e.button !== 0) return;    // chỉ chuột TRÁI
        const startX = e.clientX;
        const startWidth = width.value;
        const handle = e.currentTarget;
        resizing.value = true;
        // Bắt pointer ⇒ sự kiện vẫn về tay ta khi con trỏ rời khỏi vách ngăn 7px.
        try { if (handle && handle.setPointerCapture) handle.setPointerCapture(e.pointerId); } catch (err) { /* trình duyệt cũ */ }
        if (hasDom) document.body.classList.add('is-dock-resizing');

        const move = (ev) => {
            const dx = ev.clientX - startX;
            width.value = clamp(startWidth + (side === 'left' ? dx : -dx));
        };
        const finish = () => {
            if (!stopDrag) return;
            stopDrag();
            stopDrag = null;
            resizing.value = false;
            if (hasDom) document.body.classList.remove('is-dock-resizing');
            if (onCommit) onCommit(width.value);
        };
        stopDrag = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', finish);
            window.removeEventListener('pointercancel', finish);
            window.removeEventListener('blur', finish);
            try {
                if (handle && handle.hasPointerCapture && handle.hasPointerCapture(e.pointerId)) handle.releasePointerCapture(e.pointerId);
            } catch (err) { /* trình duyệt cũ */ }
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', finish);
        window.addEventListener('pointercancel', finish);
        window.addEventListener('blur', finish);
        if (e.cancelable) e.preventDefault();
    }

    function onKeydown(e) {
        if (!e) return;
        // Dock TRÁI rộng ra khi vách ngăn đi sang PHẢI; dock PHẢI thì ngược lại.
        const growKey = side === 'left' ? 'ArrowRight' : 'ArrowLeft';
        const shrinkKey = side === 'left' ? 'ArrowLeft' : 'ArrowRight';
        const px = e.shiftKey ? bigStep : step;
        if (e.key === growKey) setWidth(width.value + px);
        else if (e.key === shrinkKey) setWidth(width.value - px);
        else if (e.key === 'Home') setWidth(minLimit);
        else if (e.key === 'End') setWidth(maxLimit());
        else if (e.key === 'Enter' || e.key === ' ') toggleCollapsed();
        else if (e.key === 'Escape') setWidth(defaultWidth);
        else return;   // phím khác → nhường cho trình duyệt/trợ năng
        e.preventDefault();
    }

    // Cửa sổ đổi kích thước ⇒ trần mềm đổi theo, kẹp lại bề rộng đang có.
    // KHÔNG commit: đổi kích thước cửa sổ không phải thao tác của người dùng, ghi
    // localStorage mỗi lần kéo cửa sổ chỉ tạo rác.
    function onWindowResize() {
        const next = clamp(width.value);
        if (next !== width.value) width.value = next;
    }
    if (hasDom) window.addEventListener('resize', onWindowResize);

    onBeforeUnmount(() => {
        if (hasDom) {
            window.removeEventListener('resize', onWindowResize);
            document.body.classList.remove('is-dock-resizing');
        }
        if (stopDrag) stopDrag();
        if (settleTimer) clearTimeout(settleTimer);
    });

    return reactive({
        side,
        label: String(options.label || 'Bề rộng bảng'),
        hint: String(options.hint || 'Kéo để đổi bề rộng · nhấp đúp để về mặc định'),
        width,                                                   // number
        min: minLimit,
        max: computed(maxLimit),                                 // number — đổi theo cửa sổ
        resizing,                                                // boolean
        collapsed: computed(() => !!(collapsed && collapsed.value)),
        // Bề rộng THẬT đưa vào style: thu gọn thì về 0 ⇒ chính CSS transition của
        // .dock-panel chạy hiệu ứng co, canvas bên cạnh nở ra theo từng frame.
        panelStyle: computed(() => ({ width: (collapsed && collapsed.value ? 0 : width.value) + 'px' })),
        onPointerDown,
        onKeydown,
        reset: () => setWidth(defaultWidth),
        toggle: toggleCollapsed,
    });
}
