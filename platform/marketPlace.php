<?php include __DIR__ . "/header.php"; ?>
<section class="pricing-hero">
    <div class="container">
        <span class="pricing-badge">
            MARKETPLACE
        </span>
        <h1 class="pricing-title mt-4">
            Built for how a sari-sari store actually trades
        </h1>
        <p class="pricing-description mt-4">
            Built for the sari-sari store first: one counter, one owner,
            and no IT staff to call.
        </p>
    </div>
</section>

<?php
/*
| One card, for the store this was built for.
|
| There were two here and they were the same card: both titled
| "Convenience Stores", with the same subtitle, the same five modules, the
| same three bullets and the same 42-store anecdote. A copy that was never
| edited. The hero promised "a 24-hour convenience store and a 12,000-SKU
| supermarket", which no card on the page delivered.
|
| A sari-sari store is the customer: one owner, no IT staff, one counter.
| The claims below are things this system actually does -- low stock
| alerts, face attendance, a single screen -- rather than a chain-store
| statistic nobody here can stand behind.
|
| A PHP comment and not an HTML one, which is the difference between a note
| to the next developer and a note shipped to every prospect who opens view
| source.
*/
?>
<section class="bg-slate-50 py-16">
    <div class="mx-auto max-w-4xl px-6">
        <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm transition hover:shadow-lg">

            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <h3 class="text-2xl font-semibold text-slate-900">
                        Sari-Sari Stores
                    </h3>
                    <p class="mt-2 text-sm text-slate-500">
                        The neighbourhood store, run by the family that owns it.
                    </p>
                </div>
                <span class="rounded-full bg-orange-100 px-4 py-1 text-xs font-medium text-orange-600">
                    One counter, no IT staff
                </span>
            </div>

            <div class="mt-8">
                <p class="mb-3 text-[11px] font-semibold uppercase tracking-[0.25em] text-slate-400">
                    Modules Used
                </p>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full border bg-slate-100 px-3 py-1 text-xs">POS</span>
                    <span class="rounded-full border bg-slate-100 px-3 py-1 text-xs">Inventory</span>
                    <span class="rounded-full border bg-slate-100 px-3 py-1 text-xs">Suppliers</span>
                    <span class="rounded-full border bg-slate-100 px-3 py-1 text-xs">Attendance</span>
                    <span class="rounded-full border bg-slate-100 px-3 py-1 text-xs">Promotions</span>
                </div>
            </div>

            <ul class="mt-8 space-y-3 text-sm text-slate-700">
                <li class="flex gap-3">
                    <svg class="mt-0.5 h-5 w-5 text-blue-500" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path d="M5 12l5 5L20 7" />
                    </svg>
                    <span>Low stock alerts on the items that actually move</span>
                </li>
                <li class="flex gap-3">
                    <svg class="mt-0.5 h-5 w-5 text-blue-500" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path d="M5 12l5 5L20 7" />
                    </svg>
                    <span>Time in by face, so a helper's hours are not an argument</span>
                </li>
                <li class="flex gap-3">
                    <svg class="mt-0.5 h-5 w-5 text-blue-500" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24">
                        <path d="M5 12l5 5L20 7" />
                    </svg>
                    <span>One owner, one screen, and nobody to call IT about</span>
                </li>
            </ul>

            <div
                class="mt-8 rounded-2xl border-l-4 border-blue-500 bg-blue-50 p-5 text-sm leading-6 text-slate-700">
                Starts at one branch and up to ten staff. The plan grows when the
                store does -- nothing here assumes a head office.
            </div>

            <a href="/platform/pricing.php"
                class="mt-8 inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-medium transition hover:bg-slate-900 hover:text-white">
                See what it costs
                <span>&rarr;</span>
            </a>

        </div>
    </div>
</section>
<section class="py-24 bg-[#F1F5F9]">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span
                class="inline-block px-4 py-1 rounded-full border border-sky-300 bg-sky-100 text-sky-600 text-xs tracking-[3px] uppercase">
                Not sure where you fit?
            </span>
            <h2 class="text-5xl font-bold text-slate-900 mt-5">
                Most networks start with POS and inventory, then add HR and payroll
            </h2>
            <p class="text-gray-500 text-lg mt-5 max-w-3xl mx-auto leading-8">
                Our implementation team maps your branch structure before anything is configured.
            </p>
        </div>
    </div>
</section>
<?php
include __DIR__ . "/footer.php";
?>