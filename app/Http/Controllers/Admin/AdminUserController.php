<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdminUserController extends Controller
{
    /**
     * Chỉ Super Admin mới xem được danh sách tài khoản (UserPolicy::viewAny).
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $query = User::latest();

        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            });
        }

        $users = $query->paginate(15)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function store(Request $request)
    {
        // Chỉ Super Admin mới được tạo tài khoản.
        $this->authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'in:customer,admin,super_admin'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => in_array($data['role'], User::ADMIN_ROLES, true) ? $data['role'] : User::ROLE_CUSTOMER,
            'password' => $data['password'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return back()->with('success', 'Đã tạo người dùng '.$user->name.'.');
    }

    public function update(Request $request, User $user)
    {
        $isSelf = $user->id === auth()->id();

        // Không được sửa tài khoản Super Admin cuối cùng (tránh mất quyền quản trị).
        // Trừ trường hợp Super Admin tự sửa thông tin cá nhân của chính mình.
        if (! $isSelf && $this->isLastSuperAdmin($user)) {
            return back()->with('error', 'Không thể sửa tài khoản Super Admin cuối cùng.');
        }
        $this->authorize('update', $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'in:customer,admin,super_admin'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Khi tự sửa chính mình: KHÔNG cho phép đổi role và trạng thái hoạt động
        // (tránh tự hạ quyền / tự khóa tài khoản, gây lockout hệ thống).
        if ($isSelf) {
            $data['role'] = $user->role;
            $data['is_active'] = $user->is_active;
        } else {
            $data['role'] = in_array($data['role'], User::ADMIN_ROLES, true) ? $data['role'] : User::ROLE_CUSTOMER;
            $data['is_active'] = (bool) ($data['is_active'] ?? true);
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->role = $data['role'];
        $user->is_active = $data['is_active'];

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return back()->with('success', 'Đã cập nhật người dùng.');
    }

    public function updatePassword(Request $request, User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Không thể tự đặt lại mật khẩu của chính bạn tại đây.');
        }
        if ($this->isLastSuperAdmin($user)) {
            return back()->with('error', 'Không thể sửa tài khoản Super Admin cuối cùng.');
        }
        $this->authorize('resetPassword', $user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return back()->with('success', 'Đã đặt lại mật khẩu cho '.$user->name.'.');
    }

    public function toggleActive(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Không thể tự khóa tài khoản của chính bạn.');
        }
        if ($this->isLastSuperAdmin($user)) {
            return back()->with('error', 'Không thể khóa tài khoản Super Admin cuối cùng.');
        }
        $this->authorize('update', $user);

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', 'Đã cập nhật trạng thái người dùng.');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Không thể xóa tài khoản của chính bạn.');
        }
        if ($this->isLastSuperAdmin($user)) {
            return back()->with('error', 'Không thể xóa tài khoản Super Admin cuối cùng.');
        }
        $this->authorize('delete', $user);

        if ($user->isAdmin()) {
            return back()->with('error', 'Không thể xóa tài khoản quản trị.');
        }

        $user->delete();

        return back()->with('success', 'Đã xóa người dùng.');
    }

    /**
     * TRUE nếu $user là Super Admin duy nhất còn lại — không được phép hạ quyền/khóa/xóa,
     * tránh mất quyền quản trị cuối cùng của hệ thống.
     */
    protected function isLastSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin()
            && User::where('role', User::ROLE_SUPER_ADMIN)->count() <= 1;
    }
}
