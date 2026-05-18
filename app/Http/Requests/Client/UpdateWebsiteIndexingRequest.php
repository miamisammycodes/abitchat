<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteIndexingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() ?? false;
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
