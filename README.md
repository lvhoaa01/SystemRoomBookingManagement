# Accommodation Booking

Mini OTA tìm kiếm và đặt chỗ lưu trú đa cơ sở tại Thành phố Hồ Chí Minh. Dự án dùng PHP 8.2+, MySQL 8.x/MariaDB 10.4+ và kiến trúc một khối có chia mô-đun theo chức năng.

## Tài liệu chuẩn

Thứ tự ưu tiên khi có khác biệt:

1. [`huongDan/DESCRIPTION_HOTEL_BOOKING_V2.md`](huongDan/DESCRIPTION_HOTEL_BOOKING_V2.md) — nghiệp vụ và 60 tiêu chí chấp nhận;
2. [`huongDan/DESIGN_HOTEL_BOOKING_V2.md`](huongDan/DESIGN_HOTEL_BOOKING_V2.md) — kiến trúc, dữ liệu và transaction;
3. [`database/migrations/001_create_schema.sql`](database/migrations/001_create_schema.sql) và validator đã kiểm chứng;
4. [`huongDan/DFD_HOTEL_BOOKING.md`](huongDan/DFD_HOTEL_BOOKING.md) và [`huongDan/SEQUENCE_DIAGRAM_HOTEL_BOOKING.md`](huongDan/SEQUENCE_DIAGRAM_HOTEL_BOOKING.md);
5. code đang triển khai.

Lộ trình tự phát triển nằm tại [`huongDan/LO_TRINH_PHAT_TRIEN_THU_CONG_HOTEL_BOOKING_V2.md`](huongDan/LO_TRINH_PHAT_TRIEN_THU_CONG_HOTEL_BOOKING_V2.md). Schema MVP có chính xác 22 bảng.

## Khởi chạy bằng XAMPP trên Windows

### 1. Chuẩn bị

- Đặt repository tại `C:\DDrive\xampp\htdocs\DuAnNhom` hoặc điều chỉnh URL tương ứng.
- Bật Apache và MySQL trong XAMPP.
- Kiểm tra PHP: `C:\DDrive\xampp\php\php.exe -v`.
- Cài Composer và bảo đảm lệnh `composer` có trong `PATH`.

### 2. Tạo cấu hình cục bộ

```powershell
Copy-Item .env.example .env
composer install
composer dump-autoload
composer test
```

`.env` đã bị Git bỏ qua. Không commit mật khẩu hoặc cấu hình máy cá nhân.

### 3. Import database sạch

Có thể chọn lần lượt bảy file dưới đây trong phpMyAdmin, hoặc chạy PowerShell:

```powershell
$mysql = 'C:\DDrive\xampp\mysql\bin\mysql.exe'
$sqlFiles = @(
    'database/migrations/001_create_schema.sql',
    'database/seeds/001_reference.sql',
    'database/seeds/002_accounts_partners.sql',
    'database/seeds/003_properties.sql',
    'database/seeds/004_products_policies.sql',
    'database/seeds/005_inventory_prices.sql',
    'database/seeds/006_operational.sql'
)

foreach ($sqlFile in $sqlFiles) {
    & $mysql --host=127.0.0.1 --port=3306 --user=root --default-character-set=utf8mb4 `
        --execute="SOURCE $($sqlFile.Replace('\', '/'));"
    if ($LASTEXITCODE -ne 0) { throw "Import thất bại: $sqlFile" }
}
```

Migration tự tạo database `hotel_booking`. Không import các file trong `database/fixtures/invalid/`; đó là dữ liệu cố ý sai để kiểm thử.

### 4. Kiểm tra dữ liệu

```powershell
C:\DDrive\xampp\php\php.exe scripts\data\validate_data.php

C:\DDrive\xampp\mysql\bin\mysql.exe `
    --host=127.0.0.1 --port=3306 --user=root --default-character-set=utf8mb4 `
    --table --execute="SOURCE database/validation/001_integrity_checks.sql;"
```

Kết quả hợp lệ phải có đúng 22 bảng, validator file/manifest thành công và mọi dòng `Violations` bằng `0`.

### 5. Mở ứng dụng

Truy cập:

```text
http://localhost/DuAnNhom/public/
```

Baseline hiện tại hiển thị trang xác nhận bộ khung Modular Monolith đã sẵn sàng. Các chức năng nghiệp vụ được phát triển lần lượt theo lộ trình V2.

Kiểm tra tình trạng ứng dụng và kết nối database:

```text
http://localhost/DuAnNhom/public/health
http://localhost/DuAnNhom/public/api/health
```

Phản hồi hợp lệ có `status = ok`, `application = up` và `database = up`.

## Kiểm thử ứng dụng

```powershell
C:\DDrive\xampp\php\php.exe vendor\bin\phpunit --configuration phpunit.xml
```

Test được chia thành `tests/Unit`, `tests/Integration`; các giai đoạn sau bổ sung `Concurrency` và `Contract` theo đúng lộ trình. Log runtime nằm trong `storage/logs/`, session cục bộ nằm trong `storage/tmp/`; cả hai không được commit.

## Tài khoản seed

- Quản trị viên: `ADMIN001`;
- Đối tác: `DT001` đến `DT010`;
- Khách hàng: `KH0001` đến `KH0500`;
- Mật khẩu chung: `Ntu@123456`.

## Quy mô và nguồn dữ liệu

- 30 cơ sở, 120 loại phòng, 180 sản phẩm phòng;
- 21.600 dòng tồn theo ngày và 32.400 dòng giá;
- 2.000 preview và 2.000 booking;
- tên cơ sở lấy từ danh mục nguồn; địa chỉ là dữ liệu đối chiếu công khai;
- giá, tồn, booking, review và phần lớn thuộc tính vận hành là dữ liệu tổng hợp phục vụ phát triển;
- ảnh Hotel Majestic chỉ gắn cho `CS016`; cơ sở khác dùng ảnh minh họa trung tính có nhãn rõ ràng.

Google Places mới là hướng tích hợp sau MVP. Schema chỉ chuẩn bị `GooglePlaceID`, trạng thái đối chiếu và thời điểm xác minh; dữ liệu Google không được coi là master data lâu dài.

## Bất biến phải giữ

- Tồn phòng thuộc `LoaiPhong + NgayLuuTru`, không thuộc sản phẩm phòng.
- Preview tối đa 15 phút, dùng một lần và không giữ tồn.
- Counter tồn phải bằng số ledger `ACTIVE`.
- Mỗi booking chỉ có một thời điểm thanh toán thuần nhất.
- `PAY_ONLINE` trả toàn bộ ngay; `PAY_AT_PROPERTY` không theo dõi tiền mặt thực tế.
- Chỉ booking `PENDING_PAYMENT` hoặc `CONFIRMED` được giữ tồn.
- Hủy, thất bại thanh toán, no-show và hoàn tất phải trả tồn đúng một lần.
- Không gọi cổng thanh toán khi transaction đang giữ khóa tồn.
- Mọi truy vấn đối tác phải giới hạn tổ chức ngay trong SQL.
- Lịch sử booking dùng snapshot, không tính lại từ giá/chính sách hiện tại.

## Sinh lại dữ liệu

Chỉ sinh lại khi thay đổi generator hoặc fixture có chủ đích:

```powershell
C:\DDrive\xampp\php\php.exe scripts\data\generate_data.php
C:\DDrive\xampp\php\php.exe scripts\data\validate_data.php
```

Không sửa tay từng dòng seed và không dùng seed tổng hợp như dữ liệu vận hành thật.
