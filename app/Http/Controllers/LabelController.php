<?php

namespace App\Http\Controllers;

use App\Actions\CreateShippingLabel;
use App\Http\Requests\StoreLabelRequest;
use App\Http\Resources\LabelResource;
use Illuminate\Http\JsonResponse;

class LabelController extends Controller
{
    public function store(StoreLabelRequest $request, CreateShippingLabel $action): JsonResponse
    {
        $label = $action->handle($request->user(), $request->validated());

        return LabelResource::make($label)->response()->setStatusCode(201);
    }
}
