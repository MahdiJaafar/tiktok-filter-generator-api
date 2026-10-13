<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Filter;
use App\Models\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function store(Request $request, Filter $filter): JsonResponse
    {
        abort_unless($filter->status === 'published' && $filter->published_at !== null, 404);

        Like::firstOrCreate([
            'user_id' => $request->user()->id,
            'filter_id' => $filter->id,
        ]);

        return response()->json([
            'liked' => true,
            'likes_count' => $filter->likes()->count(),
        ]);
    }

    public function destroy(Request $request, Filter $filter): JsonResponse
    {
        abort_unless($filter->status === 'published' && $filter->published_at !== null, 404);

        $filter->likes()->where('user_id', $request->user()->id)->delete();

        return response()->json([
            'liked' => false,
            'likes_count' => $filter->likes()->count(),
        ]);
    }
}
