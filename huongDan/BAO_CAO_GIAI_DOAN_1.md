# BÁO CÁO HOÀN THÀNH GIAI ĐOẠN 1

**Ngày nghiệm thu:** 11/10/2026  
**Nhánh:** `develop`  
**Mục tiêu:** hoàn thiện lõi chạy ứng dụng trước khi viết nghiệp vụ

## Kết quả theo lộ trình

| Nhóm | Nội dung đã hoàn thành | Bằng chứng |
|---|---|---|
| Kiểm thử | PHPUnit, autoload test, Unit/Integration suite | `phpunit.xml`, `composer.lock` |
| Cấu hình | Nạp `.env` có kiểm soát, biến bắt buộc, cấm debug production | `Env`, `AppConfig`, `DatabaseConfig` |
| Container | Singleton dependency, phát hiện dependency vòng | `ContainerTest` |
| Session | Cookie `HttpOnly`, `SameSite=Lax`, `Secure` khi HTTPS, timeout, CSRF 256 bit | `SessionBootstrapTest` |
| Database | Một PDO singleton trong mỗi lần boot | `AppBootTest` |
| Router | GET/POST/PATCH/DELETE, route động, middleware, route name, URL | `RouterTest` |
| Request | Query, form, JSON, header, cookie, upload, route param, IP, request ID | `RequestTest` |
| Response | HTML, JSON, redirect, header | `ResponseTest` |
| View | Escape chuỗi mặc định, chỉ bỏ escape qua `SafeHtml` rõ ràng | `ViewRendererTest` |
| Flash | Thông báo session chỉ đọc một lần | `ViewRendererTest` |
| Lỗi | Envelope JSON chuẩn, HTML an toàn, 404/405 và lỗi nghiệp vụ | `ExceptionHandlerTest` |
| Logging | JSON log có request/actor và che token, email, điện thoại | `FileLoggerTest` |
| Transaction | Commit, rollback, retry SQLSTATE/driver deadlock tối đa | `TransactionManagerTest` |
| Health | Kiểm tra ứng dụng và MySQL qua web/API | `/health`, `/api/health` |
| Console | Điểm đăng ký command đã chuẩn bị | `src/routes/console.php` |

## Các commit Giai đoạn 1

```text
1dfaf6b test: thiết lập PHPUnit cho giai đoạn 1
a786210 nền tảng: hoàn thiện cấu hình container và session an toàn
9869963 http: xây dựng router động request response và view an toàn
703ce3d an toàn: chuẩn hóa lỗi log csrf và retry transaction
151a525 hạ tầng: thêm health check và bảo vệ lỗi khởi động
45eab5f an toàn: bổ sung kiểu lỗi chuẩn và kiểm thử session thật
14ac26d http: nối request id qua middleware và health controller
9334a7b http: chuẩn hóa request dự phòng khi json không hợp lệ
```

## Quy ước cho code nghiệp vụ tiếp theo

1. Controller chỉ đổi HTTP thành DTO và DTO thành Response.
2. Dependency lấy từ `Container`; repository không tự tạo PDO.
3. Route ghi phải gắn CSRF và middleware quyền phù hợp.
4. Lỗi dự kiến dùng các lớp `HttpException`; không ném chuỗi hoặc trả response tùy ý từ repository.
5. Mọi log phải dùng `Logger`; không log request body thô, token, cookie hoặc session ID.
6. Use case ghi nhiều bảng phải dùng `TransactionManager`; chỉ deadlock/serialization failure được retry.
7. API luôn trả envelope lỗi có `code`, `message`, `requestId`, `details`.
8. View nhận chuỗi đã escape mặc định; `SafeHtml` chỉ dùng cho HTML do server kiểm soát.

## Chưa thuộc Giai đoạn 1

- `Money`, `StayPeriod`, `RoomParty`, canonical JSON và đồng hồ có thể đóng băng thuộc Giai đoạn 2.
- Đăng ký/đăng nhập và context tổ chức thuộc Giai đoạn 3.
- Chưa có route nghiệp vụ nên Auth/CSRF mới được kiểm thử ở tầng middleware, chưa gắn vào route chức năng.
- Chưa có concurrency booking; các test đó chỉ bắt đầu sau Inventory/Booking.

Không bắt đầu từ `BookingService`. Bước tiếp theo là Giai đoạn 2: shared kernel và các contract dùng chung theo DESIGN mục 6.
