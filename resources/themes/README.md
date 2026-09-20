# resources/themes — theme gốc của FabrikAI

Thư mục này chứa **payload của theme daisyUI** mà toàn bộ bảng màu của sản phẩm được sinh ra từ đó.

## Vì sao có thư mục này

Từ 2026-09-25, bảng màu trong `resources/css/app.css` **không còn được viết tay**. Nó là **hàm** của
payload trong thư mục này, sinh bằng:

```bash
php artisan theme:sync          # ghi lại bảng màu vào resources/css/app.css
php artisan theme:sync --check  # chỉ kiểm tra (CI): lệch ⇒ mã lỗi 1
```

Lý do đổi: bảng viết tay đã trôi khỏi theme gốc mà không ai biết — `primary` còn là xanh lá
`#2d6f4d` trong khi theme gốc là tím `#605dff`, `secondary`/`accent` cũng lệch hẳn. Xem
`docs/DESIGN_SYSTEM.md` §1.1 và §1.5.

## Các tệp

| Tệp | Là gì |
|---|---|
| `daisyui-dark.theme.json` | Payload GỐC — **đúng** thứ trang Theme Generator phát ra (đã chuẩn hoá màu về `#rrggbb`). Đây là nguồn sự thật của chế độ TỐI. |
| `README.md` | Tệp này. |

**Không có tệp cho chế độ SÁNG** — và đó là chủ ý. Bản Sáng được `App\Support\ThemeDeriver` **sinh ra
lúc chạy** từ chính payload trên (giữ hue, đổi bậc sáng theo chuẩn của chế độ Sáng, hạ sắc độ màu trạng
thái cho đạt WCAG AA). Một tệp Sáng viết tay ở đây sẽ là **nguồn thứ hai** để lệch.

## Liên kết gốc

```
https://daisyui.com/theme-generator/#theme=eJxtlOuO2yAQhV8FIVVqpWTEDBfjvI1j48Zax0TgaLe76rtXhm1iB_9k5juHM4D44lNzdfzEuya88QNv_ejDMbYXt64ej7l-bqI7ohD8xP3b2F5-kgYpfzABAg0jTaDo1ytPa14CmcwrRloCFrhc4wioM06MtAJRF3zrp9lN80NTV2BFlURUM9IGrKrWqlsYrk348xBou8AkJaOqAsQ9uNzlewrLqCKQqNai6Fo_des9TJqCFDKpFUhhd_FyF5XnsEwqAtIbWdO2a7ZKM6MmhhahRirZwl-m0YWRDK0FhWatmdx9Ds34gDGHEZqR1WBJ7sDlBJRFipE1IDehhqn3z_jJfXlFksAYfAULZ6pzdsNISUC9ubZ4b1sX49M9XRdWFUMjgbbZv-HydPIrSiIL9eaK35swDdPvB2vTnGhrZhUoqnfYwl9h0iAxpaEWG38Xgg_P-JmsFUMJimxBlqeTX4PQDAnE_-yh6YZ7PEY3unZO_gJ0cNd1sx_c2KUOvbbO_mMricOn27rRtrdndvahcwuNt49U6NxtvizrtJr8EJevR_AD71zf3MeZn_pmjO7Ab8H1LsT0K-Xa33-M5lV3
```

Theme: `dark` (theme **Tối** mặc định của daisyUI) · `color-scheme: dark`.

## Đổi theme gốc

1. Mở `daisyui.com/theme-generator`, chỉnh theme, copy liên kết (hoặc dùng liên kết khách đưa).
2. Ghi lại payload đã giải mã vào `daisyui-dark.theme.json` — có thể lấy nhanh bằng:

   ```bash
   php -r 'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
   echo json_encode(App\Support\DaisyThemeLink::decode($argv[1])[0]->toArray(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;' '<liên kết>'
   ```

3. `php artisan theme:sync` → `php artisan test --filter=Theme` → `npm run build`.

Muốn thử một theme mà **không** đổi theme gốc của sản phẩm: dùng **Thư viện theme** ở
`/he-thong-thiet-ke` (cấp OWNER) — theme import được lưu vào bảng `themes` và phát vào `<head>`, không
đụng tới tệp này.
