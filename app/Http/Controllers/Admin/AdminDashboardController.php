<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Post;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $revenue = (float) Order::whereIn('status', ['completed', 'shipped', 'processing', 'pending'])
            ->where('payment_status', 'paid')
            ->sum('total');

        $orders = Order::count();
        $pendingOrders = Order::where('status', 'pending')->count();
        $customers = User::where('role', 'customer')->count();
        $products = Product::count();
        $lowStock = Product::active()->where('stock', '<=', 5)->count();

        $recentOrders = Order::with('items')->latest()->limit(8)->get();
        $statusCounts = Order::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->toArray();

        $topProducts = Product::withCount('orderItems')->with('category')
            ->orderByDesc('order_items_count')->limit(6)->get();

        $recentPosts = Post::latest()->limit(5)->get();
        $recentReviews = Review::with('user', 'product')->latest()->limit(5)->get();

        $lowStockProducts = Product::active()->with('category', 'variants')->get()
            ->filter(fn ($p) => $p->total_stock <= 5)
            ->sortBy(fn ($p) => $p->total_stock)
            ->take(8)->values();

        return view('admin.dashboard', compact(
            'revenue', 'orders', 'pendingOrders', 'customers', 'products',
            'lowStock', 'recentOrders', 'statusCounts', 'topProducts', 'recentPosts', 'recentReviews',
            'lowStockProducts'
        ));
    }
}