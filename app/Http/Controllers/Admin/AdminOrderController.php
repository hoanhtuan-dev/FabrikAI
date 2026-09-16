<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with('items', 'user')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('order_number', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        $orders = $query->paginate(15)->withQueryString();
        $statuses = array_flip([
            'pending' => 'Chờ xử lý',
            'processing' => 'Đang xử lý',
            'shipped' => 'Đang giao',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
            'refunded' => 'Đã hoàn tiền',
        ]);

        return view('admin.orders.index', compact('orders', 'statuses'));
    }

    public function export()
    {
        $orders = Order::with('items')->latest()->get();

        $filename = 'don-hang-'.now()->format('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->stream(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['Mã đơn', 'Ngày đặt', 'Khách hàng', 'Email', 'SĐT', 'Địa chỉ', 'Tạm tính', 'Giảm giá', 'Phí ship', 'Tổng', 'Phương thức', 'Thanh toán', 'Trạng thái']);

            foreach ($orders as $order) {
                fputcsv($out, [
                    $order->order_number,
                    $order->created_at?->format('d/m/Y H:i'),
                    $order->name,
                    $order->email,
                    $order->phone,
                    $order->address.', '.implode(', ', array_filter([$order->ward, $order->district, $order->province])),
                    $order->subtotal,
                    $order->discount,
                    $order->shipping_fee,
                    $order->total,
                    strtoupper($order->payment_method),
                    $order->payment_status,
                    $order->status_label,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    public function destroy(Order $order)
    {
        $order->items()->delete();
        $order->delete();

        return back()->with('success', 'Đã xóa đơn hàng '.$order->order_number.'.');
    }

    public function show(Order $order)
    {
        $order->load('items.product', 'user', 'coupon');

        return view('admin.orders.show', compact('order'));
    }

    public function updateStatus(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,processing,shipped,completed,cancelled,refunded'],
            'payment_status' => ['required', 'in:unpaid,paid,failed,refunded'],
            'tracking_code' => ['nullable', 'string', 'max:255'],
        ]);

        $updates = [
            'status' => $data['status'],
            'payment_status' => $data['payment_status'],
            'tracking_code' => $data['tracking_code'] ?? null,
        ];

        if ($data['status'] === 'shipped' && ! $order->shipped_at) {
            $updates['shipped_at'] = now();
        }
        if ($data['status'] === 'completed' && ! $order->delivered_at) {
            $updates['delivered_at'] = now();
        }
        if ($data['status'] === 'cancelled' && ! $order->cancelled_at) {
            $updates['cancelled_at'] = now();
        }
        if ($data['payment_status'] === 'paid' && ! $order->paid_at) {
            $updates['paid_at'] = now();
        }

        $order->update($updates);

        return back()->with('success', 'Đã cập nhật trạng thái đơn hàng.');
    }
}