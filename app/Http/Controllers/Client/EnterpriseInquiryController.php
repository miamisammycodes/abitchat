<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\Ability;
use App\Enums\EmailType;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseInquiry;
use App\Notifications\Admin\EnterpriseInquiryNotification;
use App\Services\Email\RecipientResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class EnterpriseInquiryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Ability::ViewDashboard->value);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'company' => 'nullable|string|max:255',
            'message' => 'required|string|max:2000',
        ]);

        $tenant = $this->getTenant($request);

        $inquiry = EnterpriseInquiry::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'company' => $validated['company'] ?? null,
            'message' => $validated['message'],
            'status' => 'pending',
        ]);

        $recipients = app(RecipientResolver::class)
            ->recipientsFor(EmailType::EnterpriseInquiry);

        Notification::send($recipients, new EnterpriseInquiryNotification($inquiry));

        return back()->with('success', 'Thank you for your inquiry! Our team will contact you shortly.');
    }
}
