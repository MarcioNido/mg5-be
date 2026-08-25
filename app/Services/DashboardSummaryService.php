<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    private const TYPES = [
        CategoryType::Income->value,
        CategoryType::Expense->value,
        CategoryType::Transfer->value,
    ];

    public function summarize(?string $month = null): array
    {
        $timezone = config('app.business_timezone');
        $today = Carbon::now($timezone)->startOfDay();
        $periodStart = $month === null
            ? $today->copy()->startOfMonth()
            : Carbon::createFromFormat('!Y-m', $month, $timezone)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $accounts = Account::query()
            ->orderByRaw('LOWER(name)')
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'currency', 'opening_balance', 'opening_balance_date']);
        $accountIds = $accounts->pluck('id');

        $balanceRows = $this->balanceRows($accountIds, $today->toDateString());
        $latestAttempts = $this->latestReconciliations($accountIds, false);
        $latestValid = $this->latestReconciliations($accountIds, true);
        [$accountItems, $currencyTotals, $attentionCount] = $this->accounts(
            $accounts,
            $balanceRows,
            $latestAttempts,
            $latestValid
        );

        $periodActivity = $this->periodActivity(
            $periodStart->toDateString(),
            $periodEnd->toDateString()
        );
        $workflow = $this->workflow($attentionCount);

        return [
            'period' => [
                'month' => $periodStart->format('Y-m'),
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ],
            'as_of_date' => $today->toDateString(),
            'accounts' => $accountItems,
            'account_totals_by_currency' => $currencyTotals,
            'period_activity' => $periodActivity,
            'workflow' => $workflow,
        ];
    }

    private function balanceRows(Collection $accountIds, string $asOfDate): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        $units = $this->moneyUnitsSql('transactions.amount');

        return Transaction::query()
            ->join('accounts', function ($join): void {
                $join->on('accounts.id', '=', 'transactions.account_id')
                    ->on('accounts.tenant_id', '=', 'transactions.tenant_id')
                    ->whereNull('accounts.deleted_at');
            })
            ->whereIn('transactions.account_id', $accountIds)
            ->where('transactions.status', TransactionStatus::Posted->value)
            ->whereDate('transactions.transaction_date', '<=', $asOfDate)
            ->groupBy('transactions.account_id')
            ->select('transactions.account_id')
            ->selectRaw(
                "SUM(CASE WHEN accounts.opening_balance_date IS NULL OR transactions.transaction_date > accounts.opening_balance_date THEN {$units} ELSE 0 END) AS posted_units"
            )
            ->selectRaw('MAX(transactions.transaction_date) AS last_posted_transaction_date')
            ->get()
            ->keyBy('account_id');
    }

    private function latestReconciliations(Collection $accountIds, bool $validOnly): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return Reconciliation::query()
            ->whereIn('account_id', $accountIds)
            ->when($validOnly, fn (Builder $query) => $query
                ->whereColumn('entered_bank_balance', 'calculated_balance'))
            ->whereNotExists(function ($query) use ($validOnly): void {
                $query->selectRaw('1')
                    ->from('reconciliations as later')
                    ->whereColumn('later.tenant_id', 'reconciliations.tenant_id')
                    ->whereColumn('later.account_id', 'reconciliations.account_id')
                    ->when($validOnly, fn ($query) => $query
                        ->whereColumn('later.entered_bank_balance', 'later.calculated_balance'))
                    ->where(function ($query): void {
                        $query->whereColumn('later.statement_date', '>', 'reconciliations.statement_date')
                            ->orWhere(function ($query): void {
                                $query->whereColumn('later.statement_date', 'reconciliations.statement_date')
                                    ->whereColumn('later.id', '>', 'reconciliations.id');
                            });
                    });
            })
            ->get()
            ->keyBy('account_id');
    }

    private function accounts(
        Collection $accounts,
        Collection $balanceRows,
        Collection $latestAttempts,
        Collection $latestValid
    ): array {
        $currencyUnits = [];
        $attentionCount = 0;

        $items = $accounts->map(function (Account $account) use (
            $balanceRows,
            $latestAttempts,
            $latestValid,
            &$currencyUnits,
            &$attentionCount
        ): array {
            $balanceRow = $balanceRows->get($account->id);
            $balanceUnits = Money::units($account->opening_balance)
                + (int) ($balanceRow?->posted_units ?? 0);
            $currencyUnits[$account->currency] = ($currencyUnits[$account->currency] ?? 0) + $balanceUnits;

            $attempt = $latestAttempts->get($account->id);
            $valid = $latestValid->get($account->id);
            $status = $this->reconciliationStatus(
                $attempt,
                $valid,
                $balanceRow?->last_posted_transaction_date
            );
            $needsAttention = $status !== 'up_to_date';
            $attentionCount += (int) $needsAttention;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'currency' => $account->currency,
                'current_balance' => Money::decimal($balanceUnits),
                'last_posted_transaction_date' => $balanceRow?->last_posted_transaction_date,
                'reconciliation' => [
                    'status' => $status,
                    'needs_attention' => $needsAttention,
                    'latest_valid' => $valid === null ? null : [
                        'statement_date' => $valid->statement_date->toDateString(),
                        'reconciled_at' => $valid->reconciled_at?->toISOString(),
                    ],
                    'latest_attempt' => $attempt === null ? null : [
                        'statement_date' => $attempt->statement_date->toDateString(),
                        'is_valid' => $attempt->is_valid,
                    ],
                ],
            ];
        })->all();

        ksort($currencyUnits, SORT_STRING);
        $currencyTotals = collect($currencyUnits)
            ->map(fn (int $units, string $currency): array => [
                'currency' => $currency,
                'amount' => Money::decimal($units),
            ])
            ->values()
            ->all();

        return [$items, $currencyTotals, $attentionCount];
    }

    private function reconciliationStatus(
        ?Reconciliation $attempt,
        ?Reconciliation $valid,
        ?string $lastPostedDate
    ): string {
        if ($attempt === null) {
            return 'never_reconciled';
        }

        if (! $attempt->is_valid) {
            return 'latest_attempt_invalid';
        }

        if ($valid !== null && $lastPostedDate !== null
            && $lastPostedDate > $valid->statement_date->toDateString()) {
            return 'activity_after_reconciliation';
        }

        return 'up_to_date';
    }

    private function periodActivity(string $startDate, string $endDate): array
    {
        $base = fn (): Builder => Transaction::query()
            ->where('status', TransactionStatus::Posted->value)
            ->whereBetween('transaction_date', [$startDate, $endDate]);

        $transactionUnits = $this->moneyUnitsSql('amount');
        $splitUnits = $this->moneyUnitsSql('transaction_splits.amount');
        $totals = $base()->selectRaw("COUNT(*) AS transaction_count, COALESCE(SUM({$transactionUnits}), 0) AS net_units")
            ->firstOrFail();
        $uncategorized = $base()->whereNull('category_id')->whereDoesntHave('splits')
            ->selectRaw("COALESCE(SUM({$transactionUnits}), 0) AS amount_units")
            ->firstOrFail();
        $direct = $base()->whereNotNull('category_id')->whereDoesntHave('splits')
            ->groupBy('category_id')
            ->selectRaw("category_id, SUM({$transactionUnits}) AS amount_units")
            ->get();
        $splits = TransactionSplit::query()
            ->join('transactions', function ($join): void {
                $join->on('transactions.id', '=', 'transaction_splits.transaction_id')
                    ->on('transactions.tenant_id', '=', 'transaction_splits.tenant_id')
                    ->whereNull('transactions.deleted_at');
            })
            ->where('transactions.status', TransactionStatus::Posted->value)
            ->whereBetween('transactions.transaction_date', [$startDate, $endDate])
            ->groupBy('transaction_splits.category_id')
            ->select('transaction_splits.category_id')
            ->selectRaw("SUM({$splitUnits}) AS amount_units")
            ->get();

        $categories = Category::query()->get(['id', 'parent_id', 'name', 'type', 'level'])->keyBy('id');
        $typeUnits = array_fill_keys(self::TYPES, 0);
        $groupUnits = [];

        foreach ($direct->concat($splits) as $allocation) {
            $category = $categories->get($allocation->category_id);

            if ($category === null || ! array_key_exists($category->type, $typeUnits)) {
                continue;
            }

            $units = (int) $allocation->amount_units;
            $typeUnits[$category->type] += $units;
            $root = $this->rootCategory($category, $categories);
            $groupUnits[$root->id] ??= array_fill_keys(self::TYPES, 0);
            $groupUnits[$root->id][$category->type] += $units;
        }

        $groups = collect($groupUnits)->map(function (array $units, int|string $rootId) use ($categories): array {
            $root = $categories->get((int) $rootId);

            return [
                'category' => [
                    'id' => $root->id,
                    'name' => $root->name,
                    'type' => $root->type,
                    'level' => $root->level,
                ],
                'amounts_by_type' => $this->decimalTypes($units),
                'net_change' => Money::decimal(array_sum($units)),
            ];
        })->sortBy(fn (array $group): array => [
            $group['category']['type'],
            mb_strtolower($group['category']['name']),
            $group['category']['id'],
        ])->values()->all();

        return [
            'posted_transactions_count' => (int) $totals->transaction_count,
            'amounts_by_type' => $this->decimalTypes($typeUnits),
            'uncategorized_amount' => Money::decimal((int) $uncategorized->amount_units),
            'confirmed_net_change' => Money::decimal((int) $totals->net_units),
            'groups' => $groups,
        ];
    }

    private function rootCategory(Category $category, Collection $categories): Category
    {
        $root = $category;

        while ($root->parent_id !== null && $categories->has($root->parent_id)) {
            $root = $categories->get($root->parent_id);
        }

        return $root;
    }

    private function decimalTypes(array $units): array
    {
        return collect(self::TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => Money::decimal($units[$type] ?? 0)])
            ->all();
    }

    private function workflow(int $attentionCount): array
    {
        $pendingCount = Transaction::query()
            ->where('status', TransactionStatus::Pending->value)
            ->count();
        $uncategorized = Transaction::query()
            ->whereNull('category_id')
            ->whereDoesntHave('splits')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS posted_count, '.
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_count',
                [TransactionStatus::Posted->value, TransactionStatus::Pending->value]
            )
            ->first();

        return [
            'pending_transactions_count' => $pendingCount,
            'uncategorized_posted_count' => (int) ($uncategorized?->posted_count ?? 0),
            'uncategorized_pending_count' => (int) ($uncategorized?->pending_count ?? 0),
            'accounts_needing_attention_count' => $attentionCount,
        ];
    }

    private function moneyUnitsSql(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST(ROUND({$column} * 10000) AS SIGNED)",
            'pgsql' => "CAST(ROUND({$column} * 10000) AS BIGINT)",
            default => "CAST(ROUND({$column} * 10000) AS INTEGER)",
        };
    }
}
