import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    parse,
    compileScript,
    compileTemplate,
    registerTS,
} from '@vue/compiler-sfc';
import ts from 'typescript';
registerTS(() => ts);
const source = (path) =>
    readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const module = { exports: {} };
new Function(
    'exports',
    ts.transpile(source('resources/js/lib/payment-management.ts'), {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    }),
)(module.exports);
const { paymentAction, reminderDate, paymentStateLabel } = module.exports;
test('bank issues and real reversals never become new payment records', () => {
    for (const reason of [
        'not_charged',
        'bank_problem',
        'insufficient_funds',
        'postponed',
        'declined',
        'invalid_method',
        'processing',
        'duplicate_charge',
        'bank_reversal',
        'other',
    ]) {
        assert.equal(paymentAction(reason, true), 'pending');
        assert.equal(paymentAction(reason, false), 'pending');
    }
});
test('actual payment corrects the automatic transaction and records a manual payment only explicitly', () => {
    for (const reason of [
        'already_paid',
        'different_amount',
        'other_account',
    ]) {
        assert.equal(paymentAction(reason, true), 'correct');
        assert.equal(paymentAction(reason, false), 'record');
    }

    assert.equal(paymentAction('cancelled', true), 'cancel');
});
test('reminders use calendar days across month and year boundaries', () => {
    assert.equal(reminderDate(2, new Date(2026, 9, 31, 21)), '2026-11-02');
    assert.equal(reminderDate(7, new Date(2026, 11, 29, 21)), '2027-01-05');
    assert.equal(paymentStateLabel('cancelled'), 'Singola scadenza non dovuta');
    assert.equal(paymentStateLabel('pending'), 'Pagamento ancora dovuto');
});
test('payment dialog compiles and protects explicit accounting decisions', () => {
    const path = 'resources/js/components/dashboard/ManagePaymentDialog.vue';
    const text = source(path);
    const filename = fileURLToPath(new URL(`../../${path}`, import.meta.url));
    const { descriptor, errors } = parse(text, { filename });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, {
        id: path,
        fs: {
            fileExists: existsSync,
            readFile: (file) => readFileSync(file, 'utf8'),
        },
    });
    const template = compileTemplate({
        source: descriptor.template.content,
        filename,
        id: path,
        compilerOptions: { bindingMetadata: script.bindings },
    });
    assert.deepEqual(template.errors, []);
    assert.match(text, /confirm_rectification/);
    assert.match(text, /confirm_new_payment/);
    assert.match(text, /transaction_uuid/);
    assert.match(text, /paymentCandidates\.url/);
    assert.match(text, /request_id = crypto\.randomUUID\(\)/);
    assert.match(text, /payment\.history/);
    assert.match(text, /step < 2/);
});
test('payment details preserve corrected history and display per-occurrence audit data', () => {
    const details = source(
        'resources/js/components/recurring/OccurrencePayments.vue',
    );
    const show = source('resources/js/pages/transactions/recurring/Show.vue');
    assert.match(details, /payment\.rectified\s*\? ui\('Rettificato'\)/);
    assert.match(details, /formatCurrency\(payment\.amount/);

    for (const field of [
        'expected_amount',
        'expected_date',
        'estimated_date',
        'actual_amount',
        'actual_date',
        'history',
    ]) {
        assert.match(details, new RegExp(`management\\.${field}`));
    }

    assert.match(details, /paymentActionLabel\(event\.action\)/);
    assert.match(details, /event\.created_at/);
    assert.match(details, /event\.notes/);
    assert.equal(
        (
            show.match(/:management="\s*occurrence\.payment_management\s*"/g) ??
            []
        ).length,
        2,
    );
    assert.equal(
        module.exports.paymentActionLabel('correct'),
        'Pagamento corretto',
    );
    assert.equal(
        module.exports.paymentActionLabel('record'),
        'Pagamento registrato',
    );
    const path = 'resources/js/components/recurring/OccurrencePayments.vue';
    const filename = fileURLToPath(new URL(`../../${path}`, import.meta.url));
    const { descriptor, errors } = parse(details, { filename });
    assert.deepEqual(errors, []);
    const script = compileScript(descriptor, {
        id: path,
        fs: {
            fileExists: existsSync,
            readFile: (file) => readFileSync(file, 'utf8'),
        },
    });
    const template = compileTemplate({
        source: descriptor.template.content,
        filename,
        id: path,
        compilerOptions: { bindingMetadata: script.bindings },
    });
    assert.deepEqual(template.errors, []);
});

test('a real existing payment can replace an uncharged automatic posting only explicitly', () => {
    assert.equal(paymentAction('already_paid', true, true), 'record');
    assert.equal(paymentAction('already_paid', true, false), 'correct');
    assert.equal(paymentAction('different_amount', true, true), 'correct');
    const dialog = source(
        'resources/js/components/dashboard/ManagePaymentDialog.vue',
    );
    assert.match(dialog, /v-model="existingAutomaticPayment"/);
    assert.match(dialog, /v-if="!existingAutomaticPayment"/);
    assert.match(
        dialog,
        /action\.value === 'record' && existingAutomaticPayment\.value/,
    );
    assert.match(dialog, /il collegamento del pagamento esistente/);
});
test('real duplicate charges and bank reversals are review only without missed-payment scheduling', () => {
    assert.equal(
        module.exports.paymentRequiresReview('duplicate_charge'),
        true,
    );
    assert.equal(module.exports.paymentRequiresReview('bank_reversal'), true);
    assert.equal(module.exports.paymentRequiresReview('not_charged'), false);
    const dialog = source(
        'resources/js/components/dashboard/ManagePaymentDialog.vue',
    );
    assert.match(dialog, /<template v-if="!reviewOnly">/);
    assert.match(dialog, /!reviewOnly\.value && data\.estimated_date/);
    assert.match(dialog, /action\.value === 'pending' &&\s*!reviewOnly\.value/);
});

test('occurrence timeline renders event dates separately from payment dates and keeps rectifications out of ordinary links', async () => {
    const { createServer } = await import('vite');
    const { default: vue } = await import('@vitejs/plugin-vue');
    const { createSSRApp, h } = await import('vue');
    const { renderToString } = await import('vue/server-renderer');
    const { createI18n } = await import('vue-i18n');
    const server = await createServer({
        configFile: false,
        plugins: [vue()],
        resolve: {
            alias: {
                '@': new URL('../../resources/js', import.meta.url).pathname,
            },
        },
        server: { middlewareMode: true, hmr: false, ws: false, watch: null },
        optimizeDeps: { noDiscovery: true, include: [] },
        appType: 'custom',
    });

    try {
        const { default: Payments } = await server.ssrLoadModule(
            '/resources/js/components/recurring/OccurrencePayments.vue',
        );
        const { uiCopyMessages } = await server.ssrLoadModule(
            '/resources/js/i18n/ui-copy.ts',
        );
        const snapshot = {
            expected_amount: 15,
            expected_date: '2026-10-09',
            estimated_date: null,
            reminder_date: '2026-10-11',
            actual_amount: null,
            actual_date: null,
        };
        const app = createSSRApp({
            render: () =>
                h(Payments, {
                    payments: [],
                    currency: 'EUR',
                    history: [
                        {
                            uuid: 'old',
                            date: '2026-10-09',
                            amount: 15,
                            rectified: true,
                            status: 'ignored',
                            show_url: '/transactions?highlight=old',
                        },
                    ],
                    management: {
                        expected_amount: 15,
                        expected_date: '2026-10-09',
                        estimated_date: null,
                        actual_amount: 12,
                        actual_date: '2026-11-02',
                        state: 'paid',
                        history: [
                            {
                                id: 1,
                                reason: 'not_charged',
                                action: 'rectify',
                                created_at: '2026-10-10T12:00:00Z',
                                before: snapshot,
                                after: snapshot,
                                rectified_amount: 15,
                                notes: 'Addebito assente',
                            },
                            {
                                id: 2,
                                reason: 'already_paid',
                                action: 'record',
                                created_at: '2026-11-03T12:00:00Z',
                                before: snapshot,
                                after: {
                                    ...snapshot,
                                    actual_amount: 12,
                                    actual_date: '2026-11-02',
                                    reminder_date: null,
                                    account: 'Conto personale',
                                },
                                notes: null,
                            },
                        ],
                    },
                }),
        });
        app.use(
            createI18n({
                legacy: false,
                locale: 'it',
                missingWarn: false,
                fallbackWarn: false,
                messages: { it: { uiCopy: uiCopyMessages.it } },
            }),
        );
        const html = await renderToString(app);
        assert.match(html, /<ol/);
        assert.match(html, /Evento registrato/);
        assert.match(html, /03\/11\/2026/);
        assert.match(html, /02\/11\/2026/);
        assert.match(html, /Data effettiva del pagamento/);
        assert.match(html, /Promemoria programmato/);
        assert.match(html, /Importo originario rettificato/);
        assert.match(html, /Conto personale/);
        assert.ok(
            html.indexOf('Addebito assente') <
                html.indexOf('2026-11-03T12:00:00Z'),
        );
        assert.doesNotMatch(html, /href="[^\"]*highlight=old/);
    } finally {
        await server.close();
    }
});

test('both payment forms require an explicit mode and keep the new payment separate from the final total', () => {
    for (const path of [
        'resources/js/components/dashboard/ManagePaymentDialog.vue',
        'resources/js/components/transactions/TransactionFormSheet.vue',
    ]) {
        const text = source(path);
        const filename = fileURLToPath(
            new URL(`../../${path}`, import.meta.url),
        );
        const { descriptor, errors } = parse(text, { filename });
        assert.deepEqual(errors, []);
        const script = compileScript(descriptor, {
            id: path,
            fs: {
                fileExists: existsSync,
                readFile: (file) => readFileSync(file, 'utf8'),
            },
        });
        const template = compileTemplate({
            source: descriptor.template.content,
            filename,
            id: path,
            compilerOptions: { bindingMetadata: script.bindings },
        });
        assert.deepEqual(template.errors, []);
        assert.match(text, /v-model="form.payment_type"/);
        assert.match(text, /<option value="advance">/);
        assert.match(text, /<option value="final">/);
        assert.match(text, /v-model="\s*form\.final_total_amount\s*"/);
        assert.match(text, /final_total_amount:/);
        assert.doesNotMatch(text, /v-model="form.payment_is_partial"/);
    }

    assert.equal(paymentStateLabel('partial'), 'Parzialmente pagata');
});

test('upcoming events distinguish already recorded debits from amounts still payable', () => {
    const page = source('resources/js/pages/dashboard/Analysis.vue');
    const statusFunction = page.slice(
        page.indexOf('function pendingEventStatus('),
        page.indexOf('function pendingEventStatusClass('),
    );
    const getStatus = new Function(
        ts.transpile(statusFunction + '\nreturn pendingEventStatus;', {
            target: ts.ScriptTarget.ES2022,
        }),
    )();
    assert.equal(
        getStatus({
            requires_payment_confirmation: true,
            payment_management: {
                state: 'to_verify',
                paid_amount: 15,
                remaining_amount: 0,
            },
        }),
        'In attesa di conferma',
    );
    assert.equal(
        getStatus({
            requires_payment_confirmation: true,
            payment_management: {
                state: 'partial',
                paid_amount: 10,
                remaining_amount: 5,
            },
        }),
        'Parzialmente pagato',
    );
    assert.equal(
        getStatus({
            payment_management: {
                state: 'review',
                paid_amount: 15,
                remaining_amount: 0,
            },
        }),
        'Da verificare',
    );
    assert.equal(
        getStatus({
            payment_management: { state: 'paid', remaining_amount: 0 },
        }),
        'Pagato',
    );
    assert.match(
        page,
        /Addebito già contabilizzato: conferma l’esito senza effettuare un nuovo pagamento\./,
    );
    assert.match(page, /L’importo mostrato è il solo residuo da pagare\./);
});
