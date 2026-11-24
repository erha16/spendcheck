<?php

namespace App\Http\Controllers;

use App\Models\CashAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashflowController extends Controller
{
    /** @var array<string, string> */
    private const MOVEMENT_TYPES = [
        'income' => 'Pemasukan',
        'expense' => 'Pengeluaran',
        'transfer_in' => 'Transfer masuk',
        'transfer_out' => 'Transfer keluar',
        'opening_balance' => 'Saldo awal',
        'advance_payout' => 'Pencairan bon',
        'advance_return' => 'Pengembalian sisa bon',
        'advance_repay' => 'Pembayaran penggantian ke penerima',
        'reversal' => 'Reversal',
    ];

    public function index(Request $request): View|JsonResponse
    {
        Gate::authorize('cashflow.view');

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'cash_account_ids' => ['nullable', 'array'],
            'cash_account_ids.*' => ['integer', 'distinct', 'exists:cash_accounts,id'],
            'movement_kinds' => ['nullable', 'array'],
            'movement_kinds.*' => ['string', 'distinct', Rule::in(array_keys(self::MOVEMENT_TYPES))],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $cashAccounts = CashAccount::query()->orderBy('name')->get();
        $cashAccounts->each(fn (CashAccount $account) => $account->setAttribute('ledger_balance', $account->ledgerBalance()));

        if ($request->expectsJson()) {
            return response()->json($cashAccounts->map(fn (CashAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'balance' => $account->ledger_balance,
            ]));
        }

        $movementQuery = $this->ledgerRows();
        $movements = DB::query()->fromSub($movementQuery, 'ledger')
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '<=', $date))
            ->when($filters['cash_account_ids'] ?? [], fn (Builder $query, array $accountIds) => $query->whereIn('cash_account_id', $accountIds))
            ->when($filters['movement_kinds'] ?? [], fn (Builder $query, array $kinds) => $query->whereIn('kind', $kinds))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search): void {
                $query->where('transaction_number', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('cashflow.index', [
            'cashAccounts' => $cashAccounts,
            'movements' => $movements,
            'filters' => $filters,
            'movementTypes' => self::MOVEMENT_TYPES,
        ]);
    }

    private function ledgerRows(): Builder
    {
        return DB::table('cash_movements')
            ->leftJoin('transactions', 'transactions.id', '=', 'cash_movements.transaction_id')
            ->leftJoin('opening_balance_requests', 'opening_balance_requests.id', '=', 'cash_movements.opening_balance_request_id')
            ->join('cash_accounts', 'cash_accounts.id', '=', 'cash_movements.cash_account_id')
            ->select([
                'cash_movements.id',
                'cash_movements.cash_account_id',
                'cash_accounts.name as cash_account_name',
                'cash_movements.transaction_id',
                'transactions.public_id as transaction_public_id',
                'cash_movements.kind',
                'cash_movements.direction',
                'cash_movements.amount',
                'cash_movements.occurred_at',
                'cash_movements.recorded_at',
            ])
            ->selectRaw("COALESCE(transactions.number, '—') as transaction_number")
            ->selectRaw("COALESCE(transactions.description, opening_balance_requests.reason, 'Pergerakan kas') as description")
            ->selectRaw("SUM(CASE WHEN cash_movements.direction = 'in' THEN cash_movements.amount ELSE -cash_movements.amount END) OVER (PARTITION BY cash_movements.cash_account_id ORDER BY cash_movements.occurred_at, cash_movements.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_balance");
    }
}
