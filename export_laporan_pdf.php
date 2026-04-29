<?php
ob_start();
session_start();

require __DIR__ . '/fpdf/fpdf.php';
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| export_laporan_pdf.php
|--------------------------------------------------------------------------
| Export laporan keuangan POS ke PDF memakai FPDF.
|
| Parameter:
| - jenis = semua | ringkasan | transaksi | produk | diskon
| - awal  = YYYY-MM-DD
| - akhir = YYYY-MM-DD
|
| Contoh:
| export_laporan_pdf.php?jenis=semua&awal=2026-04-01&akhir=2026-04-30
*/

function tanggalIndo($tgl)
{
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $t = strtotime($tgl);
    return date('j', $t) . ' ' . $bulan[(int)date('m', $t)] . ' ' . date('Y', $t);
}

function rupiahPdf($v)
{
    return 'Rp ' . number_format((float)($v ?? 0), 0, ',', '.');
}

function angkaPdf($v)
{
    return number_format((float)($v ?? 0), 0, ',', '.');
}

function pdfText($text)
{
    $text = (string)($text ?? '');
    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
}

function hasColumn(PDO $pdo, $table, $column)
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $stmt->execute([':c' => $column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

class PDF extends FPDF
{
    public $judul = 'LAPORAN KEUANGAN';
    public $awal = '';
    public $akhir = '';

    function Header()
    {
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(0, 8, pdfText('KOPERASI BSDK'), 0, 1, 'C');
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 7, pdfText($this->judul), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, pdfText('Periode: ' . tanggalIndo($this->awal) . ' s/d ' . tanggalIndo($this->akhir)), 0, 1, 'C');
        $this->Ln(3);
        $this->SetLineWidth(0.7);
        $this->Line(10, 30, 287, 30);
        $this->SetLineWidth(0.2);
        $this->Line(10, 32, 287, 32);
        $this->Ln(8);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, pdfText('Dicetak: ' . date('d/m/Y H:i') . ' | Halaman ' . $this->PageNo()), 0, 0, 'C');
    }

    function SectionTitle($title)
    {
        $this->Ln(3);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, pdfText($title), 0, 1, 'L');
    }

    function TableHeader($headers, $widths, $aligns = [])
    {
        $this->SetFillColor(230, 230, 230);
        $this->SetFont('Arial', 'B', 9);
        foreach ($headers as $i => $h) {
            $this->Cell($widths[$i], 7, pdfText($h), 1, 0, $aligns[$i] ?? 'C', true);
        }
        $this->Ln();
    }

    function Row($data, $widths, $aligns = [])
    {
        $nb = 0;
        for ($i = 0; $i < count($data); $i++) {
            $nb = max($nb, $this->NbLines($widths[$i], pdfText($data[$i])));
        }
        $h = 6 * $nb;
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
        for ($i = 0; $i < count($data); $i++) {
            $w = $widths[$i];
            $a = $aligns[$i] ?? 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            $this->Rect($x, $y, $w, $h);
            $this->MultiCell($w, 6, pdfText($data[$i]), 0, $a);
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }

    function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") $nb--;
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++; $sep = -1; $j = $i; $l = 0; $nl++;
                continue;
            }
            if ($c == ' ') $sep = $i;
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) $i++;
                } else {
                    $i = $sep + 1;
                }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}

$jenis = $_GET['jenis'] ?? 'semua';
if (!in_array($jenis, ['semua', 'ringkasan', 'transaksi', 'produk', 'diskon'], true)) {
    $jenis = 'semua';
}

$awal = $_GET['awal'] ?? ($_GET['from'] ?? date('Y-m-d'));
$akhir = $_GET['akhir'] ?? ($_GET['to'] ?? date('Y-m-d'));

$hasDiskon = hasColumn($pdo, 'transaksi', 'diskon');
$hasDiskonId = hasColumn($pdo, 'transaksi', 'diskon_id');
$hasPoint = hasColumn($pdo, 'transaksi', 'point_dapat');
$hasMember = hasColumn($pdo, 'transaksi', 'member_id');
$hasHargaNormal = hasColumn($pdo, 'transaksi_detail', 'harga_normal');
$hasDetailDiskon = hasColumn($pdo, 'transaksi_detail', 'diskon');
$hasDetailDiskonId = hasColumn($pdo, 'transaksi_detail', 'diskon_id');

$diskonSum = $hasDiskon ? "COALESCE(SUM(diskon),0)" : "0";
$pointSum = $hasPoint ? "COALESCE(SUM(point_dapat),0)" : "0";

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS total_transaksi,
           COALESCE(SUM(total),0) AS omzet,
           $diskonSum AS diskon,
           COALESCE(SUM(bayar),0) AS bayar,
           COALESCE(SUM(kembalian),0) AS kembalian,
           $pointSum AS point
    FROM transaksi
    WHERE DATE(created_at) BETWEEN :awal AND :akhir
");
$stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$memberJoin = $hasMember ? "LEFT JOIN member m ON m.id=t.member_id" : "";
$memberSelect = $hasMember ? "m.nama AS member_nama, m.kode AS member_kode" : "NULL AS member_nama, NULL AS member_kode";
$diskonSelect = $hasDiskon ? "t.diskon" : "0 AS diskon";
$pointSelect = $hasPoint ? "t.point_dapat" : "0 AS point_dapat";

$stmt = $pdo->prepare("
    SELECT t.id, t.invoice, t.created_at, t.total, t.bayar, t.kembalian,
           $diskonSelect, $pointSelect, $memberSelect
    FROM transaksi t
    $memberJoin
    WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
    ORDER BY t.created_at DESC, t.id DESC
");
$stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
$transaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hargaNormal = $hasHargaNormal ? "COALESCE(td.harga_normal, td.harga)" : "td.harga";
$diskonDetail = $hasDetailDiskon ? "COALESCE(td.diskon,0)" : "GREATEST(0, (($hargaNormal * td.qty) - td.subtotal))";

$stmt = $pdo->prepare("
    SELECT td.produk_id, td.kode, td.nama,
           SUM(td.qty) AS qty,
           SUM($hargaNormal * td.qty) AS subtotal_normal,
           SUM($diskonDetail) AS diskon,
           SUM(td.subtotal) AS penjualan
    FROM transaksi_detail td
    JOIN transaksi t ON t.id=td.transaksi_id
    WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
    GROUP BY td.produk_id, td.kode, td.nama
    ORDER BY qty DESC, penjualan DESC
    LIMIT 50
");
$stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
$produk = $stmt->fetchAll(PDO::FETCH_ASSOC);

$diskonTransaksi = [];
if ($hasDiskon && $hasDiskonId) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(d.nama,'Diskon Transaksi') AS nama,
               COUNT(t.id) AS jumlah,
               COALESCE(SUM(t.diskon),0) AS total
        FROM transaksi t
        LEFT JOIN diskon d ON d.id=t.diskon_id
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
          AND COALESCE(t.diskon,0) > 0
        GROUP BY t.diskon_id, d.nama
        ORDER BY total DESC
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $diskonTransaksi = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$diskonBarang = [];
if ($hasDetailDiskon && $hasDetailDiskonId) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(d.nama,'Diskon Barang') AS nama,
               COUNT(td.id) AS jumlah,
               COALESCE(SUM(td.diskon),0) AS total
        FROM transaksi_detail td
        JOIN transaksi t ON t.id=td.transaksi_id
        LEFT JOIN diskon d ON d.id=td.diskon_id
        WHERE DATE(t.created_at) BETWEEN :awal AND :akhir
          AND COALESCE(td.diskon,0) > 0
        GROUP BY td.diskon_id, d.nama
        ORDER BY total DESC
    ");
    $stmt->execute([':awal' => $awal, ':akhir' => $akhir]);
    $diskonBarang = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$judulMap = [
    'semua' => 'LAPORAN KEUANGAN LENGKAP',
    'ringkasan' => 'LAPORAN RINGKASAN KEUANGAN',
    'transaksi' => 'LAPORAN DETAIL TRANSAKSI',
    'produk' => 'LAPORAN PRODUK TERLARIS',
    'diskon' => 'LAPORAN DISKON TERPAKAI',
];

$pdf = new PDF('L', 'mm', 'A4');
$pdf->judul = $judulMap[$jenis] ?? 'LAPORAN KEUANGAN';
$pdf->awal = $awal;
$pdf->akhir = $akhir;
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pdf->SetFont('Arial', '', 9);

function renderRingkasan($pdf, $summary, $transaksi)
{
    $totalTransaksi = (int)($summary['total_transaksi'] ?? 0);
    $omzet = (int)($summary['omzet'] ?? 0);
    $diskon = (int)($summary['diskon'] ?? 0);
    $bayar = (int)($summary['bayar'] ?? 0);
    $kembali = (int)($summary['kembalian'] ?? 0);
    $point = (int)($summary['point'] ?? 0);
    $rata = $totalTransaksi > 0 ? floor($omzet / $totalTransaksi) : 0;

    $pdf->SectionTitle('RINGKASAN KEUANGAN');
    $widths = [70, 70, 70, 67];
    $pdf->TableHeader(['Keterangan', 'Nilai', 'Keterangan', 'Nilai'], $widths);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Row(['Omzet Bersih', rupiahPdf($omzet), 'Total Transaksi', angkaPdf($totalTransaksi)], $widths);
    $pdf->Row(['Total Diskon', rupiahPdf($diskon), 'Rata-rata Transaksi', rupiahPdf($rata)], $widths);
    $pdf->Row(['Total Bayar Diterima', rupiahPdf($bayar), 'Total Kembalian', rupiahPdf($kembali)], $widths);
    $pdf->Row(['Point Member Diberikan', angkaPdf($point) . ' pt', 'Jumlah Data Transaksi', angkaPdf(count($transaksi))], $widths);
}

function renderTransaksi($pdf, $transaksi)
{
    $pdf->SectionTitle('DETAIL TRANSAKSI');
    if (!$transaksi) { $pdf->Cell(0, 8, pdfText('Tidak ada transaksi pada periode ini.'), 0, 1); return; }
    $headers = ['No', 'Tanggal', 'Invoice', 'Member', 'Diskon', 'Total', 'Bayar', 'Kembali', 'Point'];
    $widths = [10, 30, 45, 45, 28, 30, 30, 30, 29];
    $aligns = ['C', 'L', 'L', 'L', 'R', 'R', 'R', 'R', 'R'];
    $pdf->TableHeader($headers, $widths, $aligns);
    $pdf->SetFont('Arial', '', 8);
    $no = 1;
    foreach ($transaksi as $t) {
        $member = trim(($t['member_nama'] ?? '') . ' ' . (($t['member_kode'] ?? '') ? '(' . $t['member_kode'] . ')' : ''));
        if ($member === '') $member = '-';
        $pdf->Row([$no++, date('d/m/Y H:i', strtotime($t['created_at'])), $t['invoice'], $member, rupiahPdf($t['diskon'] ?? 0), rupiahPdf($t['total'] ?? 0), rupiahPdf($t['bayar'] ?? 0), rupiahPdf($t['kembalian'] ?? 0), angkaPdf($t['point_dapat'] ?? 0) . ' pt'], $widths, $aligns);
    }
}

function renderProduk($pdf, $produk)
{
    $pdf->SectionTitle('PRODUK TERLARIS');
    if (!$produk) { $pdf->Cell(0, 8, pdfText('Tidak ada produk terjual pada periode ini.'), 0, 1); return; }
    $headers = ['No', 'Kode', 'Nama Produk', 'Qty', 'Subtotal Normal', 'Diskon', 'Penjualan'];
    $widths = [12, 35, 95, 25, 40, 35, 35];
    $aligns = ['C', 'L', 'L', 'R', 'R', 'R', 'R'];
    $pdf->TableHeader($headers, $widths, $aligns);
    $pdf->SetFont('Arial', '', 8);
    $no = 1;
    foreach ($produk as $p) {
        $pdf->Row([$no++, $p['kode'] ?? '-', $p['nama'] ?? '-', angkaPdf($p['qty'] ?? 0), rupiahPdf($p['subtotal_normal'] ?? 0), rupiahPdf($p['diskon'] ?? 0), rupiahPdf($p['penjualan'] ?? 0)], $widths, $aligns);
    }
}

function renderDiskon($pdf, $diskonTransaksi, $diskonBarang)
{
    $pdf->SectionTitle('DISKON TERPAKAI');
    if (!$diskonTransaksi && !$diskonBarang) { $pdf->Cell(0, 8, pdfText('Tidak ada diskon terpakai pada periode ini.'), 0, 1); return; }
    $headers = ['No', 'Nama Diskon', 'Tipe', 'Jumlah Pemakaian', 'Total Diskon'];
    $widths = [12, 130, 40, 45, 50];
    $aligns = ['C', 'L', 'L', 'R', 'R'];
    $pdf->TableHeader($headers, $widths, $aligns);
    $pdf->SetFont('Arial', '', 8);
    $no = 1;
    foreach ($diskonTransaksi as $d) {
        $pdf->Row([$no++, $d['nama'] ?? 'Diskon Transaksi', 'Transaksi', angkaPdf($d['jumlah'] ?? 0), rupiahPdf($d['total'] ?? 0)], $widths, $aligns);
    }
    foreach ($diskonBarang as $d) {
        $pdf->Row([$no++, $d['nama'] ?? 'Diskon Barang', 'Barang', angkaPdf($d['jumlah'] ?? 0), rupiahPdf($d['total'] ?? 0)], $widths, $aligns);
    }
}

if ($jenis === 'ringkasan') {
    renderRingkasan($pdf, $summary, $transaksi);
} elseif ($jenis === 'transaksi') {
    renderTransaksi($pdf, $transaksi);
} elseif ($jenis === 'produk') {
    renderProduk($pdf, $produk);
} elseif ($jenis === 'diskon') {
    renderDiskon($pdf, $diskonTransaksi, $diskonBarang);
} else {
    renderRingkasan($pdf, $summary, $transaksi);
    renderTransaksi($pdf, $transaksi);
    renderProduk($pdf, $produk);
    renderDiskon($pdf, $diskonTransaksi, $diskonBarang);
}

$namaFile = 'Laporan_' . ucfirst($jenis) . '_' . $awal . '_sd_' . $akhir . '.pdf';
@ob_end_clean();
$pdf->Output('D', $namaFile);
exit;
