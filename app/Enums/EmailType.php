<?php

declare(strict_types=1);

namespace App\Enums;

enum EmailType: string
{
    case Receipt = 'receipt';
    case LeadNotification = 'lead_notification';
    case EnterpriseInquiry = 'enterprise_inquiry';
    case PasswordReset = 'password_reset';
    case TeamInvite = 'team_invite';
    case Cancellation = 'cancellation';
    case Dunning = 'dunning';
    case QuotaWarning = 'quota_warning';
    case WeeklyDigest = 'weekly_digest';
}
