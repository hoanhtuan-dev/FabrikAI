<?php

use App\Modules\Storefront\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront module routes
|--------------------------------------------------------------------------
| The public homepage and shop listing (Vue SPA), plus the JSON feeds the SPAs
| re-request for cache expiry or filter/sort/page updates. Loaded by
| StorefrontModuleServiceProvider so the feature is self-contained.
*/

Route::middleware('web')->group(function () {
    Route::get('/', [StorefrontController::class, 'home'])->name('home');

    // Product detail.
    Route::get('/san-pham/{slug}', [StorefrontController::class, 'product'])->name('product.show');

    // Cart.
    Route::get('/gio-hang', [StorefrontController::class, 'cart'])->name('cart.show');

    // Blog.
    Route::get('/blog', [StorefrontController::class, 'blogIndex'])->name('blog.index');
    Route::get('/blog/{slug}', [StorefrontController::class, 'blogPost'])->name('blog.show');

    // Wishlist.
    Route::get('/yeu-thich', [StorefrontController::class, 'wishlist'])->name('wishlist.index');
    Route::get('/api/storefront/wishlist', [StorefrontController::class, 'wishlistFeed'])->name('storefront.wishlist');

    // Checkout (order creation stays on the legacy POST /thanh-toan endpoint).
    Route::get('/thanh-toan', [StorefrontController::class, 'checkout'])
        ->name('checkout.show')->middleware('auth');

    // Account area (auth) — GET pages; CRUD actions stay on the legacy endpoints.
    Route::middleware('auth')->prefix('tai-khoan')->name('account.')->group(function () {
        Route::get('/', [StorefrontController::class, 'accountDashboard'])->name('dashboard');
        Route::get('/don-hang', [StorefrontController::class, 'accountOrders'])->name('orders');
        Route::get('/don-hang/{order}', [StorefrontController::class, 'accountOrder'])->name('order');
        Route::get('/ho-so', [StorefrontController::class, 'accountProfile'])->name('profile');
        Route::get('/dia-chi', [StorefrontController::class, 'accountAddresses'])->name('addresses');
        Route::get('/danh-gia', [StorefrontController::class, 'accountReviews'])->name('reviews');
        Route::get('/mat-khau', [StorefrontController::class, 'accountPassword'])->name('password');
    });

    // Static content pages.
    Route::get('/gioi-thieu', [StorefrontController::class, 'staticPage'])->defaults('key', 'about')->name('page.about');
    Route::get('/lien-he', [StorefrontController::class, 'staticPage'])->defaults('key', 'contact')->name('page.contact');
    Route::get('/hoi-dap', [StorefrontController::class, 'staticPage'])->defaults('key', 'faq')->name('page.faq');
    Route::get('/chinh-sach-bao-mat', [StorefrontController::class, 'staticPage'])->defaults('key', 'privacy')->name('page.privacy');
    Route::get('/dieu-khoan', [StorefrontController::class, 'staticPage'])->defaults('key', 'terms')->name('page.terms');
    Route::get('/trang/{slug}', [StorefrontController::class, 'staticPage'])->name('page.show');

    // Checkout result (pay / success) — auth.
    Route::get('/thanh-toan/{order}/thanh-toan', [StorefrontController::class, 'checkoutPay'])
        ->name('checkout.pay')->middleware('auth');
    Route::get('/thanh-toan/{order}/hoan-tat', [StorefrontController::class, 'checkoutSuccess'])
        ->name('checkout.success')->middleware('auth');

    // Auth (submission posts to the legacy POST /dang-nhap, /dang-ky).
    Route::get('/dang-nhap', [StorefrontController::class, 'auth'])
        ->defaults('mode', 'login')->name('login')->middleware('guest');
    Route::get('/dang-ky', [StorefrontController::class, 'auth'])
        ->defaults('mode', 'register')->name('register')->middleware('guest');

    // Shop listing (all products + per-category).
    Route::get('/shop', [StorefrontController::class, 'shop'])->name('shop.index');
    Route::get('/danh-muc/{categorySlug}', [StorefrontController::class, 'shop'])->name('shop.category');

    // JSON feeds.
    Route::get('/api/storefront/product/{id}', [StorefrontController::class, 'productQuickView'])->name('storefront.product');
    Route::get('/api/storefront/home', [StorefrontController::class, 'feed'])->name('storefront.feed');
    Route::get('/api/storefront/shop', [StorefrontController::class, 'shop'])->name('storefront.shop');
    Route::get('/api/storefront/shop/{categorySlug}', [StorefrontController::class, 'shop'])->name('storefront.shop.category');
});
