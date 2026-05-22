<?php

declare(strict_types=1);

/**
 * Ambil ID akun COA berdasarkan kode
 */
function coa_id(PDO $pdo, string $kode): ?int
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM coa
        WHERE kode = ?
        LIMIT 1
    ");

    $stmt->execute([$kode]);

    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

/**
 * Membuat jurnal umum
 */
function buat_jurnal(
    PDO $pdo,
    string $tanggal,
    string $keterangan,
    string $refTable,
    int $refId,
    ?int $userId = null
): int {

    $kodeJurnal = 'JR-' . date('YmdHis');

    $stmt = $pdo->prepare("
        INSERT INTO jurnal_umum
        (
            tanggal,
            kode_jurnal,
            keterangan,
            ref_tabel,
            ref_id,
            dibuat_oleh
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?
        )
    ");

    $stmt->execute([
        $tanggal,
        $kodeJurnal,
        $keterangan,
        $refTable,
        $refId,
        $userId
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Tambah detail jurnal
 */
function tambah_jurnal_detail(
    PDO $pdo,
    int $jurnalId,
    int $coaId,
    float $debit = 0,
    float $kredit = 0
): void {

    $stmt = $pdo->prepare("
        INSERT INTO jurnal_detail
        (
            jurnal_id,
            coa_id,
            debit,
            kredit
        )
        VALUES
        (
            ?, ?, ?, ?
        )
    ");

    $stmt->execute([
        $jurnalId,
        $coaId,
        $debit,
        $kredit
    ]);
}
