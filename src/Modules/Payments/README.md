# Payments

Module Payments sở hữu bằng chứng giao dịch tài chính phát sinh sau khi booking
được tạo. `ThanhToan` hiện vẫn là summary một-một của booking trong module cũ;
`GiaoDichThanhToan` là ledger append-only phục vụ truy vết, hóa đơn và đối soát.

## Thu tiền tại cơ sở

1. Partner phải thuộc đúng tổ chức và booking phải ở `CHECKED_IN`.
2. Booking chỉ được thu tại quầy khi timing là `PAY_AT_PROPERTY`, payment đang
   `NOT_TRACKED` và số đã trả bằng 0.
3. Toàn bộ số còn phải trả được thu một lần; MVP không hỗ trợ trả từng phần.
4. Transaction cập nhật `ThanhToan.PAID`, insert đúng một collection ledger và
   ghi audit bằng cùng idempotency key.
5. Tiền mặt không có mã tham chiếu; chuyển khoản/thẻ tại quầy bắt buộc có mã.
6. Retry cùng key/payload trả giao dịch cũ. Cùng key khác booking hoặc payload
   trả `IDEMPOTENCY_CONFLICT`.

Payment online mô phỏng tiếp tục dùng provider adapter hiện tại. Không lưu số
thẻ, CVV, token ngân hàng hoặc thông tin tài chính nhạy cảm.

## Hóa đơn lưu trú

- Checkout chỉ phát hành hóa đơn khi booking `COMPLETED`, payment `PAID`, không
  có refund và số thu ròng bằng tổng booking.
- `HoaDon` giữ header cùng snapshot người đặt/cơ sở/kỳ ở; `ChiTietHoaDon` giữ
  từng item phòng. Tổng các dòng bắt buộc bằng tổng hóa đơn.
- Unique booking và số hóa đơn bảo đảm phát hành đúng một lần. Số hóa đơn có
  dạng `SB-YYYY-00000000` và được suy ra ổn định từ booking ID.
- Customer và partner chỉ đọc hóa đơn qua owner/organization scope. Response
  dùng `no-store`, `noindex` và có bản in để lưu PDF từ trình duyệt.
- Đây là biên nhận/hóa đơn nghiệp vụ trong đồ án, không giả định là hóa đơn điện
  tử có mã cơ quan thuế.
