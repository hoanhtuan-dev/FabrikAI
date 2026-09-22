// Shared studio store — each card component reads/writes this so they can share data.
// [Đợt tối ưu 2026-09-24] File này từng dài 5.658 dòng — nay là LỚP GỘP MỎNG: state/getters/actions
// sống ở ./store/*.js theo miền, gộp lại bằng spread. API công khai (useStudioStore + các helper
// apiError/safeMessage/userFacingError) GIỮ NGUYÊN ⇒ 37 file import không phải đổi gì.
import { defineStore } from 'pinia';
import { studioState } from './store/state.js';
import { studioGetters } from './store/getters.js';
import { accountActions } from './store/actions/account.js';
import { generationActions } from './store/actions/generation.js';
import { studioSceneActions } from './store/actions/studioScene.js';
import { canvasViewActions } from './store/actions/canvasView.js';
import { libraryActions } from './store/actions/library.js';
import { projectsActions } from './store/actions/projects.js';
import { agentStudioActions } from './store/actions/agentStudio.js';
import { agentChatActions } from './store/actions/agentChat.js';
import { layerCoreActions } from './store/actions/layerCore.js';
import { brushesActions } from './store/actions/brushes.js';
import { layerTransformActions } from './store/actions/layerTransform.js';
import { sourcesActions } from './store/actions/sources.js';
import { maskSelectActions } from './store/actions/maskSelect.js';
import { pathToolActions } from './store/actions/pathTool.js';
import { maskBrushActions } from './store/actions/maskBrush.js';
import { regionOpsActions } from './store/actions/regionOps.js';

export const useStudioStore = defineStore('studio', {
  state: studioState,
  getters: { ...studioGetters },
  actions: {
    ...accountActions,
    ...generationActions,
    ...studioSceneActions,
    ...canvasViewActions,
    ...libraryActions,
    ...projectsActions,
    ...agentStudioActions,
    ...agentChatActions,
    ...layerCoreActions,
    ...brushesActions,
    ...layerTransformActions,
    ...sourcesActions,
    ...maskSelectActions,
    ...pathToolActions,
    ...maskBrushActions,
    ...regionOpsActions,
  },
});

// Re-export helper: 37 file đang import { apiError, userFacingError } from '<...>/store.js'.
export { apiError, safeMessage, userFacingError } from './store/helpers.js';
