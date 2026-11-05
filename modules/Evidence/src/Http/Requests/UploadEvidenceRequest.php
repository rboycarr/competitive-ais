<?php

namespace Modules\Evidence\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Evidence\Actions\EvidenceUploadService;
use Modules\Platform\Classification;

/** An evidence upload (P1-32a); the type, size, classification and URL rules are decided in EvidenceUploadService. */
class UploadEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware `can:evidence.upload`; EvidenceUploadService checks the actor again
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.intdiv((int) config('evidence.max_bytes'), 1024)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'captured_at' => ['required', 'string', 'max:40'],
            'classification' => ['required', Rule::enum(Classification::class)],
            // P1-36a: competitor and market ULIDs; visibility and existence are checked in EvidenceUploadService.
            'competitors' => ['nullable', 'array', 'max:'.EvidenceUploadService::MAX_SCOPES],
            'competitors.*' => ['string', 'size:26'],
            'markets' => ['nullable', 'array', 'max:'.EvidenceUploadService::MAX_SCOPES],
            'markets.*' => ['string', 'size:26'],
            // P1-37: a source ULID; visibility and an approved policy in force are checked in EvidenceUploadService.
            'source' => ['nullable', 'string', 'size:26'],
        ];
    }
}
