<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    generations: Array,
    options: Object,
    balance: String,
});

const page = usePage();

function buyCredits(amount) {
    router.post(route('credits.store'), { amount }, { preserveScroll: true });
}

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
    displayed_charge_high: null,
});

const estimate = ref(null);
const estimating = ref(false);
let debounceTimer = null;

watch(
    () => [form.prompt, form.size, form.quality, form.reference_images.join('|')],
    () => {
        clearTimeout(debounceTimer);
        estimating.value = true;
        debounceTimer = setTimeout(fetchEstimate, 300);
    },
);

async function fetchEstimate() {
    estimating.value = true;
    try {
        const params = new URLSearchParams({
            prompt: form.prompt,
            size: form.size,
            quality: form.quality,
        });
        form.reference_images.filter(Boolean).forEach((u) => params.append('reference_images[]', u));
        const response = await fetch(`${route('generate.estimate')}?${params}`, {
            headers: { Accept: 'application/json' },
        });
        estimate.value = await response.json();
    } finally {
        estimating.value = false;
    }
}

fetchEstimate();

function addReferenceImage() {
    if (form.reference_images.length < props.options.max_reference_images) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index) {
    form.reference_images.splice(index, 1);
}

function submit() {
    if (!canAfford.value) {
        return;
    }
    form.reference_images = form.reference_images.filter(Boolean);
    form.displayed_charge_high = estimate.value?.charge_high ?? null;
    form.post(route('generate.store'), { preserveScroll: true });
}

const balanceNumber = computed(() => Number(props.balance));
const chargeHighNumber = computed(() =>
    estimate.value?.charge_high != null ? Number(estimate.value.charge_high) : null,
);
const canAfford = computed(() => {
    if (chargeHighNumber.value === null || Number.isNaN(chargeHighNumber.value)) {
        return true;
    }
    return balanceNumber.value >= chargeHighNumber.value;
});

const hasActive = computed(() =>
    props.generations.some((g) => ['pending_submit', 'queued', 'processing'].includes(g.status)),
);
const poller = setInterval(() => {
    if (hasActive.value) {
        router.reload({ only: ['generations', 'balance'] });
    }
}, 5000);
onBeforeUnmount(() => clearInterval(poller));

const statusStyle = {
    pending_submit: 'bg-gray-100 text-gray-700 ring-1 ring-inset ring-gray-200',
    queued: 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200',
    processing: 'bg-sky-50 text-sky-800 ring-1 ring-inset ring-sky-200',
    succeeded: 'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200',
    failed: 'bg-red-50 text-red-800 ring-1 ring-inset ring-red-200',
    rejected: 'bg-red-50 text-red-800 ring-1 ring-inset ring-red-200',
    submit_failed: 'bg-red-50 text-red-800 ring-1 ring-inset ring-red-200',
};

const statusLabel = {
    pending_submit: 'Sending',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Done',
    failed: 'Failed (free)',
    rejected: 'Rejected (free)',
    submit_failed: 'Provider busy (free)',
};

function isInFlight(status) {
    return ['pending_submit', 'queued', 'processing'].includes(status);
}

function formatRelative(iso) {
    if (!iso) {
        return '';
    }
    const then = new Date(iso).getTime();
    const seconds = Math.round((Date.now() - then) / 1000);
    if (seconds < 45) {
        return 'just now';
    }
    if (seconds < 3600) {
        const m = Math.floor(seconds / 60);
        return `${m}m ago`;
    }
    if (seconds < 86400) {
        const h = Math.floor(seconds / 3600);
        return `${h}h ago`;
    }
    const d = Math.floor(seconds / 86400);
    return `${d}d ago`;
}

function formatAbsolute(iso) {
    if (!iso) {
        return '';
    }
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="Generate" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Generate an image</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div
                    v-if="page.props.flash?.success"
                    class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    {{ page.props.flash.success }}
                </div>

                <div
                    v-if="hasActive"
                    class="flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900"
                >
                    <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-sky-500"></span>
                    A generation is still running — updating this list every 5 seconds.
                </div>

                <div class="flex flex-col gap-4 rounded-lg bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Your credit balance</p>
                        <p class="text-2xl font-semibold tabular-nums text-gray-900">${{ balance }}</p>
                    </div>
                    <div class="flex flex-col items-start gap-1 sm:items-end">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm text-gray-500">Buy credits (demo)</span>
                            <button
                                v-for="amount in [1, 5, 10]"
                                :key="amount"
                                type="button"
                                class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                                @click="buyCredits(amount)"
                            >
                                + ${{ amount }}
                            </button>
                        </div>
                        <p
                            v-if="page.props.errors?.amount"
                            class="text-sm text-red-600"
                        >
                            {{ page.props.errors.amount }}
                        </p>
                    </div>
                </div>

                <form class="space-y-5 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="prompt">Prompt</label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="3"
                            maxlength="4000"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                        ></textarea>
                        <p class="mt-1.5 text-xs text-gray-500">
                            Test keywords (passed to Pixelforge):
                            <code class="rounded bg-gray-100 px-1">[fail]</code>,
                            <code class="rounded bg-gray-100 px-1">[slow]</code>,
                            <code class="rounded bg-gray-100 px-1">[duplicate]</code>
                        </p>
                        <p v-if="form.errors.prompt" class="mt-1 text-sm text-red-600">{{ form.errors.prompt }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="size">Size</label>
                            <select
                                id="size"
                                v-model="form.size"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="s in options.sizes" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="quality">Quality</label>
                            <select
                                id="quality"
                                v-model="form.quality"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            >
                                <option v-for="q in options.qualities" :key="q" :value="q">{{ q }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label class="block text-sm font-medium text-gray-700">Reference image URLs (optional)</label>
                            <button
                                v-if="form.reference_images.length < options.max_reference_images"
                                type="button"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                @click="addReferenceImage"
                            >
                                + Add
                            </button>
                        </div>
                        <div v-for="(url, i) in form.reference_images" :key="i" class="mt-2 flex gap-2">
                            <input
                                v-model="form.reference_images[i]"
                                type="url"
                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="https://example.com/style.png"
                            />
                            <button
                                type="button"
                                class="shrink-0 text-sm text-red-600 hover:text-red-500"
                                @click="removeReferenceImage(i)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Before you pay</p>
                            <p v-if="estimating" class="text-xs text-gray-400">Estimating…</p>
                        </div>

                        <template v-if="estimate && !estimating">
                            <p class="mt-2 text-lg font-semibold tabular-nums text-gray-900">
                                ${{ estimate.charge_low }} – ${{ estimate.charge_high }}
                            </p>
                            <p class="mt-1 text-sm text-gray-600">
                                Provider cost ${{ estimate.cost_low }}–${{ estimate.cost_high }} + 25% markup
                            </p>
                            <p class="mt-2 text-xs text-gray-500">
                                Exact charge is fixed when the run finishes. Failed generations are always free.
                            </p>
                        </template>
                        <template v-else-if="estimating">
                            <p class="mt-2 text-sm text-gray-500">Calculating cost range…</p>
                        </template>
                        <template v-else>
                            <p class="mt-2 text-sm text-gray-500">Cost will appear once pricing loads.</p>
                        </template>

                        <p v-if="!canAfford && estimate" class="mt-3 text-sm font-medium text-amber-800">
                            Not enough credits for the high end of this estimate (${{ estimate.charge_high }}).
                            Buy credits above, or lower size/quality.
                        </p>
                        <p v-if="form.errors.balance" class="mt-2 text-sm text-red-600">{{ form.errors.balance }}</p>
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing || form.prompt.trim() === '' || !canAfford || estimating"
                        class="inline-flex w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >
                        {{ form.processing ? 'Submitting…' : 'Generate' }}
                    </button>
                </form>

                <div class="space-y-3">
                    <h3 class="text-lg font-semibold text-gray-800">Your generations</h3>
                    <div
                        v-if="generations.length === 0"
                        class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-10 text-center"
                    >
                        <p class="text-sm font-medium text-gray-700">Nothing yet</p>
                        <p class="mt-1 text-sm text-gray-500">Submit a prompt above to create your first image.</p>
                    </div>
                    <div
                        v-for="g in generations"
                        :key="g.id"
                        class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5"
                    >
                        <div class="flex items-start justify-between gap-4 p-4">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ g.prompt }}</p>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ g.size }} · {{ g.quality }}
                                    <span v-if="g.created_at" class="text-gray-400">
                                        ·
                                        <time :datetime="g.created_at" :title="formatAbsolute(g.created_at)">
                                            {{ formatRelative(g.created_at) }}
                                        </time>
                                    </span>
                                </p>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="statusStyle[g.status]"
                            >
                                <span
                                    v-if="isInFlight(g.status)"
                                    class="h-1.5 w-1.5 animate-pulse rounded-full bg-current"
                                ></span>
                                {{ statusLabel[g.status] ?? g.status }}
                            </span>
                        </div>
                        <p v-if="g.error_message" class="border-t border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700">
                            {{ g.error_message }}
                        </p>
                        <div
                            v-if="g.estimate_charge_low != null || g.status === 'succeeded'"
                            class="border-t border-gray-100 px-4 py-2 text-sm text-gray-700"
                        >
                            <p>
                                <template v-if="g.estimate_charge_low != null && g.estimate_charge_high != null">
                                    <span class="text-gray-500">Estimate</span>
                                    <span class="ml-1 font-semibold tabular-nums">
                                        ${{ g.estimate_charge_low }} – ${{ g.estimate_charge_high }}
                                    </span>
                                </template>
                                <template v-else>
                                    <span class="text-gray-500">Estimate unavailable</span>
                                </template>
                                <template v-if="g.status === 'succeeded' && g.charge != null">
                                    <span class="mx-1.5 text-gray-300">·</span>
                                    Charged
                                    <span class="font-semibold tabular-nums">${{ g.charge }}</span>
                                    <span class="text-gray-500">(provider ${{ g.provider_cost }})</span>
                                </template>
                            </p>
                        </div>
                        <img
                            v-if="g.status === 'succeeded' && g.image_url"
                            :src="g.image_url"
                            alt="Generated image"
                            class="max-h-80 w-full object-contain bg-gray-50"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
