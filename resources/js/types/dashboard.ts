import type { UserYearSuggestion } from './years';

export type DashboardOption<TValue = number | null> = {
    value: TValue;
    label: string;
};

export type DashboardAccountFilterOption = {
    value: string;
    label: string;
    bank_name: string | null;
    account_type_code: string | null;
    is_owned: boolean;
    is_shared: boolean;
    membership_role: string | null;
    membership_status: string | null;
    can_view: boolean;
    can_edit: boolean;
};

export type DashboardSettings = {
    active_year: number | null;
    base_currency: string;
    dashboard: Record<string, unknown>;
};

export type DashboardOverview = {
    income_total: string;
    income_total_raw: number;
    expense_total: string;
    expense_total_raw: number;
    net_total: string;
    net_total_raw: number;
    budget_total: string;
    budget_total_raw: number;
    current_balance_total: string;
    current_balance_total_raw: number;
    previous_balance_total: string;
    previous_balance_total_raw: number;
    actual_vs_budget_delta: string;
    actual_vs_budget_delta_raw: number;
    transactions_count: number;
    active_accounts_count: number;
    savings_rate: number;
    savings_mode: string;
};

export type DashboardTrendPoint = {
    label: number;
    income_total: string;
    income_total_raw: number;
    expense_total: string;
    expense_total_raw: number;
    net_total: string;
    net_total_raw: number;
};

export type DashboardCategoryBreakdownItem = {
    category_id: number | null;
    category_name: string;
    total_amount: string;
    total_amount_raw: number;
};

export type DashboardBudgetComparisonItem = {
    category_id: number | null;
    scope_id: number | null;
    category_name: string;
    scope_name: string;
    budget_total: string;
    budget_total_raw: number;
    actual_total: string;
    actual_total_raw: number;
    delta: string;
    delta_raw: number;
    percentage_used: number;
};

export type DashboardParentCategoryBudgetItem = {
    category_id: number;
    category_name: string;
    budget_total: string;
    budget_total_raw: number;
    actual_total: string;
    actual_total_raw: number;
    delta: string;
    delta_raw: number;
    percentage_used: number;
};

export type DashboardAccountSummaryItem = {
    account_id: number;
    account_name: string;
    bank_name: string | null;
    currency: string;
    opening_balance: string;
    opening_balance_raw: number;
    current_balance: string;
    current_balance_raw: number;
    income_total: string;
    income_total_raw: number;
    expense_total: string;
    expense_total_raw: number;
    net_total: string;
    net_total_raw: number;
    transactions_count: number;
};

export type DashboardRecurringSummary = {
    planned_count: number;
    due_count: number;
    matched_count: number;
    converted_count: number;
    cancelled_count: number;
    skipped_count: number;
    overdue_count: number;
    overdue_total: string;
    overdue_total_raw: number;
};

export type DashboardScheduledUpcomingItem = {
    logo_url?: string | null;
    source_url: string | null;
    id: string;
    display_label: string;
    scheduled_date: string;
    expected_amount: string;
    expected_amount_raw: number;
    status: string;
    entry_kind: 'recurring' | 'scheduled';
};

export type DashboardScheduledSummary = {
    planned_count: number;
    due_count: number;
    matched_count: number;
    converted_count: number;
    cancelled_count: number;
    upcoming: DashboardScheduledUpcomingItem[];
};

export type DashboardMerchantBreakdownItem = {
    logo_url?: string | null;
    merchant_id: number | null;
    merchant_name: string;
    display_label: string;
    total_amount: string;
    total_amount_raw: number;
    transactions_count: number;
};

export type DashboardNotificationSummary = {
    review_needed_count: number;
    overdue_recurring_count: number;
    overdue_recurring_total: string;
    overdue_recurring_total_raw: number;
    planned_scheduled_count: number;
    due_scheduled_count: number;
};

export type DashboardMonthlyRecapInsight = {
    type: string;
    tone: 'positive' | 'warning' | 'neutral';
    message: string;
};

export type DashboardMonthlyRecapCategory = {
    category_id: number | null;
    category_name: string;
    transactions_count: number;
    share: number;
    total_amount: string;
    total_amount_raw: number;
};

export type DashboardMonthlyRecapMovement = {
    date: string | null;
    description: string;
    direction: 'income' | 'expense';
    amount: string;
    amount_raw: number;
};

export type DashboardMonthlyRecap = {
    available: boolean;
    empty_reason: string | null;
    period: {
        year: number;
        month: number;
        key: string;
        label: string;
        starts_at: string;
        ends_at: string;
    };
    previous_period: {
        year: number;
        month: number;
        key: string;
        label: string;
    };
    currency: string;
    scope: {
        account_scope: string;
        account_uuid: string | null;
        accounts_count: number;
    };
    totals: {
        starting_balance_total: string;
        starting_balance_total_raw: number;
        ending_balance_total: string;
        ending_balance_total_raw: number;
        income_total: string;
        income_total_raw: number;
        expense_total: string;
        expense_total_raw: number;
        net_total: string;
        net_total_raw: number;
        transactions_count: number;
        net_vs_previous: string;
        net_vs_previous_raw: number;
        net_vs_previous_percentage: number | null;
        income_share: number;
        expense_share: number;
    };
    previous_totals: {
        starting_balance_total: string;
        starting_balance_total_raw: number;
        ending_balance_total: string;
        ending_balance_total_raw: number;
        income_total: string;
        income_total_raw: number;
        expense_total: string;
        expense_total_raw: number;
        net_total: string;
        net_total_raw: number;
        transactions_count: number;
    };
    top_expense_categories: DashboardMonthlyRecapCategory[];
    top_movements: DashboardMonthlyRecapMovement[];
    insights: DashboardMonthlyRecapInsight[];
};

export type PaymentManagement = {
    is_amount_variable?: boolean;
    total_due_amount?: number;
    paid_amount?: number;
    remaining_amount?: number;
    occurrence_uuid: string;
    entry_uuid: string;
    reference: string;
    expected_amount: number;
    expected_date: string;
    estimated_date: string | null;
    actual_amount: number | null;
    actual_date: string | null;
    state: string;
    has_automatic_transaction: boolean;
    can_rectify: boolean;
    accounts?: { uuid: string; name: string }[];
    history: {
        id: string | number;
        reason: string;
        action: string;
        created_at: string;
        notes: string | null;
        before?: PaymentTimelineSnapshot;
        after?: PaymentTimelineSnapshot;
        rectified_amount?: number | string | null;
    }[];
};

export type PaymentTimelineSnapshot = {
    expected_amount: number | null;
    expected_date: string | null;
    estimated_date: string | null;
    reminder_date: string | null;
    actual_amount: number | null;
    actual_date: string | null;
    account?: string | null;
};

export type DashboardPendingActionItem = {
    logo_url?: string | null;
    id: string;
    title: string;
    date: string;
    amount: string;
    amount_raw: number;
    status_key: 'upcoming' | 'today' | 'overdue' | 'to_record';
    action_url: string;
    recurring_entry_uuid?: string;
    occurrence_uuid?: string;
    requires_amount_confirmation?: boolean;
    requires_payment_confirmation?: boolean;
    payment_management?: PaymentManagement;
    can_manage?: boolean;
    can_verify_payment?: boolean;
    can_confirm_manual?: boolean;
    automatic_payment_missing?: boolean;
    is_future?: boolean;
    recurrence_mode?: 'automatic' | 'manual';
    transaction_uuid?: string;
    plan_title?: string;
    plan_url?: string;
    currency?: string;
    entry_kind: 'recurring' | 'scheduled';
};

export type DashboardPendingActions = {
    total_count: number;
    items: DashboardPendingActionItem[];
};

export type DashboardFilters = {
    year: number;
    month: number | null;
    available_years: DashboardOption<number>[];
    month_options: DashboardOption[];
    account_scope: string;
    account_uuid: string | null;
    show_account_scope_filter: boolean;
    account_scope_options: DashboardOption<string>[];
    account_options: DashboardAccountFilterOption[];
};

export type DashboardData = {
    filters: DashboardFilters;
    settings: DashboardSettings;
    overview: DashboardOverview;
    credits_debts: {
        arrears?: Array<{
            logo_url?: string | null;
            uuid: string;
            title: string;
            type: 'credit' | 'debit';
            amount: string;
            currency: string;
            due_date: string;
            source_url: string;
            origin: string;
        }>;
        credits_open_total: string;
        credits_open_total_raw: number;
        debts_open_total: string;
        debts_open_total_raw: number;
        net_expected_total: string;
        net_expected_total_raw: number;
        overdue_count: number;
    };
    analysis: {
        period: {
            current_balance_raw: number;
            income_raw: number;
            expenses_raw: number;
            net_raw: number;
        };
        spending_capacity: {
            available: boolean;
            source: 'budget';
            budget_total_raw: number;
            expenses_raw: number;
            remaining_budget_raw: number;
            spendable_amount_raw: number | null;
            is_budget_exceeded: boolean;
            included_components: {
                real_expenses: boolean;
                future_commitments: boolean;
                open_debts: boolean;
                open_credits: boolean;
            };
            simulation_impacts: {
                forecast_debts_overlap_raw?: number;
                forecast_expenses_raw: number;
                open_debts_raw: number;
                open_credits_raw: number;
            };
        };
        credits_debts: {
            open_credits_raw: number;
            open_debts_raw: number;
            net_expected_raw: number;
            overdue_count: number;
        };
        timeline: Array<{
            key: string;
            year: number;
            month: number;
            state: 'past' | 'current' | 'future';
            is_selected: boolean;
            is_current: boolean;
            actual_income_raw: number;
            actual_expense_raw: number;
            known_income_raw: number;
            known_expense_raw: number;
            projected_income_raw: number;
            projected_expense_raw: number;
            actual_net_raw: number;
            projected_net_flow_raw: number;
            availability_start_raw: number;
            availability_end_raw: number;
            open_credits_raw: number;
            open_debts_raw: number;
            composition: {
                actual_raw: number;
                recurring_raw: number;
                installments_raw: number;
                scheduled_raw: number;
            };
            income_composition: {
                actual_raw: number;
                recurring_raw: number;
                scheduled_raw: number;
            };
            weight: 'normal' | 'heavy' | 'light';
        }>;
        economic_commitment: {
            monthly_raw: number;
            budget_ratio: number | null;
            remaining_monthly_raw: number | null;
            months_count: number;
            composition: {
                recurring_raw: number;
                installments_raw: number;
                debts_raw: number;
            };
        };
        month_detail: {
            period: {
                key: string;
                year: number;
                month: number;
                state: 'past' | 'current' | 'future';
            };
            real: {
                income_raw: number;
                expense_raw: number;
                net_raw: number;
                starting_balance_raw: number;
                ending_balance_raw: number;
            };
            forecast: {
                income_raw: number;
                expense_raw: number;
                projected_net_flow_raw: number;
                composition: {
                    actual_raw: number;
                    recurring_raw: number;
                    installments_raw: number;
                    scheduled_raw: number;
                };
            };
            income_composition: {
                actual_raw: number;
                recurring_raw: number;
                scheduled_raw: number;
            };
            credits: { open_raw: number };
            debts: { open_raw: number };
            budget: { total_raw: number; remaining_raw: number };
            top_expense_categories: DashboardCategoryBreakdownItem[];
            top_income_categories: DashboardCategoryBreakdownItem[];
            narrative: {
                window: DashboardData['analysis']['timeline'];
                selected_expense_raw: number;
                comparison_average_raw: number | null;
                difference_from_average_raw: number | null;
                future_total_raw: number;
                heavy_future_months: number;
                lightest_future:
                    | DashboardData['analysis']['timeline'][number]
                    | null;
            };
        };
        insights: Array<{
            type: string;
            severity: 'positive' | 'neutral' | 'info' | 'warning' | 'critical';
            priority: number;
            params: Record<string, number | string>;
        }>;
    };
    monthly_recap: DashboardMonthlyRecap;
    pending_actions: DashboardPendingActions;
    upcoming_events: DashboardPendingActions;
    monthly_trend: DashboardTrendPoint[];
    expense_by_category: DashboardCategoryBreakdownItem[];
    budget_vs_actual: DashboardBudgetComparisonItem[];
    parent_category_budget_status: DashboardParentCategoryBudgetItem[];
    accounts_summary: DashboardAccountSummaryItem[];
    recurring_summary: DashboardRecurringSummary;
    scheduled_summary: DashboardScheduledSummary;
    income_by_category: DashboardCategoryBreakdownItem[];
    merchant_breakdown: DashboardMerchantBreakdownItem[];
    notifications: DashboardNotificationSummary;
    year_suggestion: UserYearSuggestion | null;
};

export type DashboardSupportPromptVariant =
    | 'first_support'
    | 'renew_support'
    | 'support_again';

export type DashboardSupportPrompt = {
    show_kofi_widget: boolean;
    support_prompt_variant: DashboardSupportPromptVariant | null;
    support_state: string;
    kofi_widget: {
        script_url: string;
        page_id: string;
        button_color: string;
    };
};

export type DashboardQuickStart = {
    show: boolean;
};

export type DashboardPageProps = {
    dashboard: DashboardData;
    support_prompt: DashboardSupportPrompt;
    quick_start: DashboardQuickStart;
};
