# Vận hành hệ thống

## Tác vụ nền

Chạy các lệnh từ thư mục gốc dự án. Mỗi lần chạy sinh một `Run ID` riêng và ghi log bắt đầu, kết thúc hoặc thất bại
vào `storage/logs/app.log`.

```powershell
php bin/console.php payment:expire 100
php bin/console.php booking:complete 100
php bin/console.php preview:invalidate-expired 100
php bin/console.php inventory:reconcile 2026-11-03 2026-12-03 1 100
php bin/console.php notification:dispatch 100
```

`notification:dispatch` xử lý transactional outbox theo batch. Transport `file` ghi `.eml` vào
`storage/mail`; transport `native` dùng cấu hình mail của PHP. Bản ghi lỗi được thử lại với backoff và
bản ghi `SENDING` treo quá 15 phút có thể được worker khác nhận lại.

- Tham số cuối của ba lệnh mutation là batch size, nhận từ 1 đến 1.000.
- `inventory:reconcile` nhận lần lượt ngày đầu, ngày cuối, ID loại phòng và giới hạn dòng hiển thị. Bỏ ID loại phòng
  để đối soát toàn bộ; lệnh chỉ báo sai lệch, không tự sửa counter hoặc ledger.
- `payment:expire`, `booking:complete` và `preview:invalidate-expired` khóa rồi kiểm tra lại từng bản ghi trong transaction;
  chạy lại không lặp side effect đã hoàn tất.
- Mọi thay đổi trạng thái nghiệp vụ đều có audit. Dùng `Run ID` trong output và log để điều tra một lần chạy.

## Vòng đời hoàn tất lưu trú

Thứ tự vận hành chuẩn của booking trả tại cơ sở:

1. Partner nhận khách trên `/partner/bookings/{code}`; booking chuyển `CONFIRMED → CHECKED_IN`.
2. Partner ghi nhận đủ tiền bằng tiền mặt, chuyển khoản hoặc thẻ tại cơ sở.
3. Partner xác nhận trả phòng; booking, inventory, invoice và outbox cùng được commit.
4. Scheduler chạy `php bin/console.php notification:dispatch 100` để gửi email hóa đơn.
5. Partner vào `/partner/settlements`, tạo bản nháp theo cơ sở/kỳ, kiểm tra rồi chốt.
6. Tải CSV từ trang chi tiết kỳ để lưu chứng từ đối soát.

Không sửa trực tiếp booking/payment/invoice/settlement bằng SQL trong vận hành. Kỳ `FINALIZED` là snapshot bất biến;
nếu phát hiện sai nguồn, điều tra audit và xử lý nghiệp vụ nguồn thay vì sửa số đã chốt.

## Backup database và uploads

Database và `storage/uploads` là một backup set không thể tách rời. Không khôi phục database từ một set rồi ghép
uploads của set khác.

Kiểm tra điều kiện trước khi chạy:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/operations/backup.ps1 `
  -Destination D:\hotel-booking-backups -ValidateOnly
```

Tạo backup:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/operations/backup.ps1 `
  -Destination D:\hotel-booking-backups
```

Mỗi set hoàn chỉnh gồm:

- `database.sql`: dump nhất quán bằng `--single-transaction`;
- `uploads.zip`: toàn bộ thư mục upload ngoài public web root;
- `manifest.json`: thời điểm tạo và SHA-256 của hai artifact.

Script ghi vào thư mục tạm và chỉ đổi tên thành set chính thức sau khi cả hai artifact cùng manifest hoàn tất. Mật khẩu
database không được ghi vào câu lệnh, output, manifest hoặc archive.

## Chính sách và diễn tập khôi phục

- Lịch tối thiểu: một backup hằng ngày; giữ 7 bản ngày, 4 bản tuần và 3 bản tháng ở nơi lưu trữ tách khỏi máy chạy app.
- Mã hóa ổ lưu backup và giới hạn quyền đọc cho người vận hành; không commit backup vào Git.
- Trước khi khôi phục, kiểm SHA-256 trong manifest. Import `database.sql` vào database trống, giải nén `uploads.zip`, rồi
  thay database và uploads trong cùng cửa sổ bảo trì.
- Sau khôi phục phải chạy `/api/health`, toàn bộ test tích hợp quan trọng và `inventory:reconcile`.
- Diễn tập khôi phục ít nhất mỗi tháng. Backup chưa từng restore thử không được coi là backup đã xác minh.
