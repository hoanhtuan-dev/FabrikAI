<script setup>
// LayersPanel — inspector "Layers" dock bên phải khung canvas (Designer Workspace).
// Không props, không emit — đọc/ghi trực tiếp useStudioStore() (spec STUDIO_UI_REDESIGN §4.1).
import { ref, computed } from 'vue';
import { useStudioStore } from '../store.js';
import StudioIcon from './StudioIcon.vue';

const store = useStudioStore();

// Menu "Thêm layer" (Trong suốt / 6 swatch nền) — logic y hệt blankMenuOpen cũ (StudioApp :406-415).
const blankMenuOpen = ref(false);
const blankRatio = ref(store.imageRatio);
const blankColor = ref('#4f9dff'); // màu tùy chỉnh cho layer màu mới
// Chọn màu tùy chỉnh xong (@change đóng bảng màu) → tự thêm layer màu + đóng menu.
function addBlankColorLayer() { store.addBlankLayer(blankColor.value, blankRatio.value); blankMenuOpen.value = false; }

// Rename inline (double-click tên layer) — giữ logic cũ StudioApp.
const renamingId = ref(null);
const renameValue = ref('');
function startRename(l) { renamingId.value = l.id; renameValue.value = l.name || ''; }
function commitRename() { if (!renamingId.value) return; store.renameLayer(renamingId.value, renameValue.value); renamingId.value = null; }
function cancelRename() { renamingId.value = null; }

// Kéo-thả sắp xếp (HTML5 DnD) → store.reorderLayer(dragId, targetId, placeAfter).
// Danh sách hiển thị front-first (trên cùng = trước nhất) nhưng store đặt theo stack canvasLayers
// (index 0 = dưới cùng) → thả nửa TRÊN = chèn TRÊN target = placeAfter=true, nửa dưới = false.
const dragId = ref(null);
const dropTargetId = ref(null);
const dropBelow = ref(false);
function clearDrag() { dragId.value = null; dropTargetId.value = null; dropBelow.value = false; }
function onDragStart(e, l) {
  if (l.locked) { e.preventDefault(); return; } // cấm kéo layer locked
  dragId.value = l.id;
  e.dataTransfer.effectAllowed = 'move';
  try { e.dataTransfer.setData('text/plain', l.id); } catch (err) { /* một số trình duyệt chặn */ }
}
function onRowDragOver(e, l) {
  if (!dragId.value || dragId.value === l.id) return;
  e.preventDefault(); // cho phép drop
  e.dataTransfer.dropEffect = 'move';
  const rect = e.currentTarget.getBoundingClientRect();
  dropBelow.value = (e.clientY - rect.top) > rect.height / 2; // nửa trên/dưới con trỏ trong hàng
  dropTargetId.value = l.id;
}
function onRowDragLeave(e, l) {
  if (e.currentTarget.contains(e.relatedTarget)) return; // di chuyển giữa phần tử con — bỏ qua
  if (dropTargetId.value === l.id) { dropTargetId.value = null; dropBelow.value = false; }
}
// Nhấn 1 layer = chọn riêng; shift+click = thêm/bỏ vào nhóm chọn nhiều (đồng bộ canvas).
function onRowClick(l, e) { if (e && e.shiftKey) { store.shiftSelectLayer(l.id); return; } if (e && e.altKey && l.groupId) { store.setActiveLayer(l.id); return; } store.selectLayerWithGroup(l.id); }
// ── Nhóm layer: hiển thị dưới dạng folder riêng trong panel ──
const collapsedGroups = ref(new Set()); // mặc định rỗng = tất cả nhóm MỞ (hiển thị member); thu gọn mới ẩn hết
function toggleGroup(gid) { const s = new Set(collapsedGroups.value); s.has(gid) ? s.delete(gid) : s.add(gid); collapsedGroups.value = s; }
function isGroupTop(l) { if (!l.groupId) return false; const m = store.canvasLayers.filter(x => x.groupId === l.groupId); return (m[m.length - 1]?.id === l.id); }
const groupRenameId = ref(null), groupRenameVal = ref('');
function startGroupRename(gid) { const g = store.layerGroups.find(x => x.id === gid); if (!g) return; groupRenameId.value = gid; groupRenameVal.value = g.name || ''; }
function commitGroupRename() { if (groupRenameId.value) store.renameGroup(groupRenameId.value, groupRenameVal.value); groupRenameId.value = null; }
function cancelGroupRename() { groupRenameId.value = null; }
const groupName = (gid) => { const g = store.layerGroups.find(x => x.id === gid); return g ? g.name : 'Nhóm'; };
const groupCount = (gid) => store.canvasLayers.filter(x => x.groupId === gid).length;
const groupLocked = (gid) => store.canvasLayers.filter(x => x.groupId === gid).every(x => x.locked);
// Giá trị ĐƠN VỊ (group = cấp group) để hiển thị + thao tác trong Thuộc tính khớp với handle canvas.
const unitGroup = computed(() => { const a = store.activeLayer; return (a && a.groupId) ? (store.layerGroups.find(x => x.id === a.groupId) || null) : null; });
const isUnitGroup = computed(() => { const g = unitGroup.value; return !!(g && g.layerIds.length > 1 && store.selectedIds.length === g.layerIds.length); });
const activeUnitScale = computed(() => isUnitGroup.value ? (unitGroup.value.scale || 1) : (store.activeLayer ? (store.activeLayer.scale || 1) : 1));
const activeUnitRotation = computed(() => isUnitGroup.value ? (unitGroup.value.rotation || 0) : (store.activeLayer ? (store.activeLayer.rotation || 0) : 0));
function onRowDrop(e, l) {
  e.preventDefault();
  if (dragId.value && dragId.value !== l.id) {
    const rect = e.currentTarget.getBoundingClientRect();
    const below = (e.clientY - rect.top) > rect.height / 2;
    store.reorderLayer(dragId.value, l.id, !below);
  }
  clearDrag();
}

// Palette: click swatch = gán màu tô + copy clipboard + toast (y hệt copyColor StudioApp :25-28).
function copyColor(c) {
  store.inpaintFillColor = c; // đồng bộ màu cho công cụ tô màu
  try { navigator.clipboard.writeText(c); store.toast('Đã chọn màu ' + c); } catch (e) { store.toast('Lỗi copy.', 'error'); }
}

// Modal xác nhận Xóa nền AI (nội bộ panel) — markup y hệt StudioApp :542-551.
const removeBgConfirmOpen = ref(false);
function doRemoveBg() { removeBgConfirmOpen.value = false; store.removeBackground(); }
</script>

<template>
  <aside class="flex h-full w-64 shrink-0 flex-col border-l border-ink-700 bg-ink-900/95">
    <!-- 1. Header: tiêu đề + đếm + thêm layer / dọn canvas / ẩn panel -->
    <div class="flex shrink-0 items-center justify-between border-b border-ink-700 px-3 py-2">
      <p class="flex min-w-0 items-center gap-1.5 text-[11px] font-semibold text-cream-100">
        <StudioIcon name="layers" size="h-4 w-4" class="shrink-0 text-cream-300" />
        <span>Layers</span>
        <span class="rounded bg-ink-800 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-cream-300/80">{{ store.canvasLayers.length }}</span>
      </p>
      <div class="flex shrink-0 items-center gap-1">
        <div class="relative">
          <button @click="blankMenuOpen = !blankMenuOpen" class="grid h-7 w-7 place-items-center rounded-lg bg-brand-600 text-white transition-colors hover:bg-brand-500" title="Thêm layer mới" aria-label="Thêm layer mới">
            <StudioIcon name="plus" size="h-4 w-4" />
          </button>
          <!-- Backdrop phủ toàn màn hình: bấm ra ngoài menu → tự thoát popup (thoát tiêu điểm) -->
          <div v-if="blankMenuOpen" class="fixed inset-0 z-40" @pointerdown="blankMenuOpen = false"></div>
          <div v-if="blankMenuOpen" class="absolute right-0 top-9 z-50 flex w-56 flex-col gap-2 rounded-md border border-ink-700 bg-ink-900/95 p-3 shadow-2xl">
            <p class="px-1 text-[10px] font-semibold text-cream-300/70">Nền layer mới</p>
            <button @click="store.addBlankLayer(null, blankRatio); blankMenuOpen = false" class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-[11px] text-cream-100 hover:bg-ink-800"><span class="h-5 w-5 rounded border border-white/30" style="background: repeating-conic-gradient(#888 0 25%, #ccc 0 50%) 0 / 8px 8px"></span>Trong suốt</button>

            <div class="grid grid-cols-6 gap-1.5">
              <button v-for="c in ['#ffffff','#000000','#ff4d4f','#4f9dff','#4ade80','#fbbf24']" :key="c" @click="store.addBlankLayer(c, blankRatio); blankMenuOpen = false" class="h-7 w-7 rounded-full border border-white/20 transition hover:scale-110" :style="{ background: c }" :title="c" :aria-label="'Layer nền màu ' + c"></button>
            </div>

            <!-- Bảng chọn màu tùy chỉnh: 1 hàng rõ ràng — bấm mở bảng màu, chọn xong tự thêm layer -->
            <label class="relative flex cursor-pointer items-center gap-2 rounded-lg bg-ink-800 px-2.5 py-1.5 transition hover:bg-ink-700" title="Mở bảng chọn màu — chọn màu xong tự thêm layer màu">
              <span class="relative inline-flex h-6 w-6 shrink-0 overflow-hidden rounded-full border border-white/30">
                <span class="absolute inset-0" :style="{ background: blankColor }"></span>
              </span>
              <span class="flex-1 truncate text-left text-[11px] text-cream-100">Chọn màu tùy chỉnh…</span>
              <span class="text-cream-300/60"><StudioIcon name="chevronDown" size="h-3 w-3" /></span>
              <input type="color" :value="blankColor" @input="blankColor = $event.target.value" @change="addBlankColorLayer" class="absolute inset-0 h-full w-full cursor-pointer opacity-0">
            </label>

            <div class="h-px w-full bg-ink-700"></div>

            <p class="px-1 text-[10px] font-semibold text-cream-300/70">Tỷ lệ khung hình</p>
            <div class="flex flex-wrap gap-1.5">
              <button v-for="r in ['1:1','4:3','3:4','9:16','16:9','4:5','21:9']" :key="r" @click="blankRatio = r" class="rounded-md px-2 py-1 text-[10px] font-semibold transition" :class="blankRatio === r ? 'bg-brand-600 text-white' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'">{{ r }}</button>
            </div>
          </div>
        </div>
        <button @click="store.openClearCanvasConfirm()"
          class="grid h-7 w-7 place-items-center rounded-lg text-red-300 transition-colors hover:bg-red-600/25 hover:text-red-200"
          title="Dọn canvas — bỏ hết ảnh trên canvas (không xóa kết quả)"
          aria-label="Dọn canvas — bỏ hết ảnh trên canvas">
          <StudioIcon name="trash" size="h-4 w-4" />
        </button>
        <button @click="store.toggleInspector()" class="grid h-7 w-7 place-items-center rounded-lg text-cream-200 transition-colors hover:bg-ink-700" title="Ẩn panel Layers" aria-label="Ẩn panel Layers">
          <StudioIcon name="panelRight" size="h-4 w-4" />
        </button>
      </div>
    </div>

    <!-- 2. Danh sách layer (front-first) — NHÓM hiển thị như folder riêng + kéo-thả sắp xếp -->
    <div class="flex-1 space-y-1 overflow-y-auto p-1.5">
      <template v-for="l in store.layersFrontFirst" :key="l.id">
        <!-- Folder nhóm (hiện trước layer đại diện) -->
        <template v-if="isGroupTop(l)">
          <div class="group flex items-center gap-1.5 rounded-lg border px-1.5 py-1" :class="store.isGroupActive(l.groupId) ? 'border-violet-400 bg-violet-400/20' : 'border-violet-400/40 bg-ink-800/60'" @click="store.selectGroup(l.groupId)">
            <button @click.stop="toggleGroup(l.groupId)" class="grid h-6 w-6 shrink-0 place-items-center rounded text-cream-300/70 hover:bg-ink-700 hover:text-cream-100" :title="collapsedGroups.has(l.groupId) ? 'Mở rộng nhóm' : 'Thu gọn nhóm'"><StudioIcon :name="collapsedGroups.has(l.groupId) ? 'chevronUp' : 'chevronDown'" size="h-3.5 w-3.5" /></button>
            <StudioIcon name="group" size="h-3.5 w-3.5" class="shrink-0 text-brand-300" />
            <template v-if="groupRenameId === l.groupId">
              <input v-model="groupRenameVal" @keydown.enter.prevent="commitGroupRename" @keydown.esc="cancelGroupRename" @blur="commitGroupRename" class="min-w-0 flex-1 rounded bg-ink-950 px-1 py-0.5 text-[10px] text-cream-100 outline-none ring-1 ring-brand-500" aria-label="Đổi tên nhóm" @click.stop>
            </template>
            <span v-else class="min-w-0 flex-1 truncate text-[11px] font-semibold text-cream-100" :title="'Nhóm: ' + groupName(l.groupId) + ' — nhấn đúp để đổi tên'" @dblclick.stop="startGroupRename(l.groupId)">{{ groupName(l.groupId) }}</span>
            <span class="shrink-0 rounded bg-ink-700 px-1 text-[9px] text-cream-300/70">{{ groupCount(l.groupId) }}</span>
            <button @click.stop="store.ungroupGroup(l.groupId)" class="grid h-6 w-6 shrink-0 place-items-center rounded text-cream-300 hover:bg-red-600/25 hover:text-red-200" :title="'Tách nhóm ' + groupName(l.groupId)"><StudioIcon name="unlink" size="h-3.5 w-3.5" /></button>
            <div class="flex items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
              <button @click.stop="store.toggleGroupLock(l.groupId)" class="grid h-6 w-6 place-items-center rounded text-cream-300 hover:bg-ink-700" :title="groupLocked(l.groupId) ? 'Mở khóa nhóm' : 'Khóa nhóm'" :aria-label="groupLocked(l.groupId) ? 'Mở khóa nhóm' : 'Khóa nhóm'"><StudioIcon :name="groupLocked(l.groupId) ? 'lock' : 'lockOpen'" size="h-3.5 w-3.5" /></button>
              <button @click.stop="store.duplicateGroup(l.groupId)" class="grid h-6 w-6 place-items-center rounded text-cream-300 hover:bg-ink-700" title="Nhân đôi nhóm" aria-label="Nhân đôi nhóm"><StudioIcon name="copy" size="h-3.5 w-3.5" /></button>
              <button @click.stop="store.deleteGroup(l.groupId)" class="grid h-6 w-6 place-items-center rounded bg-red-600/25 text-red-200 hover:bg-red-600" title="Xóa nhóm" aria-label="Xóa nhóm"><StudioIcon name="trash" size="h-3.5 w-3.5" /></button>
            </div>
          </div>
        </template>
        <!-- Row layer; ẩn các thành viên khi thu gọn nhóm (chỉ giữ đại diện) -->
        <div v-if="!l.groupId || !collapsedGroups.has(l.groupId)"
        class="group flex items-center gap-1.5 rounded-lg border px-1.5 py-1"
        :class="[
          store.activeLayerId === l.id ? 'border-sky-400 bg-sky-400/15' : (store.isSelected(l.id) ? 'border-sky-400/60 bg-sky-400/10' : 'border-transparent hover:bg-ink-800/70'),
          l.visible === false ? 'opacity-45' : '',
          l.groupId ? 'ml-3' : '',
          dropTargetId === l.id ? (dropBelow ? 'border-b-2 border-b-brand-400' : 'border-t-2 border-t-brand-400') : ''
        ]"
        @click.exact="store.selectLayerWithGroup(l.id)"
        @click.shift.exact="store.shiftSelectLayer(l.id)"
        @click.alt.exact="store.setActiveLayer(l.id)"
        @dragover="onRowDragOver($event, l)"
        @dragleave="onRowDragLeave($event, l)"
        @drop="onRowDrop($event, l)">
        <!-- Drag handle (locked → mờ + không kéo được) -->
        <span class="shrink-0 text-cream-300/50" :class="l.locked ? 'opacity-30' : 'cursor-grab hover:text-cream-200 active:cursor-grabbing'" :draggable="!l.locked" @dragstart="onDragStart($event, l)" @dragend="clearDrag" title="Kéo để sắp xếp layer">
          <StudioIcon name="gripVertical" size="h-4 w-4" />
        </span>
        <button @click.stop="store.toggleLayerVisible(l.id)" class="grid h-6 w-6 shrink-0 place-items-center rounded text-cream-200 hover:bg-ink-700" :title="l.visible !== false ? 'Ẩn layer' : 'Hiện layer'" :aria-label="l.visible !== false ? 'Ẩn layer' : 'Hiện layer'">
          <StudioIcon :name="l.visible !== false ? 'eye' : 'eyeOff'" size="h-3.5 w-3.5" />
        </button>
        <img :src="l.image" :alt="l.name" class="h-8 w-8 shrink-0 rounded bg-ink-950 object-cover ring-1 ring-ink-700">
        <span v-if="renamingId !== l.id" class="min-w-0 flex-1 truncate text-[11px] text-cream-100" :title="l.name" @dblclick.stop="startRename(l)">{{ l.name }}</span>
        <input v-else v-model="renameValue" class="min-w-0 flex-1 rounded bg-ink-950 px-1 py-0.5 text-[11px] text-cream-100 outline-none ring-1 ring-brand-500" aria-label="Đổi tên layer" @keyup.enter="commitRename()" @keyup.esc="cancelRename()" @blur="commitRename()" @click.stop>
        <div class="flex shrink-0 items-center gap-0.5">
          <button @click.stop="l.groupId ? store.toggleGroupLock(l.groupId) : store.toggleLayerLock(l.id)" class="grid h-6 w-6 place-items-center rounded" :class="l.locked ? 'bg-amber-500/20 text-amber-300' : 'text-cream-300 hover:bg-ink-700'" :title="l.groupId ? 'Khóa/Mở khóa nhóm' : (l.locked ? 'Mở khóa' : 'Khóa layer')" :aria-label="l.groupId ? 'Khóa/Mở khóa nhóm' : (l.locked ? 'Mở khóa' : 'Khóa layer')">
            <StudioIcon :name="l.locked ? 'lock' : 'lockOpen'" size="h-3.5 w-3.5" />
          </button>
          <div class="flex items-center gap-0.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
            <button @click.stop="l.groupId ? store.duplicateGroup(l.groupId) : store.duplicateLayer(l.id)" class="grid h-6 w-6 place-items-center rounded text-cream-300 hover:bg-ink-700" :title="l.groupId ? 'Nhân đôi nhóm' : 'Nhân đôi layer'" :aria-label="l.groupId ? 'Nhân đôi nhóm' : 'Nhân đôi layer'">
              <StudioIcon name="copy" size="h-3.5 w-3.5" />
            </button>
            <button @click.stop="l.groupId ? store.deleteGroup(l.groupId) : store.deleteLayer(l)" :disabled="l.locked" class="grid h-6 w-6 place-items-center rounded bg-red-600/25 text-red-200 hover:bg-red-600 disabled:opacity-30" :title="l.groupId ? 'Xóa nhóm' : (l.locked ? 'Đang khóa' : 'Gỡ khỏi canvas (không xóa kết quả)')" :aria-label="l.groupId ? 'Xóa nhóm' : (l.locked ? 'Đang khóa' : 'Gỡ khỏi canvas (không xóa kết quả)')">
              <StudioIcon name="trash" size="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </div>
      </template>
      <!-- Empty state -->
      <div v-if="!store.canvasLayers.length" class="flex flex-col items-center gap-1.5 px-3 py-8 text-center">
        <StudioIcon name="layers" size="h-7 w-7" class="text-cream-300/30" />
        <p class="text-[11px] font-semibold text-cream-300/60">Chưa có layer</p>
        <p class="text-[10px] leading-relaxed text-cream-300/40">Chọn ảnh từ Outputs hoặc thêm layer mới</p>
      </div>
    </div>

    <!-- 3. Thuộc tính layer đang chọn -->
    <section v-if="store.activeLayer" class="scrollbar-hide max-h-[42%] shrink-0 space-y-2 overflow-y-auto border-t border-ink-700 p-2.5">
      <div class="flex items-center justify-between">
        <p class="flex items-center gap-1 text-[10px] font-semibold text-cream-300/60">
          <StudioIcon name="move" size="h-3.5 w-3.5" />
          <span>Thuộc tính</span>
        </p>
        <button @click="store.resetLayerTransform(store.activeLayer.id)" class="text-[10px] font-semibold text-red-300 hover:text-red-200" title="Đưa layer về mặc định">Đặt lại</button>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-12 shrink-0 whitespace-nowrap text-[10px] text-cream-300/60">Opacity</span>
        <input type="range" min="0" max="1" step="0.05" :value="store.activeLayer.opacity" @input="store.updateUnitTransform('opacity', Number($event.target.value))" class="h-1.5 min-w-0 flex-1 accent-brand-500" aria-label="Opacity">
        <span class="w-9 shrink-0 whitespace-nowrap text-right text-[10px] tabular-nums text-cream-200">{{ Math.round(store.activeLayer.opacity * 100) }}%</span>
        <button @click="store.updateUnitTransform('opacity', 1)" class="grid h-4 w-4 shrink-0 place-items-center rounded text-cream-300 hover:bg-ink-700" title="Reset opacity" aria-label="Reset opacity"><StudioIcon name="rotateCcw" size="h-3 w-3" /></button>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-12 shrink-0 whitespace-nowrap text-[10px] text-cream-300/60">Blend</span>
        <select :value="store.activeLayer.blend" @change="store.updateUnitTransform('blend', $event.target.value)" class="min-w-0 flex-1 rounded bg-ink-800 px-1 py-0.5 text-[10px] text-cream-100" aria-label="Blend">
          <option value="normal">Normal</option>
          <option value="multiply">Multiply</option>
          <option value="screen">Screen</option>
          <option value="overlay">Overlay</option>
          <option value="darken">Darken</option>
          <option value="lighten">Lighten</option>
        </select>
        <button @click="store.updateUnitTransform('blend', 'normal')" class="grid h-4 w-4 shrink-0 place-items-center rounded text-cream-300 hover:bg-ink-700" title="Reset blend" aria-label="Reset blend"><StudioIcon name="rotateCcw" size="h-3 w-3" /></button>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-12 shrink-0 whitespace-nowrap text-[10px] text-cream-300/60">Scale</span>
        <input type="range" min="0.2" max="3" step="0.05" :value="activeUnitScale" @input="store.updateUnitTransform('scale', Number($event.target.value))" class="h-1.5 min-w-0 flex-1 accent-brand-500" aria-label="Scale">
        <span class="w-9 shrink-0 whitespace-nowrap text-right text-[10px] tabular-nums text-cream-200">{{ Math.round(activeUnitScale * 100) }}%</span>
        <button @click="store.updateUnitTransform('scale', 1)" class="grid h-4 w-4 shrink-0 place-items-center rounded text-cream-300 hover:bg-ink-700" title="Reset scale" aria-label="Reset scale"><StudioIcon name="rotateCcw" size="h-3 w-3" /></button>
      </div>
      <div class="flex items-center gap-1.5">
        <span class="w-12 shrink-0 whitespace-nowrap text-[10px] text-cream-300/60">Xoay</span>
        <input type="range" min="-180" max="180" step="1" :value="activeUnitRotation" @input="store.updateUnitTransform('rotation', Number($event.target.value))" class="h-1.5 min-w-0 flex-1 accent-brand-500" aria-label="Xoay">
        <span class="w-9 shrink-0 whitespace-nowrap text-right text-[10px] tabular-nums text-cream-200">{{ activeUnitRotation }}°</span>
        <button @click="store.resetActiveUnitRotation()" class="grid h-4 w-4 shrink-0 place-items-center rounded text-cream-300 hover:bg-ink-700" title="Reset rotation (group = cả nhóm)" aria-label="Reset rotation"><StudioIcon name="rotateCcw" size="h-3 w-3" /></button>
      </div>
      <div class="grid grid-cols-4 gap-1">
        <button @click="store.duplicateActiveUnit()" class="flex flex-col items-center justify-center gap-0.5 rounded-lg bg-ink-800 px-1 py-1.5 text-[9px] font-semibold text-cream-200 hover:bg-ink-700" title="Nhân đôi đối tượng (Ctrl+D) — group sẽ nhân đôi cả nhóm"><StudioIcon name="copy" size="h-3.5 w-3.5" /><span>Nhân đôi</span></button>
        <button @click="store.bringUnitTo(store.activeLayer.id, 'front')" class="flex flex-col items-center justify-center gap-0.5 rounded-lg bg-ink-800 px-1 py-1.5 text-[9px] font-semibold text-cream-200 hover:bg-ink-700" title="Đưa lên trên cùng (group = cả nhóm)"><StudioIcon name="chevronsUp" size="h-3.5 w-3.5" /><span>Lên đầu</span></button>
        <button @click="store.bringUnitTo(store.activeLayer.id, 'back')" class="flex flex-col items-center justify-center gap-0.5 rounded-lg bg-ink-800 px-1 py-1.5 text-[9px] font-semibold text-cream-200 hover:bg-ink-700" title="Đưa xuống dưới cùng (group = cả nhóm)"><StudioIcon name="chevronsDown" size="h-3.5 w-3.5" /><span>Xuống đáy</span></button>
        <button @click="store.fillActiveLayer()" class="flex flex-col items-center justify-center gap-0.5 rounded-lg bg-ink-800 px-1 py-1.5 text-[9px] font-semibold text-cream-200 hover:bg-ink-700" title="Tô màu toàn bộ layer đang chọn"><StudioIcon name="paintBucket" size="h-3.5 w-3.5" /><span>Tô màu</span></button>
      </div>
      <div class="grid grid-cols-2 gap-1">
        <button @click="store.toggleFlipX(store.activeLayer.id)" class="flex items-center justify-center gap-1 rounded-lg px-1 py-1.5 text-[10px] font-semibold" :class="store.activeLayer.flipX ? 'bg-brand-600/30 text-cream-100' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'" title="Lật ngang layer"><StudioIcon name="flipHorizontal" size="h-3.5 w-3.5" /><span>Lật ngang</span></button>
        <button @click="store.toggleFlipY(store.activeLayer.id)" class="flex items-center justify-center gap-1 rounded-lg px-1 py-1.5 text-[10px] font-semibold" :class="store.activeLayer.flipY ? 'bg-brand-600/30 text-cream-100' : 'bg-ink-800 text-cream-200 hover:bg-ink-700'" title="Lật dọc layer"><StudioIcon name="flipVertical" size="h-3.5 w-3.5" /><span>Lật dọc</span></button>
      </div>
      <button @click="removeBgConfirmOpen = true" :disabled="!store.activeLayer" class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-violet-500/40 bg-violet-600/15 px-2 py-1.5 text-[10px] font-semibold text-violet-200 transition-colors hover:bg-violet-600/30 disabled:opacity-40" title="Xóa nền AI (nền sẽ trong suốt; giữ vùng chọn hiện tại làm chủ thể nếu có)">
        <StudioIcon name="userX" size="h-3.5 w-3.5" />
        <span>Xóa nền AI · 1 credit</span>
      </button>
    </section>

    <!-- 4. Palette ảnh -->
    <section v-if="store.palette.length && store.step !== 3 && store.upscaleSrc" class="shrink-0 border-t border-ink-700 p-2.5">
      <p class="mb-1.5 flex items-center gap-1 text-[10px] font-semibold text-cream-300/60">
        <StudioIcon name="palette" size="h-3.5 w-3.5" />
        <span>Palette ảnh</span>
      </p>
      <div class="grid grid-cols-4 gap-1.5">
        <button v-for="c in store.palette.slice(0, 8)" :key="c" @click="copyColor(c)" class="h-7 w-full rounded-md border border-ink-700 transition hover:scale-105" :style="{ background: c }" :title="'Nhấn để copy ' + c" :aria-label="'Nhấn để copy ' + c"></button>
      </div>
    </section>

    <!-- 5. Footer: xuất / gộp / lưu -->
    <footer class="flex shrink-0 gap-1.5 border-t border-ink-700 p-2">
      <button @click="store.exportComposite()" :disabled="!store.visibleLayers.length" class="flex flex-1 items-center justify-center gap-1 rounded-lg bg-ink-800 px-2 py-1.5 text-[10px] font-semibold text-cream-200 hover:bg-ink-700 disabled:opacity-40" title="Gộp tất cả layer đang hiển thị và tải xuống PNG"><StudioIcon name="download" size="h-3.5 w-3.5" /><span>Xuất PNG</span></button>
      <button @click="store.flattenToLayer()" :disabled="!store.visibleLayers.length" class="flex flex-1 items-center justify-center gap-1 rounded-lg bg-ink-800 px-2 py-1.5 text-[10px] font-semibold text-cream-200 hover:bg-ink-700 disabled:opacity-40" title="Gộp tất cả layer đang hiển thị thành 1 layer mới"><StudioIcon name="layers" size="h-3.5 w-3.5" /><span>Gộp</span></button>
      <button @click="store.saveActiveLayerToOutput()" :disabled="!store.activeLayer" class="flex flex-1 items-center justify-center gap-1 rounded-lg bg-ink-800 px-2 py-1.5 text-[10px] font-semibold text-cream-200 hover:bg-ink-700 disabled:opacity-40" title="Lưu layer đang chọn vào Output"><StudioIcon name="save" size="h-3.5 w-3.5" /><span>Lưu Output</span></button>
    </footer>

    <!-- Popup xác nhận xóa nền AI (markup y hệt StudioApp :542-551) -->
    <div v-if="removeBgConfirmOpen" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4" @click.self="removeBgConfirmOpen = false">
      <div class="w-full max-w-xs rounded-lg border border-ink-700 bg-ink-900 p-4 shadow-2xl">
        <p class="text-sm font-semibold text-cream-100">Xóa nền AI?</p>
        <p class="mt-1 text-xs leading-relaxed text-cream-300/70">Nền sẽ được xóa thành <b>trong suốt</b> (PNG alpha). AI tự nhận diện chủ thể — <b>vẽ lasso quanh chủ thể</b> nếu muốn chính xác hơn. Tốn <b>1 credit</b>.</p>
        <div class="mt-3 flex gap-2">
          <button @click="doRemoveBg()" class="flex-1 rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500">Xóa nền</button>
          <button @click="removeBgConfirmOpen = false" class="flex-1 rounded-lg bg-ink-800 px-3 py-2 text-sm font-semibold text-cream-200 hover:bg-ink-700">Hủy</button>
        </div>
      </div>
    </div>
    
    <!-- Popup xác nhận dọn canvas -->
    <div v-if="store.confirmClearCanvasOpen" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4" @click.self="store.confirmClearCanvasOpen = false">
      <div class="w-full max-w-xs rounded-lg border border-red-500/40 bg-ink-900 p-4 shadow-2xl">
        <p class="text-sm font-semibold text-cream-100">⚠️ Dọn toàn bộ canvas?</p>
        <p class="mt-1 text-xs leading-relaxed text-cream-300/70">Tất cả <b>{{ store.canvasLayers.length }} layer</b> và <b>{{ store.layerGroups.length }} nhóm</b> sẽ bị xóa khỏi canvas. Ảnh kết quả vẫn còn trong <b>Output/Thư viện</b>. Có thể hoàn tác (Ctrl+Z).</p>
        <div class="mt-3 flex gap-2">
          <button @click="store.cleanCanvas()" class="flex-1 rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500">Dọn canvas</button>
          <button @click="store.confirmClearCanvasOpen = false" class="flex-1 rounded-lg bg-ink-800 px-3 py-2 text-sm font-semibold text-cream-200 hover:bg-ink-700">Hủy</button>
        </div>
      </div>
    </div>
  </aside>
</template>

<style scoped>
/* Chỉ CSS phụ trợ — màu sắc dùng utility Tailwind trong template. */
input[type='range'] {
  cursor: pointer;
}
</style>