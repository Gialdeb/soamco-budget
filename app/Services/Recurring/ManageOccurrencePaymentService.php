<?php

namespace App\Services\Recurring;

use App\Enums\RecurringOccurrenceStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\CounterpartyPayment;
use App\Models\RecurringEntryOccurrence;
use App\Models\RecurringPaymentEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounts\AccessibleAccountsQuery;
use App\Services\Transactions\TransactionExchangeSnapshotService;
use App\Services\Transactions\TransactionMutationService;
use App\Services\UserYearService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageOccurrencePaymentService
{
    /** @return array<string, mixed> */
    public function presentation(RecurringEntryOccurrence $occurrence, User $actor, bool $includeActions = true): array
    {
        $transaction = $occurrence->convertedTransaction;
        $automatic = $transaction !== null && $transaction->payment_verification_started_at !== null && $transaction->payment_rectified_at === null && $transaction->payment_verification_method !== 'manual';
        $canRectify = $includeActions && $automatic && $transaction->payment_verification_method !== 'manual';
        if ($canRectify) {
            try {
                $this->assertUnprotected($occurrence, $transaction);
            } catch (ValidationException) {
                $canRectify = false;
            }
        }
        $entry = $occurrence->recurringEntry;
        $active = $transaction?->status === TransactionStatusEnum::CONFIRMED;
        $read = app(RecurringOccurrenceReadService::class);
        $paid = $read->paid($occurrence);
        $remaining = $read->remaining($occurrence);
        $state = $occurrence->payment_management_state;
        if (! in_array($state, ['review', 'cancelled'], true)) {
            if ($remaining === 0 && $paid > 0) {
                $state = $automatic ? 'to_verify' : ($state === 'corrected' ? 'corrected' : 'paid');
            } elseif ($remaining > 0 && $paid > 0) {
                $state = 'partial';
            } else {
                $state = in_array($state, ['paid', 'corrected'], true) ? 'pending' : ($state ?? ($automatic ? 'to_verify' : 'pending'));
            }
        }

        return [
            'occurrence_uuid' => $occurrence->uuid, 'entry_uuid' => $entry->uuid,
            'reference' => $entry->trackedItem?->name ?? $entry->title,
            'expected_amount' => (float) $occurrence->expected_amount,
            'is_amount_variable' => (bool) $entry->is_amount_variable,
            'total_due_amount' => $occurrence->paymentTargetCents() / 100,
            'paid_amount' => $paid / 100,
            'remaining_amount' => $remaining / 100,
            'expected_date' => ($occurrence->due_date ?? $occurrence->expected_date)->toDateString(),
            'estimated_date' => $occurrence->payment_estimated_date?->toDateString(),
            'actual_amount' => $active ? (float) $transaction->amount : null,
            'actual_date' => $active ? $transaction->transaction_date->toDateString() : null,
            'state' => $state,
            'has_automatic_transaction' => $automatic, 'can_rectify' => $canRectify,
            'accounts' => $includeActions ? $this->presentationAccounts($actor, $entry->currency) : [],
            'history' => ($occurrence->relationLoaded('paymentEvents') ? $occurrence->paymentEvents : $occurrence->paymentEvents()->orderBy('id')->get())->sortBy('id')->map(fn ($event): array => [
                ...$event->only(['id', 'reason', 'action', 'created_at', 'notes']),
                'action' => ($event->details['rectified'] ?? false) ? 'rectify' : $event->action,
                'before' => $this->timelineSnapshot($event->details['before'] ?? []),
                'after' => $this->timelineSnapshot($event->details['after'] ?? []),
                'rectified_amount' => ($event->details['rectified'] ?? false) ? ($event->details['before']['transaction']['amount'] ?? null) : null,
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function presentationAccounts(User $actor, string $currency): array
    {
        $request = request();
        $key = self::class.':accounts:'.$actor->id.':'.$currency;
        if ($request->isMethod('GET') && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $accounts = app(AccessibleAccountsQuery::class)->editable($actor)->where('currency', $currency)->get(['accounts.uuid', 'accounts.name'])->toArray();
        if ($request->isMethod('GET')) {
            $request->attributes->set($key, $accounts);
        }

        return $accounts;
    }

    /** @param array<string, mixed> $data */
    public function manage(RecurringEntryOccurrence $occurrence, User $actor, array $data): RecurringEntryOccurrence
    {
        return DB::transaction(function () use ($occurrence, $actor, $data): RecurringEntryOccurrence {
            $occurrence = RecurringEntryOccurrence::withHistoricalPlan()->with('recurringEntry.user')->lockForUpdate()->findOrFail($occurrence->id);
            $entry = $occurrence->recurringEntry;
            if ($occurrence->not_due !== null) {
                throw ValidationException::withMessages(['occurrence' => __('ui.not_due_protected')]);
            }
            abort_unless(app(AccessibleAccountsQuery::class)->canEditAccountId($actor, (int) $entry->account_id), 403);
            $entry->ensureComponentIsMutable();
            $fingerprint = hash('sha256', json_encode(Arr::sortRecursive(Arr::except($data, 'request_id')), JSON_THROW_ON_ERROR));
            $existing = RecurringPaymentEvent::query()->where('request_id', $data['request_id'])->first();
            if ($existing !== null) {
                if ($existing->occurrence_id !== $occurrence->id || $existing->actor_id !== $actor->id || ($existing->details['fingerprint'] ?? null) !== $fingerprint) {
                    $this->fail('request_reused');
                }

                return $occurrence;
            }
            $before = $this->snapshot($occurrence);
            $transaction = $occurrence->converted_transaction_id === null ? null : Transaction::query()->lockForUpdate()->findOrFail($occurrence->converted_transaction_id);
            if ($transaction !== null) {
                abort_unless(app(AccessibleAccountsQuery::class)->canEditAccountId($actor, (int) $transaction->account_id), 403);
            }
            $action = $data['action'];
            $reason = $data['reason'];
            if (in_array($reason, ['duplicate_charge', 'bank_reversal'], true) && $action !== 'pending') {
                $this->fail('review_only');
            }
            if (in_array($action, ['pending', 'cancel'], true)) {
                if (! in_array($reason, ['duplicate_charge', 'bank_reversal'], true)
                    && ($occurrence->matched_transaction_id !== null || $occurrence->sharedExpenseLedgerEntry()->exists())) {
                    $this->fail('protected');
                }
                if ($transaction !== null && $transaction->status === TransactionStatusEnum::CONFIRMED && ! in_array($reason, ['duplicate_charge', 'bank_reversal'], true)) {
                    if (! ($data['confirm_rectification'] ?? false)) {
                        $this->fail('confirmation_required');
                    }
                    $this->rectify($occurrence, $transaction, $actor, $reason);
                } elseif ($action === 'cancel' && $occurrence->personalPaymentCents() + $occurrence->allocatedPaymentCents() + $occurrence->externalPaymentCents() > 0) {
                    $this->fail('protected');
                }
                $review = in_array($reason, ['duplicate_charge', 'bank_reversal'], true);
                $occurrence->forceFill([
                    'payment_management_state' => $review ? 'review' : ($action === 'cancel' ? 'cancelled' : ($reason === 'processing' ? 'processing' : 'pending')),
                    'payment_estimated_date' => $review ? null : ($data['estimated_date'] ?? null),
                    'payment_reminder_date' => $review || $action === 'cancel' ? null : ($data['reminder_date'] ?? null),
                    'payment_resolved_at' => $action === 'cancel' ? now() : null,
                    'automatic_posting_blocked_at' => $occurrence->automatic_posting_blocked_at ?? now(),
                    'status' => $action === 'cancel' ? RecurringOccurrenceStatusEnum::CANCELLED : ($review ? $occurrence->status : RecurringOccurrenceStatusEnum::PENDING),
                ])->save();
            } elseif ($action === 'confirm') {
                if ($transaction === null || $transaction->status !== TransactionStatusEnum::CONFIRMED || $transaction->payment_rectified_at !== null) {
                    $this->fail('no_payment');
                }
                $transaction->forceFill(['payment_verified_at' => $transaction->payment_verified_at ?? now(), 'payment_verification_method' => 'manual'])->saveQuietly();
                $settled = $occurrence->remainingPaymentCents() === 0;
                $review = $occurrence->payment_management_state === 'review';
                $occurrence->forceFill([
                    'payment_management_state' => $review ? 'review' : ($settled ? 'paid' : 'partial'),
                    'payment_resolved_at' => $settled && ! $review ? now() : null,
                    'payment_reminder_date' => $settled && ! $review ? null : $occurrence->payment_reminder_date,
                ])->save();
                $occurrence->synchronizeSettlementState();
            } elseif (in_array($action, ['correct', 'record'], true)) {
                $review = $occurrence->payment_management_state === 'review';
                if ($occurrence->status === RecurringOccurrenceStatusEnum::CANCELLED || $occurrence->matched_transaction_id !== null
                    || ($occurrence->paymentAllocations()->exists() && ! ($action === 'record' && isset($data['payment_type']))) || $occurrence->sharedExpenseLedgerEntry()->exists()
                    || $entry->shared_expense_counterparty_id !== null || $entry->salarySchedule !== null) {
                    $this->fail('protected');
                }
                if ($action === 'record' && isset($data['payment_type'])
                    && ($transaction === null || $transaction->payment_rectified_at !== null
                        || $transaction->payment_verification_started_at === null || $transaction->payment_verification_method === 'manual')) {
                    $transaction = $this->recordAllocated($occurrence, $actor, $data);
                } elseif ($transaction !== null && $transaction->payment_rectified_at === null) {
                    $this->assertUnprotected($occurrence, $transaction);
                    if ($action === 'record' && ! empty($data['transaction_uuid']) && $data['transaction_uuid'] !== $transaction->uuid) {
                        if (! ($data['confirm_rectification'] ?? false)) {
                            $this->fail('confirmation_required');
                        }
                        $this->rectify($occurrence, $transaction, $actor, $reason);
                        $transaction = $this->record($occurrence, $actor, $data);
                    } else {
                        $transaction = $this->correct($occurrence, $transaction, $actor, $data);
                    }
                } else {
                    if ($action === 'correct') {
                        $this->fail('no_payment');
                    }
                    $transaction = $this->record($occurrence, $actor, $data);
                }
                $occurrence->refresh();
                $settled = $occurrence->remainingPaymentCents() === 0;
                $occurrence->forceFill(['payment_management_state' => $review ? 'review' : ($settled ? ($action === 'correct' ? 'corrected' : 'paid') : 'partial'), 'payment_resolved_at' => $settled && ! $review ? now() : null, 'payment_reminder_date' => $settled && ! $review ? null : $occurrence->payment_reminder_date])->save();
            } else {
                $this->fail('invalid');
            }
            $occurrence->paymentEvents()->create([
                'request_id' => $data['request_id'], 'transaction_id' => $transaction?->id, 'actor_id' => $actor->id,
                'reason' => $reason, 'action' => $action, 'notes' => $data['notes'] ?? null,
                'details' => ['fingerprint' => $fingerprint, 'before' => $before, 'after' => $this->snapshot($occurrence), 'request' => Arr::except($data, ['request_id']), 'rectified' => ($before['transaction']['payment_rectified_at'] ?? null) === null && ! empty($before['transaction']['id']) && Transaction::query()->find($before['transaction']['id'])?->payment_rectified_at !== null],
            ]);

            return $occurrence->fresh();
        }, 5);
    }

    public function assertUnprotected(RecurringEntryOccurrence $occurrence, Transaction $transaction): void
    {
        $transaction->ensureNoPlanRefund();
        if ($transaction->payment_rectified_at !== null || $transaction->is_transfer || $transaction->import_id !== null || $transaction->import_row_id !== null
            || $transaction->matched_sample_id !== null || $transaction->matched_rule_id !== null
            || $transaction->reconciliation_key !== null || $transaction->posted_at !== null
            || $transaction->refundTransaction()->exists() || $transaction->refunded_transaction_id !== null
            || $transaction->related_transaction_id !== null || $transaction->shared_expense_counterparty_id !== null
            || $transaction->matchedRecurringOccurrences()->exists() || $transaction->matchedScheduledEntries()->exists()
            || $transaction->occurrencePaymentAllocations()->exists() || $occurrence->paymentAllocations()->exists()
            || $transaction->creditDebtPayment()->exists() || $transaction->sharedExpenseSettlement()->exists()
            || CounterpartyPayment::query()->where('transaction_id', $transaction->id)->exists()
            || $occurrence->sharedExpenseLedgerEntry()->exists() || $occurrence->matched_transaction_id !== null
            || $occurrence->personalSettlementTransactions()->count() !== 1
            || $occurrence->converted_transaction_id !== $transaction->id) {
            $this->fail('protected');
        }
    }

    public function rectify(RecurringEntryOccurrence $occurrence, Transaction $transaction, User $actor, string $reason): void
    {
        $this->assertUnprotected($occurrence, $transaction);
        if ($transaction->payment_verification_started_at === null || $transaction->kind?->value !== 'scheduled'
            || $transaction->payment_verification_method === 'manual') {
            $this->fail('real_payment');
        }
        app(UserYearService::class)->ensureDateYearIsOpen($occurrence->recurringEntry->user, $transaction->transaction_date->toDateString(), 'payment');
        $occurrence->forceFill(['payment_actual_amount' => null, 'payment_actual_date' => null, 'automatic_posting_blocked_at' => now(), 'payment_failed_at' => now()])->save();
        $transaction->forceFill(['payment_original_amount' => $transaction->amount, 'amount' => 0, 'converted_base_amount' => 0, 'status' => TransactionStatusEnum::IGNORED, 'payment_rectified_at' => now(), 'payment_rectification_reason' => $reason, 'updated_by_user_id' => $actor->id])->save();
        app(TransactionMutationService::class)->recalculateAccount($transaction->account);
        app(TransactionMutationService::class)->reconcileProcessedCreditCardCyclesForTransactions([$transaction]);
    }

    /** @param array<string, mixed> $data */
    private function correct(RecurringEntryOccurrence $occurrence, Transaction $transaction, User $actor, array $data): Transaction
    {
        $originalAccount = $transaction->account()->with('user:id,base_currency_code')->firstOrFail();
        $account = $originalAccount;
        if (! empty($data['account_uuid']) && $data['account_uuid'] !== $account->uuid) {
            $account = app(AccessibleAccountsQuery::class)->editable($actor)->where('accounts.uuid', $data['account_uuid'])->with('user:id,base_currency_code')->first();
            if (($data['reason'] ?? null) !== 'other_account' || $transaction->payment_verification_started_at === null
                || $transaction->payment_verification_method === 'manual' || $account === null
                || $account->currency !== $transaction->currency || $account->user_id !== $transaction->user_id) {
                $this->fail('account_change');
            }
        }
        app(UserYearService::class)->ensureDateYearIsOpen($occurrence->recurringEntry->user, $transaction->transaction_date->toDateString(), 'payment');
        app(UserYearService::class)->ensureDateYearIsOpen($occurrence->recurringEntry->user, $data['actual_date'], 'actual_date');
        if (isset($data['payment_type'])) {
            app(AllocateOccurrencePaymentService::class)->preparePaymentTarget($occurrence, (string) $data['actual_amount'], $data['payment_type'], isset($data['final_total_amount']) ? (string) $data['final_total_amount'] : null, $transaction->activeAmountCents());
            $occurrence->forceFill(['payment_actual_date' => $data['actual_date']])->save();
        } else {
            $occurrence->forceFill(['payment_actual_amount' => $data['actual_amount'], 'payment_actual_date' => $data['actual_date']])->save();
        }
        $original = clone $transaction;
        $transaction->forceFill([
            'account_id' => $account->id,
            'amount' => $data['actual_amount'], 'transaction_date' => $data['actual_date'], 'value_date' => $data['actual_date'],
            ...app(TransactionExchangeSnapshotService::class)->buildForAccount($account, (float) $data['actual_amount'], $data['actual_date']),
            'payment_verified_at' => now(), 'payment_verification_method' => 'manual', 'updated_by_user_id' => $actor->id,
        ])->save();
        $transaction->unsetRelation('account');
        app(TransactionMutationService::class)->recalculateAccount($originalAccount);
        if ($account->id !== $originalAccount->id) {
            app(TransactionMutationService::class)->recalculateAccount($account);
        }
        app(TransactionMutationService::class)->reconcileProcessedCreditCardCyclesForTransactions([$original, $transaction]);
        $occurrence->refresh()->synchronizeSettlementState();

        return $transaction;
    }

    /** @param array<string, mixed> $data */
    private function record(RecurringEntryOccurrence $occurrence, User $actor, array $data): Transaction
    {
        if (isset($data['payment_type'])) {
            return $this->recordAllocated($occurrence, $actor, $data);
        }
        $entry = $occurrence->recurringEntry;
        if ($occurrence->personalPaymentCents() !== 0 || $occurrence->externalPaymentCents() !== 0) {
            $this->fail('protected');
        }
        app(UserYearService::class)->ensureDateYearIsOpen($entry->user, $data['actual_date'], 'actual_date');
        $occurrence->forceFill(['payment_actual_amount' => $data['actual_amount'], 'payment_actual_date' => $data['actual_date'], 'amount_confirmed_at' => now(), 'automatic_posting_blocked_at' => $occurrence->automatic_posting_blocked_at ?? now()])->save();
        if (! empty($data['transaction_uuid'])) {
            $transaction = Transaction::query()->where('uuid', $data['transaction_uuid'])->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            if (! app(AccessibleAccountsQuery::class)->canEditAccountId($actor, (int) $transaction->account_id) || $transaction->recurring_entry_occurrence_id !== null || $transaction->occurrencePaymentAllocations()->exists()
                || $transaction->creditDebtPayment()->exists() || $transaction->sharedExpenseSettlement()->exists()
                || $transaction->refundTransaction()->exists() || $transaction->is_transfer || $transaction->shared_expense_counterparty_id !== null
                || $transaction->refunded_transaction_id !== null || $transaction->related_transaction_id !== null
                || CounterpartyPayment::query()->where('transaction_id', $transaction->id)->exists()
                || $transaction->status !== TransactionStatusEnum::CONFIRMED || $transaction->kind?->value !== 'manual'
                || $transaction->currency !== $entry->currency || $transaction->direction !== $entry->direction
                || (float) $transaction->amount !== (float) $data['actual_amount'] || $transaction->transaction_date->toDateString() !== $data['actual_date']
                || RecurringEntryOccurrence::query()->where('converted_transaction_id', $transaction->id)->orWhere('matched_transaction_id', $transaction->id)->exists()) {
                $this->fail('protected');
            }
            $transaction->forceFill(['recurring_entry_occurrence_id' => $occurrence->id])->save();
            $occurrence->forceFill(['converted_transaction_id' => $transaction->id])->save();
            $occurrence->synchronizeSettlementState();

            return $transaction;
        }
        if (! ($data['confirm_new_payment'] ?? false)) {
            $this->fail('new_payment_confirmation');
        }

        return app(RecurringEntryPostingService::class)->post($occurrence->fresh(), $actor, ['amount' => $data['actual_amount'], 'transaction_date' => $data['actual_date'], 'account_uuid' => $data['account_uuid'] ?? null]);
    }

    /** @param array<string, mixed> $data */
    private function recordAllocated(RecurringEntryOccurrence $occurrence, User $actor, array $data): Transaction
    {
        $entry = $occurrence->recurringEntry;
        app(UserYearService::class)->ensureDateYearIsOpen($entry->user, $data['actual_date'], 'actual_date');
        if (! empty($data['transaction_uuid'])) {
            $transaction = Transaction::query()->where('user_id', $actor->id)->where('uuid', $data['transaction_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless(app(AccessibleAccountsQuery::class)->canEditAccountId($actor, (int) $transaction->account_id), 403);
            if ((float) $transaction->amount !== (float) $data['actual_amount'] || $transaction->transaction_date->toDateString() !== $data['actual_date']) {
                $this->fail('protected');
            }
        } else {
            if (! ($data['confirm_new_payment'] ?? false)) {
                $this->fail('new_payment_confirmation');
            }
            $account = app(AccessibleAccountsQuery::class)->editable($actor)
                ->where('accounts.uuid', $data['account_uuid'] ?? ($occurrence->account ?? $entry->account)->uuid)
                ->where('currency', $entry->currency)->with('user:id,base_currency_code')->first();
            if ($account === null) {
                $this->fail('account_change');
            }
            $transaction = Transaction::query()->create([
                'user_id' => $entry->user_id, 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id,
                'account_id' => $account->id, 'scope_id' => $entry->scope_id, 'category_id' => $entry->category_id,
                'tracked_item_id' => $entry->tracked_item_id, 'merchant_id' => $entry->merchant_id,
                'direction' => $entry->direction, 'currency' => $entry->currency, 'amount' => $data['actual_amount'],
                'transaction_date' => $data['actual_date'], 'value_date' => $data['actual_date'],
                'kind' => 'manual', 'source_type' => 'manual', 'status' => 'confirmed',
                'description' => $entry->title, 'notes' => $data['notes'] ?? null,
                ...app(TransactionExchangeSnapshotService::class)->buildForAccount($account, (float) $data['actual_amount'], $data['actual_date']),
            ]);
            app(TransactionMutationService::class)->recalculateAccount($account);
            app(TransactionMutationService::class)->reconcileProcessedCreditCardCyclesForTransactions([$transaction]);
        }
        if ($occurrence->status === RecurringOccurrenceStatusEnum::COMPLETED && $occurrence->remainingPaymentCents() > 0) {
            $occurrence->forceFill(['status' => RecurringOccurrenceStatusEnum::PENDING])->save();
        }
        app(AllocateOccurrencePaymentService::class)->recordPayment($occurrence, $transaction, $actor, (string) $data['actual_amount'], $data['payment_type'], isset($data['final_total_amount']) ? (string) $data['final_total_amount'] : null);

        return $transaction;
    }

    /** @return array<string, mixed> */
    public function snapshot(RecurringEntryOccurrence $occurrence): array
    {
        $transaction = $occurrence->converted_transaction_id === null ? null : Transaction::query()->with('account:id,name')->find($occurrence->converted_transaction_id);

        return ['transaction' => $transaction === null ? null : [...$transaction->getAttributes(), 'account_name' => $transaction->account?->name], 'account_name' => ($occurrence->account ?? $occurrence->recurringEntry->account)?->name, ...Arr::only($occurrence->attributesToArray(), ['expected_date', 'due_date', 'expected_amount', 'account_id', 'payment_actual_amount', 'payment_actual_date', 'payment_management_state', 'payment_estimated_date', 'payment_reminder_date', 'payment_resolved_at', 'converted_transaction_id', 'status'])];
    }

    /** @param array<string, mixed> $before */
    public function recordChange(RecurringEntryOccurrence $occurrence, User $actor, array $before, string $action): void
    {
        $after = $this->snapshot($occurrence->fresh());
        if ($before === $after) {
            return;
        }
        $occurrence->paymentEvents()->create([
            'request_id' => (string) Str::uuid(), 'actor_id' => $actor->id,
            'transaction_id' => $occurrence->converted_transaction_id,
            'reason' => $action === 'forecast' ? 'forecast_changed' : 'payment_recorded', 'action' => $action,
            'details' => ['before' => $before, 'after' => $after],
        ]);
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function timelineSnapshot(array $snapshot): array
    {
        $transaction = $snapshot['transaction'] ?? null;
        $active = ($transaction['status'] ?? null) === 'confirmed' && empty($transaction['payment_rectified_at']);

        return [
            'expected_amount' => isset($snapshot['expected_amount']) ? (float) $snapshot['expected_amount'] : null,
            'expected_date' => $snapshot['due_date'] ?? $snapshot['expected_date'] ?? null,
            'estimated_date' => $snapshot['payment_estimated_date'] ?? null,
            'reminder_date' => $snapshot['payment_reminder_date'] ?? null,
            'account' => $active ? ($transaction['account_name'] ?? null) : ($snapshot['account_name'] ?? null),
            'actual_amount' => $active ? (float) $transaction['amount'] : null,
            'actual_date' => $active ? $transaction['transaction_date'] : null,
        ];
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['payment' => __('payment_management.'.$key)]);
    }
}
