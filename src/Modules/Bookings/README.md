# Bookings

Module Bookings sở hữu preview, booking, snapshot lịch sử, payment, quản lý
booking, hủy/refund/waiver, đổi ngày lưu trú, no-show và completion.

## Luồng preview

1. `POST /previews` chỉ nhận request của tài khoản `CUSTOMER` đã đăng nhập và có
   CSRF hợp lệ.
2. Client gửi criteria, property slug, timing, selection fingerprint và ánh xạ
   room/product; không gửi tổng tiền hoặc policy đáng tin cậy.
3. `PreviewService` gọi `PropertyAvailabilityService` để đọc lại property,
   organization, product, policy, rate, restriction, promotion, capacity và tồn.
4. Nếu mapping thay đổi, request bị từ chối. Nếu mapping còn nguyên nhưng
   giá/version đổi, preview mới được tạo với warning yêu cầu khách xác nhận.
5. `PreviewSnapshotFactory` tạo `PhanBoJSON` version 1 và fingerprint canonical.
6. Token ngẫu nhiên 256 bit được trả đúng một lần; database chỉ lưu SHA-256.
7. Preview ở trạng thái `VALID` trong tối đa 15 phút và không tăng counter tồn,
   không tạo `GiuTonPhongDem`.

## Checkout

- Web giữ token thô trong PHP session rồi redirect tới `GET /checkout`.
- API gửi token trong header `X-Preview-Token`.
- Token không nằm trong path/query string, HTML hoặc application log.
- Checkout revalidate snapshot một lần nữa. Preview hết hạn, đã dùng,
  invalidated, sai owner hoặc stale đều không dùng được; stale preview được đổi
  sang `INVALIDATED`.
- Response checkout đặt `Referrer-Policy: no-referrer`, `Cache-Control: no-store`
  và CSP chỉ cho tài nguyên cùng origin.

## Booking trả tại cơ sở

1. `POST /bookings` nhận preview token từ PHP session hoặc header
   `X-Preview-Token`, `ClientRequestID` UUID, liên hệ người đặt và xác nhận chính
   sách; không nhận giá hoặc tồn từ client.
2. `BookingService` khóa preview theo token hash, xử lý idempotency toàn cục và
   chỉ hỗ trợ preview thuần `PAY_AT_PROPERTY`.
3. Giá, product, policy, restriction, capacity và inventory được đọc lại. Sau
   đó inventory được khóa theo `(LoaiPhongID, NgayLuuTru)` để quyết định unit
   cuối.
4. Một transaction tạo `DatCho.CONFIRMED`, mọi `HangMucDatCho`, mọi
   `GiaDemDatCho`, ledger `GiuTonPhongDem.ACTIVE`, payment
   `PAY_AT_PROPERTY + NOT_TRACKED`, chuyển preview sang `USED` và ghi audit.
5. Bất kỳ lỗi nào rollback toàn bộ. Mã booking chỉ được trả sau commit.
6. Retry cùng UUID, owner và payload trả booking cũ. Cùng UUID nhưng khác owner,
   preview hoặc payload trả `409 IDEMPOTENCY_CONFLICT` không kèm dữ liệu booking
   đã tồn tại.

## Snapshot và xác nhận

- `GET /bookings/{code}/confirmation` luôn kiểm tra booking thuộc customer hiện
  tại; mã công khai không thay thế authorization.
- Trang xác nhận đọc tên phòng, sản phẩm, nhóm khách, giá đêm và policy từ các
  snapshot booking, không đọc lại catalog hiện tại.
- Response checkout/xác nhận dùng `no-store`, `no-referrer` và CSP cùng origin.
- `PAY_AT_PROPERTY` không ghi nhận tiền cơ sở trực tiếp thu: `DaThanhToan = 0`,
  `DaHoan = 0`, payment giữ trạng thái `NOT_TRACKED`.

## Đổi ngày lưu trú

- Chỉ booking `CONFIRMED + PAY_AT_PROPERTY + NOT_TRACKED` và còn trước giờ nhận
  phòng địa phương được đổi ngày.
- Preview luôn tính lại rate, restriction, promotion, chính sách và capacity;
  không giữ tồn và không tin tổng tiền từ client.
- Xác nhận khóa booking → payment → commercial rows → toàn bộ ledger → union
  inventory đã sort, rồi kiểm fingerprint và phần delta capacity.
- Đêm giao nhau giữ nguyên; old-only được release; new-only được insert hoặc tái
  kích hoạt ledger `RELEASED` để không vi phạm unique key.
- Ngày booking, nightly prices, snapshot item, tổng booking/payment, ledger,
  counter và audit thay đổi trong cùng transaction. Retry cùng `RequestID` và
  payload chỉ trả kết quả cũ.

## No-show

- Partner chỉ thao tác booking thuộc tổ chức trong session, từ giờ nhận phòng
  đến hết ngày kế tiếp theo múi giờ cơ sở.
- Phí được tính từ `SnapshotHuyJSON` của từng item; không đọc chính sách hiện
  tại để sửa lịch sử.
- Booking, toàn bộ ledger active, counter tồn, snapshot no-show và audit thay
  đổi trong cùng transaction theo lock order booking → payment → ledger → tồn.
- Online chỉ khởi tạo refund trong transaction. Gateway được gọi sau commit với
  idempotency key ổn định; retry refund lỗi không release hoặc tính phí lần hai.
- `DatCho.SoTienHoan` luôn bằng 0 cho no-show; số hoàn nằm ở `ThanhToan.DaHoan`.

## Nhận khách

- Partner dùng `POST /partner/bookings/{code}/check-in`; tenant scope được kiểm
  tra bằng tổ chức hiện tại trước khi khóa booking.
- Chỉ booking `CONFIRMED` được nhận khách, từ đầu ngày nhận phòng đến trước giờ
  trả phòng theo múi giờ cơ sở. Online phải `PAID`; trả tại cơ sở vẫn
  `NOT_TRACKED` cho đến giai đoạn ghi nhận tiền.
- Transaction chuyển trạng thái sang `CHECKED_IN`, lưu thời điểm/người thao tác
  và ghi audit `BOOKING_CHECKED_IN`. Retry cùng `RequestID` trả kết quả cũ.
- Check-in không release inventory. Sau check-in, luồng hủy, đổi ngày và
  no-show đều không còn hợp lệ.

## Completion

- Partner dùng checkout thủ công sau khi booking `CHECKED_IN` và payment đã thu
  đủ. Một transaction trả hold, chuyển `COMPLETED`, phát hành hóa đơn snapshot
  và ghi audit idempotent.
- `php bin/console.php booking:complete [limit]` là fallback cho booking
  `CHECKED_IN + PAID` đã qua giờ trả phòng địa phương; job cũng phát hành hóa
  đơn trong cùng transaction.
- Booking chưa check-in hoặc chưa thanh toán đủ không được job tự hoàn tất.

## Ranh giới hiện tại

Dashboard báo cáo và hàng lỗi payment/refund dành cho admin là read model thuộc
module Admin; Bookings chỉ cung cấp dữ liệu nguồn và tiếp tục sở hữu mọi chuyển
trạng thái booking/payment. Verified review thuộc module Reviews. Mọi use case
mới vẫn phải giữ snapshot lịch sử, tenant scope, idempotency và lock order đã
thiết lập.
