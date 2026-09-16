<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class SearchApiController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q', ''));

        $products = Product::active()->inStock()
            ->with('variants')
            ->search($q)
            ->limit(6)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'image' => $p->image_url,
                'price' => (float) $p->min_price,
                'compare_price' => $p->compare_price ? (float) $p->compare_price : null,
                'url' => route('product.show', $p->slug),
            ]);

        return response()->json(['products' => $products]);
    }
}
