<?php

use App\Enums\AccountBalanceNatureEnum;
use App\Enums\AccountBalanceSnapshotSourceTypeEnum;
use App\Enums\AccountMembershipRoleEnum;
use App\Enums\AccountMembershipStatusEnum;
use App\Enums\BudgetTypeEnum;
use App\Enums\CategoryDirectionTypeEnum;
use App\Enums\CategoryGroupTypeEnum;
use App\Enums\CreditDebtTypeEnum;
use App\Enums\MembershipSourceEnum;
use App\Enums\RecurringEndModeEnum;
use App\Enums\RecurringEntryRecurrenceTypeEnum;
use App\Enums\RecurringEntryStatusEnum;
use App\Enums\RecurringEntryTypeEnum;
use App\Enums\RecurringOccurrenceStatusEnum;
use App\Enums\ScheduledEntryStatusEnum;
use App\Enums\TransactionDirectionEnum;
use App\Enums\TransactionKindEnum;
use App\Enums\TransactionSourceTypeEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\AccountMembership;
use App\Models\AccountOpeningBalance;
use App\Models\AccountType;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CreditDebtItem;
use App\Models\CreditDebtPayment;
use App\Models\Merchant;
use App\Models\RecurringEntry;
use App\Models\RecurringEntryOccurrence;
use App\Models\ScheduledEntry;
use App\Models\TrackedItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserYear;
use App\Services\Accounts\AccessibleAccountsQuery;
use App\Services\CreditDebts\CreditDebtPaymentService;
use App\Services\Recurring\RecurringEntryPostingService;
use App\Services\UserProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard with inertia props', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $this->actingAs($user);

    $response = $this->get(route('dashboard', [
        'year' => 2025,
        'month' => 3,
    ]));

    $response
        ->assertSuccessful()
        ->assertSessionHas('dashboard_year', 2025)
        ->assertSessionHas('dashboard_month', 3)
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.filters.year', 2025)
            ->where('dashboard.filters.month', 3)
            ->where('dashboard.filters.available_years', fn ($years) => collect($years)
                ->pluck('value')
                ->contains(2025))
            ->where('dashboard.filters.account_options', fn ($accounts) => collect($accounts)->isNotEmpty())
            ->where('dashboard.filters.account_scope_options', fn ($scopes) => collect($scopes)->isNotEmpty())
            ->where('dashboard.overview.income_total', formatMoney(2000))
            ->where('dashboard.overview.income_total_raw', fn ($value) => (float) $value === 2000.0)
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 600.0)
            ->where('dashboard.overview.net_total_raw', fn ($value) => (float) $value === 1400.0)
            ->where('dashboard.overview.budget_total_raw', fn ($value) => (float) $value === 900.0)
            ->where('dashboard.overview.current_balance_total_raw', fn ($value) => (float) $value === 2600.0)
            ->where('dashboard.overview.previous_balance_total_raw', fn ($value) => (float) $value === 1200.0)
            ->where('dashboard.analysis.period.current_balance_raw', fn ($value) => (float) $value === 2600.0)
            ->where('dashboard.analysis.spending_capacity.available', true)
            ->where('dashboard.analysis.spending_capacity.remaining_budget_raw', fn ($value) => (float) $value === 300.0)
            ->where('dashboard.analysis.spending_capacity.spendable_amount_raw', fn ($value) => (float) $value === 300.0)
            ->where('dashboard.analysis.spending_capacity.included_components.real_expenses', true)
            ->where('dashboard.analysis.spending_capacity.included_components.future_commitments', false)
            ->where('dashboard.analysis.insights', fn ($insights) => collect($insights)->contains('type', 'budget_remaining'))
            ->where('dashboard.notifications.review_needed_count', 1)
            ->where('dashboard.pending_actions.total_count', 0)
            ->has('dashboard.monthly_trend', 3)
            ->has('dashboard.expense_by_category', 2)
            ->has('dashboard.parent_category_budget_status', 2)
            ->where('dashboard.parent_category_budget_status', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['category_name'] === 'Quotidiano'
                    && $item['budget_total'] === formatMoney(700.0)
                    && (float) $item['actual_total_raw'] === 450.0
                    && (float) $item['delta_raw'] === 250.0)),
        );

    $this->assertDatabaseHas('user_settings', [
        'user_id' => $user->id,
        'active_year' => 2025,
    ]);
});

test('dashboard shares its selected period with sidebar month links while preserving account scope', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $account = $user->accounts()->firstOrFail();

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2025,
            'month' => 3,
            'account_scope' => 'all',
            'account_uuid' => $account->uuid,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.year', 2025)
            ->where('dashboard.filters.month', 3)
            ->where('transactionsNavigation.context.year', 2025)
            ->where('transactionsNavigation.context.month', 3)
            ->where(
                'transactionsNavigation.months.3.href',
                route('dashboard', [
                    'year' => 2025,
                    'month' => 4,
                    'account_scope' => 'all',
                    'account_uuid' => $account->uuid,
                ]),
            ));
});

test('dashboard legacy is not exposed outside local development', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $this->actingAs($user)
        ->get('/dashboard/legacy?year=2025&month=3')
        ->assertNotFound();
});

test('dashboard analysis redirects to the official dashboard while preserving the query string', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $this->actingAs($user)
        ->get(route('dashboard.analysis', ['year' => 2025, 'month' => 3]))
        ->assertRedirect(route('dashboard', ['year' => 2025, 'month' => 3]));
});

test('official dashboard defaults an annual request to the current month', function () {
    $this->travelTo(CarbonImmutable::create(2025, 3, 15, 12));

    $user = User::factory()->create();
    seedDashboardFixture($user);

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2025]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.year', 2025)
            ->where('dashboard.filters.month', 3)
            ->where('dashboard.analysis.timeline.2.key', '2025-03')
            ->where('dashboard.analysis.timeline.2.is_selected', true));
});

test('dashboard keeps liquid balance separate from open credits and debts', function () {
    config(['features.credits_debts.enabled' => true]);

    $user = User::factory()->create(['base_currency_code' => 'EUR']);
    UserYear::query()->create(['user_id' => $user->id, 'year' => 2026, 'is_closed' => false]);
    $account = createTestAccount($user, [
        'currency_code' => 'EUR',
        'currency' => 'EUR',
        'opening_balance' => '500.00',
        'current_balance' => '500.00',
    ]);
    $credit = CreditDebtItem::factory()->forAccount($account)->create([
        'type' => CreditDebtTypeEnum::CREDIT->value,
        'total_amount' => '120.00',
        'due_date' => now()->subDay()->toDateString(),
    ]);
    $debt = CreditDebtItem::factory()->forAccount($account)->create([
        'type' => CreditDebtTypeEnum::DEBIT->value,
        'total_amount' => '70.00',
        'due_date' => now()->addDay()->toDateString(),
    ]);
    createDashboardCreditDebtPayment($user, $credit, $account, '20.00', now()->toDateString());

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2026]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.overview.current_balance_total_raw', fn ($value) => (float) $value === 520.0)
            ->where('dashboard.credits_debts.credits_open_total_raw', fn ($value) => (float) $value === 100.0)
            ->where('dashboard.credits_debts.debts_open_total_raw', fn ($value) => (float) $value === 70.0)
            ->where('dashboard.credits_debts.net_expected_total_raw', fn ($value) => (float) $value === 30.0)
            ->where('dashboard.credits_debts.overdue_count', 1)
        );

    expect($debt->fresh()->remainingAmount())->toBe('70.00');
});

test('dashboard exposes quick start for users without operational accounts', function () {
    $user = User::factory()->create();

    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2026,
        'is_closed' => false,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2026]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('quick_start.show', true));
});

test('dashboard keeps quick start visible when the user only has the default cash account', function () {
    $user = User::factory()->create();

    app(UserProvisioningService::class)->provisionApplicationUser($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('quick_start.show', true));
});

test('dashboard hides quick start forever after the user records any transaction', function () {
    $user = User::factory()->create();

    app(UserProvisioningService::class)->provisionApplicationUser($user);

    $account = $user->accounts()->firstOrFail();

    userTransaction($user, $account, [
        'amount' => 25,
        'currency_code' => $account->currency_code,
        'base_currency_code' => $user->base_currency_code,
        'exchange_rate' => '1.00000000',
        'exchange_rate_date' => now()->toDateString(),
        'converted_base_amount' => 25,
        'exchange_rate_source' => 'identity',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('quick_start.show', false));
});

test('dashboard hides quick start once the user has an operational account', function () {
    $user = User::factory()->create();

    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2026,
        'is_closed' => false,
    ]);

    createTestAccount($user, [
        'name' => 'Conto operativo',
        'currency' => 'EUR',
        'currency_code' => 'EUR',
        'opening_balance' => 100,
        'current_balance' => 100,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2026]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('quick_start.show', false));
});

test('dashboard exposes previous closed month financial recap from real transactions', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04 09:00:00'));

    try {
        $user = User::factory()->create([
            'base_currency_code' => 'EUR',
        ]);

        UserYear::query()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'is_closed' => false,
        ]);

        $account = createTestAccount($user, [
            'opening_balance' => 1000,
            'current_balance' => 2100,
            'opening_balance_date' => '2026-01-01',
        ]);

        $incomeCategory = dashboardRecapCategory($user, 'Stipendio', TransactionDirectionEnum::INCOME);
        $groceriesCategory = dashboardRecapCategory($user, 'Alimentari', TransactionDirectionEnum::EXPENSE);
        $homeCategory = dashboardRecapCategory($user, 'Casa', TransactionDirectionEnum::EXPENSE);

        userTransaction($user, $account, [
            'category_id' => $incomeCategory->id,
            'direction' => TransactionDirectionEnum::INCOME->value,
            'amount' => 1000,
            'transaction_date' => '2026-03-05',
            'value_date' => '2026-03-05',
            'kind' => TransactionKindEnum::MANUAL->value,
        ]);
        userTransaction($user, $account, [
            'category_id' => $groceriesCategory->id,
            'direction' => TransactionDirectionEnum::EXPENSE->value,
            'amount' => 400,
            'transaction_date' => '2026-03-10',
            'value_date' => '2026-03-10',
            'kind' => TransactionKindEnum::MANUAL->value,
        ]);
        userTransaction($user, $account, [
            'category_id' => $incomeCategory->id,
            'direction' => TransactionDirectionEnum::INCOME->value,
            'amount' => 1200,
            'transaction_date' => '2026-04-05',
            'value_date' => '2026-04-05',
            'kind' => TransactionKindEnum::MANUAL->value,
        ]);
        userTransaction($user, $account, [
            'category_id' => $groceriesCategory->id,
            'direction' => TransactionDirectionEnum::EXPENSE->value,
            'amount' => 500,
            'transaction_date' => '2026-04-12',
            'value_date' => '2026-04-12',
            'kind' => TransactionKindEnum::MANUAL->value,
        ]);
        userTransaction($user, $account, [
            'category_id' => $homeCategory->id,
            'direction' => TransactionDirectionEnum::EXPENSE->value,
            'amount' => 200,
            'transaction_date' => '2026-04-20',
            'value_date' => '2026-04-20',
            'kind' => TransactionKindEnum::MANUAL->value,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['year' => 2026, 'month' => 4]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/Analysis')
                ->where('dashboard.monthly_recap.available', true)
                ->where('dashboard.monthly_recap.period.key', '2026-04')
                ->where('dashboard.monthly_recap.previous_period.key', '2026-03')
                ->where('dashboard.monthly_recap.totals.starting_balance_total_raw', fn ($value) => (float) $value === 1600.0)
                ->where('dashboard.monthly_recap.totals.ending_balance_total_raw', fn ($value) => (float) $value === 2100.0)
                ->where('dashboard.monthly_recap.totals.income_total_raw', fn ($value) => (float) $value === 1200.0)
                ->where('dashboard.monthly_recap.totals.expense_total_raw', fn ($value) => (float) $value === 700.0)
                ->where('dashboard.monthly_recap.totals.net_total_raw', fn ($value) => (float) $value === 500.0)
                ->where('dashboard.monthly_recap.totals.net_vs_previous_raw', fn ($value) => (float) $value === -100.0)
                ->where('dashboard.monthly_recap.totals.net_vs_previous_percentage', fn ($value) => (float) $value === -16.67)
                ->where('dashboard.monthly_recap.totals.income_share', fn ($value) => (float) $value === 63.2)
                ->where('dashboard.monthly_recap.totals.expense_share', fn ($value) => (float) $value === 36.8)
                ->where('dashboard.monthly_recap.top_expense_categories.0.category_name', 'Alimentari')
                ->where('dashboard.monthly_recap.top_expense_categories.1.category_name', 'Casa')
                ->where('dashboard.monthly_recap.insights', fn ($insights) => collect($insights)
                    ->contains(fn ($insight) => $insight['type'] === 'net_positive')
                    && collect($insights)->contains(fn ($insight) => $insight['type'] === 'net_worsened')
                    && collect($insights)->contains(fn ($insight) => $insight['type'] === 'top_expense_categories')));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('dashboard monthly recap exposes an empty state when the closed month has no movements', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04 09:00:00'));

    try {
        $user = User::factory()->create([
            'base_currency_code' => 'EUR',
        ]);

        UserYear::query()->create([
            'user_id' => $user->id,
            'year' => 2026,
            'is_closed' => false,
        ]);

        createTestAccount($user, [
            'opening_balance' => 1000,
            'current_balance' => 1000,
            'opening_balance_date' => '2026-01-01',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['year' => 2026, 'month' => 4]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/Analysis')
                ->where('dashboard.monthly_recap.available', false)
                ->where('dashboard.monthly_recap.empty_reason', 'no_closed_month_transactions')
                ->where('dashboard.monthly_recap.period.key', '2026-04')
                ->where('dashboard.monthly_recap.insights', []));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('monthly recap detail view reuses the dashboard recap data', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04 09:00:00'));

    try {
        $user = seedMonthlyRecapFixture();

        $dashboardRecap = null;
        $this->actingAs($user)
            ->get(route('dashboard', ['year' => 2026, 'month' => 4]))
            ->assertSuccessful()
            ->assertInertia(function (Assert $page) use (&$dashboardRecap): void {
                $page->component('dashboard/Analysis');
                $dashboardRecap = $page->toArray()['props']['dashboard']['monthly_recap'];
            });

        $this->actingAs($user)
            ->get(route('monthly-recap.show', ['year' => 2026, 'month' => 4]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/MonthlyRecap')
                ->where('recap.period.key', $dashboardRecap['period']['key'])
                ->where('recap.totals.income_total_raw', $dashboardRecap['totals']['income_total_raw'])
                ->where('recap.totals.expense_total_raw', $dashboardRecap['totals']['expense_total_raw'])
                ->where('recap.totals.net_total_raw', $dashboardRecap['totals']['net_total_raw'])
                ->where('recap.insights.0.message', $dashboardRecap['insights'][0]['message']));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('monthly recap detail and pdf respect the active locale', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04 09:00:00'));

    try {
        $user = seedMonthlyRecapFixture([
            'locale' => 'en',
        ]);

        $this->actingAs($user)
            ->get(route('monthly-recap.show', ['year' => 2026, 'month' => 4]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard/MonthlyRecap')
                ->where('recap.period.label', 'April')
                ->where('recap.insights.0.message', fn (string $message): bool => str_contains($message, 'In April you closed')));

        $this->actingAs($user)
            ->get(route('monthly-recap.pdf', ['year' => 2026, 'month' => 4]))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('Monthly recap', false)
            ->assertDontSee('Riepilogo mensile', false);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('monthly recap pdf is generated on demand without persisted files', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04 09:00:00'));

    try {
        $user = seedMonthlyRecapFixture();
        $beforePdfCount = storedPdfCount();

        $response = $this->actingAs($user)
            ->get(route('monthly-recap.pdf', ['year' => 2026, 'month' => 4]));

        $response
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="soamco-budget-recap-2026-04-all.pdf"');

        expect($response->getContent())->toStartWith('%PDF-1.4');
        expect(storedPdfCount())->toBe($beforePdfCount);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('dashboard aggregates foreign currency transactions using converted base amounts', function () {
    $user = User::factory()->create([
        'base_currency_code' => 'EUR',
    ]);

    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2026,
        'is_closed' => false,
    ]);

    $baseAccount = createTestAccount($user, [
        'name' => 'Conto base',
        'currency' => 'EUR',
        'currency_code' => 'EUR',
        'opening_balance' => 1000,
        'current_balance' => 1100,
    ]);

    $foreignAccount = createTestAccount($user, [
        'name' => 'Conto UK',
        'currency' => 'GBP',
        'currency_code' => 'GBP',
        'opening_balance' => 0,
        'current_balance' => -10,
    ]);

    $incomeCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Entrate dashboard FX',
        'slug' => 'entrate-dashboard-fx',
        'direction_type' => CategoryDirectionTypeEnum::INCOME->value,
        'group_type' => CategoryGroupTypeEnum::INCOME->value,
        'is_active' => true,
    ]);

    $expenseCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Spese dashboard FX',
        'slug' => 'spese-dashboard-fx',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $baseAccount->id,
        'category_id' => $incomeCategory->id,
        'transaction_date' => '2026-04-10',
        'value_date' => '2026-04-10',
        'direction' => TransactionDirectionEnum::INCOME->value,
        'kind' => TransactionKindEnum::MANUAL->value,
        'amount' => 100,
        'currency' => 'EUR',
        'currency_code' => 'EUR',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'description' => 'Income in base currency',
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $foreignAccount->id,
        'category_id' => $expenseCategory->id,
        'transaction_date' => '2026-04-11',
        'value_date' => '2026-04-11',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'kind' => TransactionKindEnum::MANUAL->value,
        'amount' => 10,
        'currency' => 'GBP',
        'currency_code' => 'GBP',
        'base_currency_code' => 'EUR',
        'exchange_rate' => '1.20000000',
        'exchange_rate_date' => '2026-04-11',
        'converted_base_amount' => 12,
        'exchange_rate_source' => 'frankfurter',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'description' => 'Expense in GBP',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2026,
            'month' => 4,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.settings.base_currency', 'EUR')
            ->where('dashboard.overview.income_total_raw', fn ($value) => (float) $value === 100.0)
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 12.0)
            ->where('dashboard.overview.net_total_raw', fn ($value) => (float) $value === 88.0)
            ->where('dashboard.expense_by_category', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['category_name'] === 'Spese dashboard FX'
                    && (float) $item['total_amount_raw'] === 12.0))
            ->where('dashboard.income_by_category', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['category_name'] === 'Entrate dashboard FX'
                    && (float) $item['total_amount_raw'] === 100.0)));
});

test('dashboard excludes unsafe legacy foreign transactions without exchange snapshots from aggregated totals', function () {
    $user = User::factory()->create([
        'base_currency_code' => 'EUR',
    ]);

    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2026,
        'is_closed' => false,
    ]);

    $foreignAccount = createTestAccount($user, [
        'name' => 'Conto legacy GBP',
        'currency' => 'GBP',
        'currency_code' => 'GBP',
        'opening_balance' => 0,
        'current_balance' => -10,
    ]);

    $expenseCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Legacy FX',
        'slug' => 'legacy-fx-dashboard',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $foreignAccount->id,
        'category_id' => $expenseCategory->id,
        'transaction_date' => '2026-04-11',
        'value_date' => '2026-04-11',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'kind' => TransactionKindEnum::MANUAL->value,
        'amount' => 10,
        'currency' => 'GBP',
        'currency_code' => 'GBP',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'description' => 'Unsafe legacy FX expense',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2026,
            'month' => 4,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 0.0)
            ->where('dashboard.overview.net_total_raw', fn ($value) => (float) $value === 0.0)
            ->where('dashboard.overview.transactions_count', 1)
            ->where('dashboard.expense_by_category', []));
});

test('dashboard accessible account filters do not compile to postgres distinct on with incompatible ordering', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $connection = DB::connection();
    $originalGrammar = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new PostgresGrammar($connection));

    try {
        $sql = app(AccessibleAccountsQuery::class)
            ->query($user)
            ->orderByDesc(DB::raw('is_owned'))
            ->orderBy('accounts.name')
            ->toSql();

        expect(strtolower($sql))->not->toContain('distinct on');
    } finally {
        $connection->setQueryGrammar($originalGrammar);
    }
});

test('visiting the official dashboard with only a year query selects a concrete month', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $this->actingAs($user)
        ->withSession([
            'dashboard_year' => 2025,
            'dashboard_month' => 3,
        ]);

    $response = $this->get(route('dashboard', [
        'year' => 2025,
    ]));

    $response
        ->assertSuccessful()
        ->assertSessionHas('dashboard_year', 2025)
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.filters.year', 2025)
            ->where('dashboard.filters.month', 1),
        );

    expect(session('dashboard_month'))->toBe(1);
});

test('dashboard resolves available years from user years even without transactions for that year', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01'));

    $user = User::factory()->create();

    seedDashboardFixture($user);

    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2026,
        'is_closed' => false,
    ]);
    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2027,
        'is_closed' => false,
    ]);
    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2046,
        'is_closed' => false,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.filters.year', 2027)
            ->where('dashboard.filters.available_years', fn ($years) => collect($years)
                ->pluck('value')
                ->contains(2027))
            ->where('dashboard.filters.available_years', fn ($years) => ! collect($years)
                ->pluck('value')
                ->contains(2046)));

    $this->get(route('dashboard', ['year' => 2046]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.year', 2027));
});

test('dashboard ignores records linked to tracked items owned by another user', function () {
    $user = User::factory()->create();
    $foreignUser = User::factory()->create();

    $account = seedDashboardFixture($user);

    $foreignTrackedItem = TrackedItem::query()->create([
        'user_id' => $foreignUser->id,
        'name' => 'Foreign asset',
        'slug' => 'foreign-asset',
        'type' => 'car',
        'is_active' => true,
    ]);

    $expenseCategory = Category::query()
        ->where('user_id', $user->id)
        ->where('slug', 'spesa-casa')
        ->firstOrFail();

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'tracked_item_id' => $foreignTrackedItem->id,
        'transaction_date' => '2025-03-22',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 999,
        'currency' => 'EUR',
        'description' => 'Invalid tracked item',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
    ]);

    Budget::query()->create([
        'user_id' => $user->id,
        'category_id' => $expenseCategory->id,
        'tracked_item_id' => $foreignTrackedItem->id,
        'year' => 2025,
        'month' => 3,
        'amount' => 500,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard', [
        'year' => 2025,
        'month' => 3,
    ]));

    $response
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.overview.expense_total', formatMoney(600.0))
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 600.0)
            ->where('dashboard.overview.budget_total_raw', fn ($value) => (float) $value === 900.0)
            ->where('dashboard.overview.transactions_count', 3));
});

test('dashboard suggests creating the next management year near year end', function () {
    $this->travelTo(now()->setDate(2025, 11, 15));

    $user = User::factory()->create();

    seedDashboardFixture($user);
    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2025,
        'is_closed' => false,
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard', [
        'year' => 2025,
    ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.year_suggestion.next_year', 2026)
            ->where('dashboard.year_suggestion.current_year', 2025));
});

test('dashboard suggests opening the current calendar year when it is missing', function () {
    $this->travelTo(now()->setDate(2026, 3, 10));

    $user = User::factory()->create();

    seedDashboardFixture($user);
    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2025,
        'is_closed' => false,
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard', [
        'year' => 2025,
    ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.year_suggestion.next_year', 2026));
});

test('dashboard prefers the active year over a stale session year', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);
    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2024,
        'is_closed' => false,
    ]);
    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2025,
        'is_closed' => false,
    ]);

    $userSettings = $user->settings()->firstOrNew();
    $userSettings->forceFill([
        'active_year' => 2024,
        'base_currency' => 'EUR',
    ])->save();

    $this->actingAs($user)
        ->withSession([
            'dashboard_year' => 2025,
        ])
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.filters.year', 2024));
});

test('dashboard falls back to evolved account balances when legacy balance columns are empty', function () {
    $user = User::factory()->create();

    $account = seedDashboardFixture($user);

    $account->update([
        'opening_balance' => null,
        'current_balance' => null,
    ]);

    AccountOpeningBalance::query()->create([
        'account_id' => $account->id,
        'balance_date' => '2024-01-01',
        'amount' => 1000,
    ]);

    AccountBalanceSnapshot::query()->create([
        'account_id' => $account->id,
        'snapshot_date' => '2025-03-31',
        'balance' => 2600,
        'source_type' => AccountBalanceSnapshotSourceTypeEnum::SYSTEM->value,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard', [
        'year' => 2025,
        'month' => 3,
    ]));

    $response
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/Analysis')
            ->where('dashboard.overview.current_balance_total_raw', fn ($value) => (float) $value === 2600.0)
            ->where('dashboard.overview.previous_balance_total_raw', fn ($value) => (float) $value === 1200.0)
            ->where('dashboard.accounts_summary.0.opening_balance_raw', fn ($value) => (float) $value === 1000.0)
            ->where('dashboard.accounts_summary.0.current_balance_raw', fn ($value) => (float) $value === 2600.0));
});

test('dashboard excludes opening balance transactions from operational totals while keeping account balances aligned', function () {
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2025-03-01',
        'value_date' => '2025-03-01',
        'direction' => TransactionDirectionEnum::INCOME->value,
        'kind' => TransactionKindEnum::OPENING_BALANCE->value,
        'amount' => 800,
        'currency' => 'EUR',
        'source_type' => TransactionSourceTypeEnum::GENERATED->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'balance_after' => 800,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2025,
            'month' => 3,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.overview.income_total_raw', fn ($value) => (float) $value === 2000.0)
            ->where('dashboard.accounts_summary.0.opening_balance_raw', fn ($value) => (float) $value === 800.0)
            ->where('dashboard.accounts_summary.0.current_balance_raw', fn ($value) => (float) $value === 2400.0));
});

test('dashboard includes owned and shared accessible accounts in the default account filter', function () {
    [$user, $sharedAccount] = seedAccessibleDashboardFilterFixture();

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2025, 'month' => 3]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.account_scope', 'all')
            ->where('dashboard.filters.account_uuid', null)
            ->where('dashboard.filters.show_account_scope_filter', true)
            ->where('dashboard.overview.income_total_raw', fn ($value) => (float) $value === 1500.0)
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 300.0)
            ->where('dashboard.overview.net_total_raw', fn ($value) => (float) $value === 1200.0)
            ->where('dashboard.overview.active_accounts_count', 2)
            ->where('dashboard.accounts_summary', fn ($accounts) => collect($accounts)->pluck('account_name')->all() === [
                'Conto Personale',
                'Conto Revolut',
            ])
            ->where('dashboard.filters.account_options', fn ($options) => collect($options)
                ->contains(fn ($option) => $option['value'] === $sharedAccount->uuid
                    && $option['is_shared'] === true
                    && $option['is_owned'] === false)));
});

test('dashboard can filter only owned accounts', function () {
    [$user] = seedAccessibleDashboardFilterFixture();

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2025,
            'month' => 3,
            'account_scope' => 'owned',
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.account_scope', 'owned')
            ->where('dashboard.filters.account_uuid', null)
            ->where('dashboard.overview.income_total_raw', fn ($value) => (float) $value === 900.0)
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 100.0)
            ->where('dashboard.overview.active_accounts_count', 1)
            ->where('dashboard.accounts_summary', fn ($accounts) => collect($accounts)
                ->pluck('account_name')
                ->all() === ['Conto Personale']));
});

test('dashboard can filter only shared accounts', function () {
    [$user] = seedAccessibleDashboardFilterFixture();

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2025,
            'month' => 3,
            'account_scope' => 'shared',
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.account_scope', 'shared')
            ->where('dashboard.filters.account_uuid', null)
            ->where('dashboard.overview.income_total_raw', fn ($value) => (float) $value === 600.0)
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 200.0)
            ->where('dashboard.overview.active_accounts_count', 1)
            ->where('dashboard.accounts_summary', fn ($accounts) => collect($accounts)
                ->pluck('account_name')
                ->all() === ['Conto Revolut']));
});

test('dashboard can focus on a single shared account', function () {
    [$user, $sharedAccount] = seedAccessibleDashboardFilterFixture();

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2025,
            'month' => 3,
            'account_scope' => 'all',
            'account_uuid' => $sharedAccount->uuid,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.account_scope', 'all')
            ->where('dashboard.filters.account_uuid', $sharedAccount->uuid)
            ->where('dashboard.overview.income_total_raw', fn ($value) => (float) $value === 600.0)
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 200.0)
            ->where('dashboard.accounts_summary', fn ($accounts) => collect($accounts)
                ->pluck('account_name')
                ->all() === ['Conto Revolut']));
});

test('dashboard shows the shared account actual against the single personal reference budget for the invitee', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $account = createTestAccount($owner, ['name' => 'Conto Shared Budget']);

    UserYear::query()->create(['user_id' => $invitee->id, 'year' => 2026, 'is_closed' => false]);

    AccountMembership::query()->create([
        'uuid' => (string) Str::uuid(),
        'account_id' => $account->id,
        'user_id' => $invitee->id,
        'household_id' => $account->household_id,
        'role' => AccountMembershipRoleEnum::VIEWER,
        'status' => AccountMembershipStatusEnum::ACTIVE,
        'permissions' => null,
        'granted_by_user_id' => $owner->id,
        'source' => MembershipSourceEnum::INVITATION,
        'joined_at' => now(),
    ]);

    $expenseRoot = Category::query()->create([
        'user_id' => $owner->id,
        'name' => 'Spese',
        'slug' => 'dashboard-owner-expense-root',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);

    $insurance = Category::query()->create([
        'user_id' => $owner->id,
        'parent_id' => $expenseRoot->id,
        'name' => 'Assicurazione',
        'slug' => 'dashboard-owner-assicurazione',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);

    $sharedExpenseRoot = Category::query()->create([
        'user_id' => $owner->id,
        'account_id' => $account->id,
        'name' => 'Spese',
        'slug' => 'dashboard-shared-expense-root',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);

    $sharedInsurance = Category::query()->create([
        'user_id' => $owner->id,
        'account_id' => $account->id,
        'parent_id' => $sharedExpenseRoot->id,
        'name' => 'Assicurazione',
        'slug' => 'dashboard-shared-assicurazione',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);

    Budget::query()->create([
        'user_id' => $owner->id,
        'category_id' => $insurance->id,
        'year' => 2026,
        'month' => 2,
        'amount' => 700,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);

    Transaction::query()->create([
        'user_id' => $owner->id,
        'account_id' => $account->id,
        'category_id' => $sharedInsurance->id,
        'transaction_date' => '2026-02-15',
        'value_date' => '2026-02-15',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 670,
        'currency' => 'EUR',
        'description' => 'Assicurazione febbraio',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);

    $this->actingAs($invitee)
        ->get(route('dashboard', [
            'year' => 2026,
            'month' => 2,
            'account_scope' => 'all',
            'account_uuid' => $account->uuid,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.budget_vs_actual', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['category_name'] === 'Assicurazione'
                    && (float) $item['budget_total_raw'] === 700.0
                    && (float) $item['actual_total_raw'] === 670.0
                    && (float) $item['delta_raw'] === 30.0))
        );
});

test('dashboard merges semantic root groups across owned actuals and shared actuals without duplicating the budget model', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    UserYear::query()->create(['user_id' => $user->id, 'year' => 2026, 'is_closed' => false]);

    $ownedAccount = createTestAccount($user, ['name' => 'Conto Personale']);
    $sharedAccount = createTestAccount($owner, ['name' => 'Conto Shared']);

    AccountMembership::query()->create([
        'uuid' => (string) Str::uuid(),
        'account_id' => $sharedAccount->id,
        'user_id' => $user->id,
        'household_id' => $sharedAccount->household_id,
        'role' => AccountMembershipRoleEnum::VIEWER,
        'status' => AccountMembershipStatusEnum::ACTIVE,
        'permissions' => null,
        'granted_by_user_id' => $owner->id,
        'source' => MembershipSourceEnum::INVITATION,
        'joined_at' => now(),
    ]);

    $personalRoot = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Spese',
        'slug' => 'dashboard-owned-root-spese',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);

    $personalLeaf = Category::query()->create([
        'user_id' => $user->id,
        'parent_id' => $personalRoot->id,
        'name' => 'Spesa alimentare',
        'slug' => 'dashboard-owned-spesa-alimentare',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);

    $sharedReferenceRoot = Category::query()->create([
        'user_id' => $owner->id,
        'name' => 'Spese',
        'slug' => 'dashboard-shared-reference-root-spese',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);

    $sharedReferenceLeaf = Category::query()->create([
        'user_id' => $owner->id,
        'parent_id' => $sharedReferenceRoot->id,
        'name' => 'Assicurazione',
        'slug' => 'dashboard-shared-reference-assicurazione-aggregate',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);

    $sharedRoot = Category::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'name' => 'Spese',
        'slug' => 'dashboard-shared-root-spese',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);

    $sharedLeaf = Category::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'parent_id' => $sharedRoot->id,
        'name' => 'Assicurazione',
        'slug' => 'dashboard-shared-assicurazione-aggregate',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);

    Budget::query()->create([
        'user_id' => $user->id,
        'category_id' => $personalLeaf->id,
        'year' => 2026,
        'month' => 2,
        'amount' => 100,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);

    Budget::query()->create([
        'user_id' => $owner->id,
        'category_id' => $sharedReferenceLeaf->id,
        'year' => 2026,
        'month' => 2,
        'amount' => 700,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $ownedAccount->id,
        'category_id' => $personalLeaf->id,
        'transaction_date' => '2026-02-10',
        'value_date' => '2026-02-10',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 60,
        'currency' => 'EUR',
        'description' => 'Spesa personale',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);

    Transaction::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'category_id' => $sharedLeaf->id,
        'transaction_date' => '2026-02-15',
        'value_date' => '2026-02-15',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 670,
        'currency' => 'EUR',
        'description' => 'Spesa shared',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2026,
            'month' => 2,
            'account_scope' => 'all',
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.parent_category_budget_status', fn ($items) => collect($items)
                ->where('category_name', 'Spese')
                ->count() === 1)
            ->where('dashboard.parent_category_budget_status', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['category_name'] === 'Spese'
                    && (float) $item['budget_total_raw'] === 800.0
                    && (float) $item['actual_total_raw'] === 730.0
                    && (float) $item['delta_raw'] === 70.0))
        );
});

test('dashboard does not alter root totals when the filter is narrowed to a single shared or owned account', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();

    UserYear::query()->create(['user_id' => $user->id, 'year' => 2026, 'is_closed' => false]);

    $ownedAccount = createTestAccount($user, ['name' => 'Conto Personale']);
    $sharedAccount = createTestAccount($owner, ['name' => 'Conto Shared']);

    AccountMembership::query()->create([
        'uuid' => (string) Str::uuid(),
        'account_id' => $sharedAccount->id,
        'user_id' => $user->id,
        'household_id' => $sharedAccount->household_id,
        'role' => AccountMembershipRoleEnum::VIEWER,
        'status' => AccountMembershipStatusEnum::ACTIVE,
        'permissions' => null,
        'granted_by_user_id' => $owner->id,
        'source' => MembershipSourceEnum::INVITATION,
        'joined_at' => now(),
    ]);

    $personalRoot = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Spese',
        'slug' => 'dashboard-owned-root-spese-single',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);
    $personalLeaf = Category::query()->create([
        'user_id' => $user->id,
        'parent_id' => $personalRoot->id,
        'name' => 'Spesa alimentare',
        'slug' => 'dashboard-owned-spesa-alimentare-single',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);
    $sharedReferenceRoot = Category::query()->create([
        'user_id' => $owner->id,
        'name' => 'Spese',
        'slug' => 'dashboard-shared-reference-root-spese-single',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);
    $sharedReferenceLeaf = Category::query()->create([
        'user_id' => $owner->id,
        'parent_id' => $sharedReferenceRoot->id,
        'name' => 'Assicurazione',
        'slug' => 'dashboard-shared-reference-assicurazione-single',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);
    $sharedRoot = Category::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'name' => 'Spese',
        'slug' => 'dashboard-shared-root-spese-single',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);
    $sharedLeaf = Category::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'parent_id' => $sharedRoot->id,
        'name' => 'Assicurazione',
        'slug' => 'dashboard-shared-assicurazione-single',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => true,
    ]);

    Budget::query()->create([
        'user_id' => $user->id,
        'category_id' => $personalLeaf->id,
        'year' => 2026,
        'month' => 2,
        'amount' => 100,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);
    Budget::query()->create([
        'user_id' => $owner->id,
        'category_id' => $sharedReferenceLeaf->id,
        'year' => 2026,
        'month' => 2,
        'amount' => 700,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $ownedAccount->id,
        'category_id' => $personalLeaf->id,
        'transaction_date' => '2026-02-10',
        'value_date' => '2026-02-10',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 60,
        'currency' => 'EUR',
        'description' => 'Spesa personale singolo conto',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);
    Transaction::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'category_id' => $sharedLeaf->id,
        'transaction_date' => '2026-02-15',
        'value_date' => '2026-02-15',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 670,
        'currency' => 'EUR',
        'description' => 'Spesa shared singolo conto',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2026,
            'month' => 2,
            'account_scope' => 'all',
            'account_uuid' => $sharedAccount->uuid,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.overview.expense_total_raw', fn ($value) => (float) $value === 670.0)
            ->where('dashboard.budget_vs_actual', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['category_name'] === 'Assicurazione'
                    && (float) $item['budget_total_raw'] === 700.0
                    && (float) $item['actual_total_raw'] === 670.0
                    && (float) $item['delta_raw'] === 30.0))
        );

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2026,
            'month' => 2,
            'account_scope' => 'all',
            'account_uuid' => $ownedAccount->uuid,
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.parent_category_budget_status', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['category_name'] === 'Spese'
                    && (float) $item['budget_total_raw'] === 100.0
                    && (float) $item['actual_total_raw'] === 60.0
                    && (float) $item['delta_raw'] === 40.0))
        );
});

test('dashboard keeps account filters backward compatible for users with only owned accounts', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2025, 'month' => 3]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.account_scope', 'all')
            ->where('dashboard.filters.account_uuid', null)
            ->where('dashboard.filters.show_account_scope_filter', false)
            ->where('dashboard.filters.account_scope_options', fn ($options) => collect($options)
                ->pluck('value')
                ->all() === ['all', 'owned', 'shared'])
            ->where('dashboard.filters.account_options', fn ($options) => count($options) === 1));
});

test('financial agenda uses future recurring and scheduled items in the current account scope', function (string $entryType, string $direction) {
    $this->travelTo(now()->setDate(2026, 3, 27));

    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $expenseCategory = Category::query()
        ->where('user_id', $user->id)
        ->where('slug', 'spesa-casa')
        ->firstOrFail();

    $merchant = Merchant::query()->create([
        'user_id' => $user->id,
        'name' => 'Enel Energia',
        'normalized_name' => 'enel energia',
        'default_category_id' => $expenseCategory->id,
        'is_active' => true,
    ]);

    $trackedItem = TrackedItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Eurospin',
        'slug' => 'eurospin-dashboard',
        'type' => 'reference',
        'is_active' => true,
    ]);

    $recurringEntry = RecurringEntry::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'tracked_item_id' => $trackedItem->id,
        'title' => '',
        'description' => '',
        'direction' => $direction,
        'expected_amount' => 89.90,
        'currency' => 'EUR',
        'entry_type' => $entryType,
        'status' => RecurringEntryStatusEnum::ACTIVE->value,
        'recurrence_type' => RecurringEntryRecurrenceTypeEnum::MONTHLY->value,
        'recurrence_interval' => 1,
        'start_date' => '2026-03-29',
        'next_occurrence_date' => '2026-03-29',
        'end_mode' => RecurringEndModeEnum::NEVER->value,
        'auto_generate_occurrences' => true,
        'auto_create_transaction' => false,
        'is_active' => true,
    ]);

    $occurrence = RecurringEntryOccurrence::query()->create([
        'recurring_entry_id' => $recurringEntry->id,
        'sequence_number' => 1,
        'expected_date' => '2026-03-29',
        'due_date' => '2026-03-29',
        'expected_amount' => 89.90,
        'status' => RecurringOccurrenceStatusEnum::PENDING->value,
    ]);

    $scheduled = ScheduledEntry::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'merchant_id' => $merchant->id,
        'title' => '',
        'description' => '',
        'direction' => $direction,
        'expected_amount' => 120.00,
        'currency' => 'EUR',
        'scheduled_date' => '2026-03-28',
        'status' => ScheduledEntryStatusEnum::PLANNED->value,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2026, 'month' => 3]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.recurring_summary.planned_count', 1)
            ->where('dashboard.notifications.due_scheduled_count', 2)
            ->where('dashboard.pending_actions.total_count', 2)
            ->where('dashboard.pending_actions.items', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['title'] === 'Enel Energia'
                    && $item['status_key'] === 'upcoming'
                    && str_contains($item['action_url'], '/recurring-entries')))
            ->where('dashboard.pending_actions.items', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['title'] === 'Eurospin'
                    && $item['status_key'] === 'upcoming'
                    && str_contains($item['action_url'], '/recurring-entries/')))
            ->where('dashboard.scheduled_summary.upcoming', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['display_label'] === 'Enel Energia'
                    && $item['source_url'] === null
                    && $item['entry_kind'] === 'scheduled'
                    && (float) $item['expected_amount_raw'] === 120.0))
            ->where('dashboard.scheduled_summary.upcoming', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['display_label'] === 'Eurospin'
                    && $item['source_url'] === route('recurring-entries.show', ['recurringEntry' => $recurringEntry->uuid, 'highlight' => $occurrence->uuid])
                    && $item['entry_kind'] === 'recurring'
                    && (float) $item['expected_amount_raw'] === 89.9)));

    $sourceUrl = route('recurring-entries.show', [
        'recurringEntry' => $recurringEntry->uuid,
        'highlight' => $occurrence->uuid,
    ]);
    $this->getJson($sourceUrl)->assertSuccessful()
        ->assertJsonPath('recurringEntry.occurrence_page.year', 2026)
        ->assertJsonPath('recurringEntry.occurrences.0.uuid', $occurrence->uuid);

    $transaction = Transaction::query()->where('account_id', $account->id)->firstOrFail();
    $scheduled->update(['matched_transaction_id' => $transaction->id]);
    $this->get(route('dashboard', ['year' => 2026, 'month' => 3]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.scheduled_summary.upcoming', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['id'] === 'scheduled-'.$scheduled->id
                    && $item['source_url'] === route('transactions.show', [
                        'year' => $transaction->transaction_date->year,
                        'month' => $transaction->transaction_date->month,
                        'highlight' => $transaction->uuid,
                    ]))));

    $transaction->delete();
    $occurrence->delete();
    $this->get(route('dashboard', ['year' => 2026, 'month' => 3]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.scheduled_summary.upcoming', fn ($items) => collect($items)
                ->doesntContain('id', 'recurring-'.$occurrence->id)
                && collect($items)->contains(fn ($item) => $item['id'] === 'scheduled-'.$scheduled->id
                    && $item['source_url'] === null)));
    $this->actingAs(User::factory()->create())->getJson($sourceUrl)->assertNotFound();
    $recurringEntry->delete();
    $this->actingAs($user)->getJson($sourceUrl)->assertNotFound();
})->with([
    'recurring expense' => ['recurring', 'expense'],
    'recurring income' => ['recurring', 'income'],
    'installment expense' => ['installment', 'expense'],
    'installment income' => ['installment', 'income'],
]);

test('financial agenda top payees use meaningful fallbacks instead of missing merchant labels', function () {
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $expenseCategory = Category::query()
        ->where('user_id', $user->id)
        ->where('slug', 'spesa-casa')
        ->firstOrFail();

    $trackedItem = TrackedItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Farmacia Centrale',
        'slug' => 'farmacia-centrale-dashboard',
        'type' => 'reference',
        'is_active' => true,
    ]);

    createTransaction(
        user: $user,
        account: $account,
        category: $expenseCategory,
        direction: TransactionDirectionEnum::EXPENSE->value,
        amount: 55,
        date: '2025-03-22',
        status: TransactionStatusEnum::CONFIRMED->value,
        trackedItem: $trackedItem,
        description: '',
    );

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2025, 'month' => 3]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.merchant_breakdown', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['display_label'] === 'Farmacia Centrale'))
            ->where('dashboard.merchant_breakdown', fn ($items) => collect($items)
                ->doesntContain(fn ($item) => $item['display_label'] === 'Senza merchant')));
});

test('financial agenda respects the dashboard account scope for future items', function () {
    $this->travelTo(now()->setDate(2026, 3, 27));

    [$user, $sharedAccount, $ownedAccount] = seedAccessibleDashboardFilterFixture();

    $expenseCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Spesa condivisa',
        'slug' => 'spesa-condivisa-dashboard',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
    ]);

    ScheduledEntry::query()->create([
        'user_id' => $user->id,
        'account_id' => $ownedAccount->id,
        'category_id' => $expenseCategory->id,
        'title' => 'Rata personale',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'expected_amount' => 40,
        'currency' => 'EUR',
        'scheduled_date' => '2026-03-29',
        'status' => ScheduledEntryStatusEnum::PLANNED->value,
    ]);

    ScheduledEntry::query()->create([
        'user_id' => $sharedAccount->user_id,
        'account_id' => $sharedAccount->id,
        'category_id' => $expenseCategory->id,
        'title' => 'Rata condivisa',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'expected_amount' => 80,
        'currency' => 'EUR',
        'scheduled_date' => '2026-03-30',
        'status' => ScheduledEntryStatusEnum::PLANNED->value,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2026,
            'month' => 3,
            'account_scope' => 'shared',
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.pending_actions.items', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['title'] === 'Rata condivisa'))
            ->where('dashboard.pending_actions.items', fn ($items) => collect($items)
                ->doesntContain(fn ($item) => $item['title'] === 'Rata personale'))
            ->where('dashboard.scheduled_summary.upcoming', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['display_label'] === 'Rata condivisa'))
            ->where('dashboard.scheduled_summary.upcoming', fn ($items) => collect($items)
                ->doesntContain(fn ($item) => $item['display_label'] === 'Rata personale'))
            ->where('dashboard.analysis.timeline.2.known_expense_raw', fn ($value) => (float) $value === 80.0)
            ->where('dashboard.analysis.month_detail.forecast.composition.scheduled_raw', fn ($value) => (float) $value === 80.0));
});

test('pending actions respect the selected month filter', function () {
    $this->travelTo(now()->setDate(2026, 3, 27));

    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $expenseCategory = Category::query()
        ->where('user_id', $user->id)
        ->where('slug', 'spesa-casa')
        ->firstOrFail();

    ScheduledEntry::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'title' => 'Scadenza aprile',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'expected_amount' => 30,
        'currency' => 'EUR',
        'scheduled_date' => '2026-04-10',
        'status' => ScheduledEntryStatusEnum::PLANNED->value,
    ]);

    ScheduledEntry::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'title' => 'Scadenza maggio',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'expected_amount' => 45,
        'currency' => 'EUR',
        'scheduled_date' => '2026-05-10',
        'status' => ScheduledEntryStatusEnum::PLANNED->value,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2026, 'month' => 4]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.pending_actions.items', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['title'] === 'Scadenza aprile'))
            ->where('dashboard.pending_actions.items', fn ($items) => collect($items)
                ->doesntContain(fn ($item) => $item['title'] === 'Scadenza maggio')));
});

test('dashboard normalizes shared scope to owned when the user has no shared accessible accounts', function () {
    $user = User::factory()->create();

    seedDashboardFixture($user);

    $this->actingAs($user)
        ->get(route('dashboard', [
            'year' => 2025,
            'month' => 3,
            'account_scope' => 'shared',
        ]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.filters.account_scope', 'owned')
            ->where('dashboard.filters.show_account_scope_filter', false)
            ->where('dashboard.overview.active_accounts_count', 1));
});

test('analysis timeline separates actual forecast credits debts and prevents forecast double counting', function () {
    config(['features.credits_debts.enabled' => true]);

    $this->travelTo(CarbonImmutable::create(2025, 3, 15, 12));

    $user = User::factory()->create(['base_currency_code' => 'EUR']);
    $account = seedDashboardFixture($user);
    $category = Category::query()->where('user_id', $user->id)->where('slug', 'spesa-casa')->firstOrFail();
    $recurringEntry = RecurringEntry::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'title' => 'Canone',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'expected_amount' => 100,
        'currency' => 'EUR',
        'entry_type' => RecurringEntryTypeEnum::RECURRING->value,
        'status' => RecurringEntryStatusEnum::ACTIVE->value,
        'recurrence_type' => RecurringEntryRecurrenceTypeEnum::MONTHLY->value,
        'recurrence_interval' => 1,
        'start_date' => '2025-04-10',
        'next_occurrence_date' => '2025-04-10',
        'end_mode' => RecurringEndModeEnum::NEVER->value,
        'auto_generate_occurrences' => true,
        'auto_create_transaction' => false,
        'is_active' => true,
    ]);
    RecurringEntryOccurrence::query()->create([
        'recurring_entry_id' => $recurringEntry->id,
        'sequence_number' => 1,
        'expected_date' => '2025-04-10',
        'expected_amount' => 100,
        'status' => RecurringOccurrenceStatusEnum::PENDING->value,
    ]);
    $convertedOccurrence = RecurringEntryOccurrence::query()->create([
        'recurring_entry_id' => $recurringEntry->id,
        'sequence_number' => 2,
        'expected_date' => '2025-05-10',
        'expected_amount' => 75,
        'status' => RecurringOccurrenceStatusEnum::PENDING->value,
    ]);
    $realizedTransaction = app(RecurringEntryPostingService::class)->post($convertedOccurrence);
    ScheduledEntry::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'title' => 'Pianificata aprile',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'expected_amount' => 50,
        'currency' => 'EUR',
        'scheduled_date' => '2025-04-20',
        'status' => ScheduledEntryStatusEnum::PLANNED->value,
    ]);
    CreditDebtItem::factory()->forAccount($account)->create([
        'type' => CreditDebtTypeEnum::DEBIT->value,
        'total_amount' => 30,
        'currency_code' => 'EUR',
        'due_date' => '2025-04-25',
    ]);
    CreditDebtItem::factory()->forAccount($account)->create([
        'type' => CreditDebtTypeEnum::CREDIT->value,
        'total_amount' => 80,
        'currency_code' => 'EUR',
        'due_date' => '2025-04-26',
    ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['year' => 2025, 'month' => 4]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('dashboard.analysis.timeline', 12)
            ->where('dashboard.analysis.timeline.3.key', '2025-04')
            ->where('dashboard.analysis.timeline.3.is_selected', true)
            ->where('dashboard.analysis.timeline.3.known_expense_raw', fn ($value) => (float) $value === 150.0)
            ->where('dashboard.analysis.timeline.3.open_debts_raw', fn ($value) => (float) $value === 30.0)
            ->where('dashboard.analysis.timeline.3.open_credits_raw', fn ($value) => (float) $value === 80.0)
            ->where('dashboard.analysis.timeline.4.actual_expense_raw', fn ($value) => (float) $value === 75.0)
            ->where('dashboard.analysis.timeline.4.known_expense_raw', fn ($value) => (float) $value === 0.0)
            ->where('dashboard.analysis.timeline.3.income_composition.recurring_raw', fn ($value) => (float) $value === 0.0)
            ->where('dashboard.analysis.economic_commitment.monthly_raw', fn ($value) => (float) $value === 130.0)
            ->where('dashboard.analysis.economic_commitment.composition.recurring_raw', fn ($value) => (float) $value === 100.0)
            ->where('dashboard.analysis.economic_commitment.composition.installments_raw', fn ($value) => (float) $value === 0.0)
            ->where('dashboard.analysis.economic_commitment.composition.debts_raw', fn ($value) => (float) $value === 30.0)
            ->where('dashboard.analysis.spending_capacity.simulation_impacts.forecast_expenses_raw', fn ($value) => (float) $value === -150.0)
            ->where('dashboard.analysis.spending_capacity.simulation_impacts.open_debts_raw', fn ($value) => (float) $value === -30.0)
            ->where('dashboard.analysis.spending_capacity.simulation_impacts.open_credits_raw', fn ($value) => (float) $value === 80.0)
            ->where('dashboard.analysis.month_detail.forecast.composition.recurring_raw', fn ($value) => (float) $value === 100.0)
            ->where('dashboard.analysis.month_detail.forecast.composition.scheduled_raw', fn ($value) => (float) $value === 50.0)
            ->where('dashboard.analysis.month_detail.period.state', 'future')
            ->has('dashboard.analysis.month_detail.narrative.window', 7)
            ->where('dashboard.analysis.month_detail.narrative.window.3.key', '2025-04')
            ->where('dashboard.analysis.month_detail.narrative.window.3.is_selected', true));

    expect($realizedTransaction->kind)->toBe(TransactionKindEnum::SCHEDULED)
        ->and($convertedOccurrence->fresh()?->converted_transaction_id)
        ->toBe($realizedTransaction->id);
});

function seedDashboardFixture(User $user): Account
{
    $accountType = AccountType::query()->create([
        'code' => 'checking',
        'name' => 'Checking',
        'balance_nature' => AccountBalanceNatureEnum::ASSET->value,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'account_type_id' => $accountType->id,
        'name' => 'Conto Principale',
        'currency' => 'EUR',
        'opening_balance' => 1000,
        'current_balance' => 2300,
        'is_manual' => true,
        'is_active' => true,
    ]);

    $incomeCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Stipendio',
        'slug' => 'stipendio',
        'direction_type' => CategoryDirectionTypeEnum::INCOME->value,
        'group_type' => CategoryGroupTypeEnum::INCOME->value,
        'is_active' => true,
    ]);

    $dailyCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Quotidiano',
        'slug' => 'quotidiano',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);

    $groceriesCategory = Category::query()->create([
        'user_id' => $user->id,
        'parent_id' => $dailyCategory->id,
        'name' => 'Spesa casa',
        'slug' => 'spesa-casa',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::EXPENSE->value,
        'is_active' => true,
    ]);

    $homeCategory = Category::query()->create([
        'user_id' => $user->id,
        'name' => 'Casa',
        'slug' => 'casa',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::BILL->value,
        'is_active' => true,
        'is_selectable' => false,
    ]);

    $utilitiesCategory = Category::query()->create([
        'user_id' => $user->id,
        'parent_id' => $homeCategory->id,
        'name' => 'Bollette',
        'slug' => 'bollette',
        'direction_type' => CategoryDirectionTypeEnum::EXPENSE->value,
        'group_type' => CategoryGroupTypeEnum::BILL->value,
        'is_active' => true,
    ]);

    Budget::query()->create([
        'user_id' => $user->id,
        'category_id' => $groceriesCategory->id,
        'year' => 2025,
        'month' => 3,
        'amount' => 700,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);

    Budget::query()->create([
        'user_id' => $user->id,
        'category_id' => $utilitiesCategory->id,
        'year' => 2025,
        'month' => 3,
        'amount' => 200,
        'budget_type' => BudgetTypeEnum::LIMIT->value,
    ]);

    createTransaction(
        user: $user,
        account: $account,
        category: $groceriesCategory,
        direction: TransactionDirectionEnum::EXPENSE->value,
        amount: 100,
        date: '2025-02-28',
        status: TransactionStatusEnum::CONFIRMED->value,
    );

    createTransaction(
        user: $user,
        account: $account,
        category: $incomeCategory,
        direction: TransactionDirectionEnum::INCOME->value,
        amount: 2000,
        date: '2025-03-05',
        status: TransactionStatusEnum::CONFIRMED->value,
    );

    createTransaction(
        user: $user,
        account: $account,
        category: $groceriesCategory,
        direction: TransactionDirectionEnum::EXPENSE->value,
        amount: 450,
        date: '2025-03-10',
        status: TransactionStatusEnum::CONFIRMED->value,
    );

    createTransaction(
        user: $user,
        account: $account,
        category: $utilitiesCategory,
        direction: TransactionDirectionEnum::EXPENSE->value,
        amount: 150,
        date: '2025-03-18',
        status: TransactionStatusEnum::REVIEW_NEEDED->value,
    );

    createTransaction(
        user: $user,
        account: $account,
        category: $incomeCategory,
        direction: TransactionDirectionEnum::INCOME->value,
        amount: 500,
        date: '2025-04-02',
        status: TransactionStatusEnum::CONFIRMED->value,
    );

    createTransaction(
        user: $user,
        account: $account,
        category: $incomeCategory,
        direction: TransactionDirectionEnum::INCOME->value,
        amount: 300,
        date: '2024-11-10',
        status: TransactionStatusEnum::CONFIRMED->value,
    );

    return $account;
}

function seedAccessibleDashboardFilterFixture(): array
{
    $user = User::factory()->create();
    $owner = User::factory()->create();

    $accountType = AccountType::firstOrCreate(
        ['code' => 'checking'],
        ['name' => 'Checking', 'balance_nature' => AccountBalanceNatureEnum::ASSET->value],
    );

    $ownedAccount = Account::query()->create([
        'user_id' => $user->id,
        'account_type_id' => $accountType->id,
        'name' => 'Conto Personale',
        'currency' => 'EUR',
        'currency_code' => 'EUR',
        'opening_balance' => 300,
        'current_balance' => 1100,
        'is_manual' => true,
        'is_active' => true,
    ]);

    $sharedAccount = Account::query()->create([
        'user_id' => $owner->id,
        'account_type_id' => $accountType->id,
        'name' => 'Conto Revolut',
        'currency' => 'EUR',
        'currency_code' => 'EUR',
        'opening_balance' => 200,
        'current_balance' => 600,
        'is_manual' => true,
        'is_active' => true,
    ]);

    AccountMembership::query()->create([
        'uuid' => (string) Str::uuid(),
        'account_id' => $sharedAccount->id,
        'user_id' => $user->id,
        'household_id' => $sharedAccount->household_id,
        'role' => AccountMembershipRoleEnum::VIEWER,
        'status' => AccountMembershipStatusEnum::ACTIVE,
        'permissions' => null,
        'granted_by_user_id' => $owner->id,
        'source' => MembershipSourceEnum::INVITATION,
        'joined_at' => now(),
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $ownedAccount->id,
        'transaction_date' => '2025-03-04',
        'direction' => TransactionDirectionEnum::INCOME->value,
        'amount' => 900,
        'currency' => 'EUR',
        'description' => 'Owned income',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
        'value_date' => '2025-03-04',
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $ownedAccount->id,
        'transaction_date' => '2025-03-10',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 100,
        'currency' => 'EUR',
        'description' => 'Owned expense',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
        'value_date' => '2025-03-10',
    ]);

    Transaction::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'transaction_date' => '2025-03-08',
        'direction' => TransactionDirectionEnum::INCOME->value,
        'amount' => 600,
        'currency' => 'EUR',
        'description' => 'Shared income',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
        'value_date' => '2025-03-08',
    ]);

    Transaction::query()->create([
        'user_id' => $owner->id,
        'account_id' => $sharedAccount->id,
        'transaction_date' => '2025-03-15',
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 200,
        'currency' => 'EUR',
        'description' => 'Shared expense',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => TransactionStatusEnum::CONFIRMED->value,
        'kind' => TransactionKindEnum::MANUAL->value,
        'value_date' => '2025-03-15',
    ]);

    return [$user, $sharedAccount, $ownedAccount];
}

function createTransaction(
    User $user,
    Account $account,
    Category $category,
    string $direction,
    float $amount,
    string $date,
    string $status,
    ?TrackedItem $trackedItem = null,
    string $description = 'Dashboard test transaction',
): void {
    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => $date,
        'direction' => $direction,
        'amount' => $amount,
        'currency' => 'EUR',
        'source_type' => TransactionSourceTypeEnum::MANUAL->value,
        'status' => $status,
        'description' => $description,
        'tracked_item_id' => $trackedItem?->id,
    ]);
}

function dashboardRecapCategory(User $user, string $name, TransactionDirectionEnum $direction): Category
{
    $groupType = $direction === TransactionDirectionEnum::INCOME
        ? CategoryGroupTypeEnum::INCOME
        : CategoryGroupTypeEnum::EXPENSE;

    return Category::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => Str::slug('dashboard-recap-'.$name.'-'.$direction->value),
        'direction_type' => $direction->value,
        'group_type' => $groupType->value,
        'is_active' => true,
    ]);
}

function seedMonthlyRecapFixture(array $userAttributes = []): User
{
    $user = User::factory()->create([
        'base_currency_code' => 'EUR',
        ...$userAttributes,
    ]);

    UserYear::query()->create([
        'user_id' => $user->id,
        'year' => 2026,
        'is_closed' => false,
    ]);

    $account = createTestAccount($user, [
        'opening_balance' => 1000,
        'current_balance' => 2100,
        'opening_balance_date' => '2026-01-01',
    ]);

    $incomeCategory = dashboardRecapCategory($user, 'Stipendio', TransactionDirectionEnum::INCOME);
    $groceriesCategory = dashboardRecapCategory($user, 'Alimentari', TransactionDirectionEnum::EXPENSE);
    $homeCategory = dashboardRecapCategory($user, 'Casa', TransactionDirectionEnum::EXPENSE);

    userTransaction($user, $account, [
        'category_id' => $incomeCategory->id,
        'direction' => TransactionDirectionEnum::INCOME->value,
        'amount' => 1000,
        'transaction_date' => '2026-03-05',
        'value_date' => '2026-03-05',
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);
    userTransaction($user, $account, [
        'category_id' => $groceriesCategory->id,
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 400,
        'transaction_date' => '2026-03-10',
        'value_date' => '2026-03-10',
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);
    userTransaction($user, $account, [
        'category_id' => $incomeCategory->id,
        'direction' => TransactionDirectionEnum::INCOME->value,
        'amount' => 1200,
        'transaction_date' => '2026-04-05',
        'value_date' => '2026-04-05',
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);
    userTransaction($user, $account, [
        'category_id' => $groceriesCategory->id,
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 500,
        'transaction_date' => '2026-04-12',
        'value_date' => '2026-04-12',
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);
    userTransaction($user, $account, [
        'category_id' => $homeCategory->id,
        'direction' => TransactionDirectionEnum::EXPENSE->value,
        'amount' => 200,
        'transaction_date' => '2026-04-20',
        'value_date' => '2026-04-20',
        'kind' => TransactionKindEnum::MANUAL->value,
    ]);

    return $user;
}

function storedPdfCount(): int
{
    $storagePath = storage_path('app');

    if (! File::isDirectory($storagePath)) {
        return 0;
    }

    return collect(File::allFiles($storagePath))
        ->filter(fn (SplFileInfo $file): bool => strtolower($file->getExtension()) === 'pdf')
        ->count();
}

function createDashboardCreditDebtPayment(User $user, CreditDebtItem $item, Account $account, string $amount, string $paidAt): CreditDebtPayment
{
    return app(CreditDebtPaymentService::class)->create($user, $item, [
        'amount' => $amount,
        'account_id' => $account->id,
        'paid_at' => $paidAt,
    ]);
}

function formatMoney(float $amount, string $currency = 'EUR'): string
{
    $formatter = new NumberFormatter('it_IT', NumberFormatter::CURRENCY);

    return $formatter->formatCurrency($amount, $currency);
}

test('dashboard arrears retain recent and old unpaid occurrences independently of reminder expiry', function () {
    config(['features.credits_debts.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-10-13'));
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $entry = RecurringEntry::create(['user_id' => $user->id, 'account_id' => $account->id,
        'title' => 'Dashboard arrears', 'direction' => 'expense', 'currency' => 'EUR', 'entry_type' => 'recurring',
        'status' => 'active', 'is_active' => true, 'start_date' => '2026-09-01', 'recurrence_type' => 'monthly',
        'recurrence_interval' => 1, 'end_mode' => 'never', 'expected_amount' => 32,
        'is_amount_variable' => false, 'auto_create_transaction' => false, 'auto_generate_occurrences' => false]);
    $rows = collect(['2026-09-01', '2026-10-01', '2026-10-12', '2026-10-02', '2026-10-03', '2026-09-15', '2026-10-04', '2026-10-05'])->map(fn (string $date, int $index): RecurringEntryOccurrence => $entry->occurrences()->create([
        'sequence_number' => $index + 1, 'expected_date' => $date, 'due_date' => $date, 'expected_amount' => 32,
        'status' => $index === 4 ? 'cancelled' : 'pending',
    ]));
    app(RecurringEntryPostingService::class)->post($rows[3], $user);
    $expected = $rows->except([3, 4])->sortBy('expected_date')->map(fn (RecurringEntryOccurrence $row): string => 'recurring:'.$row->uuid)->values()->all();
    $this->actingAs($user);
    foreach (['2026-10-13', '2026-11-13'] as $date) {
        $this->travelTo(CarbonImmutable::parse($date));
        $this->get(route('dashboard', ['year' => 2026, 'month' => (int) now()->month]))->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.credits_debts.arrears', fn ($items): bool => collect($items)->pluck('uuid')->all() === $expected));
    }
});

test('dashboard upcoming events use the canonical residual for every recurring settlement', function (string $settlement) {
    $this->travelTo(CarbonImmutable::parse('2026-10-13'));
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $entry = RecurringEntry::create(['user_id' => $user->id, 'account_id' => $account->id,
        'title' => 'Dashboard future expense', 'direction' => 'expense', 'currency' => 'EUR', 'entry_type' => 'recurring',
        'status' => 'active', 'is_active' => true, 'start_date' => '2026-10-14', 'recurrence_type' => 'monthly',
        'recurrence_interval' => 1, 'end_mode' => 'never', 'expected_amount' => 32,
        'is_amount_variable' => false, 'auto_create_transaction' => false, 'auto_generate_occurrences' => false]);
    $occurrence = $entry->occurrences()->create(['sequence_number' => 1, 'expected_date' => '2026-10-14',
        'due_date' => '2026-10-14', 'expected_amount' => 32, 'status' => 'pending']);
    if (in_array($settlement, ['transaction', 'allocation'], true)) {
        $transaction = Transaction::create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 32,
            'currency' => 'EUR', 'direction' => 'expense', 'transaction_date' => '2026-10-13',
            'kind' => 'manual', 'source_type' => 'manual', 'status' => 'confirmed']);
        if ($settlement === 'allocation') {
            $occurrence->paymentAllocations()->create(['transaction_id' => $transaction->id, 'amount_cents' => 3200]);
        } else {
            $occurrence->update(['matched_transaction_id' => $transaction->id]);
        }
    } elseif ($settlement === 'external') {
        $person = TrackedItem::create(['user_id' => $user->id, 'name' => 'Mario', 'slug' => 'mario-dashboard',
            'is_active' => true, 'is_shared_expense_counterpart' => true]);
        $occurrence->sharedExpenseLedgerEntry()->create(['user_id' => $user->id, 'counterparty_id' => $person->id,
            'entry_date' => '2026-10-13', 'title' => $entry->title, 'currency' => 'EUR', 'total_cents' => 3200,
            'paid_by' => 'counterparty', 'my_share_cents' => 1600, 'counterparty_share_cents' => 1600, 'status' => 'recorded']);
        $occurrence->update(['status' => 'generated']);
    } elseif ($settlement === 'review') {
        $occurrence->update(['status' => 'completed', 'payment_management_state' => 'review']);
    } elseif ($settlement === 'completed') {
        $occurrence->update(['status' => 'completed']);
    }
    $visible = in_array($settlement, ['unpaid', 'review'], true);
    $this->actingAs($user)->get(route('dashboard', ['year' => 2026, 'month' => 10]))->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.scheduled_summary.upcoming', fn ($items): bool => collect($items)->contains('id', 'recurring-'.$occurrence->id) === ($settlement === 'unpaid'))
            ->where('dashboard.pending_actions.items', fn ($items): bool => collect($items)->contains('id', 'recurring-'.$occurrence->id) === $visible)
            ->where('dashboard.upcoming_events.items', fn ($items): bool => collect($items)->contains('id', 'recurring-'.$occurrence->id) === $visible)
            ->where('dashboard.recurring_summary.planned_count', $settlement === 'unpaid' ? 1 : 0)
            ->where('dashboard.credits_debts.arrears', []));
})->with(['unpaid', 'completed', 'transaction', 'allocation', 'external', 'review']);

test('upcoming agenda excludes cancelled plans without changing their records', function (string $date, bool $automatic, string $status) {
    $this->travelTo(CarbonImmutable::parse('2026-10-13'));
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $entry = RecurringEntry::create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'title' => 'Agenda plan', 'direction' => 'expense', 'currency' => 'EUR', 'entry_type' => 'recurring',
        'status' => $status, 'is_active' => $status === 'active', 'start_date' => '2026-09-01',
        'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'end_mode' => 'never', 'expected_amount' => 32,
        'is_amount_variable' => false, 'auto_create_transaction' => $automatic, 'auto_generate_occurrences' => false,
    ]);
    $occurrence = $entry->occurrences()->create([
        'sequence_number' => 1, 'expected_date' => $date, 'due_date' => $date,
        'expected_amount' => 32, 'status' => $automatic ? 'generated' : 'pending',
    ]);
    $transaction = null;
    if ($automatic) {
        $transaction = Transaction::create([
            'user_id' => $user->id, 'account_id' => $account->id, 'amount' => 32,
            'currency' => 'EUR', 'direction' => 'expense', 'transaction_date' => $date,
            'kind' => 'manual', 'source_type' => 'generated', 'status' => 'confirmed',
            'recurring_entry_occurrence_id' => $occurrence->id,
            'payment_verification_started_at' => $date, 'payment_verification_due_at' => $date,
        ]);
        $occurrence->update(['converted_transaction_id' => $transaction->id]);
    }
    $entryBefore = $entry->fresh()->getAttributes();
    $occurrenceBefore = $occurrence->fresh()->getAttributes();
    $transactionBefore = $transaction?->fresh()->getAttributes();

    $this->actingAs($user)->get(route('dashboard', ['year' => 2026, 'month' => 10]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.upcoming_events.items', fn ($items): bool => collect($items)->contains('id', 'recurring-'.$occurrence->id) === ($status !== 'cancelled' && $date >= '2026-10-13' && $date <= '2026-10-16'))
            ->where('dashboard.pending_actions.total_count', $status === 'cancelled' ? 0 : 1)
            ->where('dashboard.pending_actions.items', fn ($items): bool => collect($items)->contains('id', 'recurring-'.$occurrence->id) === ($status !== 'cancelled')));

    expect($entry->fresh()->getAttributes())->toBe($entryBefore)
        ->and($occurrence->fresh()->getAttributes())->toBe($occurrenceBefore)
        ->and($transaction?->fresh()->getAttributes())->toBe($transactionBefore);
})->with([
    'overdue cancelled' => ['2026-09-10', false, 'cancelled'],
    'future cancelled' => ['2026-10-14', false, 'cancelled'],
    'automatic cancelled' => ['2026-10-10', true, 'cancelled'],
    'overdue active' => ['2026-09-10', false, 'active'],
    'future active' => ['2026-10-14', false, 'active'],
    'automatic active' => ['2026-10-10', true, 'active'],
    'automatic today' => ['2026-10-13', true, 'active'],
]);

test('dashboard agenda returns all scheduled items for client pagination', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13'));
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $items = collect(range(14, 21))->map(fn (int $day): ScheduledEntry => ScheduledEntry::create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'title' => 'Scheduled '.$day, 'direction' => 'expense', 'currency' => 'EUR',
        'expected_amount' => 32, 'scheduled_date' => '2026-10-'.$day, 'status' => 'planned',
    ]));
    $this->actingAs($user)->get(route('dashboard', ['year' => 2026, 'month' => 10]))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.pending_actions.total_count', 8)
            ->where('dashboard.pending_actions.items', fn ($rows): bool => collect($rows)->pluck('id')->all() === $items->map(fn (ScheduledEntry $item): string => 'scheduled-'.$item->id)->all()));
});

test('upcoming widget uses today through day three across year boundaries and preserves arrears', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-30 12:00:00'));
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $entry = RecurringEntry::create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'title' => 'Window test', 'direction' => 'expense', 'currency' => 'EUR', 'entry_type' => 'recurring',
        'status' => 'active', 'is_active' => true, 'start_date' => '2026-12-01',
        'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'end_mode' => 'never',
        'expected_amount' => 32, 'is_amount_variable' => false, 'auto_create_transaction' => false,
        'auto_generate_occurrences' => false,
    ]);
    $ids = [];
    foreach ([-1, 0, 1, 2, 3, 4] as $offset) {
        $date = CarbonImmutable::today()->addDays($offset)->toDateString();
        $occurrence = $entry->occurrences()->create([
            'sequence_number' => $offset + 2, 'expected_date' => $date,
            'due_date' => $date, 'expected_amount' => 32, 'status' => 'pending',
        ]);
        $ids[$offset] = 'recurring-'.$occurrence->id;
    }
    $this->actingAs($user)->get(route('dashboard', ['year' => 2026, 'month' => 12]))
        ->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.upcoming_events.items', fn ($items): bool => collect($items)->pluck('id')->sort()->values()->all() === collect($ids)->only([0, 1, 2, 3])->sort()->values()->all())
        ->where('dashboard.pending_actions.items', fn ($items): bool => collect($items)->contains('id', $ids[-1])));
    expect($entry->occurrences()->count())->toBe(6);
});

test('upcoming widget filters scheduled statuses across month boundaries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
    $user = User::factory()->create();
    $account = seedDashboardFixture($user);
    $expected = [];
    foreach (['planned', 'due', 'cancelled', 'converted'] as $status) {
        $entry = ScheduledEntry::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'title' => $status, 'direction' => 'expense', 'currency' => 'EUR',
            'expected_amount' => 32, 'scheduled_date' => '2026-11-02', 'status' => $status,
        ]);
        if (in_array($status, ['planned', 'due'], true)) {
            $expected[] = 'scheduled-'.$entry->id;
        }
    }
    $this->actingAs($user)->get(route('dashboard', ['year' => 2026, 'month' => 10]))
        ->assertSuccessful()->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.upcoming_events.items', fn ($items): bool => collect($items)->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
});
