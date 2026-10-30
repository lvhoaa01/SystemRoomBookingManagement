# Inventory

Module sở hữu `TonPhongNgay` và `GiuTonPhongDem`. Mục tiêu là giữ bất biến:

```text
0 <= SoLuongDaGiu <= TongSoLuong
SoLuongDaGiu = số ledger ACTIVE cùng loại phòng và ngày lưu trú
```

## Thành phần công khai

- `AvailabilityReader`: đọc số phòng còn bán nhỏ nhất cho toàn kỳ lưu trú; thiếu một đêm được xem là không còn chỗ.
- `InventoryCalendarService`: cho partner cập nhật `TongSoLuong` theo tenant; không thể giảm thấp hơn counter đang giữ.
- `InventoryReservationService`: khóa và kiểm tra demand, tạo ledger từng hạng mục/đêm, trả ledger ACTIVE đúng một lần và tìm booking có hold hết hạn.
- `InventoryReconciliationService`: báo chênh lệch counter–ledger; không tự sửa dữ liệu.

## Quy tắc transaction và khóa

- `lockAndAssertAvailable()`, `holdForBooking()` và `releaseActive()` chỉ được gọi bên trong transaction nghiệp vụ.
- Demand luôn được gộp và khóa theo `LoaiPhongID ASC, NgayLuuTru ASC`.
- Command trên booking đã tồn tại phải khóa booking trước, sau đó ledger ACTIVE rồi mới đến inventory.
- `releaseActive()` có tính idempotent: chỉ lần đầu chuyển ledger ACTIVE và giảm counter.
- Preview/search chỉ dùng `AvailabilityReader`, không gọi thao tác ghi.

## Giao diện partner

`GET /partner/properties/{propertyId}/inventory` hiển thị lịch tổng/đang giữ/còn lại. Mutation đi qua CSRF, auth, role PARTNER và partner context; organization ID luôn lấy từ session.

Các integration test trong `tests/Integration/Modules/Inventory` chạy trên MariaDB thật, gồm cả hai tiến trình tranh unit cuối và race giữa giảm tổng với giữ phòng.
