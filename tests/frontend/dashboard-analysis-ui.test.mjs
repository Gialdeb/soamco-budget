import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);

test('analysis dashboard exposes timeline modes partial month navigation and responsive detail', async () => {
    const [
        page,
        commitment,
        timeline,
        capacity,
        forecast,
        detail,
        budget,
        targets,
        agenda,
        overviewCards,
        monthlyRecap,
        monthDetailNarrative,
    ] = await Promise.all([
        readFile(
            new URL('resources/js/pages/dashboard/Analysis.vue', root),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/EconomicCommitmentCard.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/FinancialTimeline.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/SpendingCapacityCard.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/ForecastExpensesPanel.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/MonthDetailSheet.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/BudgetVsActual.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/CategoryTargets.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/FinancialAgenda.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/LegacyOverviewCards.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/MonthlyRecapPanel.vue',
                root,
            ),
            'utf8',
        ),
        readFile(
            new URL(
                'resources/js/components/dashboard/MonthDetailNarrative.vue',
                root,
            ),
            'utf8',
        ),
    ]);

    assert.match(page, /dashboardRoute\.url\(\{ query }\)/);
    assert.match(page, /only: \['dashboard', 'transactionsNavigation']/);
    assert.match(page, /@select="selectMonth"/);
    assert.match(page, /monthCallout/);
    assert.match(page, /<FinancialTimeline[\s\S]*selectedMonthCallout/);
    assert.doesNotMatch(page, /<FinancialTimeline(?:\s+[^>]*?)?\/>/);
    assert.match(page, /detailOpen\.value = false/);
    assert.match(page, /v-model:include-forecast/);
    assert.match(page, /v-model:include-debts/);
    assert.match(page, /v-model:include-credits/);
    assert.match(timeline, /'expense' \| 'income' \| 'availability'/);
    assert.match(timeline, /availability_end_raw/);
    assert.match(timeline, /charts\.LineChart/);
    assert.match(timeline, /point\.is_selected/);
    assert.match(timeline, /point\.is_current/);
    assert.match(timeline, /includeForecast/);
    assert.match(timeline, /includeDebts/);
    assert.match(timeline, /includeCredits/);
    assert.match(timeline, /weight === 'heavy'/);
    assert.match(timeline, /import\('echarts\/core'\)/);
    assert.match(timeline, /axisLine: \{ show: false }/);
    assert.match(timeline, /chart\.value\.on\('click', onTimelineChartClick\)/);
    assert.match(timeline, /resolveTimelineClickPoint/);
    assert.match(timeline, /<slot \/>/);
    assert.match(timeline, /class="flex flex-col"/);
    assert.doesNotMatch(timeline, /class="flex h-full flex-col"/);
    assert.match(capacity, /role="switch"/);
    assert.match(capacity, /simulation_impacts\.forecast_expenses_raw/);
    assert.match(capacity, /simulation_impacts\.open_debts_raw/);
    assert.match(capacity, /simulation_impacts\.open_credits_raw/);
    assert.match(capacity, /update:includeForecast/);
    assert.match(capacity, /update:includeDebts/);
    assert.match(capacity, /update:includeCredits/);
    assert.match(capacity, /remaining_budget_raw/);
    assert.match(forecast, /projected_expense_raw/);
    assert.match(page, /dashboard\.expense_by_category/);
    assert.match(page, /dashboard\.accounts_summary/);
    assert.match(page, /dashboard\.filters\s*\.account_options/);
    assert.match(page, /changeAccount/);
    assert.match(page, /navigatePeriod/);
    assert.match(page, /IntersectionObserver/);
    assert.match(page, /isAccountSelectorOutOfView/);
    assert.match(page, /applicationTimezone/);
    assert.match(page, /currentCalendarPeriod/);
    assert.match(page, /timeZone: applicationTimezone\.value/);
    assert.match(page, /accountContextLabel/);
    assert.match(page, /scrollToAccountSelector/);
    assert.match(page, /periodSelectorElement/);
    assert.match(page, /scrollToPeriodSelector/);
    assert.match(page, /v-if="isAccountSelectorOutOfView"/);
    assert.match(page, /CalendarDays class="size-3\.5 shrink-0 text-primary"/);
    assert.match(page, /class="truncate capitalize">\{\{ periodLabel }}/);
    assert.match(page, /max-w-\[calc\(50%-0\.25rem\)]/);
    assert.match(page, /md:hidden/);
    assert.match(page, /hidden[\s\S]*md:inline-flex/);
    assert.match(page, /yearStatus/);
    assert.match(page, /todayLabel/);
    assert.doesNotMatch(page, /openLegacy/);
    assert.doesNotMatch(page, /legacyRoute/);
    assert.match(page, /dashboard\.upcoming_events\.items/);
    assert.match(page, /<BudgetVsActual/);
    assert.match(page, /<CategoryTargets/);
    assert.match(page, /<FinancialAgenda/);
    assert.match(page, /dashboard\.budget_vs_actual/);
    assert.match(page, /dashboard\.parent_category_budget_status/);
    assert.match(page, /dashboard\.scheduled_summary/);
    assert.match(page, /dashboard\.merchant_breakdown/);
    assert.match(page, /<LegacyOverviewCards/);
    assert.match(page, /dashboard\.overview/);
    assert.match(page, /dashboard\.pending_actions/);
    assert.match(page, /<MonthlyRecapPanel/);
    assert.match(page, /dashboard\.monthly_recap/);
    assert.match(forecast, /function barHeight/);
    assert.match(forecast, /h-52[\s\S]*grid-cols-7/);
    assert.match(forecast, /whitespace-nowrap/);
    assert.match(forecast, /text-\[clamp\(7px,1vw,10px\)]/);
    assert.match(forecast, /\* 76/);
    assert.doesNotMatch(page, /max-w-7xl/);
    assert.match(detail, /useMediaQuery\('\(min-width: 768px\)'\)/);
    assert.match(detail, /max-h-\[92dvh]/);
    assert.match(detail, /env\(safe-area-inset-bottom\)/);
    assert.match(detail, /income_composition/);
    assert.match(detail, /top_expense_categories/);
    assert.match(detail, /MonthDetailNarrative/);
    assert.match(monthDetailNarrative, /detail\.narrative\.window/);
    assert.match(monthDetailNarrative, /import\('echarts\/core'\)/);
    assert.match(monthDetailNarrative, /markLine/);
    assert.match(monthDetailNarrative, /futureTitle/);
    assert.match(monthDetailNarrative, /currentTitle/);
    assert.match(monthDetailNarrative, /pastTitle/);
    assert.match(commitment, /simulatedMonthlyCost/);
    assert.match(commitment, /remaining_monthly_raw/);
    assert.match(commitment, /simulatedCost/);
    assert.match(commitment, /effectiveCommitmentPercentage/);
    assert.match(commitment, /visualCommitmentPercentage/);
    assert.match(commitment, /isSimulating/);
    assert.match(
        commitment,
        /!isSimulating\.value\s*\?\s*\(props\.commitment\.budget_ratio \?\? 0\)/,
    );
    assert.match(
        commitment,
        /\(props\.commitment\.monthly_raw \+ simulatedCost\.value\)\s*\/\s*capacity\.value/,
    );
    assert.match(
        commitment,
        /Math\.max\(0, Math\.min\(100, effectiveCommitmentPercentage\.value\)\)/,
    );
    assert.match(
        commitment,
        /const status = computed\(\(\) =>\s*effectiveCommitmentPercentage\.value/s,
    );
    assert.match(commitment, /const commitmentAccent = computed/);
    assert.match(
        commitment,
        /conic-gradient\(\$\{commitmentAccent} \$\{visualCommitmentPercentage}%/,
    );
    assert.match(commitment, /MobileAmountInput/);
    assert.match(commitment, /clearAmount/);
    assert.match(commitment, /simulatedMonthlyCost = '0'/);
    assert.match(commitment, /conic-gradient[\s\S]*visualCommitmentPercentage/);
    assert.match(commitment, /Math\.round\(effectiveCommitmentPercentage\)/);
    assert.match(budget, /percentage_used/);
    assert.match(budget, /budgetVsActual\.empty/);
    assert.match(targets, /DashboardParentCategoryBudgetItem/);
    assert.match(targets, /categoryTargets\.empty/);
    assert.match(agenda, /scheduled\.upcoming/);
    assert.match(agenda, /agenda\.upcomingEmpty/);
    assert.match(agenda, /agenda\.payeesEmpty/);
    assert.match(overviewCards, /overview\.income_total_raw/);
    assert.match(overviewCards, /overview\.expense_total_raw/);
    assert.match(overviewCards, /pendingActions\.items/);
    assert.match(overviewCards, /overview\.savings_rate/);
    assert.match(overviewCards, /signedMoney/);
    assert.match(monthlyRecap, /expanded = ref\(false\)/);
    assert.match(monthlyRecap, /monthlyRecapShow\.url/);
    assert.match(monthlyRecap, /monthlyRecapPdf\.url/);
    assert.match(monthlyRecap, /recap\.scope\.account_scope/);
    assert.match(monthlyRecap, /recap\.totals\.transactions_count/);
});

test('financial agenda links the entire row and keeps unavailable sources readable', async () => {
    const agenda = await readFile(
        new URL('resources/js/components/dashboard/FinancialAgenda.vue', root),
        'utf8',
    );
    const row = agenda.match(/<component[\s\S]*?<\/component>/)?.[0];

    assert.ok(row);
    assert.match(row, /:is="entry\.source_url \? Link : 'article'"/);
    assert.match(row, /:href="entry\.source_url \?\? undefined"/);
    assert.match(row, /entry\.display_label/);
    assert.match(row, /entry\.scheduled_date/);
    assert.match(row, /entry\.expected_amount_raw/);
    assert.match(row, /focus-visible:outline/);
    assert.match(row, /v-if="!entry\.source_url"/);
    assert.match(row, /dashboard\.agenda\.sourceUnavailable/);
    assert.doesNotMatch(row, /@click|hidden|sm:|md:/);
    assert.match(agenda, /props\.scheduled\.upcoming\.slice\(0, 4\)/);
});

test('the Dashboard route renders the Analysis page and its financial agenda', async () => {
    const [controller, page] = await Promise.all([
        readFile(
            new URL('app/Http/Controllers/DashboardController.php', root),
            'utf8',
        ),
        readFile(
            new URL('resources/js/pages/dashboard/Analysis.vue', root),
            'utf8',
        ),
    ]);

    assert.match(controller, /Inertia::render\('dashboard\/Analysis'/);
    assert.match(
        page,
        /import FinancialAgenda from '@\/components\/dashboard\/FinancialAgenda\.vue'/,
    );
    assert.match(
        page,
        /<FinancialAgenda[\s\S]*?:scheduled="dashboard\.scheduled_summary"/,
    );
});

test('the rendered agenda exposes full row links for income and expense and no link for unavailable resources', async () => {
    const { createServer } = await import('vite');
    const { default: vue } = await import('@vitejs/plugin-vue');
    const { createSSRApp, h } = await import('vue');
    const { renderToString } = await import('vue/server-renderer');
    const { createI18n } = await import('vue-i18n');
    const server = await createServer({
        configFile: false,
        plugins: [vue()],
        resolve: { alias: { '@': new URL('resources/js', root).pathname } },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        appType: 'custom',
    });

    try {
        const { default: Agenda } = await server.ssrLoadModule(
            '/resources/js/components/dashboard/FinancialAgenda.vue',
        );
        const upcoming = [
            {
                id: 'income',
                display_label: 'Entrata pianificata',
                source_url: '/recurring-entries/income?highlight=income-due',
            },
            {
                id: 'expense',
                display_label: 'Uscita pianificata',
                source_url: '/recurring-entries/expense?highlight=expense-due',
            },
            {
                id: 'unavailable',
                display_label: 'Risorsa eliminata',
                source_url: null,
            },
        ].map((entry) => ({
            ...entry,
            scheduled_date: '2026-10-25',
            expected_amount_raw: 100,
            entry_kind: 'recurring',
        }));
        const app = createSSRApp({
            render: () =>
                h(Agenda, {
                    notifications: {},
                    recurring: {},
                    scheduled: { upcoming },
                    merchants: [],
                    currency: 'EUR',
                }),
        });
        app.use(
            createI18n({
                legacy: false,
                locale: 'it',
                missingWarn: false,
                fallbackWarn: false,
                messages: {},
            }),
        );
        const html = await renderToString(app);

        for (const entry of upcoming.slice(0, 2)) {
            const anchor = html.match(
                new RegExp(
                    `<a[^>]*href="${entry.source_url.replace('?', '\\?')}"[^>]*>[\\s\\S]*?<\\/a>`,
                ),
            )?.[0];
            assert.ok(anchor, `Missing row link for ${entry.id}`);
            assert.ok(anchor.includes(entry.display_label));
            assert.ok(anchor.includes('25 ott'));
            assert.ok(anchor.includes('100'));
            assert.doesNotMatch(
                anchor,
                /sm:hidden|md:hidden|pointer-events-none/,
            );
        }

        assert.match(
            html,
            /<article[^>]*>[\s\S]*?Risorsa eliminata[\s\S]*?sourceUnavailable[\s\S]*?<\/article>/,
        );
    } finally {
        await server.close();
    }
});
