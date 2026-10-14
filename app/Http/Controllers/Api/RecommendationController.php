<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FilterResource;
use App\Services\Recommendations\RecommendationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecommendationController extends Controller
{
    public function index(Request $request, RecommendationService $recommendations): AnonymousResourceCollection
    {
        $result = $recommendations->recommend($request->user());

        return FilterResource::collection($result['filters'])->additional(['meta' => ['source' => $result['source']]]);
    }
}
