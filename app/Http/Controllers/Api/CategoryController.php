<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    /**
     * Query params: search, per_page. Catálogo para elegir `category_id` al dar de alta un producto.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Category::query();

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->string('search').'%');
        }

        return CategoryResource::collection($query->orderBy('name')->paginate($this->perPage($request)));
    }
}
