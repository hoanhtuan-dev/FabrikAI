<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\BlogCategory;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Post;
use App\Models\Preset;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    protected array $samples = [
        '2aOboQqblAMOiMufB3AkbldbRcHTw1FcZniDtBtg.jpg',
        '2aOboQqbQqh4oV35fDW94LVzGivFikccYi6W4EqG.jpg',
        '2aOboQqbUuwLYr8V7gYLtLCmxe0SdB4IMsRpCiDw.jpg',
        '2aOboQqbVmtc4ajmAmq9dfsqiXBargzWXJdQ7g6S.jpg',
        '2aOboQqbWnpuJ8CwGq04jI8TAuaUBXqFq4CGuDrs.jpg',
        '2aOboQqcO01UEvHriIvno7snaXwcoEJKh0KjKs40.jpg',
        '2aOboQqdBCum8rLLtvNyf97BqIIFWihR0b2nIFY8.jpg',
        '2aOboQqOBTafTId01XeJZjcygtyDg1c3MhyN31l2.jpg',
        '2aOboQqOBTR5uosVCsNhbUXA5FrAsBRBPGV455LU.jpg',
        '2aOboQqOBTVICjd445u8vYekOYuc7POrpG7sNsSO.jpg',
        '2aOboQqwiWdy0U1XcjR2b2Prv0bbLIV7XQHArvcG.jpg',
        '2aOboQqwO6c0wZZ7QOb0dRAocoIOvSEYROi8wjyK.jpg',
        '2aOboQqwOPpKBzJJc9LORgKByYnNb1GfrnmpElaC.jpg',
        '2aOboQqWyw5goWZNeUzwabecmtsgyqMlrfl7aAuO.jpg',
        '2aOboQqWzNl9GD7n5CAMLfyIZCLR418oKvhF1ugi.jpg',
        '2aOboQqWzNyULZGgDnLJMHK4Gyhz2M3zvcJh0Fxg.jpg',
        '2aOboQqxrAsqbkGAbHwplpXgfcxKZtkNfJAJQ9pY.jpg',
        '2aOboQqxrEYUatPd23BDVTAjI8BU3Pau8dsyaUnA.jpg',
        '2aOboQqxrgdZX6pa9L6NUdw4tpnrB3zWyUdfTk8G.jpg',
        '2aOboQqxUUVBVzDc81exE31Nm1Mz6rCjZS11dyr2.jpg',
        '2aOboQqYvkXKiGyUiupCkfAkexFC5tnuYVTfH4Ns.jpg',
        '2aOboQqzyNjYEetX2fZyATVNCgcQoatZcmOKYSgq.jpg',
        '2aOboQqzzJHb5etG6miSyeTWPr4XLqZPUMIZEcgy.jpg',
        '2aOboQr0HCs03o2crLIG1suwLBPHUnCCMYc1enZo.jpg',
        '2aOboQr0HF2hCUY1rBu4z1Rd2b7HOxZnRNwdXOkK.jpg',
        '2aOboQrGJ4gpkR84yzoOPCvrEv4NaIdxp5pC8HOC.jpg',
        '2aOboQrGJ4Pj8CanWSj6MFVJ1xiwOYY5srLPEBjk.jpg',
        '2aOboQrGJ4pooaktFXy8iOZbkRaFyYPgEBShVm1A.jpg',
        '2aOboQrIE7h9RdQlrXMVcFvpiBgHImcBLt8GwIDY.jpg',
        '2aOboQrIJs0nI7Dg5nUjxwV3CLU2eyKQPAXhJWIi.jpg',
        '41653248-dd09-4f21-a30f-976a72e2eb62.jpeg',
        '4be0cd05-cce1-427e-a90d-c3c43c29f7a1.jpeg',
    ];

    protected function img(string $seed, int $w = 600, int $h = 750): string
    {
        static $idx = 0;
        $file = $this->samples[$idx % count($this->samples)];
        $idx++;

        return 'samples/'.$file;
    }

    public function run(): void
    {
        $this->settings();
        $this->users();
        $this->categories();
        $this->products();
        $this->shipping();
        $this->payments();
        $this->coupons();
        $this->banners();
        $this->blog();
        $this->menus();
        $this->reviews();
        $this->orders();
        $this->presets();
        $this->call(FacePresetSeeder::class);
        $this->call(PosePresetSeeder::class);
        $this->call(StylistCatalogSeeder::class);
        $this->call(StudioProjectSeeder::class);

        $this->command?->info('Seeded Trillfa Fa successfully.');
    }

    protected function settings(): void
    {
        $settings = [
            'site_name' => 'Trillfa Fa',
            'site_tagline' => 'Thời trang & Phong cách sống',
            'site_email' => 'hello@trillfa.com',
            'site_phone' => '1900 6363',
            'site_address' => '123 Nguyễn Huệ, Quận 1, TP. Hồ Chí Minh',
            'free_shipping_threshold' => '500000',
            'default_shipping_fee' => '30000',
            'facebook' => 'https://facebook.com',
            'instagram' => 'https://instagram.com',
            'tiktok' => 'https://tiktok.com',
            'youtube' => 'https://youtube.com',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    protected function users(): void
    {
        // Super Admin — tài khoản quyền cao nhất, duy nhất được quản lý tài khoản khác.
        User::updateOrCreate(['email' => 'tuan.ho.designer@gmail.com'], [
            'name' => 'Tuan Ho (Super Admin)',
            'password' => Hash::make('hattf2768'),
            'role' => User::ROLE_SUPER_ADMIN,
            'phone' => '0900000001',
            'credits_balance' => 0,
            'email_verified_at' => now(),
        ]);

        // Admin thường — vào được khu vực quản trị nhưng KHÔNG quản lý tài khoản.
        User::updateOrCreate(['email' => 'admin@trillfa.com'], [
            'name' => 'Quản trị Trillfa',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'phone' => '0900000000',
            'credits_balance' => 1000,
            'email_verified_at' => now(),
        ]);

        User::updateOrCreate(['email' => 'customer@trillfa.com'], [
            'name' => 'Nguyễn Văn An',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'phone' => '0912345678',
            'credits_balance' => 200,
            'email_verified_at' => now(),
        ]);

        $names = ['Trần Thị Bích', 'Lê Minh Cường', 'Phạm Thu Hà', 'Võ Đức Duy', 'Đặng Kim Ngân'];
        foreach ($names as $i => $name) {
            $email = 'khach'.($i + 1).'@trillfa.com';
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => 'customer',
                'phone' => '09'.rand(10000000, 99999999),
                'email_verified_at' => now(),
            ]);
            $user->addresses()->create([
                'name' => $name,
                'phone' => $user->phone,
                'address' => rand(1, 200).' Đường '.['Lê Lợi', 'Nguyễn Trãi', 'Trần Hưng Đạo', 'Cách Mạng Tháng Tám'][rand(0, 3)],
                'ward' => 'Phường '.rand(1, 20),
                'district' => 'Quận '.rand(1, 12),
                'province' => 'TP. Hồ Chí Minh',
                'is_default' => true,
            ]);
        }
    }

    protected function categories(): void
    {
        $cats = [
            ['Thời trang nữ', 'thoi-trang-nu', null, 1, [
                'Áo nữ' => ['ao-nu', 1], 'Đầm & Váy' => ['dam-vay', 2], 'Quần nữ' => ['quan-nu', 3],
            ]],
            ['Thời trang nam', 'thoi-trang-nam', null, 2, [
                'Áo nam' => ['ao-nam', 1], 'Quần nam' => ['quan-nam', 2],
            ]],
            ['Giày dép', 'giay-dep', null, 3, []],
            ['Phụ kiện', 'phu-kien', null, 4, [
                'Túi xách' => ['tui-xach', 1], 'Kính mắt' => ['kinh-mat', 2], 'Đồng hồ' => ['dong-ho', 3],
            ]],
            ['Nhà cửa & Đời sống', 'nha-cua-doi-song', null, 5, [
                'Trang trí' => ['trang-tri', 1], 'Gia dụng' => ['gia-dung', 2],
            ]],
            ['Sức khỏe & Làm đẹp', 'suc-khoe-lam-dep', null, 6, []],
        ];

        foreach ($cats as [$name, $slug, $parent, $sort, $children]) {
            $cat = Category::updateOrCreate(['slug' => $slug], [
                'name' => $name, 'description' => "Danh mục {$name}.", 'is_active' => true, 'sort_order' => $sort,
            ]);
            foreach ($children as $cName => [$cSlug, $cSort]) {
                Category::updateOrCreate(['slug' => $cSlug], [
                    'name' => $cName, 'parent_id' => $cat->id, 'is_active' => true, 'sort_order' => $cSort,
                ]);
            }
        }

        $iconMap = [
            'thoi-trang-nu' => 'heart', 'ao-nu' => 'shirt', 'dam-vay' => 'star', 'quan-nu' => 'shirt',
            'thoi-trang-nam' => 'star', 'ao-nam' => 'shirt', 'quan-nam' => 'shirt',
            'giay-dep' => 'bag',
            'phu-kien' => 'bag', 'tui-xach' => 'bag', 'kinh-mat' => 'eye', 'dong-ho' => 'clock',
            'nha-cua-doi-song' => 'home', 'trang-tri' => 'gift', 'gia-dung' => 'home',
            'suc-khoe-lam-dep' => 'sparkles',
        ];

        foreach ($iconMap as $slug => $icon) {
            Category::where('slug', $slug)->update(['icon' => $icon]);
        }
    }

    protected function products(): void
    {
        $p = [
            // [name, catSlug, brand, price, compare, stock, featured, hasVariants, desc]
            ['Đầm Linen Trắng', 'dam-vay', 'Trillfa', 890000, 1190000, 40, true, true, 'Đầm linen trắng thanh lịch, chất liệu thoáng mát cho ngày hè.'],
            ['Đầm Rút Eo Be', 'dam-vay', 'Trillfa', 790000, null, 32, true, true, 'Đầm rút eo tôn dáng với chất liệu cao cấp.'],
            ['Áo Sơ Mi Cổ V Nữ', 'ao-nu', 'Aura', 450000, 650000, 60, true, true, 'Áo sơ mi cổ V nữ sang trọng, phù hợp công sở.'],
            ['Áo Thun Cotton Nữ', 'ao-nu', 'Aura', 290000, 390000, 80, false, false, 'Áo thun cotton trắng mềm mại, giữ form.'],
            ['Quần Tây Nữ Cao Cấp', 'quan-nu', 'Trillfa', 620000, null, 45, false, true, 'Quần tây nữ form suông, co giãn nhẹ.'],
            ['Áo Polo Nam', 'ao-nam', 'Menv', 420000, 520000, 70, true, true, 'Áo polo nam chất liệu pique thoáng mát.'],
            ['Áo Sơ Mi Nam Oxford', 'ao-nam', 'Menv', 560000, null, 55, true, true, 'Áo sơ mi Oxford nam kinh điển, bền đẹp.'],
            ['Quần Jeans Slim Nam', 'quan-nam', 'Menv', 780000, 920000, 50, false, true, 'Quần jeans slim nam cá tính, co giãn.'],
            ['Giày Sneaker Trắng', 'giay-dep', 'Kicks', 990000, 1290000, 36, true, true, 'Sneaker trắng phối nhiều outfit, đế êm.'],
            ['Giày Loafer Da', 'giay-dep', 'Kicks', 1250000, null, 20, false, true, 'Giày loafer da bò sang trọng.'],
            ['Túi Tote Da', 'tui-xach', 'Trillfa', 690000, 850000, 44, true, false, 'Túi tote da sạch, đựng được laptop.'],
            ['Túi Đeo Chéo Mini', 'tui-xach', 'Trillfa', 490000, null, 38, false, false, 'Túi đeo chéo mini thời trang, tiện lợi.'],
            ['Kính Mát Pilot', 'kinh-mat', 'Eyes', 380000, 480000, 50, false, false, 'Kính mát pilot tròng UV 400.'],
            ['Đồng Hồ Dây Da', 'dong-ho', 'Time', 1450000, 1750000, 15, true, false, 'Đồng hồ dây da cổ điển, chống nước 3ATM.'],
            ['Váy Xếp Ly', 'dam-vay', 'Trillfa', 520000, null, 33, false, true, 'Váy xếp ly tôn dáng, chất liệu mềm rủ.'],
            ['Gối Trang Trí Họa Tiết', 'trang-tri', 'Home', 189000, null, 90, false, false, 'Gối trang trí họa tiết chữ nhật, bọc ngoài tháo giặt.'],
            ['Đèn Bàn Nhựa Tối Giản', 'trang-tri', 'Home', 340000, 420000, 24, false, false, 'Đèn bàn tối giản, ánh sáng vàng ấm áp.'],
            ['Bộ Chén Gốm Sứ', 'gia-dung', 'Home', 260000, null, 60, false, false, 'Bộ chén gốm sứ trắng cao cấp, 10 món.'],
            ['Serum Vitamin C', 'suc-khoe-lam-dep', 'Glow', 390000, 520000, 100, true, false, 'Serum Vitamin C dưỡng sáng da, giảm thâm.'],
            ['Nước Hoa Mini', 'suc-khoe-lam-dep', 'Glow', 820000, null, 18, false, false, 'Nước hoa mini hương gỗ, sang trọng.'],
            ['Kính Mát Chống UV Nữ', 'kinh-mat', 'Eyes', 320000, null, 42, false, false, 'Kính mát nữ mắt mèo, chống tia UV.'],
            ['Áo Khoác Denim Nữ', 'ao-nu', 'Aura', 920000, 1120000, 22, true, true, 'Áo khoác denim nữ dáng rộng, chất liệu mạnh.'],
            ['Áo Blazer Nữ', 'ao-nu', 'Aura', 1250000, null, 14, true, true, 'Áo blazer nữ công sở, cắt may chuẩn.'],
            ['Quần Short Nam', 'quan-nam', 'Menv', 360000, null, 66, false, true, 'Quần short nam thoáng mát cho mùa hè.'],
        ];

        foreach ($p as $i => [$name, $catSlug, $brand, $price, $compare, $stock, $featured, $hasVariants, $desc]) {
            $cat = Category::where('slug', $catSlug)->first();
            $slug = Str::slug($name);
            $product = Product::updateOrCreate(['slug' => $slug], [
                'category_id' => $cat->id,
                'name' => $name,
                'sku' => 'TF-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'brand' => $brand,
                'short_description' => $desc,
                'description' => '<h2>Mô tả sản phẩm</h2><p>'.$desc.'</p><p>Sản phẩm thuộc bộ sưu tập Trillfa Fa — thiết kế tối giản, chất liệu cao cấp và độ bền bỉ. Đây là lựa chọn hoàn hảo cho phong cách hiện đại, tinh tế.</p><ul><li>Chất liệu cao cấp, thân thiện môi trường</li><li>Thiết kế tối giản, dễ phối đồ</li><li>Đổi trả trong 7 ngày</li></ul>',
                'price' => $price,
                'compare_price' => $compare,
                'stock' => $stock,
                'featured' => $featured,
                'is_active' => true,
                'sales_count' => rand(5, 300),
                'rating_avg' => rand(35, 50) / 10,
                'rating_count' => rand(3, 60),
                'image' => $this->img($slug),
                'gallery' => [$this->img($slug.'-2', 800, 1000), $this->img($slug.'-3', 800, 1000)],
                'tags' => $featured ? ['bestseller', 'hot'] : [],
                'attributes' => $this->attributesFor($catSlug, $brand),
            ]);

            // Variants
            if ($hasVariants) {
                $v = [
                    ['S', $price], ['M', $price + 30000], ['L', $price + 30000],
                ];
                foreach ($v as [$size, $vprice]) {
                    $product->variants()->updateOrCreate(
                        ['name' => $size],
                        ['options' => ['size' => $size], 'price' => $vprice, 'stock' => max(2, $stock - rand(0, 10)), 'image' => $this->img($slug.'-'.$size)]
                    );
                }
            }
        }
    }

    protected function attributesFor(string $catSlug, string $brand): ?array
    {
        $common = ['Thương hiệu' => $brand, 'Xuất xứ' => 'Việt Nam'];

        return match ($catSlug) {
            'dam-vay', 'ao-nu', 'ao-nam', 'quan-nu', 'quan-nam' => $common + ['Chất liệu' => 'Cotton', 'Bảo quản' => 'Giặt nhẹ'],
            'giay-dep' => $common + ['Size' => '36–42', 'Chất liệu' => 'Da bò'],
            'tui-xach' => $common + ['Chất liệu' => 'Da tổng hợp'],
            'kinh-mat' => $common + ['Tròng kính' => 'UV400'],
            'dong-ho' => $common + ['Chống nước' => '3ATM'],
            'suc-khoe-lam-dep' => $common + ['Dung tích' => '30ml'],
            default => $common,
        };
    }

    protected function shipping(): void
    {
        $methods = [
            ['Giao tiêu chuẩn', 'standard', 'Giao trong 3–5 ngày.', 30000, 500000, 5, 1],
            ['Giao nhanh', 'express', 'Giao trong 1–2 ngày.', 50000, null, 2, 2],
            ['Nhận tại cửa hàng', 'pickup', 'Nhận tại cửa hàng Trillfa Fa.', 0, null, 0, 3],
        ];
        foreach ($methods as [$name, $code, $desc, $fee, $free, $days, $sort]) {
            ShippingMethod::updateOrCreate(['code' => $code], [
                'name' => $name, 'description' => $desc, 'fee' => $fee,
                'free_threshold' => $free, 'estimated_days' => $days, 'sort_order' => $sort, 'is_active' => true,
            ]);
        }
    }

    protected function payments(): void
    {
        $methods = [
            ['Thanh toán khi nhận hàng (COD)', 'cod', 'Thanh toán tiền mặt khi nhận hàng.', 0, 1],
            ['Chuyển khoản ngân hàng', 'bank', 'Chuyển khoản vào tài khoản ngân hàng của chúng tôi.', 0, 2],
            ['VNPay', 'vnpay', 'Thanh toán online qua cổng VNPay.', 0, 3],
            ['Ví MoMo', 'momo', 'Thanh toán online qua ví MoMo.', 0, 4],
        ];
        foreach ($methods as [$name, $code, $desc, $fee, $sort]) {
            PaymentMethod::updateOrCreate(['code' => $code], [
                'name' => $name, 'description' => $desc, 'fee' => $fee, 'sort_order' => $sort, 'is_active' => true,
            ]);
        }
    }

    protected function coupons(): void
    {
        $coupons = [
            ['WELCOME10', 'percent', 10, 500000, 200000, 100, now()->subDay(), now()->addDays(60)],
            ['SALE15', 'percent', 15, 300000, 300000, 200, now()->subDay(), now()->addDays(30)],
            ['GIAM50K', 'fixed', 50000, 200000, null, 50, now()->subDay(), now()->addDays(15)],
        ];
        foreach ($coupons as [$code, $type, $value, $min, $max, $limit, $start, $end]) {
            Coupon::updateOrCreate(['code' => $code], [
                'type' => $type, 'value' => $value, 'min_order' => $min, 'max_discount' => $max,
                'usage_limit' => $limit, 'starts_at' => $start, 'ends_at' => $end, 'is_active' => true,
            ]);
        }
    }

    protected function banners(): void
    {
        $banners = [
            ['Bộ sưu tập mới 2025', 'Phong cách từ sự tối giản', 'banner-collection', 'Mua ngay', '/shop', 'hero', 1],
            ['Giảm giá đến 50%', 'Ưu đãi cuối tuần', 'banner-sale', 'Khám phá', '/shop?sort=price_desc', 'hero', 2],
            ['Flash Sale', 'Mua 1 tặng 1', 'banner-flash', 'Săn deal', '/shop', 'hero', 3],
            ['Túi xách thời thượng', 'Phụ kiện chọn lọc', 'banner-tui', '/danh-muc/phu-kien', '/danh-muc/phu-kien', 'secondary', 1],
            ['Đồng hồ cổ điển', 'Thời gian là phong cách', 'banner-dongho', '/danh-muc/dong-ho', '/danh-muc/dong-ho', 'secondary', 2],
        ];
        foreach ($banners as [$title, $sub, $seed, $btn, $link, $pos, $sort]) {
            Banner::updateOrCreate(['title' => $title], [
                'subtitle' => $sub, 'image' => $this->img($seed), 'button_text' => $btn, 'button_link' => $link,
                'position' => $pos, 'sort_order' => $sort, 'is_active' => true,
            ]);
        }
    }

    protected function blog(): void
    {
        $cats = [
            ['Phong cách', 'phong-cach', 1],
            ['Mẹo hay', 'meo-hay', 2],
            ['Câu chuyện', 'cau-chuyen', 3],
        ];
        foreach ($cats as [$name, $slug, $sort]) {
            BlogCategory::updateOrCreate(['slug' => $slug], [
                'name' => $name, 'sort_order' => $sort, 'is_active' => true,
            ]);
        }

        $admin = User::where('email', 'admin@trillfa.com')->first();
        $posts = [
            ['Phối đồ công sở tối giản nhưng tinh tế', 'phong-cach', 'Bí quyết phối đồ công sở để luôn tự tin và thanh lịch, dành cho quý cô hiện đại.', 1],
            ['Mẹo chọn giày phù hợp từng dáng người', 'meo-hay', 'Cách chọn giày giúp bạn thoải mái và tôn dáng hơn mỗi ngày.', 0],
            ['Câu chuyện về chất liệu bền vững', 'cau-chuyen', 'Hành trình Trillfa Fa hướng tới thời trang bền vững và có trách nhiệm.', 1],
            ['Top 5 kiểu đầm cho mùa hè', 'phong-cach', 'Những mẫu đầm được yêu thích nhất mùa hè này.', 0],
            ['Cách bảo quản đồ da bền lâu', 'meo-hay', 'Bí quyết bảo quản túi xách, giày da luôn như mới.', 0],
            ['Phong cách tối giản là gì?', 'cau-chuyen', 'Hiểu về phong cách tối giản và cách áp dụng vào cuộc sống.', 1],
        ];

        foreach ($posts as $i => [$title, $catSlug, $excerpt, $featured]) {
            $cat = BlogCategory::where('slug', $catSlug)->first();
            Post::updateOrCreate(['slug' => Str::slug($title)], [
                'blog_category_id' => $cat->id,
                'author_id' => $admin->id,
                'title' => $title,
                'excerpt' => $excerpt,
                'body' => '<h2>'.$title.'</h2><p>'.$excerpt.'</p><p>Trong thời đại hôm nay, phong cách không chỉ là quần áo mà là cách thể hiện bản thân. Trillfa Fa tin rằng sự tối giản và tinh tế luôn bền vững theo thời gian.</p><blockquote>"Tối giản không phải là ít, mà là đủ."</blockquote><p>Hãy bắt đầu hành trình phong cách của bạn cùng Trillfa Fa.</p>',
                'image' => $this->img('post-'.Str::slug($title), 1200, 700),
                'status' => 'published',
                'published_at' => now()->subDays($i * 3),
                'is_featured' => $featured,
                'tags' => ['phong-cach', 'trillfa', 'meo-hay'],
                'views_count' => rand(100, 5000),
            ]);
        }
    }

    protected function reviews(): void
    {
        $users = User::where('role', 'customer')->get();
        $products = Product::inRandomOrder()->limit(12)->get();
        $titles = ['Rất ưng ý', 'Chất lượng tốt', 'Đáng tiền', 'Giao hàng nhanh', 'Sẽ mua lại', 'Sản phẩm đẹp'];
        $bodies = ['Chất liệu tuyệt vời, đúng như mô tả. Sẽ ủng hộ shop lần sau.', 'Dịch vụ tốt, đóng gói cẩn thận, giao hàng nhanh.', 'Sản phẩm đẹp hơn mong đợi, rất đáng tiền.', 'Chất lượng ổn, lần sau sẽ mua thêm.', 'Trải nghiệm mua sắm tuyệt vời, nhân viên hỗ trợ nhiệt tình.'];

        foreach ($products as $product) {
            $count = rand(1, 3);
            foreach ($users->random(min($count, $users->count())) as $user) {
                Review::updateOrCreate(
                    ['product_id' => $product->id, 'user_id' => $user->id],
                    [
                        'rating' => rand(3, 5),
                        'title' => $titles[array_rand($titles)],
                        'body' => $bodies[array_rand($bodies)],
                        'is_active' => true,
                        'helpful_count' => rand(0, 20),
                    ]
                );
            }
        }
    }

    protected function orders(): void
    {
        $customer = User::where('email', 'customer@trillfa.com')->first();
        $coupon = Coupon::where('code', 'WELCOME10')->first();

        foreach (range(1, 4) as $i) {
            $products = Product::inRandomOrder()->limit(rand(1, 3))->get();
            if ($products->isEmpty()) {
                continue;
            }

            $subtotal = 0;
            $items = [];
            foreach ($products as $product) {
                $qty = rand(1, 2);
                $price = (float) $product->min_price;
                $subtotal += $price * $qty;
                $items[] = [
                    'product' => $product,
                    'price' => $price,
                    'qty' => $qty,
                    'options' => null,
                ];
            }

            $shippingFee = (float) setting('free_shipping_threshold', 500000) > 30000 && $subtotal >= (float) setting('free_shipping_threshold', 500000) ? 0 : 30000;
            $discount = $subtotal >= 500000 ? round($subtotal * 0.10, 2) : 0;
            $total = round($subtotal - $discount + $shippingFee, 2);
            $status = ['completed', 'completed', 'shipped', 'pending'][$i - 1];

            $order = Order::create([
                'order_number' => Order::generateNumber(),
                'user_id' => $customer->id,
                'email' => $customer->email,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => '123 Đường Nguyễn Huệ',
                'ward' => 'Phường Bến Nghé',
                'district' => 'Quận 1',
                'province' => 'TP. Hồ Chí Minh',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping_fee' => $shippingFee,
                'tax' => 0,
                'total' => $total,
                'shipping_method' => 'Giao tiêu chuẩn',
                'payment_method' => $i === 2 ? 'vnpay' : 'cod',
                'payment_status' => $status === 'pending' ? 'unpaid' : 'paid',
                'coupon_id' => $discount > 0 ? $coupon->id : null,
                'status' => $status,
                'placed_at' => now()->subDays($i * 5),
                'paid_at' => $status === 'pending' ? null : now()->subDays($i * 5),
                'shipped_at' => in_array($status, ['shipped', 'completed']) ? now()->subDays($i * 5 - 1) : null,
                'delivered_at' => $status === 'completed' ? now()->subDays($i * 5 - 2) : null,
            ]);

            foreach ($items as $item) {
                $product = $item['product'];
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'price' => $item['price'],
                    'quantity' => $item['qty'],
                    'subtotal' => round($item['price'] * $item['qty'], 2),
                    'image' => $product->image,
                ]);
            }
        }
    }

    protected function menus(): void
    {
        MenuItem::where('location', 'header')->delete();
        MenuItem::where('location', 'footer')->delete();

        $sort = 0;
        MenuItem::create(['location' => 'header', 'label' => 'Trang chủ', 'url' => '/', 'type' => 'page', 'sort_order' => $sort++]);
        MenuItem::create(['location' => 'header', 'label' => 'Tất cả sản phẩm', 'url' => '/shop', 'type' => 'page', 'sort_order' => $sort++]);

        // Category menu items: frontend auto-renders subcategories recursively.
        foreach (Category::active()->whereNull('parent_id')->orderBy('sort_order')->get() as $cat) {
            MenuItem::create([
                'location' => 'header', 'label' => $cat->name, 'url' => '/danh-muc/'.$cat->slug,
                'type' => 'category', 'category_id' => $cat->id, 'sort_order' => $sort++,
            ]);
        }

        MenuItem::create(['location' => 'header', 'label' => 'Blog', 'url' => '/blog', 'type' => 'page', 'sort_order' => $sort++]);
        MenuItem::create(['location' => 'header', 'label' => 'Về chúng tôi', 'url' => '/gioi-thieu', 'type' => 'page', 'sort_order' => $sort++]);
        MenuItem::create(['location' => 'header', 'label' => 'Liên hệ', 'url' => '/lien-he', 'type' => 'page', 'sort_order' => $sort++]);

        $fsort = 0;
        foreach (['Tất cả sản phẩm' => '/shop', 'Blog' => '/blog', 'Về chúng tôi' => '/gioi-thieu', 'Liên hệ' => '/lien-he'] as $label => $url) {
            MenuItem::create(['location' => 'footer', 'label' => $label, 'url' => $url, 'sort_order' => $fsort++]);
        }
    }

    protected function presets(): void
    {
        $rows = require database_path('data/studio_presets.php');

        $file = database_path('data/fashion_presets.json');
        if (file_exists($file)) {
            $extra = json_decode(file_get_contents($file), true) ?: [];
            foreach (['styles' => 'style', 'backgrounds' => 'background'] as $key => $cat) {
                foreach ($extra[$key] ?? [] as $item) {
                    $rows[] = [$cat, $item['label'], $item['prompt'], ''];
                }
            }
        }

        Preset::whereIn('category', ['camera', 'lens', 'video_scene', 'pose'])->delete();

        $sort = 0;
        $all = collect($rows)->map(fn ($row) => [$row[0], $row[1], $row[2], $row[3] ?? '']);
        foreach ($all as [$category, $label, $injection, $note]) {
            Preset::updateOrCreate(
                ['category' => $category, 'ui_label' => $label],
                ['prompt_injection' => $injection, 'note' => $note, 'sort_order' => $sort++],
            );
        }
    }
}
