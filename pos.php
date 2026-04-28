<?php
require_once 'config.php';

// ── API Handler (AJAX requests) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
  header('Content-Type: application/json');

  switch ($_GET['action']) {

    // Ambil semua produk aktif
    case 'get_products':
      $stmt = $pdo->query("
                SELECT id, kode, nama, kategori, harga_jual, stok, satuan
                FROM produk
                WHERE status = 'aktif'
                ORDER BY kategori, nama
            ");
      echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
      exit;

      // Simpan transaksi ke database
    case 'simpan_transaksi':
      $input = json_decode(file_get_contents('php://input'), true);

      if (empty($input['items']) || !is_array($input['items'])) {
        echo json_encode(['success' => false, 'message' => 'Keranjang kosong.']);
        exit;
      }

      $userId  = $_SESSION['user_id'] ?? null;
      $invoice = generateInvoice();
      $items   = $input['items'];
      $bayar   = (int)($input['bayar'] ?? 0);

      // Hitung total dari server (tidak percaya klien)
      $subtotal  = 0;
      $produkIds = array_column($items, 'id');

      if (empty($produkIds)) {
        echo json_encode(['success' => false, 'message' => 'Data produk tidak valid.']);
        exit;
      }

      $placeholders = implode(',', array_fill(0, count($produkIds), '?'));
      $stmtCheck = $pdo->prepare("
                SELECT id, kode, nama, harga_jual, stok FROM produk
                WHERE id IN ($placeholders) AND status = 'aktif'
            ");
      $stmtCheck->execute($produkIds);
      $produkMap = [];
      foreach ($stmtCheck->fetchAll() as $p) {
        $produkMap[$p['id']] = $p;
      }

      // Validasi stok & hitung subtotal
      foreach ($items as $item) {
        $pid = (int)$item['id'];
        $qty = (int)$item['qty'];

        if (!isset($produkMap[$pid])) {
          echo json_encode(['success' => false, 'message' => "Produk ID $pid tidak ditemukan."]);
          exit;
        }
        if ($qty <= 0) {
          echo json_encode(['success' => false, 'message' => "Qty tidak valid untuk produk {$produkMap[$pid]['nama']}."]);
          exit;
        }
        if ($produkMap[$pid]['stok'] < $qty) {
          echo json_encode([
            'success' => false,
            'message' => "Stok {$produkMap[$pid]['nama']} tidak cukup (stok: {$produkMap[$pid]['stok']})."
          ]);
          exit;
        }
        $subtotal += $produkMap[$pid]['harga_jual'] * $qty;
      }

      // Pajak 11%
      $pajak     = (int)round($subtotal * 0.11);
      $total     = $subtotal + $pajak;
      $kembalian = max(0, $bayar - $total);

      if ($bayar > 0 && $bayar < $total) {
        echo json_encode(['success' => false, 'message' => 'Uang bayar kurang dari total tagihan.']);
        exit;
      }

      try {
        $pdo->beginTransaction();

        // Insert transaksi header
        $stmtTrans = $pdo->prepare("
                    INSERT INTO transaksi (invoice, user_id, total, bayar, kembalian, catatan)
                    VALUES (:invoice, :user_id, :total, :bayar, :kembalian, :catatan)
                ");
        $stmtTrans->execute([
          ':invoice'   => $invoice,
          ':user_id'   => $userId,
          ':total'     => $total,
          ':bayar'     => $bayar,
          ':kembalian' => $kembalian,
          ':catatan'   => $input['catatan'] ?? null,
        ]);
        $transaksiId = $pdo->lastInsertId();

        // Insert detail & kurangi stok
        $stmtDetail = $pdo->prepare("
                    INSERT INTO transaksi_detail (transaksi_id, produk_id, kode, nama, harga, qty, subtotal)
                    VALUES (:transaksi_id, :produk_id, :kode, :nama, :harga, :qty, :subtotal)
                ");
        $stmtStokUp = $pdo->prepare("
                    UPDATE produk SET stok = stok - :qty, updated_at = NOW()
                    WHERE id = :id
                ");

        foreach ($items as $item) {
          $pid  = (int)$item['id'];
          $qty  = (int)$item['qty'];
          $p    = $produkMap[$pid];
          $sub  = $p['harga_jual'] * $qty;

          $stmtDetail->execute([
            ':transaksi_id' => $transaksiId,
            ':produk_id'    => $pid,
            ':kode'         => $p['kode'],
            ':nama'         => $p['nama'],
            ':harga'        => $p['harga_jual'],
            ':qty'          => $qty,
            ':subtotal'     => $sub,
          ]);

          $stmtStokUp->execute([':qty' => $qty, ':id' => $pid]);
        }

        $pdo->commit();

        echo json_encode([
          'success'   => true,
          'invoice'   => $invoice,
          'total'     => $total,
          'subtotal'  => $subtotal,
          'pajak'     => $pajak,
          'bayar'     => $bayar,
          'kembalian' => $kembalian,
          'message'   => 'Transaksi berhasil disimpan.',
        ]);
      } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
      }
      exit;

    default:
      echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
      exit;
  }
}

// ── Ambil kategori unik untuk filter ────────────────────────────────────────
$stmtKat = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE status = 'aktif' ORDER BY kategori");
$kategoriList = $stmtKat->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>POS – Mesin Kasir</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background-color: #fcfcfc;
      color: #1a1a1a;
    }

    .border-subtle {
      border-color: #f0f0f0;
    }

    .no-scrollbar::-webkit-scrollbar {
      display: none;
    }

    .no-scrollbar {
      -ms-overflow-style: none;
      scrollbar-width: none;
    }

    .product-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: 12px;
    }

    .cart-item-anim {
      animation: slideIn 0.2s ease-out;
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .spinner {
      border: 3px solid #f0f0f0;
      border-top-color: #2563eb;
      border-radius: 50%;
      width: 24px;
      height: 24px;
      animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    button,
    .btn,
    .category-btn,
    #btn-struk,
    header a {
      border-radius: 2px !important;
      font-size: 11px !important;
      font-weight: 800 !important;
      text-transform: uppercase !important;
      letter-spacing: 0.08em !important;
      transition: all 0.15s ease !important;
    }

    button.bg-black {
      background: #000 !important;
      color: #fff !important;
      border-color: #000 !important;
    }

    button.bg-black:hover {
      background: #1f1f1f !important;
      border-color: #1f1f1f !important;
    }

    button.border:not(.active-category),
    #btn-struk {
      background: #fff !important;
    }

    button.border:not(.active-category):hover,
    #btn-struk:hover {
      background: #f9f9f9 !important;
    }

    button.p-2,
    button.w-5 {
      border-radius: 2px !important;
    }

    button:hover,
    #btn-struk:hover,
    header a:hover {
      transform: translateY(-1px);
    }

    button:active {
      transform: translateY(0) scale(0.98);
    }

    .category-btn.active-category,
    button.category-btn.active-category,
    button.border.category-btn.active-category {
      background-color: #000 !important;
      color: #fff !important;
      border-color: #000 !important;
    }

    .category-btn.active-category:hover,
    button.category-btn.active-category:hover,
    button.border.category-btn.active-category:hover {
      background-color: #000 !important;
      color: #fff !important;
      border-color: #000 !important;
    }

    #mobileMenuOverlay {
      transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    #mobileMenuContent {
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    @media (min-width: 1024px) {
      .sidebar {
        width: 220px;
      }

      .content {
        margin-left: 220px;
      }
    }
  </style>
</head>

<body class="antialiased min-h-screen lg:h-screen flex flex-col overflow-x-hidden lg:overflow-hidden pb-20 lg:pb-0">

  <!-- Mobile Menu Overlay -->
  <div id="mobileMenuOverlay" class="fixed inset-0 bg-black/50 z-[300] opacity-0 invisible flex justify-end lg:hidden">
    <div id="mobileMenuContent" class="w-72 bg-white h-full p-8 translate-x-full shadow-2xl flex flex-col">
      <div class="flex justify-between items-center mb-10">
        <span class="text-xs font-bold tracking-widest uppercase">Navigasi</span>
        <button onclick="toggleMobileMenu()" class="p-2 -mr-2 hover:bg-gray-100 rounded-sm transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
      <nav class="space-y-8 flex-1">
        <a href="index.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
        <a href="pos.php" class="block text-sm font-medium text-blue-600 uppercase tracking-widest">Mesin Kasir (POS)</a>
        <!-- <a href="#" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Laporan Shift</a> -->
        <a href="produk.php" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
        <!-- <a href="#" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Stok Opname</a>
        <a href="#" class="block text-sm font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Pengaturan Toko</a> -->
        <!-- LOGOUT -->
        <a href="logout.php"
          onclick="return confirm('Yakin mau logout?')"
          class="block text-sm font-bold text-red-500 uppercase tracking-widest">
          Logout
        </a>
      </nav>
      <div class="pt-8 border-t border-subtle">
        <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
        <p class="text-[10px] text-gray-400 font-medium">Login: <?= htmlspecialchars($_SESSION['nama']) ?></p>
      </div>
    </div>
  </div>

  <!-- Desktop Sidebar -->
  <aside class="sidebar hidden lg:flex flex-col fixed inset-y-0 left-0 border-r border-subtle bg-white p-8 z-30">
    <div class="mb-12">
      <span class="text-sm font-bold tracking-tighter border-b-2 border-black pb-1">BSDK SEJAHTERA</span>
    </div>
    <nav class="flex-1 space-y-6">
      <a href="index.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Dashboard</a>
      <a href="pos.php" class="block text-xs font-semibold text-black uppercase tracking-widest flex items-center gap-2">
        <span class="w-2 h-2 bg-black rounded-full"></span>
        Mesin Kasir (POS)
      </a>
      <!-- <a href="#" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Laporan Shift</a> -->
      <a href="produk.php" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Kelola Produk</a>
      <!-- <a href="#" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Stok Opname</a>
      <a href="#" class="block text-xs font-medium text-gray-400 hover:text-black uppercase tracking-widest transition-colors">Pengaturan Toko</a> -->
    </nav>
    <div class="mt-auto">
      <p class="text-[10px] text-gray-400 font-medium uppercase">ID Toko: T042 - BOGOR</p>
      <p class="text-[10px] text-gray-400 font-medium">v 2.4.0</p>

      <!-- LOGOUT -->
      <a href="logout.php"
        onclick="return confirm('Yakin mau logout?')"
        class="block mt-4 text-[10px] text-red-500 hover:text-red-700 uppercase font-bold tracking-widest">
        Logout
      </a>
    </div>
  </aside>

  <!-- Header POS -->
  <header class="content bg-white border-b border-subtle px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center z-10 shadow-sm sticky top-0 lg:relative">
    <div class="flex items-center gap-3 sm:gap-4">
      <a href="index.php" class="p-2 hover:bg-gray-100 rounded-sm transition-colors group">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 group-hover:-translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
      </a>
      <h1 class="text-sm font-bold tracking-[0.2em] uppercase">Mesin Kasir</h1>
    </div>
    <div class="flex items-center gap-3 sm:gap-6">
      <div class="text-right hidden md:block border-r border-subtle pr-6">
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Operator</p>
        <p class="text-xs font-semibold uppercase"><?= htmlspecialchars($_SESSION['nama']) ?></p>
      </div>
      <div id="clock" class="text-sm font-bold tabular-nums">00:00:00</div>
    </div>
  </header>

  <!-- Main Layout -->
  <div class="content flex-1 flex flex-col lg:flex-row overflow-visible lg:overflow-hidden">

    <!-- Kiri: Produk -->
    <div class="flex-1 flex flex-col bg-gray-50/50 border-b lg:border-b-0 lg:border-r border-subtle min-h-0">
      <!-- Search & Filter -->
      <div class="p-4 sm:p-6 bg-white border-b border-subtle space-y-4">
        <div class="relative">
          <span class="absolute inset-y-0 left-4 flex items-center text-gray-400">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
          </span>
          <input type="text" id="search-input" oninput="filterProducts()"
            placeholder="Cari nama produk atau scan barcode..."
            class="w-full bg-gray-50 border border-gray-100 text-sm py-3 sm:py-4 pl-12 pr-4 rounded-sm focus:outline-none focus:ring-2 focus:ring-black/5 transition-all">
        </div>
        <div class="flex gap-3 overflow-x-auto no-scrollbar" id="category-filters">
          <button onclick="setCategory('Semua')" class="category-btn active-category px-6 py-2 bg-white border border-subtle text-gray-400 text-[10px] font-bold uppercase tracking-widest rounded-sm hover:border-black hover:text-black transition-colors">
            Semua
          </button>
          <?php foreach ($kategoriList as $kat): ?>
            <button onclick="setCategory('<?= htmlspecialchars($kat, ENT_QUOTES) ?>')"
              class="category-btn px-6 py-2 bg-white border border-subtle text-gray-400 text-[10px] font-bold uppercase tracking-widest rounded-sm hover:border-black hover:text-black transition-colors">
              <?= htmlspecialchars($kat) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- List Produk -->
      <div class="flex-1 overflow-y-visible lg:overflow-y-auto p-4 sm:p-6 no-scrollbar">
        <div class="product-grid" id="product-list">
          <div class="col-span-full flex justify-center py-20">
            <div class="spinner"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Kanan: Keranjang -->
    <div class="w-full lg:w-[420px] bg-white flex flex-col shadow-none lg:shadow-2xl z-20 max-h-none lg:max-h-full">
      <div class="p-4 sm:p-6 border-b border-subtle flex justify-between items-center bg-gray-50/30">
        <h2 class="text-xs font-black uppercase tracking-[0.2em]">Daftar Belanja</h2>
        <button onclick="clearCart()" class="text-[10px] text-red-500 font-bold uppercase tracking-widest hover:underline">Reset</button>
      </div>

      <div class="min-h-[260px] lg:flex-1 overflow-y-visible lg:overflow-y-auto p-4 space-y-4 no-scrollbar" id="cart-container">
        <div id="empty-cart" class="h-full flex flex-col items-center justify-center text-center opacity-30">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
          </svg>
          <p class="text-xs font-bold uppercase tracking-widest">Keranjang Kosong</p>
        </div>
      </div>

      <!-- Ringkasan -->
      <div class="p-4 sm:p-6 border-t border-subtle bg-white space-y-4 sticky bottom-20 lg:static shadow-[0_-8px_24px_rgba(0,0,0,0.04)] lg:shadow-none">
        <div class="space-y-2">
          <div class="flex justify-between text-[11px] font-medium text-gray-400 uppercase tracking-widest">
            <span>Subtotal</span><span id="subtotal">Rp 0</span>
          </div>
          <div class="flex justify-between text-[11px] font-medium text-gray-400 uppercase tracking-widest">
            <span>Pajak (11%)</span><span id="tax">Rp 0</span>
          </div>
        </div>
        <div class="flex justify-between items-end py-2">
          <span class="text-xs font-black uppercase tracking-[0.2em]">Total</span>
          <span class="text-2xl font-bold text-blue-600" id="total-price">Rp 0</span>
        </div>
        <button onclick="openPayment()"
          class="w-full bg-black text-white py-4 rounded-sm text-xs font-black uppercase tracking-[0.3em] hover:bg-gray-800 transition-all transform active:scale-[0.98] shadow-xl shadow-black/10 mt-2">
          Bayar Sekarang
        </button>
      </div>
    </div>
  </div>

  <!-- Payment Modal -->
  <div id="payment-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[100] hidden items-center justify-center p-4" style="display:none">
    <div class="bg-white w-full max-w-md rounded-sm overflow-hidden shadow-2xl max-h-[92vh] overflow-y-auto no-scrollbar">
      <div class="p-6 sm:p-8 text-center border-b border-subtle">
        <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-2">Total Tagihan</p>
        <h3 class="text-4xl font-black text-black" id="modal-total">Rp 0</h3>
        <p class="text-xs text-gray-400 mt-1" id="modal-subtotal-tax"></p>
      </div>
      <div class="p-6 sm:p-8 space-y-4">
        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Pilih Metode</p>
        <div class="grid grid-cols-2 gap-4">
          <button id="btn-tunai" onclick="selectMethod('tunai')"
            class="p-4 border-2 border-black rounded-sm flex flex-col items-center gap-2 bg-black text-white transition-all">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
            <span class="text-[10px] font-black uppercase">Tunai</span>
          </button>
          <button id="btn-qris" onclick="selectMethod('qris')"
            class="p-4 border border-subtle rounded-sm flex flex-col items-center gap-2 hover:border-blue-600 hover:text-blue-600 transition-all">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
            </svg>
            <span class="text-[10px] font-black uppercase">QRIS / EDC</span>
          </button>
        </div>
        <!-- Input uang tunai -->
        <div id="tunai-input" class="hidden mt-4">
          <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-2">Uang Diterima</label>
          <input type="number" id="bayar-input" placeholder="0" oninput="hitungKembalian()"
            class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-black/10">
          <div class="flex justify-between mt-3 text-sm">
            <span class="text-gray-400 font-medium">Kembalian</span>
            <span id="kembalian-display" class="font-black text-green-600">Rp 0</span>
          </div>
        </div>

        <!-- QRIS Test Mode (Gratis / Dummy) -->
        <div id="qris-box" class="hidden mt-4 border border-subtle bg-gray-50/60 p-5 text-center">
          <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">
            QRIS Test Mode
          </p>
          <p class="text-xs text-gray-500 mb-4">
            Scan QR dummy ini untuk simulasi. Klik tombol di bawah jika pembayaran sudah diterima.
          </p>

          <div class="bg-white border border-subtle inline-block p-3 rounded-sm shadow-sm">
            <img src="assets/qr_code_kasir.png" class="mx-auto border p-2 rounded-sm">
          </div>

          <div class="mt-4 text-left bg-white border border-subtle p-3 rounded-sm">
            <div class="flex justify-between text-[10px] font-bold uppercase tracking-widest text-gray-400">
              <span>Status</span>
              <span class="text-yellow-600">Testing</span>
            </div>
            <div class="flex justify-between text-[10px] font-bold uppercase tracking-widest text-gray-400 mt-2">
              <span>Total</span>
              <span id="qris-total-label" class="text-black">Rp 0</span>
            </div>
          </div>

          <button onclick="confirmQrisPayment()"
            class="mt-4 w-full bg-blue-600 text-white py-3 text-[10px] font-black uppercase tracking-widest rounded-sm hover:bg-blue-700 transition-all">
            Sudah Dibayar
          </button>
        </div>
      </div>
      <div class="p-6 sm:p-8 bg-gray-50 flex gap-3 sm:gap-4">
        <button onclick="closePayment()" class="flex-1 py-4 text-[10px] font-black uppercase border border-subtle rounded-sm hover:bg-white transition-all">Batal</button>
        <button onclick="processPayment()" id="btn-konfirmasi"
          class="flex-1 py-4 text-[10px] font-black uppercase bg-blue-600 text-white rounded-sm shadow-lg shadow-blue-200 active:scale-95 transition-all">
          Konfirmasi
        </button>
      </div>
    </div>
  </div>

  <!-- Success Modal -->
  <div id="success-modal" class="fixed inset-0 bg-black/40 backdrop-blur-sm z-[200] hidden items-center justify-center p-4" style="display:none">
    <div class="bg-white w-full max-w-sm rounded-sm overflow-hidden shadow-2xl text-center p-8 sm:p-10">
      <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
        </svg>
      </div>
      <h3 class="text-xl font-black mb-2">Transaksi Berhasil!</h3>
      <p class="text-xs text-gray-400 uppercase tracking-widest mb-1" id="success-invoice"></p>
      <p class="text-2xl font-black text-blue-600 my-3" id="success-total"></p>
      <p class="text-sm text-green-600 font-bold" id="success-kembalian"></p>
      <div class="flex gap-3 mt-8">
        <a id="btn-struk" href="#" target="_blank"
          class="flex-1 py-3 text-[10px] font-black uppercase border border-subtle rounded-sm hover:bg-gray-50 transition-all">
          Cetak Struk
        </a>
        <button onclick="closeSuccess()"
          class="flex-1 py-3 text-[10px] font-black uppercase bg-black text-white rounded-sm transition-all">
          Transaksi Baru
        </button>
      </div>
    </div>
  </div>

  <!-- Mobile Bottom Navigation -->
  <nav class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-subtle px-6 py-3 flex justify-between items-center z-[250] shadow-lg">
    <button onclick="toggleMobileMenu()" class="flex flex-col items-center p-2">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 12h18M3 6h18M3 18h18" />
      </svg>
      <span class="text-[8px] font-bold mt-1 uppercase">Menu</span>
    </button>
    <a href="pos.php" class="flex flex-col items-center bg-black text-white p-3 rounded-full -mt-8 shadow-xl border-4 border-white">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <circle cx="12" cy="12" r="10" />
        <path d="M12 8v8M8 12h8" />
      </svg>
    </a>
    <a href="produk.php" class="flex flex-col items-center p-2">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
      </svg>
      <span class="text-[8px] font-bold mt-1 uppercase text-gray-400">Produk</span>
    </a>
  </nav>

  <script>
    function toggleMobileMenu() {
      const overlay = document.getElementById('mobileMenuOverlay');
      const content = document.getElementById('mobileMenuContent');
      if (!overlay || !content) return;
      if (overlay.classList.contains('invisible')) {
        overlay.classList.remove('invisible');
        overlay.classList.add('opacity-100');
        content.classList.remove('translate-x-full');
      } else {
        overlay.classList.add('invisible');
        overlay.classList.remove('opacity-100');
        content.classList.add('translate-x-full');
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const overlay = document.getElementById('mobileMenuOverlay');
      if (overlay) {
        overlay.addEventListener('click', function(e) {
          if (e.target === this) toggleMobileMenu();
        });
      }
    });

    // ── State ──────────────────────────────────────────────────────────────────
    let PRODUCTS = [];
    let cart = [];
    let currentCat = 'Semua';
    let selectedMethod = 'tunai';

    // ── Init ───────────────────────────────────────────────────────────────────
    async function init() {
      updateClock();
      setInterval(updateClock, 1000);
      await loadProducts();
    }

    async function loadProducts() {
      try {
        const res = await fetch('pos.php?action=get_products', {
          method: 'POST'
        });
        const data = await res.json();
        if (data.success) {
          PRODUCTS = data.data;
          renderProducts(PRODUCTS);
        } else {
          showError('Gagal memuat produk: ' + data.message);
        }
      } catch (e) {
        showError('Koneksi ke server gagal.');
      }
    }

    // ── Produk ─────────────────────────────────────────────────────────────────
    function setCategory(cat) {
      currentCat = cat;
      document.querySelectorAll('.category-btn').forEach(btn => {
        const btnText = btn.textContent.trim().toLowerCase();
        const selectedCat = cat.trim().toLowerCase();
        btn.classList.toggle('active-category', btnText === selectedCat);
      });
      filterProducts();
    }

    function filterProducts() {
      const q = document.getElementById('search-input').value.toLowerCase();
      const filtered = PRODUCTS.filter(p => {
        const matchSearch = p.nama.toLowerCase().includes(q) || p.kode.toLowerCase().includes(q) || p.kategori.toLowerCase().includes(q);
        const matchCat = currentCat === 'Semua' || p.kategori === currentCat;
        return matchSearch && matchCat;
      });
      renderProducts(filtered);
    }

    function renderProducts(data) {
      const list = document.getElementById('product-list');
      if (data.length === 0) {
        list.innerHTML = `<div class="col-span-full py-20 text-center opacity-20">
            <p class="text-xs font-bold uppercase tracking-[0.2em]">Produk tidak ditemukan</p></div>`;
        return;
      }
      list.innerHTML = data.map(p => {
        const habis = p.stok <= 0;
        const lowStock = p.stok > 0 && p.stok <= 5;
        return `
        <div onclick="${habis ? '' : 'addToCart(' + p.id + ')'}"
             class="bg-white p-3 rounded-2xl border border-subtle transition-all cursor-pointer group active:scale-95 shadow-sm
                    ${habis ? 'opacity-40 cursor-not-allowed' : 'hover:border-black hover:shadow-md'}">
            <div class="w-full aspect-square bg-gray-50 rounded-xl mb-3 flex items-center justify-center
                        ${habis ? 'text-gray-200' : 'text-gray-200 group-hover:text-blue-500'} transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
            <p class="text-[8px] font-black uppercase text-gray-400 tracking-widest mb-1">${p.kategori}</p>
            <h4 class="text-[12px] font-bold leading-tight h-8 overflow-hidden text-slate-800">${p.nama}</h4>
            <p class="text-xs font-black text-black mt-1">${formatRp(p.harga_jual)}</p>
            <div class="flex items-center justify-between mt-1">
                ${habis
                  ? '<span class="text-[8px] font-black text-red-500 uppercase">Habis</span>'
                  : lowStock
                    ? `<span class="text-[8px] font-black text-orange-500 uppercase">Sisa ${p.stok}</span>`
                    : `<span class="text-[8px] text-gray-300 uppercase">Stok ${p.stok}</span>`
                }
            </div>
        </div>`;
      }).join('');
    }

    // ── Cart ───────────────────────────────────────────────────────────────────
    function addToCart(id) {
      const productId = parseInt(id);
      const p = PRODUCTS.find(x => parseInt(x.id) === productId);
      if (!p || parseInt(p.stok) <= 0) return;

      const inCart = cart.find(x => parseInt(x.id) === productId);
      if (inCart) {
        if (parseInt(inCart.qty) >= parseInt(p.stok)) {
          alert(`Stok ${p.nama} hanya tersedia ${p.stok} pcs.`);
          return;
        }
        inCart.qty = parseInt(inCart.qty) + 1;
      } else {
        cart.push({
          id: productId,
          kode: p.kode,
          nama: p.nama,
          kategori: p.kategori,
          harga_jual: parseInt(p.harga_jual),
          stok: parseInt(p.stok),
          satuan: p.satuan,
          qty: 1
        });
      }
      updateUI();
    }

    function updateQty(id, delta) {
      const productId = parseInt(id);
      const item = cart.find(i => parseInt(i.id) === productId);
      if (!item) return;

      const product = PRODUCTS.find(p => parseInt(p.id) === productId);
      const maxStock = product ? parseInt(product.stok) : parseInt(item.stok);
      const nextQty = parseInt(item.qty) + parseInt(delta);

      if (nextQty <= 0) {
        cart = cart.filter(i => parseInt(i.id) !== productId);
      } else if (nextQty > maxStock) {
        alert(`Stok ${item.nama} hanya tersedia ${maxStock} pcs.`);
      } else {
        item.qty = nextQty;
      }
      updateUI();
    }

    function clearCart() {
      cart = [];
      updateUI();
    }

    function updateUI() {
      const container = document.getElementById('cart-container');

      if (!container) return;

      if (cart.length === 0) {
        container.innerHTML = `
      <div id="empty-cart" class="h-full flex flex-col items-center justify-center text-center opacity-30">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
        </svg>
        <p class="text-xs font-bold uppercase tracking-widest">Keranjang Kosong</p>
      </div>
    `;
      } else {
        container.innerHTML = cart.map(item => `
      <div class="cart-item-anim flex justify-between items-center bg-gray-50/30 p-4 rounded-2xl border border-subtle">
        <div class="flex-1 pr-2">
          <h5 class="text-[10px] font-bold uppercase leading-tight tracking-tight">${item.nama}</h5>
          <p class="text-[9px] text-gray-400 mt-1">${formatRp(item.harga_jual)}</p>
        </div>
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-2 bg-white rounded-lg border border-subtle p-1">
            <button onclick="updateQty(${item.id}, -1)" class="w-5 h-5 flex items-center justify-center text-[10px] hover:bg-gray-100 rounded-sm">−</button>
            <span class="text-[10px] font-black w-4 text-center">${item.qty}</span>
            <button onclick="updateQty(${item.id}, 1)" class="w-5 h-5 flex items-center justify-center text-[10px] hover:bg-gray-100 rounded-sm">+</button>
          </div>
          <p class="text-[11px] font-black w-20 text-right">${formatRp(item.harga_jual * item.qty)}</p>
        </div>
      </div>
    `).join('');
      }

      const sub = cart.reduce((acc, i) => acc + (Number(i.harga_jual) * Number(i.qty)), 0);
      const tax = Math.round(sub * 0.11);
      const total = sub + tax;

      document.getElementById('subtotal').innerText = formatRp(sub);
      document.getElementById('tax').innerText = formatRp(tax);
      document.getElementById('total-price').innerText = formatRp(total);
      document.getElementById('modal-total').innerText = formatRp(total);
      document.getElementById('modal-subtotal-tax').innerText =
        `Subtotal ${formatRp(sub)} + Pajak ${formatRp(tax)}`;

      hitungKembalian();

      if (selectedMethod === 'qris') {
        updateQrisTestQR();
      }
    }

    // ── Payment Modal ──────────────────────────────────────────────────────────
    function openPayment() {
      if (cart.length === 0) return;
      document.getElementById('payment-modal').style.display = 'flex';
      selectMethod('tunai');
    }

    function closePayment() {
      document.getElementById('payment-modal').style.display = 'none';
    }

    function selectMethod(method) {
      selectedMethod = method;

      const tunaiInput = document.getElementById('tunai-input');
      const qrisBox = document.getElementById('qris-box');
      const bayarInput = document.getElementById('bayar-input');
      const btnKonfirmasi = document.getElementById('btn-konfirmasi');

      document.getElementById('btn-tunai').className =
        method === 'tunai' ?
        'p-4 border-2 border-black rounded-sm flex flex-col items-center gap-2 bg-black text-white transition-all' :
        'p-4 border border-subtle rounded-sm flex flex-col items-center gap-2 hover:border-black hover:text-black transition-all';

      document.getElementById('btn-qris').className =
        method === 'qris' ?
        'p-4 border-2 border-blue-600 rounded-sm flex flex-col items-center gap-2 bg-blue-600 text-white transition-all' :
        'p-4 border border-subtle rounded-sm flex flex-col items-center gap-2 hover:border-blue-600 hover:text-blue-600 transition-all';

      if (method === 'tunai') {
        if (tunaiInput) tunaiInput.classList.remove('hidden');
        if (qrisBox) qrisBox.classList.add('hidden');
        if (btnKonfirmasi) btnKonfirmasi.innerText = 'Konfirmasi';
        if (bayarInput) bayarInput.focus();
      } else {
        if (tunaiInput) tunaiInput.classList.add('hidden');
        if (qrisBox) qrisBox.classList.remove('hidden');
        if (btnKonfirmasi) btnKonfirmasi.innerText = 'Konfirmasi QRIS';
        updateQrisTestQR();
      }
    }

    function updateQrisTestQR() {
      const img = document.getElementById('qris-dummy-img');
      const label = document.getElementById('qris-total-label');

      const sub = cart.reduce((acc, i) => acc + (Number(i.harga_jual) * Number(i.qty)), 0);
      const total = sub + Math.round(sub * 0.11);

      if (label) label.innerText = formatRp(total);

      if (img) {
        const payload = encodeURIComponent('QRIS-TEST|BSDK-SEJAHTERA|TOTAL:' + total + '|TIME:' + Date.now());
        img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + payload;
      }
    }

    function confirmQrisPayment() {
      if (selectedMethod !== 'qris') {
        selectMethod('qris');
      }

      const ok = confirm('Konfirmasi pembayaran QRIS test sudah diterima?');
      if (!ok) return;

      processPayment();
    }

    function hitungKembalian() {
      const sub = cart.reduce((acc, i) => acc + i.harga_jual * i.qty, 0);
      const total = sub + Math.round(sub * 0.11);
      const bayar = parseInt(document.getElementById('bayar-input')?.value || 0);
      const kem = Math.max(0, bayar - total);
      const el = document.getElementById('kembalian-display');
      if (el) el.innerText = formatRp(kem);
    }

    async function processPayment() {
      if (cart.length === 0) return;

      const sub = cart.reduce((acc, i) => acc + i.harga_jual * i.qty, 0);
      const total = sub + Math.round(sub * 0.11);
      let bayar = 0;

      if (selectedMethod === 'tunai') {
        bayar = parseInt(document.getElementById('bayar-input').value || 0);
        if (bayar < total) {
          alert('Uang bayar kurang dari total: ' + formatRp(total));
          return;
        }
      } else {
        bayar = total;
      }

      const btn = document.getElementById('btn-konfirmasi');
      btn.disabled = true;
      btn.innerText = 'Memproses...';

      try {
        const res = await fetch('pos.php?action=simpan_transaksi', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            items: cart.map(i => ({
              id: parseInt(i.id),
              qty: parseInt(i.qty)
            })),
            bayar
          })
        });
        const data = await res.json();

        if (data.success) {
          closePayment();
          document.getElementById('success-invoice').innerText = data.invoice;
          document.getElementById('success-total').innerText = formatRp(data.total);
          document.getElementById('success-kembalian').innerText =
            selectedMethod === 'tunai' ? 'Kembalian: ' + formatRp(data.kembalian) : 'QRIS / EDC – Lunas';
          document.getElementById('btn-struk').href = 'struk.php?invoice=' + encodeURIComponent(data.invoice);
          document.getElementById('success-modal').style.display = 'flex';
          clearCart();
          await loadProducts();
        } else {
          alert('Gagal: ' + data.message);
        }
      } catch (e) {
        alert('Terjadi kesalahan jaringan.');
      } finally {
        btn.disabled = false;
        btn.innerText = 'Konfirmasi';
      }
    }

    function closeSuccess() {
      document.getElementById('success-modal').style.display = 'none';
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    function formatRp(num) {
      return 'Rp ' + Math.floor(num).toLocaleString('id-ID');
    }

    function updateClock() {
      const now = new Date();
      document.getElementById('clock').innerText = now.toLocaleTimeString('id-ID', {
        hour12: false
      });
    }

    function showError(msg) {
      document.getElementById('product-list').innerHTML =
        `<div class="col-span-full py-20 text-center text-red-400">
            <p class="text-xs font-bold uppercase">${msg}</p></div>`;
    }


    // ── Barcode Scanner ───────────────────────────────────────────────────────
    function focusBarcodeInput() {
      const input = document.getElementById('search-input');
      if (input) input.focus();
    }

    function handleBarcodeScan(code) {
      const cleanCode = String(code || '').trim().toLowerCase();
      if (!cleanCode) return;

      const found = PRODUCTS.find(p => String(p.kode || '').trim().toLowerCase() === cleanCode);

      if (found) {
        addToCart(found.id);
        const input = document.getElementById('search-input');
        if (input) {
          input.value = '';
          filterProducts();
          input.focus();
        }
      } else {
        alert('Produk dengan barcode/kode "' + code + '" tidak ditemukan.');
        focusBarcodeInput();
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const searchInput = document.getElementById('search-input');
      if (!searchInput) return;

      searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          handleBarcodeScan(this.value);
        }
      });

      focusBarcodeInput();
    });
    window.onload = function() {
      init();
      setTimeout(focusBarcodeInput, 300);
    };
  </script>
</body>

</html>