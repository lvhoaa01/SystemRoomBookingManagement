<?php

declare(strict_types=1);

const DATA_SEED = 20261005;
const AS_OF_UTC = '2026-10-05 12:00:00.000000';
const INVENTORY_DAYS = 180;
const PROPERTY_SOURCE_HEADERS = ['property_id', 'source_row', 'source_name', 'verified_name', 'address', 'match_type', 'checked_at'];
const GENERATOR_VERSION = '2.2-image-assets';

$root = dirname(__DIR__, 2);
$seedDir = $root . '/database/seeds';
$fixtureDir = $root . '/database/fixtures';
$arguments = array_slice($argv, 1);
$unknownArguments = array_values(array_diff($arguments, ['--operational-only', '--properties-only']));
if ($unknownArguments !== []) {
    throw new InvalidArgumentException('Tham số không hỗ trợ: ' . implode(', ', $unknownArguments));
}
$operationalOnly = in_array('--operational-only', $arguments, true);
$propertiesOnly = in_array('--properties-only', $arguments, true);
if ($operationalOnly && $propertiesOnly) {
    throw new InvalidArgumentException('Không thể dùng đồng thời --operational-only và --properties-only.');
}
$fullGeneration = !$operationalOnly && !$propertiesOnly;

function sqlValue(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $value) . "'";
}

/** @param list<string> $columns @param list<list<mixed>> $rows */
function sqlInsert(string $table, array $columns, array $rows, int $chunkSize = 250): string
{
    if ($rows === []) {
        return '';
    }
    $output = '';
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $values = array_map(
            static fn (array $row): string => '  (' . implode(', ', array_map('sqlValue', $row)) . ')',
            $chunk,
        );
        $output .= 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ") VALUES\n";
        $output .= implode(",\n", $values) . ";\n\n";
    }
    return $output;
}

function writeText(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Không tạo được thư mục: ' . $directory);
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Không ghi được tệp: ' . $path);
    }
}

function jsonData(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function readJsonObject(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Không tìm thấy tệp JSON: ' . $path);
    }
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) {
        throw new RuntimeException('JSON phải là object: ' . $path);
    }
    return $data;
}

/**
 * @param mixed $images
 * @return list<array{path:string,alt:string,sort:int,cover:bool}>
 */
function validateSeedImages(mixed $images, string $root, int $maxImages, string $context, bool $requireIllustrationAlt = false): array
{
    if (!is_array($images) || !array_is_list($images) || $images === [] || count($images) > $maxImages) {
        throw new RuntimeException($context . ': danh sách ảnh phải có từ 1 đến ' . $maxImages . ' phần tử.');
    }
    $coverCount = 0;
    $seenPaths = [];
    foreach ($images as $index => $image) {
        if (!is_array($image)) {
            throw new RuntimeException($context . ': phần tử ảnh không phải object.');
        }
        foreach (['path', 'alt', 'sort', 'cover'] as $field) {
            if (!array_key_exists($field, $image)) {
                throw new RuntimeException($context . ': ảnh thiếu trường ' . $field . '.');
            }
        }
        $path = $image['path'];
        $alt = $image['alt'];
        $sort = $image['sort'];
        $cover = $image['cover'];
        if (!is_string($path) || !str_starts_with($path, '/assets/images/accommodation/') || str_contains($path, '..') || str_contains($path, '\\')) {
            throw new RuntimeException($context . ': đường dẫn ảnh public không hợp lệ.');
        }
        if (!preg_match('/\.(?:jpe?g|png|webp)$/i', $path)) {
            throw new RuntimeException($context . ': định dạng ảnh không được hỗ trợ: ' . $path);
        }
        if (isset($seenPaths[$path])) {
            throw new RuntimeException($context . ': đường dẫn ảnh bị trùng: ' . $path);
        }
        $seenPaths[$path] = true;
        if (!is_string($alt) || trim($alt) === '') {
            throw new RuntimeException($context . ': alt ảnh không được rỗng.');
        }
        if ($requireIllustrationAlt && !str_starts_with($alt, 'Ảnh minh họa')) {
            throw new RuntimeException($context . ': ảnh dùng chung phải có alt bắt đầu bằng "Ảnh minh họa".');
        }
        if (!is_int($sort) || $sort !== $index + 1) {
            throw new RuntimeException($context . ': thứ tự ảnh phải liên tục từ 1.');
        }
        if (!is_bool($cover)) {
            throw new RuntimeException($context . ': cover phải là boolean.');
        }
        $coverCount += $cover ? 1 : 0;
        $assetPath = $root . '/public' . $path;
        if (!is_file($assetPath) || @getimagesize($assetPath) === false) {
            throw new RuntimeException($context . ': thiếu hoặc không đọc được ảnh ' . $assetPath . '.');
        }
    }
    if ($coverCount !== 1) {
        throw new RuntimeException($context . ': phải có đúng một ảnh cover.');
    }
    return $images;
}

/** @param list<string> $expectedHeaders @return list<array<string, string>> */
function readCsvRows(string $path, array $expectedHeaders): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Không đọc được tệp CSV: ' . $path);
    }
    try {
        $headers = fgetcsv($handle);
        if ($headers === false) {
            throw new RuntimeException('Tệp CSV không có tiêu đề: ' . $path);
        }
        if ($headers !== $expectedHeaders) {
            throw new RuntimeException('Tiêu đề CSV không đúng cấu trúc quy định: ' . $path);
        }
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($values) !== count($headers)) {
                throw new RuntimeException('Số cột CSV không hợp lệ: ' . $path);
            }
            $rows[] = array_combine($headers, $values);
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

function slugify(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $ascii = $ascii === false ? $value : $ascii;
    $ascii = str_replace(["'", '`', '^', '~'], '', $ascii);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
}

function dt(string $date, string $time = '12:00:00.000000'): string
{
    return $date . ' ' . $time;
}

function uuidFromInt(int $value): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $value);
}

function datePlus(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function basePrice(int $propertyId, int $roomIndex, int $variant, string $date): int
{
    $price = 420000 + ($propertyId * 17000) + ($roomIndex * 115000) + ($variant * 55000);
    $day = (int) (new DateTimeImmutable($date))->format('N');
    if ($day >= 5) {
        $price = (int) round($price * 1.10);
    }
    if ((new DateTimeImmutable($date))->format('m') === '12') {
        $price = (int) round($price * 1.15);
    }
    return (int) (round($price / 1000) * 1000);
}

/** @return array{base:int,discount:int,included:int,atProperty:int,final:int,promotionId:?int} */
function nightPrice(array $product, string $date, bool $promotionEligible): array
{
    $base = basePrice($product['propertyId'], $product['roomIndex'], $product['variant'], $date);
    $promotionId = null;
    $discount = 0;
    if ($promotionEligible && $date >= '2026-10-05' && $date <= '2027-03-31') {
        $promotionId = (($product['propertyId'] - 1) * 2) + 1;
        $discount = (int) round($base * 0.10);
    }
    $net = $base - $discount;
    $tax = (int) round($net * 0.08);
    $serviceFee = (int) round($net * 0.05);
    if (!in_array($product['timing'], ['PAY_ONLINE', 'PAY_AT_PROPERTY'], true)) {
        throw new RuntimeException('Thời điểm thanh toán của sản phẩm không hợp lệ.');
    }

    // V2 chỉ cho một timing thuần nhất. Phí dịch vụ của PAY_ONLINE được thu
    // trong tổng online; chỉ PAY_AT_PROPERTY mới có khoản trả tại cơ sở.
    $included = $tax + ($product['timing'] === 'PAY_ONLINE' ? $serviceFee : 0);
    $atProperty = $product['timing'] === 'PAY_AT_PROPERTY' ? $serviceFee : 0;
    return [
        'base' => $base,
        'discount' => $discount,
        'included' => $included,
        'atProperty' => $atProperty,
        'final' => $net + $included + $atProperty,
        'promotionId' => $promotionId,
    ];
}

function roomInventory(int $roomIndex): int
{
    return [1 => 8, 2 => 6, 3 => 4, 4 => 3][$roomIndex];
}

function isClosedDay(int $roomId, int $dayOffset): bool
{
    return (($roomId * 17) + $dayOffset) % 97 === 0;
}

/**
 * Kiểm tra các tổng và payment timing trước khi ghi seed vận hành.
 *
 * @param list<list<mixed>> $previewRows
 * @param list<list<mixed>> $bookingRows
 * @param list<list<mixed>> $bookingItemRows
 * @param list<list<mixed>> $nightRows
 * @param list<list<mixed>> $paymentRows
 * @param array<int, array<string, mixed>> $products
 */
function assertOperationalInvariants(
    array $previewRows,
    array $bookingRows,
    array $bookingItemRows,
    array $nightRows,
    array $paymentRows,
    array $products,
): void {
    $previews = [];
    foreach ($previewRows as $row) {
        $previews[(int) $row[0]] = [
            'total' => (int) $row[7],
            'payNow' => (int) $row[8],
            'payAtProperty' => (int) $row[9],
            'allocation' => json_decode((string) $row[10], true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    $bookings = [];
    foreach ($bookingRows as $row) {
        $bookings[(int) $row[0]] = [
            'previewId' => (int) $row[3],
            'timing' => (string) $row[12],
            'total' => (int) $row[14],
        ];
    }

    $payments = [];
    foreach ($paymentRows as $row) {
        $payments[(int) $row[1]] = ['timing' => (string) $row[2], 'total' => (int) $row[4]];
    }

    $itemTotals = [];
    $itemBookings = [];
    $bookingItemSums = [];
    foreach ($bookingItemRows as $row) {
        $itemId = (int) $row[0];
        $bookingId = (int) $row[1];
        $productId = (int) $row[2];
        $itemTotals[$itemId] = (int) $row[9];
        $itemBookings[$itemId] = $bookingId;
        $bookingItemSums[$bookingId] = ($bookingItemSums[$bookingId] ?? 0) + (int) $row[9];
        if (($products[$productId]['timing'] ?? null) !== ($bookings[$bookingId]['timing'] ?? null)) {
            throw new RuntimeException("Hạng mục {$itemId} có timing khác booking {$bookingId}.");
        }
    }

    $nightSums = [];
    foreach ($nightRows as $row) {
        $itemId = (int) $row[0];
        $nightSums[$itemId] = ($nightSums[$itemId] ?? 0) + (int) $row[6];
        $bookingId = $itemBookings[$itemId] ?? null;
        if ($bookingId === null) {
            throw new RuntimeException("Giá đêm tham chiếu hạng mục không tồn tại: {$itemId}.");
        }
        if ($bookings[$bookingId]['timing'] === 'PAY_ONLINE' && (int) $row[5] !== 0) {
            throw new RuntimeException("Booking online {$bookingId} còn ThuePhiTaiCoSo khác 0.");
        }
    }

    foreach ($itemTotals as $itemId => $itemTotal) {
        if (($nightSums[$itemId] ?? 0) !== $itemTotal) {
            throw new RuntimeException("Tổng giá đêm không khớp hạng mục {$itemId}.");
        }
    }

    foreach ($bookings as $bookingId => $booking) {
        if (($bookingItemSums[$bookingId] ?? 0) !== $booking['total']) {
            throw new RuntimeException("Tổng hạng mục không khớp booking {$bookingId}.");
        }
        $preview = $previews[$booking['previewId']] ?? null;
        $payment = $payments[$bookingId] ?? null;
        if ($preview === null || $payment === null || $preview['total'] !== $booking['total'] || $payment['total'] !== $booking['total'] || $payment['timing'] !== $booking['timing']) {
            throw new RuntimeException("Preview/booking/payment không đồng bộ ở booking {$bookingId}.");
        }
        $expectedPayNow = $booking['timing'] === 'PAY_ONLINE' ? $booking['total'] : 0;
        $expectedPayAtProperty = $booking['timing'] === 'PAY_AT_PROPERTY' ? $booking['total'] : 0;
        if ($preview['payNow'] !== $expectedPayNow || $preview['payAtProperty'] !== $expectedPayAtProperty) {
            throw new RuntimeException("Khoản trả ngay/tại cơ sở không đúng ở preview {$booking['previewId']}.");
        }
        if (($preview['allocation']['paymentTiming'] ?? null) !== $booking['timing']) {
            throw new RuntimeException("JSON preview thiếu hoặc sai paymentTiming ở booking {$bookingId}.");
        }
        $roomTotal = 0;
        foreach (($preview['allocation']['rooms'] ?? []) as $room) {
            $nightTotal = 0;
            foreach (($room['nightPrices'] ?? []) as $night) {
                $nightTotal += (int) ($night['final'] ?? 0);
                if ($booking['timing'] === 'PAY_ONLINE' && (int) ($night['atProperty'] ?? 0) !== 0) {
                    throw new RuntimeException("JSON preview online còn khoản tại cơ sở ở booking {$bookingId}.");
                }
            }
            $roomTotal += $nightTotal;
            $snapshot = $room['paymentSnapshot'] ?? [];
            if (($snapshot['timing'] ?? null) !== $booking['timing']
                || (int) ($snapshot['payNow'] ?? -1) !== ($booking['timing'] === 'PAY_ONLINE' ? $nightTotal : 0)
                || (int) ($snapshot['payAtProperty'] ?? -1) !== ($booking['timing'] === 'PAY_AT_PROPERTY' ? $nightTotal : 0)) {
                throw new RuntimeException("Payment snapshot không đồng bộ ở booking {$bookingId}.");
            }
        }
        if ($roomTotal !== $booking['total']) {
            throw new RuntimeException("Tổng JSON preview không khớp booking {$bookingId}.");
        }
    }
}

mt_srand(DATA_SEED);

// 1. Reference catalog. City, property type and meal plan are CHECK/config in MVP.
$propertyFacilities = ['WIFI','PARKING','POOL','RESTAURANT','FRONT_DESK_24H','GYM','AIRPORT_SHUTTLE','SPA','ELEVATOR','GARDEN','FAMILY_FRIENDLY','PET_FRIENDLY'];
$roomFacilities = ['AIR_CONDITIONING','TV','BALCONY','MINIBAR','BATHTUB','KITCHENETTE','SAFE','DESK','HAIR_DRYER','SOUNDPROOF','CITY_VIEW','SEA_VIEW'];
$facilityRows = [];
$facilityId = 0;
foreach ($propertyFacilities as $code) {
    ++$facilityId;
    $facilityRows[] = [$facilityId, $code, ucwords(strtolower(str_replace('_', ' ', $code))), 'PROPERTY', 'HOAT_DONG'];
}
foreach ($roomFacilities as $code) {
    ++$facilityId;
    $facilityRows[] = [$facilityId, $code, ucwords(strtolower(str_replace('_', ' ', $code))), 'ROOM', 'HOAT_DONG'];
}
$sql = "-- Reference catalog.\nUSE hotel_booking;\nSET NAMES utf8mb4;\n\n";
$sql .= sqlInsert('TienNghi', ['TienNghiID','MaTienNghi','TenTienNghi','PhamVi','TrangThai'], $facilityRows);
if ($fullGeneration) {
    writeText($seedDir . '/001_reference.sql', $sql);
}

// 2. Accounts and partner organizations.
$passwordHash = '$2y$10$6e1V.I7AYrvoS3vtwUz4/OEXinJJfGTpCboBR6Dcn121CiiwcUmoO'; // Ntu@123456
$userRows = [[1, 'ADMIN001', 'Quản trị viên nền tảng', 'admin@accommodation.local', '0900000001', $passwordHash, 'ADMIN', 'HOAT_DONG', AS_OF_UTC, AS_OF_UTC]];
$organizationRows = [];
$memberRows = [];
for ($id = 1; $id <= 10; ++$id) {
    $userId = $id + 1;
    $userRows[] = [$userId, 'DT' . sprintf('%03d', $id), 'Đối tác lưu trú ' . sprintf('%02d', $id), 'partner' . sprintf('%02d', $id) . '@accommodation.local', '091' . sprintf('%07d', $id), $passwordHash, 'PARTNER', 'HOAT_DONG', AS_OF_UTC, AS_OF_UTC];
    $organizationRows[] = [$id, 'TC' . sprintf('%03d', $id), 'Công ty Lưu trú ' . sprintf('%02d', $id), 'contact' . sprintf('%02d', $id) . '@accommodation.local', '092' . sprintf('%07d', $id), 'HOAT_DONG', AS_OF_UTC];
    $memberRows[] = [$id, $userId, 1, AS_OF_UTC];
}
for ($id = 1; $id <= 500; ++$id) {
    $userId = $id + 11;
    $userRows[] = [$userId, 'KH' . sprintf('%04d', $id), 'Khách hàng ' . sprintf('%04d', $id), 'customer' . sprintf('%04d', $id) . '@example.test', '093' . sprintf('%07d', $id), $passwordHash, 'CUSTOMER', 'HOAT_DONG', AS_OF_UTC, AS_OF_UTC];
}
$sql = "-- Accounts; initial password: Ntu@123456\nUSE hotel_booking;\nSET NAMES utf8mb4;\n\n";
$sql .= sqlInsert('NguoiDung', ['NguoiDungID','MaNguoiDung','HoTen','Email','SoDienThoai','MatKhauHash','VaiTro','TrangThai','TaoLuc','CapNhatLuc'], $userRows);
$sql .= sqlInsert('ToChucDoiTac', ['ToChucDoiTacID','MaToChuc','TenToChuc','EmailLienHe','SoDienThoai','TrangThai','TaoLuc'], $organizationRows);
$sql .= sqlInsert('ThanhVienDoiTac', ['ToChucDoiTacID','NguoiDungID','LaChuSoHuu','TaoLuc'], $memberRows);
if ($fullGeneration) {
    writeText($seedDir . '/002_accounts_partners.sql', $sql);
}

// 3. Properties, rooms, images and facilities.
$imageMappingRelativePath = 'huongDan/majestic_hotel_image_pack_v2/image_mapping.json';
$imagePackManifestRelativePath = 'huongDan/majestic_hotel_image_pack_v2/MANIFEST.csv';
$imageAssetLockRelativePath = 'public/assets/images/accommodation/majestic/ASSET_LOCK.json';
$imageMapping = readJsonObject($root . '/' . $imageMappingRelativePath);
$imageAssignments = $imageMapping['assignments'] ?? null;
$sourceImageHotel = $imageMapping['sourceHotel'] ?? null;
if (!is_array($imageAssignments) || !is_array($sourceImageHotel)) {
    throw new RuntimeException('image_mapping.json thiếu assignments hoặc sourceHotel.');
}
$defaultImageAssignment = $imageAssignments['default'] ?? null;
$sourceImagePropertyCode = $sourceImageHotel['propertyCode'] ?? null;
$sourceImagePropertyName = $sourceImageHotel['name'] ?? null;
$sourceImageAssignment = is_string($sourceImagePropertyCode) ? ($imageAssignments[$sourceImagePropertyCode] ?? null) : null;
if (!is_array($defaultImageAssignment) || !is_string($sourceImagePropertyCode) || !is_string($sourceImagePropertyName) || !is_array($sourceImageAssignment)) {
    throw new RuntimeException('Cấu hình assignment ảnh không đầy đủ.');
}
$defaultPropertyImages = validateSeedImages($defaultImageAssignment['propertyImages'] ?? null, $root, 12, 'Ảnh property mặc định', true);
$defaultRoomImages = validateSeedImages($defaultImageAssignment['roomImages'] ?? null, $root, 12, 'Ảnh phòng mặc định', true);
$sourcePropertyImages = validateSeedImages($sourceImageAssignment['propertyImages'] ?? null, $root, 12, 'Ảnh Hotel Majestic');
$sourceRoomImagesByCode = $sourceImageAssignment['roomImagesByCode'] ?? null;
if (!is_array($sourceRoomImagesByCode) || array_is_list($sourceRoomImagesByCode)) {
    throw new RuntimeException('roomImagesByCode của cơ sở nguồn phải là object.');
}
foreach ($sourceRoomImagesByCode as $roomCode => $images) {
    if (!is_string($roomCode) || !preg_match('/^[A-Z0-9_]{2,24}$/', $roomCode)) {
        throw new RuntimeException('Mã loại phòng trong roomImagesByCode không hợp lệ.');
    }
    $sourceRoomImagesByCode[$roomCode] = validateSeedImages($images, $root, 12, 'Ảnh phòng nguồn ' . $roomCode);
}
$seedAssetPaths = [];
foreach ([$defaultPropertyImages, $defaultRoomImages, $sourcePropertyImages, ...array_values($sourceRoomImagesByCode)] as $imageList) {
    foreach ($imageList as $image) {
        $seedAssetPaths['public' . $image['path']] = true;
    }
}
$matchedSourceImageProperty = false;

$verifiedPropertyRows = readCsvRows($root . '/database/source/hcmc_accommodations_verified.csv', PROPERTY_SOURCE_HEADERS);
if (count($verifiedPropertyRows) !== 30) {
    throw new RuntimeException('Danh sách cơ sở đã đối chiếu phải có đúng 30 dòng.');
}
$seenSourceRows = [];
foreach ($verifiedPropertyRows as $index => $sourceProperty) {
    $expectedPropertyId = $index + 1;
    $propertyId = filter_var($sourceProperty['property_id'], FILTER_VALIDATE_INT);
    $sourceRow = filter_var($sourceProperty['source_row'], FILTER_VALIDATE_INT);
    if ($propertyId !== $expectedPropertyId) {
        throw new RuntimeException('Mã cơ sở trong CSV phải liên tục từ 1 đến 30 và đúng thứ tự.');
    }
    if ($sourceRow === false || $sourceRow < 2 || isset($seenSourceRows[$sourceRow])) {
        throw new RuntimeException('Số dòng Excel phải hợp lệ và không trùng trong CSV.');
    }
    $seenSourceRows[$sourceRow] = true;
    foreach (['source_name', 'verified_name', 'address'] as $requiredColumn) {
        if (trim($sourceProperty[$requiredColumn]) === '') {
            throw new RuntimeException('CSV thiếu giá trị bắt buộc tại cột ' . $requiredColumn . '.');
        }
    }
    if (!str_contains($sourceProperty['address'], 'Thành phố Hồ Chí Minh')) {
        throw new RuntimeException('Mọi địa chỉ trong CSV phải thuộc Thành phố Hồ Chí Minh.');
    }
    if (!in_array($sourceProperty['match_type'], ['KHOP_CHINH_XAC', 'KHOP_TEN_CU_BIET_DANH'], true)) {
        throw new RuntimeException('Loại kết quả đối chiếu trong CSV không hợp lệ.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sourceProperty['checked_at'])) {
        throw new RuntimeException('Ngày đối chiếu trong CSV phải theo dạng YYYY-MM-DD.');
    }
}
$roomTemplates = [
    1 => ['STD','Phòng đôi tiêu chuẩn',2,2,1,[['type' => 'DOUBLE', 'quantity' => 1]],24.0],
    2 => ['DLX','Phòng hai giường cao cấp',2,2,1,[['type' => 'SINGLE', 'quantity' => 2]],30.0],
    3 => ['FAM','Phòng gia đình',4,3,2,[['type' => 'DOUBLE', 'quantity' => 1], ['type' => 'SINGLE', 'quantity' => 2]],42.0],
    4 => ['STE','Phòng hạng sang',3,3,1,[['type' => 'KING', 'quantity' => 1], ['type' => 'SOFA_BED', 'quantity' => 1]],48.0],
];
$propertyRows = $roomRows = $propertyFacilityRows = $roomFacilityRows = [];
$properties = $rooms = [];
$roomId = 0;
foreach ($verifiedPropertyRows as $sourceProperty) {
    $propertyId = (int) $sourceProperty['property_id'];
    if ($propertyId < 1 || $propertyId > 30) {
        throw new RuntimeException('Mã cơ sở trong CSV phải nằm từ 1 đến 30.');
    }
    $orgId = (($propertyId - 1) % 10) + 1;
    $code = 'CS' . sprintf('%03d', $propertyId);
    $name = $sourceProperty['verified_name'];
    $slug = $code . '-' . slugify($name);
    $state = $propertyId <= 27 ? 'ACTIVE' : ($propertyId === 28 ? 'DRAFT' : ($propertyId === 29 ? 'PENDING_REVIEW' : 'SUSPENDED'));
    $mapQuery = rawurlencode($name . ', ' . $sourceProperty['address']);
    $mapUrl = 'https://www.google.com/maps/search/?api=1&query=' . $mapQuery;
    if ($code === $sourceImagePropertyCode) {
        if ($name !== $sourceImagePropertyName) {
            throw new RuntimeException('Cơ sở nhận ảnh thật không khớp tên nguồn trong image_mapping.json.');
        }
        $matchedSourceImageProperty = true;
        $propertyImages = $sourcePropertyImages;
    } else {
        $propertyImages = $defaultPropertyImages;
    }
    $reviewed = in_array($state, ['ACTIVE','SUSPENDED'], true);
    $propertyRows[] = [
        $propertyId, $code, $orgId, 'HCM', 'HOTEL', $name, $sourceProperty['source_name'],
        'HCMC_TOURISM_XLSX_2024', 'ROW-' . $sourceProperty['source_row'], $slug,
        $sourceProperty['address'], 'PUBLIC_WEB_CROSS_CHECK', dt($sourceProperty['checked_at']),
        'Tên lấy từ danh mục Sở Du lịch; địa chỉ được đối chiếu từ nguồn công khai. Phòng, giá và tồn phòng là dữ liệu phát triển.',
        jsonData($propertyImages), null, 'NOT_CHECKED', null, null, null, null, null,
        $sourceProperty['match_type'], $mapUrl, null, 8.00, 1, 5.00, 0,
        '14:00:00', '12:00:00', 'Asia/Ho_Chi_Minh', $state,
        $reviewed ? 1 : null, $reviewed ? AS_OF_UTC : null,
        $state === 'SUSPENDED' ? 'Tạm ngưng trong bộ dữ liệu phát triển.' : null,
        AS_OF_UTC, AS_OF_UTC,
    ];
    $properties[$propertyId] = ['id' => $propertyId, 'orgId' => $orgId, 'state' => $state, 'code' => $code, 'name' => $name];
    for ($f = 0; $f < 6; ++$f) {
        $propertyFacilityRows[] = [$propertyId, (($propertyId + $f - 1) % 12) + 1, 1, null];
    }
    for ($roomIndex = 1; $roomIndex <= 4; ++$roomIndex) {
        ++$roomId;
        [$roomCode, $roomName, $maxGuests, $maxAdults, $maxChildren, $beds, $area] = $roomTemplates[$roomIndex];
        $roomImages = $code === $sourceImagePropertyCode && isset($sourceRoomImagesByCode[$roomCode])
            ? $sourceRoomImagesByCode[$roomCode]
            : $defaultRoomImages;
        $roomRows[] = [$roomId, $propertyId, $roomCode, $roomName, 'Không gian lưu trú ' . $roomName . ' được bố trí tiện nghi cơ bản.', $maxGuests, $maxAdults, $maxChildren, jsonData($beds), $area, 0, jsonData($roomImages), 'ACTIVE', AS_OF_UTC, AS_OF_UTC];
        $rooms[$roomId] = ['id' => $roomId, 'propertyId' => $propertyId, 'roomIndex' => $roomIndex, 'code' => $roomCode, 'name' => $roomName, 'maxGuests' => $maxGuests, 'maxAdults' => $maxAdults, 'maxChildren' => $maxChildren];
        for ($f = 0; $f < 5; ++$f) {
            $roomFacilityRows[] = [$roomId, 13 + (($roomId + $f - 1) % 12), null, null];
        }
    }
}
if (!$matchedSourceImageProperty) {
    throw new RuntimeException('Không tìm thấy cơ sở nguồn ảnh ' . $sourceImagePropertyCode . ' trong CSV.');
}

$sql = "-- Properties, room types and facilities.\nUSE hotel_booking;\nSET NAMES utf8mb4;\n\n";
$sql .= sqlInsert('CoSoLuuTru', ['CoSoLuuTruID','MaCoSo','ToChucDoiTacID','MaThanhPho','LoaiCoSo','TenCoSo','TenTrongNguon','NguonTen','MaBanGhiNguon','Slug','DiaChi','NguonDiaChi','DiaChiXacMinhLuc','MoTa','AnhJSON','GooglePlaceID','GoogleMatchStatus','GoogleVerifiedAt','ViDo','KinhDo','NguonToaDo','ToaDoXacMinhLuc','TrangThaiDoiChieu','DuongDanBanDo','SoSao','TyLeThue','ThueDaBaoGom','TyLePhiDichVu','PhiDichVuDaBaoGom','GioNhanPhong','GioTraPhong','MuiGio','TrangThai','NguoiDuyetID','DuyetLuc','GhiChuDuyet','TaoLuc','CapNhatLuc'], $propertyRows);
$sql .= sqlInsert('LoaiPhong', ['LoaiPhongID','CoSoLuuTruID','MaLoaiPhong','TenLoaiPhong','MoTa','SoKhachToiDa','SoNguoiLonToiDa','SoTreEmToiDa','CauHinhGiuongJSON','DienTichM2','ChoPhepHutThuoc','AnhJSON','TrangThai','TaoLuc','CapNhatLuc'], $roomRows);
$sql .= sqlInsert('CoSoTienNghi', ['CoSoLuuTruID','TienNghiID','MienPhi','GhiChu'], $propertyFacilityRows);
$sql .= sqlInsert('LoaiPhongTienNghi', ['LoaiPhongID','TienNghiID','SoLuong','GhiChu'], $roomFacilityRows);
if ($fullGeneration || $propertiesOnly) {
    writeText($seedDir . '/003_properties.sql', $sql);
}

// 4. Policies, room products and property-wide promotions.
$policyRows = $productRows = $promotionRows = [];
$products = $productsByPropertyTiming = [];
$productId = 0;
for ($propertyId = 1; $propertyId <= 30; ++$propertyId) {
    $policyBase = (($propertyId - 1) * 3);
    $policyRows[] = [$policyBase + 1, $propertyId, 'FLEX_1D', 'Linh hoạt 1 ngày', 'FLEXIBLE', 24, 'FIRST_NIGHT', 1, 100, 'ACTIVE'];
    $policyRows[] = [$policyBase + 2, $propertyId, 'FLEX_2D_50', 'Linh hoạt 2 ngày, phí 50%', 'FLEXIBLE', 48, 'PERCENT', 50, 100, 'ACTIVE'];
    $policyRows[] = [$policyBase + 3, $propertyId, 'NON_REF', 'Không hoàn tiền', 'NON_REFUNDABLE', null, 'FULL_STAY', 100, 100, 'ACTIVE'];
    for ($roomIndex = 1; $roomIndex <= 4; ++$roomIndex) {
        $currentRoomId = (($propertyId - 1) * 4) + $roomIndex;
        ++$productId;
        $product = ['id' => $productId, 'propertyId' => $propertyId, 'roomId' => $currentRoomId, 'roomIndex' => $roomIndex, 'variant' => 1, 'timing' => 'PAY_AT_PROPERTY', 'policyId' => $policyBase + (($roomIndex % 2) + 1), 'mealId' => $roomIndex % 2 === 0 ? 2 : 1];
        $products[$productId] = $product;
        $productsByPropertyTiming[$propertyId]['PAY_AT_PROPERTY'][] = $product;
        $productRows[] = [$productId, $currentRoomId, $product['policyId'], 'SP' . sprintf('%04d', $productId), 'Giá linh hoạt', $product['mealId'] === 2 ? 'BREAKFAST_INCLUDED' : 'ROOM_ONLY', 'PAY_AT_PROPERTY', 'ACTIVE'];
        if ($roomIndex <= 2) {
            ++$productId;
            $product = ['id' => $productId, 'propertyId' => $propertyId, 'roomId' => $currentRoomId, 'roomIndex' => $roomIndex, 'variant' => 2, 'timing' => 'PAY_ONLINE', 'policyId' => $policyBase + 3, 'mealId' => $roomIndex === 2 ? 2 : 1];
            $products[$productId] = $product;
            $productsByPropertyTiming[$propertyId]['PAY_ONLINE'][] = $product;
            $productRows[] = [$productId, $currentRoomId, $product['policyId'], 'SP' . sprintf('%04d', $productId), 'Giá tiết kiệm', $product['mealId'] === 2 ? 'BREAKFAST_INCLUDED' : 'ROOM_ONLY', 'PAY_ONLINE', 'ACTIVE'];
        }
    }
    for ($p = 1; $p <= 2; ++$p) {
        $promotionId = (($propertyId - 1) * 2) + $p;
        $promotionRows[] = [$promotionId, $propertyId, 'KM' . sprintf('%03d', $promotionId), $p === 1 ? 'Ưu đãi đặt sớm' : 'Ưu đãi kỳ nghỉ', $p === 1 ? 10 : 7, '2026-10-01 00:00:00.000000', '2027-03-15 23:59:59.000000', '2026-10-05', '2027-03-31', 'ACTIVE'];
    }
}

$sql = "-- Policies, room products and property-wide promotions.\nUSE hotel_booking;\nSET NAMES utf8mb4;\n\n";
$sql .= sqlInsert('ChinhSachHuy', ['ChinhSachHuyID','CoSoLuuTruID','MaChinhSach','TenChinhSach','LoaiChinhSach','SoGioHuyMienPhi','LoaiPhat','GiaTriPhat','TyLePhatNoShow','TrangThai'], $policyRows);
$sql .= sqlInsert('SanPhamPhong', ['SanPhamPhongID','LoaiPhongID','ChinhSachHuyID','MaSanPham','TenSanPham','LoaiBuaAn','ThoiDiemThanhToan','TrangThai'], $productRows);
$sql .= sqlInsert('KhuyenMai', ['KhuyenMaiID','CoSoLuuTruID','MaKhuyenMai','TenKhuyenMai','PhanTramGiam','DatTu','DatDen','LuuTruTu','LuuTruDen','TrangThai'], $promotionRows);
if ($fullGeneration) {
    writeText($seedDir . '/004_products_policies.sql', $sql);
}

// 5/6. Operational model first, so inventory counters can be derived from active ledger.
$previewRows = $bookingRows = $bookingItemRows = $nightRows = [];
$ledgerRows = $paymentRows = $waiverRows = [];
$reviewRows = $auditRows = [];
$reserved = [];
$bookingModels = [];
$bookingItemId = $ledgerId = $auditId = 0;

for ($bookingId = 1; $bookingId <= 2000; ++$bookingId) {
    $propertyId = (($bookingId - 1) % 27) + 1;
    $customerId = 12 + (($bookingId - 1) % 500);
    if ($bookingId <= 800) {
        $state = 'COMPLETED';
        $checkin = datePlus('2026-07-10', $bookingId % 75);
    } elseif ($bookingId <= 900) {
        $state = 'NO_SHOW';
        $checkin = datePlus('2026-08-15', $bookingId % 40);
    } elseif ($bookingId <= 1200) {
        $state = 'CANCELLED';
        $checkin = datePlus('2026-10-10', ($bookingId * 3) % 130);
    } elseif ($bookingId <= 1700) {
        $state = 'CONFIRMED';
        $checkin = datePlus('2026-10-06', ($bookingId * 5) % 160);
    } elseif ($bookingId <= 1800) {
        $state = 'PENDING_PAYMENT';
        $checkin = datePlus('2026-10-08', ($bookingId * 7) % 45);
    } else {
        $state = 'PAYMENT_FAILED';
        $checkin = datePlus('2026-10-10', ($bookingId * 11) % 120);
    }
    $nights = 2 + ($bookingId % 4);
    $checkout = datePlus($checkin, $nights);
    $timing = in_array($state, ['PENDING_PAYMENT','PAYMENT_FAILED'], true) ? 'PAY_ONLINE' : ($bookingId % 2 === 0 ? 'PAY_AT_PROPERTY' : 'PAY_ONLINE');
    $itemCount = $bookingId % 4 === 0 ? 2 : 1;
    $availableProducts = $productsByPropertyTiming[$propertyId][$timing];
    $selected = [];
    for ($itemIndex = 0; $itemIndex < $itemCount; ++$itemIndex) {
        $product = $availableProducts[($bookingId + $itemIndex) % count($availableProducts)];
        if ($itemCount === 2 && $bookingId % 8 === 0) {
            $product = $availableProducts[$bookingId % count($availableProducts)];
        }
        $selected[] = $product;
    }
    $holdsInventory = in_array($state, ['CONFIRMED','PENDING_PAYMENT'], true);
    if ($holdsInventory) {
        for ($shift = 0; $shift < 180 - $nights; ++$shift) {
            $candidate = datePlus($checkin, $shift);
            $demand = [];
            foreach ($selected as $product) {
                for ($night = 0; $night < $nights; ++$night) {
                    $date = datePlus($candidate, $night);
                    $dayOffset = (int) (new DateTimeImmutable('2026-10-05'))->diff(new DateTimeImmutable($date))->format('%a');
                    $key = $product['roomId'] . '|' . $date;
                    $demand[$key] = ($demand[$key] ?? 0) + 1;
                    if (isClosedDay($product['roomId'], $dayOffset) || (($reserved[$key] ?? 0) + $demand[$key]) > roomInventory($product['roomIndex'])) {
                        continue 3;
                    }
                }
            }
            $checkin = $candidate;
            $checkout = datePlus($checkin, $nights);
            foreach ($demand as $key => $quantity) {
                $reserved[$key] = ($reserved[$key] ?? 0) + $quantity;
            }
            break;
        }
    }

    $createdDate = $state === 'COMPLETED' || $state === 'NO_SHOW' ? datePlus($checkin, -20) : '2026-10-05';
    $createdAt = dt($createdDate, sprintf('%02d:%02d:00.000000', 8 + ($bookingId % 10), $bookingId % 60));
    $expiresAt = (new DateTimeImmutable(substr($createdAt, 0, 19), new DateTimeZone('UTC')))->modify('+15 minutes')->format('Y-m-d H:i:s.000000');
    $holdExpiry = $state === 'PENDING_PAYMENT' ? '2026-10-05 12:10:00.000000' : null;
    $total = 0;
    $itemModels = [];
    foreach ($selected as $itemIndex => $product) {
        $itemTotal = 0;
        $nightModels = [];
        for ($night = 0; $night < $nights; ++$night) {
            $date = datePlus($checkin, $night);
            $price = nightPrice($product, $date, $bookingId % 3 === 0);
            $itemTotal += $price['final'];
            $nightModels[] = ['date' => $date, 'price' => $price];
        }
        $total += $itemTotal;
        $room = $rooms[$product['roomId']];
        $children = ($bookingId + $itemIndex + 1) % 5 === 0 && $room['maxChildren'] > 0 ? [7] : [];
        $itemModels[] = [
            'product' => $product,
            'total' => $itemTotal,
            'nights' => $nightModels,
            'order' => $itemIndex + 1,
            'adults' => min(2, $room['maxAdults']),
            'children' => $children,
        ];
    }

    $previewAllocations = [];
    foreach ($itemModels as $itemModel) {
        $product = $itemModel['product'];
        $priceSnapshot = [];
        foreach ($itemModel['nights'] as $nightModel) {
            $priceSnapshot[] = ['date' => $nightModel['date']] + $nightModel['price'];
        }
        $policySnapshot = ['policyId' => $product['policyId'], 'timezone' => 'Asia/Ho_Chi_Minh', 'schedule' => $timing === 'PAY_ONLINE' ? [['from' => 'now', 'fee' => $itemModel['total']]] : [['from' => 'now', 'fee' => 0], ['from' => dt($checkin, '00:00:00.000000'), 'feeType' => 'FIRST_NIGHT']]];
        $previewAllocations[] = [
            'roomOrder' => $itemModel['order'],
            'productId' => $product['id'],
            'roomTypeId' => $product['roomId'],
            'adults' => $itemModel['adults'],
            'childAges' => $itemModel['children'],
            'nightPrices' => $priceSnapshot,
            'cancellationSnapshot' => $policySnapshot,
            'paymentSnapshot' => ['timing' => $timing, 'payNow' => $timing === 'PAY_ONLINE' ? $itemModel['total'] : 0, 'payAtProperty' => $timing === 'PAY_AT_PROPERTY' ? $itemModel['total'] : 0],
        ];
    }
    $allocationDocument = [
        'version' => 1,
        'criteria' => ['checkIn' => $checkin, 'checkOut' => $checkout],
        'paymentTiming' => $timing,
        'rooms' => $previewAllocations,
    ];
    $previewRows[] = [$bookingId, hash('sha256', 'preview-token-' . $bookingId), $customerId, $propertyId, $checkin, $checkout, 'VND', $total, $timing === 'PAY_ONLINE' ? $total : 0, $timing === 'PAY_AT_PROPERTY' ? $total : 0, jsonData($allocationDocument), hash('sha256', jsonData([$propertyId,$checkin,$checkout,$allocationDocument,$total])), 'USED', $expiresAt, $createdAt, $createdAt];

    $confirmedAt = in_array($state, ['CONFIRMED','COMPLETED','NO_SHOW','CANCELLED'], true) ? $createdAt : null;
    $cancelledAt = $state === 'CANCELLED' ? AS_OF_UTC : null;
    $completedAt = $state === 'COMPLETED' ? dt($checkout, '05:00:00.000000') : null;
    $cancellationFee = $state === 'CANCELLED' && $bookingId % 2 === 0 ? (int) round($total * 0.10) : 0;
    $cancellationRefund = $state === 'CANCELLED' && $timing === 'PAY_ONLINE' ? max(0, $total - $cancellationFee) : 0;
    $cancelKey = $state === 'CANCELLED' ? uuidFromInt(200000 + $bookingId) : null;
    $cancelActor = $state === 'CANCELLED' ? $customerId : null;
    $cancelReason = $state === 'CANCELLED' ? 'Khách thay đổi kế hoạch lưu trú.' : null;
    $cancelSnapshot = $state === 'CANCELLED' ? jsonData(['version' => 1, 'total' => $total, 'fee' => $cancellationFee, 'refund' => $cancellationRefund, 'calculatedAt' => AS_OF_UTC]) : null;
    $noShowActor = $state === 'NO_SHOW' ? (($propertyId - 1) % 10) + 2 : null;
    $noShowAt = $state === 'NO_SHOW' ? dt($checkin, '23:00:00.000000') : null;
    $noShowFee = $state === 'NO_SHOW' ? $total : 0;
    $noShowSnapshot = $state === 'NO_SHOW' ? jsonData(['version' => 1, 'feeRate' => 100, 'fee' => $noShowFee, 'inventoryReleased' => true]) : null;
    $bookingRows[] = [
        $bookingId, 'BK' . sprintf('%010d', $bookingId), uuidFromInt($bookingId), $bookingId,
        $customerId, $propertyId, $checkin, $checkout,
        'Khách hàng ' . sprintf('%04d', (($bookingId - 1) % 500) + 1),
        'customer' . sprintf('%04d', (($bookingId - 1) % 500) + 1) . '@example.test',
        '093' . sprintf('%07d', (($bookingId - 1) % 500) + 1),
        $bookingId % 6 === 0 ? 'Ưu tiên phòng yên tĩnh nếu có thể.' : null,
        $timing, 'VND', $total, $state, $holdExpiry, $confirmedAt, $completedAt,
        $cancelKey, $cancelActor, $cancelledAt, $cancelReason, $cancellationFee, $cancellationRefund, $cancelSnapshot,
        $noShowActor, $noShowAt, $noShowFee, $noShowSnapshot,
        $createdAt, $createdAt,
    ];

    foreach ($itemModels as $itemModel) {
        ++$bookingItemId;
        $product = $itemModel['product'];
        $room = $rooms[$product['roomId']];
        $mealType = $product['mealId'] === 2 ? 'BREAKFAST_INCLUDED' : 'ROOM_ONLY';
        $conditionSnapshot = ['paymentTiming' => $timing, 'mealType' => $mealType, 'adults' => $itemModel['adults']];
        $cancelSnapshot = ['policyId' => $product['policyId'], 'timezone' => 'Asia/Ho_Chi_Minh', 'absoluteFeeAtBooking' => $timing === 'PAY_ONLINE' ? $itemModel['total'] : 0];
        $guests = [];
        for ($adult = 1; $adult <= $itemModel['adults']; ++$adult) {
            $guests[] = ['name' => 'Khách lưu trú ' . $bookingId . '-' . $itemModel['order'] . '-' . $adult, 'type' => 'ADULT', 'age' => null];
        }
        foreach ($itemModel['children'] as $childAge) {
            $guests[] = ['name' => 'Trẻ em ' . $bookingId . '-' . $itemModel['order'], 'type' => 'CHILD', 'age' => $childAge];
        }
        $bookingItemRows[] = [$bookingItemId, $bookingId, $product['id'], $product['roomId'], $itemModel['order'], $room['name'], $product['variant'] === 1 ? 'Giá linh hoạt' : 'Giá tiết kiệm', $mealType, jsonData($guests), $itemModel['total'], jsonData($conditionSnapshot), jsonData($cancelSnapshot)];
        foreach ($itemModel['nights'] as $nightModel) {
            $price = $nightModel['price'];
            $nightRows[] = [$bookingItemId, $nightModel['date'], $price['base'], $price['discount'], $price['included'], $price['atProperty'], $price['final'], $price['promotionId'], jsonData(['currency' => 'VND', 'rounding' => 'HALF_UP_TO_1_VND'])];
            ++$ledgerId;
            $active = $holdsInventory;
            $releasedAt = $active ? null : ($state === 'COMPLETED' ? dt($checkout, '05:00:00.000000') : AS_OF_UTC);
            $releaseReason = $active ? null : ($state === 'CANCELLED' ? 'CANCELLED' : ($state === 'PAYMENT_FAILED' ? 'PAYMENT_FAILED' : ($state === 'NO_SHOW' ? 'NO_SHOW' : 'STAY_COMPLETED')));
            $ledgerRows[] = [$ledgerId, $bookingItemId, $product['roomId'], $nightModel['date'], $active ? 'ACTIVE' : 'RELEASED', $state === 'PENDING_PAYMENT' ? $holdExpiry : null, $createdAt, $releasedAt, $releaseReason];
        }
    }

    if ($timing === 'PAY_ONLINE') {
        $paymentState = $state === 'PENDING_PAYMENT' ? 'PROCESSING' : ($state === 'PAYMENT_FAILED' ? 'FAILED' : 'PAID');
        $paid = $paymentState === 'PAID' ? $total : 0;
        $refunded = $state === 'CANCELLED' ? $cancellationRefund : 0;
        $hasRefund = $refunded > 0;
        $paymentRows[] = [
            $bookingId, $bookingId, $timing, $paymentState, $total, $paid, $refunded, 'VND',
            'MOCK', uuidFromInt(100000 + $bookingId),
            $paymentState === 'PROCESSING' ? null : 'PAY' . sprintf('%08d', $bookingId),
            1, $paymentState === 'FAILED' ? 'PAYMENT_DECLINED' : null,
            $paymentState === 'PROCESSING' ? null : AS_OF_UTC,
            $hasRefund ? uuidFromInt(300000 + $bookingId) : null,
            $hasRefund ? 'SUCCEEDED' : 'NONE',
            $hasRefund ? 'REF' . sprintf('%08d', $bookingId) : null,
            $hasRefund ? 'Hoàn tiền sau khi hủy đặt chỗ' : null,
            $hasRefund ? AS_OF_UTC : null,
            $createdAt, AS_OF_UTC,
        ];
    } else {
        $paymentRows[] = [$bookingId, $bookingId, $timing, 'NOT_TRACKED', $total, 0, 0, 'VND', null, null, null, 0, null, null, null, 'NONE', null, null, null, $createdAt, AS_OF_UTC];
    }

    ++$auditId;
    $auditRows[] = [$auditId, $customerId, 'BOOKING_CREATED', 'DatCho', (string) $bookingId, 'REQ-' . sprintf('%08d', $bookingId), null, jsonData(['status' => $state, 'total' => $total]), '127.0.0.1', $createdAt];
    $bookingModels[$bookingId] = ['propertyId' => $propertyId, 'customerId' => $customerId, 'state' => $state, 'timing' => $timing, 'checkin' => $checkin, 'checkout' => $checkout, 'total' => $total];
}

// Modifications are audit events in MVP; the current booking remains the source of truth.
$modificationCount = 0;
foreach ($bookingModels as $bookingId => $booking) {
    if ($modificationCount >= 100) {
        break;
    }
    if ($booking['state'] !== 'CONFIRMED' || $booking['timing'] !== 'PAY_AT_PROPERTY') {
        continue;
    }
    ++$modificationCount;
    ++$auditId;
    $auditRows[] = [$auditId, $booking['customerId'], 'BOOKING_GUEST_INFO_CHANGED', 'DatCho', (string) $bookingId, 'MOD-' . sprintf('%06d', $modificationCount), jsonData(['guestNote' => null]), jsonData(['guestNote' => 'Cập nhật tên khách ở']), '127.0.0.1', AS_OF_UTC];
}

// 200 waiver requests: 100 approved cancelled, 50 rejected + 50 withdrawn confirmed.
$waiverId = 0;
foreach ($bookingModels as $bookingId => $booking) {
    if ($waiverId >= 200) {
        break;
    }
    $target = null;
    if ($waiverId < 100 && $booking['state'] === 'CANCELLED') {
        $target = 'APPROVED';
    } elseif ($waiverId >= 100 && $booking['state'] === 'CONFIRMED') {
        $target = $waiverId < 150 ? 'REJECTED' : 'WITHDRAWN';
    }
    if ($target === null) {
        continue;
    }
    ++$waiverId;
    $partnerUserId = (($booking['propertyId'] - 1) % 10) + 2;
    $waiverRows[] = [$waiverId, $bookingId, $booking['customerId'], 'Đề nghị xem xét miễn phí hủy do thay đổi kế hoạch.', $target, $target === 'WITHDRAWN' ? null : $partnerUserId, $target === 'APPROVED' ? 'Đối tác đồng ý hỗ trợ.' : ($target === 'REJECTED' ? 'Không đủ điều kiện miễn phí hủy.' : null), '2026-10-05 09:00:00.000000', $target === 'WITHDRAWN' ? null : AS_OF_UTC];
    ++$auditId;
    $auditRows[] = [$auditId, $target === 'WITHDRAWN' ? $booking['customerId'] : $partnerUserId, 'CANCELLATION_REQUEST_' . $target, 'YeuCauHuyMienPhi', (string) $waiverId, 'WAIVER-' . sprintf('%06d', $waiverId), null, jsonData(['status' => $target]), '127.0.0.1', AS_OF_UTC];
}

// Reviews for all 800 completed bookings.
for ($reviewId = 1; $reviewId <= 800; ++$reviewId) {
    $booking = $bookingModels[$reviewId];
    $score = 7 + ($reviewId % 4);
    $reviewRows[] = [$reviewId, $reviewId, $booking['propertyId'], $booking['customerId'], $score, $score, min(10, $score + 1), $score, $score, max(1, $score - 1), $score, 'Kỳ nghỉ thuận tiện, thông tin đặt chỗ rõ ràng.', $reviewId % 20 === 0 ? 'HIDDEN' : 'PUBLISHED', AS_OF_UTC];
    ++$auditId;
    $auditRows[] = [$auditId, $booking['customerId'], 'REVIEW_CREATED', 'DanhGia', (string) $reviewId, 'REVIEW-' . sprintf('%06d', $reviewId), null, jsonData(['score' => $score]), '127.0.0.1', AS_OF_UTC];
}
for ($propertyId = 1; $propertyId <= 30; ++$propertyId) {
    ++$auditId;
    $auditRows[] = [$auditId, (($propertyId - 1) % 10) + 2, 'PROPERTY_SEEDED', 'CoSoLuuTru', (string) $propertyId, null, null, jsonData(['status' => $properties[$propertyId]['state']]), '127.0.0.1', AS_OF_UTC];
}

// Daily inventory and prices, now with counters derived from active ledger.
$inventoryRows = $dailyRateRows = [];
$inventoryStart = '2026-10-05';
foreach ($rooms as $currentRoomId => $room) {
    for ($offset = 0; $offset < INVENTORY_DAYS; ++$offset) {
        $date = datePlus($inventoryStart, $offset);
        $key = $currentRoomId . '|' . $date;
        $inventoryRows[] = [$currentRoomId, $date, roomInventory($room['roomIndex']), $reserved[$key] ?? 0, 1, AS_OF_UTC];
    }
}
foreach ($products as $currentProductId => $product) {
    for ($offset = 0; $offset < INVENTORY_DAYS; ++$offset) {
        $date = datePlus($inventoryStart, $offset);
        $dailyRateRows[] = [
            $currentProductId,
            $date,
            basePrice($product['propertyId'], $product['roomIndex'], $product['variant'], $date),
            isClosedDay($product['roomId'], $offset),
            1,
            $product['timing'] === 'PAY_ONLINE' ? 10 : 14,
            0,
            1,
            AS_OF_UTC,
        ];
    }
}
$sql = "-- Daily inventory and prices for a 180-day development horizon.\nUSE hotel_booking;\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n\n";
$sql .= sqlInsert('TonPhongNgay', ['LoaiPhongID','NgayLuuTru','TongSoLuong','SoLuongDaGiu','PhienBan','CapNhatLuc'], $inventoryRows);
$sql .= sqlInsert('GiaPhongNgay', ['SanPhamPhongID','NgayLuuTru','GiaCoBan','DongBan','SoDemToiThieu','SoDemToiDa','SoNgayDatTruoc','PhienBan','CapNhatLuc'], $dailyRateRows);
$sql .= "COMMIT;\n";
if ($fullGeneration) {
    writeText($seedDir . '/005_inventory_prices.sql', $sql);
}

assertOperationalInvariants($previewRows, $bookingRows, $bookingItemRows, $nightRows, $paymentRows, $products);

$sql = "-- Preview, booking, inventory ledger, payment, cancellation and review fixtures.\nUSE hotel_booking;\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n\n";
$sql .= sqlInsert('XemTruocDatCho', ['XemTruocDatChoID','TokenHash','KhachHangID','CoSoLuuTruID','NgayNhanPhong','NgayTraPhong','TienTe','TongTien','SoTienTraNgay','SoTienTraTaiCoSo','PhanBoJSON','Fingerprint','TrangThai','HetHanLuc','TaoLuc','DaDungLuc'], $previewRows);
$sql .= sqlInsert('DatCho', ['DatChoID','MaDatCho','ClientRequestID','XemTruocDatChoID','KhachHangID','CoSoLuuTruID','NgayNhanPhong','NgayTraPhong','TenNguoiDat','EmailNguoiDat','DienThoaiNguoiDat','YeuCauDacBiet','ThoiDiemThanhToan','TienTe','TongTien','TrangThai','HetHanThanhToanLuc','XacNhanLuc','HoanThanhLuc','HuyIdempotencyKey','NguoiHuyID','HuyLuc','LyDoHuy','PhiHuy','SoTienHoan','TinhHuySnapshotJSON','NguoiDanhDauNoShowID','NoShowLuc','PhiNoShow','NoShowSnapshotJSON','TaoLuc','CapNhatLuc'], $bookingRows);
$sql .= sqlInsert('HangMucDatCho', ['HangMucDatChoID','DatChoID','SanPhamPhongID','LoaiPhongID','ThuTuPhong','TenLoaiPhongSnapshot','TenSanPhamSnapshot','LoaiBuaAnSnapshot','KhachJSON','TongTien','SnapshotDieuKienJSON','SnapshotHuyJSON'], $bookingItemRows);
$sql .= sqlInsert('GiaDemDatCho', ['HangMucDatChoID','NgayLuuTru','GiaCoBan','TienGiam','ThuePhiDaGom','ThuePhiTaiCoSo','ThanhTien','KhuyenMaiID','SnapshotJSON'], $nightRows);
$sql .= sqlInsert('GiuTonPhongDem', ['GiuTonPhongDemID','HangMucDatChoID','LoaiPhongID','NgayLuuTru','TrangThai','HetHanLuc','GiuLuc','NhaLuc','LyDoNha'], $ledgerRows);
$sql .= sqlInsert('ThanhToan', ['ThanhToanID','DatChoID','ThoiDiemThanhToan','TrangThai','TongPhaiTra','DaThanhToan','DaHoan','TienTe','Provider','IdempotencyKey','ProviderReference','SoLanThu','MaLoiCuoi','XuLyLuc','HoanTienIdempotencyKey','TrangThaiHoanTien','ProviderRefundReference','LyDoHoanTien','HoanTienHoanTatLuc','TaoLuc','CapNhatLuc'], $paymentRows);
$sql .= sqlInsert('YeuCauHuyMienPhi', ['YeuCauHuyMienPhiID','DatChoID','NguoiYeuCauID','LyDo','TrangThai','NguoiXuLyID','PhanHoi','TaoLuc','XuLyLuc'], $waiverRows);
$sql .= sqlInsert('DanhGia', ['DanhGiaID','DatChoID','CoSoLuuTruID','KhachHangID','DiemTong','DiemSachSe','DiemViTri','DiemNhanVien','DiemThoaiMai','DiemTienNghi','DiemDangTien','BinhLuan','TrangThai','TaoLuc'], $reviewRows);
$sql .= sqlInsert('NhatKyHeThong', ['NhatKyHeThongID','NguoiThucHienID','HanhDong','LoaiDoiTu','DoiTuID','RequestID','DuLieuTruocJSON','DuLieuSauJSON','DiaChiIP','TaoLuc'], $auditRows);
$sql .= "COMMIT;\n";
if ($fullGeneration || $operationalOnly) {
    writeText($seedDir . '/006_operational.sql', $sql);
}

$generatedFiles = [
    'database/seeds/001_reference.sql',
    'database/seeds/002_accounts_partners.sql',
    'database/seeds/003_properties.sql',
    'database/seeds/004_products_policies.sql',
    'database/seeds/005_inventory_prices.sql',
    'database/seeds/006_operational.sql',
];
$hashes = [];
foreach ($generatedFiles as $relativePath) {
    $hashes[$relativePath] = hash_file('sha256', $root . '/' . $relativePath);
}
$counts = [
    'facilities' => count($facilityRows),
    'users' => count($userRows), 'partnerOrganizations' => count($organizationRows), 'properties' => count($propertyRows),
    'roomTypes' => count($roomRows), 'roomProducts' => count($productRows), 'dailyInventory' => count($inventoryRows),
    'dailyPrices' => count($dailyRateRows), 'previews' => count($previewRows), 'bookings' => count($bookingRows), 'bookingItems' => count($bookingItemRows),
    'nightPriceSnapshots' => count($nightRows), 'inventoryLedgerRows' => count($ledgerRows), 'payments' => count($paymentRows),
    'cancellationRequests' => count($waiverRows), 'reviews' => count($reviewRows), 'promotions' => count($promotionRows),
    'auditRows' => count($auditRows), 'googlePlacesMatches' => 0,
];
$sourceFiles = [
    'database/source/hcmc_accommodations_verified.csv' => hash_file('sha256', $root . '/database/source/hcmc_accommodations_verified.csv'),
];
$sourceDependencyPaths = [
    $imageMappingRelativePath,
    $imagePackManifestRelativePath,
    $imageAssetLockRelativePath,
    ...array_keys($seedAssetPaths),
];
foreach (array_values(array_unique($sourceDependencyPaths)) as $relativePath) {
    $absolutePath = $root . '/' . $relativePath;
    if (!is_file($absolutePath)) {
        throw new RuntimeException('Thiếu dependency ảnh khi tạo manifest: ' . $relativePath);
    }
    $sourceFiles[$relativePath] = hash_file('sha256', $absolutePath);
}
ksort($sourceFiles);
$manifest = [
    'dataset' => 'ACCOMMODATION_BOOKING_DEVELOPMENT',
    'generatorVersion' => GENERATOR_VERSION,
    'seed' => DATA_SEED,
    'asOfUtc' => AS_OF_UTC,
    'databaseTarget' => ['MySQL 8.x', 'MariaDB 10.4.x'],
    'tableCount' => 22,
    'syntheticData' => true,
    'counts' => $counts,
    'assumptions' => [
        'Phạm vi địa lý chỉ gồm Thành phố Hồ Chí Minh.',
        'Tên của 30 cơ sở lấy từ workbook Sở Du lịch; địa chỉ là dữ liệu đối chiếu từ nguồn công khai; tọa độ chưa được nhập.',
        'Google Places mới được chuẩn bị bằng ba cột metadata trên cơ sở; chưa gọi API và chưa có mã địa điểm.',
        'Loại phòng, giá, tồn phòng, đơn đặt chỗ và đánh giá là dữ liệu tổng hợp để phát triển.',
        'Ảnh Wikimedia của Hotel Majestic chỉ gắn cho CS016; cơ sở khác và loại phòng không khớp dùng ảnh minh họa trung tính.',
        'VND là tiền tệ duy nhất; trẻ em chiếm sức chứa nhưng chưa có giá riêng.',
        'PAY_ONLINE thu toàn bộ trong tổng trả ngay và không có ThuePhiTaiCoSo; PAY_AT_PROPERTY trả toàn bộ tại cơ sở.',
        'Khoảng tồn phòng bắt đầu 2026-10-05 và kéo dài 180 ngày.',
    ],
    'sourceFiles' => $sourceFiles,
    'files' => $hashes,
];
writeText($fixtureDir . '/data-manifest.json', jsonData($manifest) . PHP_EOL);

echo $operationalOnly
    ? "Đã tái sinh riêng seed vận hành 006 và manifest.\n"
    : ($propertiesOnly
        ? "Đã tái sinh riêng seed cơ sở 003 và manifest.\n"
        : "Đã sinh toàn bộ dữ liệu accommodation booking.\n");
foreach ($counts as $name => $count) {
    echo str_pad($name, 28) . ': ' . $count . PHP_EOL;
}
