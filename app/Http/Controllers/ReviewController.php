<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product)
    {
        abort_unless(auth()->check(), 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        auth()->user()->reviews()->updateOrCreate(
            ['product_id' => $product->id],
            $data + ['is_active' => true]
        );

        $this->refreshRating($product);

        return back()->with('success', 'Cảm ơn bạn đã đánh giá sản phẩm.');
    }

    public function helpful(Request $request, Review $review)
    {
        $review->increment('helpful_count');

        return back()->with('success', 'Cảm ơn bạn đã đánh giá hữu ích.');
    }

    protected function refreshRating(Product $product): void
    {
        $rating = Review::where('product_id', $product->id)->avg('rating');
        $count = Review::where('product_id', $product->id)->count();

        $product->update([
            'rating_avg' => $rating ? round($rating, 2) : 0,
            'rating_count' => $count,
        ]);
    }
}
