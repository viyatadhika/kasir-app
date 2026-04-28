<?php

require 'auth.php';
require_login();

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$items = $data['items'] ?? [];
$bayar = (int)($data['bayar'] ?? 0);

if (!$items) {
    echo json_encode([
        'status' => false,
        'message' => 'Keranjang masih kosong.'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    $total = 0;
    $produkTransaksi = [];

    foreach ($items as $item) {
        $produkId = (int)$item['id'];
        $qty = (int)$item['qty'];

        if ($qty <= 0) {
            throw new Exception('Qty tidak valid.');
        }

        $stmt = $pdo->prepare("SELECT * FROM produk WHERE id = ? AND status = 'aktif' FOR UPDATE");
        $stmt->execute([$produkId]);
        $produk = $stmt->fetch();

        if (!$produk) {
            throw new Exception('Produk tidak ditemukan.');
        }

        if ($produk['stok'] < $qty) {
            throw new Exception('Stok tidak cukup untuk ' . $produk['nama']);
        }

        $subtotal = $produk['harga_jual'] * $qty;
        $total += $subtotal;

        $produkTransaksi[] = [
            'produk' => $produk,
            'qty' => $qty,
            'subtotal' => $subtotal
        ];
    }

    if ($bayar < $total) {
        throw new Exception('Uang bayar kurang.');
    }

    $invoice = 'INV-' . date('YmdHis') . '-' . rand(100, 999);
    $kembalian = $bayar - $total;

    $stmt = $pdo->prepare("
        INSERT INTO transaksi 
        (invoice, user_id, total, bayar, kembalian)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $invoice,
        user()['id'],
        $total,
        $bayar,
        $kembalian
    ]);

    $transaksiId = $pdo->lastInsertId();

    foreach ($produkTransaksi as $row) {
        $produk = $row['produk'];
        $qty = $row['qty'];
        $subtotal = $row['subtotal'];

        $stmt = $pdo->prepare("
            INSERT INTO transaksi_detail
            (transaksi_id, produk_id, kode, nama, harga, qty, subtotal)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $transaksiId,
            $produk['id'],
            $produk['kode'],
            $produk['nama'],
            $produk['harga_jual'],
            $qty,
            $subtotal
        ]);

        $stmt = $pdo->prepare("
            UPDATE produk
            SET stok = stok - ?
            WHERE id = ?
        ");

        $stmt->execute([$qty, $produk['id']]);
    }

    $pdo->commit();

    echo json_encode([
        'status' => true,
        'message' => 'Transaksi berhasil disimpan.',
        'invoice' => $invoice,
        'redirect' => 'struk.php?id=' . $transaksiId
    ]);
} catch (Exception $e) {
    $pdo->rollBack();

    echo json_encode([
        'status' => false,
        'message' => $e->getMessage()
    ]);
}
