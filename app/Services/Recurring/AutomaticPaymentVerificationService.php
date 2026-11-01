<?php

namespace App\Services\Recurring;

use App\Enums\TransactionKindEnum;
use App\Models\RecurringEntryOccurrence;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutomaticPaymentVerificationService
{
    public function confirm(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $occurrence = RecurringEntryOccurrence::withHistoricalPlan()->lockForUpdate()->findOrFail($transaction->recurring_entry_occurrence_id);
            $current = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            $this->lockedOccurrence($current);
            if (($occurrence->due_date ?? $occurrence->expected_date)->isFuture()) {
                throw ValidationException::withMessages(['payment' => __('payment_management.future_confirmation')]);
            }
            if ($current->payment_verified_at !== null && $current->payment_verification_method === 'manual') {
                $remaining = $occurrence->remainingPaymentCents();
                $state = $occurrence->payment_management_state;
                if (($remaining === 0 && $occurrence->payment_resolved_at !== null && in_array($state, ['paid', 'corrected'], true))
                    || ($occurrence->payment_resolved_at === null && ($state === 'review' || ($remaining > 0 && $state === 'partial')))) {
                    return;
                }
            }
            app(ManageOccurrencePaymentService::class)->manage($occurrence, auth()->user() ?? $occurrence->recurringEntry->user, [
                'request_id' => (string) Str::uuid(), 'reason' => 'already_paid', 'action' => 'confirm',
            ]);
        });
    }

    public function reportProblem(Transaction $transaction, bool $confirmed = false): RecurringEntryOccurrence
    {
        if (! $confirmed) {
            throw ValidationException::withMessages(['payment' => __('payment_management.confirmation_required')]);
        }
        $occurrence = $this->lockedOccurrence($transaction);

        return app(ManageOccurrencePaymentService::class)->manage($occurrence, auth()->user() ?? $occurrence->recurringEntry->user, [
            'request_id' => (string) Str::uuid(), 'reason' => 'not_charged', 'action' => 'pending',
            'confirm_rectification' => true,
        ]);
    }

    public function confirmExpired(): int
    {
        return 0;
    }

    protected function lockedOccurrence(Transaction $transaction): RecurringEntryOccurrence
    {
        $occurrence = RecurringEntryOccurrence::withHistoricalPlan()->lockForUpdate()
            ->find($transaction->recurring_entry_occurrence_id);
        if ($occurrence === null
            || $occurrence->converted_transaction_id !== $transaction->id
            || $occurrence->matched_transaction_id !== null
            || $transaction->payment_rectified_at !== null
            || $transaction->status?->value !== 'confirmed'
            || $transaction->kind !== TransactionKindEnum::SCHEDULED
            || $transaction->payment_verification_started_at === null) {
            throw ValidationException::withMessages(['payment' => __('ui.this_transaction_is_not_an_automatic_posting_that_can_be_verifie_b94a80ff')]);
        }

        return $occurrence;
    }
}
