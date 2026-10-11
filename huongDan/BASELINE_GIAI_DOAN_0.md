# BIÊN BẢN BASELINE — GIAI ĐOẠN 0

**Ngày kiểm tra:** 11/10/2026  
**Nhánh phát triển:** `develop`  
**Phạm vi:** khóa baseline V2.2 trước khi triển khai chức năng ứng dụng

## Kết quả

| Đầu việc | Kết quả | Bằng chứng |
|---|---|---|
| Tạo nhánh phát triển riêng | Đạt | Nhánh `develop` |
| Chốt baseline dữ liệu | Đạt | Commit `1dfdb53` |
| Chốt DESCRIPTION/DESIGN/lộ trình V2.2 | Đạt | Commit `ff04f8e` |
| Import migration và seed `001–006` | Đạt | Database `hotel_booking` được tạo sạch, không có lỗi import |
| Kiểm tra file và manifest | Đạt | `scripts/data/validate_data.php` trả dữ liệu hợp lệ |
| Kiểm tra bất biến trong database | Đạt | 33 kiểm tra, tổng kiểm tra khác 0 bằng 0 |
| Tạo `.env` cục bộ | Đạt | Tạo từ `.env.example`; `git ls-files .env` không có kết quả |
| Composer install và autoload | Đạt | `composer.lock` và `vendor/autoload.php`; autoload tối ưu có 12 lớp |
| Cập nhật README V2 | Đạt | Commit `0718a7f` |
| Khôi phục DFD và sequence V2.2 | Đạt | Commit `3d9431f` |
| Tạo bảng theo dõi tiêu chí | Đạt | Đúng 60 dòng; commit `d5b3f88` |
| Mở trang gốc qua Apache | Đạt | `GET /DuAnNhom/public/` trả HTTP 200 |

## Quy mô database đã nhập

| Hạng mục | Số lượng |
|---|---:|
| Bảng | 22 |
| Cơ sở lưu trú | 30 |
| Loại phòng | 120 |
| Sản phẩm phòng | 180 |
| Tồn phòng theo ngày | 21.600 |
| Giá phòng theo ngày | 32.400 |
| Preview | 2.000 |
| Booking | 2.000 |
| Hạng mục booking | 2.500 |
| Giá đêm snapshot | 8.000 |
| Ledger tồn | 8.000 |
| Thanh toán | 2.000 |

## Validator

Lệnh kiểm tra file và manifest:

```powershell
C:\DDrive\xampp\php\php.exe scripts\data\validate_data.php
```

Kết quả: hợp lệ; checksum manifest, số dòng, provenance ảnh và quy tắc payment timing đều đạt.

Lệnh kiểm tra database:

```powershell
C:\DDrive\xampp\mysql\bin\mysql.exe `
    --host=127.0.0.1 --port=3306 --user=root --default-character-set=utf8mb4 `
    --table --execute="SOURCE database/validation/001_integrity_checks.sql;"
```

Kết quả: 33/33 kiểm tra có `Violations = 0`, gồm số bảng, counter–ledger, trạng thái booking, tổng tiền, payment timing, preview, hủy, no-show, review, tenant, provenance và restriction.

## Kiểm tra an toàn repository

- `.env`, `vendor/`, log, session, upload thử và file tạm không được theo dõi.
- Chỉ `.gitkeep` được giữ trong các thư mục `storage` rỗng.
- Không import fixture cố ý sai trong `database/fixtures/invalid/`.
- Seed chuẩn tiếp tục được dùng làm fixture cho test và `EXPLAIN`; không sửa tay dữ liệu SQL.

## Điều kiện chuyển sang Giai đoạn 1

- Database phát triển đã sẵn sàng và tái tạo được từ repository.
- Trang `/` mở được theo đúng URL trong README.
- DESCRIPTION, DESIGN, DFD, sequence và bảng 60 tiêu chí đều tồn tại trong Git.
- Mọi thay đổi tiếp theo phải cập nhật trạng thái/bằng chứng trong bảng tiêu chí và giữ validator bằng 0.

Giai đoạn tiếp theo là hoàn thiện lõi chạy ứng dụng: bootstrap cấu hình, session, HTTP kernel, exception mapping, logging và test nền tảng. Không bắt đầu `BookingService` trước các lớp nền này.
