<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ShopPageController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->where('is_active', true)
            ->with('pharmacy')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('c'), fn ($q) => $q->where('kind', $request->string('c')))
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('pages.shop', ['products' => $products]);
    }

    public function show(Product $product)
    {
        return view('pages.product-single', ['product' => $product->load('pharmacy')]);
    }
}
