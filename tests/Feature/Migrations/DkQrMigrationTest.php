<?php

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DkQrMigrationTest extends TestCase
{
    public function test_transactions_table_has_dk_qr_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('transactions', 'dk_reference_no'));
        $this->assertTrue(Schema::hasColumn('transactions', 'dk_rrn'));
        $this->assertTrue(Schema::hasColumn('transactions', 'dk_status_last_checked_at'));
    }

    public function test_dk_reference_no_is_unique(): void
    {
        $indexes = collect(Schema::getIndexes('transactions'))
            ->pluck('columns')
            ->map(fn ($cols) => implode(',', $cols))
            ->all();

        $this->assertContains('dk_reference_no', $indexes);
    }

    public function test_dk_rrn_is_unique(): void
    {
        $indexes = collect(Schema::getIndexes('transactions'))
            ->pluck('columns')
            ->map(fn ($cols) => implode(',', $cols))
            ->all();

        $this->assertContains('dk_rrn', $indexes);
    }

    public function test_transaction_number_is_now_nullable(): void
    {
        $columns = collect(Schema::getColumns('transactions'))
            ->keyBy('name');

        $this->assertTrue($columns['transaction_number']['nullable']);
    }
}
