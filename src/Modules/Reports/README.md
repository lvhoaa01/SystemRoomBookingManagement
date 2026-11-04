# Reports / Revenue settlement

Đối soát doanh thu là snapshot tài chính theo một cơ sở và khoảng ngày phát hành hóa đơn.

- Nguồn duy nhất là `HoaDon` đã phát hành cùng payment/collection thực tế.
- Bản `DRAFT` được phép tạo lại; chi tiết cũ được thay atomically.
- `FINALIZED` là bất biến và không cho chốt hai khoảng ngày giao nhau tại cùng cơ sở.
- Tenant scope nằm trong mọi truy vấn đọc/chốt/export.
- CSV dùng đúng snapshot chi tiết của kỳ, không tính lại từ catalog.
