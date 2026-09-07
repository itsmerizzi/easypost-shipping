<?php

namespace App\Http\Controllers;

use App\Actions\CreateShippingLabel;
use App\Http\Requests\StoreLabelRequest;
use App\Http\Resources\LabelResource;
use App\Models\ShippingLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LabelController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return LabelResource::collection(
            $request->user()->labels()->latest()->paginate(15),
        );
    }

    public function store(StoreLabelRequest $request, CreateShippingLabel $action): JsonResponse
    {
        $label = $action->handle($request->user(), $request->validated());

        return LabelResource::make($label)->response()->setStatusCode(201);
    }

    public function show(ShippingLabel $label): LabelResource
    {
        Gate::authorize('view', $label);

        return LabelResource::make($label);
    }

    public function download(ShippingLabel $label): StreamedResponse
    {
        Gate::authorize('view', $label);

        return Storage::disk('local')->response(
            $label->label_file_path,
            "label-{$label->id}.pdf",
            ['Content-Type' => $label->label_file_type],
            'inline',
        );
    }
}
