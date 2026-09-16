<?php
namespace App\Modules\Studio;

/**
 * Studio Bridge — facade gọn gàng giữa Studio module và phần còn lại của app.
 * Mọi phụ thuộc xuyên nhóm (Studio UI, admin pages, ngoài module) nên gọi qua đây
 * thay vì new trực tiếp service, để dễ thay thế / mock.
 */
class StudioBridge
{
    public function garmentTypes(): array { return app(\App\Services\StylistCatalog::class)->garmentTypes(); }

    /**
     * Trạng thái + transition của luồng công việc Designer (cho UI render).
     * Trả metadata tĩnh; trạng thái khả dụng theo từng project được tính riêng
     * trong ProjectController (cần user context).
     */
    public function workflowStates(): array
    {
        return app(\App\Services\ProjectWorkflowService::class)->states();
    }
}
