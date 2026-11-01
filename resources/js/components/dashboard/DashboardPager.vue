<script setup lang="ts" generic="T">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    onUpdated,
    ref,
    useId,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    items: T[];
    label: string;
    fill?: boolean;
    compactMobile?: boolean;
    compactControls?: boolean;
    stableLayout?: boolean;
}>();
defineSlots<{ default(props: { item: T }): unknown }>();
const { t } = useI18n();
const root = ref<HTMLElement | null>(null);
const controls = ref<HTMLElement | null>(null);
const viewport = ref<HTMLElement | null>(null);
const activePage = ref(0);
const viewportId = useId();
const heights = ref<number[]>([]);
const availableHeight = ref(Infinity);
const isDesktop = ref(true);
const gap = ref(0);
const padding = ref(0);
const controlsHeight = ref(0);
const naturalHeight = computed(
    () =>
        heights.value.reduce((sum, height) => sum + height, 0) +
        Math.max(0, props.items.length - 1) * gap.value +
        padding.value,
);
const needsPagination = computed(
    () => naturalHeight.value > availableHeight.value,
);
const contentHeight = computed(() =>
    Math.max(
        1,
        availableHeight.value -
            (needsPagination.value ? controlsHeight.value : 0),
    ),
);
const pages = computed(() => {
    const result: { item: T; index: number }[][] = [];
    let page: { item: T; index: number }[] = [];
    let used = padding.value;
    props.items.forEach((item, index) => {
        const height = heights.value[index] ?? 0;
        const next = height + (page.length ? gap.value : 0);

        if (page.length && used + next > contentHeight.value) {
            result.push(page);
            page = [];
            used = padding.value;
        }

        used += height + (page.length ? gap.value : 0);
        page.push({ item, index });
    });

    if (page.length) {
        result.push(page);
    }

    return result;
});
const viewportHeight = computed(() => {
    if (!heights.value.length) {
        return undefined;
    }

    const tallest = Math.max(
        0,
        ...(props.compactMobile && !props.stableLayout && !isDesktop.value
            ? pages.value.slice(activePage.value, activePage.value + 1)
            : pages.value
        ).map(
            (page) =>
                page.reduce(
                    (sum, entry) => sum + (heights.value[entry.index] ?? 0),
                    padding.value,
                ) +
                Math.max(0, page.length - 1) * gap.value,
        ),
    );

    return `${Math.min(tallest, contentHeight.value)}px`;
});
let observer: ResizeObserver | undefined;
const observed = new Set<Element>();
let frame: number | undefined;
let mounted = false;
let previousWidth = 0;

function measure(): void {
    const element = root.value;

    if (!element) {
        return;
    }

    const items = Array.from(
        element.querySelectorAll<HTMLElement>('[data-pager-item]'),
    );
    const nextHeights: number[] = [];
    items.forEach((item) => {
        nextHeights[Number(item.dataset.pagerItem)] =
            item.getBoundingClientRect().height;
    });

    if (JSON.stringify(nextHeights) !== JSON.stringify(heights.value)) {
        heights.value = nextHeights;
    }

    const page = element.querySelector<HTMLElement>('[data-pager-page]');

    if (page) {
        const style = window.getComputedStyle(page);
        gap.value = parseFloat(style.rowGap) || 0;
        padding.value =
            (parseFloat(style.paddingTop) || 0) +
            (parseFloat(style.paddingBottom) || 0);
    }

    if (controls.value) {
        controlsHeight.value =
            controls.value.getBoundingClientRect().height +
            (parseFloat(window.getComputedStyle(controls.value).marginTop) ||
                0);
    }

    const desktop = window.matchMedia('(min-width: 1024px)').matches;
    isDesktop.value = desktop;
    const section = element.closest('section');
    const chrome = section
        ? section.getBoundingClientRect().height -
          element.getBoundingClientRect().height
        : 0;
    availableHeight.value =
        props.fill && desktop
            ? element.clientHeight
            : Math.max(1, window.innerHeight / (desktop ? 1 : 2) - chrome);
    const targets = new Set<Element>([
        element,
        ...items,
        ...(controls.value ? [controls.value] : []),
        ...(section ? [section] : []),
    ]);
    observed.forEach((target) => {
        if (!targets.has(target)) {
            observer?.unobserve(target);
            observed.delete(target);
        }
    });
    targets.forEach((target) => {
        if (!observed.has(target)) {
            observer?.observe(target);
            observed.add(target);
        }
    });
    const width = viewport.value?.clientWidth ?? 0;

    if (width !== previousWidth) {
        previousWidth = width;
        goToPage(activePage.value, false);
    }
}
function scheduleMeasure(): void {
    if (!mounted || frame !== undefined) {
        return;
    }

    frame = window.requestAnimationFrame(() => {
        frame = undefined;
        measure();
    });
}

function goToPage(index: number, smooth = true): void {
    const element = viewport.value;

    if (!element) {
        return;
    }

    const target = Math.max(0, Math.min(index, pages.value.length - 1));
    activePage.value = target;
    element.scrollTo({
        left: target * element.clientWidth,
        behavior:
            smooth &&
            !window.matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'smooth'
                : 'instant',
    });
}
function updatePage(): void {
    const element = viewport.value;

    if (element?.clientWidth) {
        activePage.value = Math.max(
            0,
            Math.min(
                pages.value.length - 1,
                Math.round(element.scrollLeft / element.clientWidth),
            ),
        );
    }
}
function onKeydown(event: KeyboardEvent): void {
    if (event.target !== event.currentTarget) {
        return;
    }

    const target =
        event.key === 'ArrowRight'
            ? activePage.value + 1
            : event.key === 'ArrowLeft'
              ? activePage.value - 1
              : event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? pages.value.length - 1
                  : null;

    if (target !== null) {
        event.preventDefault();
        goToPage(target);
    }
}
watch(
    () => pages.value.length,
    async () => {
        await nextTick();
        goToPage(activePage.value, false);
    },
);
onMounted(() => {
    mounted = true;
    observer = new ResizeObserver(scheduleMeasure);
    window.addEventListener('resize', scheduleMeasure);
    scheduleMeasure();
});
onUpdated(scheduleMeasure);
onBeforeUnmount(() => {
    mounted = false;
    observer?.disconnect();
    window.removeEventListener('resize', scheduleMeasure);

    if (frame !== undefined) {
        window.cancelAnimationFrame(frame);
    }
});
</script>

<template>
    <div ref="root" class="flex min-h-0 min-w-0 flex-col">
        <div
            :id="viewportId"
            ref="viewport"
            role="region"
            :aria-label="label"
            tabindex="0"
            class="flex shrink-0 snap-x snap-mandatory overflow-x-auto overflow-y-auto overscroll-x-contain rounded-lg [scrollbar-width:none] focus-visible:outline-2 focus-visible:outline-ring [&::-webkit-scrollbar]:hidden"
            :style="{ height: viewportHeight }"
            @scroll.passive="updatePage"
            @keydown="onKeydown"
        >
            <div
                v-for="(items, pageIndex) in pages"
                :key="pageIndex"
                data-pager-page
                role="group"
                :aria-label="
                    t('dashboard.analysis.pagination.page', {
                        page: pageIndex + 1,
                        total: pages.length,
                    })
                "
                :inert="pageIndex !== activePage"
                class="grid w-full min-w-0 shrink-0 snap-start snap-always content-start gap-2 p-1"
            >
                <div
                    v-for="entry in items"
                    :key="entry.index"
                    :data-pager-item="entry.index"
                    class="flow-root min-w-0"
                >
                    <slot :item="entry.item" />
                </div>
            </div>
        </div>
        <nav
            ref="controls"
            v-if="pages.length > 1"
            :aria-label="label"
            class="mt-3 flex shrink-0 items-center justify-center gap-2"
        >
            <button
                v-if="pages.length > 1"
                type="button"
                :aria-controls="viewportId"
                :aria-label="t('dashboard.analysis.pagination.previous')"
                :disabled="activePage === 0"
                class="grid shrink-0 place-items-center rounded-full hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-30"
                :class="
                    compactControls
                        ? 'size-11 touch-manipulation select-none'
                        : 'size-8'
                "
                @click="goToPage(activePage - 1)"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
            </button>
            <span
                v-if="compactControls"
                class="min-w-0 text-center text-sm tabular-nums"
            >
                {{
                    t('dashboard.analysis.pagination.page', {
                        page: activePage + 1,
                        total: pages.length,
                    })
                }}
            </span>
            <div v-else class="flex min-w-0 flex-wrap justify-center">
                <button
                    v-for="(_, index) in pages"
                    :key="index"
                    type="button"
                    :aria-controls="viewportId"
                    :aria-label="
                        t('dashboard.analysis.pagination.page', {
                            page: index + 1,
                            total: pages.length,
                        })
                    "
                    :aria-current="index === activePage ? 'page' : undefined"
                    class="grid size-8 place-items-center rounded-full focus-visible:outline-2 focus-visible:outline-ring"
                    @click="goToPage(index)"
                >
                    <span
                        class="size-2 rounded-full transition-colors motion-reduce:transition-none"
                        :class="
                            index === activePage
                                ? 'bg-primary'
                                : 'bg-muted-foreground/30'
                        "
                    />
                </button>
            </div>
            <button
                v-if="pages.length > 1"
                type="button"
                :aria-controls="viewportId"
                :aria-label="t('dashboard.analysis.pagination.next')"
                :disabled="activePage === pages.length - 1"
                class="grid shrink-0 place-items-center rounded-full hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-30"
                :class="
                    compactControls
                        ? 'size-11 touch-manipulation select-none'
                        : 'size-8'
                "
                @click="goToPage(activePage + 1)"
            >
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
        </nav>
        <p class="sr-only" role="status" aria-live="polite" aria-atomic="true">
            {{
                t('dashboard.analysis.pagination.page', {
                    page: activePage + 1,
                    total: pages.length,
                })
            }}
        </p>
    </div>
</template>
