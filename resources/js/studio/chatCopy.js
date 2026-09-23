/**
 * COPY VĂN BẢN VÀO BỘ NHỚ TẠM — MỘT bản dùng chung cho CẢ HAI khung chat (2026-09-26).
 *
 * [VÌ SAO CÓ FILE NÀY]
 * Nút Copy có mặt ở HAI khung chat: components/ChatModal.vue (modal «Trợ lý thiết kế» ở /studio) và
 * components/agents/AgentChatStep.vue (bước «Hỏi đáp» của Agent Studio). Viết bản copy trong từng khung
 * là hai chỗ để lệch nhau: một bên có đường dự phòng, bên kia không, rồi trên máy này bấm được còn máy
 * kia thì im lặng — cùng một nút, hai hành vi. Đây là cùng lý do đã dùng cho CHAT_SUGGESTIONS và
 * agentChatNotes() (xem store/actions/agentChat.js).
 *
 * [VÌ SAO PHẢI CÓ ĐƯỜNG DỰ PHÒNG — KHÔNG PHẢI CHO ĐỦ BỘ]
 * navigator.clipboard CHỈ tồn tại khi trang chạy trong ngữ cảnh an toàn (HTTPS hoặc localhost) VÀ tài
 * liệu đang được focus. Máy khách truy cập bằng http://<ip> (đúng cách một số shop mở studio) sẽ có
 * navigator.clipboard là undefined; gọi thẳng vào nó là TypeError, và lỗi ấy ném ra console — người
 * dùng chỉ thấy nút KHÔNG LÀM GÌ. Vì vậy: thử đường chính, hỏng thì rơi xuống document.execCommand
 * (cũ nhưng vẫn chạy ở mọi trình duyệt hiện có), và nếu cả hai đều hỏng thì TRẢ VỀ false để khung chat
 * nói một câu người dùng đọc được.
 *
 * KHÔNG ném lỗi ra ngoài và KHÔNG ghi console: đây là hành động người dùng bấm, thất bại của nó phải
 * thành CÂU CHỮ trên giao diện (khung chat gọi store.toast với câu tương ứng), không phải dòng đỏ
 * trong console mà người dùng không bao giờ mở.
 */

/**
 * Copy một chuỗi vào bộ nhớ tạm. Trả về true khi ĐÃ copy, false khi cả hai đường đều không chạy được.
 *
 * @param {string} text nội dung cần copy (đã là CHỮ SẠCH — xem assistantPlainText trong chatFormat.js)
 * @returns {Promise<boolean>}
 */
export async function copyPlainText(text) {
  const value = String(text == null ? '' : text);
  if (! value.trim()) return false;

  // ĐƯỜNG CHÍNH: Clipboard API. Bọc try vì trình duyệt ném NotAllowedError khi tài liệu không được
  // focus — trường hợp thật xảy ra khi người dùng bấm nút ngay sau khi chuyển tab.
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(value);
      return true;
    }
  } catch (error) { /* rơi xuống đường dự phòng ngay bên dưới — KHÔNG ném tiếp */ }

  return legacyCopy(value);
}

/**
 * ĐƯỜNG DỰ PHÒNG: chọn nội dung trong một <textarea> tạm rồi gọi document.execCommand('copy').
 *
 * Vì sao phải là <textarea> đặt trong trang (không phải thẻ ẩn bằng display:none): trình duyệt chỉ
 * copy được nội dung của phần tử CHỌN ĐƯỢC và ĐANG HIỆN — phần tử display:none không chọn được.
 * Vì sao đặt ở -1000px: nó phải nằm ngoài tầm nhìn nhưng vẫn "hiện" theo nghĩa của trình duyệt.
 */
function legacyCopy(value) {
  if (typeof document === 'undefined' || ! document.body) return false;

  const area = document.createElement('textarea');
  area.value = value;
  area.setAttribute('readonly', 'readonly');
  area.setAttribute('aria-hidden', 'true');
  area.style.position = 'fixed';
  area.style.top = '-1000px';
  area.style.opacity = '0';

  try {
    document.body.appendChild(area);
    area.select();
    area.setSelectionRange(0, value.length);
    return document.execCommand('copy') === true;
  } catch (error) {
    return false;
  } finally {
    // Dọn phần tử tạm trong MỌI trường hợp — để lại là một textarea lạ trong DOM của studio.
    if (area.parentNode) area.parentNode.removeChild(area);
  }
}
