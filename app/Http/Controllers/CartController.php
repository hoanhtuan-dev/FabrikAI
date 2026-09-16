<?php

namespace App\Http\Controllers;

use App\Services\CartService;

class CartController extends Controller
{
    public function __construct(protected CartService $cart)
    {
    }

    public function show()
    {
        $payload = $this->cart->payload();

        return view('cart.show', compact('payload'));
    }
}
