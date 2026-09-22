// ═══════════════════════════════════════════════════════════════════════════════════════
// HỎI ĐÁP THEO LUỒNG của Agent Studio (2026-09-26) — miền "chat".
//
// Vì sao tách thành module riêng thay vì nhét vào agentStudio.js: đây là đường DUY NHẤT trong repo đọc
// luồng NDJSON của một HỘI THOẠI, và nó có ba thứ dễ hỏng mà không miền nào khác phải lo:
//   · vòng đọc luồng (chữ về TỪNG MẢNH, phải nối đúng thứ tự);
//   · DỪNG giữa lượt — giữ phần chữ đã nhận;
//   · giữ lỗi kỹ thuật ngoài màn hình (docs/DESIGN_SYSTEM.md §6).
//
// HỢP ĐỒNG MÁY CHỦ (POST /api/design-agent/chat/stream — KHÔNG được đoán thêm):
//   · đầu vào {"messages":[{"role":"user"|"assistant","content":"…"}],"region":"all"} — trần số lượt và
//     trần ký tự MỖI LƯỢT do máy chủ giữ; chỉ hai vai user/assistant, vai khác là 422;
//   · trả về NDJSON, mỗi dòng MỘT object: phase · tool · tool_result · token · citation · provider ·
//     result · error;
//   · lỗi TRƯỚC khi mở luồng vẫn là JSON thường (422 sai đầu vào · 401 hết phiên · 403 thiếu gói).
// ═══════════════════════════════════════════════════════════════════════════════════════
import { apiError, safeMessage, userFacingError, CSRF } from '../helpers.js';

/**
 * TRẦN CỦA HỢP ĐỒNG — chép đúng hai con số của máy chủ.
 *
 * Vì sao chặn ở client dù máy chủ đã chặn: câu 422 giữa lúc đang gõ là câu khó hiểu (nó nói về trường
 * dữ liệu), còn nói TRƯỚC thì người dùng biết ngay phải làm gì. Máy chủ VẪN là bên phán quyết: mọi 422
 * vẫn được hiện nguyên văn câu hướng dẫn của nó, hai con số ở đây chỉ để báo sớm.
 */
const MAX_TURNS = 12;        // tổng số lượt gửi lên trong MỘT lần hỏi (đã tính cả câu hỏi mới)
const MAX_TURN_CHARS = 4000; // trần ký tự của MỘT lượt

/**
 * AbortController để bấm «Dừng» — giữ ở BIẾN CẤP MODULE, KHÔNG nhét vào state của Pinia.
 *
 * Vì sao: state của Pinia phải TUẦN TỰ HOÁ ĐƯỢC (nó là ảnh chụp trạng thái, có lúc đi vào bản lưu và
 * vào bộ đệm). AbortController là đối tượng SỐNG gắn với một request đang mở — nhét vào state là mang
 * theo một thứ không đọc lại được ở lần tải sau.
 */
let chatAbort = null;

export const agentChatActions = {
    /**
     * GỬI MỘT CÂU HỎI và đọc câu trả lời theo luồng.
     *
     * Trả về true khi câu hỏi ĐÃ ĐI (kể cả khi máy chủ trả lời lỗi giữa luồng), false khi chưa gửi được
     * (ô rỗng · quá dài · quá số lượt · mất mạng) — nơi gọi dùng giá trị này để QUYẾT ĐỊNH CÓ XOÁ ô nhập
     * hay không, nên nó phải nói đúng "đã đi hay chưa", không phải "thành công hay không".
     */
    async agentChatAsk(text, region = 'all') {
      const value = String(text == null ? '' : text).trim();
      if (!value) { this.agentChatError = 'Nhập câu hỏi rồi hãy gửi.'; return false; }
      // Chống gửi chồng: hai lượt cùng lúc là hai câu trả lời đan vào nhau trong cùng một khung.
      if (this.agentChatStreaming) return false;

      if (value.length > MAX_TURN_CHARS) {
        this.agentChatError = 'Câu hỏi dài quá ' + MAX_TURN_CHARS + ' ký tự — bạn rút gọn giúp, hoặc tách thành hai câu.';
        return false;
      }

      // Lịch sử gửi lên = những lượt CÓ CHỮ, cắt theo trần từng lượt.
      // Vì sao phải cắt: câu trả lời của máy có thể dài hơn trần đó, mà máy chủ từ chối cả lượt nếu một
      // lượt vượt trần — tức là một câu trả lời dài sẽ làm HỎNG luôn câu hỏi kế tiếp. Vì sao phải bỏ lượt
      // rỗng: máy chủ đòi content khác rỗng, mà lượt bị dừng/bị lỗi có thể chưa có chữ nào.
      const history = (this.agentChatMessages || [])
        .filter((m) => m && (m.role === 'user' || m.role === 'assistant'))
        .map((m) => ({ role: m.role, content: String(m.text || '').trim().slice(0, MAX_TURN_CHARS) }))
        .filter((m) => m.content !== '');

      if (history.length + 1 > MAX_TURNS) {
        this.agentChatError = 'Hội thoại đã đủ ' + MAX_TURNS + ' lượt. Bấm «Hội thoại mới» rồi hỏi tiếp — câu trả lời mới vẫn dựa trên dữ liệu shop của bạn.';
        return false;
      }

      this.agentChatError = '';
      this.agentChatStreaming = true;
      this.agentChatPhase = 'prepare';
      this.agentChatPhaseLabel = 'Đang chuẩn bị câu hỏi…';
      this.agentChatToolLine = '';
      this.agentChatLastMeta = null;

      // Tin của người dùng vào khung NGAY (trước khi có chữ nào của máy chủ): để trống trong lúc chờ thì
      // người dùng tưởng cú bấm không ăn và bấm lại — mà bấm lại là gửi thêm một lượt nữa.
      this.agentChatMessages = [
        ...(this.agentChatMessages || []),
        { role: 'user', text: value, citations: [], streaming: false, stopped: false, failed: false },
        { role: 'assistant', text: '', citations: [], streaming: true, stopped: false, failed: false },
      ];

      const controller = new AbortController();
      chatAbort = controller;

      try {
        // KHÔNG dùng this.api(): nó ép Accept: application/json, mà luồng này trả NDJSON.
        const res = await fetch('/api/design-agent/chat/stream', {
          method: 'POST',
          headers: { 'X-XSRF-TOKEN': CSRF(), 'Content-Type': 'application/json', Accept: 'application/x-ndjson' },
          body: JSON.stringify({ messages: [...history, { role: 'user', content: value }], region: region || 'all' }),
          signal: controller.signal,
        });

        if (res.status === 401 || res.redirected) { this.setAuthStatus(401); throw new Error('Phiên đăng nhập đã hết — tải lại trang.'); }

        if (!res.ok) {
          // Lỗi TRƯỚC khi mở luồng vẫn là JSON thường: 422 (sai đầu vào) · 403 (gói thiếu module).
          // Đọc được thì nói đúng chuyện đó — KHÔNG được để lại một khung chat rỗng giả vờ như đã hỏi.
          const data = (await res.clone?.().json?.().catch(() => ({}))) || {};
          if (data.code === 'module_locked') {
            throw apiError(data, 'Hỏi đáp chưa có trong gói của bạn — mở gói để dùng.', res);
          }
          // 422 của Laravel để chi tiết trong errors; câu ở đó là câu viết cho NGƯỜI DÙNG nên hiện nó
          // (đây là ngoại lệ đã ghi ở §6.1 luật 6), không hiện câu chung chung.
          const first = data.errors ? Object.values(data.errors)[0] : null;
          const detail = Array.isArray(first) ? first[0] : first;
          throw apiError(detail ? { ...data, message: detail } : data, 'Không gửi được câu hỏi. Bạn thử lại sau ít phút.', res);
        }

        // ── VÒNG ĐỌC NDJSON: bê NGUYÊN cách đã chạy production của suggestStyleStream (actions/sources.js)
        //    — fetch tay + getReader + TextDecoder{stream:!done} + split('\n') + giữ lại dòng cuối chưa
        //    trọn + xử lý nốt phần còn lại sau khi luồng đóng.
        const reader = res.body && res.body.getReader ? res.body.getReader() : null;

        if (!reader) {
          // Trình duyệt không cho đọc luồng: vẫn lấy được TRỌN câu trả lời (chỉ là không chảy dần).
          // Máy chủ vẫn là bên nói thật về việc đó qua result.streamed.
          const whole = await res.text();
          for (const line of String(whole).split('\n')) this._handleAgentChatEvent(line);
        } else {
          const decoder = new TextDecoder();
          let buf = '';
          for (;;) {
            const { value: chunk, done } = await reader.read();
            buf += decoder.decode(chunk || new Uint8Array(), { stream: !done });
            const lines = buf.split('\n');
            buf = lines.pop();
            for (const line of lines) this._handleAgentChatEvent(line);
            if (done) break;
          }
          if (buf.trim()) this._handleAgentChatEvent(buf);   // dòng cuối không có ký tự xuống dòng
        }

        // Luồng đóng mà KHÔNG có sự kiện result/error (đứt giữa đường) ⇒ nói thật là lượt này chưa xong,
        // đừng để khung chat trông như đã trả lời đủ.
        if (this._agentChatStreamingIndex() >= 0) {
          this.agentChatError = this.agentChatError || 'Câu trả lời bị đứt giữa đường. Bạn thử hỏi lại.';
          this._agentChatFailLast();
        }
        return true;
      } catch (e) {
        // Người dùng bấm «Dừng» ⇒ KHÔNG phải lỗi: agentChatStop() đã ghi chú vào chính lượt đó rồi.
        if (controller.signal.aborted) return false;
        const message = userFacingError(e, 'Không gửi được câu hỏi. Bạn thử lại sau ít phút.');
        this.agentChatError = message;
        this._agentChatFailLast();
        this.toast(message, 'error');
        return false;
      } finally {
        if (chatAbort === controller) chatAbort = null;
        this.agentChatStreaming = false;
        const i = this._agentChatStreamingIndex();
        if (i >= 0) this._agentChatMerge(i, { streaming: false });
      }
    },

    /**
     * DỪNG giữa lượt.
     *
     * GIỮ phần chữ đã nhận: người dùng đã đọc được nó, xoá đi là lấy mất thứ họ vừa thấy. Ghi chú của
     * lượt bị dừng nằm ở CỜ `stopped` chứ KHÔNG nhét vào text — vì text này còn được gửi lại làm lịch
     * sử ở câu hỏi sau, nhét câu ghi chú của giao diện vào đó là bắt máy chủ đọc rác của mình.
     */
    agentChatStop() {
      if (!this.agentChatStreaming) return false;
      try { if (chatAbort) chatAbort.abort(); } catch { /* vòng đọc có thể đã đóng — dừng vẫn phải thành công */ }
      const i = this._agentChatStreamingIndex();
      if (i >= 0) this._agentChatMerge(i, { streaming: false, stopped: true });
      this.agentChatStreaming = false;
      this.agentChatPhaseLabel = 'Bạn đã dừng lượt này.';
      this.agentChatToolLine = '';
      return true;
    },

    /** Hội thoại MỚI — chỉ xoá ở màn hình; máy chủ không lưu hội thoại này nên không có gì để xoá ở đó. */
    agentChatReset() {
      if (this.agentChatStreaming) this.agentChatStop();
      this.agentChatMessages = [];
      this.agentChatError = '';
      this.agentChatPhase = '';
      this.agentChatPhaseLabel = '';
      this.agentChatToolLine = '';
      this.agentChatLastMeta = null;
    },

    /** Một dòng NDJSON của luồng chat → cập nhật state cho khung chat. */
    _handleAgentChatEvent(line) {
      const raw = String(line || '').trim();
      if (!raw) return;
      let ev;
      try { ev = JSON.parse(raw); } catch { return; }   // dòng hỏng KHÔNG được làm sập cả lượt
      if (!ev || typeof ev !== 'object') return;

      const i = this._agentChatStreamingIndex();
      if (i < 0) return;                                 // không còn lượt nào đang chờ ⇒ bỏ qua

      if (ev.type === 'phase') {
        this.agentChatPhase = String(ev.key || this.agentChatPhase || '');
        // Nhãn này HIỂN THỊ cho người dùng ⇒ lọc ở BIÊN, không tin nội dung máy chủ gửi (§6.3 tầng 2).
        this.agentChatPhaseLabel = safeMessage(ev.label, '') || this.agentChatPhaseLabel;
      } else if (ev.type === 'tool') {
        // Tên công cụ là chuyện KỸ THUẬT: câu hiển thị nói VIỆC ĐANG LÀM, không nêu tên hàm.
        const query = safeMessage(ev.query, '');
        this.agentChatToolLine = ev.name === 'read_page'
          ? 'Đang đọc nội dung một trang…'
          : (query ? 'Đang tra: ' + query : 'Đang tra thông tin trên web…');
      } else if (ev.type === 'tool_result') {
        const found = Number(ev.found) || 0;
        const reused = Number(ev.reused) || 0;
        // Hai con số này là SỐ ĐO CỦA MÁY CHỦ — không tự đếm lại ở client, vì đếm lại là có hai con số
        // và chúng sẽ lệch nhau ở lần đầu tiên ai đó sửa một bên.
        const head = ev.name === 'read_page'
          ? (found ? 'Đã đọc xong · ' + found + ' nguồn' : 'Đã đọc xong trang đó')
          : (found ? 'Đã tra xong · ' + found + ' nguồn' : 'Đã tra xong · chưa thấy nguồn nào dùng được');
        this.agentChatToolLine = head + (reused ? ' · ' + reused + ' nguồn dùng lại' : '');
      } else if (ev.type === 'token') {
        // Chữ về TỪNG MẢNH: nối vào cuối phần đã nhận, đúng thứ tự máy chủ gửi.
        const delta = String(ev.text == null ? '' : ev.text);
        if (delta) this._agentChatMerge(i, { text: String(this.agentChatMessages[i].text || '') + delta });
      } else if (ev.type === 'citation') {
        const row = this._agentChatCitation(ev);
        if (row && !(this.agentChatMessages[i].citations || []).some((c) => c.ref && c.ref === row.ref)) {
          this._agentChatMerge(i, { citations: [...(this.agentChatMessages[i].citations || []), row] });
        }
      } else if (ev.type === 'provider') {
        // KHỐI KỸ THUẬT — TUYỆT ĐỐI không hiển thị cho người dùng, nên nó KHÔNG đi vào state hiển thị
        // nào cả (không có trường nào để nó chảy vào). Chi tiết vẫn nằm trong log phía máy chủ (§6.1 luật 5).
      } else if (ev.type === 'result') {
        const data = ev.data || {};
        const row = this.agentChatMessages[i];
        this._agentChatMerge(i, {
          // Bản chữ ĐẦY ĐỦ của máy chủ THẮNG phần đã nối từ token (nối mảnh có thể sót khi mạng chập).
          text: typeof data.text === 'string' && data.text !== '' ? data.text : row.text,
          citations: Array.isArray(data.citations)
            ? data.citations.map((c) => this._agentChatCitation(c)).filter(Boolean)
            : row.citations,
          streaming: false,
        });
        // SỐ ĐO CỦA MÁY CHỦ cho lượt vừa rồi — giao diện KHÔNG tự bấm giờ, KHÔNG tự đếm nguồn.
        this.agentChatLastMeta = {
          streamed: !!data.streamed,
          elapsed_ms: Number(data.elapsed_ms) || 0,
          tool_search: data.tool_search || null,
        };
        this.agentChatPhase = 'done';
        this.agentChatPhaseLabel = 'Đã trả lời';
      } else if (ev.type === 'error') {
        this.agentChatError = safeMessage(ev.message, 'Trợ lý chưa trả lời được lúc này. Bạn thử lại sau ít phút.');
        this._agentChatMerge(i, { streaming: false, failed: true });
        this.toast(this.agentChatError, 'error');
      }
    },

    /**
     * MỘT nguồn → hàng để khung chat dựng LINK THẬT.
     *
     * Chỉ nhận hàng có ĐỊA CHỈ: nguồn không bấm được thì người dùng không kiểm chứng được, mà cả bước này
     * tồn tại chính vì việc kiểm chứng. Tiêu đề/nhãn nguồn là DỮ LIỆU của nguồn (người ngoài viết), không
     * phải nhãn giao diện của mình ⇒ giữ nguyên văn.
     */
    _agentChatCitation(source) {
      const url = String((source && source.url) || '').trim();
      if (!url) return null;
      return {
        ref: String((source && source.ref) || '').trim(),
        title: String((source && source.title) || '').trim(),
        url,
        source_name: String((source && source.source_name) || '').trim(),
        published_at: String((source && source.published_at) || '').trim(),
        snippet: String((source && source.snippet) || '').trim(),
        found_query: String((source && source.found_query) || '').trim(),
        reused: !!(source && source.reused),
      };
    },

    /** Vị trí lượt trả lời ĐANG CHẢY (lượt cuối, nếu nó còn đang chảy) — để vá đúng hàng đó. */
    _agentChatStreamingIndex() {
      const list = this.agentChatMessages || [];
      const i = list.length - 1;
      const row = list[i];
      return row && row.role === 'assistant' && row.streaming ? i : -1;
    },

    /** Vá MỘT hàng tin nhắn — thay cả mảng để Vue thấy thay đổi rõ ràng, không sửa ngầm trong object. */
    _agentChatMerge(index, patch) {
      const row = (this.agentChatMessages || [])[index];
      if (!row) return;
      this.agentChatMessages = this.agentChatMessages.map((m, i) => (i === index ? { ...m, ...patch } : m));
    },

    /** Đánh dấu lượt cuối là HỎNG — GIỮ phần chữ đã nhận, khung chat vẫn cho hỏi lại. */
    _agentChatFailLast() {
      const i = (this.agentChatMessages || []).length - 1;
      const row = (this.agentChatMessages || [])[i];
      if (!row || row.role !== 'assistant') return;
      this._agentChatMerge(i, { streaming: false, failed: true });
    },
};

// ═══════════════════════════════════════════════════════════════════════════════════════
// HAI CÂU HIỂN THỊ DỰNG TỪ SỐ ĐO CỦA MÁY CHỦ — dùng CHUNG cho MỌI khung chat.
//
// Vì sao để ở đây thay vì viết trong từng component: CÙNG một hội thoại nay có HAI khung đọc nó —
// bước «Hỏi đáp» của Agent Studio (components/agents/AgentChatStep.vue) và tab «Trò chuyện» ở màn
// hình canvas trống (components/CanvasEmptyState.vue). Chép câu chữ sang nơi thứ hai là mở đường cho
// hai màn nói hai kiểu về CÙNG một lượt trả lời, rồi lần sau ai sửa một bên thì bên kia lệch mà
// không ai biết. Nhãn đã ghi ở docs/DESIGN_SYSTEM.md §6.8 — sửa chữ ở đây thì sửa cả bảng đó.
// ═══════════════════════════════════════════════════════════════════════════════════════

/**
 * CẢNH BÁO CỦA LƯỢT VỪA RỒI — dựng từ SỐ ĐO máy chủ trả về, câu chữ nói đúng chuyện đã xảy ra:
 *   · không chảy dần ⇒ nói thẳng, để người dùng không tưởng lượt nào cũng hiện từng mảnh;
 *   · có dùng lại nguồn đã tra ⇒ nguồn cũ có thể đã lỗi thời, người dùng cần biết;
 *   · phần tra cứu bị cắt ⇒ câu trả lời có thể còn thiếu nguồn (nói TRƯỚC, đừng để họ tin tuyệt đối).
 */
export function agentChatNotes(meta) {
  if (! meta) return [];
  const tool = meta.tool_search || {};
  const notes = [];
  if (! meta.streamed) notes.push('Lượt này không hiện dần — câu trả lời hiện ra một lần.');
  if (Number(tool.reused) > 0) notes.push('Có dùng lại nguồn đã tra trước đó — nguồn cũ có thể đã lỗi thời.');
  if (tool.truncated) notes.push('Phần tra cứu đã bị cắt bớt — câu trả lời có thể còn thiếu nguồn.');
  return notes;
}

/**
 * DÒNG SỐ ĐO của lượt vừa rồi: thời gian là đồng hồ CỦA MÁY CHỦ (elapsed_ms trong sự kiện result),
 * KHÔNG phải đồng hồ trình duyệt — giao diện không được tự bấm giờ rồi kể như thể đó là số của máy chủ.
 */
export function agentChatMetaLine(meta) {
  if (! meta) return '';
  const count = (value) => (Number.isFinite(Number(value)) ? new Intl.NumberFormat('vi-VN').format(Number(value)) : '—');
  const seconds = new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 1 }).format(Number(meta.elapsed_ms || 0) / 1000);
  const parts = ['Trả lời trong ' + seconds + ' giây'];
  const sources = Number((meta.tool_search || {}).results || 0);
  if (sources) parts.push(count(sources) + ' nguồn đã tra');
  return parts.join(' · ');
}
