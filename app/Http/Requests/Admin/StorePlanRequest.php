<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:plans,slug',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'billing_period' => 'required|in:monthly,yearly',
            'conversations_limit' => 'required|integer|min:-1',
            'messages_per_conversation' => 'required|integer|min:-1',
            'knowledge_items_limit' => 'required|integer|min:-1',
            'tokens_limit' => 'required|integer|min:-1',
            'leads_limit' => 'required|integer|min:-1',
            'features' => 'nullable|array',
            'features.*' => 'string|max:255',
            'is_active' => 'boolean',
            'is_contact_sales' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'This slug is already taken. Please use a different one.',
            'conversations_limit.min' => 'Use -1 for unlimited conversations.',
            'tokens_limit.min' => 'Use -1 for unlimited tokens.',
        ];
    }
}
