# StayBooking — Mini OTA đặt chỗ lưu trú

StayBooking là ứng dụng Mini OTA cho thị trường lưu trú tại Thành phố Hồ Chí Minh. Hệ thống hỗ trợ khách tìm kiếm,
xem trước và đặt nhiều phòng; đối tác quản lý cơ sở, giá, tồn và booking; quản trị viên duyệt nội dung, theo dõi audit
và báo cáo vận hành.

Dự án dùng PHP 8.2+, MariaDB/MySQL và kiến trúc Modular Monolith, không phụ thuộc framework backend hoặc frontend.
Dữ liệu đi kèm là fixture tổng hợp dành cho phát triển, không phải dữ liệu vận hành thật.

## Chức năng chính

- Identity: đăng ký, đăng nhập, phân quyền customer/partner/admin và tenant scope.
- Properties/Commercial: cơ sở, loại phòng, tiện nghi, ảnh, sản phẩm, chính sách, giá ngày và khuyến mại.
- Inventory/Search: tồn theo loại phòng-ngày, phân bổ nhiều phòng xác định, search/detail theo payment timing.
- Bookings: preview 15 phút, booking trả tại cơ sở hoặc online mô phỏng, snapshot giá/chính sách.
- Sau booking: sửa khách, hủy/refund, waiver, đổi ngày, no-show, nhận khách, thu tiền tại cơ sở,
  trả phòng, hóa đơn, email outbox, đối soát doanh thu và review verified.
- Admin: account/facility, duyệt cơ sở, audit đã che PII, payment issues và báo cáo.
- Vận hành: CSRF/CSP/rate limit, console jobs, backup đồng bộ database–uploads và bộ validator nghiệm thu.

## Yêu cầu môi trường

- PHP `>= 8.2` với `pdo_mysql`, `fileinfo` và session.
- MariaDB 10.4+ hoặc MySQL 8.x.
- Composer 2.
- Apache/Nginx trỏ document root tới `public/`, hoặc XAMPP cho môi trường Windows.

## Cài đặt

```powershell
git clone https://github.com/lvhoaa01/SystemRoomBookingManagement.git DuAnNhom
cd DuAnNhom
composer install
Copy-Item .env.example .env
```

Chỉnh `.env` theo MariaDB/MySQL local. Cấu hình XAMPP mặc định của dự án dùng database `hotel_booking`, user `root`
và cổng `3307`.

Tạo database UTF-8 rồi import đúng thứ tự:

```powershell
mysql -u root -P 3307 -e "CREATE DATABASE hotel_booking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -P 3307 hotel_booking -e "source database/migrations/001_create_schema.sql"
mysql -u root -P 3307 hotel_booking -e "source database/migrations/002_add_stay_check_in.sql"
mysql -u root -P 3307 hotel_booking -e "source database/migrations/003_add_property_payment_transactions.sql"
mysql -u root -P 3307 hotel_booking -e "source database/migrations/004_add_invoices.sql"
mysql -u root -P 3307 hotel_booking -e "source database/migrations/005_add_notification_outbox.sql"
mysql -u root -P 3307 hotel_booking -e "source database/migrations/006_add_revenue_settlements.sql"
mysql -u root -P 3307 hotel_booking -e "source database/seeds/001_reference.sql"
mysql -u root -P 3307 hotel_booking -e "source database/seeds/002_accounts_partners.sql"
mysql -u root -P 3307 hotel_booking -e "source database/seeds/003_properties.sql"
mysql -u root -P 3307 hotel_booking -e "source database/seeds/004_products_policies.sql"
mysql -u root -P 3307 hotel_booking -e "source database/seeds/005_inventory_prices.sql"
mysql -u root -P 3307 hotel_booking -e "source database/seeds/006_operational.sql"
```

Với XAMPP đặt tại `C:\DDrive\xampp`, thay `mysql` bằng `C:\DDrive\xampp\mysql\bin\mysql.exe`.

## Chạy ứng dụng

Khi dùng XAMPP và repository nằm trong `htdocs/DuAnNhom`, mở:

- Trang chủ: `http://localhost/DuAnNhom/public/`
- Health check: `http://localhost/DuAnNhom/public/api/health`
- Nguồn ảnh: `http://localhost/DuAnNhom/public/image-sources`

Có thể chạy server phát triển PHP:

```powershell
php -S 127.0.0.1:8080 -t public
```

Sau đó cập nhật `APP_URL=http://127.0.0.1:8080` và mở địa chỉ tương ứng.

## Tài khoản fixture

Mật khẩu chung: `Ntu@123456`.

| Vai trò | Tài khoản |
|---|---|
| Admin | `admin@accommodation.local` hoặc `ADMIN001` |
| Partner | `partner01@accommodation.local` hoặc `DT001` |
| Customer | `customer0001@example.test` hoặc `KH0001` |

Không dùng các tài khoản/mật khẩu fixture trong production.

## Kiểm thử và validator

```powershell
composer test
composer run verify
```

`composer test` chạy unit, integration và concurrency suite. `composer run verify` kiểm checksum/số lượng fixture,
constraint từ chối dữ liệu sai, đúng 28 bảng và 46 invariant SQL. Database phải được import từ seed chuẩn trước khi
chạy toàn bộ nghiệm thu.

## Tác vụ vận hành

```powershell
php bin/console.php payment:expire 100
php bin/console.php booking:complete 100
php bin/console.php preview:invalidate-expired 100
php bin/console.php inventory:reconcile 2026-11-03 2026-12-03 1 100
php bin/console.php notification:dispatch 100
```

Ở môi trường local, `MAIL_TRANSPORT=file` ghi email vào `storage/mail` để kiểm thử không phụ thuộc SMTP.
Trên server đã cấu hình dịch vụ gửi mail, có thể chọn `MAIL_TRANSPORT=native`.

Chi tiết batch, run ID, audit, backup và restore nằm tại [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Cấu trúc chính

```text
public/                 Front controller và static assets
src/Bootstrap/          Container, session và application bootstrap
src/Http/               Router, request/response và middleware
src/Modules/            Identity, Properties, Commercial, Inventory, Search, Bookings, Reviews, Admin
src/Shared/             Primitive và dịch vụ dùng chung
database/               Migration, seed, fixture manifest và SQL validator
tests/                  Unit, integration, concurrency và support workers
scripts/operations/     Công cụ backup
scripts/validation/     Fixture/integrity validator
views/                  Layout và view PHP
```

## Bất biến quan trọng

- Một booking thuộc một cơ sở và chỉ có một payment timing.
- Preview không giữ tồn; booking mới giữ counter và ledger trong cùng transaction.
- Counter tồn luôn bằng số ledger active và không vượt tổng mở bán.
- Giá, khách và chính sách lịch sử được đọc từ snapshot.
- Payment provider không được gọi khi transaction đang giữ khóa tồn.
- Partner luôn bị scope theo organization ở server.
- Tiền VND dùng kiểu thập phân/`Money`, không dùng `float`.

## Phạm vi MVP

Không bao gồm payment gateway thật, lưu thẻ, email/SMS, loyalty, quản lý phòng vật lý/PMS, bản đồ runtime,
microservice hoặc cơ sở ngoài Thành phố Hồ Chí Minh. Giao diện lấy cảm hứng từ mô hình OTA nhưng không sao chép nội
dung, tài sản hay thuật toán nội bộ của Booking.com.
