<?php

namespace Splicewire\Beam\Docs\Publishing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Docs\Publishing\Data\PublicationData;
use Splicewire\Beam\Docs\Publishing\Data\PublicationListData;
use Splicewire\Beam\Docs\Publishing\Data\PublicationResponseData;
use Splicewire\Beam\Docs\Publishing\Data\PublishInputData;
use Splicewire\Beam\Docs\Publishing\PublicationFailure;
use Splicewire\Beam\Docs\Publishing\PublicationService;

/** @group Documentation publishing */
class PublicationController
{
    public function __construct(private PublicationService $publications) {}

    #[ResponseFromData(PublicationListData::class)]
    public function index(): JsonResponse
    {
        Gate::authorize('beam-docs.publish');

        return response()->json(new PublicationListData($this->publications->recent()->map(fn ($publication) => PublicationData::fromPublication($publication))->all()));
    }

    #[RequestFromData(PublishInputData::class)]
    #[ResponseFromData(PublicationResponseData::class, status: 202)]
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('beam-docs.publish');
        $input = PublishInputData::validateAndCreate($request->only('version'));

        try {
            $publication = $this->publications->enqueue($this->publications->capture($input->version));
        } catch (PublicationFailure $error) {
            abort(422, $error->getMessage());
        }

        return response()->json(new PublicationResponseData(PublicationData::fromPublication($publication)), 202);
    }

    #[ResponseFromData(PublicationResponseData::class)]
    public function show(string $publication): JsonResponse
    {
        Gate::authorize('beam-docs.publish');

        return response()->json(new PublicationResponseData(PublicationData::fromPublication($this->publications->find($publication))));
    }

    #[ResponseFromData(PublicationResponseData::class, status: 202)]
    public function retry(string $publication): JsonResponse
    {
        Gate::authorize('beam-docs.publish');
        try {
            $attempt = $this->publications->enqueue($this->publications->retry($publication));
        } catch (PublicationFailure $error) {
            abort(409, $error->getMessage());
        }

        return response()->json(new PublicationResponseData(PublicationData::fromPublication($attempt)), 202);
    }
}
