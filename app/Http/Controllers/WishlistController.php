<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index()
    {
        $products = auth()->user()->wishlistProducts()
            ->with('category', 'variants')
            ->latest('wishlists.created_at')
            ->paginate(12);

        return view('account.wishlist', compact('products'));
    }

    public function toggle(Request $request)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);

        $user = auth()->user();
        $productId = $data['product_id'];

        if ($user->wishlistProducts()->where('product_id', $productId)->exists()) {
            $user->wishlistProducts()->detach($productId);

            return response()->json(['added' => false, 'message' => 'Đã xóa khỏi danh sách yêu thích.']);
        }

        $user->wishlistProducts()->attach($productId);

        return response()->json(['added' => true, 'message' => 'Đã thêm vào danh sách yêu thích.']);
    }
}
