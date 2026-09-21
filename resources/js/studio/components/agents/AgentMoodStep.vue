<script setup>
/**
 * BƯỚC CON 3.4 — BẢNG MOOD LÀM THẬT (2026-09-25).
 *
 * Trước đây bảng mood là LƯỚI MÀU ĐỂ NHÌN: máy chủ dựng 24 ô, người dùng không sửa được ô nào, và prompt
 * ảnh chỉ lấy bảng màu + tên hướng — KHÔNG lấy gì từ bảng mood. Sửa gì cũng không đổi được ảnh sinh ra,
 * tức là một bảng trang trí.
 *
 * Nay: sửa được từng ô (nhãn · chú thích · màu), thêm/xoá/đổi thứ tự, và NHÃN + CHÚ THÍCH đi thẳng vào
 * prompt ảnh. Đổi một ô ở đây là prompt của mọi mẫu chưa chốt đổi theo.
 */
import { computed, inject, ref } from 'vue';
import StudioIcon from '../StudioIcon.vue';
import { MOOD_COLOR } from '../../dataColors.js';

const moodboardItems = inject('moodboardItems');
const moodRows = inject('moodRows');
const palette = inject('palette');
const paletteRows = inject('paletteRows');
const ensureMoodRows = inject('ensureMoodRows');
const ensurePaletteRows = inject('ensurePaletteRows');
const resetMoodRows = inject('resetMoodRows');
const resetPaletteRows = inject('resetPaletteRows');
const addMoodRow = inject('addMoodRow');
const removeMoodRow = inject('removeMoodRow');
const setMoodRow = inject('setMoodRow');
const moveMoodRow = inject('moveMoodRow');
const addPaletteRow = inject('addPaletteRow');
const removePaletteRow = inject('removePaletteRow');
const setPaletteRow = inject('setPaletteRow');
const collection = inject('collection');
const briefStale = inject('briefStale');
const createBrief = inject('createBrief');
const copyText = inject('copyText');

const selected = ref(0);
const editing = ref(false);

const MOOD_LABELS = {
  'Silhouette': 'Dáng', 'Color story': 'Câu chuyện màu', 'Fabric': 'Chất liệu', 'Detail': 'Chi tiết',
  'Styling': 'Phối đồ', 'Runway cue': 'Gợi ý sàn diễn', 'Office wear': 'Đồ công sở', 'Texture': 'Bề mặt chất liệu',
};
const moodLabel = (label) => MOOD_LABELS[label] || label;
const current = computed(() => moodboardItems.value[selected.value] || null);

/**
 * CÂU SẼ VÀO PROMPT — dựng lại đúng luật của máy chủ (moodPhrase): nhãn + chú thích, bỏ trùng, tối đa 8 vế.
 * Hiện ngay tại đây để người dùng thấy việc sửa ô có TÁC DỤNG THẬT, thay vì phải tin vào lời hứa.
 */
const promptPhrase = computed(() => {
  const parts = [];
  for (const row of moodboardItems.value) {
    const label = String(row.label || '').trim();
    const caption = String(row.caption || '').trim();
    if (!label && !caption) continue;
    const part = (label + (caption ? ': ' + caption : '')).trim();
    if (part && !parts.includes(part)) parts.push(part);
    if (parts.length >= 8) break;
  }
  return parts.join(' · ');
});

function beginEdit() {
  ensureMoodRows();
  editing.value = true;
}
function addCell() {
  ensureMoodRows();
  addMoodRow();
  selected.value = moodRows.value.length - 1;
  editing.value = true;
}
function removeCell(index) {
  ensureMoodRows();
  removeMoodRow(index);
  selected.value = Math.max(0, Math.min(selected.value, moodboardItems.value.length - 1));
}
function backToGenerated() {
  resetMoodRows();
  editing.value = false;
  selected.value = 0;
}

let applying = ref(false);
async function applyToPrompt() {
  applying.value = true;
  try { await createBrief({ force: true, ai: false }); } finally { applying.value = false; }
}
</script>

<template>
  <div>
    <div v-if="!collection" class="rounded-xl border border-dashed border-ink-700 bg-ink-900/60 p-6 text-center sm:p-8">
      <StudioIcon name="palette" size="h-7 w-7" class="mx-auto text-brand-300" />
      <p class="mt-3 text-sm font-semibold text-cream-100">Chưa có bảng mood để sửa</p>
      <p class="mt-1 text-xs leading-5 text-cream-400">Bảng mood được dựng cùng brief — quay lại việc 1 và tạo brief trước.</p>
    </div>

    <template v-else>
      <div class="card p-3 sm:p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="font-display text-base font-semibold text-brand-300">Bảng mood</h2>
            <p class="mt-0.5 text-body leading-5 text-cream-400">
              Mỗi ô là một sắc màu kèm một ý về bộ sưu tập. <b class="text-cream-200">Nhãn và chú thích của ô đi thẳng vào prompt ảnh</b> — sửa ở đây là ảnh sinh ra đổi theo.
            </p>
          </div>
          <span class="rounded-full bg-ink-800 px-2.5 py-1 text-label text-cream-300">{{ moodboardItems.length }} ô</span>
        </div>

        <div class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-8">
          <button
            v-for="(item, index) in moodboardItems"
            :key="item.id || index"
            type="button"
            class="group relative aspect-square overflow-hidden rounded-lg border text-left motion-ui"
            :class="index === selected ? 'border-brand-500 ring-2 ring-brand-500/40' : 'border-ink-600 hover:border-brand-400'"
            :style="{ backgroundColor: item.color || palette[index % Math.max(1, palette.length)]?.hex || MOOD_COLOR }"
            :aria-label="'Ô mood ' + (index + 1) + ': ' + (item.label || '') + ' — ' + (item.caption || '')"
            :aria-pressed="index === selected"
            @click="selected = index; editing = true"
          >
            <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-scrim/85 to-transparent p-1.5 text-tiny font-semibold leading-3 text-scrim-content">
              {{ moodLabel(item.label) || 'Ô ' + (index + 1) }}
            </span>
            <span v-if="index === 0" class="absolute right-1 top-1 rounded bg-scrim/70 px-1 text-tiny text-scrim-content">đầu</span>
          </button>
          <button
            type="button"
            class="grid aspect-square place-items-center rounded-lg border border-dashed border-ink-600 text-cream-400 motion-ui hover:border-brand-400 hover:text-brand-200"
            aria-label="Thêm ô mood"
            title="Thêm ô mood"
            @click="addCell"
          >
            <StudioIcon name="plus" size="h-5 w-5" />
          </button>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button type="button" class="tool-btn state-layer" :disabled="editing" @click="beginEdit">
            <StudioIcon name="pencil" size="h-3 w-3" /> Sửa bảng này
          </button>
          <button v-if="moodRows.length" type="button" class="tool-btn state-layer" @click="backToGenerated">
            <StudioIcon name="refresh" size="h-3 w-3" /> Về bảng hệ thống dựng
          </button>
          <span v-if="!editing" class="text-label leading-4 text-cream-400">↳ Bảng đang là bản hệ thống dựng. Bấm «Sửa bảng này» để chép thành ô sửa được rồi chỉnh.</span>
          <span v-else class="text-label leading-4 text-cream-400">Bảng này do bạn sửa — {{ moodRows.length }} ô.</span>
        </div>
      </div>

      <!-- Trình sửa MỘT ô: điện thoại chỉ hiện một ô một lúc, đúng nhịp "một việc một màn" -->
      <div v-if="editing && current" class="card mt-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-display text-base font-semibold text-brand-300">Ô {{ selected + 1 }}/{{ moodboardItems.length }}</h3>
          <div class="flex items-center gap-1.5">
            <button type="button" class="tool-btn !px-2 !py-1.5" :disabled="selected === 0" aria-label="Đưa ô này lên trước" title="Lên trước" @click="moveMoodRow(selected, -1); selected = Math.max(0, selected - 1)">
              <StudioIcon name="chevronUp" size="h-3.5 w-3.5" />
            </button>
            <button type="button" class="tool-btn !px-2 !py-1.5" :disabled="selected >= moodboardItems.length - 1" aria-label="Đưa ô này xuống sau" title="Xuống sau" @click="moveMoodRow(selected, 1); selected = Math.min(moodboardItems.length - 1, selected + 1)">
              <StudioIcon name="chevronDown" size="h-3.5 w-3.5" />
            </button>
            <button type="button" class="tool-btn !px-2 !py-1.5" :aria-label="'Xoá ô ' + (selected + 1)" title="Xoá ô này" @click="removeCell(selected)">
              <StudioIcon name="trash" size="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="text-label font-semibold uppercase tracking-wide text-cream-400">Nhãn</span>
            <input
              class="input mt-1 w-full !py-2 !text-body"
              maxlength="60"
              placeholder="VD: Bề mặt chất liệu"
              :value="current.label || ''"
              @input="setMoodRow(selected, { label: $event.target.value })"
            >
          </label>
          <label class="block">
            <span class="text-label font-semibold uppercase tracking-wide text-cream-400">Màu ô</span>
            <span class="mt-1 flex items-center gap-2">
              <input
                type="color"
                class="h-9 w-14 shrink-0 rounded border border-ink-600 bg-ink-800"
                :value="current.color || MOOD_COLOR"
                aria-label="Màu của ô mood"
                @input="setMoodRow(selected, { color: $event.target.value.toUpperCase() })"
              >
              <input
                class="input w-full !py-2 !text-body uppercase tabular-nums"
                maxlength="7"
                :value="current.color || ''"
                aria-label="Mã màu của ô mood"
                @input="setMoodRow(selected, { color: $event.target.value.toUpperCase() })"
              >
            </span>
          </label>
        </div>
        <label class="mt-3 block">
          <span class="text-label font-semibold uppercase tracking-wide text-cream-400">Chú thích (vào thẳng prompt ảnh)</span>
          <textarea
            class="input mt-1 w-full resize-none !py-2 !text-body"
            rows="2"
            maxlength="240"
            placeholder="VD: linen thô, nhăn tự nhiên, vai mềm"
            :value="current.caption || ''"
            @input="setMoodRow(selected, { caption: $event.target.value })"
          ></textarea>
        </label>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button v-for="hex in palette.slice(0, 8)" :key="hex.hex" type="button" class="h-7 w-7 rounded border border-ink-600 motion-ui hover:border-brand-400" :style="{ backgroundColor: hex.hex }" :title="'Lấy màu ' + hex.hex" :aria-label="'Lấy màu ' + hex.hex + ' cho ô này'" @click="setMoodRow(selected, { color: hex.hex })"></button>
        </div>
      </div>

      <!-- BẢNG MÀU: nguồn màu cho các ô -->
      <div class="card mt-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-display text-base font-semibold text-brand-300">Bảng màu</h3>
          <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" class="tool-btn state-layer" :disabled="paletteRows.length >= 12" @click="addPaletteRow">
              <StudioIcon name="plus" size="h-3 w-3" /> Thêm màu
            </button>
            <button v-if="paletteRows.length" type="button" class="tool-btn state-layer" @click="resetPaletteRows">
              <StudioIcon name="refresh" size="h-3 w-3" /> Về bảng hệ thống
            </button>
            <button v-else type="button" class="tool-btn state-layer" @click="ensurePaletteRows">
              <StudioIcon name="pencil" size="h-3 w-3" /> Sửa bảng màu
            </button>
          </div>
        </div>
        <p v-if="paletteRows.length >= 12" class="mt-1.5 text-label text-cream-400">↳ Đã đủ 12 màu — bỏ một màu nếu muốn thêm.</p>

        <ul class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <li v-for="(color, index) in palette" :key="index" class="flex items-center gap-2 rounded-lg border border-ink-700 bg-ink-800 p-2">
            <input
              type="color"
              class="h-8 w-10 shrink-0 rounded border border-ink-600 bg-ink-900"
              :value="color.hex"
              :aria-label="'Màu ' + (color.name || index + 1)"
              @input="setPaletteRow(index, { hex: $event.target.value.toUpperCase() })"
            >
            <input
              class="input min-w-0 flex-1 !py-1.5 !text-body"
              maxlength="40"
              placeholder="Tên màu"
              :value="color.name || ''"
              :aria-label="'Tên màu ' + (index + 1)"
              @input="setPaletteRow(index, { name: $event.target.value })"
            >
            <code class="text-label text-cream-400">{{ color.hex }}</code>
            <button type="button" class="tool-btn !px-2 !py-1.5" :aria-label="'Sao chép mã màu ' + color.hex" title="Sao chép mã màu" @click="copyText(color.hex, 'mã màu ' + color.hex)">
              <StudioIcon name="copy" size="h-3 w-3" />
            </button>
            <button v-if="paletteRows.length" type="button" class="tool-btn !px-2 !py-1.5" :aria-label="'Bỏ màu ' + (color.name || index + 1)" title="Bỏ màu này" @click="removePaletteRow(index)">
              <StudioIcon name="trash" size="h-3 w-3" />
            </button>
          </li>
        </ul>
      </div>

      <!-- Câu sẽ vào prompt: bằng chứng bảng mood có tác dụng thật -->
      <div class="card mt-3 p-3 sm:p-4">
        <h3 class="font-display text-base font-semibold text-brand-300">Câu từ bảng mood sẽ vào prompt ảnh</h3>
        <p class="mt-2 rounded-lg border border-ink-700 bg-ink-900 px-3 py-2 text-body leading-5 text-cream-200">{{ promptPhrase || 'Chưa có ô nào có nhãn hoặc chú thích.' }}</p>
        <p class="mt-1.5 text-label leading-4 text-cream-400">Tối đa 8 vế đầu tiên, bỏ vế trùng. Bước Thực thi sinh prompt cho TỪNG mẫu nên câu này được dùng ngay ở lần sinh kế tiếp.</p>
      </div>

      <div v-if="briefStale" role="status" class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-2 rounded-lg border border-warn/40 bg-warn/10 p-3 text-body leading-5 text-warn">
        <StudioIcon name="info" size="h-4 w-4" class="shrink-0" />
        <span>Bảng mood đã đổi so với lần dựng brief gần nhất.</span>
        <button type="button" class="btn-brand btn-sm" :disabled="applying" @click="applyToPrompt">
          {{ applying ? 'Đang cập nhật…' : 'Cập nhật số liệu (không gọi AI)' }}
        </button>
        <span class="text-label">Chạy bằng bộ quy tắc — không tốn lượt gọi AI, và phần chữ AI viết ở lượt trước được giữ nguyên.</span>
      </div>
    </template>
  </div>
</template>
