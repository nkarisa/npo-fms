<?php

use App\Controllers\Api\Procurement;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The procurement controls a donor audit tests: commitment accounting and the
 * three-quote rule. Both are computed from the requisition, so they can be
 * asserted without going through the HTTP layer.
 */
final class ProcurementControlsTest extends CIUnitTestCase
{
    private function requisition(array $over = []): array
    {
        return array_merge([
            'amount'    => 400000,
            'budget'    => 1000000,
            'spent'     => 200000,
            'committed' => 100000,
            'quotes'    => [],
        ], $over);
    }

    public function testAvailableBudgetNetsOffActualAndCommitments(): void
    {
        // 1,000,000 approved less 200,000 spent less 100,000 committed.
        $this->assertSame(700000.0, (float) Procurement::available($this->requisition()));
    }

    public function testCommitmentsAloneCanExhaustALine(): void
    {
        // Nothing has been spent, but open purchase orders have taken the lot.
        $p = $this->requisition(['spent' => 0, 'committed' => 1000000]);

        $this->assertSame(0.0, (float) Procurement::available($p));
        $this->assertTrue(Procurement::isOverBudget($p));
    }

    public function testApprovalIsBlockedOnlyWhenTheLineIsShort(): void
    {
        $this->assertFalse(Procurement::isOverBudget($this->requisition(['amount' => 700000])));
        $this->assertTrue(Procurement::isOverBudget($this->requisition(['amount' => 700001])));
    }

    public function testThreeQuoteRuleAppliesOnlyAboveTheThreshold(): void
    {
        $quote = ['supplier' => 'X', 'amount' => 1, 'note' => '', 'chosen' => false, 'file' => ''];

        // At or below the threshold, quotations are not compelled.
        $this->assertFalse(Procurement::needsQuotes($this->requisition(['amount' => 500000, 'quotes' => []])));

        // Above it, fewer than three is a block.
        $this->assertTrue(Procurement::needsQuotes($this->requisition(['amount' => 500001, 'quotes' => [$quote, $quote]])));
        $this->assertFalse(Procurement::needsQuotes($this->requisition(['amount' => 500001, 'quotes' => [$quote, $quote, $quote]])));
    }
}
