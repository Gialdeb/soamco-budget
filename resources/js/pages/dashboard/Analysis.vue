<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    CircleCheck,
    ExternalLink,
    Settings2,
    Clock3,
    Landmark,
    Sparkles,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { confirm as confirmPayment } from '@/actions/App/Http/Controllers/AutomaticPaymentVerificationController';
import { convert } from '@/actions/App/Http/Controllers/RecurringEntryOccurrenceController';
import BudgetVsActual from '@/components/dashboard/BudgetVsActual.vue';
import CategoryTargets from '@/components/dashboard/CategoryTargets.vue';
import DashboardPager from '@/components/dashboard/DashboardPager.vue';
import EconomicCommitmentCard from '@/components/dashboard/EconomicCommitmentCard.vue';
import FinancialAgenda from '@/components/dashboard/FinancialAgenda.vue';
import FinancialTimeline from '@/components/dashboard/FinancialTimeline.vue';
import ForecastExpensesPanel from '@/components/dashboard/ForecastExpensesPanel.vue';
import LegacyOverviewCards from '@/components/dashboard/LegacyOverviewCards.vue';
import ManagePaymentDialog from '@/components/dashboard/ManagePaymentDialog.vue';
import MonthDetailSheet from '@/components/dashboard/MonthDetailSheet.vue';
import MonthlyRecapPanel from '@/components/dashboard/MonthlyRecapPanel.vue';
import SpendingCapacityCard from '@/components/dashboard/SpendingCapacityCard.vue';
import ReferenceAvatar from '@/components/ReferenceAvatar.vue';
import SensitiveValue from '@/components/SensitiveValue.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useForm } from '@/composables/useNotifiedForm';
import { useUiCopy } from '@/i18n/ui-copy';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCurrency } from '@/lib/currency';
import { buildDashboardMonthCallout } from '@/lib/dashboard-month-callout';
import { dashboard as dashboardRoute } from '@/routes';
import type {
    BreadcrumbItem,
    DashboardData,
    DashboardPageProps,
} from '@/types';
import type { DashboardPendingActionItem } from '@/types/dashboard';

const ui = useUiCopy();

const props = defineProps<DashboardPageProps>();
const upcomingWidget = ref<HTMLElement | null>(null);
const upcomingWidgetHeight = ref<string>();
let upcomingWidgetObserver: ResizeObserver | undefined;

onMounted(() => {
    upcomingWidgetObserver = new ResizeObserver(() => {
        if (upcomingWidget.value) {
            upcomingWidgetHeight.value = `${upcomingWidget.value.getBoundingClientRect().height}px`;
        }
    });

    if (upcomingWidget.value) {
        upcomingWidgetObserver.observe(upcomingWidget.value);
    }
});
onBeforeUnmount(() => upcomingWidgetObserver?.disconnect());

const manualPayment = ref<DashboardPendingActionItem | null>(null);
const manualOpen = ref(false);
const manualRegistration = useForm<Record<string, boolean>>({
    return_to_dashboard: true,
    confirm_payment: true,
});
const paymentVerification = useForm<Record<string, string>>({});
const managedPayment = ref<DashboardPendingActionItem | null>(null);
const managementOpen = ref(false);
function managePayment(item: DashboardPendingActionItem): void {
    managedPayment.value = item;
    managementOpen.value = true;
}
function verifyPayment(item: DashboardPendingActionItem): void {
    if (!item.transaction_uuid) {
        return;
    }

    paymentVerification.post(confirmPayment.url(item.transaction_uuid), {
        preserveScroll: true,
    });
}
function askManualPayment(item: DashboardPendingActionItem): void {
    manualRegistration.clearErrors();
    manualPayment.value = item;
    manualOpen.value = true;
}
function registerManualPayment(): void {
    const item = manualPayment.value;

    if (
        !item?.can_confirm_manual ||
        !item.recurring_entry_uuid ||
        !item.occurrence_uuid
    ) {
        return;
    }

    manualRegistration.post(
        convert.url([item.recurring_entry_uuid, item.occurrence_uuid]),
        {
            preserveScroll: true,
            onSuccess: () => {
                manualOpen.value = false;
            },
        },
    );
}
const page = usePage();
const { locale, t, te } = useI18n();
const currency = computed(
    () => props.dashboard.settings.base_currency || 'EUR',
);
const mode = ref<'expense' | 'income' | 'availability'>('expense');
const detailOpen = ref(false);
const isRefreshing = ref(false);
const includeForecast = ref(false);
const includeDebts = ref(false);
const includeCredits = ref(false);
const periodSelectorElement = ref<HTMLElement | null>(null);
const accountSelectorElement = ref<HTMLElement | null>(null);
const isAccountSelectorOutOfView = ref(false);
let accountSelectorObserver: IntersectionObserver | null = null;
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('dashboard.analysis.title'), href: dashboardRoute() },
];
const selectedPoint = computed(
    () =>
        props.dashboard.analysis.timeline.find((point) => point.is_selected) ??
        props.dashboard.analysis.timeline[0],
);
const currentPoint = computed(() =>
    props.dashboard.analysis.timeline.find((point) => point.is_current),
);
const selectedMonthCallout = computed(() => {
    const point = selectedPoint.value;
    const callout = buildDashboardMonthCallout({
        selected: point,
        current: currentPoint.value,
        currentPeriod: currentPoint.value
            ? { year: currentPoint.value.year, month: currentPoint.value.month }
            : null,
    });
    const comparisonMonth = currentPoint.value
        ? monthName(currentPoint.value.month, currentPoint.value.year)
        : '';
    const key =
        callout.direction === 'unavailable'
            ? 'unavailable'
            : callout.period_relation === 'current'
              ? 'current'
              : `${callout.period_relation}${callout.direction[0].toUpperCase()}${callout.direction.slice(1)}`;

    return {
        ...callout,
        tone: callout.severity,
        title: t(`dashboard.analysis.monthCallout.${key}`, {
            month: monthName(point.month, point.year),
            comparisonMonth,
            amount: money(Math.abs(callout.difference_amount ?? 0)),
            selectedAmount: money(callout.selected_amount ?? 0),
            percentage:
                callout.difference_percentage === null
                    ? ''
                    : ` (${formatSignedPercentage(callout.difference_percentage)})`,
        }),
    };
});
const categoryTotal = computed(() =>
    props.dashboard.expense_by_category.reduce(
        (sum, category) => sum + category.total_amount_raw,
        0,
    ),
);
const accountExpenseTotal = computed(() =>
    props.dashboard.accounts_summary.reduce(
        (sum, account) => sum + account.expense_total_raw,
        0,
    ),
);
const periodLabel = computed(() =>
    new Intl.DateTimeFormat(locale.value, {
        month: 'long',
        year: 'numeric',
    }).format(
        new Date(
            props.dashboard.filters.year,
            (props.dashboard.filters.month ?? 1) - 1,
            1,
        ),
    ),
);
const monthValue = computed(() => String(props.dashboard.filters.month ?? 1));
const yearValue = computed(() => String(props.dashboard.filters.year));
const applicationTimezone = computed(
    () =>
        page.props.app?.timezone ??
        Intl.DateTimeFormat().resolvedOptions().timeZone,
);
const currentCalendarPeriod = computed(() => {
    const parts = new Intl.DateTimeFormat('en', {
        timeZone: applicationTimezone.value,
        year: 'numeric',
        month: 'numeric',
    }).formatToParts(new Date());

    return {
        year: Number(parts.find((part) => part.type === 'year')?.value),
        month: Number(parts.find((part) => part.type === 'month')?.value),
    };
});
const todayLabel = computed(() =>
    new Intl.DateTimeFormat(locale.value, {
        timeZone: applicationTimezone.value,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date()),
);
const selectedYearStatus = computed(() => {
    const selectedYear = props.dashboard.filters.year;
    const currentYear = currentCalendarPeriod.value.year;

    if (selectedYear === currentYear) {
        return { key: 'current', tone: 'bg-emerald-500' };
    }

    return selectedYear < currentYear
        ? { key: 'past', tone: 'bg-destructive' }
        : { key: 'future', tone: 'bg-amber-500' };
});
const accountContextLabel = computed(() => {
    if (!props.dashboard.filters.account_uuid) {
        return t('dashboard.analysis.allAccounts');
    }

    return (
        props.dashboard.filters.account_options.find(
            (account) => account.value === props.dashboard.filters.account_uuid,
        )?.label ?? t('dashboard.analysis.selectedScope')
    );
});
const compactMetrics = computed(() => [
    {
        key: 'balance',
        label: t('dashboard.metrics.currentBalance'),
        value: props.dashboard.analysis.period.current_balance_raw,
        tone: 'default',
    },
    {
        key: 'month',
        label: t('dashboard.analysis.selectedMonth'),
        value: props.dashboard.analysis.period.expenses_raw,
        tone: 'default',
    },
    {
        key: 'net',
        label: t('dashboard.analysis.netFlow'),
        value: props.dashboard.analysis.period.net_raw,
        tone:
            props.dashboard.analysis.period.net_raw >= 0
                ? 'positive'
                : 'negative',
    },
    {
        key: 'income',
        label: t('dashboard.analysis.monthIncome'),
        value: props.dashboard.analysis.period.income_raw,
        tone: 'positive',
    },
]);

function pendingEventStatus(
    item: DashboardPendingActionItem,
):
    | 'Annullato'
    | 'Da verificare'
    | 'Parzialmente pagato'
    | 'In attesa'
    | 'In attesa di conferma'
    | 'Pagato'
    | 'Previsto'
    | 'Scaduto' {
    const payment = item.payment_management;

    if (payment?.state === 'cancelled') {
        return 'Annullato';
    }

    if (payment?.state === 'review') {
        return 'Da verificare';
    }

    if (
        ((payment?.paid_amount ?? 0) > 0 &&
            (payment?.remaining_amount ?? 0) > 0) ||
        (payment?.state === 'partial' && payment.remaining_amount !== 0)
    ) {
        return 'Parzialmente pagato';
    }

    if (item.requires_payment_confirmation || payment?.state === 'to_verify') {
        return 'In attesa di conferma';
    }

    if (payment?.state === 'processing') {
        return 'In attesa';
    }

    if (
        (payment?.state === 'paid' || payment?.state === 'corrected') &&
        !(payment.remaining_amount && payment.remaining_amount > 0)
    ) {
        return 'Pagato';
    }

    if (item.is_future || item.status_key === 'upcoming') {
        return 'Previsto';
    }

    return item.status_key === 'overdue' ? 'Scaduto' : 'In attesa';
}
function pendingEventStatusClass(item: DashboardPendingActionItem): string {
    switch (pendingEventStatus(item)) {
        case 'Pagato':
            return 'border-emerald-500/25 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200';
        case 'Parzialmente pagato':
            return 'border-amber-500/25 bg-amber-500/10 text-amber-800 dark:text-amber-200';
        case 'Scaduto':
            return 'border-destructive/25 bg-destructive/5 text-destructive';
        case 'Previsto':
            return 'border-blue-500/25 bg-blue-500/10 text-blue-700 dark:text-blue-200';
        case 'Annullato':
            return 'border-border bg-muted/50 text-muted-foreground';
        default:
            return 'border-primary/20 bg-primary/5 text-primary';
    }
}

function money(value: number): string {
    return formatCurrency(value, currency.value);
}
function formatSignedPercentage(value: number): string {
    const sign = value > 0 ? '+' : value < 0 ? '-' : '';

    return `${sign}${new Intl.NumberFormat(locale.value, {
        maximumFractionDigits: 1,
    }).format(Math.abs(value))}%`;
}
function percentage(value: number, total: number): number {
    return total > 0 ? Math.round((value / total) * 100) : 0;
}
function dateLabel(value: string): string {
    return new Intl.DateTimeFormat(locale.value, {
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${value}T12:00:00`));
}
function monthName(month: number, year: number): string {
    return new Intl.DateTimeFormat(locale.value, { month: 'long' }).format(
        new Date(year, month - 1, 1),
    );
}
function selectMonth(
    point: DashboardData['analysis']['timeline'][number],
): void {
    const query: Record<string, number | string> = {
        year: point.year,
        month: point.month,
        account_scope: props.dashboard.filters.account_scope,
    };

    if (props.dashboard.filters.account_uuid) {
        query.account_uuid = props.dashboard.filters.account_uuid;
    }

    router.get(
        dashboardRoute.url({ query }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['dashboard', 'transactionsNavigation'],
            onSuccess: () => {
                detailOpen.value = false;
            },
        },
    );
}
function navigatePeriod(year: number, month: number, openDetail = false): void {
    const normalized = new Date(year, month - 1, 1);
    const query: Record<string, number | string> = {
        year: normalized.getFullYear(),
        month: normalized.getMonth() + 1,
        account_scope: props.dashboard.filters.account_scope,
    };

    if (props.dashboard.filters.account_uuid) {
        query.account_uuid = props.dashboard.filters.account_uuid;
    }

    isRefreshing.value = true;
    router.get(
        dashboardRoute.url({ query }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['dashboard', 'transactionsNavigation'],
            onSuccess: () => {
                detailOpen.value = openDetail;
            },
            onFinish: () => {
                isRefreshing.value = false;
            },
        },
    );
}
function changeAccount(accountUuid: string | null): void {
    const query: Record<string, number | string> = {
        year: props.dashboard.filters.year,
        month: props.dashboard.filters.month ?? 1,
        account_scope: props.dashboard.filters.account_scope,
    };

    if (accountUuid) {
        query.account_uuid = accountUuid;
    }

    isRefreshing.value = true;
    router.get(
        dashboardRoute.url({ query }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['dashboard', 'transactionsNavigation'],
            onFinish: () => {
                isRefreshing.value = false;
            },
        },
    );
}
function goToCurrentMonth(): void {
    navigatePeriod(
        currentCalendarPeriod.value.year,
        currentCalendarPeriod.value.month,
    );
}
function scrollToAccountSelector(): void {
    accountSelectorElement.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
    });
}
function scrollToPeriodSelector(): void {
    periodSelectorElement.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
    });
}
function insightParams(
    insight: DashboardData['analysis']['insights'][number],
): Record<string, string | number> {
    const month =
        typeof insight.params.month === 'number' &&
        typeof insight.params.year === 'number'
            ? new Intl.DateTimeFormat(locale.value, { month: 'long' }).format(
                  new Date(insight.params.year, insight.params.month - 1, 1),
              )
            : '';

    return {
        ...insight.params,
        amount: money(Number(insight.params.amount ?? 0)),
        month,
    };
}
function insightText(
    insight: DashboardData['analysis']['insights'][number],
    part: 'title' | 'description',
): string {
    const direction = Number(insight.params.direction ?? 0);
    const directionalPart =
        part === 'description' &&
        ['next_month_difference', 'spending_vs_average'].includes(insight.type)
            ? `${part}_${direction > 0 ? 'more' : 'less'}`
            : part;
    const key = `dashboard.analysis.insight.${insight.type}.${directionalPart}`;

    if (!te(key)) {
        return part === 'title'
            ? t('dashboard.analysis.attention')
            : t('dashboard.analysis.insightFallback');
    }

    return t(key, insightParams(insight));
}

onMounted(() => {
    if (!accountSelectorElement.value || !('IntersectionObserver' in window)) {
        return;
    }

    accountSelectorObserver = new IntersectionObserver(
        ([entry]) => {
            isAccountSelectorOutOfView.value = !entry.isIntersecting;
        },
        { threshold: 0.15 },
    );
    accountSelectorObserver.observe(accountSelectorElement.value);
});

onBeforeUnmount(() => accountSelectorObserver?.disconnect());
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('dashboard.analysis.title')" />
        <main class="flex w-full flex-col gap-4 py-4 sm:gap-5 sm:py-6">
            <header
                data-onboarding="dashboard.welcome"
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <h1
                        class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl"
                    >
                        {{ t('dashboard.analysis.title') }}
                    </h1>
                    <p class="mt-1 text-muted-foreground capitalize">
                        {{ periodLabel }}
                    </p>
                </div>
                <div
                    class="flex flex-col gap-1 text-sm text-muted-foreground sm:items-end"
                >
                    <span
                        class="inline-flex items-center gap-2 font-medium text-foreground"
                    >
                        <i
                            class="size-2 rounded-full"
                            :class="selectedYearStatus.tone"
                        />
                        {{
                            t(
                                `dashboard.analysis.yearStatus.${selectedYearStatus.key}`,
                            )
                        }}
                    </span>
                    <span class="capitalize">{{ todayLabel }}</span>
                </div>
            </header>

            <section
                ref="periodSelectorElement"
                data-onboarding="dashboard.period"
                class="rounded-2xl border bg-card p-3 shadow-sm sm:p-4"
            >
                <div
                    class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between"
                >
                    <div
                        class="flex items-center justify-center gap-2 sm:justify-start"
                    >
                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-full border hover:bg-muted"
                            :aria-label="t('dashboard.analysis.previousMonth')"
                            @click="
                                navigatePeriod(
                                    dashboard.filters.year,
                                    (dashboard.filters.month ?? 1) - 1,
                                )
                            "
                        >
                            <ChevronLeft class="size-4" />
                        </button>
                        <div
                            class="flex min-w-52 items-center justify-center gap-1 rounded-full bg-muted p-1"
                        >
                            <select
                                :value="monthValue"
                                class="h-9 border-0 bg-transparent text-sm font-semibold capitalize focus:ring-0"
                                @change="
                                    navigatePeriod(
                                        dashboard.filters.year,
                                        Number(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        ),
                                    )
                                "
                            >
                                <option
                                    v-for="option in dashboard.filters
                                        .month_options"
                                    :key="String(option.value)"
                                    :value="String(option.value)"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <select
                                :value="yearValue"
                                class="h-9 border-0 bg-transparent text-sm font-semibold focus:ring-0"
                                @change="
                                    navigatePeriod(
                                        Number(
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        ),
                                        dashboard.filters.month ?? 1,
                                    )
                                "
                            >
                                <option
                                    v-for="option in dashboard.filters
                                        .available_years"
                                    :key="option.value"
                                    :value="String(option.value)"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-full border hover:bg-muted"
                            :aria-label="t('dashboard.analysis.nextMonth')"
                            @click="
                                navigatePeriod(
                                    dashboard.filters.year,
                                    (dashboard.filters.month ?? 1) + 1,
                                )
                            "
                        >
                            <ChevronRight class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="hidden rounded-full px-3 py-2 text-xs font-semibold text-primary hover:bg-muted sm:block"
                            @click="goToCurrentMonth"
                        >
                            {{ t('dashboard.analysis.currentMonth') }}
                        </button>
                    </div>
                    <div
                        ref="accountSelectorElement"
                        data-onboarding="dashboard.accounts"
                        class="min-w-0"
                    >
                        <div
                            class="flex gap-2 overflow-x-auto pb-1 xl:justify-end"
                        >
                            <button
                                type="button"
                                class="shrink-0 rounded-full border px-3 py-2 text-xs font-semibold transition"
                                :class="
                                    !dashboard.filters.account_uuid
                                        ? 'border-foreground bg-foreground text-background'
                                        : 'bg-background hover:bg-muted'
                                "
                                @click="changeAccount(null)"
                            >
                                {{ t('dashboard.analysis.allAccounts') }}
                            </button>
                            <button
                                v-for="account in dashboard.filters
                                    .account_options"
                                :key="account.value"
                                type="button"
                                class="shrink-0 rounded-full border px-3 py-2 text-xs font-semibold transition"
                                :class="
                                    dashboard.filters.account_uuid ===
                                    account.value
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-background hover:bg-muted'
                                "
                                @click="changeAccount(account.value)"
                            >
                                {{ account.label }}
                            </button>
                        </div>
                        <p
                            class="mt-1 text-right text-xs text-muted-foreground"
                        >
                            <span>{{
                                t('dashboard.analysis.aggregateBalance')
                            }}</span>
                            ·
                            <SensitiveValue
                                class="font-semibold text-foreground"
                                :value="
                                    money(
                                        dashboard.overview
                                            .current_balance_total_raw,
                                    )
                                "
                            />
                        </p>
                    </div>
                </div>
            </section>

            <div
                v-if="isAccountSelectorOutOfView"
                class="pointer-events-none fixed inset-x-3 top-3 z-30 flex items-center justify-between gap-2 md:hidden"
            >
                <button
                    type="button"
                    class="pointer-events-auto inline-flex max-w-[calc(50%-0.25rem)] min-w-0 items-center gap-2 rounded-full border bg-background/95 px-3 py-2 text-xs font-semibold text-foreground shadow-md backdrop-blur transition hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    :aria-label="periodLabel"
                    @click="scrollToPeriodSelector"
                >
                    <CalendarDays class="size-3.5 shrink-0 text-primary" />
                    <span class="truncate capitalize">{{ periodLabel }}</span>
                </button>
                <button
                    type="button"
                    class="pointer-events-auto inline-flex max-w-[calc(50%-0.25rem)] min-w-0 items-center gap-2 rounded-full border bg-background/95 px-3 py-2 text-xs font-semibold text-foreground shadow-md backdrop-blur transition hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                    :aria-label="
                        t('dashboard.analysis.returnToAccountSelector')
                    "
                    @click="scrollToAccountSelector"
                >
                    <i class="size-2 shrink-0 rounded-full bg-primary" />
                    <span class="truncate">{{ accountContextLabel }}</span>
                </button>
            </div>

            <button
                v-if="isAccountSelectorOutOfView"
                type="button"
                class="fixed top-5 right-5 z-30 hidden max-w-[min(24rem,calc(100vw-2.5rem))] items-center gap-2 rounded-full border bg-background/95 px-3 py-2 text-xs font-semibold text-foreground shadow-md backdrop-blur transition hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none md:inline-flex"
                :aria-label="t('dashboard.analysis.returnToAccountSelector')"
                @click="scrollToAccountSelector"
            >
                <i class="size-2 shrink-0 rounded-full bg-primary" />
                <span class="truncate">{{ accountContextLabel }}</span>
            </button>

            <section
                :class="isRefreshing ? 'pointer-events-none opacity-55' : ''"
                class="grid gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(20rem,0.78fr)]"
            >
                <div
                    class="min-w-0 rounded-2xl border bg-card p-5 shadow-sm sm:p-6"
                >
                    <FinancialTimeline
                        data-onboarding="dashboard.timeline"
                        v-model:mode="mode"
                        :points="dashboard.analysis.timeline"
                        :currency="currency"
                        :include-forecast="includeForecast"
                        :include-debts="includeDebts"
                        :include-credits="includeCredits"
                        @select="selectMonth"
                    >
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center gap-3 rounded-xl border p-3.5 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none sm:p-4"
                            :class="
                                selectedMonthCallout.tone === 'warning'
                                    ? 'border-destructive/20 bg-destructive/5 text-destructive hover:border-destructive/35 hover:bg-destructive/10'
                                    : selectedMonthCallout.tone === 'positive'
                                      ? 'border-[var(--dashboard-emerald,var(--chart-2))]/20 bg-[var(--dashboard-emerald-soft,var(--muted))] text-[var(--dashboard-emerald,var(--chart-2))] hover:border-[var(--dashboard-emerald,var(--chart-2))]/35'
                                      : 'border-primary/15 bg-primary/5 text-primary hover:border-primary/30 hover:bg-primary/8'
                            "
                            @click="detailOpen = true"
                        >
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-full"
                                :class="
                                    selectedMonthCallout.tone === 'warning'
                                        ? 'bg-destructive/10'
                                        : selectedMonthCallout.tone ===
                                            'positive'
                                          ? 'bg-[var(--dashboard-emerald,var(--chart-2))]/10'
                                          : 'bg-primary/10'
                                "
                            >
                                <Sparkles class="size-4.5" />
                            </span>
                            <span
                                class="min-w-0 flex-1 text-sm leading-5 font-semibold sm:text-base"
                            >
                                {{ selectedMonthCallout.title }}
                            </span>
                            <ChevronRight class="size-5 shrink-0" />
                        </button>
                    </FinancialTimeline>
                </div>
                <SpendingCapacityCard
                    data-onboarding="dashboard.capacity"
                    :capacity="dashboard.analysis.spending_capacity"
                    :currency="currency"
                    v-model:include-forecast="includeForecast"
                    v-model:include-debts="includeDebts"
                    v-model:include-credits="includeCredits"
                />
            </section>

            <section
                data-onboarding="dashboard.metrics"
                class="grid grid-cols-2 divide-x overflow-hidden rounded-2xl border bg-card shadow-sm sm:grid-cols-4"
            >
                <div
                    v-for="metric in compactMetrics"
                    :key="metric.key"
                    class="min-w-0 px-4 py-4 sm:px-5"
                >
                    <p
                        class="truncate text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                    >
                        {{ metric.label }}
                    </p>
                    <SensitiveValue
                        class="mt-1 block truncate text-lg font-semibold sm:text-xl"
                        :class="
                            metric.tone === 'positive'
                                ? 'text-[var(--dashboard-emerald,var(--chart-2))]'
                                : metric.tone === 'negative'
                                  ? 'text-destructive'
                                  : ''
                        "
                        :value="money(metric.value)"
                    />
                </div>
            </section>

            <LegacyOverviewCards
                :overview="dashboard.overview"
                :pending-actions="dashboard.pending_actions"
                :currency="currency"
            />

            <MonthlyRecapPanel :recap="dashboard.monthly_recap" />

            <section
                class="grid gap-4 xl:grid-cols-[minmax(20rem,0.88fr)_minmax(0,1.12fr)]"
            >
                <article
                    class="rounded-2xl border bg-card p-5 shadow-sm sm:p-6"
                >
                    <h2 class="text-lg font-semibold">
                        {{ t('dashboard.analysis.howWeGotHere') }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('dashboard.analysis.howWeGotHereDescription') }}
                    </p>
                    <dl
                        class="mt-5 overflow-hidden rounded-xl border bg-muted/25"
                    >
                        <div
                            class="flex items-center justify-between px-4 py-3"
                        >
                            <dt>{{ t('dashboard.analysis.monthlyBudget') }}</dt>
                            <dd class="font-semibold">
                                <SensitiveValue
                                    :value="
                                        money(
                                            dashboard.analysis.spending_capacity
                                                .budget_total_raw,
                                        )
                                    "
                                />
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between border-t px-4 py-3"
                        >
                            <dt>
                                {{ t('dashboard.analysis.actualExpenses') }}
                            </dt>
                            <dd class="font-semibold text-destructive">
                                <SensitiveValue
                                    :value="
                                        money(
                                            -dashboard.analysis
                                                .spending_capacity.expenses_raw,
                                        )
                                    "
                                />
                            </dd>
                        </div>
                        <div
                            class="flex items-center justify-between border-t px-4 py-3 font-semibold"
                        >
                            <dt>
                                {{
                                    dashboard.analysis.spending_capacity
                                        .is_budget_exceeded
                                        ? t('dashboard.analysis.budgetExceeded')
                                        : t('dashboard.metrics.remainingBudget')
                                }}
                            </dt>
                            <dd
                                :class="
                                    dashboard.analysis.spending_capacity
                                        .is_budget_exceeded
                                        ? 'text-destructive'
                                        : 'text-[var(--dashboard-emerald,var(--chart-2))]'
                                "
                            >
                                <SensitiveValue
                                    :value="
                                        money(
                                            dashboard.analysis.spending_capacity
                                                .remaining_budget_raw,
                                        )
                                    "
                                />
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6">
                        <h3
                            class="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                        >
                            {{ t('dashboard.analysis.whereYouSpent') }}
                        </h3>
                        <div class="mt-3 grid gap-3">
                            <div
                                v-for="category in dashboard.expense_by_category.slice(
                                    0,
                                    5,
                                )"
                                :key="String(category.category_id)"
                                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 text-sm"
                            >
                                <span class="truncate font-medium">{{
                                    category.category_name
                                }}</span
                                ><SensitiveValue
                                    class="font-semibold"
                                    :value="money(category.total_amount_raw)"
                                />
                                <span
                                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                                    ><span
                                        class="block h-full rounded-full bg-[var(--dashboard-blue,var(--chart-1))]"
                                        :style="{
                                            width: `${percentage(category.total_amount_raw, categoryTotal)}%`,
                                        }" /></span
                                ><span class="text-xs text-muted-foreground"
                                    >{{
                                        percentage(
                                            category.total_amount_raw,
                                            categoryTotal,
                                        )
                                    }}%</span
                                >
                            </div>
                            <p
                                v-if="
                                    dashboard.expense_by_category.length === 0
                                "
                                class="text-sm text-muted-foreground"
                            >
                                {{ t('dashboard.expenseBreakdown.empty') }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="dashboard.accounts_summary.length > 1"
                        class="mt-6"
                    >
                        <h3
                            class="text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                        >
                            {{ t('dashboard.analysis.byAccount') }}
                        </h3>
                        <div class="mt-3 grid gap-3">
                            <div
                                v-for="account in dashboard.accounts_summary"
                                :key="account.account_id"
                                class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 text-sm"
                            >
                                <span class="truncate font-medium">{{
                                    account.account_name
                                }}</span
                                ><SensitiveValue
                                    class="font-semibold"
                                    :value="money(account.expense_total_raw)"
                                /><span
                                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                                    ><span
                                        class="block h-full rounded-full bg-[var(--dashboard-emerald,var(--chart-2))]"
                                        :style="{
                                            width: `${percentage(account.expense_total_raw, accountExpenseTotal)}%`,
                                        }" /></span
                                ><span class="text-xs text-muted-foreground"
                                    >{{
                                        percentage(
                                            account.expense_total_raw,
                                            accountExpenseTotal,
                                        )
                                    }}%</span
                                >
                            </div>
                        </div>
                    </div>
                </article>

                <div class="grid gap-4">
                    <EconomicCommitmentCard
                        :commitment="dashboard.analysis.economic_commitment"
                        :currency="currency"
                        :period-label="periodLabel"
                    />
                    <ForecastExpensesPanel
                        :points="dashboard.analysis.timeline"
                        :currency="currency"
                        @select="selectMonth"
                    />
                    <section class="grid gap-3">
                        <h2
                            class="px-1 text-xs font-semibold tracking-[0.12em] text-muted-foreground uppercase"
                        >
                            {{ t('dashboard.analysis.insights') }}
                        </h2>
                        <article
                            v-for="insight in dashboard.analysis.insights.slice(
                                0,
                                3,
                            )"
                            :key="insight.type"
                            class="flex gap-4 rounded-2xl border p-4"
                            :class="
                                insight.severity === 'critical' ||
                                insight.severity === 'warning'
                                    ? 'border-destructive/20 bg-destructive/5'
                                    : insight.severity === 'positive'
                                      ? 'border-[var(--dashboard-emerald,var(--chart-2))]/20 bg-[var(--dashboard-emerald-soft,var(--muted))]'
                                      : 'bg-card'
                            "
                        >
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-full bg-background"
                                ><CircleAlert
                                    v-if="
                                        insight.severity === 'critical' ||
                                        insight.severity === 'warning'
                                    "
                                    class="size-5 text-destructive" /><ArrowDownRight
                                    v-else
                                    class="size-5 text-[var(--dashboard-emerald,var(--chart-2))]"
                            /></span>
                            <div>
                                <h3 class="font-semibold">
                                    {{ insightText(insight, 'title') }}
                                </h3>
                                <p
                                    class="mt-1 text-sm leading-relaxed text-muted-foreground"
                                >
                                    {{ insightText(insight, 'description') }}
                                </p>
                            </div>
                        </article>
                        <div
                            v-if="
                                dashboard.analysis.credits_debts
                                    .open_credits_raw > 0 ||
                                dashboard.analysis.credits_debts
                                    .open_debts_raw > 0
                            "
                            class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-dashed px-4 py-3 text-sm"
                        >
                            <Landmark
                                class="size-4 text-muted-foreground"
                            /><span
                                >{{ t('dashboard.metrics.openCredits') }}
                                <SensitiveValue
                                    class="font-semibold"
                                    :value="
                                        money(
                                            dashboard.analysis.credits_debts
                                                .open_credits_raw,
                                        )
                                    " /></span
                            ><span
                                >{{ t('dashboard.metrics.openDebts') }}
                                <SensitiveValue
                                    class="font-semibold"
                                    :value="
                                        money(
                                            dashboard.analysis.credits_debts
                                                .open_debts_raw,
                                        )
                                    "
                            /></span>
                        </div>
                    </section>
                </div>
            </section>

            <div
                class="grid min-w-0 grid-cols-1 items-start gap-4 lg:grid-cols-2"
                :style="{ '--dashboard-upcoming-height': upcomingWidgetHeight }"
            >
                <section
                    v-if="dashboard.credits_debts.arrears?.length"
                    class="flex min-h-0 min-w-0 flex-col rounded-2xl border border-destructive/20 bg-card p-5 shadow-sm sm:p-6 lg:h-[var(--dashboard-upcoming-height)]"
                >
                    <h2 class="text-lg font-semibold">
                        {{ t('creditsDebts.arrears') }}
                    </h2>
                    <DashboardPager
                        v-slot="{ item }"
                        :items="dashboard.credits_debts.arrears"
                        fill
                        :label="t('creditsDebts.arrears')"
                        class="mt-3 min-h-0 lg:flex-1"
                    >
                        <Link
                            :key="item.uuid"
                            :href="item.source_url"
                            class="flex min-w-0 items-center justify-between gap-3 rounded-xl border bg-muted/25 p-3 transition-colors hover:bg-muted/50"
                        >
                            <ReferenceAvatar
                                :name="item.title"
                                :logo-url="item.logo_url"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium break-words">
                                    {{ item.title }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t('creditsDebts.overdueSince', {
                                            period: new Intl.DateTimeFormat(
                                                locale,
                                                {
                                                    month: 'long',
                                                    year: 'numeric',
                                                },
                                            ).format(
                                                new Date(
                                                    `${item.due_date}T00:00:00`,
                                                ),
                                            ),
                                        })
                                    }}
                                </p>
                            </div>
                            <div class="shrink-0 text-right text-sm">
                                <SensitiveValue
                                    class="font-semibold"
                                    :value="
                                        formatCurrency(
                                            Number(item.amount),
                                            item.currency,
                                        )
                                    "
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            item.type === 'credit'
                                                ? 'creditsDebts.receive'
                                                : 'creditsDebts.pay',
                                        )
                                    }}
                                </p>
                            </div>
                        </Link>
                    </DashboardPager>
                </section>

                <section
                    ref="upcomingWidget"
                    data-onboarding="dashboard.pending"
                    class="min-w-0 rounded-2xl border bg-card p-5 shadow-sm sm:p-6"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold">
                                {{ t('dashboard.analysis.upcomingEvents') }}
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    t(
                                        'dashboard.analysis.upcomingEventsDescription',
                                    )
                                }}
                            </p>
                        </div>
                        <CalendarDays
                            class="size-5 shrink-0 text-muted-foreground"
                        />
                    </div>
                    <DashboardPager
                        v-if="dashboard.upcoming_events.items.length"
                        v-slot="{ item }"
                        :items="dashboard.upcoming_events.items"
                        stable-layout
                        compact-controls
                        :label="t('dashboard.analysis.upcomingEvents')"
                        class="mt-4"
                    >
                        <article
                            :key="item.id"
                            class="flex min-w-0 flex-col gap-2 rounded-xl border bg-muted/25 p-3"
                        >
                            <div class="flex min-w-0 items-center gap-1.5">
                                <ReferenceAvatar
                                    :name="item.title"
                                    :logo-url="item.logo_url"
                                    class="size-9 shrink-0 rounded-lg"
                                />
                                <Link
                                    :href="item.action_url"
                                    class="block min-w-0 flex-1 rounded-lg focus-visible:outline-2 focus-visible:outline-ring"
                                >
                                    <div
                                        class="grid min-w-0 grid-cols-1 items-center gap-1.5 sm:grid-cols-[minmax(0,1fr)_auto]"
                                    >
                                        <h3
                                            :title="item.title"
                                            class="line-clamp-2 min-w-0 text-sm leading-snug font-semibold [overflow-wrap:anywhere]"
                                        >
                                            {{ item.title }}
                                        </h3>
                                        <SensitiveValue
                                            class="shrink-0 text-sm font-semibold whitespace-nowrap tabular-nums"
                                            :value="
                                                item.requires_payment_confirmation
                                                    ? formatCurrency(
                                                          (item
                                                              .payment_management
                                                              ?.remaining_amount ??
                                                              0) > 0
                                                              ? (item
                                                                    .payment_management
                                                                    ?.remaining_amount ??
                                                                    0)
                                                              : item.amount_raw,
                                                          item.currency ??
                                                              currency,
                                                      )
                                                    : money(item.amount_raw)
                                            "
                                        />
                                    </div>
                                </Link>
                            </div>
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted-foreground"
                            >
                                <time
                                    :datetime="item.date"
                                    class="inline-flex items-center gap-1"
                                    ><CalendarDays
                                        class="size-3.5 shrink-0"
                                    />{{ dateLabel(item.date) }}</time
                                >
                                <span aria-hidden="true">·</span>
                                <span>{{
                                    item.recurrence_mode
                                        ? ui(
                                              item.recurrence_mode ===
                                                  'automatic'
                                                  ? 'Ricorrenza automatica'
                                                  : 'Ricorrenza manuale',
                                          )
                                        : t(
                                              `dashboard.agenda.entryKinds.${item.entry_kind}`,
                                          )
                                }}</span>
                                <Badge
                                    variant="outline"
                                    class="max-w-full gap-1 rounded-full px-2 py-0.5 text-[11px] leading-snug font-medium whitespace-normal"
                                    :class="pendingEventStatusClass(item)"
                                >
                                    <CircleAlert
                                        v-if="
                                            pendingEventStatus(item) ===
                                            'Scaduto'
                                        "
                                        class="size-3 shrink-0"
                                    />
                                    <CircleCheck
                                        v-else-if="
                                            pendingEventStatus(item) ===
                                            'Pagato'
                                        "
                                        class="size-3 shrink-0"
                                    />
                                    <Clock3 v-else class="size-3 shrink-0" />
                                    {{ ui(pendingEventStatus(item)) }}
                                </Badge>
                                <p
                                    v-if="item.requires_payment_confirmation"
                                    class="basis-full text-xs text-muted-foreground"
                                >
                                    {{
                                        ui(
                                            (item.payment_management
                                                ?.remaining_amount ?? 0) > 0
                                                ? 'Addebito già contabilizzato da confermare. L’importo mostrato è il solo residuo da pagare.'
                                                : 'Addebito già contabilizzato: conferma l’esito senza effettuare un nuovo pagamento.',
                                        )
                                    }}
                                </p>
                                <p
                                    v-else-if="
                                        (item.payment_management?.paid_amount ??
                                            0) > 0 &&
                                        (item.payment_management
                                            ?.remaining_amount ?? 0) > 0
                                    "
                                    class="basis-full text-xs text-muted-foreground"
                                >
                                    {{ ui('Importo residuo ancora dovuto.') }}
                                </p>
                                <p
                                    v-if="
                                        !item.is_future &&
                                        item.automatic_payment_missing
                                    "
                                    class="basis-full text-xs text-muted-foreground"
                                >
                                    {{ ui('Movimento automatico mancante') }}
                                </p>
                            </div>
                            <div
                                class="mt-auto grid min-w-0 grid-cols-1 gap-2 empty:hidden sm:grid-cols-2"
                            >
                                <template v-if="!item.is_future">
                                    <Button
                                        v-if="
                                            item.payment_management &&
                                            item.can_manage
                                        "
                                        size="sm"
                                        class="h-auto min-h-11 w-full min-w-0 justify-center gap-1 rounded-md px-2 py-1 text-xs leading-snug whitespace-normal"
                                        variant="outline"
                                        @click="managePayment(item)"
                                        ><Settings2 class="size-4 shrink-0" />{{
                                            ui('Gestisci pagamento')
                                        }}</Button
                                    >
                                </template>
                                <Link
                                    v-if="item.plan_url"
                                    :href="item.plan_url"
                                    class="inline-flex h-auto min-h-11 w-full min-w-0 items-center justify-center gap-1 rounded-md border border-blue-500/20 bg-blue-500/5 px-2 py-1 text-center text-xs leading-snug font-medium whitespace-normal text-blue-700 transition-colors hover:bg-blue-500/10 focus-visible:outline-2 focus-visible:outline-ring dark:text-blue-300"
                                    ><ExternalLink
                                        class="size-3.5 shrink-0"
                                    />{{ ui('Vai al piano') }}</Link
                                >
                                <template v-if="!item.is_future">
                                    <Button
                                        v-if="
                                            item.can_verify_payment &&
                                            item.transaction_uuid
                                        "
                                        size="sm"
                                        class="h-auto min-h-11 w-full min-w-0 justify-center gap-1 rounded-md px-2 py-1 text-xs leading-snug whitespace-normal"
                                        :disabled="
                                            paymentVerification.processing
                                        "
                                        @click="verifyPayment(item)"
                                        >{{ ui('Conferma') }}</Button
                                    >
                                    <Button
                                        v-else-if="item.can_confirm_manual"
                                        size="sm"
                                        class="h-auto min-h-11 w-full min-w-0 justify-center gap-1 rounded-md px-2 py-1 text-xs leading-snug whitespace-normal"
                                        :disabled="
                                            manualRegistration.processing
                                        "
                                        @click="askManualPayment(item)"
                                        >{{ ui('Conferma pagamento') }}</Button
                                    >
                                </template>
                                <p
                                    v-if="
                                        !item.is_future &&
                                        paymentVerification.errors.payment &&
                                        item.can_verify_payment
                                    "
                                    role="alert"
                                    class="text-xs text-destructive sm:col-span-2"
                                >
                                    {{ paymentVerification.errors.payment }}
                                </p>
                            </div>
                        </article>
                    </DashboardPager>
                    <p
                        v-else
                        class="mt-5 rounded-xl bg-muted/40 p-5 text-sm text-muted-foreground"
                    >
                        {{ t('dashboard.metrics.noPendingActions') }}
                    </p>
                </section>
            </div>
            <Dialog v-model:open="manualOpen">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{{
                            ui('Il pagamento è realmente avvenuto?')
                        }}</DialogTitle>
                        <DialogDescription>{{
                            ui(
                                'Il movimento verrà registrato con importo e data previsti. Per correggerli, usa Gestisci pagamento.',
                            )
                        }}</DialogDescription>
                    </DialogHeader>
                    <div
                        v-if="manualPayment"
                        class="rounded-xl border bg-muted/25 p-4"
                    >
                        <p class="font-medium">{{ manualPayment.title }}</p>
                        <SensitiveValue
                            class="mt-2 block font-semibold"
                            :value="money(manualPayment.amount_raw)"
                        />
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ dateLabel(manualPayment.date) }}
                        </p>
                    </div>
                    <p
                        v-if="
                            manualRegistration.errors.payment ||
                            manualRegistration.errors.occurrence
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{
                            manualRegistration.errors.payment ||
                            manualRegistration.errors.occurrence
                        }}
                    </p>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="manualRegistration.processing"
                            @click="manualOpen = false"
                            >{{ ui('Non ancora') }}</Button
                        >
                        <Button
                            :disabled="manualRegistration.processing"
                            @click="registerManualPayment"
                            >{{ ui('Sì, registra pagamento') }}</Button
                        >
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ManagePaymentDialog
                v-model:open="managementOpen"
                :item="managedPayment"
                :currency="currency"
            />

            <section
                class="grid gap-4 2xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.45fr)_minmax(20rem,0.95fr)]"
            >
                <BudgetVsActual
                    data-onboarding="dashboard.budget"
                    :items="dashboard.budget_vs_actual"
                    :currency="currency"
                />
                <CategoryTargets
                    :items="dashboard.parent_category_budget_status"
                    :currency="currency"
                />
                <FinancialAgenda
                    data-onboarding="dashboard.agenda"
                    :notifications="dashboard.notifications"
                    :recurring="dashboard.recurring_summary"
                    :scheduled="dashboard.scheduled_summary"
                    :merchants="dashboard.merchant_breakdown"
                    :currency="currency"
                />
            </section>
        </main>
        <MonthDetailSheet
            v-model:open="detailOpen"
            :detail="dashboard.analysis.month_detail"
            :insights="dashboard.analysis.insights"
            :currency="currency"
        />
    </AppLayout>
</template>
