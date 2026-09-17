<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Plan;
use App\Models\User;
use App\Services\CreditService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * JSON API cho trang Quản trị của Owner (/admin — SPA Vue).
 *
 * Phân quyền (2 tầng, defence-in-depth):
 *   - routes/web.php: nhóm [admin] (super_admin + admin) cho dashboard/plans/transactions;
 *     nhóm [superadmin] cho thao tác trên tài khoản người dùng.
 *   - $this->authorize() theo App\Policies\UserPolicy cho từng hành động người dùng.
 *
 * Mọi biến động credit đi qua App\Services\CreditService (sổ cái) — không bao giờ
 * increment/decrement credits_balance trực tiếp ở controller.
 */
class AdminController extends Controller
{
    /** Trang Quản trị SPA (shell Blade) — Vue mount vào #admin-root. */
    public function adminPage()
    {
        return view('studio.admin');
    }

    // ── Dashboard ──────────────────────────────────────────────────────────

    public function dashboard(): JsonResponse
    {
        $paidPlanIds = Plan::query()->where('is_active', true)->where('price_vnd', '>', 0)->pluck('id');

        $paying = User::query()
            ->whereIn('plan_id', $paidPlanIds)
            ->where(fn ($q) => $q->whereNull('plan_expires_at')->orWhere('plan_expires_at', '>', now()))
            ->count();

        $planDist = User::query()
            ->selectRaw('plan_id, count(*) as n')
            ->whereNotNull('plan_id')
            ->groupBy('plan_id')
            ->pluck('n', 'plan_id');

        $creditsSpent30d = (int) CreditTransaction::query()
            ->where('type', CreditTransaction::TYPE_SPEND)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        return response()->json([
            'kpis' => [
                'total_users' => User::count(),
                'new_users_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
                'paying_subscribers' => $paying,
                'generations_total' => Generation::where('status', 'completed')->count(),
                'generations_today' => Generation::where('status', 'completed')->whereDate('created_at', today())->count(),
                'credits_balance_total' => (int) User::sum('credits_balance'),
                'credits_spent_30d' => abs($creditsSpent30d),
            ],
            'plan_distribution' => $planDist,
            'plans' => Plan::query()->orderBy('sort')->get()->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price_vnd' => (int) $p->price_vnd,
                'users_count' => (int) ($planDist[$p->id] ?? 0),
            ]),
        ]);
    }

    // ── Users ──────────────────────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $q = User::query()->with('plan')->withCount('generations');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(fn ($qq) => $qq
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%'));
        }
        if ($role = (string) $request->query('role', '')) {
            $q->where('role', $role);
        }
        if (($status = (string) $request->query('status', '')) !== '') {
            $q->where('is_active', $status === 'active');
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $rows = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'users' => collect($rows->items())->map(fn (User $u) => $this->mapUser($u))->values(),
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::in([User::ROLE_CUSTOMER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);
        $user->forceFill(['role' => $data['role']])->save();

        if (! empty($data['plan_id'])) {
            app(PlanService::class)->assign($user, Plan::findOrFail((int) $data['plan_id']));
        }

        // Ghi sổ cái số dư khởi tạo (tài khoản do admin tạo).
        app(CreditService::class)->record($user->fresh(), (int) $user->fresh()->credits_balance, 'grant', [
            'admin_id' => auth()->id(),
            'note' => 'Tạo tài khoản bởi quản trị viên',
        ]);

        return response()->json(['user' => $this->mapUser($user->fresh()->load('plan')->loadCount('generations'))], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in([User::ROLE_CUSTOMER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])],
            'is_active' => ['nullable', 'boolean'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
        ]);

        $actor = auth()->user();

        // Không tự hạ quyền / tự khoá chính mình (tránh mất quyền quản trị cuối cùng).
        if ($actor->id === $user->id && $data['role'] !== User::ROLE_SUPER_ADMIN) {
            return response()->json(['message' => 'Không thể tự hạ quyền chính mình.'], 422);
        }
        if ($actor->id === $user->id && ($data['is_active'] ?? true) === false) {
            return response()->json(['message' => 'Không thể tự khoá chính mình.'], 422);
        }

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);
        $user->forceFill([
            'role' => $data['role'],
            'is_active' => (bool) ($data['is_active'] ?? $user->is_active),
        ])->save();

        // Gói cước: chỉ xử lý khi key có mặt trong request.
        if (array_key_exists('plan_id', $data)) {
            if ($data['plan_id'] === null) {
                $user->forceFill(['plan_id' => null, 'plan_expires_at' => null])->save();
            } else {
                app(PlanService::class)->assign($user, Plan::findOrFail((int) $data['plan_id']));
            }
        }

        return response()->json(['user' => $this->mapUser($user->fresh()->load('plan')->loadCount('generations'))]);
    }

    public function destroyUser(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->json(['ok' => true]);
    }

    public function adjustCredits(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = app(CreditService::class)->apply($user, (int) $data['amount'], 'adjust', [
            'admin_id' => auth()->id(),
            'note' => $data['note'] ?: 'Điều chỉnh credit bởi quản trị viên',
        ]);

        return response()->json([
            'user' => $this->mapUser($user->fresh()->load('plan')->loadCount('generations')),
            'transaction' => $this->mapTransaction($transaction->load(['user:id,name,email', 'admin:id,name'])),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorize('resetPassword', $user);

        $data = $request->validate([
            'password' => ['required', Password::min(8)],
        ]);

        $user->update(['password' => $data['password']]);

        return response()->json(['ok' => true]);
    }

    // ── Plans ──────────────────────────────────────────────────────────────

    public function plans(): JsonResponse
    {
        $plans = Plan::query()->withCount('users')->orderBy('sort')->get();

        return response()->json(['plans' => $plans->map(fn (Plan $p) => $this->mapPlan($p))]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request);

        if (! empty($data['is_default'])) {
            Plan::query()->update(['is_default' => false]);
        }

        $plan = Plan::create($data);

        return response()->json(['plan' => $this->mapPlan($plan)], 201);
    }

    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $data = $this->validatePlan($request, $plan);

        if (! empty($data['is_default'])) {
            Plan::query()->where('id', '!=', $plan->id)->update(['is_default' => false]);
        }

        $plan->update($data);

        return response()->json(['plan' => $this->mapPlan($plan->fresh())]);
    }

    public function destroyPlan(Plan $plan): JsonResponse
    {
        // Không xoá gói đang có người dùng — gợi ý tắt gói (is_active=false) thay vì xoá.
        $users = $plan->users()->count();
        if ($users > 0) {
            return response()->json([
                'message' => 'Gói đang có '.$users.' người dùng. Hãy tắt gói (ẩn) thay vì xoá để giữ lịch sử.',
            ], 422);
        }

        if ($plan->is_default) {
            return response()->json(['message' => 'Không thể xoá gói mặc định. Hãy đặt gói khác làm mặc định trước.'], 422);
        }

        $plan->delete();

        return response()->json(['ok' => true]);
    }

    // ── Transactions (sổ cái credit) ───────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $q = CreditTransaction::query()->with(['user:id,name,email', 'admin:id,name']);

        if ($type = (string) $request->query('type', '')) {
            $q->where('type', $type);
        }
        if ($userId = (int) $request->query('user_id', 0)) {
            $q->where('user_id', $userId);
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->whereHas('user', fn ($qq) => $qq
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $rows = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'transactions' => collect($rows->items())->map(fn (CreditTransaction $t) => $this->mapTransaction($t))->values(),
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    protected function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'tagline' => ['nullable', 'string', 'max:255'],
            'price_vnd' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'credits_per_month' => ['required', 'integer', 'min:0', 'max:1000000'],
            'bonus_credits' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'image_credit_cost' => ['nullable', 'integer', 'min:1', 'max:100'],
            'video_credit_cost' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'resolution_cap' => ['nullable', 'string', 'in:1K,2K'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    protected function mapUser(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'role_label' => $u->roleLabel(),
            'is_active' => (bool) $u->is_active,
            'credits_balance' => (int) $u->credits_balance,
            'plan' => $u->relationLoaded('plan') && $u->plan ? $this->mapPlan($u->plan) : null,
            'is_subscribed' => $u->isSubscribed(),
            'generations_count' => (int) ($u->generations_count ?? 0),
            'created_at' => $u->created_at?->format('d/m/Y'),
        ];
    }

    protected function mapPlan(Plan $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'tagline' => $p->tagline,
            'price_vnd' => (int) $p->price_vnd,
            'price_label' => $p->priceLabel(),
            'credits_per_month' => (int) $p->credits_per_month,
            'bonus_credits' => (int) $p->bonus_credits,
            'image_credit_cost' => (int) $p->image_credit_cost,
            'video_credit_cost' => (int) $p->video_credit_cost,
            'resolution_cap' => $p->resolution_cap,
            'features' => $p->features ?? [],
            'is_active' => (bool) $p->is_active,
            'is_default' => (bool) $p->is_default,
            'sort' => (int) $p->sort,
            'users_count' => (int) ($p->users_count ?? 0),
        ];
    }

    protected function mapTransaction(CreditTransaction $t): array
    {
        return [
            'id' => $t->id,
            'type' => $t->type,
            'type_label' => $t->typeLabel(),
            'amount' => (int) $t->amount,
            'balance_after' => (int) $t->balance_after,
            'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name, 'email' => $t->user->email] : null,
            'admin' => $t->admin ? ['id' => $t->admin->id, 'name' => $t->admin->name] : null,
            'note' => $t->note,
            'created_at' => $t->created_at?->format('d/m/Y H:i'),
        ];
    }
}
