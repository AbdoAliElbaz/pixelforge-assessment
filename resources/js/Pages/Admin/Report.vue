<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    rows: Array,
    reconciliation: Array,
    window: Object,
});

const totals = computed(() => {
    const seed = { generations: 0, charged: 0, cost: 0, profit: 0 };
    return (props.rows ?? []).reduce((acc, r) => {
        acc.generations += Number(r.generations) || 0;
        acc.charged += Number(r.charged) || 0;
        acc.cost += Number(r.cost) || 0;
        acc.profit += Number(r.profit) || 0;
        return acc;
    }, seed);
});

/** Display helper for summed USD amounts (summary strip only). */
function formatMoney(value) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return '0';
    }
    return n.toFixed(9).replace(/\.?0+$/, '') || '0';
}

function delta(ourCost, pixelforgeCost) {
    const ours = Number(ourCost);
    const theirs = Number(pixelforgeCost);
    if (Number.isNaN(ours) || Number.isNaN(theirs)) {
        return null;
    }
    const diff = ours - theirs;
    const formatted = formatMoney(diff);
    return diff > 0 ? `+${formatted}` : formatted;
}
</script>

<template>
    <Head title="Admin report" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Costs and profit</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-8 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-800">
                        Per day and user (UTC, {{ window.start }} to {{ window.end }})
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Credit spend is what users were charged; provider cost is what Pixelforge charged us; profit is
                        the 25% markup. Only succeeded generations appear: failed ones are free.
                    </p>

                    <div
                        v-if="rows.length"
                        class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4"
                    >
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Generations</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900">{{ totals.generations }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Charged</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900">${{ formatMoney(totals.charged) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Provider cost</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900">${{ formatMoney(totals.cost) }}</p>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Profit</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-emerald-700">${{ formatMoney(totals.profit) }}</p>
                        </div>
                    </div>

                    <div v-if="rows.length" class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <thead class="sticky top-0 bg-white">
                                <tr class="border-b border-gray-200 text-gray-500">
                                    <th class="py-2 pr-4 font-medium">Day</th>
                                    <th class="py-2 pr-4 font-medium">User</th>
                                    <th class="py-2 pr-4 text-right font-medium">Generations</th>
                                    <th class="py-2 pr-4 text-right font-medium">Charged</th>
                                    <th class="py-2 pr-4 text-right font-medium">Provider cost</th>
                                    <th class="py-2 text-right font-medium">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in rows"
                                    :key="r.day + r.user"
                                    class="border-b border-gray-100 last:border-0 odd:bg-white even:bg-gray-50/80 hover:bg-indigo-50/40"
                                >
                                    <td class="whitespace-nowrap py-2.5 pr-4 text-gray-700">{{ r.day }}</td>
                                    <td class="py-2.5 pr-4 text-gray-900">{{ r.user }}</td>
                                    <td class="py-2.5 pr-4 text-right tabular-nums text-gray-700">{{ r.generations }}</td>
                                    <td class="py-2.5 pr-4 text-right tabular-nums text-gray-900">${{ r.charged }}</td>
                                    <td class="py-2.5 pr-4 text-right tabular-nums text-gray-700">${{ r.cost }}</td>
                                    <td class="py-2.5 text-right tabular-nums font-medium text-emerald-700">${{ r.profit }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-4 text-sm text-gray-500">No succeeded generations in this window yet.</p>
                </div>

                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-800">Reconciliation with Pixelforge (GET /v1/usage)</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Pixelforge bills a generation on the UTC day it completes, so both sides group by completion day.
                    </p>
                    <div v-if="reconciliation" class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-left text-sm">
                            <thead class="sticky top-0 bg-white">
                                <tr class="border-b border-gray-200 text-gray-500">
                                    <th class="py-2 pr-4 font-medium">Day</th>
                                    <th class="py-2 pr-4 text-right font-medium">Pixelforge generations</th>
                                    <th class="py-2 pr-4 text-right font-medium">Pixelforge cost</th>
                                    <th class="py-2 pr-4 text-right font-medium">Our record</th>
                                    <th class="py-2 text-right font-medium">Match</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in reconciliation"
                                    :key="r.day"
                                    class="border-b border-gray-100 last:border-0 odd:bg-white even:bg-gray-50/80 hover:bg-indigo-50/40"
                                >
                                    <td class="whitespace-nowrap py-2.5 pr-4 text-gray-700">{{ r.day }}</td>
                                    <td class="py-2.5 pr-4 text-right tabular-nums text-gray-700">{{ r.generations }}</td>
                                    <td class="py-2.5 pr-4 text-right tabular-nums text-gray-700">${{ r.pixelforge_cost }}</td>
                                    <td class="py-2.5 pr-4 text-right tabular-nums text-gray-900">${{ r.our_cost }}</td>
                                    <td class="py-2.5 text-right">
                                        <span
                                            v-if="r.matches"
                                            class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-200"
                                        >
                                            match
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex flex-col items-end gap-0.5"
                                        >
                                            <span
                                                class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-200"
                                            >
                                                mismatch
                                            </span>
                                            <span
                                                v-if="delta(r.our_cost, r.pixelforge_cost) !== null"
                                                class="text-[11px] tabular-nums text-red-600"
                                            >
                                                Δ {{ delta(r.our_cost, r.pixelforge_cost) }} (ours − theirs)
                                            </span>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Reconciliation unavailable: set <code class="rounded bg-amber-100 px-1">PIXELFORGE_API_KEY</code>
                        in .env. The table above still works from our own records.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
