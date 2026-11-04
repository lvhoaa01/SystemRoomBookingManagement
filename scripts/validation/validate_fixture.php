<?php

declare(strict_types=1);

use App\Config\DatabaseConfig;
use App\Config\Env;
use App\Shared\Database\Connection;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
Env::load($root . '/.env');
$pdo = Connection::create(DatabaseConfig::fromEnv());
$manifestPath = $root . '/database/fixtures/data-manifest.json';
$failures = [];
$passes = 0;

$check = static function (bool $condition, string $name, string $detail = '') use (&$failures, &$passes): void {
    if ($condition) {
        ++$passes;
        fwrite(STDOUT, "[PASS] {$name}" . ($detail === '' ? '' : " — {$detail}") . PHP_EOL);
        return;
    }
    $failures[] = $name . ($detail === '' ? '' : ': ' . $detail);
    fwrite(STDERR, "[FAIL] {$name}" . ($detail === '' ? '' : " — {$detail}") . PHP_EOL);
};

$manifestJson = file_get_contents($manifestPath);
$check($manifestJson !== false, 'Đọc data manifest');
if ($manifestJson === false) {
    exit(1);
}
$manifest = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
$check(is_array($manifest), 'Manifest là JSON object');
$check(($manifest['syntheticData'] ?? null) === true, 'Dữ liệu được gắn nhãn synthetic');
$check(($manifest['tableCount'] ?? null) === 22, 'Manifest fixture gốc khai báo đúng 22 bảng');

/** @var array<string, string> $requiredFiles */
$requiredFiles = array_merge(
    is_array($manifest['files'] ?? null) ? $manifest['files'] : [],
    array_filter(
        is_array($manifest['sourceFiles'] ?? null) ? $manifest['sourceFiles'] : [],
        static fn (string $path): bool => !str_starts_with($path, 'huongDan/'),
        ARRAY_FILTER_USE_KEY,
    ),
);
foreach ($requiredFiles as $relativePath => $expectedHash) {
    $path = $root . '/' . $relativePath;
    $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
    $check(
        is_string($actualHash) && hash_equals(strtolower($expectedHash), strtolower($actualHash)),
        'Checksum ' . $relativePath,
    );
}

/** @var array<string, string> $optionalSources */
$optionalSources = array_filter(
    is_array($manifest['sourceFiles'] ?? null) ? $manifest['sourceFiles'] : [],
    static fn (string $path): bool => str_starts_with($path, 'huongDan/'),
    ARRAY_FILTER_USE_KEY,
);
foreach ($optionalSources as $relativePath => $expectedHash) {
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        fwrite(STDOUT, "[SKIP] Nguồn provenance local không được Git theo dõi: {$relativePath}" . PHP_EOL);
        continue;
    }
    $actualHash = hash_file('sha256', $path);
    $check(is_string($actualHash) && hash_equals(strtolower($expectedHash), strtolower($actualHash)), 'Checksum ' . $relativePath);
}

$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'",
)->fetchColumn();
$check($tableCount === 26, 'Database có đúng 26 bảng sau mở rộng hóa đơn và notification outbox', (string) $tableCount);

$countMap = [
    'facilities' => 'TienNghi',
    'users' => 'NguoiDung',
    'partnerOrganizations' => 'ToChucDoiTac',
    'properties' => 'CoSoLuuTru',
    'roomTypes' => 'LoaiPhong',
    'roomProducts' => 'SanPhamPhong',
    'dailyInventory' => 'TonPhongNgay',
    'dailyPrices' => 'GiaPhongNgay',
    'previews' => 'XemTruocDatCho',
    'bookings' => 'DatCho',
    'bookingItems' => 'HangMucDatCho',
    'nightPriceSnapshots' => 'GiaDemDatCho',
    'inventoryLedgerRows' => 'GiuTonPhongDem',
    'payments' => 'ThanhToan',
    'cancellationRequests' => 'YeuCauHuyMienPhi',
    'reviews' => 'DanhGia',
    'promotions' => 'KhuyenMai',
    'auditRows' => 'NhatKyHeThong',
];
foreach ($countMap as $manifestKey => $table) {
    $expected = (int) ($manifest['counts'][$manifestKey] ?? -1);
    $actual = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $check($expected === $actual, "Số dòng {$table}", "expected={$expected}, actual={$actual}");
}

$assertRejected = static function (string $name, callable $mutation) use ($pdo, $check): void {
    $rejected = false;
    $pdo->beginTransaction();
    try {
        $mutation($pdo);
    } catch (PDOException $exception) {
        $rejected = (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23000';
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    $check($rejected, $name);
};

$assertRejected('Fixture sai: held vượt total bị từ chối', static function (PDO $pdo): void {
    $pdo->exec(
        "UPDATE TonPhongNgay SET SoLuongDaGiu = TongSoLuong + 1
         WHERE LoaiPhongID = 1 AND NgayLuuTru = '2026-10-05'",
    );
});

$assertRejected('Fixture sai: một booking có review thứ hai bị từ chối', static function (PDO $pdo): void {
    $pdo->exec(
        "INSERT INTO DanhGia (DatChoID, CoSoLuuTruID, KhachHangID, DiemTong, TrangThai)
         SELECT DatChoID, CoSoLuuTruID, KhachHangID, 10, 'PUBLISHED'
         FROM DanhGia ORDER BY DanhGiaID LIMIT 1",
    );
});

$assertRejected('Fixture sai: một preview tạo booking thứ hai bị từ chối', static function (PDO $pdo): void {
    $pdo->exec(
        "INSERT INTO DatCho
            (MaDatCho, ClientRequestID, XemTruocDatChoID, KhachHangID, CoSoLuuTruID,
             NgayNhanPhong, NgayTraPhong, TenNguoiDat, EmailNguoiDat, DienThoaiNguoiDat,
             ThoiDiemThanhToan, TienTe, TongTien, TrangThai)
         SELECT 'BK9999999998', 'ffffffff-ffff-4fff-8fff-ffffffffffff', XemTruocDatChoID,
                KhachHangID, CoSoLuuTruID, NgayNhanPhong, NgayTraPhong, TenNguoiDat,
                EmailNguoiDat, DienThoaiNguoiDat, ThoiDiemThanhToan, TienTe, TongTien, TrangThai
         FROM DatCho ORDER BY DatChoID LIMIT 1",
    );
});

$assertRejected('Fixture sai: ClientRequestID trùng bị từ chối', static function (PDO $pdo): void {
    $previewId = (int) $pdo->query('SELECT MAX(XemTruocDatChoID) + 1 FROM XemTruocDatCho')->fetchColumn();
    $statement = $pdo->prepare(
        "INSERT INTO XemTruocDatCho
            (XemTruocDatChoID, TokenHash, KhachHangID, CoSoLuuTruID, NgayNhanPhong,
             NgayTraPhong, TienTe, TongTien, SoTienTraNgay, SoTienTraTaiCoSo,
             PhanBoJSON, Fingerprint, TrangThai, HetHanLuc, TaoLuc, DaDungLuc)
         SELECT ?, SHA2('invalid-duplicate-client-request', 256), KhachHangID,
                CoSoLuuTruID, NgayNhanPhong, NgayTraPhong, TienTe, TongTien,
                SoTienTraNgay, SoTienTraTaiCoSo, PhanBoJSON, SHA2('invalid-fingerprint', 256),
                'USED', '2027-01-01 00:00:00.000000', TaoLuc, DaDungLuc
         FROM XemTruocDatCho ORDER BY XemTruocDatChoID LIMIT 1",
    );
    $statement->execute([$previewId]);
    $insert = $pdo->prepare(
        "INSERT INTO DatCho
            (MaDatCho, ClientRequestID, XemTruocDatChoID, KhachHangID, CoSoLuuTruID,
             NgayNhanPhong, NgayTraPhong, TenNguoiDat, EmailNguoiDat, DienThoaiNguoiDat,
             ThoiDiemThanhToan, TienTe, TongTien, TrangThai)
         SELECT 'BK9999999999', ClientRequestID, ?, KhachHangID, CoSoLuuTruID,
                NgayNhanPhong, NgayTraPhong, TenNguoiDat, EmailNguoiDat, DienThoaiNguoiDat,
                ThoiDiemThanhToan, TienTe, TongTien, TrangThai
         FROM DatCho ORDER BY DatChoID LIMIT 1",
    );
    $insert->execute([$previewId]);
});

fwrite(STDOUT, PHP_EOL . "Fixture validation: {$passes} passed, " . count($failures) . ' failed.' . PHP_EOL);
if ($failures !== []) {
    exit(1);
}
