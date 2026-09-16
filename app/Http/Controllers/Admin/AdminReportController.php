<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->input('from', now()->subDays(29)->format('Y-m-d'));
        $to = $request->input('to', now()->format('Y-m-d'));

        $start = \Carbon\Carbon::parse($from)->startOfDay();
        $end = \Carbon\Carbon::parse($to)->endOfDay();

        $validStatuses = ['pending', 'processing', 'shipped', 'completed'];

        $base = Order::whereBetween('created_at', [$start, $end]);

        // Revenue (valid, not cancelled/refunded)
        $revenue = (clone $base)->whereIn('status', $validStatuses)->sum('total');

        // All orders in range
        $ordersCount = (clone $base)->count();

        // Valid orders for AOV
        $validCount = (clone $base)->whereIn('status', $validStatuses)->count();
        $aov = $validCount ? round($revenue / $validCount) : 0;

        // Refunded total
        $refunded = (clone $base)->where('status', 'refunded')->sum('total');

        // Customers (distinct users) in range
        $customers = (clone $base)->whereNotNull('user_id')->distinct('user_id')->count('user_id');

        // Orders by status
        $ordersByStatus = (clone $base)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status')->toArray();

        // Revenue by day
        $revenueByDay = (clone $base)->whereIn('status', $validStatuses)
            ->selectRaw('DATE(created_at) as day, SUM(total) as total')
            ->groupBy('day')->orderBy('day')->get();

        // Top products
        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->whereIn('orders.status', $validStatuses)
            ->selectRaw('order_items.product_id, order_items.product_name, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as revenue')
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id', 'order_items.product_name')
            ->orderByDesc('revenue')->limit(6)->get();

        // Top customers (qualify columns to avoid ambiguity with the join)
        $topCustomers = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->whereNotNull('orders.user_id')
            ->selectRaw('orders.user_id, users.name, users.email, COUNT(*) as orders_count, SUM(orders.total) as total')
            ->groupBy('orders.user_id', 'users.name', 'users.email')
            ->orderByDesc('total')->limit(6)->get();

        $statusLabels = [
            'pending' => 'Chờ xử lý', 'processing' => 'Đang xử lý', 'shipped' => 'Đang giao',
            'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy', 'refunded' => 'Đã hoàn',
        ];

        $maxDay = (float) max($revenueByDay->max('total') ?? 0, 1);

        return view('admin.reports.index', compact(
            'from', 'to', 'revenue', 'ordersCount', 'aov', 'refunded', 'customers',
            'ordersByStatus', 'revenueByDay', 'topProducts', 'topCustomers', 'statusLabels', 'maxDay'
        ));
    }
}