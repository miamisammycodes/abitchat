<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\EmailType;
use PHPUnit\Framework\TestCase;

class EmailTypeTest extends TestCase
{
    public function test_all_expected_cases_exist(): void
    {
        $expected = [
            'receipt', 'lead_notification', 'enterprise_inquiry', 'password_reset',
            'team_invite', 'cancellation', 'dunning', 'quota_warning', 'weekly_digest',
        ];

        $actual = array_map(fn (EmailType $case): string => $case->value, EmailType::cases());

        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_can_be_constructed_from_string_value(): void
    {
        $this->assertSame(EmailType::Receipt, EmailType::from('receipt'));
        $this->assertSame(EmailType::TeamInvite, EmailType::from('team_invite'));
    }

    public function test_invalid_string_returns_null_via_try_from(): void
    {
        $this->assertNull(EmailType::tryFrom('not_a_real_email'));
    }
}
