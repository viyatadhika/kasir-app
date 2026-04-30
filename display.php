<?php
$dataFile = __DIR__ . '/customer_display.json';

$defaultData = [
    'mode' => 'cart',
    'items' => [],
    'subtotal' => 0,
    'diskon' => 0,
    'total' => 0,
    'member' => null,
    'updated_at' => date('Y-m-d H:i:s')
];

if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode($defaultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Customer Display - Koperasi BSDK</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #fcfcfc;
            color: #1a1a1a;
            overflow: hidden;
        }

        .border-subtle {
            border-color: #f0f0f0;
        }

        .soft-card {
            background: #ffffff;
            border: 1px solid #f0f0f0;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .04);
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .label {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .18em;
            color: #9ca3af;
        }

        .mono {
            font-variant-numeric: tabular-nums;
        }

        .total-box {
            background: #000;
            color: #fff;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #9ca3af;
            display: inline-block;
            transition: background .2s ease, box-shadow .2s ease;
        }

        .status-dot.active {
            background: #16a34a;
            box-shadow: 0 0 0 5px rgba(22, 163, 74, .12);
        }

        .item-row.new-item {
            animation: fadeItem .22s ease-out;
        }

        @keyframes fadeItem {
            from {
                opacity: 0;
                transform: translateY(6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .thank-panel {
            animation: thankIn .28s ease-out;
        }

        @keyframes thankIn {
            from {
                opacity: 0;
                transform: scale(.98);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @media (max-width: 900px) {
            body {
                overflow: auto;
            }

            .display-main {
                grid-template-columns: 1fr !important;
            }

            .display-page {
                min-height: 100vh;
                height: auto !important;
            }
        }
    </style>
</head>

<body>
    <div class="display-page h-screen flex flex-col">

        <header class="bg-white border-b border-subtle px-8 py-5 flex justify-between items-center">
            <div>
                <div class="flex items-center gap-3">
                    <span class="text-sm font-black tracking-tighter border-b-2 border-black pb-1">KOPERASI BSDK</span>
                    <span class="label">Customer Display</span>
                </div>
                <h1 class="mt-3 text-2xl font-black tracking-[0.22em] uppercase">Daftar Belanja Anda</h1>
            </div>

            <div class="text-right">
                <div id="jam" class="text-2xl font-black mono">00:00:00</div>
                <div class="mt-1 flex items-center justify-end gap-2">
                    <span id="statusDot" class="status-dot"></span>
                    <span id="status" class="label">Menunggu transaksi</span>
                </div>
            </div>
        </header>

        <main id="mainCart" class="display-main flex-1 min-h-0 grid grid-cols-[1fr_420px] gap-6 p-6 bg-gray-50/50">
            <section class="soft-card flex flex-col min-h-0">
                <div class="px-6 py-4 border-b border-subtle grid grid-cols-12 gap-3">
                    <div class="col-span-5 label">Produk</div>
                    <div class="col-span-2 label text-right">Qty</div>
                    <div class="col-span-2 label text-right">Harga</div>
                    <div class="col-span-3 label text-right">Subtotal</div>
                </div>

                <div id="items" class="flex-1 min-h-0 overflow-y-auto no-scrollbar divide-y divide-gray-100"></div>
            </section>

            <aside class="flex flex-col gap-4 min-h-0">
                <div class="soft-card p-6">
                    <div class="label">Subtotal</div>
                    <div id="subtotal" class="mt-2 text-3xl font-black mono">Rp 0</div>
                </div>

                <div id="diskonBox" class="soft-card p-6 hidden">
                    <div class="label">Diskon</div>
                    <div id="diskon" class="mt-2 text-3xl font-black text-red-600 mono">-Rp 0</div>
                </div>

                <div class="total-box p-7">
                    <div class="text-[10px] font-black uppercase tracking-[0.22em] text-gray-300">Total Bayar</div>
                    <div id="total" class="mt-3 text-5xl font-black mono">Rp 0</div>
                </div>

                <div id="memberBox" class="soft-card p-6 hidden">
                    <div class="label">Member</div>
                    <div id="memberNama" class="mt-2 text-2xl font-black uppercase">-</div>
                    <div class="mt-4 flex justify-between items-center border-t border-subtle pt-4">
                        <span class="label">Point Didapat</span>
                        <span id="memberPoint" class="text-xl font-black text-blue-600 mono">+0 pt</span>
                    </div>
                </div>

                <div class="soft-card p-6 mt-auto">
                    <div class="text-center">
                        <div class="text-sm font-black uppercase tracking-[0.22em]">Terima Kasih</div>
                        <div class="mt-2 text-xs text-gray-400 font-semibold">Silakan cek daftar belanja sebelum pembayaran</div>
                    </div>
                </div>
            </aside>
        </main>

        <main id="thankYouView" class="hidden flex-1 bg-gray-50/50 p-6">
            <div class="thank-panel soft-card w-full h-full flex items-center justify-center text-center">
                <div class="max-w-3xl px-8">
                    <div class="mx-auto w-24 h-24 rounded-full bg-green-50 border border-green-200 flex items-center justify-center mb-8">
                        <svg class="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>

                    <div class="label mb-3">Transaksi Berhasil</div>
                    <h2 class="text-5xl font-black tracking-[0.18em] uppercase mb-5">Terima Kasih</h2>
                    <p class="text-gray-400 font-semibold mb-8">Belanja Anda sudah selesai. Silakan ambil struk dan barang belanjaan.</p>

                    <div class="grid grid-cols-2 gap-4 text-left mb-6">
                        <div class="bg-gray-50 border border-subtle p-5">
                            <div class="label">Invoice</div>
                            <div id="thanksInvoice" class="mt-2 text-xl font-black mono">-</div>
                        </div>
                        <div class="bg-gray-50 border border-subtle p-5">
                            <div class="label">Metode Bayar</div>
                            <div id="thanksMetode" class="mt-2 text-xl font-black uppercase">-</div>
                        </div>
                    </div>

                    <div class="bg-black text-white p-7 mb-4">
                        <div class="text-[10px] font-black uppercase tracking-[0.22em] text-gray-300">Total Bayar</div>
                        <div id="thanksTotal" class="mt-3 text-6xl font-black mono">Rp 0</div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 text-left">
                        <div class="bg-gray-50 border border-subtle p-5">
                            <div class="label">Diskon</div>
                            <div id="thanksDiskon" class="mt-2 text-xl font-black text-red-600 mono">Rp 0</div>
                        </div>
                        <div class="bg-gray-50 border border-subtle p-5">
                            <div class="label">Bayar</div>
                            <div id="thanksBayar" class="mt-2 text-xl font-black mono">Rp 0</div>
                        </div>
                        <div class="bg-gray-50 border border-subtle p-5">
                            <div class="label">Kembalian</div>
                            <div id="thanksKembalian" class="mt-2 text-xl font-black text-green-600 mono">Rp 0</div>
                        </div>
                    </div>

                    <div id="thanksMemberBox" class="hidden mt-5 bg-blue-50 border border-blue-200 p-5 text-left">
                        <div class="label text-blue-400">Point Member</div>
                        <div class="mt-2 flex justify-between items-center">
                            <div id="thanksMemberNama" class="text-xl font-black uppercase text-blue-700">-</div>
                            <div id="thanksPoint" class="text-xl font-black text-blue-700 mono">+0 pt</div>
                        </div>
                    </div>

                    <div class="mt-8 label">Layar akan kembali kosong otomatis</div>
                </div>
            </div>
        </main>
    </div>

    <script>
        const THANK_YOU_DURATION = 10000;
        const POS_STALE_TIMEOUT = 15000;

        let lastPayload = '';
        let lastItemsPayload = '';
        let lastSummaryPayload = '';
        let lastMode = 'cart';
        let lastUpdateMs = Date.now();
        let failedCount = 0;
        let thankYouTimer = null;

        function formatRp(n) {
            return 'Rp ' + Math.floor(Number(n || 0)).toLocaleString('id-ID');
        }

        function updateJam() {
            document.getElementById('jam').innerText = new Date().toLocaleTimeString('id-ID', {
                hour12: false
            });
        }

        function escapeHtml(str) {
            return String(str)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", "&#039;");
        }

        function emptyView() {
            return `
                <div class="h-full flex items-center justify-center text-center text-gray-300">
                    <div>
                        <div class="text-6xl mb-5">🛒</div>
                        <div class="text-xl font-black uppercase tracking-[0.18em] text-gray-400">Keranjang Kosong</div>
                        <div class="text-xs mt-3 font-bold uppercase tracking-widest text-gray-300">Belanjaan akan tampil di sini</div>
                    </div>
                </div>
            `;
        }

        function setStatus(active, text) {
            const status = document.getElementById('status');
            const dot = document.getElementById('statusDot');
            status.innerText = text || (active ? 'Transaksi berjalan' : 'Menunggu transaksi');
            if (active) dot.classList.add('active');
            else dot.classList.remove('active');
        }

        function clearThankYouTimer() {
            if (thankYouTimer) {
                clearTimeout(thankYouTimer);
                thankYouTimer = null;
            }
        }

        function showCartView() {
            clearThankYouTimer();
            document.getElementById('mainCart').classList.remove('hidden');
            document.getElementById('thankYouView').classList.add('hidden');
        }

        function showThankYouView(d) {
            document.getElementById('mainCart').classList.add('hidden');
            document.getElementById('thankYouView').classList.remove('hidden');

            setStatus(true, 'Transaksi berhasil');

            document.getElementById('thanksInvoice').innerText = d.invoice || '-';
            document.getElementById('thanksMetode').innerText = d.metode || '-';
            document.getElementById('thanksTotal').innerText = formatRp(d.total || 0);
            document.getElementById('thanksDiskon').innerText = '-' + formatRp(d.diskon || 0);
            document.getElementById('thanksBayar').innerText = formatRp(d.bayar || 0);
            document.getElementById('thanksKembalian').innerText = formatRp(d.kembalian || 0);

            const memberBox = document.getElementById('thanksMemberBox');
            if (d.member && d.member.nama) {
                memberBox.classList.remove('hidden');
                document.getElementById('thanksMemberNama').innerText = d.member.nama;
                document.getElementById('thanksPoint').innerText = '+' + Number(d.member.point || 0).toLocaleString('id-ID') + ' pt';
            } else {
                memberBox.classList.add('hidden');
            }

            clearThankYouTimer();
            thankYouTimer = setTimeout(() => {
                clearDisplay();
            }, THANK_YOU_DURATION);
        }

        function clearDisplay(text = 'Menunggu transaksi') {
            showCartView();
            lastItemsPayload = '';
            lastSummaryPayload = '';
            lastMode = 'cart';
            document.getElementById('items').innerHTML = emptyView();
            document.getElementById('subtotal').innerText = 'Rp 0';
            document.getElementById('total').innerText = 'Rp 0';
            document.getElementById('diskonBox').classList.add('hidden');
            document.getElementById('memberBox').classList.add('hidden');
            setStatus(false, text);
        }

        function renderItems(items) {
            showCartView();

            const box = document.getElementById('items');
            const itemsPayload = JSON.stringify(items);

            if (itemsPayload === lastItemsPayload) return;
            lastItemsPayload = itemsPayload;

            if (!items.length) {
                box.innerHTML = emptyView();
                setStatus(false);
                return;
            }

            setStatus(true);

            box.innerHTML = items.map(i => {
                const diskon = Number(i.diskon || 0);
                const harga = Number(i.harga || 0);
                const qty = Number(i.qty || 0);
                const subtotal = Number(i.subtotal || 0);

                return `
                    <div class="item-row new-item grid grid-cols-12 gap-3 px-6 py-5 items-center">
                        <div class="col-span-5 min-w-0">
                            <div class="text-lg font-black uppercase leading-tight truncate">${escapeHtml(i.nama || '-')}</div>
                            ${diskon > 0
                                ? `<div class="mt-1 text-[10px] font-black uppercase tracking-widest text-red-600">Diskon -${formatRp(diskon)}</div>`
                                : `<div class="mt-1 text-[10px] font-bold uppercase tracking-widest text-gray-300">Harga normal</div>`
                            }
                        </div>
                        <div class="col-span-2 text-right text-2xl font-black mono">${qty}</div>
                        <div class="col-span-2 text-right text-base font-bold mono">${formatRp(harga)}</div>
                        <div class="col-span-3 text-right text-2xl font-black mono">${formatRp(subtotal)}</div>
                    </div>
                `;
            }).join('');

            box.scrollTop = box.scrollHeight;
        }

        function renderSummary(d) {
            const summaryPayload = JSON.stringify({
                subtotal: d.subtotal || 0,
                diskon: d.diskon || 0,
                total: d.total || 0,
                member: d.member || null
            });

            if (summaryPayload === lastSummaryPayload) return;
            lastSummaryPayload = summaryPayload;

            const subtotal = Number(d.subtotal || 0);
            const diskon = Number(d.diskon || 0);
            const total = Number(d.total || 0);

            document.getElementById('subtotal').innerText = formatRp(subtotal);
            document.getElementById('total').innerText = formatRp(total);

            const diskonBox = document.getElementById('diskonBox');
            if (diskon > 0) {
                diskonBox.classList.remove('hidden');
                document.getElementById('diskon').innerText = '-' + formatRp(diskon);
            } else {
                diskonBox.classList.add('hidden');
            }

            const memberBox = document.getElementById('memberBox');
            if (d.member && d.member.nama) {
                memberBox.classList.remove('hidden');
                document.getElementById('memberNama').innerText = d.member.nama;
                document.getElementById('memberPoint').innerText =
                    '+' + Number(d.member.point || 0).toLocaleString('id-ID') + ' pt';
            } else {
                memberBox.classList.add('hidden');
            }
        }

        async function loadData() {
            try {
                const res = await fetch('customer_display.json?_=' + Date.now(), {
                    cache: 'no-store'
                });
                const text = await res.text();

                if (text === lastPayload) {
                    failedCount = 0;
                    return;
                }

                const previousMode = lastMode;
                lastPayload = text;
                const d = JSON.parse(text);
                const mode = d.mode || 'cart';
                lastMode = mode;
                lastUpdateMs = Date.now();

                if (mode === 'thank_you') {
                    showThankYouView(d);
                } else {
                    if (previousMode === 'thank_you') {
                        clearThankYouTimer();
                    }

                    const items = Array.isArray(d.items) ? d.items : [];
                    renderItems(items);
                    renderSummary(d);
                }

                failedCount = 0;
            } catch (e) {
                failedCount++;
                if (failedCount > 3) clearDisplay('Display belum terhubung');
            }
        }

        function checkStale() {
            const idleTooLong = Date.now() - lastUpdateMs > POS_STALE_TIMEOUT;
            if (idleTooLong && lastMode !== 'thank_you') {
                clearDisplay('POS tidak aktif');
                lastPayload = '';
            }
        }

        updateJam();
        setInterval(updateJam, 1000);

        clearDisplay();
        loadData();

        setInterval(loadData, 1000);
        setInterval(checkStale, 2000);
    </script>
</body>

</html>