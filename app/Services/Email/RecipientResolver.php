<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\EmailType;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use LogicException;

class RecipientResolver
{
    /**
     * Resolve the recipients for a given email type.
     *
     * Returns either a Collection of User models (tenant-facing emails) or
     * a Collection containing a single AnonymousNotifiable wrapping a raw
     * email address (admin-facing emails).
     *
     * @return Collection<int, mixed>
     */
    public function recipientsFor(EmailType $type, ?Tenant $tenant = null): Collection
    {
        return match ($type) {
            EmailType::Receipt,
            EmailType::LeadNotification,
            EmailType::Cancellation,
            EmailType::Dunning,
            EmailType::QuotaWarning,
            EmailType::WeeklyDigest,
            EmailType::TrialStarted,
            EmailType::TrialExpiring,
            EmailType::TrialExpired => $this->ownersOf($this->requireTenant($type, $tenant)),

            EmailType::EnterpriseInquiry => $this->adminInquiryNotifiable(),

            EmailType::TeamInvite,
            EmailType::PasswordReset => throw new LogicException(
                "EmailType::{$type->name} recipients are resolved by the caller (raw email / requesting User), not by RecipientResolver."
            ),
        };
    }

    /**
     * @return Collection<int, mixed>
     */
    private function ownersOf(Tenant $tenant): Collection
    {
        return User::query()
            ->whereIn('id', UserRole::query()
                ->forTenant($tenant)
                ->where('role', Role::Owner)
                ->select('user_id'))
            ->get();
    }

    /**
     * @return Collection<int, mixed>
     */
    private function adminInquiryNotifiable(): Collection
    {
        $notifiable = (new AnonymousNotifiable)->route(
            'mail',
            (string) config('mail.admin_inquiry_address'),
        );

        return new Collection([$notifiable]);
    }

    private function requireTenant(EmailType $type, ?Tenant $tenant): Tenant
    {
        if ($tenant === null) {
            throw new LogicException("EmailType::{$type->name} requires a Tenant.");
        }

        return $tenant;
    }
}
