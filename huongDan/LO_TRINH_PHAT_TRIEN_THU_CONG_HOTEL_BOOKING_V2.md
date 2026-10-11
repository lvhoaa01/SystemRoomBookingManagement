# LỘ TRÌNH TỰ PHÁT TRIỂN THỦ CÔNG MINI OTA V2

**Phạm vi:** tự xây dựng toàn bộ ứng dụng PHP 8.2 + MySQL/MariaDB từ bộ khung hiện có  
**Nguồn nghiệp vụ:** `DESCRIPTION_HOTEL_BOOKING_V2.md` phiên bản 2.2  
**Nguồn kỹ thuật:** `DESIGN_HOTEL_BOOKING_V2.md` phiên bản 2.2  
**Mục tiêu cuối:** mini OTA nhiều cơ sở lưu trú, đúng 22 bảng, có tìm kiếm, phân bổ nhiều phòng, preview, booking chống bán vượt, hai thời điểm thanh toán, hủy, đổi ngày, no-show, review, quản trị và báo cáo

---

## 1. Cách dùng lộ trình này

Làm tuần tự từ Giai đoạn 0 đến Giai đoạn 17. Không chuyển giai đoạn chỉ vì giao diện đã nhìn thấy được; chỉ chuyển khi tiêu chí hoàn thành và test của giai đoạn hiện tại đã đạt.

Thứ tự ưu tiên khi có mâu thuẫn:

1. `huongDan/DESCRIPTION_HOTEL_BOOKING_V2.md`;
2. `huongDan/DESIGN_HOTEL_BOOKING_V2.md`;
3. migration và validator đã được kiểm chứng;
4. DFD, sequence diagram và README giải thích;
5. code đang viết.

Không tự sửa code để “khớp code cũ” nếu code cũ trái đặc tả. Dừng lại, xác định use case và bất biến bị ảnh hưởng, rồi sửa đồng bộ tài liệu, migration, validator và test nếu thực sự cần thay đổi quyết định đã chốt.

---

## 2. Hiện trạng workspace

### 2.1. Phần đã có thể dùng ngay

- Migration chuẩn có đúng 22 bảng: `database/migrations/001_create_schema.sql`.
- Sáu seed đã đủ quy mô: 30 cơ sở, 120 loại phòng, 180 sản phẩm, 21.600 dòng tồn, 32.400 dòng giá, 2.000 booking.
- Dữ liệu payment timing đã được sửa đúng quy tắc V2.
- Ảnh seed, provenance, giấy phép và checksum đã có.
- Validator file/manifest và SQL integrity check đã có.
- Composer autoload PSR-4 đã cấu hình namespace `App\`.
- Bộ khung ban đầu đã có `App`, `Router`, `Request`, `Response`, kết nối PDO, transaction manager, auth/CSRF middleware, layout và tám module.

### 2.2. Phần hiện mới là bộ khung, chưa được coi là chức năng

- Router chỉ khớp đường dẫn tĩnh và chỉ hỗ trợ `GET`.
- `App` mới đăng ký `web.php`, chưa đăng ký `api.php`, console command hoặc middleware pipeline.
- Chưa có container/dependency wiring, session bootstrap, request ID, logger và view renderer.
- `Request` chưa đọc JSON, header, cookie, route parameter hoặc uploaded file.
- Mọi exception hiện bị đổi thành HTTP 500; chưa có error mapping theo mục 16 DESIGN.
- Auth middleware chỉ kiểm tra có `user_id`, chưa kiểm tra trạng thái tài khoản, vai trò và tổ chức.
- CSRF mới có bước kiểm token, chưa có sinh/rotate token.
- `Clock` hiện trả giờ TP.HCM; đặc tả yêu cầu timestamp kỹ thuật lưu UTC và chỉ quy đổi local cho cutoff/ngày nghiệp vụ.
- Transaction manager cần kiểm lại cách nhận biết deadlock qua `PDOException::errorInfo`, không chỉ dựa vào `getCode()`.
- Các module mới chỉ có README, chưa có Domain/Application/Infrastructure/Http.
- Không có test thực thi trong thư mục `tests`.
- Root `README.md` còn trỏ tới một số tài liệu DRAFT cũ; DESCRIPTION V2 lại tham chiếu DFD/sequence nhưng hai file này hiện không có trong workspace.

### 2.3. Kết luận hiện trạng

Không nên bắt đầu bằng trang search đẹp hoặc màn hình booking. Việc đầu tiên là hoàn thiện hạ tầng HTTP, lỗi, session, test và các value object. Sau đó đi đúng chuỗi phụ thuộc:

```text
Nền tảng
→ Identity
→ Properties
→ Commercial
→ Inventory
→ Search/Detail
→ Preview
→ Booking trả tại cơ sở
→ Booking online
→ Nghiệp vụ sau booking
→ Review/Admin/Report
→ Hardening và nghiệm thu
```

---

## 3. Các nguyên tắc không được phá trong lúc phát triển

1. Không thêm bảng khi chưa có quyết định cập nhật V2; schema giữ đúng 22 bảng.
2. Controller không gọi PDO, không tính tiền, không tự chuyển trạng thái.
3. Repository chỉ truy vấn/lưu dữ liệu; quyết định nghiệp vụ nằm ở application/domain service.
4. Application service sở hữu transaction của use case ghi.
5. Tiền dùng chuỗi thập phân hoặc `Money`; tuyệt đối không dùng `float`.
6. Ngày lưu trú là khoảng nửa kín `[check-in, check-out)`.
7. Mọi truy vấn đối tác phải scope `ToChucDoiTacID` ngay trong SQL.
8. Preview tối đa 15 phút, dùng một lần và không giữ tồn.
9. Inventory thuộc `LoaiPhong + NgayLuuTru`, không thuộc `SanPhamPhong`.
10. Counter tồn và ledger phải thay đổi trong cùng transaction.
11. Chỉ booking `PENDING_PAYMENT` hoặc `CONFIRMED` được có ledger `ACTIVE`.
12. Mọi item trong booking phải cùng một `ThoiDiemThanhToan`.
13. Giá/chính sách/khách lịch sử phải dùng snapshot, không đọc lại cấu hình hiện tại.
14. Không gọi mock payment/refund trong transaction đang giữ khóa tồn.
15. Hủy, payment retry, waiver approve, đổi ngày, no-show và completion phải chạy lặp an toàn.
16. Thứ tự khóa của command trên booking: `DatCho → ThanhToan → GiuTonPhongDem → TonPhongNgay` đã sort.
17. Danh sách tồn luôn khóa theo `LoaiPhongID ASC, NgayLuuTru ASC`.
18. Không lưu token preview thô, session ID, mật khẩu, PAN, CVV hoặc secret trong DB/log/audit.
19. Không gọi chỉ số bán trên tồn mở bán là công suất toàn khách sạn.
20. Không mở rộng Google Maps, thanh toán thật, chatbot, notification hoặc tính năng ngoài mục 25 DESCRIPTION trước khi MVP đạt.

---

## 4. Quy trình thủ công cho mỗi chức năng

Với mỗi use case, thực hiện đúng vòng lặp sau:

1. Ghi mã tiêu chí chấp nhận liên quan trong DESCRIPTION mục 27.
2. Liệt kê input, output, actor, quyền, trạng thái đầu/cuối và lỗi nghiệp vụ.
3. Chốt bảng nào được đọc, bảng nào được ghi và module nào sở hữu chúng.
4. Viết value object/policy thuần trước nếu có quy tắc tính toán.
5. Viết unit test cho quy tắc thuần.
6. Viết repository query và integration test với DB thật.
7. Viết application service cùng transaction/lock/idempotency.
8. Viết controller/request mapper; controller chỉ chuyển HTTP sang DTO và DTO sang response.
9. Viết route, view/JavaScript tối thiểu.
10. Thêm authorization, CSRF, tenant scope và audit.
11. Viết test happy path, validation, unauthorized, conflict, retry và rollback.
12. Nếu có tranh chấp tồn/payment/state, viết concurrency test bằng hai kết nối DB thật.
13. Chạy PHP lint, toàn bộ test, data validator và SQL integrity validator.
14. Commit theo một use case hoàn chỉnh, không commit riêng controller/repository rời rạc.

Một chức năng không “xong” nếu mới chạy được happy path trên trình duyệt.

---

## 5. Giai đoạn 0 — Khóa baseline và làm sạch tài liệu

### Việc phải làm

1. Tạo nhánh phát triển riêng, giữ các thay đổi dữ liệu hiện tại thành một commit logic trước khi viết ứng dụng.
2. Chạy lại đúng thứ tự migration → seed 001–006 → SQL validator trên database phát triển sạch.
3. Chạy `scripts/data/validate_data.php`; lưu kết quả baseline 0 vi phạm.
4. Sao chép `.env.example` thành `.env`, không commit `.env`.
5. Chạy `composer install` và `composer dump-autoload`.
6. Cập nhật root `README.md` để trỏ tới DESCRIPTION/DESIGN V2.2 hiện hành.
7. Khôi phục hoặc soạn lại `DFD_HOTEL_BOOKING.md` và `SEQUENCE_DIAGRAM_HOTEL_BOOKING.md`, vì V2 đang tham chiếu chúng nhưng workspace hiện không có file.
8. Tạo bảng theo dõi 60 tiêu chí mục 27 DESCRIPTION: `NOT_STARTED/IN_PROGRESS/PASSED`.
9. Không thay seed bằng dữ liệu tự nhập tay trong quá trình code; seed là fixture chuẩn để test và `EXPLAIN`.

### Hoàn thành khi

- Một thành viên mới clone dự án có thể import database và mở trang `/` bằng README.
- Validator file và SQL đều bằng 0.
- Git không chứa `.env`, log, session, upload thử hoặc dữ liệu DB cá nhân.

---

## 6. Giai đoạn 1 — Hoàn thiện lõi chạy ứng dụng

### 6.1. Bootstrap và cấu hình

Tạo hoặc hoàn thiện:

```text
src/Bootstrap/App.php
src/Bootstrap/Container.php
src/Bootstrap/SessionBootstrap.php
src/Config/Env.php
src/Config/AppConfig.php
src/Config/DatabaseConfig.php
```

Thứ tự thực hiện:

1. Nạp `.env` có kiểm soát hoặc truyền biến môi trường rõ ràng.
2. Kiểm tra các biến bắt buộc khi boot; production không được bật debug.
3. Khởi tạo session với `HttpOnly`, `SameSite=Lax`, `Secure` khi HTTPS.
4. Rotate session ID sau login/logout và tạo CSRF token bằng `random_bytes`.
5. Container chỉ wiring dependency; không nhét nghiệp vụ vào container.
6. Đăng ký cả `web.php` và `api.php`; chuẩn bị `console.php` cho job.

### 6.2. HTTP kernel

Mở rộng:

```text
src/Http/Router.php
src/Http/Request.php
src/Http/Response.php
src/Http/Middleware/*
src/Shared/Http/ViewRenderer.php
src/Shared/Http/HttpException.php
src/Shared/Http/ExceptionHandler.php
```

Router cần hỗ trợ:

- `GET`, `POST`, `PATCH`, `DELETE` hoặc method override cho form;
- route parameter như `/properties/{slug}`;
- middleware theo route;
- route name và sinh URL cơ bản;
- JSON và HTML response;
- redirect và flash message;
- 404, 405 và error envelope chuẩn.

Request cần hỗ trợ:

- query, form, JSON body;
- header, cookie, uploaded file;
- route parameter;
- IP và request ID;
- không log credential/token.

### 6.3. Database, lỗi và logging

1. Dùng duy nhất một PDO cho request; không tạo kết nối ở từng repository.
2. Tách lỗi validation, authentication, authorization, not found, conflict và provider error.
3. Map đúng HTTP/mã lỗi ở DESIGN mục 16; không biến mọi lỗi thành 500.
4. Chỉ retry deadlock/serialization failure, tối đa hai lần, dùng cùng idempotency key.
5. Kiểm deadlock bằng SQLSTATE và driver code trong `errorInfo`.
6. Log có request ID, actor ID khi có; che email/điện thoại/token/secret.
7. Trang lỗi production không lộ stack trace, SQL hoặc đường dẫn máy.

### Test bắt buộc

- route tĩnh/động, method không hỗ trợ, 404;
- parse JSON/form/query;
- session và CSRF hợp lệ/sai;
- exception mapping;
- transaction commit, rollback và deadlock retry;
- view escape dữ liệu mặc định.

### Hoàn thành khi

- Có `/health` hoặc trang tương đương kiểm app và DB.
- Một request đi được trọn `Router → Middleware → Controller → Response`.
- Test có thể boot app bằng config test riêng.

---

## 7. Giai đoạn 2 — Shared kernel và contract dùng chung

### Thành phần cần viết trước nghiệp vụ

```text
src/Shared/Money/Money.php
src/Shared/Clock/ClockInterface.php
src/Shared/Clock/SystemClock.php
src/Shared/Clock/FrozenClock.php
src/Shared/Json/CanonicalJson.php
src/Shared/Security/TokenGenerator.php
src/Shared/Security/Hasher.php
src/Shared/Validation/ValidationResult.php
```

Các value object/DTO theo DESIGN mục 6:

- `StayPeriod`;
- `RoomParty`;
- `SearchCriteria`;
- `InventoryDemand`;
- `PriceBreakdown`;
- `RoomAllocation`;
- `AllocationResult`.

### Quy tắc cần khóa bằng unit test

- `StayPeriod` sinh đúng danh sách đêm, tối đa 30 đêm, check-out sau check-in.
- `Money` chỉ nhận VND, không nhận float, cộng/trừ/so sánh/làm tròn HALF_UP.
- `RoomParty` có ít nhất một người lớn; tuổi trẻ em 0–17.
- JSON canonical không đổi hash khi thứ tự key thay đổi.
- `SystemClock` trả UTC cho timestamp kỹ thuật; helper riêng đổi sang múi giờ cơ sở để tính cutoff.
- Token dùng ít nhất 256 bit ngẫu nhiên; DB chỉ nhận hash.

### Chú ý

Không đưa `BookingService`, SQL hoặc entity khổng lồ vào `Shared`. Shared chỉ chứa primitive thật sự dùng chung; nghiệp vụ vẫn nằm trong module sở hữu.

---

## 8. Giai đoạn 3 — Identity, đăng nhập và phạm vi tổ chức

### Thứ tự triển khai

1. `UserRepository`: tìm bằng email/mã, khóa/mở tài khoản, không trả hash ra view.
2. `PartnerMembershipRepository`: tìm duy nhất tổ chức của partner.
3. `RegisterCustomerService`: validate, normalize email, `password_hash`, unique conflict.
4. `LoginService`: `password_verify`, account status, rotate session.
5. `LogoutService`: hủy session/rotate CSRF.
6. `CurrentUser` và `OrganizationContext` lấy từ session server.
7. `AuthMiddleware`, `RoleMiddleware`, `PartnerContextMiddleware`.
8. Form/view đăng ký, đăng nhập, đăng xuất.
9. Admin khóa/mở tài khoản có audit làm sau phần login cơ bản hoặc trong giai đoạn Admin.

### Route tương ứng

```text
POST /auth/register
POST /auth/login
POST /auth/logout
```

### Test bắt buộc

- mật khẩu đúng/sai, email trùng, account bị khóa;
- session fixation: ID đổi sau login;
- customer không vào partner/admin;
- partner không membership không có organization context;
- partner chỉ thuộc tối đa một tổ chức;
- CSRF cho mọi POST;
- login error không tiết lộ email có tồn tại hay không.

### Mốc hoàn thành

Đạt tiêu chí chấp nhận 1–3 liên quan auth/scope cơ bản; chưa cần giao diện đẹp.

---

## 9. Giai đoạn 4 — Properties và workflow duyệt cơ sở

### Cấu trúc module đề xuất

```text
src/Modules/Properties/
├── Domain/
│   ├── PropertyStatus.php
│   ├── PropertyReadinessChecker.php
│   ├── BedConfiguration.php
│   └── ImageCollection.php
├── Application/
│   ├── CreatePropertyService.php
│   ├── UpdatePropertyService.php
│   ├── SubmitPropertyForReviewService.php
│   └── ReviewPropertyService.php
├── Infrastructure/
│   ├── PropertyRepository.php
│   ├── RoomTypeRepository.php
│   └── FacilityRepository.php
└── Http/
```

### Trình tự chức năng

1. Partner xem danh sách property đúng tổ chức.
2. Tạo/sửa property `DRAFT`.
3. Quản lý loại phòng, cấu hình giường JSON, ảnh JSON.
4. Quản lý tiện nghi đúng `PROPERTY/ROOM` scope.
5. Upload ảnh: MIME thực, giải mã được, dung lượng/kích thước, tên ngẫu nhiên, không thực thi PHP.
6. Viết `PropertyReadinessChecker` đúng DESCRIPTION mục 8.3.
7. Partner gửi duyệt `DRAFT → PENDING_REVIEW`.
8. Admin: approve, request changes về `DRAFT`, reject, suspend, restore.
9. Sửa trường trọng yếu của property active phải về `PENDING_REVIEW`; sửa mô tả/ảnh/tiện nghi không tự hạ trạng thái nhưng vẫn audit.
10. Tổ chức `TAM_KHOA` không quản trị catalog hoặc gửi duyệt.

### Chú ý đặc biệt

- Không có trạng thái `NEEDS_CHANGES`.
- `REJECTED` là từ chối dứt điểm trong luồng thường.
- Khi về `DRAFT/PENDING_REVIEW`, xử lý `NguoiDuyetID/DuyetLuc` đúng CHECK.
- Không xóa cứng dữ liệu đã được snapshot trong booking.
- Ảnh Majestic chỉ áp dụng `CS016`; ảnh chung phải hiển thị nhãn “Ảnh minh họa”.

### Test/DoD

- tenant isolation ở list/detail/mutation;
- readiness thiếu từng thành phần phải fail rõ ràng;
- transition property hợp lệ/sai;
- product/policy chéo cơ sở chưa làm ở đây nhưng readiness phải phát hiện khi Commercial đã có;
- đạt tiêu chí chấp nhận 4–7.

---

## 10. Giai đoạn 5 — Commercial: chính sách, sản phẩm, giá và khuyến mãi

### Thứ tự triển khai

1. CRUD `ChinhSachHuy`, validate loại chính sách/loại phạt/giá trị chuẩn hóa.
2. CRUD `SanPhamPhong`, bắt buộc room type và policy cùng cơ sở.
3. Màn hình lịch giá `GiaPhongNgay`: giá, đóng bán, min/max stay, lead days.
4. Khi cập nhật giá/restriction, tăng `PhienBan` cùng transaction.
5. CRUD `KhuyenMai`, không cộng dồn; tie-break phần trăm giảm rồi ID.
6. Viết `PriceCalculator` duy nhất cho search/detail/preview/booking/đổi ngày.
7. Viết `CancellationCalculator` và `NoShowFeeCalculator` thuần, đọc snapshot.

### Unit test quan trọng

- đủ mọi đêm mới tính được giá;
- restriction lấy ở ngày nhận;
- promotion tie-break xác định;
- thuế/phí đã gồm/chưa gồm;
- làm tròn từng thành phần và từng đêm;
- tổng night → item → booking khớp;
- hai payment timing có split all-or-nothing;
- bốn loại phạt hủy và đúng biên deadline;
- no-show tính từng item rồi cộng.

### Không được làm

- Không tính giá trong JavaScript/controller.
- Không dùng giá hiện tại để giải thích booking cũ.
- Không để `PAY_ONLINE` có `ThuePhiTaiCoSo > 0`.

---

## 11. Giai đoạn 6 — Inventory an toàn trước concurrency

### Thành phần

```text
AvailabilityReader
InventoryRepository
InventoryReservationService
InventoryReconciliationService
InventoryCalendarService
```

### Thứ tự triển khai

1. Đọc minimum availability cho cả khoảng ngày.
2. Partner cập nhật `TongSoLuong`; cấm giảm thấp hơn `SoLuongDaGiu`.
3. `lockAndAssertAvailable()` gom demand theo room type/date và sort cố định.
4. `holdForBooking()` tạo một ledger cho mỗi item/đêm và tăng counter đúng số lượng.
5. `releaseActive()` chỉ release dòng `ACTIVE` và giảm counter đúng một lần.
6. Reconcile counter–ledger: báo chênh lệch, không tự sửa âm thầm nếu chưa có command/audit.
7. Viết query tìm hold hết hạn phục vụ payment expiry.

### Test bắt buộc trước khi đi tiếp

- hold nhiều phòng/nhiều đêm;
- thiếu một đêm phải rollback toàn bộ;
- release hai lần chỉ có lần đầu thay đổi counter;
- counter luôn trong `[0, TongSoLuong]`;
- counter bằng số ledger active;
- hai kết nối tranh unit cuối: đúng một bên thành công;
- partner giảm tổng đồng thời với booking không làm held vượt tổng.

Không viết booking transaction trước khi các test này chạy ổn định trên DB thật.

---

## 12. Giai đoạn 7 — Search, allocator và trang chi tiết

### 12.1. Search criteria và query ứng viên

1. Validate destination HCM, ngày, tối đa 5 phòng/10 người lớn/10 trẻ em/30 đêm.
2. Chuẩn hóa filters/sort bằng allow-list.
3. Chỉ lấy property `ACTIVE` thuộc tổ chức `HOAT_DONG`.
4. Lấy room/product/policy active, đủ rate mọi đêm, không đóng bán.
5. Áp dụng restriction, sức chứa và minimum availability.
6. Không khóa `FOR UPDATE` ở search.

### 12.2. RoomAllocator

Triển khai đúng DESIGN mục 6.3:

- một lời gọi công khai;
- tối đa hai partition timing độc lập;
- backtracking tối đa 5 nhóm;
- mỗi nhóm không bị tách;
- trừ tồn tạm trong từng partition;
- sort ứng viên/tie-break xác định;
- nếu hòa giá ưu tiên `PAY_AT_PROPERTY`, sau đó tuple room/product;
- không fallback tuần tự và không ghép sản phẩm rẻ nhất riêng lẻ từ hai timing.

### 12.3. Search/detail UI

1. Card hiển thị giá đại diện, tổng thuế/phí và đúng badge timing.
2. Filter timing chỉ xét nhánh tương ứng.
3. Sort/range filter dùng chính giá đang hiển thị.
4. Detail mặc định đúng allocation/timing từ card.
5. Toggle timing phải gọi lại availability endpoint; không lọc response cũ trong JS.
6. Search/detail cùng dùng `PriceCalculator` và fingerprint.
7. Nếu version thay đổi, trả `selectionChanged=true`, giải thích thay đổi.

### Test/hiệu năng

- tiêu chí 8–13;
- allocator deterministic;
- hai timing, một timing hết, hòa giá, nhiều phòng dùng chung room type;
- search/detail khớp giá khi dữ liệu không đổi;
- test query đủ rate/tồn mọi đêm;
- chạy `EXPLAIN` search, filter tiện nghi và detail availability trên fixture chuẩn.

### Mốc demo 1

Khách chưa đăng nhập tìm kiếm, lọc, xem card, mở detail và đổi timing được; chưa thể đặt.

---

## 13. Giai đoạn 8 — Preview và checkout

### Trình tự

1. Yêu cầu customer đã đăng nhập.
2. Nhận criteria + allocation ID, không nhận tổng tiền/policy đáng tin từ client.
3. Revalidate property, organization, product, policy, rate, restriction, capacity, promotion, timing và tồn.
4. Gọi `PriceCalculator` từng phòng.
5. Từ chối timing hỗn hợp bằng `MIXED_PAYMENT_TIMING`.
6. Sinh `PhanBoJSON` version 1 và canonical fingerprint.
7. Sinh token 256 bit; DB lưu SHA-256, token thô chỉ trả một lần.
8. Lưu preview `VALID`, hết hạn tối đa 15 phút.
9. Tuyệt đối không gọi hold/tăng counter/tạo ledger.
10. Checkout lấy token từ session hoặc body/header, không đưa token vào URL/localStorage/log.
11. Checkout có `Referrer-Policy: no-referrer`, không tải tài nguyên bên thứ ba.

### Test bắt buộc

- tiêu chí 14–17;
- preview đủ tổng/pay now/pay at property/policy/warning;
- mixed timing bị chặn;
- hết hạn, sai owner, used, invalidated, stale fingerprint;
- preview không làm thay đổi inventory/ledger;
- token không xuất hiện ở URL, log hoặc HTML không cần thiết.

---

## 14. Giai đoạn 9 — Booking trả tại cơ sở: lát dọc đầu tiên

Làm `PAY_AT_PROPERTY` trước vì không có provider, giúp khóa đúng transaction booking trước khi thêm payment race.

### Trình tự transaction

1. Nhận preview token, `ClientRequestID` UUID và thông tin người đặt.
2. Bắt đầu transaction, khóa preview theo hash.
3. Kiểm owner, `VALID`, hạn, chưa dùng và fingerprint.
4. Xử lý idempotency toàn cục; cùng key chỉ trả cũ khi cùng owner/payload.
5. Revalidate commercial data và timing.
6. Gom demand, khóa tồn theo thứ tự chuẩn, kiểm tra unit cuối.
7. Tạo `DatCho.CONFIRMED`.
8. Tạo một `HangMucDatCho` cho mỗi phòng.
9. Tạo `GiaDemDatCho` từ snapshot máy chủ.
10. Hold tồn và tạo ledger `ACTIVE`, không có expiry.
11. Tạo đúng một payment `PAY_AT_PROPERTY + NOT_TRACKED`.
12. Đánh dấu preview `USED`, ghi audit, commit.
13. Chỉ sau commit mới trả mã booking.

### Test bắt buộc

- booking một/nhiều phòng;
- thiếu một đêm rollback tất cả;
- snapshot không đổi khi sửa catalog;
- double click cùng `ClientRequestID` chỉ một booking;
- cùng ID khác payload/owner trả 409 không rò dữ liệu;
- hai UUID dùng cùng preview chỉ một booking;
- hai request tranh unit cuối chỉ một request thắng;
- counter/ledger đối soát sau mỗi test.

### Mốc demo 2

Khách tìm → detail → preview → đặt trả tại cơ sở → xem mã booking và booking snapshot. Đây là mốc end-to-end đầu tiên được coi là có giá trị.

---

## 15. Giai đoạn 10 — Thanh toán online mô phỏng

### Thành phần

- `PaymentGateway` interface;
- `MockPaymentGateway` với success/failure/timeout/late success;
- `OnlinePaymentService`;
- `PaymentExpiryJob` trong route/command console;
- finalize/refund primitives dùng lại cho cancellation/no-show.

### Trình tự

1. Transaction booking tạo `PENDING_PAYMENT`, payment `PROCESSING`, ledger có hạn 10 phút.
2. Commit transaction giữ tồn.
3. Gọi mock gateway ngoài transaction.
4. Finalize trong transaction ngắn, khóa booking trước payment.
5. Success hợp lệ: `PAID + CONFIRMED`.
6. Failure/expiry: `FAILED + PAYMENT_FAILED`, release tồn đúng một lần.
7. Timeout: giữ state đối soát được, retry cùng key.
8. Late success sau `PAYMENT_FAILED`: không hồi sinh booking; void/refund mô phỏng và audit.

### Test bắt buộc

- tiêu chí 26–34;
- provider không được gọi khi PDO còn transaction/lock;
- retry không insert payment/booking mới;
- callback–expiry race chỉ một terminal result;
- cancel–payment race theo thứ tự lock;
- online không có `ThuePhiTaiCoSo`;
- chỉ báo thành công khi cả booking confirmed và payment paid.

---

## 16. Giai đoạn 11 — Trang tài khoản và sửa thông tin khách

### Chức năng

1. Danh sách booking của customer có phân trang.
2. Chi tiết booking dùng snapshot, không dùng tên/giá/policy hiện tại.
3. Partner xem booking đúng organization scope.
4. Admin tra cứu hỗ trợ có audit khi truy cập nhạy cảm.
5. Customer sửa contact, yêu cầu đặc biệt và tên khách trước cutoff.
6. Không cho đổi số khách/tuổi/sức chứa bằng route sửa đơn giản.
7. Mỗi command sửa nhận `RequestID`, khóa booking và audit before/after.

### Test

- owner/partner/admin scope;
- mã booking khó đoán vẫn không thay authorization;
- trước/đúng/sau cutoff;
- thay đổi không ảnh hưởng price/inventory;
- retry cùng RequestID không ghi hai lần.

---

## 17. Giai đoạn 12 — Hủy, refund và miễn phí hủy

### 12.1. Cancel preview

- Đọc snapshot booking.
- Tính fee theo múi giờ cơ sở.
- Trả `calculatedAt`, `validUntil`, fee, expected refund và giải thích.
- Khi confirm phải tính lại; thay đổi trả `CANCELLATION_QUOTE_CHANGED`.

### 12.2. Hủy trực tiếp

Thực hiện đúng thứ tự khóa và transaction ở DESIGN mục 10.2. Release chỉ dòng `ACTIVE`; `DatCho.SoTienHoan` và `ThanhToan.DaHoan` chỉ tăng sau refund success.

### 12.3. Refund

- Khởi tạo `PENDING` trong transaction hủy.
- Gọi gateway ngoài transaction.
- Chốt success/failure trong transaction ngắn.
- Chênh số provider với expected không được đánh dấu success.
- Retry dùng cùng refund idempotency key.

### 12.4. Waiver

- `PENDING` không đổi booking/tồn.
- Chỉ partner cùng tổ chức approve/reject.
- Approve và cancellation fee 0 phải cùng transaction.
- Không tồn tại waiver `APPROVED` nhưng booking chưa `CANCELLED`.

### Test

- tiêu chí 35–42;
- cancel retry/release/refund exactly once;
- refund fail không đảo booking;
- waiver approve thua race rollback toàn bộ;
- cancel và waiver approve đồng thời;
- pay-at-property chỉ ghi assessed fee, không tạo tiền đã thu/hoàn.

---

## 18. Giai đoạn 13 — Đổi ngày all-or-nothing

Chỉ làm sau khi booking, inventory và cancellation đã ổn định.

### Thứ tự

1. Chặn ngoài phạm vi: chỉ `CONFIRMED + PAY_AT_PROPERTY + NOT_TRACKED`, trước cutoff.
2. Preview đổi ngày dùng token HMAC ngắn hạn hoặc luôn recompute; không giữ tồn.
3. Khóa booking/payment, đọc version rate, tính hợp demand cũ/mới.
4. Khóa ledger và union inventory theo thứ tự.
5. Kiểm delta mới trước khi thay dữ liệu cũ.
6. Giữ đêm giao nhau; release old-only; acquire new-only.
7. Nếu ledger ngày mới đã từng `RELEASED`, tái kích hoạt, không insert trùng.
8. Cập nhật nightly price/snapshot/tổng trong cùng transaction.
9. Audit before/after và idempotency bằng `RequestID`.

### Test quan trọng

- tiêu chí 43–46;
- đổi sớm/muộn, có/không overlap;
- đổi lại ngày từng release;
- thiếu một ngày mới rollback nguyên booking cũ;
- hai đổi ngày khóa chéo không deadlock vĩnh viễn;
- giá mới stale phải từ chối hoặc yêu cầu xác nhận lại.

---

## 19. Giai đoạn 14 — No-show, completion và review

### No-show

- Chỉ partner đúng tổ chức, đúng cửa sổ local.
- Tính fee từ snapshot từng item.
- Release toàn bộ ledger active trong cùng transaction.
- Online tạo refund phần vượt phí sau commit; `DatCho.SoTienHoan` vẫn 0.
- Retry không tính/release/refund lần hai.

### Completion job

- Chạy theo batch nhỏ sau giờ trả phòng địa phương.
- Khóa từng booking confirmed, release hold còn lại, chuyển completed, audit.
- Chạy lại không có side effect.

### Review

- Chỉ đúng customer, booking `COMPLETED`, 0–90 ngày từ check-out local.
- Một booking một review.
- Chỉ `PUBLISHED` tính điểm.
- Admin chỉ hide/publish, không sửa nội dung.

### Test

- tiêu chí 47–52;
- cancel/no-show/completion race chỉ một terminal state;
- no-show online refund đúng một lần;
- no-show không được review;
- moderation cập nhật summary công khai.

---

## 20. Giai đoạn 15 — Admin, audit và báo cáo

### Admin

- quản lý account/facility;
- hàng duyệt property;
- review moderation;
- tra cứu booking;
- xem audit có phân trang và che PII;
- xem lỗi payment/refund cần xử lý.

### Audit

Chuẩn hóa action name, object type, RequestID và schema JSON before/after. Audit append-only; không dùng audit thay trạng thái nghiệp vụ.

### Báo cáo

Làm query đọc sau cùng, khi state/snapshot đã ổn định:

- booking theo thời gian/trạng thái;
- tổng giá trị booking;
- tiền online thật sự đã thu;
- phí pay-at-property chỉ được đánh giá;
- cancellation/no-show rate;
- room-night và tỷ lệ bán trên tồn mở bán;
- điểm review và promotion usage.

### Test/EXPLAIN

- partner chỉ thấy organization mình;
- công thức báo cáo đúng status filter;
- không gọi assessed fee là collected revenue;
- `EXPLAIN` cho tám nhóm query ở DESIGN mục 14.2;
- chỉ thêm index khi có bằng chứng trước/sau.

---

## 21. Giai đoạn 16 — UI hoàn chỉnh, bảo mật và vận hành

### UI

- layout/components dùng chung cho customer/partner/admin;
- loading, empty, validation, conflict, retry;
- tiền VND, ngày và deadline local dễ hiểu;
- badge payment timing nhất quán;
- nhãn “Ảnh minh họa” và trang “Nguồn ảnh”;
- keyboard navigation, label, focus và thông báo lỗi;
- double submit/back button không tạo side effect kép.

### Bảo mật

- CSRF mọi mutation;
- output escaping theo context;
- CSP cơ bản;
- upload hardening;
- allow-list sort/filter;
- rate limit đơn giản cho login, preview hủy, payment và upload;
- cookie/session production;
- PII masking trong log/support;
- không để preview/payment token trong URL/log/localStorage;
- backup DB và uploads có cùng chiến lược.

### Tác vụ console

```text
payment:expire
booking:complete
inventory:reconcile
preview:invalidate-expired   # nếu cần dọn trạng thái; hết hạn vẫn dựa trên thời gian
```

Mỗi job phải có batch size, lock, idempotency, log request/run ID và audit cho thay đổi nghiệp vụ.

---

## 22. Giai đoạn 17 — Nghiệm thu toàn hệ thống

### 22.1. Chạy lại dữ liệu

1. Database sạch.
2. Import migration và seed 001–006.
3. Chạy file validator PHP.
4. Chạy SQL integrity checks, mọi `Violations = 0`.
5. Xác nhận đúng 22 bảng và checksum manifest.

### 22.2. Test suite cuối

- toàn bộ unit test;
- toàn bộ integration test;
- 13 concurrency scenario ở DESIGN mục 17.3;
- contract/UI test search → detail → preview;
- security/tenant/CSRF test;
- `EXPLAIN` query quan trọng;
- test các fixture invalid phải bị từ chối.

### 22.3. Đi qua 60 tiêu chí DESCRIPTION mục 27

| Nhóm tiêu chí | Giai đoạn chịu trách nhiệm |
|---|---|
| 1–3 | Identity và tenant scope |
| 4–7 | Properties/approval |
| 8–17 | Search, detail, allocator, preview |
| 18–25 | Booking và inventory |
| 26–34 | Payment |
| 35–49 | Cancel, refund, waiver, date change, no-show, completion |
| 50–52 | Review |
| 53–60 | Provenance, report, schema, validator, EXPLAIN và consistency |

Mỗi tiêu chí phải có link tới test hoặc biên bản kiểm thủ công. Không đánh dấu hoàn thành chỉ dựa trên quan sát giao diện.

### 22.4. Nghiệm thu tài liệu

- Route thực tế khớp DESIGN hoặc tài liệu được cập nhật.
- DFD/sequence khớp transaction thật.
- README cài đặt/import/chạy job đúng.
- Module README ghi contract và ownership thật.
- Không có tài liệu tham chiếu bảng/trạng thái đã bỏ.

---

## 23. Chiến lược kiểm thử nên dựng ngay từ đầu

### Cấu trúc

```text
tests/
├── Unit/
├── Integration/
├── Concurrency/
├── Contract/
└── Fixtures/
```

### Unit test

Không dùng DB cho `Money`, ngày, allocator, calculator, state policy, canonical fingerprint và JSON schema.

### Integration test

Dùng database test riêng, chạy migration/fixture tối thiểu, bọc từng test bằng transaction khi phù hợp. Test transaction tự commit/race phải có cơ chế reset database riêng, không dựa vào rollback của test runner.

### Concurrency test

Dùng ít nhất hai PDO connection thật và barrier đồng bộ. Sau race, không chỉ kiểm response; phải truy vấn hậu kiểm:

- số booking;
- booking state;
- payment state;
- ledger active/released;
- counter inventory;
- audit/idempotency row.

### Đồng hồ test

Dùng `FrozenClock`, không chèn `now()` trực tiếp khắp service. Các test cutoff, expiry, no-show và review phải kiểm trước/đúng/sau biên.

---

## 24. Thứ tự commit đề xuất cho người tự code

```text
1.  chore: establish v2 baseline and test database
2.  feat: build http kernel session and error handling
3.  feat: add shared money date token and canonical json primitives
4.  feat: implement customer authentication and partner context
5.  feat: implement property room and facility management
6.  feat: implement property readiness and admin approval
7.  feat: implement products policies rates and promotions
8.  feat: implement deterministic price and cancellation calculators
9.  feat: implement inventory locking hold release and reconciliation
10. feat: implement deterministic search and room allocation
11. feat: implement property availability and payment timing toggle
12. feat: implement preview and checkout without inventory hold
13. feat: implement pay-at-property booking transaction
14. test: prove last-unit booking concurrency and idempotency
15. feat: implement mock online payment lifecycle and expiry
16. feat: implement booking management and guest detail update
17. feat: implement cancellation refund and waiver workflows
18. feat: implement all-or-nothing date changes
19. feat: implement no-show completion and verified reviews
20. feat: implement admin audit and partner reports
21. chore: harden security performance ui and documentation
22. test: reconcile all v2 acceptance criteria
```

Mỗi commit phải chạy được test liên quan. Không giữ một nhánh khổng lồ đến cuối mới tích hợp.

---

## 25. Những việc dễ làm sai và phải tự kiểm tra thường xuyên

| Sai lầm | Hậu quả | Cách phòng tránh |
|---|---|---|
| Làm UI/search trước calculator và inventory | giá nhảy, logic lặp | khóa DTO/calculator/inventory trước |
| Dùng float cho VND | lệch tổng/snapshot | `Money` + DECIMAL string |
| Tin giá/timing từ client | sửa giá, mixed timing | revalidate server ở preview và booking |
| Preview giữ tồn | phòng bị khóa khi khách bỏ trang | preview tuyệt đối read-only với inventory |
| Mỗi product có inventory riêng | oversell loại phòng | tồn duy nhất theo room type/date |
| Release counter mà không đổi ledger | mất invariant | primitive release chung, cùng transaction |
| Gọi gateway trong transaction | lock dài/race khó xử lý | commit trước, finalize sau |
| Retry tạo payment/booking mới | trùng giao dịch | idempotency key + unique + payload fingerprint |
| Partner query theo ID rồi kiểm quyền sau | rò dữ liệu | tenant scope ngay trong SQL |
| Tính hủy từ policy hiện tại | lịch sử thay đổi | chỉ đọc booking snapshot |
| Đổi ngày release cũ trước khi biết ngày mới đủ | phá booking đang tốt | lock union, kiểm delta, all-or-nothing |
| `NO_SHOW` giữ ledger | mâu thuẫn invariant | release toàn bộ trong cùng transaction |
| Dùng seed vận hành làm dữ liệu thật | sai provenance | luôn gắn nhãn dữ liệu tổng hợp |
| Thêm bảng để giải quyết nhanh | phá target V2 | dùng đúng aggregate/audit/JSON đã chốt |

---

## 26. Việc nên bắt đầu ngay

Thứ tự công việc thực tế cho phiên code đầu tiên:

1. Cập nhật README về tài liệu V2 và khôi phục DFD/sequence bị thiếu.
2. Thiết lập PHPUnit hoặc test runner tương đương và database test riêng.
3. Viết test cho router/transaction hiện có để tạo safety net.
4. Nâng Router thành dynamic router có POST/middleware.
5. Hoàn thiện Request/Response/ViewRenderer/ExceptionHandler.
6. Boot session an toàn, CSRF generation và request ID.
7. Sửa Clock thành UTC + local-time policy và sửa deadlock detection.
8. Viết `Money`, `StayPeriod`, `RoomParty`, canonical JSON cùng unit test.
9. Triển khai đăng nhập/customer/partner context.
10. Chỉ sau khi chín bước trên ổn định mới bắt đầu Properties.

Đừng bắt đầu bằng `BookingService`. Booking là điểm hội tụ của gần như toàn bộ module; viết nó quá sớm sẽ khiến bạn phải sửa lại transaction, snapshot và DTO nhiều lần.

