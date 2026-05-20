<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Enums\Ability;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateWebsiteIndexingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows(Ability::ManageTenantSettings->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'website_url' => ['nullable', 'url:http,https', 'max:2048', new SafeExternalUrl],
            'auto_recrawl' => ['required', 'boolean'],
        ];
    }
}
