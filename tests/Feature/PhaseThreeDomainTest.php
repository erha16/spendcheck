<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\OpeningBalanceRequest;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class PhaseThreeDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_and_movement_identifiers_are_uuid_version_seven(): void
    {
        $transaction = Transaction::factory()->create(['type' => 'expense', 'occurred_at' => '2026-05-06']);
        $movement = CashMovement::factory()->create();
        $openingBalanceRequest = OpeningBalanceRequest::factory()->create();

        $this->assertSame(7, (int) substr($transaction->public_id, 14, 1));
        $this->assertTrue(Str::isUuid($transaction->public_id));
        $this->assertSame(7, (int) substr($movement->public_id, 14, 1));
        $this->assertTrue(Str::isUuid($movement->public_id));
        $this->assertSame(7, (int) substr($openingBalanceRequest->public_id, 14, 1));
        $this->assertTrue(Str::isUuid($openingBalanceRequest->public_id));
    }

    public function test_transaction_numbers_follow_type_and_occurred_month_sequences(): void
    {
        $this->travelTo('2026-05-12 10:00:00');
        $firstExpense = Transaction::factory()->create(['type' => 'expense', 'occurred_at' => '2026-05-01']);
        $secondExpense = Transaction::factory()->create(['type' => 'expense', 'occurred_at' => '2026-05-31']);
        $firstIncome = Transaction::factory()->create(['type' => 'income', 'occurred_at' => '2026-05-15']);
        $nextMonth = Transaction::factory()->create(['type' => 'expense', 'occurred_at' => '2026-06-01']);

        $this->assertSame('EXP/202605/0001', $firstExpense->number);
        $this->assertSame('EXP/202605/0002', $secondExpense->number);
        $this->assertSame('INC/202605/0001', $firstIncome->number);
        $this->assertSame('EXP/202606/0001', $nextMonth->number);
        $this->assertSame(4, Transaction::query()->distinct('number')->count('number'));
    }

    public function test_transaction_items_are_related_to_their_transaction(): void
    {
        $transaction = Transaction::factory()->create();
        $item = TransactionItem::factory()->for($transaction)->create();

        $this->assertTrue($transaction->items->contains($item));
        $this->assertSame($transaction->id, $item->transaction->id);
        $this->assertSame('100.00', $item->subtotal);
    }

    public function test_draft_transactions_do_not_change_ledger_balances(): void
    {
        $transaction = Transaction::factory()->create(['status' => 'draft']);

        $this->assertSame('0.00', $transaction->sourceCashAccount->ledgerBalance());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_cash_account_balance_comes_from_in_and_out_movements(): void
    {
        $cashAccount = CashAccount::factory()->create();
        $creator = User::factory()->create();
        CashMovement::factory()->for($cashAccount)->create(['direction' => 'in', 'amount' => '1200.55', 'created_by' => $creator->id]);
        CashMovement::factory()->for($cashAccount)->create(['direction' => 'out', 'amount' => '200.25', 'created_by' => $creator->id]);
        CashMovement::factory()->for($cashAccount)->create(['direction' => 'out', 'amount' => '0.30', 'created_by' => $creator->id]);

        $this->assertSame('1000.00', $cashAccount->ledgerBalance());
        $this->assertArrayNotHasKey('balance', $cashAccount->getAttributes());
        $this->assertFalse(Schema::hasColumn('cash_accounts', 'balance'));
    }

    public function test_duplicate_movement_idempotency_key_is_rejected(): void
    {
        $movement = CashMovement::factory()->create(['idempotency_key' => 'opening-balance:once']);

        try {
            CashMovement::factory()->for($movement->cashAccount)->create([
                'idempotency_key' => 'opening-balance:once',
                'created_by' => $movement->created_by,
            ]);
            $this->fail('Duplicate idempotency key was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('cash_movements', 1);
        }
    }

    public function test_cash_movements_reject_zero_or_negative_amounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CashMovement::factory()->create(['amount' => '0.00']);
    }

    public function test_cash_ledger_movements_cannot_be_updated_or_deleted(): void
    {
        $movement = CashMovement::factory()->create();

        try {
            $movement->update(['amount' => '200.00']);
            $this->fail('A cash ledger movement was updated.');
        } catch (LogicException) {
            $this->assertDatabaseHas('cash_movements', ['id' => $movement->id, 'amount' => '100.00']);
        }

        try {
            $movement->delete();
            $this->fail('A cash ledger movement was deleted.');
        } catch (LogicException) {
            $this->assertDatabaseCount('cash_movements', 1);
        }
    }

    public function test_referenced_cash_accounts_cannot_be_deleted(): void
    {
        $movement = CashMovement::factory()->create();

        $this->expectException(QueryException::class);
        $movement->cashAccount->delete();
    }
}
