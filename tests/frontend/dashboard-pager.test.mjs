import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const filename = new URL(
    '../../resources/js/components/dashboard/DashboardPager.vue',
    import.meta.url,
).pathname;
const source = readFileSync(filename, 'utf8');
const { descriptor } = parse(source, { filename });
const script = compileScript(descriptor, { id: 'pager', inlineTemplate: true });
const exports = {};
new Function(
    'require',
    'exports',
    ts.transpile(script.content, {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    }),
)((name) => {
    if (name === 'vue') {
        return Vue;
    }

    if (name === 'vue-i18n') {
        return {
            useI18n: () => ({
                t: (key, params) =>
                    params ? `${params.page}/${params.total}` : key,
            }),
        };
    }

    if (name === 'lucide-vue-next') {
        return {
            ChevronLeft: () => Vue.h('svg'),
            ChevronRight: () => Vue.h('svg'),
        };
    }

    throw new Error(name);
}, exports);
const Pager = exports.default;

const frames = new Map();
let nextFrame = 0;
async function settle() {
    for (let pass = 0; pass < 12; pass += 1) {
        await Vue.nextTick();
        const callbacks = [...frames.values()];
        frames.clear();
        callbacks.forEach((callback) => callback());

        if (!callbacks.length) {
            await Vue.nextTick();

            if (!frames.size) {
                return;
            }
        }
    }

    assert.fail('Layout did not settle');
}

function mount(items, reducedMotion = false, geometry = {}) {
    const observers = [];
    globalThis.ResizeObserver = class {
        constructor(callback) {
            this.callback = callback;
            observers.push(this);
        }
        observe() {}
        unobserve() {}
        disconnect() {
            this.disconnected = true;
        }
    };
    globalThis.window = {
        innerHeight: geometry.screenHeight ?? 400,
        matchMedia: (query) => ({
            matches: query.includes('reduced-motion')
                ? reducedMotion
                : geometry.desktop !== false,
        }),
        getComputedStyle: () => ({
            rowGap: '8',
            paddingTop: '4',
            paddingBottom: '4',
            marginTop: '12',
        }),
        requestAnimationFrame(callback) {
            frames.set(++nextFrame, callback);

            return nextFrame;
        },
        cancelAnimationFrame(id) {
            frames.delete(id);
        },
        addEventListener() {},
        removeEventListener() {},
    };
    const makeNode = (type, text = '') => ({
        type,
        text,
        props: {},
        children: [],
        parent: null,
        clientWidth: 400,
        scrollLeft: 0,
        clientHeight: geometry.rootHeight ?? 400,
        get dataset() {
            return { pagerItem: String(this.props['data-pager-item']) };
        },
        getBoundingClientRect() {
            const index = this.props['data-pager-item'];

            return {
                height:
                    index !== undefined
                        ? (geometry.heights?.[index] ?? 100)
                        : this.type === 'nav'
                          ? 32
                          : this.clientHeight,
            };
        },
        querySelectorAll(selector) {
            const nodes = this.children.flatMap(function walk(node) {
                return [node, ...node.children.flatMap(walk)];
            });

            return nodes.filter((node) =>
                selector.includes('data-pager-item')
                    ? node.props['data-pager-item'] !== undefined
                    : node.props['data-pager-page'] !== undefined,
            );
        },
        querySelector(selector) {
            return this.querySelectorAll(selector)[0];
        },
        closest() {
            return {
                getBoundingClientRect: () => ({
                    height: this.clientHeight + (geometry.chrome ?? 0),
                }),
            };
        },
        scrollTo(options) {
            this.lastScroll = options;
            this.scrollLeft = options.left;
        },
    });
    const renderer = Vue.createRenderer({
        createElement: makeNode,
        createText: (text) => makeNode('text', text),
        createComment: (text) => makeNode('comment', text),
        setText: (node, text) => {
            node.text = text;
        },
        setElementText: (node, text) => {
            node.text = text;
        },
        patchProp: (node, key, _previous, value) => {
            node.props[key] = value;
        },
        insert(node, parent, anchor) {
            if (node.parent) {
                node.parent.children.splice(
                    node.parent.children.indexOf(node),
                    1,
                );
            }

            node.parent = parent;
            const index = anchor ? parent.children.indexOf(anchor) : -1;
            parent.children.splice(
                index < 0 ? parent.children.length : index,
                0,
                node,
            );
        },
        remove(node) {
            node.parent.children.splice(node.parent.children.indexOf(node), 1);
        },
        parentNode: (node) => node.parent,
        nextSibling: (node) =>
            node.parent?.children[node.parent.children.indexOf(node) + 1],
    });
    const state = Vue.reactive({ items });
    const app = renderer.createApp({
        setup: () => () =>
            Vue.h(
                Pager,
                {
                    items: state.items,
                    label: 'Agenda',
                    fill: geometry.fill,
                    compactMobile: geometry.compactMobile,
                    compactControls: geometry.compactControls,
                    stableLayout: geometry.stableLayout,
                },
                {
                    default: ({ item }) =>
                        Vue.h('article', { 'data-item': item }, item),
                },
            ),
    });
    const root = makeNode('root');
    app.mount(root);
    const all = (node = root) => [
        node,
        ...node.children.flatMap((child) => all(child)),
    ];
    const viewport = () => all().find((node) => node.props.role === 'region');
    const buttons = () => all().filter((node) => node.type === 'button');
    const groups = () => all().filter((node) => node.props.role === 'group');

    return { state, app, all, viewport, buttons, groups, observers };
}

test('pages fit measured card heights and retain every item with accessible controls', async () => {
    const view = mount(['a', 'b', 'c', 'd', 'e', 'f', 'g']);
    await settle();
    assert.deepEqual(
        view
            .groups()
            .map(
                (group) =>
                    group.children.filter(
                        (child) => child.props['data-pager-item'] !== undefined,
                    ).length,
            ),
        [3, 3, 1],
    );
    assert.deepEqual(
        view
            .all()
            .filter((node) => node.type === 'article')
            .map((node) => node.props['data-item']),
        ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
    );
    assert.equal(view.buttons().length, 5);
    assert.equal(view.buttons()[0].props.disabled, true);
    assert.equal(view.buttons()[1].props['aria-current'], 'page');
    assert.ok(
        view
            .buttons()
            .every(
                (button) =>
                    button.props['aria-label'] && button.props['aria-controls'],
            ),
    );
    view.buttons()[3].props.onClick();
    await settle();
    assert.equal(view.viewport().lastScroll.left, 800);
    assert.equal(view.viewport().lastScroll.behavior, 'smooth');
    assert.equal(view.buttons()[3].props['aria-current'], 'page');
    assert.equal(view.buttons().at(-1).props.disabled, true);
    assert.equal(view.groups()[0].props.inert, true);
    assert.equal(view.groups()[2].props.inert, false);
    assert.ok(
        view
            .buttons()[3]
            .children.some((child) =>
                child.props.class?.includes('bg-primary'),
            ),
    );
    view.app.unmount();
    assert.equal(view.observers[0].disconnected, true);
});

test('swiping updates the dots, arrows and keyboard navigation independently', async () => {
    const view = mount([1, 2, 3, 4, 5, 6, 7]);
    const other = mount([8, 9, 10, 11]);
    await settle();
    view.viewport().scrollLeft = 400;
    view.viewport().props.onScrollPassive();
    await settle();
    assert.equal(view.buttons()[2].props['aria-current'], 'page');
    assert.equal(other.buttons()[1].props['aria-current'], 'page');
    view.buttons().at(-1).props.onClick();
    await settle();
    assert.equal(view.viewport().lastScroll.left, 800);
    view.buttons()[0].props.onClick();
    await settle();
    assert.equal(view.viewport().lastScroll.left, 400);
    let prevented = false;
    const keyboard = (key, target = view.viewport()) =>
        view.viewport().props.onKeydown({
            key,
            target,
            currentTarget: view.viewport(),
            preventDefault() {
                prevented = true;
            },
        });
    keyboard('End');
    await settle();
    assert.equal(view.viewport().lastScroll.left, 800);
    assert.equal(prevented, true);
    keyboard('Home');
    await settle();
    assert.equal(view.viewport().lastScroll.left, 0);
    keyboard('ArrowRight', {});
    assert.equal(view.viewport().lastScroll.left, 0);
    keyboard('ArrowRight');
    await settle();
    assert.equal(view.viewport().lastScroll.left, 400);
    view.app.unmount();
    other.app.unmount();
});

test('resizing and shrinking the list keep the current page valid with reduced motion', async () => {
    const view = mount([1, 2, 3, 4, 5, 6, 7], true);
    await settle();
    view.buttons()[3].props.onClick();
    await settle();
    assert.equal(view.viewport().lastScroll.behavior, 'instant');
    view.viewport().clientWidth = 250;
    view.observers[0].callback();
    await settle();
    assert.equal(view.viewport().lastScroll.left, 500);
    view.state.items = [1, 2];
    await settle();
    await settle();
    await settle();
    assert.equal(view.viewport().lastScroll.left, 0);
    assert.equal(view.groups().length, 1);
    assert.equal(view.buttons().length, 0);
    assert.equal(
        view.all().some((node) => node.type === 'nav'),
        false,
    );
    view.app.unmount();
});

test('pager template compiles with native touch scrolling and mandatory page snapping', () => {
    const template = compileTemplate({
        source: descriptor.template.content,
        filename,
        id: 'pager',
    });
    assert.deepEqual(template.errors, []);
    assert.match(source, /snap-x snap-mandatory overflow-x-auto/);
    assert.match(source, /snap-start snap-always/);
    assert.doesNotMatch(source, /touch-action:\s*none|touchmove\.prevent/);
});

test('more than three compact cards fit while controls stay absent for a single page', async () => {
    const view = mount([1, 2, 3, 4, 5, 6, 7], false, {
        heights: Array(7).fill(40),
    });
    await settle();
    assert.equal(view.groups().length, 1);
    assert.equal(view.buttons().length, 0);
    assert.equal(
        view.all().some((node) => node.type === 'nav'),
        false,
    );
    assert.equal(view.viewport().props.style.height, '336px');
    view.app.unmount();
});

test('overflow packs variable card heights without dropping any item', async () => {
    const geometry = { heights: [40, 40, 40, 40, 40, 40, 40, 40, 40, 40] };
    const view = mount(
        Array.from({ length: 10 }, (_, index) => index),
        false,
        geometry,
    );
    await settle();
    const counts = () =>
        view
            .groups()
            .map(
                (group) =>
                    group.children.filter(
                        (child) => child.props['data-pager-item'] !== undefined,
                    ).length,
            );
    assert.deepEqual(counts(), [7, 3]);
    geometry.heights = [180, 180, 40, 40, 40, 40, 40, 40, 40, 40];
    view.observers[0].callback();
    await settle();
    assert.deepEqual(counts(), [1, 4, 5]);
    assert.equal(
        view.all().filter((node) => node.type === 'article').length,
        10,
    );
    view.app.unmount();
});

test('desktop fill follows available reference space and mobile stays bounded by its viewport share', async () => {
    const desktop = mount([1, 2, 3, 4, 5, 6], false, {
        heights: Array(6).fill(40),
        fill: true,
        rootHeight: 180,
    });
    await settle();
    assert.equal(desktop.groups().length, 3);
    assert.ok(parseFloat(desktop.viewport().props.style.height) <= 180);
    desktop.app.unmount();
    const mobile = mount([1, 2, 3, 4, 5, 6], false, {
        heights: Array(6).fill(40),
        screenHeight: 800,
        desktop: false,
        chrome: 100,
    });
    await settle();
    assert.equal(mobile.groups().length, 1);
    assert.equal(mobile.buttons().length, 0);
    mobile.app.unmount();
});

for (const heights of [[100], [100, 100], [100, 100, 100, 100, 100, 100]]) {
    test(`compact mobile pager fits active content for ${heights.length} movements`, async () => {
        const view = mount(
            heights.map((_, i) => i),
            false,
            {
                heights,
                desktop: false,
                screenHeight: 800,
                compactMobile: true,
            },
        );
        await settle();
        assert.equal(
            view.viewport().props.style.height,
            `${Math.min(heights.length, 3) * 108}px`,
        );

        if (heights.length > 3) {
            const last = view
                .buttons()
                .filter(
                    (node) =>
                        node.props['aria-current'] !== undefined ||
                        node.props['aria-label']?.includes('/'),
                )
                .at(-1);
            last.props.onClick();
            await settle();
            assert.equal(view.viewport().props.style.height, '324px');
        }

        view.app.unmount();
    });
}

test('compact mobile last page does not reserve space for fuller pages', async () => {
    const view = mount([1, 2, 3, 4], false, {
        heights: [100, 100, 100, 100],
        desktop: false,
        screenHeight: 800,
        compactMobile: true,
    });
    await settle();
    view.buttons().at(-1).props.onClick();
    await settle();
    assert.equal(view.viewport().props.style.height, '108px');
    assert.deepEqual(
        view
            .all()
            .filter((node) => node.type === 'article')
            .map((node) => node.props['data-item']),
        [1, 2, 3, 4],
    );
    view.app.unmount();
});

test('upcoming compact controls keep variable-height pages stable on every screen', async () => {
    for (const desktop of [false, true]) {
        const view = mount([1, 2, 3, 4], false, {
            heights: [180, 100, 160, 80],
            desktop,
            compactMobile: true,
            compactControls: true,
            stableLayout: true,
        });
        await settle();
        const height = view.viewport().props.style.height;
        assert.equal(view.buttons().length, 2);
        assert.match(view.buttons()[0].props.class, /touch-manipulation/);
        assert.match(view.buttons()[0].props.class, /size-11/);
        view.buttons().at(-1).props.onClick();
        await settle();
        assert.equal(view.viewport().props.style.height, height);
        assert.equal(view.viewport().lastScroll.left, 400);
        view.viewport().scrollLeft = 400;
        view.viewport().props.onScrollPassive();
        await settle();
        assert.equal(view.viewport().props.style.height, height);
        view.app.unmount();
    }
});
