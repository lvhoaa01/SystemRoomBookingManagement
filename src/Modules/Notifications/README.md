# Notifications

Module gửi thông báo hậu giao dịch theo transactional outbox.

- Phát hành hóa đơn chỉ ghi một bản ghi `PENDING` trong transaction nghiệp vụ.
- Lệnh `notification:dispatch` claim từng email trong transaction ngắn, gửi bên ngoài transaction rồi cập nhật kết quả.
- Lỗi được retry tối đa 5 lần với backoff 1, 2, 4, 8, 16 phút; job khác có thể nhận lại bản ghi `SENDING` treo quá 15 phút.
- `MAIL_TRANSPORT=file` là mặc định local, email nằm trong `storage/mail`; `native` dùng `mail()` của PHP trên server đã cấu hình.
- Idempotency key và unique invoice/type ngăn phát sinh hai email hóa đơn cho cùng một hóa đơn.
