<?php

use App\Models\Category;
use App\Models\RecurringEntry;
use App\Models\RecurringEntryOccurrence;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserYear;
use App\Services\Recurring\AutomaticPaymentVerificationService;
use App\Services\Recurring\ManageOccurrencePaymentService;
use App\Services\Recurring\RecurringEntryPostingService;
use App\Services\Recurring\TransactionRefundService;
use App\Services\Transactions\RecurringEntryLifecycleService;
use App\Services\Transactions\TransactionMutationService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->travelTo(now()->setDate(2026, 10, 9)->setTime(12, 0));
});

function managedPaymentOccurrence(bool $automatic = true): RecurringEntryOccurrence
{
    $user = User::factory()->create();
    UserYear::create(['user_id' => $user->id, 'year' => 2026, 'is_closed' => false]);
    $account = createTestAccount($user, ['opening_balance' => 1000, 'current_balance' => 1000]);
    $category = Category::create(['user_id' => $user->id, 'name' => 'Bill', 'slug' => 'bill', 'direction_type' => 'expense', 'group_type' => 'expense', 'is_active' => true, 'is_selectable' => true]);
    $entry = RecurringEntry::create(['user_id' => $user->id, 'account_id' => $account->id, 'category_id' => $category->id,
        'title' => 'Managed bill', 'direction' => 'expense', 'currency' => 'EUR', 'entry_type' => 'recurring', 'status' => 'active', 'is_active' => true,
        'recurrence_type' => 'monthly', 'recurrence_interval' => 1, 'recurrence_rule' => ['mode' => 'day_of_month', 'day' => 9],
        'start_date' => '2026-10-09', 'end_mode' => 'never', 'expected_amount' => 15, 'is_amount_variable' => true,
        'auto_generate_occurrences' => false, 'auto_create_transaction' => $automatic]);

    return $entry->occurrences()->create(['sequence_number' => 1, 'expected_date' => '2026-10-09', 'due_date' => '2026-10-09', 'expected_amount' => 15, 'status' => 'pending']);
}

function managePaymentUrl(RecurringEntryOccurrence $occurrence): string
{
    return route('recurring-entries.occurrences.manage-payment', [$occurrence->recurringEntry->uuid, $occurrence->uuid]);
}

it('rectifies an unreal automatic debit atomically and idempotently without deleting its history or links', function () {
    $occurrence = managedPaymentOccurrence();
    $user = $occurrence->recurringEntry->user;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $user, ['automatic_posting' => true]);
    expect($transaction->account->fresh()->current_balance)->toBe('985.00');
    $data = ['request_id' => (string) Str::uuid(), 'reason' => 'not_charged', 'action' => 'pending', 'confirm_rectification' => true, 'reminder_date' => '2026-10-11'];
    $this->actingAs($user)->post(managePaymentUrl($occurrence), $data)->assertSessionHasNoErrors();
    $this->post(managePaymentUrl($occurrence), $data)->assertSessionHasNoErrors();
    expect(Transaction::withTrashed()->count())->toBe(1)
        ->and($transaction->fresh()->amount)->toBe('0.00')
        ->and($transaction->fresh()->payment_original_amount)->toBe('15.00')
        ->and($transaction->fresh()->deleted_at)->toBeNull()
        ->and($transaction->fresh()->status->value)->toBe('ignored')
        ->and($transaction->account->fresh()->current_balance)->toBe('1000.00')
        ->and($occurrence->fresh()->converted_transaction_id)->toBe($transaction->id)
        ->and($transaction->fresh()->recurring_entry_occurrence_id)->toBe($occurrence->id)
        ->and($occurrence->fresh()->payment_management_state)->toBe('pending')
        ->and($occurrence->paymentEvents()->count())->toBe(1);
    app(RecurringEntryLifecycleService::class)->runAutomationPipeline();
    expect(Transaction::count())->toBe(1);
    $this->post(managePaymentUrl($occurrence), [...$data, 'reason' => 'postponed'])->assertSessionHasErrors('payment');
});

it('requires explicit consent before reversing an unverified automatic debit', function () {
    $occurrence = managedPaymentOccurrence();
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $occurrence->recurringEntry->user, ['automatic_posting' => true]);
    $this->actingAs($occurrence->recurringEntry->user)->post(managePaymentUrl($occurrence), ['request_id' => (string) Str::uuid(), 'reason' => 'not_charged', 'action' => 'pending'])->assertSessionHasErrors('payment');
    expect($transaction->fresh()->amount)->toBe('15.00')->and($occurrence->paymentEvents()->count())->toBe(0)
        ->and($transaction->account->fresh()->current_balance)->toBe('985.00');
});

it('keeps a manual occurrence pending without a known new date and visible in later months', function () {
    $occurrence = managedPaymentOccurrence(false);
    $this->actingAs($occurrence->recurringEntry->user)->post(managePaymentUrl($occurrence), [
        'request_id' => (string) Str::uuid(), 'reason' => 'postponed', 'action' => 'pending', 'estimated_date' => null, 'reminder_date' => '2026-10-11',
    ])->assertSessionHasNoErrors();
    expect(Transaction::count())->toBe(0)->and($occurrence->fresh()->payment_estimated_date)->toBeNull();
    $this->travelTo(now()->setDate(2026, 11, 2));
    $this->get(route('dashboard', ['year' => 2026, 'month' => 11]))->assertInertia(fn ($page) => $page
        ->where('dashboard.pending_actions.items.0.payment_management.occurrence_uuid', $occurrence->uuid));
});

it('records a late variable payment against the original rectified occurrence while retaining its forecast', function () {
    $occurrence = managedPaymentOccurrence();
    $user = $occurrence->recurringEntry->user;
    $old = app(RecurringEntryPostingService::class)->post($occurrence, $user, ['automatic_posting' => true]);
    $this->actingAs($user)->post(managePaymentUrl($occurrence), ['request_id' => (string) Str::uuid(), 'reason' => 'not_charged', 'action' => 'pending', 'confirm_rectification' => true])->assertSessionHasNoErrors();
    $this->travelTo(now()->setDate(2026, 11, 2));
    $data = ['request_id' => (string) Str::uuid(), 'reason' => 'already_paid', 'action' => 'record', 'actual_date' => '2026-11-02', 'actual_amount' => 12, 'confirm_new_payment' => true];
    $this->post(managePaymentUrl($occurrence), $data)->assertSessionHasNoErrors();
    $this->post(managePaymentUrl($occurrence), $data)->assertSessionHasNoErrors();
    $payment = $occurrence->fresh()->convertedTransaction;
    expect($payment->id)->not->toBe($old->id)
        ->and($old->fresh()->recurring_entry_occurrence_id)->toBe($occurrence->id)
        ->and($occurrence->fresh()->expected_amount)->toBe('15.00')
        ->and($occurrence->fresh()->payment_actual_amount)->toBe('12.00')
        ->and($occurrence->fresh()->expected_date->toDateString())->toBe('2026-10-09')
        ->and($payment->transaction_date->toDateString())->toBe('2026-11-02')
        ->and($occurrence->fresh()->remainingPaymentCents())->toBe(0)
        ->and($occurrence->fresh()->payment_resolved_at)->not->toBeNull()
        ->and($occurrence->fresh()->payment_reminder_date)->toBeNull()
        ->and($occurrence->paymentAllocations()->count())->toBe(0)
        ->and($payment->account->fresh()->current_balance)->toBe('988.00')
        ->and(Transaction::count())->toBe(2);
    app(RecurringEntryLifecycleService::class)->runAutomationPipeline();
    expect(Transaction::count())->toBe(2);
});

it('corrects an existing automatic debit without changing the forecast or creating another movement', function () {
    $occurrence = managedPaymentOccurrence();
    $user = $occurrence->recurringEntry->user;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $user, ['automatic_posting' => true]);
    $this->actingAs($user)->post(managePaymentUrl($occurrence), ['request_id' => (string) Str::uuid(), 'reason' => 'different_amount', 'action' => 'correct', 'actual_amount' => 12, 'actual_date' => '2026-11-02'])->assertSessionHasNoErrors();
    expect($transaction->fresh()->amount)->toBe('12.00')->and($occurrence->fresh()->expected_amount)->toBe('15.00')
        ->and($transaction->fresh()->transaction_date->toDateString())->toBe('2026-11-02')
        ->and(Transaction::count())->toBe(1)->and($occurrence->fresh()->remainingPaymentCents())->toBe(0)
        ->and($transaction->account->fresh()->current_balance)->toBe('988.00');
});

it('never automatically verifies an expired automatic payment', function () {
    $occurrence = managedPaymentOccurrence();
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $occurrence->recurringEntry->user, ['automatic_posting' => true]);
    $this->travel(30)->days();
    expect(app(AutomaticPaymentVerificationService::class)->confirmExpired())->toBe(0)
        ->and($transaction->fresh()->payment_verified_at)->toBeNull();
});

it('blocks protected adjustments without changing transactions balances or audit history', function (string $protection) {
    $occurrence = managedPaymentOccurrence();
    $user = $occurrence->recurringEntry->user;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $user, ['automatic_posting' => true]);
    if ($protection === 'refund') {
        app(TransactionRefundService::class)->refund($transaction, ['amount' => 3]);
    } elseif ($protection === 'partial') {
        $occurrence->paymentAllocations()->create(['transaction_id' => $transaction->id, 'amount_cents' => 100]);
    } elseif ($protection === 'matched') {
        $occurrence->update(['matched_transaction_id' => $transaction->id]);
    } else {
        app(AutomaticPaymentVerificationService::class)->confirm($transaction);
    }
    $snapshot = Transaction::orderBy('id')->get()->map->getAttributes()->all();
    $balance = $transaction->account->fresh()->current_balance;
    $eventCount = $occurrence->paymentEvents()->count();
    $this->actingAs($user)->post(managePaymentUrl($occurrence), ['request_id' => (string) Str::uuid(), 'reason' => 'not_charged', 'action' => 'pending', 'confirm_rectification' => true])->assertSessionHasErrors('payment');
    expect(Transaction::orderBy('id')->get()->map->getAttributes()->all())->toBe($snapshot)
        ->and($transaction->account->fresh()->current_balance)->toBe($balance)
        ->and($occurrence->paymentEvents()->count())->toBe($eventCount);
})->with(['refund', 'partial', 'matched', 'verified']);

it('links an existing later payment instead of duplicating it', function () {
    $occurrence = managedPaymentOccurrence(false);
    $entry = $occurrence->recurringEntry;
    $this->travelTo(now()->setDate(2026, 11, 2));
    $payment = Transaction::create(['user_id' => $entry->user_id, 'account_id' => $entry->account_id, 'category_id' => $entry->category_id,
        'transaction_date' => '2026-11-02', 'amount' => 12, 'currency' => 'EUR', 'kind' => 'manual', 'source_type' => 'manual', 'status' => 'confirmed', 'direction' => 'expense']);
    app(TransactionMutationService::class)->recalculateAccount($payment->account);
    $balance = $payment->account->fresh()->current_balance;
    $this->actingAs($entry->user)->post(managePaymentUrl($occurrence), [
        'request_id' => (string) Str::uuid(), 'reason' => 'already_paid', 'action' => 'record', 'actual_date' => '2026-11-02', 'actual_amount' => 12, 'transaction_uuid' => $payment->uuid,
    ])->assertSessionHasNoErrors();
    expect(Transaction::count())->toBe(1)->and($occurrence->fresh()->converted_transaction_id)->toBe($payment->id)
        ->and($payment->account->fresh()->current_balance)->toBe($balance)
        ->and($occurrence->fresh()->remainingPaymentCents())->toBe(0);
});

it('cancels only one occurrence and records real bank reversals as review without changing cash', function () {
    $occurrence = managedPaymentOccurrence(false);
    $entry = $occurrence->recurringEntry;
    $next = $entry->occurrences()->create(['sequence_number' => 2, 'expected_date' => '2026-11-09', 'expected_amount' => 15, 'status' => 'pending']);
    $this->actingAs($entry->user)->post(managePaymentUrl($occurrence), ['request_id' => (string) Str::uuid(), 'reason' => 'cancelled', 'action' => 'cancel'])->assertSessionHasNoErrors();
    expect($occurrence->fresh()->status->value)->toBe('cancelled')->and($entry->fresh()->status->value)->toBe('active')
        ->and($next->fresh()->status->value)->toBe('pending')->and(Transaction::count())->toBe(0);
    $transaction = app(RecurringEntryPostingService::class)->post($next, $entry->user, ['automatic_posting' => true]);
    $balance = $transaction->account->fresh()->current_balance;
    $this->post(managePaymentUrl($next), ['request_id' => (string) Str::uuid(), 'reason' => 'bank_reversal', 'action' => 'pending', 'notes' => 'Check original bank reversal', 'estimated_date' => '2026-11-12', 'reminder_date' => '2026-11-12'])->assertSessionHasNoErrors();
    expect($next->fresh()->payment_management_state)->toBe('review')
        ->and($next->fresh()->payment_estimated_date)->toBeNull()
        ->and($next->fresh()->payment_reminder_date)->toBeNull()
        ->and($transaction->fresh()->payment_rectified_at)->toBeNull()
        ->and($transaction->fresh()->amount)->toBe('15.00')
        ->and($transaction->account->fresh()->current_balance)->toBe($balance);
    $next->fresh()->synchronizeSettlementState();
    expect($next->fresh()->payment_management_state)->toBe('review')
        ->and($next->fresh()->payment_resolved_at)->toBeNull();
    $this->get(route('dashboard', ['year' => 2026, 'month' => 11]))->assertInertia(fn ($page) => $page
        ->where('dashboard.pending_actions.items.0.amount', fn (string $amount): bool => str_contains($amount, '15,00')));
    $this->travelTo(now()->setDate(2026, 11, 9));
    app(AutomaticPaymentVerificationService::class)->confirm($transaction->fresh());
    expect($next->fresh()->payment_management_state)->toBe('review')
        ->and($next->fresh()->payment_resolved_at)->toBeNull()
        ->and($transaction->account->fresh()->current_balance)->toBe($balance);
});

it('rolls back the whole adjustment if accounting reconciliation fails', function () {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    $this->mock(TransactionMutationService::class)->shouldReceive('recalculateAccount')->once()->andThrow(new RuntimeException('balance failure'));
    expect(fn () => app(ManageOccurrencePaymentService::class)->manage($occurrence, $entry->user, [
        'request_id' => (string) Str::uuid(), 'reason' => 'not_charged', 'action' => 'pending', 'confirm_rectification' => true,
    ]))->toThrow(RuntimeException::class);
    expect($transaction->fresh()->amount)->toBe('15.00')->and($transaction->fresh()->payment_rectified_at)->toBeNull()
        ->and($transaction->account->fresh()->current_balance)->toBe('985.00')->and($occurrence->paymentEvents()->count())->toBe(0);
});

it('neutralizes an automatic placeholder and links the already recorded actual debit without duplicating cash', function () {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $automatic = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    $actual = Transaction::create(['user_id' => $entry->user_id, 'account_id' => $entry->account_id, 'category_id' => $entry->category_id,
        'transaction_date' => '2026-10-09', 'amount' => 12, 'currency' => 'EUR', 'kind' => 'manual', 'source_type' => 'manual', 'status' => 'confirmed', 'direction' => 'expense']);
    app(TransactionMutationService::class)->recalculateAccount($actual->account);
    $data = ['request_id' => (string) Str::uuid(), 'reason' => 'already_paid', 'action' => 'record', 'transaction_uuid' => $actual->uuid,
        'actual_date' => '2026-10-09', 'actual_amount' => 12, 'confirm_rectification' => true];
    $this->actingAs($entry->user)->post(managePaymentUrl($occurrence), $data)->assertSessionHasNoErrors();
    $this->post(managePaymentUrl($occurrence), $data)->assertSessionHasNoErrors();
    expect(Transaction::count())->toBe(2)->and($automatic->fresh()->amount)->toBe('0.00')
        ->and($automatic->fresh()->payment_original_amount)->toBe('15.00')
        ->and($occurrence->fresh()->converted_transaction_id)->toBe($actual->id)
        ->and($automatic->fresh()->recurring_entry_occurrence_id)->toBe($occurrence->id)
        ->and($actual->fresh()->recurring_entry_occurrence_id)->toBe($occurrence->id)
        ->and($actual->account->fresh()->current_balance)->toBe('988.00')
        ->and($occurrence->fresh()->remainingPaymentCents())->toBe(0);
});

it('corrects a synthetic debit paid from another own account while preserving its transaction identity', function () {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $otherAccount = createTestAccount($entry->user, ['opening_balance' => 100, 'current_balance' => 100]);
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    $this->actingAs($entry->user)->post(managePaymentUrl($occurrence), [
        'request_id' => (string) Str::uuid(), 'reason' => 'other_account', 'action' => 'correct', 'actual_date' => '2026-10-09', 'actual_amount' => 12, 'account_uuid' => $otherAccount->uuid,
    ])->assertSessionHasNoErrors();
    expect(Transaction::count())->toBe(1)->and($transaction->fresh()->account_id)->toBe($otherAccount->id)
        ->and($entry->account->fresh()->current_balance)->toBe('1000.00')
        ->and($otherAccount->fresh()->current_balance)->toBe('88.00')
        ->and($occurrence->fresh()->converted_transaction_id)->toBe($transaction->id);
});

it('links an existing transaction using the frontend payment type after an explicitly confirmed automatic rectification', function (string $type) {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $automatic = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    expect($occurrence->fresh()->status->value)->toBe('completed');
    $actual = Transaction::create([
        'user_id' => $entry->user_id, 'account_id' => $entry->account_id, 'category_id' => $entry->category_id,
        'transaction_date' => '2026-10-09', 'amount' => 12, 'currency' => 'EUR', 'kind' => 'manual',
        'source_type' => 'manual', 'status' => 'confirmed', 'direction' => 'expense',
    ]);
    app(TransactionMutationService::class)->recalculateAccount($actual->account);
    $before = collect($actual->fresh()->getAttributes())->except('balance_after')->all();
    $data = [
        'request_id' => (string) Str::uuid(), 'reason' => 'already_paid', 'action' => 'record',
        'transaction_uuid' => $actual->uuid, 'actual_date' => '2026-10-09', 'actual_amount' => 12,
        'payment_type' => $type, 'final_total_amount' => $type === 'final' ? 12 : null,
        'confirm_rectification' => true,
    ];
    $this->actingAs($entry->user)->post(managePaymentUrl($occurrence), $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(managePaymentUrl($occurrence), $data)->assertRedirect()->assertSessionHasNoErrors();
    expect(collect($actual->fresh()->getAttributes())->except('balance_after')->all())->toBe($before)
        ->and(Transaction::count())->toBe(2)
        ->and($automatic->fresh()->amount)->toBe('0.00')
        ->and($actual->account->fresh()->current_balance)->toBe('988.00')
        ->and($occurrence->paymentAllocations()->count())->toBe(1)
        ->and($occurrence->fresh()->remainingPaymentCents())->toBe($type === 'final' ? 0 : 300)
        ->and($occurrence->fresh()->payment_management_state)->toBe($type === 'final' ? 'paid' : 'partial')
        ->and($occurrence->fresh()->status->value)->toBe($type === 'final' ? 'completed' : 'pending');
    $this->get(route('dashboard', ['year' => 2026, 'month' => 10]))->assertInertia(fn ($page) => $page
        ->where('dashboard.upcoming_events.items', fn ($items): bool => collect($items)->contains('id', 'recurring-'.$occurrence->id) === ($type === 'advance')));
})->with(['final', 'advance']);

it('keeps a confirmed automatic advance partial without changing or duplicating its transaction', function () {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    $transaction->forceFill(['amount' => 10])->save();
    app(TransactionMutationService::class)->recalculateAccount($transaction->account);
    $this->actingAs($entry->user)->get(route('dashboard', ['year' => 2026, 'month' => 10]))->assertInertia(fn ($page) => $page
        ->where('dashboard.upcoming_events.items.0.requires_payment_confirmation', true)
        ->where('dashboard.upcoming_events.items.0.can_verify_payment', true)
        ->where('dashboard.upcoming_events.items.0.payment_management.state', 'partial')
        ->where('dashboard.upcoming_events.items.0.payment_management.remaining_amount', 5));
    $this->actingAs($entry->user)->post(route('transactions.payment-confirmation', $transaction->uuid))->assertRedirect()->assertSessionHasNoErrors();
    $events = $occurrence->paymentEvents()->count();
    $this->post(route('transactions.payment-confirmation', $transaction->uuid))->assertRedirect()->assertSessionHasNoErrors();
    expect($occurrence->fresh()->remainingPaymentCents())->toBe(500)
        ->and($occurrence->fresh()->payment_management_state)->toBe('partial')
        ->and($occurrence->paymentEvents()->count())->toBe($events)
        ->and($occurrence->fresh()->payment_resolved_at)->toBeNull()
        ->and($transaction->fresh()->amount)->toBe('10.00')
        ->and($transaction->account->fresh()->current_balance)->toBe('990.00')
        ->and(Transaction::count())->toBe(1);
});

it('preserves a real review after confirming its linked automatic payment even with no residual', function () {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    $this->actingAs($entry->user)->post(managePaymentUrl($occurrence), [
        'request_id' => (string) Str::uuid(), 'reason' => 'bank_reversal', 'action' => 'pending',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('transactions.payment-confirmation', $transaction->uuid))->assertRedirect()->assertSessionHasNoErrors();
    expect($occurrence->fresh()->remainingPaymentCents())->toBe(0)
        ->and($occurrence->fresh()->payment_management_state)->toBe('review')
        ->and($occurrence->fresh()->payment_resolved_at)->toBeNull()
        ->and($transaction->fresh()->amount)->toBe('15.00')
        ->and(Transaction::count())->toBe(1);
});

it('links an explicit existing final payment to a completed occurrence that still has an authoritative residual', function (bool $review) {
    $occurrence = managedPaymentOccurrence(false);
    $entry = $occurrence->recurringEntry;
    $actual = Transaction::create([
        'user_id' => $entry->user_id, 'account_id' => $entry->account_id,
        'transaction_date' => '2026-10-09', 'amount' => 12, 'currency' => 'EUR', 'kind' => 'manual',
        'source_type' => 'manual', 'status' => 'confirmed', 'direction' => 'expense',
    ]);
    $before = $actual->fresh()->getAttributes();
    $occurrence->forceFill(['status' => 'completed', 'payment_management_state' => $review ? 'review' : null])->save();
    expect($occurrence->remainingPaymentCents())->toBe(1500);
    $data = [
        'request_id' => (string) Str::uuid(), 'reason' => 'already_paid', 'action' => 'record',
        'transaction_uuid' => $actual->uuid, 'actual_date' => '2026-10-09', 'actual_amount' => 12,
        'payment_type' => 'final', 'final_total_amount' => 12,
    ];
    $this->actingAs($entry->user)->post(managePaymentUrl($occurrence), $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(managePaymentUrl($occurrence), $data)->assertRedirect()->assertSessionHasNoErrors();
    expect($actual->fresh()->getAttributes())->toBe($before)
        ->and(Transaction::count())->toBe(1)
        ->and($occurrence->fresh()->remainingPaymentCents())->toBe(0)
        ->and($occurrence->fresh()->payment_management_state)->toBe($review ? 'review' : 'paid')
        ->and($occurrence->paymentAllocations()->count())->toBe(1);
})->with([false, true]);

it('presents an explicitly linked settled payment as paid even when its stored management label is stale', function () {
    $occurrence = managedPaymentOccurrence(false);
    $entry = $occurrence->recurringEntry;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user);
    $occurrence->forceFill(['payment_management_state' => 'pending', 'payment_resolved_at' => null])->save();
    $before = $transaction->fresh()->getAttributes();
    $presentation = app(ManageOccurrencePaymentService::class)->presentation($occurrence->fresh(), $entry->user);
    expect($presentation['state'])->toBe('paid')
        ->and($presentation['remaining_amount'])->toEqual(0)
        ->and($presentation['has_automatic_transaction'])->toBeFalse()
        ->and($transaction->fresh()->getAttributes())->toBe($before);
    $this->actingAs($entry->user)->get(route('dashboard', ['year' => 2026, 'month' => 10]))->assertInertia(fn ($page) => $page
        ->where('dashboard.upcoming_events.items', fn ($items): bool => ! collect($items)->contains('id', 'recurring-'.$occurrence->id)));
});

it('keeps an unverified automatic posting in the dashboard as awaiting confirmation without enabling another payment', function () {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    $before = $transaction->fresh()->getAttributes();
    $this->actingAs($entry->user)->get(route('dashboard', ['year' => 2026, 'month' => 10]))->assertInertia(fn ($page) => $page
        ->where('dashboard.upcoming_events.items.0.requires_payment_confirmation', true)
        ->where('dashboard.upcoming_events.items.0.payment_management.state', 'to_verify')
        ->where('dashboard.upcoming_events.items.0.payment_management.remaining_amount', 0)
        ->missing('dashboard.upcoming_events.items.0.can_confirm_manual'));
    expect($transaction->fresh()->getAttributes())->toBe($before)->and(Transaction::count())->toBe(1);
});

it('repairs a stale paid confirmation when the authoritative residual has become positive', function () {
    $occurrence = managedPaymentOccurrence();
    $entry = $occurrence->recurringEntry;
    $transaction = app(RecurringEntryPostingService::class)->post($occurrence, $entry->user, ['automatic_posting' => true]);
    $transaction->forceFill(['amount' => 10, 'payment_verified_at' => now(), 'payment_verification_method' => 'manual'])->save();
    $occurrence->forceFill(['payment_management_state' => 'paid', 'payment_resolved_at' => now()])->save();
    $this->actingAs($entry->user)->post(route('transactions.payment-confirmation', $transaction->uuid))->assertRedirect()->assertSessionHasNoErrors();
    expect($occurrence->fresh()->remainingPaymentCents())->toBe(500)
        ->and($occurrence->fresh()->payment_management_state)->toBe('partial')
        ->and($occurrence->fresh()->payment_resolved_at)->toBeNull()
        ->and(Transaction::count())->toBe(1);
});

it('never presents an unpaid residual as paid because of a stale stored label', function () {
    $occurrence = managedPaymentOccurrence(false);
    $occurrence->forceFill(['payment_management_state' => 'paid'])->save();
    $presentation = app(ManageOccurrencePaymentService::class)->presentation($occurrence, $occurrence->recurringEntry->user);
    expect($presentation['state'])->toBe('pending')
        ->and($presentation['remaining_amount'])->toEqual(15)
        ->and(Transaction::count())->toBe(0);
});
