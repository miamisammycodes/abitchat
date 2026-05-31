<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\KnowledgeItemStatus;
use PHPUnit\Framework\TestCase;

class KnowledgeItemStatusTest extends TestCase
{
    public function test_enum_has_expected_backing_values(): void
    {
        $this->assertSame('pending', KnowledgeItemStatus::Pending->value);
        $this->assertSame('processing', KnowledgeItemStatus::Processing->value);
        $this->assertSame('ready', KnowledgeItemStatus::Ready->value);
        $this->assertSame('failed', KnowledgeItemStatus::Failed->value);
        $this->assertSame('skipped_no_content', KnowledgeItemStatus::SkippedNoContent->value);
    }

    public function test_enum_has_exactly_five_cases(): void
    {
        $this->assertCount(5, KnowledgeItemStatus::cases());
    }

    public function test_enum_can_be_instantiated_from_string_value(): void
    {
        $this->assertSame(
            KnowledgeItemStatus::Ready,
            KnowledgeItemStatus::from('ready'),
        );
    }

    public function test_json_encodes_to_backing_string_for_inertia_payloads(): void
    {
        $this->assertSame('"failed"', json_encode(KnowledgeItemStatus::Failed));
    }
}
