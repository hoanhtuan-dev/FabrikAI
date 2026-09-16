<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Không cần hit() thủ công: middleware throttle:login đã đếm MỌI request tới route này.
            return back()->withErrors(['email' => 'Thông tin đăng nhập không chính xác.']);
        }

        // CỐ Ý KHÔNG "clear bộ đếm khi đăng nhập đúng" ở đây.
        // Đã thử và ĐO LẠI: middleware throttle:login lưu bộ đếm dưới khoá
        // md5($limiterName.$limitKey) (ThrottleRequests.php:134 — self::$shouldHashKeys), còn
        // RateLimiter::clear(auth_throttle_key($request)) lại tra khoá THÔ -> xoá một khoá không tồn
        // tại, tức bản "xoá bộ đếm" chỉ là no-op trang trí (và test đọc khoá thô nên PASS RỖNG).
        // Muốn xoá đúng phải bám công thức md5 nội bộ — giòn theo phiên bản framework.
        // Cửa sổ 5 lần/phút đã đủ rộng: gõ sai 3 lần rồi gõ đúng vẫn vào được (có test quan sát được).

        $request->session()->regenerate();

        $redirect = $request->input('redirect');
        if (is_string($redirect) && str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return redirect($redirect);
        }

        return redirect('/');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            // KHÔNG truyền 'role' ở đây: `role` không nằm trong User::$fillable (cố ý — chống tự
            // nâng quyền), nên truyền vào cũng bị bỏ qua im lặng. Mặc định 'customer' nay khai báo
            // tường minh ở User::$attributes.
        ]); 

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
