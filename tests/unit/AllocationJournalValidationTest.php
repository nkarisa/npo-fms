<?php

use App\Controllers\Api\Journals;
use CodeIgniter\Test\CIUnitTestCase;

final class AllocationJournalValidationTest extends CIUnitTestCase
{
    public function testAllocationFundTransfersMustNetToZero(): void
    {
        $validLines = [
            ['fund' => 'Grant Fund', 'dr' => 320000, 'cr' => 0],
            ['fund' => 'Grant Fund', 'dr' => 190000, 'cr' => 0],
            ['fund' => 'General Fund', 'dr' => 0, 'cr' => 510000],
        ];

        $this->assertSame('', Journals::validateAllocationFundTransfer($validLines));

        $invalidLines = [
            ['fund' => 'Grant Fund', 'dr' => 320000, 'cr' => 0],
            ['fund' => 'General Fund', 'dr' => 0, 'cr' => 300000],
        ];

        $this->assertStringContainsString('sum to zero', Journals::validateAllocationFundTransfer($invalidLines));
    }
}
