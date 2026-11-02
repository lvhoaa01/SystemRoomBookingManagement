# Admin

Sở hữu luồng đọc `NhatKyHeThong`, quản lý danh mục dùng chung và cung cấp báo
cáo đọc từ các module. Việc duyệt cơ sở, kiểm duyệt review và tra cứu booking
vẫn được điều phối qua controller của module sở hữu nghiệp vụ tương ứng.

## Quản trị danh mục

- Tài khoản được lọc, phân trang và chỉ cho phép khóa/mở. Admin không thể tự
  khóa; màn hình này không đổi vai trò hoặc quan hệ tổ chức.
- Tiện nghi được tạo với mã/phạm vi cố định. Sau khi tạo chỉ đổi tên hoặc trạng
  thái; bản ghi đang dùng không bị xóa.
- Mọi thay đổi đi qua transaction và ghi audit với `RequestID`.

## Audit

- Action dùng `UPPER_SNAKE_CASE`; object type, object ID và `RequestID` được
  kiểm tra trước khi ghi.
- JSON before/after được chuẩn hóa; màn hình admin che email, điện thoại, tên
  khách và IP trước khi render.
- Audit là append-only và không thay thế trạng thái nghiệp vụ.

## Báo cáo

- Partner luôn bị giới hạn theo organization trong session. Admin có thể xem
  toàn hệ thống hoặc lọc theo organization/cơ sở.
- Giá trị booking chỉ tính `CONFIRMED`, `COMPLETED`, `NO_SHOW`.
- Tiền online thu ròng bằng tiền thực thu trừ tiền đã hoàn.
- Phí hủy/no-show của `PAY_AT_PROPERTY` chỉ mang nghĩa **được đánh giá**, không
  được gọi là doanh thu đã thu.
- Báo cáo tồn dùng tên “tỷ lệ bán trên tồn mở bán”, không tuyên bố là công suất
  toàn khách sạn.
- Hàng vận hành riêng hiển thị payment online lỗi và refund chờ/thất bại.

## Kiểm chứng truy vấn

Tám nhóm query tại thiết kế mục 14.2 có test `EXPLAIN` trên fixture chuẩn. Kế
hoạch hiện tại dùng các index sẵn có, vì vậy giai đoạn này không thêm index mới.
