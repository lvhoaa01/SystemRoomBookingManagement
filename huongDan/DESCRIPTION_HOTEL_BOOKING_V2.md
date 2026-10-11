# ĐẶC TẢ NGHIỆP VỤ HỆ THỐNG TÌM KIẾM VÀ ĐẶT CHỖ LƯU TRÚ ĐA CƠ SỞ

**Tên ngắn:** Mini OTA lưu trú  
**Phiên bản:** 2.2 – bổ sung quản lý nguồn gốc ảnh và tài sản seed  
**Ngày kiểm chứng nguồn:** 09/10/2026  
**Ngày cập nhật gần nhất:** 11/10/2026  
**Phạm vi triển khai:** Thành phố Hồ Chí Minh, PHP + MySQL/MariaDB, một khối ứng dụng có chia mô-đun  
**Mô hình dữ liệu:** chính xác 22 bảng trong `database/migrations/001_create_schema.sql`

> Đây là tài liệu độc lập, không ghi đè bản nháp cũ. Khi triển khai phiên bản 2, phải đọc cùng `huongDan/DESIGN_HOTEL_BOOKING_V2.md`. Tài liệu tham khảo hành vi công khai của Booking.com để học cách tổ chức nghiệp vụ; không tuyên bố biết kiến trúc, thuật toán xếp hạng hay quy trình nội bộ của Booking.com.

---

## 1. Mục đích của tài liệu

Tài liệu trả lời bốn câu hỏi:

1. Hệ thống giải quyết bài toán gì và không giải quyết bài toán gì?
2. Mỗi tác nhân được làm gì?
3. Một lượt tìm, xem, đặt, thanh toán, hủy, đổi ngày và đánh giá diễn ra như thế nào?
4. Dữ liệu và trạng thái phải giữ những bất biến nào để không bán vượt phòng, không tính sai tiền và không lộ dữ liệu giữa các đối tác?

Mục tiêu không phải sao chép toàn bộ Booking.com. Mục tiêu là một **mini OTA nhiều cơ sở lưu trú** đủ nghiêm túc để thể hiện luồng đặt chỗ thật, nhưng vẫn vừa sức một bài tập lớn PHP + MySQL.

---

## 2. Phương pháp kiểm chứng Booking.com

### 2.1. Nguồn được dùng

Việc kiểm chứng chỉ dựa trên tài liệu công khai, gồm:

- tài liệu chính thức về tìm kiếm, kiểm tra lại và tạo đơn của Booking.com Demand API;
- tài liệu chính thức về giá, khả năng bán, loại phòng và gói giá dành cho đối tác kết nối;
- trang trợ giúp khách hàng, điều khoản và hướng dẫn đánh giá công khai;
- hành vi có thể quan sát từ trang web công khai tại thời điểm kiểm chứng.

Trang chủ người dùng cuối được dựng động và có thể thay đổi theo vị trí, tài khoản, thiết bị hoặc thử nghiệm giao diện. Vì vậy tài liệu này không lấy một ảnh giao diện tại một thời điểm làm bằng chứng cho quy tắc nghiệp vụ lâu dài.

### 2.2. Ba mức kết luận

| Nhãn | Ý nghĩa |
|---|---|
| **Đã kiểm chứng công khai** | Có tài liệu chính thức hoặc hành vi công khai hỗ trợ kết luận. |
| **Quyết định của MVP** | Quy tắc do dự án tự chốt để triển khai rõ ràng, có thể đơn giản hơn Booking.com. |
| **Cố ý không mô phỏng** | Booking.com có khả năng rộng hơn nhưng dự án không làm trong phiên bản này. |

### 2.3. Kết quả chẩn đoán luồng công khai

| Nội dung quan sát | Điều đã kiểm chứng công khai | Quyết định áp dụng cho MVP |
|---|---|---|
| Tìm kiếm | Yêu cầu có ngày, số khách/phòng và khu vực; kết quả mặc định có thể đưa ra sản phẩm còn bán phù hợp, giá rẻ nhất của mỗi cơ sở. | Search trả một giá khởi điểm từ tổ hợp hợp lệ rẻ nhất; trang chi tiết mới liệt kê các lựa chọn phòng/sản phẩm phù hợp. |
| Giá và chỗ trống | Giá và khả năng bán thay đổi; cần kiểm tra dữ liệu hiện tại trước khi tạo đơn. | Search chỉ để tham khảo. Preview và tạo booking đều đọc lại dữ liệu mới. |
| Preview | Preview cung cấp giá cuối, thuế/phí, cách thanh toán, chính sách hủy và cảnh báo; preview chưa phải booking. | Preview có hạn 15 phút, dùng một lần và tuyệt đối không giữ tồn. |
| Phân bổ khách | Thay đổi ngày, sản phẩm, tuổi hoặc phân bổ khách phải kiểm tra lại; không được âm thầm bỏ hoặc chuyển khách. | Mọi phòng có phân bổ người lớn và tuổi trẻ em rõ ràng; sai sức chứa trả lỗi để khách chọn lại. |
| Xác nhận | Chỉ coi đơn thành công sau khi thao tác tạo đơn trả kết quả thành công. | Chỉ hiển thị mã đặt chỗ sau khi transaction tạo booking đã commit; trả online chỉ báo thành công khi payment đã `PAID` và booking đã `CONFIRMED`. |
| Thanh toán | Nền tảng công khai phân biệt trả tại cơ sở, trả online ngay, trả online sau và có thể có nhiều mốc thanh toán. | MVP chỉ có `PAY_AT_PROPERTY` và `PAY_ONLINE`; không có trả online sau, trả góp, lịch nhiều đợt hoặc bảo lãnh thẻ. |
| Hủy | Phí hủy phải lấy từ chính sách/lịch phí hiện hành của đơn, không đoán từ nhãn ngắn. | Trước đặt dùng snapshot preview; sau đặt dùng snapshot đã lưu trong booking. |
| Sửa booking | Khả năng sửa ngày/thông tin phụ thuộc loại đặt chỗ và chính sách. | MVP cho sửa thông tin khách; đổi ngày chỉ trong điều kiện hẹp đã nêu tại mục 15. |
| Đánh giá | Thang 1–10; cửa sổ công khai khoảng ba tháng và Booking.com có thể nhận đánh giá từ người đã đến nơi dù không lưu trú. | MVP nghiêm hơn: chỉ booking `COMPLETED`, trong 90 ngày, một đánh giá/booking. `NO_SHOW` không được đánh giá. |

### 2.4. Những điều không được suy diễn

- Không khẳng định schema, dịch vụ, hàng đợi, cơ chế khóa hoặc thuật toán xếp hạng nội bộ của Booking.com.
- Không gọi dự án là bản sao Booking.com.
- Không dùng tài liệu API công khai để kết luận rằng giao diện người dùng cuối luôn hiển thị giống hệt API.
- Không sao chép nội dung thương mại, ảnh, nhận xét hoặc dữ liệu vị trí từ Booking.com làm dữ liệu gốc của dự án.

---

## 3. Mục tiêu sản phẩm

Hệ thống cho phép:

- khách tìm và so sánh chỗ lưu trú tại Thành phố Hồ Chí Minh;
- khách đặt một hoặc nhiều phòng trong cùng một cơ sở và cùng khoảng ngày;
- đối tác quản lý nhiều cơ sở, loại phòng, sản phẩm bán, giá và tồn mở bán;
- quản trị viên duyệt cơ sở trước khi xuất hiện công khai;
- hệ thống chống bán vượt bằng khóa dữ liệu và sổ giữ tồn theo từng đêm;
- khách trả tại cơ sở hoặc thanh toán trực tuyến mô phỏng;
- khách hủy, xin miễn phí hủy, đổi ngày trong phạm vi cho phép và gửi đánh giá;
- đối tác xử lý yêu cầu miễn phí hủy, đánh dấu khách không đến và xem báo cáo;
- mọi thay đổi quan trọng có nhật ký đủ để kiểm tra lại.

### 3.1. Thước đo thành công

- Không có booking được tạo nếu thiếu giá hoặc thiếu tồn ở bất kỳ đêm nào.
- Hai yêu cầu cùng tranh phòng cuối không thể cùng thành công.
- Khách nhìn thấy tổng tiền, thời điểm thanh toán và phí hủy trước khi xác nhận.
- Booking đã tạo không thay đổi lịch sử khi đối tác sửa giá hoặc chính sách mới.
- Đối tác không thể đọc hoặc sửa dữ liệu của tổ chức khác.
- Dữ liệu nguồn và dữ liệu đối chiếu địa điểm không bị nhập nhằng.

---

## 4. Phạm vi và giới hạn vận hành

### 4.1. Giới hạn của một yêu cầu đặt chỗ

- Chỉ Thành phố Hồ Chí Minh, `MaThanhPho = HCM`.
- Chỉ tiền Việt Nam, `VND`.
- Tối đa 5 phòng, 10 người lớn, 10 trẻ em và 30 đêm.
- Ngày nhận phòng không ở quá khứ, ngày trả phải sau ngày nhận.
- Chỉ cho đặt trong phần lịch đã mở, tối đa 365 ngày tính từ hiện tại theo quy tắc ứng dụng.
- Mỗi phòng phải có ít nhất một người lớn.
- Trẻ em từ 0 đến 17 tuổi, tuổi phải được gửi cho từng trẻ và trẻ vẫn chiếm sức chứa.
- Mọi phòng trong cùng booking có chung ngày nhận và ngày trả.
- Một booking chỉ thuộc một cơ sở; không gom phòng của nhiều cơ sở vào một giỏ.
- Một booking có thể chứa nhiều phòng và nhiều sản phẩm khác nhau của cùng cơ sở, nhưng tất cả sản phẩm phải có cùng một `ThoiDiemThanhToan`.
- Hủy, no-show và đổi ngày áp dụng toàn booking; không hủy riêng một phòng.

### 4.2. Giới hạn của mô hình khách sạn

Hệ thống quản lý **số lượng mở bán theo loại phòng và ngày**, không quản lý phòng vật lý như phòng 301. Do đó hệ thống không làm:

- xếp khách vào số phòng vật lý;
- nhận/trả phòng tại quầy;
- dọn phòng, khóa cửa, minibar hoặc bảo trì;
- đồng bộ PMS;
- đo tỷ lệ lấp đầy toàn khách sạn nếu đối tác chỉ mở bán một phần tồn trên hệ thống.

### 4.3. Khác biệt có chủ ý so với Booking.com

- Khách phải đăng nhập trước khi tạo preview lưu trong hệ thống và trước khi đặt; MVP không mô phỏng mọi lựa chọn đặt không cần tài khoản.
- Chỉ có hai thời điểm thanh toán thuần nhất; không có trả online sau, nhiều đợt hoặc trộn timing trong một booking.
- Không lưu thẻ, không bảo lãnh thẻ và không dùng cổng thanh toán thật.
- Chính sách hủy có tối đa một mốc miễn phí và một cách tính phạt chính; không mô phỏng lịch phí nhiều tầng.
- Đổi ngày hẹp hơn luồng công khai của Booking.com.
- Review chỉ dành cho booking đã hoàn tất; `NO_SHOW` không đủ điều kiện.
- Không có nhắn tin với cơ sở, email/SMS thật, loyalty, Genius hoặc hệ thống xếp hạng cá nhân hóa.

---

## 5. Thuật ngữ thống nhất

| Thuật ngữ | Nghĩa trong dự án |
|---|---|
| OTA | Nền tảng trung gian tìm kiếm và đặt chỗ trực tuyến. |
| Cơ sở lưu trú | Một khách sạn, khu nghỉ dưỡng, căn hộ, biệt thự, hostel hoặc nhà khách. |
| Tổ chức đối tác | Đơn vị sở hữu/quản lý một hoặc nhiều cơ sở trên hệ thống. |
| Loại phòng | Nhóm phòng có cùng sức chứa và đặc tính; không phải phòng vật lý. |
| Sản phẩm phòng | Cách bán một loại phòng, gồm bữa ăn, cách thanh toán và chính sách hủy. |
| Giá ngày | Giá cùng điều kiện mở bán của một sản phẩm cho một ngày lưu trú. |
| Tồn mở bán | Số lượng loại phòng mà đối tác cho phép nền tảng bán trong một ngày. |
| Khả năng còn chỗ | Kết quả đồng thời thỏa giá, điều kiện bán, sức chứa và tồn ở mọi đêm. |
| Bản xem trước (`preview`) | Bản tính và kiểm tra cuối trước khi đặt; chưa phải đặt chỗ và không giữ tồn. |
| Đặt chỗ (`booking`) | Cam kết đã được ghi nhận với mã đặt chỗ, các phòng, giá và chính sách đã chụp lại. |
| Hạng mục đặt chỗ | Một phòng được đặt trong một lượt đặt chỗ. Hai phòng giống nhau vẫn là hai hạng mục. |
| Bản chụp dữ liệu (`snapshot`) | Dữ liệu được giữ tại thời điểm giao dịch để lịch sử không đổi theo cấu hình mới. |
| Sổ giữ tồn | Các dòng `GiuTonPhongDem` chứng minh booking đang giữ loại phòng nào ở đêm nào. |
| Khách không đến (`no-show`) | Khách không xuất hiện để nhận phòng; mã trạng thái là `NO_SHOW`. |
| Tính lặp an toàn (`idempotency`) | Gửi lại cùng mã yêu cầu và cùng nội dung không tạo tác dụng lần hai. |

Tài liệu ưu tiên từ tiếng Việt: tìm kiếm, xem trước, đặt chỗ, thanh toán, hoàn tiền, nhà cung cấp thanh toán, bản chụp và tính lặp an toàn. Từ tiếng Anh chỉ được giữ khi cần đối chiếu tài liệu nguồn, mã trạng thái, tên lớp/hàm, đường dẫn hoặc giao diện lập trình.

Khoảng lưu trú luôn là khoảng nửa kín:

```text
[NgayNhanPhong, NgayTraPhong)
```

Ví dụ nhận ngày 10 và trả ngày 12 nghĩa là ở hai đêm: ngày 10 và ngày 11.

---

## 6. Tác nhân và quyền

### 6.1. Khách chưa đăng nhập

- tìm kiếm;
- dùng bộ lọc và sắp xếp;
- xem chi tiết cơ sở, loại phòng, tiện nghi, giá tham khảo và đánh giá công khai;
- khi chọn đặt, được yêu cầu đăng nhập/đăng ký trước khi tạo preview.

### 6.2. Khách hàng đã đăng nhập

- thực hiện mọi quyền của khách chưa đăng nhập;
- tạo preview và xác nhận đặt;
- xem booking của chính mình;
- sửa thông tin liên hệ, yêu cầu đặc biệt và danh sách khách trong giới hạn;
- đổi ngày nếu booking đủ điều kiện;
- hủy trực tiếp hoặc gửi yêu cầu miễn phí hủy;
- xem trạng thái thanh toán/hoàn tiền do nền tảng theo dõi;
- tạo một đánh giá cho booking đủ điều kiện.

### 6.3. Thành viên đối tác

- chỉ thao tác trên cơ sở thuộc `ToChucDoiTacID` của mình;
- tạo và sửa cơ sở khi ở trạng thái cho phép;
- quản lý loại phòng, tiện nghi, sản phẩm, chính sách hủy, khuyến mãi, giá và tồn;
- gửi cơ sở để duyệt;
- xem booking của cơ sở thuộc tổ chức;
- duyệt/từ chối yêu cầu miễn phí hủy;
- đánh dấu no-show trong cửa sổ cho phép;
- xem báo cáo bán trên tồn mở bán của tổ chức.

Mỗi tài khoản đối tác chỉ thuộc tối đa một tổ chức trong MVP. `LaChuSoHuu` phân biệt thành viên chủ sở hữu, nhưng MVP chưa tách ma trận quyền chi tiết giữa các thành viên.

Khi `ToChucDoiTac.TrangThai = TAM_KHOA`, thành viên không được quản trị hồ sơ thương mại, giá, tồn, khuyến mãi hoặc gửi cơ sở đi duyệt. Các cơ sở của tổ chức bị loại khỏi tìm kiếm, kiểm tra còn chỗ, preview và booking mới dù bản thân cơ sở đang `ACTIVE`. Booking đã tồn tại không bị tự hủy; khách vẫn tự quản lý booking, còn đối tác chỉ được xử lý các nghiệp vụ phục vụ booking hiện hữu trong phạm vi cần thiết hoặc quản trị viên hỗ trợ có audit.

### 6.4. Quản trị viên

- khóa/mở tài khoản theo nghiệp vụ quản trị;
- quản lý danh mục tiện nghi;
- duyệt, trả về để sửa, từ chối dứt điểm, tạm ngừng hoặc mở lại cơ sở;
- hỗ trợ tra cứu booking nhưng không được tự ý thay đổi giá lịch sử;
- ẩn/hiện đánh giá vi phạm;
- xem nhật ký và báo cáo toàn hệ thống.

### 6.5. Tác vụ nền

Tác vụ PHP định kỳ được phép:

- làm thất bại booking online hết hạn thanh toán và trả tồn;
- hoàn tất booking đã qua ngày trả;
- đối soát bộ đếm tồn với sổ giữ tồn;
- ghi nhật ký cho mỗi thay đổi tự động.

Tác vụ nền không được bỏ qua quy tắc khóa và tính lặp an toàn.

---

## 7. Mô hình nghiệp vụ và 22 bảng chính thức

Schema phiên bản này có **chính xác 22 bảng**.

| # | Bảng | Trách nhiệm nghiệp vụ |
|---:|---|---|
| 1 | `NguoiDung` | Tài khoản khách, đối tác và quản trị viên. |
| 2 | `ToChucDoiTac` | Tổ chức quản lý các cơ sở. |
| 3 | `ThanhVienDoiTac` | Gắn tài khoản đối tác với một tổ chức. |
| 4 | `TienNghi` | Danh mục tiện nghi cấp cơ sở hoặc loại phòng. |
| 5 | `CoSoLuuTru` | Hồ sơ cơ sở, thuế/phí, nguồn dữ liệu, trạng thái duyệt và metadata Google. |
| 6 | `LoaiPhong` | Sức chứa, giường, ảnh và mô tả loại phòng. |
| 7 | `CoSoTienNghi` | Các tiện nghi của cơ sở. |
| 8 | `LoaiPhongTienNghi` | Các tiện nghi của loại phòng. |
| 9 | `ChinhSachHuy` | Mốc miễn phí, cách phạt hủy và tỷ lệ phạt no-show. |
| 10 | `SanPhamPhong` | Gói bán của loại phòng: bữa ăn, thời điểm thanh toán, chính sách hủy. |
| 11 | `GiaPhongNgay` | Giá và điều kiện mở bán của sản phẩm theo ngày. |
| 12 | `KhuyenMai` | Khuyến mãi phần trăm cấp cơ sở theo cửa sổ đặt/lưu trú. |
| 13 | `TonPhongNgay` | Tổng tồn mở bán và số đã giữ theo loại phòng/ngày. |
| 14 | `XemTruocDatCho` | Preview có hạn, tổng tiền và phân bổ/snapshot dạng JSON. |
| 15 | `DatCho` | Đầu booking, trạng thái, liên hệ, dữ liệu hủy và no-show. |
| 16 | `HangMucDatCho` | Một phòng trong booking cùng snapshot sản phẩm/khách/chính sách. |
| 17 | `GiaDemDatCho` | Giá lịch sử của từng hạng mục theo từng đêm. |
| 18 | `GiuTonPhongDem` | Sổ giữ hoặc trả tồn theo hạng mục và đêm. |
| 19 | `ThanhToan` | Một bản ghi thanh toán/hoàn tiền tổng hợp cho mỗi booking. |
| 20 | `YeuCauHuyMienPhi` | Yêu cầu ngoại lệ miễn phí hủy và kết quả xử lý. |
| 21 | `DanhGia` | Điểm và nhận xét của khách sau lưu trú. |
| 22 | `NhatKyHeThong` | Nhật ký thao tác quan trọng và dữ liệu trước/sau. |

### 7.1. Ranh giới dữ liệu quan trọng

- `CoSoLuuTru` khác `LoaiPhong`: một cơ sở có nhiều loại phòng.
- `LoaiPhong` khác `SanPhamPhong`: một loại phòng có thể bán với nhiều bữa ăn/chính sách/cách trả.
- Tồn nằm tại `LoaiPhong + NgayLuuTru`, không nằm tại sản phẩm. Các sản phẩm của cùng loại phòng dùng chung tồn.
- Giá nằm tại `SanPhamPhong + NgayLuuTru`.
- `DatCho` không chứa chi tiết từng phòng; chi tiết nằm ở `HangMucDatCho`.
- `GiaDemDatCho` giữ lịch sử; không tính lại booking cũ từ `GiaPhongNgay` hiện tại.
- `GiuTonPhongDem` là chứng cứ chi tiết, còn `TonPhongNgay.SoLuongDaGiu` là bộ đếm truy vấn nhanh. Hai phần phải luôn khớp.
- `DatCho` và `ThanhToan` chỉ có một `ThoiDiemThanhToan`; vì vậy mọi `SanPhamPhong` trong cùng allocation phải cùng timing.
- Không gộp booking, thanh toán và tồn vào một bảng.

---

## 8. Onboarding, độ sẵn sàng và duyệt cơ sở

### 8.1. Khởi tạo đối tác

Tổ chức và thành viên đầu tiên được tạo bằng quy trình quản trị có kiểm soát hoặc dữ liệu khởi tạo. MVP không có bảng hồ sơ xin trở thành đối tác. Mọi tài khoản đối tác phải có dòng `ThanhVienDoiTac` hợp lệ trước khi quản lý cơ sở.

### 8.2. Trạng thái cơ sở

```text
DRAFT → PENDING_REVIEW → ACTIVE ↔ SUSPENDED
              │
              ├─ cần sửa → DRAFT
              └─ từ chối dứt điểm → REJECTED
```

Quy tắc:

- `DRAFT`: đối tác được sửa, chưa xuất hiện công khai.
- `PENDING_REVIEW`: đang chờ quản trị viên; đối tác không được thay đổi dữ liệu làm sai nội dung đang duyệt.
- **Trả về để sửa**: chuyển từ `PENDING_REVIEW` về `DRAFT`, không dùng trạng thái `NEEDS_CHANGES`. Ghi chú phản hồi nằm ở `GhiChuDuyet` và sự kiện người trả về nằm trong `NhatKyHeThong`; `NguoiDuyetID`, `DuyetLuc` phải để trống để đúng ràng buộc schema.
- `ACTIVE`: đủ điều kiện xuất hiện trong tìm kiếm nếu giá/tồn cũng hợp lệ.
- `SUSPENDED`: bị tạm ngừng; không xuất hiện trong tìm kiếm và không nhận booking mới, nhưng booking lịch sử vẫn đọc/xử lý được. Có thể được quản trị viên mở lại `ACTIVE`.
- `REJECTED`: kết thúc trong luồng tự phục vụ của đối tác; đối tác không tự sửa/gửi lại và không tạo bản ghi thay thế cùng provenance. Chỉ quản trị viên hỗ trợ mới được mở lại **chính bản ghi đó** về `DRAFT` trong trường hợp ngoại lệ, phải ghi audit/lý do và xóa `NguoiDuyetID`, `DuyetLuc` theo CHECK của schema.

### 8.3. Điều kiện gửi duyệt

Cơ sở chỉ được chuyển sang `PENDING_REVIEW` khi đồng thời có:

- tổ chức sở hữu đang `HOAT_DONG`;
- tên, loại cơ sở, địa chỉ, múi giờ, giờ nhận/trả;
- ít nhất một ảnh hợp lệ;
- nguồn tên, mã dòng nguồn, nguồn địa chỉ và thời điểm xác minh;
- ít nhất một loại phòng `ACTIVE`, ảnh và cấu hình giường hợp lệ;
- ít nhất một chính sách hủy `ACTIVE` thuộc cùng cơ sở;
- ít nhất một sản phẩm `ACTIVE` liên kết đúng loại phòng/chính sách;
- giá và tồn cho khoảng mở bán tối thiểu do dự án quy định;
- tỷ lệ thuế/phí hợp lệ;
- không có quan hệ chéo sang cơ sở khác.

Việc duyệt hồ sơ không đảm bảo cơ sở luôn còn chỗ. Search vẫn phải kiểm tra giá, điều kiện bán và tồn theo ngày.

### 8.4. Quản lý nội dung sau khi hoạt động

- Dữ liệu đã xuất hiện trong booking không xóa cứng; chuyển `INACTIVE`/`NGUNG` khi ngừng bán.
- Thay đổi tên, ảnh, tiện nghi hoặc mô tả không được sửa snapshot booking cũ.
- Tạm ngừng cơ sở không tự hủy booking đã xác nhận. Đối tác và quản trị viên phải xử lý các booking đó theo quy trình riêng nếu không thể phục vụ.

## 9. Giá, tồn và điều kiện mở bán

### 9.1. Tồn theo từng đêm

`TonPhongNgay` quản lý:

```text
LoaiPhongID + NgayLuuTru
```

Trong đó:

- `TongSoLuong`: số lượng đối tác mở bán trên nền tảng, không phải toàn bộ số phòng vật lý;
- `SoLuongDaGiu`: số lượng đang được các booking hợp lệ giữ;
- `PhienBan`: dùng phát hiện thay đổi và lập fingerprint.

Với nhu cầu `q`, một ngày đủ tồn khi:

```text
SoLuongDaGiu + q <= TongSoLuong
```

Một khoảng lưu trú đủ tồn khi điều kiện trên đúng ở **mọi đêm**.

### 9.2. Sổ giữ tồn

Mỗi `HangMucDatCho` sinh một dòng `GiuTonPhongDem` cho mỗi đêm. Ví dụ booking hai phòng trong ba đêm tạo sáu dòng. Dòng `ACTIVE` giữ một đơn vị; dòng `RELEASED` không còn giữ.

```text
TonPhongNgay.SoLuongDaGiu
  = số dòng GiuTonPhongDem ACTIVE
    của cùng LoaiPhongID + NgayLuuTru
```

### 9.3. Giá và hạn chế bán

Mỗi `GiaPhongNgay` chứa:

- `GiaCoBan`;
- `DongBan`;
- `SoDemToiThieu`;
- `SoDemToiDa` nếu có;
- `SoNgayDatTruoc`;
- `PhienBan`.

Một sản phẩm chỉ đủ điều kiện khi:

1. cơ sở, loại phòng, sản phẩm và chính sách đều đang hoạt động;
2. mọi đêm đều có dòng giá và `DongBan = 0`;
3. số đêm nằm trong giới hạn;
4. số ngày đặt trước đạt yêu cầu;
5. loại phòng chứa được đúng phân bổ khách;
6. mọi đêm đủ tồn.

Để đơn giản, số đêm tối thiểu/tối đa và số ngày đặt trước lấy từ dòng giá của **ngày nhận phòng**. `SoNgayDatTruoc` là số ngày dẫn tối thiểu:

```text
DATEDIFF(NgayNhanPhong, DATE(thời điểm kiểm tra theo múi giờ cơ sở))
  >= SoNgayDatTruoc
```

Đây không phải số ngày tối đa được đặt xa. MVP chưa hỗ trợ đóng riêng ngày đến/ngày đi.

### 9.4. Giá cuối và thuế/phí

Giá được tính theo từng phòng, từng đêm và chụp vào `GiaDemDatCho`:

```text
giá cơ bản
− tiền khuyến mãi
+ thuế/phí thuộc booking
= thành tiền đêm
```

Quy tắc:

- dùng kiểu thập phân/số nguyên tiền, không cộng bằng số thực nhị phân của PHP;
- làm tròn tới một đồng VND theo một quy tắc thống nhất trong bộ tính giá;
- tổng booking bằng tổng `ThanhTien` của mọi đêm, mọi hạng mục;
- snapshot phải ghi tỷ lệ, cờ đã bao gồm, cách làm tròn và kết quả tiền cụ thể;
- `null`, thiếu trường hoặc chưa tính không được hiểu là số 0;
- giao diện phải tách giá cơ bản, giảm giá và thuế/phí.

### 9.5. Timing thanh toán thuần nhất

Mô hình hiện tại chỉ có một `ThoiDiemThanhToan` ở `DatCho` và `ThanhToan`. Vì vậy một booking không được trộn sản phẩm trả online với sản phẩm trả tại cơ sở:

| Timing của mọi hạng mục | `SoTienTraNgay` | `SoTienTraTaiCoSo` |
|---|---:|---:|
| `PAY_ONLINE` | bằng `TongTien` | 0 |
| `PAY_AT_PROPERTY` | 0 | bằng `TongTien` |

Hai giá trị phân rã này được lưu ở `XemTruocDatCho` và xuất hiện trong `PriceBreakdown`/snapshot để hiển thị. `DatCho` chỉ lưu `ThoiDiemThanhToan` và `TongTien`; không thêm hai cột lặp lại vào booking chỉ để giảm mơ hồ ở tầng giao diện.

Preview phải loại allocation có timing hỗn hợp. Với `PAY_ONLINE`, mọi thuế/phí thuộc booking phải được thu trong tổng online và `GiaDemDatCho.ThuePhiTaiCoSo = 0`. Nếu có khoản bắt buộc phải trả tại cơ sở thì sản phẩm đó phải dùng `PAY_AT_PROPERTY` trong MVP.

**Trạng thái dữ liệu:** seed vận hành tuân theo các bất biến payment timing đã chốt từ generator `2.1-payment-timing`; generator hiện hành là `2.2-image-assets` và không thay đổi các bất biến này. Với `PAY_ONLINE`, phần phí dịch vụ bắt buộc được đưa vào phần thu trực tuyến và `GiaDemDatCho.ThuePhiTaiCoSo = 0`; với `PAY_AT_PROPERTY`, cột này vẫn được phép lớn hơn 0. Validator phải tiếp tục chặn việc tái xuất hiện dữ liệu sai.

### 9.6. Khuyến mãi

- Khuyến mãi áp dụng cấp cơ sở, theo cửa sổ đặt và cửa sổ lưu trú.
- Chỉ dùng phần trăm giảm.
- Không cộng dồn.
- Nếu nhiều khuyến mãi hợp lệ, chọn `PhanTramGiam` lớn nhất; nếu bằng nhau, chọn mã/ID nhỏ nhất để kết quả luôn xác định.
- Booking giữ `KhuyenMaiID` và snapshot tiền giảm từng đêm; sửa hoặc tắt khuyến mãi không làm đổi lịch sử.

### 9.7. Chính sách hủy

Schema có hai cấu hình gốc:

- `FLEXIBLE`: có thể miễn phí đến một mốc rồi tính phạt;
- `NON_REFUNDABLE`: tính phí theo cấu hình ngay từ lúc đặt.

Ngữ nghĩa chốt:

- `FLEXIBLE`: miễn phí trước deadline địa phương tính từ `SoGioHuyMienPhi`; từ deadline trở đi áp dụng `LoaiPhat`.
- `NON_REFUNDABLE`: áp dụng phạt ngay từ lúc booking được xác nhận, không có khoảng miễn phí.
- `NONE`: phí 0 và `GiaTriPhat = 0`.
- `PERCENT`: phí bằng `GiaTriPhat%` tổng tiền snapshot của hạng mục.
- `FIRST_NIGHT`: phí bằng `ThanhTien` của đêm đầu trong snapshot; giá trị chuẩn hóa của `GiaTriPhat` là `1`, biểu thị một đêm nhưng bộ tính phí vẫn phải đọc giá đêm đầu từ snapshot.
- `FULL_STAY`: phí bằng toàn bộ tổng tiền snapshot của hạng mục; giá trị chuẩn hóa của `GiaTriPhat` là `100`, biểu thị toàn bộ kỳ ở nhưng không được diễn giải như phần trăm.
- Chỉ `PERCENT` diễn giải `GiaTriPhat` là tỷ lệ phần trăm. Các giá trị chuẩn hóa của `NONE/FIRST_NIGHT/FULL_STAY` giúp seed và validator có một biểu diễn duy nhất, không thay thế công thức tính từ snapshot.
- Phí được tính riêng từng `HangMucDatCho` rồi cộng cho toàn booking; không hủy một phần.
- Phí no-show của mỗi hạng mục bằng `TyLePhatNoShow%` tổng tiền snapshot hạng mục, rồi cộng và làm tròn VND nhất quán.

Giao diện phải hiển thị deadline địa phương, số tiền hoặc công thức cụ thể, không chỉ nhãn “linh hoạt”. Chính sách phức tạp nhiều mốc của Booking.com là phần cố ý không mô phỏng.

---

## 10. Luồng khách: tìm kiếm đến xác nhận

### 10.1. Tổng quan

```text
Nhập nhu cầu
→ Kết quả tìm kiếm
→ Trang chi tiết và lựa chọn phòng
→ Đăng nhập nếu cần
→ Preview
→ Màn hình xác nhận
→ Tạo booking
→ Thanh toán online nếu có
→ Trang xác nhận/quản lý booking
```

### 10.2. Bước 1 – nhập nhu cầu

Đầu vào bắt buộc:

- điểm đến; MVP chỉ chấp nhận Thành phố Hồ Chí Minh;
- ngày nhận, ngày trả;
- số phòng;
- số người lớn của từng phòng;
- số trẻ em và tuổi từng trẻ của từng phòng.

Máy chủ phải kiểm tra lại toàn bộ giới hạn ở mục 4, dù trình duyệt đã kiểm tra.

### 10.3. Bước 2 – tìm kiếm

Luồng xử lý:

```text
kiểm tra đầu vào
→ chọn cơ sở ACTIVE tại HCM có tổ chức sở hữu HOAT_DONG
→ chọn loại phòng/sản phẩm đang hoạt động
→ kiểm tra đủ dòng giá mọi đêm
→ kiểm tra điều kiện ở ngày nhận
→ lấy tồn còn nhỏ nhất của từng loại phòng
→ tìm tổ hợp tối đa 5 phòng đáp ứng đúng phân bổ khách
→ chỉ giữ tổ hợp có cùng payment timing
→ tính giá tổ hợp hợp lệ rẻ nhất của mỗi cơ sở
→ lọc, sắp xếp, phân trang
```

Quy tắc khi một cơ sở có cả hai timing:

1. `RoomAllocator` được gọi một lần cho cơ sở, nhưng bên trong chia sản phẩm thành hai nhánh độc lập: `PAY_AT_PROPERTY` và `PAY_ONLINE`.
2. Mỗi nhánh chỉ tạo tổ hợp thuần nhất và dùng cùng ảnh chụp giá/tồn đầu vào; không trừ tồn giữa hai nhánh vì chúng là hai phương án thay thế, chưa phải hai booking đồng thời.
3. Nếu không lọc timing, hệ thống tính tổ hợp tốt nhất của cả hai nhánh rồi chọn tổ hợp có tổng giá thấp nhất làm **lựa chọn đại diện** cho thẻ kết quả.
4. Nếu chỉ một nhánh có tổ hợp hợp lệ, dùng nhánh đó. Nếu cả hai nhánh không hợp lệ, cơ sở không xuất hiện.
5. Nếu hai tổ hợp bằng tổng giá, ưu tiên `PAY_AT_PROPERTY`, sau đó so chuỗi `(LoaiPhongID, SanPhamPhongID)` theo thứ tự phòng để kết quả xác định.
6. Đây là một lần gọi allocator với tối đa hai nhánh nội bộ, không phải thử nhánh rẻ nhất rồi mới fallback. Cách fallback tuần tự có thể bỏ sót tổ hợp nhiều phòng tốt hơn.

Mỗi thẻ kết quả tối thiểu hiển thị:

- tên, loại, ảnh đại diện, địa chỉ tóm tắt;
- điểm đánh giá và số đánh giá đã công bố;
- các tiện nghi nổi bật;
- ngày/số đêm/phân bổ đang tìm;
- giá khởi điểm của lựa chọn đại diện, tổng thuế/phí và badge `Trả ngay` hoặc `Trả tại cơ sở`;
- nhãn hủy dựa trên chính sách thật;
- cảnh báo nếu chỉ còn ít tồn mở bán.

Kết quả search phải mang theo timing, phân bổ phòng/sản phẩm và dấu vân tay lựa chọn của phương án đại diện. Giá ở trang kết quả là thông tin tạm thời, không phải cam kết giữ chỗ; dấu vân tay này chỉ dùng đối chiếu tính liên tục giữa search và detail, không phải token bảo mật hay token giữ phòng.

### 10.4. Bộ lọc và sắp xếp

Bộ lọc MVP:

- khoảng giá;
- loại cơ sở;
- tiện nghi;
- bữa ăn;
- trả tại cơ sở/trả online;
- có khoảng hủy miễn phí;
- điểm đánh giá;
- số sao nếu trường này có nguồn rõ ràng.

Sắp xếp MVP:

- phù hợp mặc định theo quy tắc công khai của chính dự án;
- giá tăng/giảm;
- điểm đánh giá;
- số sao.

Không sắp theo khoảng cách khi tọa độ chưa đủ tin cậy. Không gọi sắp xếp mặc định là thuật toán của Booking.com.

Quy tắc timing và giá:

- nếu khách lọc `PAY_ONLINE` hoặc `PAY_AT_PROPERTY`, allocator chỉ xét nhánh tương ứng; giá trên card và giá dùng để sort đều là giá của nhánh đó;
- nếu không lọc timing, sort theo giá của lựa chọn đại diện rẻ nhất toàn cục đang hiển thị trên card;
- bộ lọc khoảng giá áp dụng lên cùng giá đại diện sau khi đã áp dụng bộ lọc timing;
- không được hiển thị giá của một timing nhưng badge, sort key hoặc đường dẫn sang detail lại mang timing khác.

### 10.5. Bước 3 – trang chi tiết và kiểm tra khả năng còn chỗ

Trang chi tiết hiển thị:

- thông tin, ảnh, tiện nghi, giờ nhận/trả và chính sách chung của cơ sở;
- các loại phòng phù hợp cùng sức chứa, giường, diện tích và tiện nghi;
- các sản phẩm của từng loại phòng: bữa ăn, cách trả, chính sách hủy;
- giá từng đêm/tổng, thuế/phí và số phòng có thể chọn;
- đánh giá đã công bố;
- nguồn/bản đồ ở mức được phép hiển thị.

Khác với thẻ search chỉ cần một giá đại diện, trang này trả các lựa chọn hợp lệ để khách chủ động chọn. Timing mặc định khi khách đi từ search sang detail phải là timing của card vừa bấm, không tự chuyển sang timing do hệ thống ưu tiên khác.

Trang chi tiết có nút chuyển giữa `Trả tại cơ sở` và `Trả trực tuyến` khi cả hai nhánh có lựa chọn. Mỗi lần chuyển timing phải gọi lại endpoint availability với tiêu chí gốc và timing được chọn; kết quả chỉ chứa các tổ hợp thuần nhất của timing đó. Nếu truy cập detail trực tiếp mà không có lựa chọn từ search, hệ thống dùng cùng quy tắc chọn đại diện tại mục 10.3 để xác định timing mặc định.

Search và detail phải dùng cùng `PriceCalculator`, cùng tiêu chí chuẩn hóa và cùng quy tắc promotion/tie-break. Khi ngày, khách, sản phẩm, phiên bản giá/tồn và timing không đổi, detail phải nhận lại đúng lựa chọn đại diện và đúng giá search. Nếu dữ liệu đã thay đổi, detail phải đánh dấu lựa chọn cũ không còn mới, hiển thị giá/lựa chọn mới và yêu cầu khách xác nhận; không âm thầm tạo cảm giác “giá nhảy”.

Khi đổi ngày, số khách, tuổi, số phòng hoặc timing, phải chạy lại kiểm tra; không âm thầm giữ lựa chọn cũ.

### 10.6. Phân bổ nhiều phòng

- Mỗi nhóm khách do người dùng nhập gắn với một phòng mong muốn.
- Thuật toán tìm tổ hợp phòng theo sức chứa và tồn, giới hạn không gian tìm kiếm ở tối đa 5 phòng.
- Không được âm thầm bỏ trẻ em, đổi tuổi, gom/tách nhóm hoặc chuyển người sang phòng khác mà không hiển thị rõ.
- Nếu không có tổ hợp đúng, trả trạng thái “không có lựa chọn phù hợp”; có thể gợi ý khách sửa nhu cầu nhưng không tự thay đổi yêu cầu.
- Hai phòng giống hệt nhau vẫn trở thành hai hạng mục riêng khi đặt.
- Các sản phẩm được chọn phải cùng `PAY_ONLINE` hoặc cùng `PAY_AT_PROPERTY`; nếu trộn, yêu cầu khách chọn lại.
- Mỗi `RoomAllocation` và kết quả tính giá phải mang `paymentTiming`; timing của tất cả phòng phải giống timing cấp tổ hợp.
- Khi không lọc timing, allocator so sánh hai tổ hợp thuần nhất hoàn chỉnh. Không chọn sản phẩm rẻ nhất từng phòng trước rồi ghép, vì cách đó có thể tạo tổ hợp hỗn hợp hoặc sai tối ưu toàn booking.

### 10.7. Bước 4 – đăng nhập

Khách có thể tìm và xem khi chưa đăng nhập. Vì `XemTruocDatCho.KhachHangID` là bắt buộc, hệ thống yêu cầu đăng nhập/đăng ký trước khi tạo preview lưu ở máy chủ. Sau đăng nhập phải kiểm tra lại lựa chọn, không dùng nguyên dữ liệu giá ở trình duyệt.

### 10.8. Bước 5 – tạo preview

Preview phải đọc lại:

- trạng thái cơ sở/loại phòng/sản phẩm/chính sách;
- phiên bản giá và đủ giá mọi đêm;
- restriction;
- tồn hiện tại;
- khuyến mãi hiện hành;
- thuế/phí;
- phân bổ và sức chứa;
- cùng một timing thanh toán cho mọi hạng mục.

Preview trả tối thiểu:

- cơ sở, ngày và thông tin từng phòng;
- phân bổ người lớn, trẻ em và tuổi;
- sản phẩm, bữa ăn;
- giá từng đêm, giảm giá, thuế/phí và tổng;
- `SoTienTraNgay`, `SoTienTraTaiCoSo` theo quy tắc all-or-nothing;
- chính sách hủy/no-show cụ thể của từng phòng;
- cảnh báo nếu có khác biệt so với màn hình trước;
- token hết hạn sau tối đa 15 phút.

Preview:

- không phải booking;
- không ghi `GiuTonPhongDem`;
- không tăng `SoLuongDaGiu`;
- chỉ dùng một lần;
- lưu token dạng băm, không lưu token thô;
- có fingerprint của lựa chọn, timing và phiên bản dữ liệu;
- bị từ chối nếu hết hạn, đã dùng, sai khách hoặc dữ liệu đã đổi.

Nếu giá, sản phẩm, ngày, tuổi, phân bổ hoặc timing thay đổi, hệ thống tạo preview mới và yêu cầu khách xác nhận lại. Không âm thầm thay sản phẩm.

### 10.9. Bước 6 – màn hình xác nhận

Trước nút xác nhận, giao diện phải cho khách xem lại:

- tên cơ sở và thông tin liên hệ đặt chỗ;
- từng phòng và từng nhóm khách;
- ngày/đêm;
- tổng tiền và một thời điểm thanh toán thuần nhất;
- bữa ăn;
- hạn/mức phí hủy và phí no-show;
- điều kiện đổi ngày của MVP;
- yêu cầu đặc biệt, với lưu ý đây là yêu cầu chứ không phải cam kết phục vụ.

### 10.10. Bước 7 – tạo booking

Khách gửi:

- token preview;
- `ClientRequestID` UUID do máy khách tạo;
- thông tin người đặt;
- xác nhận đã xem giá/chính sách.

`ClientRequestID` **duy nhất toàn cục**, đúng với unique key của schema:

- gửi lại cùng ID, cùng khách và cùng payload chuẩn hóa thì trả booking đã tạo;
- cùng ID nhưng khác khách hoặc khác payload thì trả lỗi xung đột `409`;
- phản hồi xung đột không được tiết lộ booking, email hoặc thông tin của chủ thể khác.

Transaction tạo booking:

```text
BEGIN
→ khóa preview
→ kiểm tra token, chủ sở hữu, trạng thái, hạn và fingerprint
→ kiểm tra ClientRequestID và payload
→ đọc lại sản phẩm, giá, restriction, timing và sức chứa
→ gom nhu cầu theo LoaiPhongID + NgayLuuTru
→ khóa tồn theo thứ tự LoaiPhongID, NgayLuuTru
→ kiểm tra đủ tồn lần cuối
→ tạo DatCho
→ tạo HangMucDatCho và GiaDemDatCho
→ tạo GiuTonPhongDem ACTIVE và tăng counter
→ tạo ThanhToan ban đầu
→ đánh dấu preview USED
→ ghi NhatKyHeThong
COMMIT
```

Nếu bất kỳ bước nào lỗi, rollback toàn bộ. Không được có booking thiếu hạng mục, giữ tồn thiếu đêm hoặc payment thiếu booking.

### 10.11. Bước 8 – xác nhận kết quả

- `PAY_AT_PROPERTY`: booking được `CONFIRMED` ngay sau commit; thanh toán là `NOT_TRACKED`.
- `PAY_ONLINE`: booking là `PENDING_PAYMENT`; chỉ chuyển `CONFIRMED` sau khi mô phỏng thanh toán thành công.
- Mã đặt chỗ công khai phải khó đoán, khác khóa số tự tăng nội bộ.
- Trang xác nhận online chỉ được báo “đặt thành công” sau khi payment đã `PAID` **và** booking đã `CONFIRMED`.
- Trước thời điểm đó chỉ hiển thị “đang chờ thanh toán”, không gây hiểu nhầm đã xác nhận.

---

## 11. Thanh toán

### 11.1. Phân biệt khái niệm

Booking.com công khai phân biệt thời điểm, phương thức và lịch thanh toán. MVP chỉ giữ **thời điểm thanh toán** ở mức đơn giản:

- `PAY_AT_PROPERTY`: nền tảng không thu tiền;
- `PAY_ONLINE`: nền tảng mô phỏng thu toàn bộ `TongTien` ngay.

Không có bảng danh mục cách thanh toán. Không có lựa chọn động do quản trị viên thêm.

### 11.2. Trả tại cơ sở

- Booking được xác nhận ngay nếu transaction giữ tồn thành công.
- `ThanhToan.TrangThai = NOT_TRACKED`.
- `TongPhaiTra` giữ tổng giá trị đã báo để đối chiếu; `DaThanhToan = 0`, `DaHoan = 0`.
- Hệ thống không ghi nhận khách đã đưa tiền mặt/thẻ cho cơ sở.
- Booking có thể `COMPLETED` trong khi payment vẫn `NOT_TRACKED`; đây không phải lỗi.
- Phí hủy hoặc no-show chỉ được **tính và ghi nhận là khoản phải chịu**, không được coi là đã thu. MVP không theo dõi việc cơ sở có thực thu khoản đó hay không.
- Không tạo hoàn tiền cho khoản nền tảng chưa từng thu.

### 11.3. Trả trực tuyến mô phỏng

Sau transaction booking:

```text
Booking = PENDING_PAYMENT
Payment = PROCESSING
Ledger = ACTIVE, có HetHanLuc
```

Quy tắc:

1. Commit booking và tồn trước khi gọi bộ mô phỏng thanh toán.
2. Không gọi provider khi đang giữ khóa tồn.
3. Thành công: payment `PAID`, `DaThanhToan = TongTien` và booking `CONFIRMED` trong transaction ngắn.
4. Thất bại hoặc hết hạn: payment `FAILED`, booking `PAYMENT_FAILED`, trả mọi ledger `ACTIVE` đúng một lần.
5. Retry dùng cùng payment và cùng `IdempotencyKey`; tăng `SoLanThu`, không tạo booking/payment mới.
6. Nếu phản hồi không chắc chắn, không đoán thành công; giữ trạng thái có thể đối soát và chỉ chốt khi có kết quả xác định.
7. Kết quả thành công đến muộn sau khi booking đã `PAYMENT_FAILED` không được hồi sinh booking hoặc giữ lại tồn. Nếu còn void được, giữ `DaThanhToan = 0` và void idempotent. Nếu provider báo đã capture, booking vẫn `PAYMENT_FAILED`, payment vẫn mang kết quả nghiệp vụ `FAILED` nhưng ghi số thực tế đã capture vào `DaThanhToan`, khởi tạo refund `PENDING` rồi hoàn toàn bộ bằng idempotency riêng. Cả hai trường hợp phải audit; nhánh này không được dùng như thanh toán thành công bình thường.

### 11.4. Một payment cho một booking

`ThanhToan` là bản tổng hợp duy nhất, không phải sổ từng lần thử. Nó giữ:

- timing, trạng thái, tổng giá trị booking;
- số đã thu online, số đã hoàn thành công;
- provider mô phỏng, idempotency key và reference;
- số lần thử và lỗi cuối;
- trạng thái và metadata hoàn tiền.

Chi tiết từng lần thử chỉ ghi audit. Đây là quyết định giảm độ sâu payment cho bài tập lớn.

### 11.5. Số tiền hiển thị

- `TongTien` luôn là toàn bộ giá trị booking đã chụp.
- `SoTienTraNgay + SoTienTraTaiCoSo = TongTien` ở preview.
- `PAY_ONLINE`: `SoTienTraNgay = TongTien`, `SoTienTraTaiCoSo = 0`.
- `PAY_AT_PROPERTY`: `SoTienTraNgay = 0`, `SoTienTraTaiCoSo = TongTien`.
- Giao diện không được coi giá trị thiếu là 0.

---

## 12. Quản lý booking sau khi đặt

### 12.1. Truy cập booking

Khách đã đăng nhập chỉ được xem booking có `KhachHangID` của mình. Đối tác chỉ xem booking có `CoSoLuuTru.ToChucDoiTacID` của tổ chức mình. Quản trị viên được tra cứu để hỗ trợ.

Trang chi tiết booking hiển thị dữ liệu snapshot, không lấy tên/giá/chính sách hiện tại thay cho lịch sử.

### 12.2. Sửa thông tin không ảnh hưởng giá/tồn

Khách chỉ có thể sửa trước thời điểm nhận phòng địa phương:

```text
cutoff = NgayNhanPhong + GioNhanPhong trong MuiGio của cơ sở
```

Tại hoặc sau cutoff, vì MVP không có `CHECKED_IN` hay lưu trú một phần, khách không được tự sửa/hủy/đổi ngày; chỉ còn luồng no-show, hoàn tất hoặc hỗ trợ ngoài hệ thống.

Trước cutoff, khách có thể sửa:

- số điện thoại/email liên hệ;
- yêu cầu đặc biệt;
- tên khách trong `KhachJSON` nếu không thay đổi số lượng, tuổi hoặc sức chứa.

Nếu thay đổi số người hoặc tuổi làm thay đổi sức chứa/giá, không xử lý như sửa thông tin đơn giản; phải hủy và đặt lại trong MVP.

Mọi thay đổi ghi dữ liệu trước/sau trong nhật ký. Yêu cầu đặc biệt không đồng nghĩa cơ sở đã chấp nhận.

---

## 13. Hủy trực tiếp, phí hủy và hoàn tiền

### 13.1. Nguồn tính phí

- Trước booking: dùng snapshot do preview vừa tạo.
- Sau booking: dùng `SnapshotHuyJSON`/`GiaDemDatCho` của booking, không dùng chính sách hiện tại.
- Mốc thời gian tính theo `CoSoLuuTru.MuiGio`.
- Phí không âm, không vượt tổng giá trị phần liên quan và phải được chốt vào `DatCho`.

### 13.2. Phạm vi

Hủy trực tiếp áp dụng toàn booking `CONFIRMED` và chỉ trước cutoff nhận phòng nêu tại mục 12.2. Booking `PENDING_PAYMENT` không đi qua hủy có phí; khách có thể bỏ thanh toán và tác vụ hết hạn sẽ chuyển `PAYMENT_FAILED`, trả tồn. `NO_SHOW` và `COMPLETED` là trạng thái kết thúc, không được hủy. Không cho hủy giữa kỳ để tránh mơ hồ về các đêm đã dùng/còn lại.

### 13.3. Dữ liệu hủy

Không có bảng hủy riêng. `DatCho` phải giữ:

- `HuyIdempotencyKey`;
- `NguoiHuyID`, `HuyLuc`, `LyDoHuy`;
- `PhiHuy`;
- `SoTienHoan`: chỉ là số **đã hoàn thành công**, không phải số dự kiến;
- `TinhHuySnapshotJSON` chứa đầu vào, chính sách, thời điểm, múi giờ, phép tính và `refundExpected`.

Chi tiết trước/sau và request nằm trong `NhatKyHeThong`.

### 13.4. Luồng hủy

```text
hiển thị phí dự kiến từ snapshot
→ khách xác nhận và gửi HuyIdempotencyKey
→ BEGIN
→ khóa booking
→ kiểm tra trạng thái, chủ thể, payload và idempotency
→ khóa ThanhToan
→ khóa ledger ACTIVE và inventory theo thứ tự ổn định
→ chốt PhiHuy, đặt SoTienHoan = 0, chuyển CANCELLED
→ release ledger và giảm counter đúng một lần
→ nếu đã thu online và cần hoàn: đặt refund PENDING
→ ghi refundExpected trong snapshot và ghi audit
→ COMMIT
→ gọi mock refund ngoài transaction
→ transaction ngắn chốt kết quả refund
```

Gửi lại cùng `HuyIdempotencyKey`, cùng chủ thể và cùng payload trả kết quả cũ. Cùng key nhưng khác chủ thể/payload trả `409` mà không rò dữ liệu. Không release tồn hoặc hoàn tiền lần hai.

### 13.5. Tính hoàn tiền

- `PAY_AT_PROPERTY`: `SoTienHoan = 0`; phí chỉ được ghi nhận, không coi là đã thu.
- `PAY_ONLINE`: `refundExpected` không vượt `DaThanhToan` và bằng phần đã thu còn lại sau phí theo snapshot.
- Khi refund `PENDING` hoặc `FAILED`: `DatCho.SoTienHoan` và phần tăng thêm của `ThanhToan.DaHoan` vẫn bằng 0.
- Chỉ khi provider trả `SUCCEEDED` mới cập nhật nguyên tử `DatCho.SoTienHoan`, `ThanhToan.DaHoan`, provider reference và thời điểm hoàn tất.
- `DaHoan <= DaThanhToan`.
- Hoàn tiền có idempotency key riêng.
- `CANCELLED` không đồng nghĩa hoàn tiền đã xong.
- Refund `FAILED` không được tự đổi booking về `CONFIRMED`; booking vẫn `CANCELLED`, trạng thái hoàn tiền được xử lý lại riêng.

## 14. Yêu cầu miễn phí hủy

Yêu cầu miễn phí hủy là ngoại lệ do con người quyết định, tách khỏi hủy tự động theo chính sách.

### 14.1. Điều kiện

- Khách sở hữu booking `CONFIRMED`.
- Thời điểm yêu cầu còn trước cutoff nhận phòng địa phương.
- Chưa có yêu cầu `PENDING` khác cho cùng booking.
- Lý do bắt buộc.

### 14.2. Trạng thái

```text
PENDING → APPROVED
        → REJECTED
        → WITHDRAWN
```

### 14.3. Xử lý

- Khi `PENDING`, booking và tồn không đổi.
- Đối tác đúng tổ chức mới được quyết định.
- `APPROVED`: chuyển waiver sang `APPROVED` và gọi primitive hủy với `PhiHuy = 0` **trong cùng transaction**. Nếu hủy/release/refund-init thất bại, rollback cả quyết định; không được có waiver `APPROVED` nhưng booking chưa `CANCELLED`.
- `REJECTED`: booking tiếp tục `CONFIRMED`.
- `WITHDRAWN`: khách rút trước khi đối tác quyết định.
- Việc quyết định phải có người xử lý, thời điểm, phản hồi và audit.

---

## 15. Đổi ngày

### 15.1. Phạm vi cho phép

Chỉ cho đổi khi:

- booking `CONFIRMED`;
- sản phẩm có `PAY_AT_PROPERTY`;
- payment `NOT_TRACKED`;
- thời điểm xác nhận đổi còn trước cutoff nhận phòng địa phương;
- không có thay đổi cơ sở, loại phòng, sản phẩm, số phòng hoặc phân bổ khách.

Không đổi ngày cho booking đã trả online, `CANCELLED`, `NO_SHOW`, `PAYMENT_FAILED` hoặc `COMPLETED`. Trường hợp ngoài phạm vi phải hủy và đặt lại.

### 15.2. Preview đổi ngày

Preview đổi ngày không được giữ tồn. Vì schema không có bảng preview thay đổi riêng, thiết kế phải chọn một trong hai cách nhưng không được thêm bảng:

1. tạo token có chữ ký, chứa booking, ngày mới, giá/snapshot, hạn ngắn và fingerprint; hoặc
2. chỉ hiển thị ước tính rồi bắt buộc tính lại toàn bộ phía máy chủ khi khách xác nhận.

Token phải chống sửa, gắn đúng khách/booking, có hạn và dùng cùng `RequestID` khi xác nhận. Không tin giá/ngày do trình duyệt gửi lại.

### 15.3. Luồng transaction

1. Khách chọn ngày mới.
2. Hệ thống tính preview thay đổi nhưng không giữ tồn.
3. Khách xem giá mới/chính sách mới và xác nhận với `RequestID` duy nhất.
4. Transaction khóa booking, rồi lập hợp của các dòng tồn cũ và mới theo `LoaiPhongID, NgayLuuTru`.
5. Khóa hợp đó theo thứ tự cố định và kiểm tra giá, restriction, sức chứa, timing và tồn mới.
6. Với các đêm giao nhau giữa khoảng cũ/mới, giữ nguyên ledger; với đêm chỉ có ở khoảng cũ, release; với đêm chỉ có ở khoảng mới, acquire.
7. Nếu một hạng mục quay lại đúng ngày đã có dòng ledger `RELEASED` từ lần đổi trước, không chèn dòng mới vì unique `(HangMucDatChoID, NgayLuuTru)`. Phải khóa và tái kích hoạt dòng đó thành `ACTIVE`, đặt lại `GiuLuc/HetHanLuc` thích hợp, xóa `NhaLuc/LyDoNha`, rồi tăng counter đúng một lần.
8. Điều chỉnh counter đúng phần chênh lệch, cập nhật ngày, giá đêm và snapshot.
9. Ghi toàn bộ before/after vào `NhatKyHeThong`.
10. Commit.

Không có bảng điều chỉnh riêng. Nếu bất kỳ bước nào thất bại, rollback toàn bộ; booking cũ, giá cũ và tồn cũ phải nguyên vẹn.

Gửi lại cùng `RequestID`, cùng chủ thể và payload trả kết quả cũ. Cùng ID nhưng khác chủ thể/payload trả `409`, không thực thi lại và không rò dữ liệu.

---

## 16. No-show và hoàn tất lưu trú

### 16.1. No-show

Quy tắc này là **quyết định riêng của MVP**, không khẳng định giống Booking.com:

```text
CONFIRMED
→ tính và chụp phí no-show cho toàn booking
→ release mọi ledger ACTIVE
→ chuyển NO_SHOW
→ ghi audit
```

- Chỉ đối tác đúng tổ chức được đánh dấu.
- Chỉ được làm từ giờ nhận phòng đến hết ngày kế tiếp theo múi giờ cơ sở.
- No-show áp dụng toàn booking, không theo từng phòng.
- Phí no-show và quyền bán lại tồn là hai việc độc lập.
- Sau khi chuyển `NO_SHOW`, booking không được còn ledger `ACTIVE`.
- Với `PAY_AT_PROPERTY`, phí là khoản được đánh giá nhưng nền tảng không coi là đã thu.
- Với `PAY_ONLINE`, nếu `PhiNoShow < ThanhToan.DaThanhToan` thì `NoShowSnapshotJSON` phải giữ số hoàn dự kiến bằng phần chênh lệch. Trong transaction no-show, khóa theo thứ tự booking → payment → ledger → inventory, release tồn và đặt refund `PENDING`; commit trước khi gọi provider. Khi refund thành công chỉ cập nhật idempotent `ThanhToan.DaHoan` và metadata refund. Không cập nhật `DatCho.SoTienHoan` vì trường đó chỉ dành cho `CANCELLED` theo CHECK hiện tại.
- Nếu phí no-show bằng số đã thu thì không có refund; nếu trả tại cơ sở thì không tạo refund.
- Retry không tính phí hoặc trả tồn lần hai.

### 16.2. Hoàn tất

Tác vụ nền chuyển booking `CONFIRMED` đã qua ngày trả sang `COMPLETED`:

- khóa booking;
- release mọi ledger còn `ACTIVE` đúng một lần;
- đặt `HoanThanhLuc`;
- ghi audit;
- chạy lặp an toàn.

Việc hoàn tất không buộc payment `PAY_AT_PROPERTY` đổi khỏi `NOT_TRACKED`.

---

## 17. Đánh giá

### 17.1. Điều kiện tạo

- Người gửi là khách sở hữu booking.
- Booking ở `COMPLETED`.
- Chưa có đánh giá cho booking đó.
- Không quá 90 ngày kể từ ngày trả phòng theo múi giờ cơ sở.

`NO_SHOW` không được đánh giá trong MVP, dù quy tắc công khai của Booking.com rộng hơn.

### 17.2. Nội dung

- Điểm tổng bắt buộc từ 1 đến 10.
- Điểm thành phần tùy chọn từ 1 đến 10: sạch sẽ, vị trí, nhân viên, thoải mái, tiện nghi, đáng tiền.
- Bình luận tùy chọn nhưng phải qua kiểm tra độ dài/nội dung.
- Mỗi booking tối đa một đánh giá.
- Chỉ đánh giá `PUBLISHED` được tính điểm công khai.

### 17.3. Kiểm duyệt

Quản trị viên được chuyển `PUBLISHED ↔ HIDDEN`, không sửa nội dung thay khách. Lý do và người thao tác ghi audit. Ẩn đánh giá phải làm cập nhật điểm tổng hợp hiển thị ở lần truy vấn sau.

---

## 18. Vòng đời trạng thái

### 18.1. Preview

```text
VALID → USED
VALID → INVALIDATED
```

`VALID` có `HetHanLuc <= now` được xem là hết hạn dù không có mã trạng thái `EXPIRED` trong bảng.

### 18.2. Booking

```text
PENDING_PAYMENT → CONFIRMED → COMPLETED
       │             ├──────→ CANCELLED
       └────────────→ PAYMENT_FAILED
                     └──────→ NO_SHOW
```

Diễn giải chính xác:

- `PENDING_PAYMENT`: đã giữ tồn có hạn, chưa được coi là xác nhận. Khách không hủy trực tiếp ở trạng thái này; bỏ thanh toán dẫn tới timeout.
- `CONFIRMED`: booking hợp lệ và đang giữ các đêm chưa release.
- `PAYMENT_FAILED`: thanh toán online không thành công/hết hạn, trạng thái kết thúc.
- `CANCELLED`: đã hủy và chốt phí; hoàn tiền có thể vẫn đang chờ/thất bại.
- `NO_SHOW`: khách không đến, đã chốt phí và release tồn.
- `COMPLETED`: đã qua kỳ lưu trú và kết thúc.

`PAYMENT_FAILED`, `CANCELLED`, `NO_SHOW`, `COMPLETED` không được có ledger `ACTIVE`. Kết quả payment đến muộn không được chuyển ngược `PAYMENT_FAILED` sang `CONFIRMED`.

### 18.3. Thanh toán

```text
PAY_AT_PROPERTY: NOT_TRACKED

PAY_ONLINE: PROCESSING → PAID
                   └──→ FAILED

Hoàn tiền: NONE → PENDING → SUCCEEDED
                         └→ FAILED
```

`NOT_TRACKED` nghĩa là nền tảng không theo dõi giao dịch tại cơ sở, không có nghĩa khách chắc chắn chưa trả. `CANCELLED` và trạng thái refund là hai trục riêng.

### 18.4. Cơ sở, miễn phí hủy và đánh giá

```text
Cơ sở: DRAFT → PENDING_REVIEW → ACTIVE ↔ SUSPENDED
                        ├─────→ DRAFT (cần sửa)
                        └─────→ REJECTED

Miễn phí hủy: PENDING → APPROVED/REJECTED/WITHDRAWN

Đánh giá: PUBLISHED ↔ HIDDEN
```

---

## 19. Bất biến bắt buộc

1. Một cơ sở thuộc đúng một tổ chức; mọi quyền đối tác phải đi qua tổ chức.
2. Mỗi tài khoản đối tác thuộc tối đa một tổ chức trong MVP.
3. Loại phòng thuộc cơ sở; chính sách thuộc cơ sở; sản phẩm chỉ nối loại phòng và chính sách của cùng cơ sở.
4. Booking thuộc đúng một cơ sở và mọi hạng mục phải thuộc cơ sở đó.
5. Ngày trả sau ngày nhận; một booking không quá 30 đêm.
6. Mỗi hạng mục đại diện một phòng, có ít nhất một người lớn và không vượt sức chứa.
7. Tuổi/số khách không được tự bỏ, tự đổi hoặc tự phân bổ khác yêu cầu đã xác nhận.
8. Mọi sản phẩm trong booking phải cùng `ThoiDiemThanhToan` với `DatCho` và `ThanhToan`.
9. `PAY_ONLINE` phải trả toàn bộ ngay; `PAY_AT_PROPERTY` phải trả toàn bộ tại cơ sở; không split.
10. Tồn quản lý theo `LoaiPhongID + NgayLuuTru`, dùng chung cho mọi sản phẩm cùng loại phòng.
11. `0 <= SoLuongDaGiu <= TongSoLuong`.
12. Bộ đếm đã giữ bằng số ledger `ACTIVE` cùng loại phòng/ngày.
13. Chỉ `PENDING_PAYMENT` và `CONFIRMED` được có ledger `ACTIVE`.
14. Mọi trạng thái kết thúc, kể cả `NO_SHOW`, phải release toàn bộ ledger đúng một lần.
15. Preview không giữ tồn, hết hạn tối đa 15 phút, dùng một lần và luôn được kiểm tra lại.
16. Booking chỉ được tạo từ preview của đúng khách và đúng fingerprint.
17. `ClientRequestID` duy nhất toàn cục; cùng key chỉ trả kết quả cũ khi cùng chủ thể và payload.
18. Một preview tạo tối đa một booking.
19. Một booking có đúng một `ThanhToan`.
20. Retry payment dùng cùng aggregate/idempotency, không tạo payment mới.
21. Không gọi provider khi đang giữ khóa inventory.
22. Payment thành công đến muộn không được hồi sinh booking `PAYMENT_FAILED`.
23. `PAY_AT_PROPERTY` luôn `NOT_TRACKED`; phí hủy/no-show là khoản đánh giá, không phải bằng chứng đã thu.
24. Hủy có idempotency key, người/lúc/lý do/phí/refund dự kiến/snapshot; retry không lặp side effect.
25. `DatCho.SoTienHoan` và `ThanhToan.DaHoan` chỉ phản ánh tiền hoàn thành công.
26. Waiver `PENDING` không đổi booking hoặc tồn.
27. Waiver chỉ thành `APPROVED` trong cùng transaction làm booking `CANCELLED`; không có trạng thái nửa chừng.
28. Khách chỉ sửa, hủy, xin miễn phí hủy hoặc đổi ngày trước cutoff nhận phòng địa phương.
29. Đổi ngày là tất cả hoặc không; thất bại phải giữ nguyên booking cũ.
30. Preview đổi ngày không giữ tồn và không tin dữ liệu giá từ trình duyệt.
31. Đổi ngày quay lại một đêm cũ phải tái kích hoạt ledger `RELEASED`, không chèn trùng.
32. Booking lịch sử dùng snapshot, không phụ thuộc giá/chính sách hiện tại.
33. Tổng booking bằng tổng giá đêm; tổng preview bằng đúng một trong hai timing thuần nhất.
34. Hoàn tiền không vượt số nền tảng đã thu.
35. Refund no-show online chỉ cập nhật `ThanhToan.DaHoan`; `DatCho.SoTienHoan` giữ 0 vì booking không `CANCELLED`.
36. Review đúng khách, đúng booking `COMPLETED`, trong 90 ngày, tối đa một lần.
37. `REJECTED` không dùng cho yêu cầu sửa; trả về sửa phải là `DRAFT`.
38. Cơ sở `SUSPENDED` hoặc thuộc tổ chức `TAM_KHOA` không xuất hiện trong search/preview/booking mới, nhưng booking lịch sử vẫn tồn tại và được xử lý trong phạm vi an toàn.
39. Cập nhật giá/tồn cạnh tranh với booking phải dùng version/khóa cùng thứ tự, không ghi đè hold vừa tạo.
40. JSON phải đúng schema ứng dụng; JSON không thay thế tồn, nightly price hoặc payment aggregate.
41. Không lưu PAN, CVV, mật khẩu thô hoặc bí mật provider.
42. Metadata Google không được biến thành master data sai nguồn/quyền lưu.

---

## 20. Xử lý lỗi nghiệp vụ

| Tình huống | Kết quả bắt buộc |
|---|---|
| Đầu vào ngày/khách không hợp lệ | Từ chối trước khi truy vấn nặng; trả lỗi theo từng trường. |
| Không tìm được tổ hợp phòng | Trả danh sách rỗng/gợi ý sửa nhu cầu; không tự thay phân bổ. |
| Allocation trộn timing | Từ chối preview và yêu cầu chọn sản phẩm cùng timing. |
| Giá đổi sau search | Preview hiển thị giá mới và yêu cầu xác nhận. |
| Giá/sản phẩm đổi sau preview | Không tạo booking; làm mất hiệu lực preview và yêu cầu tạo lại. |
| Preview hết hạn/đã dùng/sai khách | Từ chối; không giữ tồn. |
| Hết phòng ở lúc tạo booking | Rollback và báo vừa hết chỗ; không tạo booking rỗng. |
| Gửi lại create cùng ID, cùng payload/chủ thể | Trả booking cũ. |
| Cùng ID nhưng khác payload/chủ thể | `409` chung, không rò booking hiện hữu. |
| Thanh toán online thất bại | `PAYMENT_FAILED`, trả tồn một lần. |
| Thành công payment đến sau timeout | Không hồi sinh booking; void/hoàn mô phỏng idempotent và audit. |
| Kết quả provider chưa chắc chắn | Không đoán; giữ trạng thái đối soát được, retry idempotent. |
| Gửi lại hủy/đổi ngày đúng payload | Trả kết quả cũ theo idempotency/request ID. |
| Refund thất bại | Booking vẫn `CANCELLED`; refund `FAILED` và cho phép retry an toàn. |
| Đối tác truy cập cơ sở khác tổ chức | Từ chối như không có quyền, không rò rỉ dữ liệu. |
| Dữ liệu JSON sai schema | Từ chối ghi; không để lỗi lan sang transaction booking. |

---

## 21. Báo cáo

### 21.1. Báo cáo đối tác

- booking theo ngày/tháng/trạng thái;
- tổng **giá trị đặt chỗ** và giá bình quân đã đặt, không gọi mặc định là doanh thu;
- giá trị đã thu online do nền tảng thực sự theo dõi;
- phí hủy/no-show đã đánh giá cho `PAY_AT_PROPERTY`, trình bày riêng và không gọi là đã thu;
- tỷ lệ hủy/no-show;
- room-night đã đặt theo loại phòng;
- tỷ lệ bán trên tồn mở bán;
- điểm đánh giá trung bình và số đánh giá công bố;
- số lần khuyến mãi được áp dụng.

### 21.2. Nguồn và công thức

- Room-night tương lai đang giữ có thể lấy từ ledger `ACTIVE`.
- Số liệu lịch sử lấy từ `HangMucDatCho`, `GiaDemDatCho` và trạng thái booking; không dựa vào ledger đã release để làm mất lịch sử.
- Giá trị đặt chỗ lấy từ snapshot lịch sử, không lấy giá hiện tại.
- Mẫu số tỷ lệ bán là tổng `TongSoLuong` mà đối tác đã mở bán cho các ngày/loại phòng trong phạm vi báo cáo.
- Báo cáo phải ghi rõ lọc trạng thái nào; ví dụ booked room-night có thể loại `CANCELLED`/`PAYMENT_FAILED` và tách `NO_SHOW` tùy mục đích.

### 21.3. Báo cáo quản trị

- toàn bộ các báo cáo trên theo tổ chức/cơ sở;
- cơ sở theo trạng thái duyệt;
- lỗi thanh toán cuối cùng;
- yêu cầu miễn phí hủy và thời gian xử lý;
- chênh lệch counter–ledger nếu có;
- thao tác nhạy cảm từ `NhatKyHeThong`.

### 21.4. Cách gọi đúng

Không gọi `Booked / offered inventory` là “tỷ lệ lấp đầy khách sạn”. Tên đúng trong dự án là:

```text
Tỷ lệ bán trên tồn mở bán
= room-night đã đặt theo định nghĩa báo cáo
  ÷ room-night đối tác đã mở bán trên nền tảng
```

Mẫu số không đại diện toàn bộ công suất vật lý của khách sạn. Tổng giá trị booking cũng không tự động là doanh thu của nền tảng hay doanh thu đã thu của cơ sở.

---

## 22. Dữ liệu địa điểm và Google

### 22.1. Nguồn dữ liệu ban đầu

`huongDan/data/CoSoLuuTruDuLich.xlsx` là danh sách nguồn dùng chọn tên cơ sở tại TP.HCM. Tệp gốc không được mặc định là có địa chỉ/tọa độ chính thức.

Bộ dữ liệu hiện hành dùng 30 cơ sở đã chọn để làm fixture. Thông tin phòng, giá, tồn, giao dịch, đánh giá và nhiều thuộc tính mô tả là dữ liệu tổng hợp phục vụ học tập, không phải lời chào bán thật.

Ảnh có nguồn từ Wikimedia Commons về Hotel Majestic Saigon chỉ được gắn cho đúng cơ sở `CS016`. Các cơ sở khác và loại phòng không có ảnh nguồn phù hợp phải dùng ảnh minh họa trung tính do dự án tạo, không được dùng ảnh Majestic để khiến người đọc hiểu nhầm đó là ảnh thật của cơ sở. Đường dẫn ảnh seed là đường dẫn tương đối dưới `public/assets/images/accommodation/`; ảnh minh họa phải có văn bản thay thế bắt đầu bằng `Ảnh minh họa` và giao diện công khai phải thể hiện rõ nhãn này.

Tác giả, giấy phép, trang nguồn, đường dẫn tải và thay đổi kỹ thuật của ảnh bên ngoài được lưu trong `huongDan/majestic_hotel_image_pack_v2/MANIFEST.csv` và `ATTRIBUTION.md`. Tệp khóa tài sản ghi SHA-256 của file đã tải; validator phải phát hiện ảnh thiếu, ảnh bị thay đổi hoặc ảnh ngoài phạm vi ánh xạ. Metadata giấy phép không thay thế nghĩa vụ hiển thị ghi công khi công bố sản phẩm.

### 22.2. Provenance bắt buộc trên cơ sở

- `TenTrongNguon`, `NguonTen`, `MaBanGhiNguon`: tên và dòng gốc;
- `DiaChi`, `NguonDiaChi`, `DiaChiXacMinhLuc`: địa chỉ hiển thị, nguồn và lần xác minh;
- `TrangThaiDoiChieu`, `DuongDanBanDo`: trạng thái/đường dẫn hỗ trợ đối chiếu;
- `GooglePlaceID`, `GoogleMatchStatus`, `GoogleVerifiedAt`: metadata đối chiếu Google nếu có;
- `ViDo`, `KinhDo`, `NguonToaDo`, `ToaDoXacMinhLuc`: tọa độ cùng nguồn được phép lưu.

Tên từ nguồn mở và địa chỉ dùng để đối chiếu là hai nguồn khác nhau; không được trình bày như cùng một master data.

### 22.3. Vai trò của Google Places

Google chỉ là công cụ làm giàu/xác minh, không phải nguồn quyết định nghiệp vụ booking:

- không có bảng cache Google trong schema lõi;
- chỉ lưu kết quả đối chiếu đã chọn ở mức metadata cần thiết;
- danh sách ứng viên/tải đáp ứng tạm thời để ngoài cơ sở dữ liệu nghiệp vụ;
- không sao chép lâu dài dữ liệu Google nếu điều khoản không cho phép;
- trước khi công khai, đối tác vẫn phải xác nhận hồ sơ;
- chưa dùng Google để xếp khoảng cách, ghim bản đồ hoặc chỉ đường trong MVP.

`GoogleMatchStatus`:

- `NOT_CHECKED`: chưa kiểm chứng, không có place ID/thời điểm;
- `MATCHED`: có place ID và thời điểm kiểm chứng;
- `AMBIGUOUS`: có nhiều khả năng, không lưu place ID như kết quả chắc chắn;
- `NOT_FOUND`: không tìm thấy kết quả đủ tin cậy.

## 23. An toàn, riêng tư và kiểm soát truy cập

- Mật khẩu dùng `password_hash()`/`password_verify()`, không lưu mật khẩu thô.
- Dùng PDO prepared statement; cột sort/filter phải theo danh sách cho phép.
- Mọi thao tác ghi từ trình duyệt có CSRF protection.
- Cookie phiên dùng `HttpOnly`, `Secure` khi chạy HTTPS và `SameSite` phù hợp.
- Kiểm tra vai trò và tổ chức ở máy chủ; không tin ID ẩn từ giao diện.
- Mã booking công khai và token phải khó đoán; preview chỉ lưu hash token.
- Không trả email/số điện thoại/danh sách khách của booking trong search công khai.
- Nhật ký không chứa mật khẩu, token thô, PAN, CVV hoặc bí mật provider.
- Ảnh kiểm tra MIME, kích thước, phần mở rộng; đổi tên ngẫu nhiên và lưu ngoài vùng thực thi.
- JSON có schema/version, giới hạn kích thước và danh sách trường cho phép.
- Tiền dùng `DECIMAL`, timestamp giao dịch lưu UTC; ngày lưu trú và deadline hiển thị theo `Asia/Ho_Chi_Minh`/múi giờ cơ sở.
- Dữ liệu booking, review, audit có phân trang.
- Có quy trình sao lưu/khôi phục và không đưa dữ liệu nhạy cảm thật vào repository.
- Lỗi idempotency giữa hai chủ thể phải trả thông báo chung, không xác nhận mã booking đó có tồn tại.

---

## 24. Nhật ký bắt buộc

Tối thiểu ghi audit cho:

- tạo/khóa tài khoản và thay đổi thành viên tổ chức;
- gửi duyệt, trả về sửa, duyệt, từ chối, tạm ngừng/mở lại cơ sở;
- thay đổi giá/tồn số lượng lớn;
- tạo booking;
- kết quả/retry/late callback payment;
- hủy, waiver, refund;
- đổi ngày;
- no-show, hoàn tất;
- ẩn/hiện đánh giá;
- đối soát/sửa sai tồn bởi tác vụ quản trị.

Audit phải có người thực hiện nếu có, hành động, loại/ID đối tượng, `RequestID` khi cần idempotency, thời gian, địa chỉ IP nếu phù hợp và dữ liệu trước/sau đã loại bí mật.

---

## 25. Ngoài phạm vi MVP

- chatbot, RAG, gợi ý bằng AI hoặc máy học;
- microservice, Redis, Kafka, queue, Elasticsearch;
- chuyến bay, taxi, xe thuê, vé tham quan;
- cơ sở ngoài Thành phố Hồ Chí Minh;
- nhiều tiền tệ;
- quản lý phòng vật lý/PMS/lễ tân/dọn phòng;
- loyalty, hạng thành viên, Genius;
- hoa hồng, hóa đơn, quyết toán đối tác;
- cổng thanh toán thật, lưu thẻ, bảo lãnh thẻ, trả online sau, chia nhiều đợt;
- booking trộn nhiều payment timing hoặc split payment;
- nhiều lần hoàn tiền một phần;
- email, SMS, push, notification center;
- nhắn tin khách–cơ sở;
- map runtime, xếp theo khoảng cách, tuyến đường;
- hủy/sửa riêng một phòng trong booking nhiều phòng;
- đổi cơ sở/sản phẩm trong booking hiện hữu;
- chính sách trẻ em/giường phụ/giá theo tuổi phức tạp;
- chính sách hủy nhiều tầng như hệ thống production;
- sao chép giao diện, nội dung hoặc thuật toán nội bộ của Booking.com.

---

## 26. Dữ liệu khởi tạo, nhập dữ liệu và kiểm tra

### 26.1. Quy mô fixture mục tiêu

- 30 cơ sở tại TP.HCM;
- 10 tổ chức đối tác;
- 120 loại phòng;
- 180 sản phẩm;
- 21.600 dòng tồn;
- 32.400 dòng giá;
- 500 khách hàng;
- 2.000 booking;
- 2.500 hạng mục booking.

Dữ liệu phải có cả trường hợp nhiều phòng, trẻ em, khuyến mãi, hủy, no-show, payment thất bại, refund và review để kiểm thử được nghiệp vụ, không chỉ có dữ liệu đẹp.

### 26.2. Thứ tự nhập

```text
database/migrations/001_create_schema.sql
database/seeds/001_reference.sql
database/seeds/002_accounts_partners.sql
database/seeds/003_properties.sql
database/seeds/004_products_policies.sql
database/seeds/005_inventory_prices.sql
database/seeds/006_operational.sql
database/validation/001_integrity_checks.sql
```

### 26.3. Điều kiện bộ dữ liệu đạt

- Import được trên MySQL 8 hoặc MariaDB 10.4 theo môi trường dự án.
- Có đúng 22 bảng.
- Không lỗi khóa ngoại/CHECK/unique.
- Dữ liệu sinh xác định được, có manifest và mã SHA-256 nếu dùng bộ sinh.
- Mọi kiểm tra invariant trong validator trả 0 vi phạm.
- Validator V2 phải phát hiện booking trộn timing, split payment và `PAY_ONLINE` có `ThuePhiTaiCoSo > 0`.
- Fixture hiện hành được công nhận tương thích V2 khi generator, manifest và validator payment timing cùng đạt; mọi lần tái sinh sau phải giữ các kiểm tra này bằng 0.
- `EXPLAIN` cho search, danh sách booking và báo cáo chính dùng index hợp lý với fixture mục tiêu.
- Không gọi dữ liệu tổng hợp là dữ liệu vận hành thật.

---

## 27. Tiêu chí chấp nhận

### 27.1. Tài khoản, đối tác và cơ sở

1. Khách chưa đăng nhập tìm/xem được nhưng không tạo preview lưu máy chủ.
2. Tài khoản đối tác không có membership không quản lý được cơ sở.
3. Đối tác không đọc/sửa được cơ sở hoặc booking của tổ chức khác.
4. Cơ sở thiếu readiness không gửi duyệt được.
5. Trả về sửa chuyển `PENDING_REVIEW → DRAFT`; không tồn tại `NEEDS_CHANGES`.
6. Từ chối dứt điểm dùng `REJECTED` và có người/thời điểm duyệt.
7. Chỉ cơ sở `ACTIVE` thuộc tổ chức `HOAT_DONG` xuất hiện công khai; `SUSPENDED` hoặc tổ chức `TAM_KHOA` không nhận booking mới nhưng giữ được lịch sử.

### 27.2. Search, chi tiết và preview

8. Search kiểm tra đúng ngày, số phòng, người lớn, tuổi trẻ em và giới hạn.
9. Kết quả chỉ có cơ sở có ít nhất một phân bổ hợp lệ ở mọi đêm.
10. Không âm thầm bỏ/đổi khách khi phân bổ không phù hợp.
11. Khi không lọc timing, thẻ search hiển thị tổ hợp thuần timing rẻ nhất toàn cục cùng badge timing; detail mặc định đúng timing/lựa chọn đó và giữ nguyên giá khi dữ liệu không đổi.
12. Không allocation/preview/booking nào được trộn `PAY_ONLINE` và `PAY_AT_PROPERTY`; allocator cho cùng input phải chọn kết quả và tie-break xác định.
13. Kết quả search cũ không được dùng làm quyết định cuối.
14. Preview đọc lại giá, restriction, khuyến mãi, sức chứa, timing và tồn.
15. Preview hiển thị tổng, một timing thuần nhất, chính sách hủy/no-show và cảnh báo thay đổi.
16. Preview hết hạn tối đa 15 phút, dùng một lần, không tăng counter và không tạo ledger.
17. Preview hết hạn/đã dùng/sai khách/sai fingerprint không tạo booking.

### 27.3. Booking, tồn và lịch sử

18. Booking nhiều phòng chỉ thuộc một cơ sở và tạo một item cho mỗi phòng.
19. Booking giữ đủ mọi phòng/mọi đêm hoặc rollback toàn bộ.
20. Hai request tranh unit cuối chỉ một request thành công.
21. `ClientRequestID` unique toàn cục; cùng key/chủ thể/payload trả booking cũ.
22. Cùng idempotency key nhưng khác chủ thể/payload trả `409` và không rò dữ liệu.
23. Counter không âm/không vượt tổng và bằng số ledger `ACTIVE`.
24. Chỉ `PENDING_PAYMENT`/`CONFIRMED` có ledger `ACTIVE`.
25. Giá, khách, sản phẩm và chính sách lịch sử không đổi khi đối tác sửa cấu hình hiện tại.

### 27.4. Thanh toán

26. `PAY_AT_PROPERTY` tạo booking `CONFIRMED`, payment `NOT_TRACKED`, `DaThanhToan = 0`.
27. Booking trả tại cơ sở có thể `COMPLETED` mà payment vẫn `NOT_TRACKED`.
28. Phí hủy/no-show trả tại cơ sở chỉ được ghi nhận, không bị coi là đã thu.
29. `PAY_ONLINE` có `SoTienTraNgay = TongTien`, `SoTienTraTaiCoSo = 0` và không có phí tại cơ sở.
30. Online chỉ hiển thị xác nhận thành công sau cả `PAID` và `CONFIRMED`.
31. Provider không được gọi trong lúc giữ khóa inventory.
32. Retry payment không tạo booking/payment trùng.
33. Payment thất bại/hết hạn trả tồn đúng một lần.
34. Late success sau `PAYMENT_FAILED` không hồi sinh booking; được void/hoàn mô phỏng và audit.

### 27.5. Hủy, waiver, đổi ngày và no-show

35. Phí hủy sau booking dùng snapshot lịch sử và đúng múi giờ.
36. Hủy lưu đủ idempotency key, người, lúc, lý do, phí, refund dự kiến và snapshot.
37. `SoTienHoan`/`DaHoan` chỉ tăng sau refund `SUCCEEDED`.
38. Retry hủy không release tồn/hoàn tiền lần hai.
39. Refund không vượt số đã thu; refund thất bại không đảo booking về xác nhận.
40. `CANCELLED` không bị hiểu nhầm là refund đã hoàn tất.
41. Waiver `PENDING` không thay đổi booking/tồn.
42. Chỉ đối tác đúng tổ chức xử lý waiver/no-show.
43. Preview đổi ngày không giữ tồn, có hạn/chữ ký hoặc được tái tính phía máy chủ.
44. Đổi ngày có overlap giữ đêm giao nhau, release old-only, acquire new-only trong một transaction.
45. Đổi ngày thất bại giữ nguyên booking, nightly price, ledger và counter cũ.
46. Retry đổi ngày với cùng `RequestID`/chủ thể/payload không thực thi lần hai.
47. `NO_SHOW` áp dụng toàn booking, chụp phí và tự release mọi ledger `ACTIVE`.
48. `NO_SHOW` không vi phạm invariant chỉ booking đang hoạt động mới giữ tồn.
49. Tác vụ hoàn tất chạy lặp không release hai lần.

### 27.6. Đánh giá, dữ liệu và báo cáo

50. Chỉ booking `COMPLETED`, đúng khách và trong 90 ngày được đánh giá.
51. Mỗi booking tối đa một đánh giá; `NO_SHOW` không được đánh giá.
52. Chỉ đánh giá `PUBLISHED` được tính điểm công khai.
53. Dữ liệu Google giữ đúng provenance và không trở thành master data không rõ quyền lưu.
54. Báo cáo gọi đúng là “tỷ lệ bán trên tồn mở bán”, không phải occupancy toàn khách sạn.
55. Báo cáo tách giá trị đặt chỗ, tiền đã thu online và phí tại cơ sở chỉ được đánh giá.
56. Schema có đúng 22 bảng; không use case nào tham chiếu bảng ngoài danh sách.
57. Import/seed/validator V2 chạy được và mọi kiểm tra invariant bằng 0.
58. Fixture không còn `PAY_ONLINE` với `ThuePhiTaiCoSo > 0`.
59. `EXPLAIN` phù hợp fixture mục tiêu.
60. DESCRIPTION và DESIGN dùng cùng trạng thái, bảng, transaction và giới hạn phạm vi.

---

## 28. Ma trận quyết định quan trọng

| Mã | Vấn đề | Quyết định cuối |
|---|---|---|
| QD-01 | Phạm vi | Chỉ lưu trú tại TP.HCM, VND. |
| QD-02 | Schema | Chính xác 22 bảng, không thêm bảng trong đặc tả V2. |
| QD-03 | Đăng nhập | Search/detail công khai; đăng nhập trước preview/booking. |
| QD-04 | Search và detail | Search hiển thị một tổ hợp thuần timing rẻ nhất cùng badge; detail mặc định đúng lựa chọn đó, có toggle timing và gọi lại availability. |
| QD-05 | Preview | 15 phút, một lần, không giữ tồn, phải revalidate. |
| QD-06 | Tồn | Counter + ledger theo loại phòng/ngày, khóa trong transaction. |
| QD-07 | Nhiều phòng | Một cơ sở/booking, một item/phòng. |
| QD-08 | Payment timing | Chỉ `PAY_AT_PROPERTY` hoặc `PAY_ONLINE`, thuần nhất toàn booking; một lời gọi allocator giải tối đa hai nhánh timing độc lập. |
| QD-09 | Split payment | Không hỗ trợ; pay online toàn bộ hoặc pay at property toàn bộ. |
| QD-10 | Thu tại cơ sở | Không theo dõi; phí hủy/no-show chỉ assessed, không coi là collected. |
| QD-11 | Refund | Snapshot giữ expected; `SoTienHoan`/`DaHoan` chỉ là tiền đã hoàn thành công. |
| QD-12 | Hủy | Metadata trên `DatCho`, audit trong `NhatKyHeThong`. |
| QD-13 | No-show | Toàn booking, tự release toàn bộ hold trong cùng transaction. |
| QD-14 | Đổi ngày | Chỉ booking trả tại cơ sở; all-or-nothing, xử lý đúng overlap. |
| QD-15 | Cơ sở cần sửa | Trả về `DRAFT`, không có `NEEDS_CHANGES`. |
| QD-16 | Từ chối cơ sở | `REJECTED` là từ chối dứt điểm trong luồng thường. |
| QD-17 | Idempotency booking | `ClientRequestID` unique toàn cục, so cả chủ thể và payload. |
| QD-18 | Payment late success | Không hồi sinh booking đã thất bại. |
| QD-19 | Review | `COMPLETED`, 90 ngày, một review; không cho `NO_SHOW`. |
| QD-20 | Google | Chỉ enrichment/verification; metadata trực tiếp trên cơ sở. |
| QD-21 | Báo cáo tồn | Tỷ lệ bán trên tồn mở bán, không gọi occupancy khách sạn. |

---

## 29. Nguồn tham khảo chính thức

Các nguồn dưới đây được kiểm tra ngày 09/10/2026. Chúng chứng minh hành vi công khai, không chứng minh kiến trúc nội bộ.

### 29.1. Luồng tìm – xem – đặt

- [Booking.com Demand API – tìm cơ sở còn chỗ](https://developers.booking.com/demand/docs/accommodations/search-for-available-properties)
- [Booking.com Demand API – luồng Search, Look, Book](https://developers.booking.com/demand/docs/development-guide/application-flows/search-look-book)
- [Booking.com Demand API – preview và tạo đơn](https://developers.booking.com/demand/docs/orders-api/order-preview-create)
- [Booking.com Demand API – vòng đời Orders](https://developers.booking.com/demand/docs/orders-api/overview)

### 29.2. Hủy, sửa và thanh toán

- [Booking.com Demand API – chính sách hủy](https://developers.booking.com/demand/docs/orders-api/cancellation-policies)
- [Booking.com Demand API – hủy đơn](https://developers.booking.com/demand/docs/open-api/3.2/demand-api/orders/orders/cancel)
- [Booking.com Demand API – sửa đơn](https://developers.booking.com/demand/docs/orders-api/order-modify)
- [Booking.com Demand API – tổng quan thanh toán](https://developers.booking.com/demand/docs/payments/overview)
- [Booking.com Demand API – thời điểm thanh toán](https://developers.booking.com/demand/docs/payments/payments-timings)
- [Booking.com Demand API – cách thanh toán](https://developers.booking.com/demand/docs/payments/payments-methods)
- [Booking.com Demand API – yêu cầu hiển thị thanh toán](https://developers.booking.com/demand/docs/payments/payments-display)

### 29.3. Nội dung, khách và đánh giá

- [Booking.com Demand API – chính sách trẻ em](https://developers.booking.com/demand/docs/accommodations/child-policies)
- [Booking.com – trung tâm trợ giúp khách hàng](https://www.booking.com/customer-service.html)
- [Booking.com – hướng dẫn và tiêu chuẩn đánh giá](https://www.booking.com/reviews_guidelines.html)
- [Booking.com – điều khoản công khai](https://www.booking.com/content/terms.html)

### 29.4. Đối tác và tồn mở bán

- [Booking.com Connectivity – giá và khả năng bán](https://developers.booking.com/connectivity/docs/ari)
- [Booking.com Connectivity – loại phòng và gói giá](https://developers.booking.com/connectivity/docs/room-type-and-rate-plan-management/understanding-room-types-and-rate-plans)
- [Booking.com Connectivity – tổng quan tiếp nhận cơ sở](https://developers.booking.com/connectivity/docs/tutorial-property-onboarding/intro)
- [Booking.com Connectivity – tổng quan đặt chỗ phía đối tác](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview)
- [Booking.com Connectivity – báo cáo khách không đến](https://developers.booking.com/connectivity/docs/reporting-api/b_xml-reporting)

### 29.5. Google

- [Google Maps Platform – Place ID](https://developers.google.com/maps/documentation/places/web-service/place-id)
- [Google Maps Platform – điều khoản dịch vụ](https://cloud.google.com/maps-platform/terms/maps-service-terms)

---

## 30. Tài liệu liên quan

- Thiết kế kỹ thuật tương ứng: `huongDan/DESIGN_HOTEL_BOOKING_V2.md`
- Sơ đồ luồng dữ liệu: `huongDan/DFD_HOTEL_BOOKING.md`
- Sơ đồ tuần tự: `huongDan/SEQUENCE_DIAGRAM_HOTEL_BOOKING.md`
- Migration chính thức: `database/migrations/001_create_schema.sql`
- Bộ kiểm tra toàn vẹn: `database/validation/001_integrity_checks.sql`

Nếu triển khai phiên bản 2 và có khác biệt giữa tài liệu với code hiện hữu, không tự đoán. Phải đối chiếu DESCRIPTION, DESIGN, migration và validator; sửa đồng bộ để các bất biến tại mục 19 vẫn đúng.
