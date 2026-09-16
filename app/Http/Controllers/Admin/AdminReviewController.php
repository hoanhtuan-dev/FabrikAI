<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    public function index(Request $request)
    {
        $query = Review::with('user', 'product')->latest();

        if ($request->filled('status')) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        $reviews = $query->paginate(15)->withQueryString();

        return view('admin.reviews.index', compact('reviews'));
    }

    public function toggleActive(Review $review)
    {
        $review->update(['is_active' => ! $review->is_active]);

        return back()->with('success', 'Đã cập nhật trạng thái đánh giá.');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return back()->with('success', 'Đã xóa đánh giá.');
    }
}
