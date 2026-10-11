# THIẾT KẾ KỸ THUẬT MINI OTA LƯU TRÚ NHIỀU CƠ SỞ

**Phiên bản:** 2.2  
**Ngày chốt:** 11/10/2026  
**Tài liệu nghiệp vụ tương ứng:** `DESCRIPTION_HOTEL_BOOKING_V2.md`  
**Nền tảng mục tiêu:** PHP 8.2+, MySQL 8.x hoặc MariaDB 10.4+, HTML/CSS/JavaScript  
**Phạm vi triển khai:** cơ sở lưu trú tại Thành phố Hồ Chí Minh, một tiền tệ VND

---

## 1. Mục đích và vị trí của tài liệu

Tài liệu này là thiết kế kỹ thuật độc lập để nhóm có thể xây dựng mini OTA mà không phải suy đoán transaction, quyền dữ liệu hay ý nghĩa của từng bảng. Hệ thống tham khảo các hành trình công khai của Booking.com ở mức tìm kiếm, xem lựa chọn, xem trước, xác nhận, quản lý đặt chỗ và vận hành đối tác. Đây **không** phải mô tả kiến trúc nội bộ của Booking.com.

Các quyết định kỹ thuật dưới đây là quyết định của dự án:

- nguyên khối chia module, một ứng dụng PHP và một cơ sở dữ liệu;
- chính xác 22 bảng nghiệp vụ;
- tìm kiếm có thể đọc dữ liệu vừa thay đổi, nhưng xem trước và tạo đặt chỗ phải kiểm tra lại;
- xem trước không giữ tồn;
- tồn được giữ ở cấp `LoaiPhong + NgayLuuTru`, có cả bộ đếm và sổ giữ tồn;
- đặt nhiều phòng được phép nhưng mọi phòng phải cùng một cơ sở và cùng khoảng ngày;
- mọi sản phẩm trong một booking phải có cùng `ThoiDiemThanhToan`; không trộn trả tại cơ sở với trả trực tuyến;
- chỉ có `PAY_AT_PROPERTY` và `PAY_ONLINE`; thanh toán trực tuyến là mô phỏng;
- không chatbot/RAG, học máy gợi ý, microservice, Redis, hàng đợi, Elasticsearch, PMS, phòng vật lý hoặc nhiều tiền tệ.

Nếu tài liệu này khác migration đang chạy, phải dừng merge, đối chiếu với `DESCRIPTION_HOTEL_BOOKING_V2.md`, rồi sửa cả migration, validator và tài liệu trong cùng thay đổi. Không tự chọn một nguồn để bỏ qua nguồn còn lại.

### 1.1. Từ ngữ dùng trong tài liệu

| Từ ngắn trong code/tài liệu | Cách hiểu tiếng Việt |
|---|---|
| property | cơ sở lưu trú |
| room type | loại phòng, không phải phòng vật lý |
| product | sản phẩm/cách bán một loại phòng |
| availability | tình trạng còn chỗ |
| inventory | tồn phòng mở bán trên nền tảng |
| allocation | phân bổ nhóm khách vào loại phòng/sản phẩm |
| preview | bản xem trước giá và điều kiện trước xác nhận |
| booking | đặt chỗ |
| hold/ledger | lần giữ tồn/sổ chi tiết giữ tồn theo đêm |
| payment provider | cổng thanh toán mô phỏng |
| tenant scope | phạm vi dữ liệu của một tổ chức đối tác |
| idempotency | lặp an toàn: gửi lại cùng yêu cầu không tạo tác dụng lần hai |
| fingerprint | dấu vân tay/hash của dữ liệu đã dùng để tính bản xem trước |

Tên enum, lớp, trường và mã lỗi vẫn giữ nguyên tiếng Anh vì chúng là contract máy đọc; phần giải thích ưu tiên tiếng Việt.

---

## 2. Cơ sở khảo sát và giới hạn suy luận

### 2.1. Những điểm công khai được dùng làm tham chiếu

| Điều đã xác thực từ tài liệu công khai | Ảnh hưởng tới thiết kế mini OTA |
|---|---|
| Tìm kiếm nhận địa điểm, ngày, số phòng và phân bổ khách; kết quả ban đầu có thể chỉ đưa ra lựa chọn phù hợp/rẻ nhất của mỗi cơ sở | Tách `SearchService` khỏi trang chi tiết; kết quả tìm kiếm chỉ là tóm tắt, không phải báo giá cuối |
| Giá và tình trạng phòng không phải dữ liệu tĩnh | Search không được dùng làm quyết định cuối; preview và booking đều tính lại |
| Bước xem trước đưa ra tổng giá, khoản trả trực tuyến/tại cơ sở, chính sách hủy và cảnh báo phân bổ khách | Dùng một `PriceBreakdown` và snapshot máy chủ trong `XemTruocDatCho` |
| Token tạo đơn công khai có thời hạn 15 phút | Preview của dự án hết hạn sau 15 phút, nhưng không giữ tồn |
| Phương thức, thời điểm và lịch thanh toán là ba khái niệm khác nhau | MVP cố ý rút còn hai thời điểm; không giả vờ hỗ trợ trả sau trực tuyến, chia đợt hay bảo lãnh thẻ |
| Sửa ngày phải kiểm tra lại giá và tình trạng phòng | Đổi ngày dùng preview mới và transaction all-or-nothing |
| Chính sách hủy trước xác nhận và điều kiện hiện tại sau đặt chỗ phải được trình bày rõ | Booking giữ snapshot giá/chính sách; mọi phí hủy/no-show tính từ snapshot |

Nguồn chính thức dùng để đối chiếu:

- [Tìm cơ sở còn chỗ](https://developers.booking.com/demand/docs/accommodations/search-for-available-properties)
- [Luồng tìm kiếm – xem – đặt](https://developers.booking.com/demand/docs/development-guide/application-flows/search-look-book)
- [Xem trước và tạo đơn](https://developers.booking.com/demand/docs/orders-api/order-preview-create)
- [Vòng đời đơn](https://developers.booking.com/demand/docs/orders-api/overview)
- [Chính sách hủy](https://developers.booking.com/demand/docs/orders-api/cancellation-policies)
- [Thời điểm thanh toán](https://developers.booking.com/demand/docs/payments/payments-timings)
- [Hiển thị thanh toán](https://developers.booking.com/demand/docs/payments/payments-display)
- [Sửa đơn](https://developers.booking.com/demand/docs/orders-api/order-modify)
- [Quản lý loại phòng và gói giá phía đối tác](https://developers.booking.com/connectivity/docs/room-type-and-rate-plan-management/understanding-room-types-and-rate-plans)
- [Quản lý giá và tồn](https://developers.booking.com/connectivity/docs/ari)
- [Tiếp nhận cơ sở phía đối tác](https://developers.booking.com/connectivity/docs/tutorial-property-onboarding/intro)
- [Vòng đời đặt chỗ phía đối tác](https://developers.booking.com/connectivity/docs/reservations-api/reservations-overview)
- [Báo cáo khách không đến](https://developers.booking.com/connectivity/docs/reporting-api/b_xml-reporting)
- [Nguyên tắc đánh giá](https://www.booking.com/reviews_guidelines.html)

Trang người dùng Booking.com được kết xuất động và có thể khác theo thị trường, thiết bị, đăng nhập hoặc thử nghiệm giao diện. Vì vậy tài liệu không khẳng định thứ tự màn hình, thuật toán xếp hạng hay kiến trúc nội bộ của họ. Nguồn trên chỉ giúp xác thực khái niệm nghiệp vụ công khai.

### 2.2. Những khác biệt có chủ ý

- Dự án bắt buộc đăng nhập trước khi tạo preview; không hỗ trợ đặt chỗ khách vãng lai.
- Trẻ em được quy ước 0–17 tuổi và luôn chiếm sức chứa; không có chính sách tuổi riêng theo từng cơ sở.
- Không nhận/lưu dữ liệu thẻ. `PAY_ONLINE` gọi cổng mô phỏng bằng token kỹ thuật, còn `PAY_AT_PROPERTY` không theo dõi tiền mặt thực tế.
- Không có `PAY_ONLINE_LATER`, lịch thu nhiều đợt, đặt cọc, bảo lãnh thẻ, thanh toán nhiều phương thức hoặc nhiều tiền tệ.
- Đổi ngày chỉ áp dụng cho `CONFIRMED + PAY_AT_PROPERTY`; không đổi loại phòng/sản phẩm và không sửa một phần booking.
- Đánh giá chỉ dành cho booking `COMPLETED` trong 90 ngày; quy tắc này chặt hơn luồng công khai cho phép một số khách đã đến nhưng không lưu trú.

---

## 3. Kiến trúc tổng thể

### 3.1. Kiểu kiến trúc

Hệ thống là **nguyên khối chia module** (modular monolith): một tiến trình PHP, một database, một bộ route. Module là ranh giới tổ chức code và quyền ghi dữ liệu, không phải dịch vụ mạng.

```text
HTTP Request
    ↓
Router → Session/CSRF/Auth/Role middleware
    ↓
Controller → Request validator → Application service
                                      ↓
                              Domain policy/service
                                      ↓
                         Repository / external adapter
                                      ↓
                                PDO / MySQL
```

Quy tắc phụ thuộc:

1. Controller không gọi PDO và không tự chuyển trạng thái.
2. Repository không chứa quyết định nghiệp vụ; nó tải/khóa/lưu aggregate theo yêu cầu application service.
3. Application service sở hữu transaction của use case ghi.
4. Module không ghi trực tiếp bảng do module khác sở hữu; gọi contract công khai của module đó.
5. Search và báo cáo được dùng truy vấn đọc tối ưu nhưng không thay đổi nghiệp vụ.
6. Thời điểm kỹ thuật lưu UTC; `DATE` lưu trú và cửa sổ nghiệp vụ được tính theo `Asia/Ho_Chi_Minh` hoặc `MuiGio` của cơ sở.
7. Mọi tiền được truyền trong PHP dưới dạng chuỗi số thập phân/đối tượng `Money`; không dùng `float`. Schema dùng `DECIMAL(14,2)`, nhưng VND được làm tròn đến một đồng và ghi phần lẻ `.00`.

### 3.2. Cây thư mục mục tiêu

```text
public/
├── index.php
└── assets/
    ├── css/
    ├── js/
    └── images/

src/
├── Bootstrap/
├── Config/
├── Http/
│   └── Middleware/
├── Modules/
│   ├── Identity/
│   ├── Properties/
│   ├── Commercial/
│   ├── Inventory/
│   ├── Search/
│   ├── Bookings/
│   ├── Reviews/
│   └── Admin/
├── Shared/
│   ├── Clock/
│   ├── Csv/
│   ├── Database/
│   ├── Http/
│   ├── Money/
│   ├── Security/
│   └── Storage/
└── routes/
    ├── web.php
    ├── api.php
    └── console.php

views/
├── layouts/
├── components/
└── modules/
    ├── customer/
    ├── partner/
    └── admin/

database/
├── migrations/
├── seeds/
├── source/
├── fixtures/
└── validation/

scripts/data/
storage/{uploads,logs,tmp,cache}/
tests/{Unit,Integration,Concurrency,Fixtures}/
huongDan/
```

Mỗi module dùng khung sau khi cần; không bắt buộc tạo thư mục rỗng:

```text
src/Modules/<Module>/
├── Domain/          # value object, state rule, policy
├── Application/     # use case, DTO, transaction boundary
├── Infrastructure/  # PDO repository, adapter
├── Http/            # controller, request mapping
└── README.md         # ownership và contract công khai
```

### 3.3. Module và quyền sở hữu bảng

| Module | Bảng sở hữu | Số | Trách nhiệm |
|---|---|---:|---|
| Identity | `NguoiDung`, `ToChucDoiTac`, `ThanhVienDoiTac` | 3 | danh tính, vai trò, tư cách thành viên và ranh giới tổ chức |
| Properties | `TienNghi`, `CoSoLuuTru`, `LoaiPhong`, `CoSoTienNghi`, `LoaiPhongTienNghi` | 5 | hồ sơ cơ sở, duyệt, loại phòng, ảnh, tiện nghi, nguồn dữ liệu |
| Commercial | `ChinhSachHuy`, `SanPhamPhong`, `GiaPhongNgay`, `KhuyenMai` | 4 | chính sách, sản phẩm, giá/restriction ngày, khuyến mại |
| Inventory | `TonPhongNgay`, `GiuTonPhongDem` | 2 | tồn mở bán, khóa dòng, giữ và trả tồn |
| Search | không sở hữu bảng | 0 | tìm kiếm, kiểm tra còn chỗ, phân bổ phòng, tính giá đọc |
| Bookings | `XemTruocDatCho`, `DatCho`, `HangMucDatCho`, `GiaDemDatCho`, `ThanhToan`, `YeuCauHuyMienPhi` | 6 | preview, booking, snapshot, thanh toán mô phỏng, hủy/sửa/no-show |
| Reviews | `DanhGia` | 1 | đánh giá đã xác minh bằng booking |
| Admin | `NhatKyHeThong` | 1 | audit, hỗ trợ quản trị và truy vấn báo cáo |
| **Tổng** | **22 bảng** | **22** | |

`GiuTonPhongDem` thuộc Inventory nhưng tham chiếu `HangMucDatCho`. Bookings chỉ giữ/trả tồn qua `InventoryReservationService` trong cùng transaction, không tự tăng/giảm counter bằng câu lệnh rời rạc.

---

## 4. Mô hình dữ liệu chuẩn — đúng 22 bảng

Migration chuẩn là `database/migrations/001_create_schema.sql`. Danh sách sau phải khớp đúng tên và ý nghĩa, không có bảng ẩn do framework tự tạo.

### 4.1. Identity — bảng 1 đến 3

#### 1. `NguoiDung`

Trường quan trọng: `NguoiDungID`, `MaNguoiDung`, `HoTen`, `Email`, `SoDienThoai`, `MatKhauHash`, `VaiTro`, `TrangThai`, `TaoLuc`, `CapNhatLuc`.

- `MaNguoiDung` và `Email` duy nhất.
- `VaiTro ∈ {CUSTOMER, PARTNER, ADMIN}`.
- `TrangThai ∈ {HOAT_DONG, KHOA}`.
- Không lưu mật khẩu thô; dùng `password_hash()` và `password_verify()`.

#### 2. `ToChucDoiTac`

Trường: ID, mã/tên tổ chức, email/điện thoại liên hệ, `TrangThai`, `TaoLuc`. Mã tổ chức duy nhất; trạng thái `HOAT_DONG/TAM_KHOA`.

#### 3. `ThanhVienDoiTac`

Khóa chính `(ToChucDoiTacID, NguoiDungID)`, có `LaChuSoHuu`, `TaoLuc`. Unique `NguoiDungID` làm cho một tài khoản đối tác thuộc tối đa một tổ chức trong MVP.

Mọi truy vấn của đối tác phải nhận `ToChucDoiTacID` từ session server, không nhận tổ chức tin cậy từ body/query string.

### 4.2. Properties — bảng 4 đến 8

#### 4. `TienNghi`

Mã/tên tiện nghi, `PhamVi ∈ {PROPERTY, ROOM}`, `TrangThai ∈ {HOAT_DONG, NGUNG}`. Bảng độc lập để lọc many-to-many bằng SQL.

#### 5. `CoSoLuuTru`

Các nhóm trường chuẩn:

- định danh và chủ sở hữu: `CoSoLuuTruID`, `MaCoSo`, `ToChucDoiTacID`, `Slug`;
- phân loại/phạm vi: `MaThanhPho='HCM'`, `LoaiCoSo`;
- nội dung: `TenCoSo`, `DiaChi`, `MoTa`, `AnhJSON`, giờ nhận/trả, múi giờ;
- nguồn tên: `TenTrongNguon`, `NguonTen`, `MaBanGhiNguon`;
- nguồn địa chỉ: `NguonDiaChi`, `DiaChiXacMinhLuc`;
- đối chiếu Google: `GooglePlaceID`, `GoogleMatchStatus`, `GoogleVerifiedAt`;
- tọa độ lâu dài: `ViDo`, `KinhDo`, `NguonToaDo`, `ToaDoXacMinhLuc`, `TrangThaiDoiChieu`, `DuongDanBanDo`;
- thương mại: `SoSao`, thuế/phí và cờ đã bao gồm;
- duyệt: `TrangThai`, `NguoiDuyetID`, `DuyetLuc`, `GhiChuDuyet`;
- thời điểm tạo/cập nhật.

Ràng buộc quan trọng:

- `LoaiCoSo ∈ {HOTEL, RESORT, APARTMENT, VILLA, HOSTEL, GUEST_HOUSE}`;
- trạng thái chỉ gồm `DRAFT`, `PENDING_REVIEW`, `ACTIVE`, `REJECTED`, `SUSPENDED`; **không có** `NEEDS_CHANGES`;
- Google match chỉ gồm `NOT_CHECKED`, `MATCHED`, `AMBIGUOUS`, `NOT_FOUND`;
- `MATCHED` mới có `GooglePlaceID`; trạng thái đã kiểm tra phải có `GoogleVerifiedAt`;
- bốn trường tọa độ cùng rỗng hoặc cùng có giá trị; vĩ/kinh độ phải hợp lệ;
- unique mã, slug, cặp nguồn `(NguonTen, MaBanGhiNguon)` và nullable `GooglePlaceID`.

`GooglePlaceID` chỉ là metadata xác minh. Dữ liệu do dự án được phép giữ lâu dài phải có nguồn riêng; không biến nội dung Places thành master data hoặc tạo cache Places trong database.

`AnhJSON` dùng schema ứng dụng:

```json
[
  {"path":"/uploads/properties/CS001/front.webp","alt":"Mặt tiền","sort":1,"cover":true}
]
```

Tối đa 12 ảnh, đường dẫn tương đối, đúng một ảnh bìa nếu mảng không rỗng.

#### 6. `LoaiPhong`

Thuộc một cơ sở; mã duy nhất trong cơ sở. Giữ tên/mô tả, sức chứa tổng/người lớn/trẻ em, `CauHinhGiuongJSON`, diện tích, hút thuốc, ảnh, trạng thái.

```json
[
  {"type":"DOUBLE","quantity":1},
  {"type":"SINGLE","quantity":1}
]
```

Application chỉ nhận loại giường trong allow-list và số lượng nguyên dương. Đây là cấu hình hiển thị, không phải tồn từng giường/phòng vật lý.

#### 7. `CoSoTienNghi`

Khóa kép cơ sở–tiện nghi, có `MienPhi`, `GhiChu`. Service chỉ cho gắn tiện nghi phạm vi `PROPERTY`.

#### 8. `LoaiPhongTienNghi`

Khóa kép loại phòng–tiện nghi, có `SoLuong`, `GhiChu`. Service chỉ cho gắn tiện nghi phạm vi `ROOM`.

### 4.3. Commercial — bảng 9 đến 12

#### 9. `ChinhSachHuy`

Thuộc cơ sở; giữ mã/tên, `LoaiChinhSach`, số giờ hủy miễn phí, kiểu/giá trị phạt, tỷ lệ phạt no-show, trạng thái.

- loại: `FLEXIBLE/NON_REFUNDABLE`;
- kiểu phạt: `NONE/PERCENT/FIRST_NIGHT/FULL_STAY`;
- mọi phần trăm 0–100;
- invariant tầng ứng dụng: `NONE` bắt buộc `GiaTriPhat=0`; `PERCENT` diễn giải `GiaTriPhat` là phần trăm; `FIRST_NIGHT` chuẩn hóa `GiaTriPhat=1`; `FULL_STAY` chuẩn hóa `GiaTriPhat=100`. Hai giá trị sau là mã hóa quy ước cho seed/validator, không phải tỷ lệ; bộ tính phí vẫn đọc trực tiếp snapshot giá;
- UI có thể diễn giải thành miễn phí có hạn, hoàn một phần hoặc không hoàn dựa trên dữ liệu, nhưng không thêm enum/bảng chỉ để gắn nhãn.

#### 10. `SanPhamPhong`

Một cách bán của `LoaiPhong`, gắn đúng một `ChinhSachHuy` **cùng cơ sở**, gồm:

- mã/tên sản phẩm;
- `LoaiBuaAn ∈ {ROOM_ONLY, BREAKFAST_INCLUDED, HALF_BOARD}`;
- `ThoiDiemThanhToan ∈ {PAY_AT_PROPERTY, PAY_ONLINE}`;
- `TrangThai ∈ {ACTIVE, INACTIVE}`.

Sản phẩm không sở hữu inventory. Bữa ăn và thời điểm thanh toán là tập giá trị cố định, không phải danh mục quản trị động.

Do `DatCho` và `ThanhToan` chỉ có một `ThoiDiemThanhToan`, một lựa chọn nhiều phòng chỉ hợp lệ khi tất cả sản phẩm cùng timing. Search/detail có thể hiển thị cả hai nhóm, nhưng allocator phải tạo tổ hợp thuần `PAY_AT_PROPERTY` hoặc thuần `PAY_ONLINE`; preview và booking kiểm tra lại, không tự đổi sản phẩm để làm cho khớp.

#### 11. `GiaPhongNgay`

Khóa chính `(SanPhamPhongID, NgayLuuTru)`. Mỗi dòng chứa `GiaCoBan`, `DongBan`, `SoDemToiThieu`, `SoDemToiDa`, `SoNgayDatTruoc`, `PhienBan`, `CapNhatLuc`.

- mọi đêm phải có dòng giá và `DongBan=0`;
- min/max stay và số ngày đặt trước lấy từ dòng ngày nhận;
- `SoNgayDatTruoc` là **số ngày tối thiểu phải đặt sớm**: hợp lệ khi `DATEDIFF(NgayNhanPhong, ngay_hien_tai_tai_co_so) >= SoNgayDatTruoc`; giá trị 0 cho phép đặt cùng ngày nếu các rule khác cho phép;
- cập nhật giá/điều kiện phải tăng `PhienBan` trong cùng transaction;
- `PhienBan` đi vào fingerprint preview.

#### 12. `KhuyenMai`

Thuộc cơ sở, có phần trăm giảm, cửa sổ đặt, cửa sổ lưu trú và trạng thái. Trong MVP, khuyến mại áp dụng cho mọi sản phẩm đang hoạt động của cơ sở khi đủ điều kiện. Nếu nhiều khuyến mại hợp lệ, chọn phần trăm cao nhất, rồi `KhuyenMaiID` nhỏ nhất để kết quả xác định.

### 4.4. Inventory counter — bảng 13

#### 13. `TonPhongNgay`

Khóa chính `(LoaiPhongID, NgayLuuTru)`; giữ `TongSoLuong`, `SoLuongDaGiu`, `PhienBan`, `CapNhatLuc`.

```text
0 <= SoLuongDaGiu <= TongSoLuong
available = TongSoLuong - SoLuongDaGiu
```

`TongSoLuong` là số phòng cơ sở mở bán trên nền tảng, không nhất thiết bằng tổng phòng vật lý. Giảm tổng không được làm tổng nhỏ hơn số đang giữ. Đóng bán sản phẩm nằm ở `GiaPhongNgay`, không thêm nguồn đóng/mở thứ hai vào inventory.

### 4.5. Booking aggregate và ledger inventory — bảng 14 đến 20

#### 14. `XemTruocDatCho`

Giữ hash token, khách, cơ sở, ngày, VND, ba tổng tiền, `PhanBoJSON`, `Fingerprint`, trạng thái, hạn và thời điểm sử dụng.

- token thô chỉ trả một lần cho client; database lưu SHA-256;
- `VALID/USED/INVALIDATED`; hết hạn được xác định thêm bởi `HetHanLuc`;
- `HetHanLuc = TaoLuc + 15 phút`;
- `TongTien = SoTienTraNgay + SoTienTraTaiCoSo`;
- không chia lịch thanh toán: `PAY_ONLINE` có `SoTienTraNgay=TongTien`, `SoTienTraTaiCoSo=0`; `PAY_AT_PROPERTY` có hai giá trị ngược lại;
- preview không tạo ledger và không tăng counter;
- unique token, một preview chỉ tạo tối đa một booking.

Schema JSON phiên bản 1 được chuẩn hóa ở mục 8.

#### 15. `DatCho`

Trường chính gồm mã công khai, `ClientRequestID`, preview, khách, cơ sở, khoảng ngày, người đặt/liên hệ/yêu cầu, payment timing, VND, tổng tiền, trạng thái và các mốc thời gian.

- `ClientRequestID` là UUID duy nhất **toàn hệ thống**, không phải unique theo khách;
- `XemTruocDatChoID` unique;
- trạng thái: `PENDING_PAYMENT`, `CONFIRMED`, `PAYMENT_FAILED`, `CANCELLED`, `NO_SHOW`, `COMPLETED`;
- metadata hủy nằm trực tiếp trên booking: idempotency key, actor, lúc/lý do, phí, số hoàn, snapshot;
- metadata no-show: actor, lúc, phí, snapshot;
- CHECK buộc metadata chỉ có ở đúng trạng thái.

#### 16. `HangMucDatCho`

Một dòng là một phòng được đặt. Giữ product/room type, thứ tự phòng, snapshot tên phòng/sản phẩm/bữa ăn, `KhachJSON`, tổng hạng mục, snapshot điều kiện và chính sách hủy. Unique `(DatChoID, ThuTuPhong)`.

#### 17. `GiaDemDatCho`

Khóa `(HangMucDatChoID, NgayLuuTru)`; giữ giá gốc, giảm, thuế/phí đã gồm, khoản tại cơ sở, thành tiền, khuyến mại và `SnapshotJSON`. Đây là lịch sử giá bất biến của từng đêm, không tính lại từ bảng giá hiện tại.

Trong mô hình không chia lịch thanh toán, `ThuePhiTaiCoSo` chỉ ghi phần thuế/phí phải trả tại cơ sở: bắt buộc bằng 0 với `PAY_ONLINE`; có thể lớn hơn 0 với `PAY_AT_PROPERTY`. Tổng phải trả tại cơ sở của booking pay-at-property vẫn là toàn bộ `TongTien`, không chỉ riêng cột thuế/phí này.

#### 18. `GiuTonPhongDem`

Bảng này do module Inventory sở hữu nhưng đứng ở vị trí 18 theo thứ tự migration vì phụ thuộc `HangMucDatCho`. Một dòng là một phòng đã giữ trong một đêm. Unique `(HangMucDatChoID, NgayLuuTru)`; giữ loại phòng, trạng thái `ACTIVE/RELEASED`, hạn giữ, thời điểm giữ/trả và lý do trả.

Bất biến trung tâm:

```text
TonPhongNgay.SoLuongDaGiu
= số GiuTonPhongDem ACTIVE cùng LoaiPhongID + NgayLuuTru
```

Chỉ booking `PENDING_PAYMENT` hoặc `CONFIRMED` được có ledger `ACTIVE`. Mọi trạng thái kết thúc phải không còn ledger active.

#### 19. `ThanhToan`

Một dòng duy nhất cho một booking. Giữ timing, trạng thái, tổng/đã trả/đã hoàn, provider, idempotency/reference, số lần thử/lỗi cuối và toàn bộ substate hoàn tiền.

- `PAY_AT_PROPERTY` luôn `NOT_TRACKED`, các trường provider/idempotency/tiền đã trả/hoàn đều rỗng hoặc 0;
- `PAY_ONLINE` dùng `PROCESSING/PAID/FAILED`, provider và idempotency bắt buộc, `SoLanThu > 0`;
- `TongPhaiTra=DatCho.TongTien` cho cả hai timing; với `NOT_TRACKED`, đây chỉ là tổng nghĩa vụ hiển thị chứ không mở vòng đời thu tiền tại cơ sở;
- trạng thái hoàn: `NONE/PENDING/SUCCEEDED/FAILED`;
- unique booking, payment idempotency, refund idempotency và `(Provider, ProviderReference)`;
- không lưu PAN, CVV, ngày hết hạn hoặc dữ liệu thẻ.

#### 20. `YeuCauHuyMienPhi`

Giữ booking, người yêu cầu, lý do, trạng thái, người xử lý, phản hồi và thời điểm. Trạng thái `PENDING/APPROVED/REJECTED/WITHDRAWN`. Tối đa một yêu cầu pending cho mỗi booking được bảo đảm bằng row lock và service, vì schema không có partial unique index tương thích cả hai hệ quản trị.

### 4.6. Reviews và Admin — bảng 21, 22

#### 21. `DanhGia`

Một booking tối đa một đánh giá; giữ cơ sở, khách, điểm tổng 1–10, sáu điểm thành phần tùy chọn, bình luận, `PUBLISHED/HIDDEN` và thời điểm.

#### 22. `NhatKyHeThong`

Audit append-only: actor, hành động, loại/ID đối tượng, `RequestID` nullable unique, JSON trước/sau đã làm sạch, IP và thời điểm. Audit hỗ trợ truy vết/idempotency command không có khóa riêng; không thay thế bảng trạng thái nghiệp vụ.

---

## 5. Mô hình trạng thái

### 5.1. Cơ sở lưu trú

```text
DRAFT ──gửi duyệt──> PENDING_REVIEW ──duyệt──> ACTIVE
  ^                         │
  └────yêu cầu sửa──────────┤
                            └─từ chối──────────> REJECTED (kết thúc)

ACTIVE ──tạm ngừng──> SUSPENDED ──khôi phục──> ACTIVE
ACTIVE/SUSPENDED ──sửa trọng yếu──> PENDING_REVIEW
```

Khi về `DRAFT/PENDING_REVIEW`, phải xóa `NguoiDuyetID` và `DuyetLuc` để thỏa CHECK; actor, thời điểm và lý do trước đó nằm trong audit. `GhiChuDuyet` chứa hướng dẫn hiện tại.

`REJECTED` là quyết định từ chối dứt điểm cho hồ sơ đó trong MVP, không gửi lại qua route thường. “Yêu cầu chỉnh sửa” phải đưa về `DRAFT`, không dùng `REJECTED` thay cho trạng thái chờ sửa. Ngoại lệ duy nhất là route hỗ trợ của quản trị viên mở lại **chính bản ghi đó** về `DRAFT`, ghi audit/lý do và xóa metadata duyệt; không tạo bản ghi thay thế chỉ để né uniqueness của provenance.

Thay đổi trọng yếu gồm chủ sở hữu, tên, loại cơ sở, địa chỉ/nguồn địa chỉ, tọa độ/nguồn tọa độ, thuế/phí và giờ nhận/trả. Chỉnh mô tả, sắp xếp ảnh hoặc tiện nghi không tự làm mất trạng thái active nhưng luôn audit. Giá, tồn, sản phẩm có workflow riêng và không đẩy property về duyệt lại.

Điều kiện “sẵn sàng gửi duyệt”:

- tổ chức sở hữu đang `HOAT_DONG`;
- hồ sơ và provenance bắt buộc hợp lệ;
- ít nhất một ảnh hợp lệ;
- ít nhất một loại phòng active có ảnh/cấu hình giường;
- ít nhất một sản phẩm active gắn policy cùng cơ sở;
- có giá mở bán và tồn hợp lệ cho tối thiểu 30 ngày tới (có thể cấu hình);
- không có lỗi validator thuộc aggregate.

### 5.2. Booking và inventory

```text
PAY_ONLINE:      PENDING_PAYMENT ──trả thành công──> CONFIRMED
                       │                                  │
                       └─thất bại/hết hạn──> PAYMENT_FAILED

PAY_AT_PROPERTY:                    tạo mới ────────> CONFIRMED

CONFIRMED ──hủy──> CANCELLED
CONFIRMED ──không đến──> NO_SHOW
CONFIRMED ──kết thúc kỳ ở──> COMPLETED
```

`PAYMENT_FAILED`, `CANCELLED`, `NO_SHOW`, `COMPLETED` là kết thúc cục bộ và không có ledger `ACTIVE`. Không chuyển từ terminal state sang trạng thái khác bằng route thường; sửa sai phải là thủ tục quản trị có audit, ngoài MVP.

### 5.3. Payment

```text
PAY_AT_PROPERTY: NOT_TRACKED (không chuyển trạng thái)

PAY_ONLINE: PROCESSING ──thành công──> PAID
                  └─────thất bại────> FAILED

Refund: NONE ──yêu cầu──> PENDING ──thành công──> SUCCEEDED
                               └────thất bại────> FAILED ──retry──> PENDING
```

`NOT_TRACKED` nghĩa là nền tảng không quản lý việc thu tại cơ sở, không có nghĩa “khách còn nợ nền tảng”.

---

## 6. Contract giữa các module

### 6.1. DTO chuẩn

```text
StayPeriod(checkIn, checkOut)          # nửa kín [checkIn, checkOut)
RoomParty(roomOrder, adults, childAges[])
SearchCriteria(destination, stayPeriod, parties[], filters, sort, page)
InventoryDemand(roomTypeId, date, quantity)
Money(currency='VND', decimalString)
PriceBreakdown(
  paymentTiming, nights[], subtotal, discountTotal,
  includedTaxFeeTotal, payNowTotal,
  payAtPropertyTotal, grandTotal,
  promotionId?, cancellationSnapshot,
  paymentSnapshot, rateVersions[]
)
RoomAllocation(roomOrder, roomTypeId, productId, paymentTiming, party, priceBreakdown)
AllocationResult(paymentTiming, rooms[], grandTotal, selectionFingerprint)
```

Chỉ có **một** cấu trúc `PriceBreakdown`. Search, trang chi tiết, preview, booking và đổi ngày đều gọi cùng `PriceCalculator`; không viết công thức giá riêng trong controller hoặc JavaScript.

`PriceBreakdown.paymentTiming`, `RoomAllocation.paymentTiming` và `AllocationResult.paymentTiming` phải giống nhau. `selectionFingerprint` là SHA-256 của tiêu chí chuẩn hóa, timing, danh sách `(roomOrder, roomTypeId, productId)`, giá/tổng và các version đã đọc. Nó giúp detail nhận biết lựa chọn từ search còn nguyên hay đã stale; đây không phải credential, không giữ tồn và không thay thế việc kiểm tra lại.

### 6.2. Contract công khai

```php
interface AvailabilityReader {
    public function getMinimumAvailable(array $roomTypeIds, StayPeriod $stay): array;
}

interface RoomAllocator {
    /** @return AllocationResult[] theo giá tăng dần, kết quả xác định */
    public function allocate(
        array $parties,
        array $candidates,
        array $availability,
        ?string $paymentTiming = null
    ): array;
}

interface PriceCalculator {
    public function calculate(Product $product, StayPeriod $stay, RoomParty $party, DateTimeImmutable $bookedAt): PriceBreakdown;
}

interface InventoryReservationService {
    public function lockAndAssertAvailable(array $demands): void;
    public function holdForBooking(Booking $booking, array $demands, ?DateTimeImmutable $expiresAt): void;
    public function releaseActive(Booking $booking, string $reason): void;
}

interface PaymentGateway {
    public function charge(string $idempotencyKey, Money $amount, string $opaqueToken): GatewayResult;
    public function refund(string $idempotencyKey, string $providerReference, Money $amount): GatewayResult;
}
```

`PaymentGateway` là adapter ngoài transaction. Bản mock phải mô phỏng thành công, thất bại, timeout và retry bằng cùng idempotency key.

### 6.3. Phân bổ phòng xác định

MVP nhận tối đa 5 nhóm/phòng. Mỗi nhóm không được bị tách giữa hai phòng. `RoomAllocator::allocate()` là một lời gọi công khai và thực hiện:

1. chuẩn hóa thứ tự nhóm theo `roomOrder` và snapshot availability dùng chung;
2. lọc loại phòng đủ tổng khách, người lớn, trẻ em, giá và restriction cho toàn kỳ;
3. nếu có filter timing, chỉ tạo một partition tương ứng; nếu không, chia ứng viên thành tối đa hai partition `PAY_AT_PROPERTY` và `PAY_ONLINE`;
4. chạy backtracking độc lập trong từng partition; khi gán một nhóm, trừ tồn tạm của loại phòng trên toàn kỳ **trong partition đó**;
5. thử ứng viên theo `grandTotal`, rồi `roomTypeId`, `productId` tăng dần;
6. chọn tổ hợp tốt nhất của từng partition. Không trừ tồn giữa hai partition vì chúng là phương án thay thế, chưa được đặt đồng thời;
7. gộp tối đa hai `AllocationResult` rồi sort theo `grandTotal`, timing (`PAY_AT_PROPERTY` trước khi hòa giá), cuối cùng chuỗi `(roomTypeId, productId)` theo `roomOrder`;
8. kết quả đầu là lựa chọn đại diện. Nếu một partition không có tổ hợp thì tự nhiên dùng partition còn lại; không triển khai fallback tuần tự phụ thuộc thứ tự thử;
9. không tự bỏ trẻ em, đổi tuổi hay chuyển khách âm thầm. Nếu cả hai partition không phân bổ được, trả lỗi/cảnh báo cụ thể.

Giới hạn nhỏ làm hai nhánh backtracking dễ giải thích và kiểm thử. Cùng input và cùng snapshot phải trả đúng cùng thứ tự kết quả. Không cần bộ tối ưu tổng quát.

### 6.4. Quy tắc tính và làm tròn tiền

`PriceCalculator` tính theo từng phòng, từng đêm: giá gốc → giảm khuyến mại → thuế/phí theo snapshot cấu hình → thành tiền đêm. Mỗi thành phần tiền được làm tròn VND bằng `PHP_ROUND_HALF_UP` đến một đồng **trước** khi cộng lên đêm, hạng mục và booking; không làm tròn lại từ một số `float` tổng hợp.

```text
itemTotal  = SUM(night.ThanhTien)
grandTotal = SUM(itemTotal)

PAY_ONLINE:
  payNowTotal       = grandTotal
  payAtPropertyTotal = 0

PAY_AT_PROPERTY:
  payNowTotal        = 0
  payAtPropertyTotal = grandTotal
```

Snapshot từng đêm lưu input, tỷ lệ, cờ đã bao gồm, giá trị sau làm tròn và promotion đã chọn để có thể giải thích lại kết quả. `GiaDemDatCho`, `HangMucDatCho`, `DatCho`, preview và `ThanhToan.TongPhaiTra` phải cộng khớp theo quy tắc này.

---

## 7. Route và kiểm soát truy cập

### 7.1. Công khai và khách hàng

```text
GET  /search
GET  /properties/{slug}
GET  /properties/{slug}/availability

POST /auth/register
POST /auth/login
POST /auth/logout

POST /previews
GET  /checkout
POST /bookings
GET  /account/bookings
GET  /account/bookings/{code}
POST /account/bookings/{code}/cancel-preview
POST /account/bookings/{code}/cancel
POST /account/bookings/{code}/waiver
POST /account/bookings/{code}/waiver/withdraw
POST /account/bookings/{code}/guest-details
POST /account/bookings/{code}/date-change-preview
POST /account/bookings/{code}/date-change
POST /account/bookings/{code}/review
```

Search và chi tiết property là công khai. Preview, booking, quản lý và review yêu cầu `CUSTOMER`. Mã booking không thay thế authorization: luôn kiểm tra `KhachHangID` bằng session.

### 7.2. Đối tác

```text
GET|POST       /partner/properties
GET|POST|PATCH /partner/properties/{id}
POST           /partner/properties/{id}/submit-review
GET|POST|PATCH /partner/properties/{id}/rooms
GET|POST|PATCH /partner/properties/{id}/products
GET|POST       /partner/properties/{id}/calendar
GET|POST|PATCH /partner/properties/{id}/promotions
GET            /partner/bookings
GET            /partner/bookings/{code}
POST           /partner/bookings/{code}/no-show
GET|POST        /partner/cancellation-requests
POST           /partner/cancellation-requests/{id}/approve
POST           /partner/cancellation-requests/{id}/reject
GET            /partner/reports
```

Mỗi repository query bắt buộc join/where `CoSoLuuTru.ToChucDoiTacID = :sessionOrganizationId`. Không “tải trước rồi kiểm tra sau” vì có thể làm lộ dữ liệu trong log/DTO.

### 7.3. Quản trị

```text
GET|POST /admin/properties/review
POST     /admin/properties/{id}/approve
POST     /admin/properties/{id}/request-changes
POST     /admin/properties/{id}/reject
POST     /admin/properties/{id}/suspend
POST     /admin/properties/{id}/restore
GET|POST /admin/accounts
GET|POST /admin/facilities
GET|POST /admin/reviews
GET      /admin/bookings
GET      /admin/audit
GET      /admin/reports
```

Mọi mutation dùng POST/PATCH/DELETE, CSRF và kiểm tra quyền tại server. Các route minh họa có thể đổi cú pháp router nhưng không được đổi semantics/idempotency.

---

## 8. Tìm kiếm, chi tiết, xem trước

### 8.1. Search không phải availability cuối

Đầu vào:

```json
{
  "destination":"TP.HCM hoặc từ khóa",
  "checkIn":"2026-12-10",
  "checkOut":"2026-12-12",
  "rooms":[
    {"roomOrder":1,"adults":2,"childAges":[7]}
  ],
  "filters":{},
  "sort":"PRICE_ASC",
  "page":1
}
```

Pipeline:

1. chuẩn hóa ngày, từ khóa, nhóm khách, filter/sort allow-list;
2. lọc property `ACTIVE`, `HCM`, tổ chức sở hữu `HOAT_DONG` và điều kiện cơ sở/tiện nghi;
3. lấy room type, product, policy đang active;
4. yêu cầu đủ dòng `GiaPhongNgay` và `DongBan=0` cho mọi đêm;
5. áp dụng min/max stay và advance days từ ngày nhận;
6. tính tồn nhỏ nhất của room type trên mọi đêm;
7. gọi allocator một lần; allocator giải một partition nếu có filter timing, nếu không giải tối đa hai partition thuần timing;
8. với mỗi property chọn `AllocationResult` đầu tiên làm lựa chọn đại diện và trả `paymentTiming`, badge, rooms, `PriceBreakdown`, `selectionFingerprint`;
9. áp dụng khoảng giá và sort giá lên chính `grandTotal` của lựa chọn đại diện đang hiển thị; không dùng giá/timing ẩn khác;
10. sắp xếp ổn định, phân trang; ghi request ID/metric, không ghi audit nghiệp vụ.

Search không `FOR UPDATE`. Dòng “còn 1 phòng” chỉ phản ánh thời điểm đọc.

Không có filter timing nghĩa là card dùng tổ hợp thuần timing rẻ nhất toàn cục. Tie-break là `grandTotal → PAY_AT_PROPERTY → roomTypeId/productId theo roomOrder`. Search không ghép sản phẩm rẻ nhất riêng lẻ từ hai timing.

### 8.2. Trang chi tiết và availability

Trang chi tiết tải nội dung, ảnh, tiện nghi, room types, review summary. Endpoint availability chạy lại theo cùng criteria và vẫn là dữ liệu tham khảo, không giữ tồn.

Contract tối thiểu:

```text
GET /properties/{slug}/availability
  ?checkIn=...
  &checkOut=...
  &rooms=...
  &paymentTiming=PAY_AT_PROPERTY|PAY_ONLINE
  &selected=1:3:10,2:7:21
  &selectionFingerprint=...  # tùy chọn, từ card search
```

- `selected` mang ánh xạ `thuTuPhong:LoaiPhongID:SanPhamPhongID` đã chọn ở bước trước. Đây chỉ là dữ liệu đầu vào không đáng tin cậy; endpoint phải kiểm tra lại quyền mở bán, sức chứa, hạn chế ngày, tồn và giá.
- Khi khách đi từ search, `paymentTiming` mặc định bằng timing của card và request mang lựa chọn/fingerprint của card để đối chiếu.
- Endpoint trả mọi lựa chọn hợp lệ của **một timing đang chọn**, không trả tổ hợp hỗn hợp. Response có thể kèm summary `available/minPrice` của timing còn lại để dựng toggle, nhưng danh sách phòng chính chỉ thuộc timing hiện tại.
- Khi khách đổi toggle, client gọi lại endpoint với timing mới; không lọc lại một response cũ ở JavaScript.
- Nếu không có timing từ search/deep link, server gọi cùng allocator và dùng lựa chọn đại diện theo quy tắc mục 8.1.
- Nếu fingerprint và version đầu vào còn khớp, allocation/price đầu tiên phải trùng card search. Nếu stale, response đặt `selectionChanged=true`, trả lựa chọn/giá hiện tại và lý do thay đổi; không âm thầm gắn timing khác.
- Sort/badge/giá ở card, detail và preview đều lấy từ cùng `PriceBreakdown`, không tự tính lại ở controller hoặc giao diện.

### 8.3. Preview 15 phút

`POST /previews` nhận allocation mà người dùng đã chọn cùng tiêu chí gốc. Server không tin giá, policy hoặc tổng do client gửi.

Trình tự:

1. xác thực customer, payload và timing đã chọn;
2. tải property/product/rate/inventory hiện tại;
3. kiểm tra lại sức chứa, restriction, availability và phân bổ;
4. gọi `PriceCalculator` cho từng phòng;
5. kiểm tra `RoomAllocation.paymentTiming`, `PriceBreakdown.paymentTiming`, product và timing cấp request đều giống nhau; nếu trộn thì trả `MIXED_PAYMENT_TIMING` và không tạo preview; chỉ khi hợp lệ mới tạo `PhanBoJSON` phiên bản 1 và fingerprint;
6. sinh token ngẫu nhiên tối thiểu 256 bit, lưu SHA-256; bản web giữ token thô trong session để mở `/checkout`, bản API gửi token trong body/header chứ không đưa vào URL;
7. lưu preview `VALID`, hết hạn sau 15 phút; **không** chạm inventory.

Schema tối thiểu:

```json
{
  "version":1,
  "criteria":{"checkIn":"...","checkOut":"..."},
  "paymentTiming":"PAY_AT_PROPERTY",
  "rooms":[{
    "roomOrder":1,
    "roomTypeId":3,
    "productId":10,
    "party":{"adults":2,"childAges":[7]},
    "nightPrices":[],
    "cancellationSnapshot":{},
    "paymentSnapshot":{},
    "priceBreakdown":{}
  }],
  "warnings":[]
}
```

Fingerprint là SHA-256 của biểu diễn JSON canonical gồm property, ngày, allocation, payment timing thống nhất, product/policy ID, rate version từng đêm, inventory version đọc được, cấu hình thuế/phí và promotion đã chọn. Inventory version trong fingerprint chỉ giúp báo stale; booking vẫn phải khóa và kiểm tra số lượng thực.

Checkout phải hiển thị: cơ sở/phòng/sản phẩm, ngày và phân bổ khách, bữa ăn, giá từng đêm, giảm giá, thuế/phí đã gồm, khoản trả ngay, khoản trả tại cơ sở, tổng, chính sách hủy/no-show và mọi cảnh báo. `null` không được diễn giải thành 0.

Token preview không xuất hiện trong path/query string để tránh browser history, access log và `Referer`. Trang checkout đặt `Referrer-Policy: no-referrer`, không nạp tài nguyên bên thứ ba và log middleware phải che mọi credential/token trong body/header.

---

## 9. Transaction tạo booking

### 9.1. Quy tắc khóa

Mọi danh sách inventory phải sort `(LoaiPhongID ASC, NgayLuuTru ASC)` trước `SELECT ... FOR UPDATE`. Với command trên booking đã tồn tại, thứ tự khóa thống nhất là `DatCho → ThanhToan` (nếu cần) `→ GiuTonPhongDem → TonPhongNgay` đã sort. Quy tắc này áp dụng cho hủy, đổi ngày, payment finalize/expiry, no-show và hoàn tất để giảm deadlock. Tạo booking chưa có dòng `DatCho`, nên khóa preview, đọc/khóa chia sẻ dữ liệu thương mại rồi mới khóa inventory.

`ClientRequestID` là UUID do client sinh cho một lần xác nhận và dùng lại khi retry. Unique toàn hệ thống. Nếu insert gặp duplicate, service chỉ trả kết quả trước khi owner và fingerprint payload đều khớp. Cùng key nhưng khác payload hoặc owner trả `409 IDEMPOTENCY_CONFLICT` chung, không trả mã booking, email hay dữ liệu của bản ghi đã tồn tại.

### 9.2. Transaction chung

```text
BEGIN
  1. SELECT XemTruocDatCho BY TokenHash FOR UPDATE
  2. kiểm tra owner, VALID, chưa hết hạn, chưa dùng
3. kiểm tra ClientRequestID chưa gắn payload khác
4. kiểm tra property ACTIVE và tổ chức sở hữu HOAT_DONG; tải lại product/policy/rate hiện tại;
   khóa chia sẻ/version-check rate cần dùng;
     kiểm tra cùng payment timing; tính lại fingerprint và PriceBreakdown
  5. tổng hợp demand theo LoaiPhongID + NgayLuuTru
  6. SELECT TonPhongNgay FOR UPDATE theo thứ tự chuẩn
  7. kiểm tra đủ mọi dòng, available >= demand
  8. INSERT DatCho
  9. INSERT HangMucDatCho và GiaDemDatCho từ snapshot máy chủ
 10. INSERT GiuTonPhongDem ACTIVE, UPDATE counter cùng transaction
 11. INSERT đúng một ThanhToan
 12. UPDATE preview USED + DaDungLuc
 13. INSERT NhatKyHeThong
COMMIT
```

Bất kỳ bước nào lỗi đều rollback toàn bộ. Không cho booking “thiếu một phòng” hoặc “thiếu một đêm”. Đọc thương mại hiện tại và đối chiếu `PhienBan`/fingerprint diễn ra trước khi khóa tồn; rate rows cần dùng được giữ ổn định bằng shared lock hoặc optimistic version check lần cuối. Không gọi cổng thanh toán trong transaction.

### 9.3. Trả tại cơ sở

- tạo `DatCho.CONFIRMED`, `XacNhanLuc=now`;
- ledger `ACTIVE`, `HetHanLuc=NULL`;
- preview/snapshot có `SoTienTraNgay=0`, `SoTienTraTaiCoSo=TongTien`;
- tạo `ThanhToan(PAY_AT_PROPERTY, NOT_TRACKED, TongPhaiTra=DatCho.TongTien, DaThanhToan=0, DaHoan=0)`; `TongPhaiTra` phục vụ hiển thị/đối chiếu, không có nghĩa nền tảng theo dõi thu tiền;
- hệ thống không cập nhật tiền mặt/thẻ mà cơ sở tự thu.

### 9.4. Thanh toán trực tuyến mô phỏng

Trong transaction tạo:

- booking `PENDING_PAYMENT`, hạn 10 phút;
- ledger `ACTIVE` có cùng hạn;
- preview/snapshot có `SoTienTraNgay=TongTien`, `SoTienTraTaiCoSo=0`;
- payment `PROCESSING`, provider mock, idempotency key ổn định, `SoLanThu=1`, `TongPhaiTra=DatCho.TongTien`.

Sau commit mới gọi adapter. Khi có kết quả, mở transaction ngắn:

```text
lock DatCho → ThanhToan → active ledgers (theo ID) [→ inventory nếu phải release]
```

- nếu thành công và booking vẫn `PENDING_PAYMENT`, chưa hết hạn, ledger còn đủ: payment `PAID`, ghi reference/amount/time; booking `CONFIRMED`, xóa hạn;
- nếu thất bại: payment `FAILED`, booking `PAYMENT_FAILED`, release tất cả ledger/counter đúng một lần;
- nếu timeout: giữ `PROCESSING` đến tác vụ đối soát/hết hạn; không tự gọi bằng key mới;
- nếu kết quả thành công đến sau khi booking đã `PAYMENT_FAILED` và hold đã release: **không xác nhận lại booking**. Theo schema hiện tại, giữ `DatCho=PAYMENT_FAILED`, giữ `ThanhToan.TrangThai=FAILED`, nhưng ghi `DaThanhToan`, provider reference và refund `PENDING`; gọi void/refund capture ở mock adapter ngoài inventory lock, rồi cập nhật kết quả trong transaction ngắn. Nếu provider chưa cho phép hoàn tự động, chỉ audit sự cố để admin xử lý; không có “cờ hỗ trợ” giả định ngoài schema;
- callback/retry luôn đọc trạng thái trước khi ghi, tăng `SoLanThu` khi thực sự gọi lại và không insert payment thứ hai.

Tác vụ hết hạn khóa booking trước. Nếu vẫn `PENDING_PAYMENT` và chưa có kết quả thành công, nó chuyển `PAYMENT_FAILED` và release đúng một lần. Race callback–expiry chỉ được tạo một kết quả terminal hợp lệ.

Chỉ trả màn hình xác nhận thành công cho online sau khi cả `ThanhToan=PAID` và `DatCho=CONFIRMED`. `PENDING_PAYMENT` không được hủy trực tiếp như booking đã xác nhận; khách có thể rời luồng và để tác vụ hết hạn/abandon giải phóng hold.

---

## 10. Hủy, hoàn tiền, miễn phí hủy, đổi ngày

### 10.1. Tính phí hủy

`CancellationCalculator` chỉ đọc `SnapshotHuyJSON`, ngày nhận, thời điểm hiện tại theo múi giờ cơ sở và giá đêm snapshot. Không đọc policy hiện tại để sửa lịch sử.

Quy tắc trên từng `HangMucDatCho`:

- `FLEXIBLE`: miễn phí khi thời điểm hủy còn **trước** `localCheckIn - SoGioHuyMienPhi`; từ đúng mốc giới hạn trở đi áp dụng `LoaiPhat`. Nếu số giờ rỗng thì không có cửa sổ miễn phí;
- `NON_REFUNDABLE`: áp dụng phạt ngay sau khi booking được xác nhận;
- `NONE`: 0 và `GiaTriPhat` phải bằng 0;
- `PERCENT`: làm tròn `itemTotal * GiaTriPhat / 100`;
- `FIRST_NIGHT`: yêu cầu giá trị chuẩn hóa `GiaTriPhat=1`, rồi lấy `ThanhTien` của đêm đầu trong snapshot item;
- `FULL_STAY`: yêu cầu giá trị chuẩn hóa `GiaTriPhat=100`, rồi lấy toàn bộ `itemTotal`;
- chỉ nhánh `PERCENT` dùng `GiaTriPhat` làm phần trăm.

`PhiHuy = min(DatCho.TongTien, SUM(itemFee))`. Mỗi item fee được làm tròn VND trước khi cộng. Mốc đúng bằng deadline được xem là đã hết miễn phí và phải có test biên.

`cancel-preview` trả:

```text
calculatedAt, validUntil ngắn
fee
expectedRefund = max(0, paidAmount - fee)
policyExplanation
```

Khi người dùng xác nhận, server tính lại; nếu phí thay đổi, trả `409 CANCELLATION_QUOTE_CHANGED` để xác nhận lại.

### 10.2. Hủy trực tiếp và hoàn tiền

Hủy trực tiếp chỉ nhận booking `CONFIRMED` và phải thực hiện trước thời điểm nhận phòng cục bộ `NgayNhanPhong + GioNhanPhong` trong `MuiGio` cơ sở. `PENDING_PAYMENT` đi theo luồng thất bại/hết hạn thanh toán, không dùng cancellation policy của kỳ ở. Vì MVP không có `CHECKED_IN`, không cho khách hủy giữa kỳ ở.

```text
BEGIN
  lock booking
  kiểm tra owner/state/idempotency
  khóa payment và active ledger
  khóa inventory liên quan theo thứ tự chuẩn
  tính lại fee từ snapshot
  set CANCELLED + actor/time/reason/fee/snapshot/idempotency
  release active ledger và giảm counter đúng một lần
  nếu online đã trả và cần hoàn: set refund PENDING, ghi expected refund trong snapshot;
    DatCho.SoTienHoan=0 và ThanhToan.DaHoan=0
  audit
COMMIT

nếu refund PENDING:
  gọi provider ngoài transaction bằng HoanTienIdempotencyKey ổn định
  BEGIN ngắn → lock booking/payment →
    SUCCEEDED: nếu provider trả đúng refundExpected, cập nhật nguyên tử
      DatCho.SoTienHoan = ThanhToan.DaHoan = actualRefund,
      cùng provider reference và thời điểm hoàn;
    FAILED: giữ cả hai bằng 0, cho retry cùng idempotency key;
    audit → COMMIT
```

Nếu provider trả số tiền khác `refundExpected`, không đánh dấu `SUCCEEDED`; chuyển kết quả sang lỗi/đối soát thủ công và audit chênh lệch. Mô hình một lần hoàn của MVP không cho cộng dồn nhiều khoản hoàn nhỏ.

Retry cùng `HuyIdempotencyKey` và cùng owner/payload trả kết quả cũ; khác payload/owner trả conflict chung, không rò dữ liệu. Khóa unique ngăn một key dùng cho hai booking. Release chỉ cập nhật ledger `ACTIVE`; lần chạy sau không giảm counter nữa. Với `PAY_AT_PROPERTY`, không có refund: `SoTienHoan=0`; `PhiHuy` vẫn ghi nhận khoản phí được đánh giá theo policy, nền tảng không theo dõi việc cơ sở có thu khoản đó hay không.

Race hủy–payment:

- cả hai khóa booking trước;
- nếu hủy thắng, callback thành công đến sau không được xác nhận booking; chuyển sang hoàn/báo hỗ trợ;
- nếu payment thành công thắng, hủy tính refund từ số tiền đã trả;
- không trạng thái nào vừa `CANCELLED` vừa có hold active.

### 10.3. Yêu cầu miễn phí hủy

- tạo request `PENDING` không đổi booking, payment hay tồn;
- chỉ cho tạo và xử lý trước thời điểm nhận phòng cục bộ; request còn chờ khi qua cutoff không được approve;
- dưới lock booking/request, service chặn request pending thứ hai;
- chỉ partner cùng tổ chức của property được approve/reject;
- approve chạy **cùng một transaction** với cancellation primitive: khóa booking → payment → waiver pending → hold/inventory, ghi booking `CANCELLED` với fee 0, release đúng một lần, tạo refund linkage nếu có, rồi mới ghi waiver `APPROVED` và audit;
- reject/withdraw chỉ đóng request;
- nếu booking đã terminal trước lúc xử lý, trả conflict và đóng/ghi audit theo lý do;
- nếu primitive hủy/refund-init lỗi hoặc thua race, rollback cả trạng thái waiver; tuyệt đối không có `APPROVED` khi booking chưa `CANCELLED`;
- retry approve không release hoặc refund lần hai.

### 10.4. Sửa thông tin khách

Chỉ `CONFIRMED` trước thời điểm nhận phòng cục bộ mới sửa contact, yêu cầu đặc biệt và `KhachJSON`; không đổi số phòng/product/sức chứa vượt giới hạn. Command nhận `RequestID`, khóa booking, validate toàn bộ rồi cập nhật snapshot và audit before/after. Không có bảng lịch sử riêng.

### 10.5. Đổi ngày all-or-nothing

Điều kiện: booking `CONFIRMED`, `PAY_AT_PROPERTY`, payment `NOT_TRACKED`, trước thời điểm nhận phòng cục bộ; đổi toàn booking, giữ nguyên room type/product/phân bổ khách.

Preview đổi ngày dùng giá/restriction/tồn hiện tại nhưng không giữ tồn và không tạo bảng. Server trả token HMAC ngắn hạn gắn `KhachHangID`, `DatChoID`, criteria, fingerprint và expiry; khi xác nhận phải kiểm chữ ký, owner, expiry rồi tính lại toàn bộ. Có thể chọn phương án không token và luôn recompute từ payload, nhưng không được tin tổng tiền client gửi.

Khi xác nhận:

1. `BEGIN`, khóa booking, payment và kiểm tra `RequestID` chưa dùng;
2. đọc/version-check giá và restriction hiện tại; tính union demand cũ/mới theo room type–date;
3. khóa active ledger, rồi toàn bộ inventory union theo thứ tự chuẩn;
4. kiểm tra active ledger cũ còn nhất quán;
5. chỉ yêu cầu capacity bổ sung cho `delta > 0`; nếu thiếu thì rollback, dữ liệu cũ nguyên vẹn;
6. cập nhật counter bằng delta; giữ nguyên `ACTIVE` ở ngày giao nhau, chuyển `RELEASED` ở ngày chỉ-cũ; với ngày chỉ-mới thì insert nếu chưa có hoặc kích hoạt lại dòng `RELEASED` đã tồn tại từ lần đổi trước (`TrangThai=ACTIVE`, xóa `NhaLuc/LyDoNha`, đặt lại `GiuLuc/HetHanLuc`); không release-all rồi insert lại vì unique `(HangMucDatChoID, NgayLuuTru)`;
7. cập nhật ngày booking/item snapshot; với `GiaDemDatCho`, update ngày giao nhau, xóa ngày chỉ-cũ, insert ngày chỉ-mới trong cùng transaction;
8. cập nhật tổng booking, audit before/after; commit.

Không xóa dữ liệu cũ trước khi đã khóa/kiểm tra đủ dữ liệu mới. Deadlock được retry giới hạn bằng cùng `RequestID`.

---

## 11. No-show, hoàn tất và đánh giá

### 11.1. No-show

Đối tác cùng tổ chức chỉ được đánh dấu từ giờ nhận phòng đến hết ngày kế tiếp theo múi giờ cơ sở.

`PhiNoShow = min(DatCho.TongTien, SUM(round(itemTotal * TyLePhatNoShow / 100)))`, đọc tỷ lệ trong snapshot của từng item và làm tròn trước khi cộng.

```text
BEGIN
  lock CONFIRMED booking
  kiểm tra organization và cửa sổ thời gian
  tính phí no-show từ snapshot
  lock payment (nếu online)
  lock mọi ledger ACTIVE và inventory theo thứ tự chuẩn
  release toàn bộ ledger ACTIVE, giảm counter
  set NO_SHOW + actor/time/fee/snapshot
  nếu online đã trả > phí: set refund PENDING với key/reason ổn định
  audit
COMMIT
```

MVP không có no-show một phần. Phí no-show và khả năng bán lại tồn là hai vấn đề độc lập; booking `NO_SHOW` luôn giải phóng toàn bộ hold cục bộ.

Với online, `expectedRefund = max(0, ThanhToan.DaThanhToan - PhiNoShow)` được ghi trong `NoShowSnapshotJSON`; `DatCho.SoTienHoan` vẫn bằng 0 vì trường này dành cho cancellation. Sau commit, adapter hoàn tiền ngoài inventory lock, rồi transaction ngắn cập nhật `ThanhToan.DaHoan` và trạng thái refund bằng cùng idempotency key. Với `PAY_AT_PROPERTY`, phí no-show chỉ là **phí được đánh giá**, nền tảng không biết cơ sở có thực thu hay không và không tạo refund.

### 11.2. Hoàn tất

Tác vụ console chạy theo lô nhỏ sau thời điểm trả phòng:

- khóa từng booking `CONFIRMED`;
- release hold active còn lại;
- chuyển `COMPLETED`, ghi `HoanThanhLuc` và audit;
- chạy lại không tạo side effect.

### 11.3. Đánh giá

Cho phép khi booking `COMPLETED`, đúng khách, chưa có review và ngày hiện tại nằm trong 0–90 ngày tính từ `NgayTraPhong` theo múi giờ cơ sở. Không tính từ `HoanThanhLuc`, vì job chạy trễ không được kéo dài hạn review. Transaction khóa booking, kiểm tra unique rồi insert. Quản trị chỉ `HIDDEN/PUBLISHED`, không sửa nội dung thay khách; mọi moderation có audit.

Điểm trung bình công khai chỉ tính review `PUBLISHED`; hiển thị số lượng để tránh gây hiểu nhầm với mẫu quá nhỏ.

---

## 12. Duyệt đối tác, cô lập tenant và audit

### 12.1. Cô lập tổ chức

Ba lớp cùng bắt buộc:

1. middleware xác nhận `PARTNER`, user active và tổ chức active;
2. repository thêm organization scope ngay trong SQL;
3. application kiểm tra quan hệ xuyên bảng: property–room–product–policy–booking cùng tổ chức/cơ sở.

Không cho client truyền `ToChucDoiTacID` để chọn scope. Admin vượt scope chỉ qua route admin và ghi audit.

`ToChucDoiTac.TAM_KHOA` chặn cơ sở của tổ chức khỏi search/detail availability, preview và booking mới dù `CoSoLuuTru` còn `ACTIVE`; readiness/approval cũng không được hoàn tất cho tổ chức đang khóa. Các booking đã tồn tại vẫn phải được khách quản lý bình thường. Thành viên đối tác còn active được đọc và thực hiện use case phục vụ booking hiện hữu có giới hạn (xử lý waiver, no-show và xem thông tin cần thiết), hoặc admin hỗ trợ có audit; họ không được sửa catalog, giá, tồn, promotion hay gửi/duyệt property. Middleware vì vậy tách quyền “quản trị thương mại” khỏi quyền “phục vụ booking hiện hữu”, không chỉ kiểm tra một cờ chung.

### 12.2. Duyệt và thay đổi trọng yếu

Admin dùng row lock trên property khi duyệt/từ chối/tạm ngừng. Trước khi `ACTIVE`, chạy `PropertyReadinessChecker` trong cùng request; dữ liệu không đạt thì không duyệt. Khi partner sửa trường trọng yếu trên property active, transaction chuyển `PENDING_REVIEW`, xóa metadata duyệt hiện tại và audit snapshot. Search chỉ đọc `ACTIVE`, nên cơ sở tạm ẩn cho đến khi duyệt lại.

### 12.3. Audit tối thiểu

Phải audit: login thất bại đáng chú ý/khóa user, thay đổi vai trò/tổ chức, gửi/duyệt/từ chối/tạm ngừng property, sửa giá/tồn hàng loạt, tạo booking, kết quả payment/refund, hủy, waiver, sửa booking, đổi ngày, no-show, completion, review moderation và admin support access.

JSON audit không chứa mật khẩu/hash, token preview thô, session ID, payment token, bí mật provider hoặc PII không cần thiết.

---

## 13. Báo cáo và định nghĩa số liệu

Không gọi số liệu là “công suất phòng khách sạn” vì hệ thống chỉ biết tồn mở bán trên nền tảng.

| Chỉ số | Công thức/nguồn | Ghi chú |
|---|---|---|
| Số booking | `COUNT(DatCho)` theo ngày tạo/property/state | luôn tách trạng thái |
| Tổng giá trị booking | `SUM(TongTien)` cho trạng thái được chọn | không gọi là doanh thu nền tảng |
| Tỷ lệ hủy | `CANCELLED / booking đủ mẫu` | hiển thị mẫu và kỳ |
| Tỷ lệ no-show | `NO_SHOW / booking đã đến kỳ` | không gộp cancellation |
| Room-night đặt thành công | số `GiaDemDatCho` của booking `CONFIRMED/COMPLETED/NO_SHOW` | no-show đã chiếm khả năng bán trước thời điểm đánh dấu |
| Room-night đang giữ tương lai | số `GiuTonPhongDem.ACTIVE` trong kỳ tới | chỉ là ảnh chụp hiện tại, gồm pending/confirmed |
| Room-night mở bán | `SUM(TonPhongNgay.TongSoLuong)` trong kỳ | phản ánh dữ liệu platform hiện lưu |
| Tỷ lệ bán trên tồn mở bán | `room-night đặt thành công / room-night mở bán * 100` | không phải hotel occupancy; historical denominator có thể đổi nếu partner sửa tồn quá khứ |
| Giá đặt bình quân | tổng `GiaDemDatCho.ThanhTien / room-night đặt thành công` | dùng snapshot, không dùng giá hiện tại |
| Tồn đang giữ | `SUM(SoLuongDaGiu)` hoặc ledger active | dùng đối soát thời điểm hiện tại |
| Điểm trung bình | `AVG(DiemTong)` review published | kèm số review |

Đối tác chỉ thấy property của tổ chức mình. Admin có số liệu toàn hệ thống. `PhiHuy`/`PhiNoShow` của `PAY_AT_PROPERTY` là phí đã đánh giá theo policy, không phải doanh thu đã thu. Không dùng ledger `RELEASED` để tái dựng lịch sử bán vì release có nhiều nguyên nhân; lịch sử booking/giá đọc từ `DatCho`, `HangMucDatCho`, `GiaDemDatCho`. Query báo cáo là read-only; nếu cần denominator tồn mở bán bất biến theo lịch sử sẽ là phase sau, không thêm bảng trong MVP.

---

## 14. Chỉ mục và kế hoạch `EXPLAIN`

### 14.1. Chỉ mục hiện có trong migration chuẩn

- property: unique mã/slug/nguồn/Google Place; `(MaThanhPho, TrangThai, LoaiCoSo)`, `(ToChucDoiTacID, TrangThai)`;
- room type: unique mã trong cơ sở, `(CoSoLuuTruID, TrangThai)`;
- product: unique mã trong room type, `(LoaiPhongID, TrangThai)`;
- rate: PK `(SanPhamPhongID, NgayLuuTru)`, `(NgayLuuTru, DongBan, SanPhamPhongID)`;
- promotion: `(CoSoLuuTruID, TrangThai, DatTu, DatDen, LuuTruTu, LuuTruDen)`;
- inventory: PK `(LoaiPhongID, NgayLuuTru)`, `(NgayLuuTru, LoaiPhongID)`;
- preview: unique token, `(KhachHangID, TrangThai, HetHanLuc)`;
- booking: unique mã, `ClientRequestID`, preview, cancellation key; `(KhachHangID, TaoLuc)`, `(CoSoLuuTruID, TrangThai, NgayNhanPhong)`;
- item/night: unique booking–thứ tự, PK item–ngày;
- ledger: unique item–ngày, `(LoaiPhongID, NgayLuuTru, TrangThai)`, `(TrangThai, HetHanLuc)`;
- payment: unique booking/payment key/refund key/provider reference;
- waiver/review/audit: chỉ mục theo các màn hình chính.

### 14.2. Query phải kiểm tra

Fixture chuẩn cần đủ tối thiểu 30 cơ sở, nhiều loại phòng/product và 365 ngày giá/tồn. Chạy `EXPLAIN` cho:

1. search đủ giá và tồn cho toàn kỳ;
2. lọc property theo nhiều tiện nghi;
3. availability trang chi tiết;
4. danh sách booking khách;
5. danh sách booking property theo ngày/state;
6. tìm ledger active để đối soát counter và tác vụ expiry;
7. review công khai;
8. báo cáo tỷ lệ bán trên tồn mở bán.

Không thêm index chỉ theo cảm tính. Nếu fixture chứng minh lọc tiện nghi bị quét lớn, migration tiếp theo có thể thêm chỉ mục đảo `(TienNghiID, CoSoLuuTruID)` và `(TienNghiID, LoaiPhongID)`. Mọi chỉ mục mới phải có query/`EXPLAIN` trước–sau và kiểm tra chi phí ghi.

---

## 15. Bảo mật và dữ liệu cá nhân

- rotate session ID sau đăng nhập; timeout hợp lý;
- cookie `HttpOnly`, `SameSite=Lax`, `Secure` khi HTTPS;
- CSRF token cho mọi thao tác ghi;
- prepared statement; sort/column/filter chỉ qua allow-list;
- escape HTML theo ngữ cảnh; CSP cơ bản nếu triển khai được;
- login, payment/cancel preview và upload có giới hạn tần suất đơn giản theo session/IP;
- token preview lưu hash; mã booking khó đoán nhưng vẫn cần authorization;
- upload kiểm MIME thực, kích thước, giải mã ảnh, tên ngẫu nhiên và lưu ngoài vùng PHP thực thi;
- không nhận/lưu số thẻ, CVV, payment token thô hoặc provider secret;
- mã hóa/che email, số điện thoại trong log và giao diện support tùy quyền;
- backup database và uploads phải cùng chính sách giữ/xóa;
- lỗi trả client không lộ SQL, đường dẫn máy chủ hoặc stack trace.

---

## 16. Mã lỗi và phản hồi

Envelope JSON nếu dùng API:

```json
{
  "error": {
    "code": "PREVIEW_EXPIRED",
    "message": "Bản xem trước đã hết hạn. Vui lòng kiểm tra lại giá và phòng.",
    "requestId": "...",
    "details": {}
  }
}
```

| HTTP | Mã | Trường hợp |
|---:|---|---|
| 400 | `VALIDATION_ERROR` | payload/ngày/khách không hợp lệ |
| 401 | `AUTH_REQUIRED` | chưa đăng nhập |
| 403 | `FORBIDDEN`, `TENANT_SCOPE_DENIED` | sai vai trò/tổ chức |
| 404 | `PROPERTY_NOT_FOUND`, `BOOKING_NOT_FOUND` | không tìm thấy hoặc cố ý che tài nguyên ngoài scope |
| 409 | `NO_AVAILABILITY` | không đủ tồn khi preview/booking/đổi ngày |
| 409 | `PREVIEW_STALE`, `CANCELLATION_QUOTE_CHANGED` | giá/policy/fee thay đổi |
| 409 | `PREVIEW_USED`, `INVALID_STATE_TRANSITION` | dùng lại hoặc state không cho phép |
| 409 | `IDEMPOTENCY_CONFLICT` | cùng key nhưng payload khác |
| 410 | `PREVIEW_EXPIRED` | preview quá 15 phút |
| 422 | `OCCUPANCY_MISMATCH`, `RESTRICTION_FAILED`, `MIXED_PAYMENT_TIMING` | không khớp sức chứa/điều kiện bán hoặc trộn thời điểm thanh toán |
| 429 | `RATE_LIMITED` | vượt giới hạn |
| 502 | `PAYMENT_PROVIDER_ERROR` | provider lỗi có thể retry |
| 503 | `TEMPORARILY_UNAVAILABLE` | deadlock đã retry hết hoặc phụ thuộc tạm lỗi |

Deadlock DB retry tối đa 2 lần với jitter nhỏ và cùng idempotency key. Không retry tự động validation/conflict.

---

## 17. Kiểm thử bắt buộc

### 17.1. Unit

- khoảng ngày nửa kín, số đêm, múi giờ và cửa sổ no-show/review;
- capacity người lớn/trẻ em và schema JSON giường/khách;
- restriction tại ngày nhận và yêu cầu đủ giá mọi đêm;
- promotion tie-break;
- VND không dùng float, làm tròn và `grand = payNow + payAtProperty`;
- min lead days và các biên local check-in/cancel/review;
- một `PriceCalculator` cho search/detail/preview/booking;
- fingerprint canonical, không phụ thuộc thứ tự key JSON;
- đủ bốn kiểu phí hủy, flexible/non-refundable, deadline đúng mốc và phí no-show từ snapshot;
- state transition property/booking/payment/refund/waiver;
- allocator deterministic và không làm rơi khách.

### 17.2. Integration

- đăng nhập, CSRF, account lock;
- partner tenant isolation ở list/detail/mutation/report;
- tổ chức tạm khóa biến mất khỏi bán mới nhưng booking cũ vẫn được khách/đối tác đúng scope xử lý có giới hạn;
- readiness, duyệt, yêu cầu sửa về `DRAFT`, reject, suspend, material edit;
- product–policy cùng cơ sở; tiện nghi đúng phạm vi;
- search cần đủ rate/tồn mọi đêm; trang detail trả nhiều lựa chọn;
- cơ sở có hai timing đều hợp lệ: card search hiển thị đúng tổ hợp rẻ nhất toàn cục và badge của timing thắng;
- không đổi criteria/timing/lựa chọn và dữ liệu không đổi: giá, allocation, `paymentTiming` và fingerprint ở search khớp detail;
- cùng input và cùng snapshot: allocator luôn trả cùng thứ tự kết quả; ca hòa giá dùng `PAY_AT_PROPERTY`, rồi tuple room/product làm tie-break;
- filter timing chỉ xét nhánh tương ứng; sort giá dùng đúng giá đang hiển thị;
- toggle timing ở detail gọi lại availability và toàn bộ lựa chọn trả về thuộc timing mới;
- preview 15 phút, single-use, JSON schema, không giữ tồn;
- stale preview bị từ chối hoặc yêu cầu preview mới;
- booking nhiều phòng cùng property và rollback toàn bộ;
- `PAY_AT_PROPERTY + NOT_TRACKED`;
- online success/failure/timeout/late success/retry;
- multi-room cùng timing; tổ hợp trộn `PAY_AT_PROPERTY/PAY_ONLINE` bị từ chối ở allocator, preview và booking;
- cancel metadata, quote change, release/refund idempotent;
- cùng idempotency key + cùng owner/payload trả kết quả cũ; khác owner/payload trả conflict không rò dữ liệu;
- waiver pending không đổi tồn; approve + cancel atomic, lỗi primitive rollback trạng thái waiver;
- sửa khách, đổi ngày thành công, overlap giữ ledger, đổi lại ngày từng release và rollback khi thiếu ngày mới;
- no-show release toàn bộ; completion idempotent;
- no-show online hoàn phần đã trả vượt phí đúng một lần; pay-at-property chỉ ghi phí assessed;
- review eligibility/unique/moderation;
- provenance và quy tắc Google metadata.
- preview/checkout không để token trong URL, log, referrer hoặc localStorage.

### 17.3. Concurrency

Dùng ít nhất hai kết nối DB thật, barrier đồng bộ và assertion hậu kiểm:

1. hai request tranh unit cuối: đúng một booking thắng;
2. double-click cùng `ClientRequestID`: một booking;
3. hai request dùng cùng preview nhưng UUID khác: một booking do unique preview;
4. payment callback và expiry cùng lúc: một terminal result;
5. payment success muộn sau release: không hồi sinh booking;
6. cancel và payment finalize: không giữ tồn sai, không hoàn hai lần;
7. cancel và no-show: đúng một state thắng;
8. waiver approve và cancel trực tiếp: release một lần;
9. giảm `TongSoLuong` và booking: không thấp hơn held;
10. hai đổi ngày khóa chéo: không deadlock vĩnh viễn, booking cũ không bị phá;
11. booking và partner sửa rate/restriction đồng thời: booking dùng đúng version đã revalidate hoặc bị stale, không tạo snapshot lai;
12. completion và cancel/no-show: đúng một terminal state;
13. mọi test cuối cùng đối soát counter với ledger.

### 17.4. Contract/UI

- trường tiền, `paymentTiming`, badge, allocation và chính sách hiển thị giống giữa search → detail → preview khi dữ liệu không đổi;
- thay đổi timing bằng toggle tạo lựa chọn mới rõ ràng; không giữ fingerprint/giá của timing trước;
- thay đổi được phát hiện rõ, không âm thầm đổi allocation;
- màn hình có trạng thái loading/empty/error/retry;
- back button/double submit không tạo booking/hủy/refund trùng;
- keyboard, label, thông báo lỗi và định dạng ngày/tiền dễ hiểu.

---

## 18. Seed, import và validator

### 18.1. Thứ tự import

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

Generator phải deterministic: cùng seed nguồn tạo cùng mã, số dòng và checksum. Manifest ghi phiên bản generator, SHA-256 file nguồn, thời điểm tạo và `tableCount=22`. Dữ liệu cơ sở dùng tên/địa chỉ/tọa độ có provenance; giá, tồn, booking và review tổng hợp phải được ghi rõ là dữ liệu sinh.

CSV import dùng UTF-8, header cố định, ngày ISO, số không có dấu phân cách hàng nghìn. Import vào transaction theo lô; kiểm tra toàn bộ dòng trước commit; lỗi trả số dòng/cột và không tạo trạng thái nửa vời. Không dùng `SET FOREIGN_KEY_CHECKS=0` trong luồng import runtime.

### 18.2. Validator phải trả 0 vi phạm

- schema có đúng 22 bảng và đúng FK/CHECK/unique dự kiến;
- không orphan; quan hệ xuyên bảng cùng property/tổ chức;
- JSON bắt buộc hợp lệ theo schema ứng dụng;
- `SoLuongDaGiu` bằng ledger `ACTIVE`, không oversell;
- active ledger chỉ thuộc `PENDING_PAYMENT/CONFIRMED`;
- terminal booking, gồm `NO_SHOW`, không có active ledger;
- nightly rows đúng `[check-in, check-out)` và cộng đúng item/booking;
- preview có tổng tiền/JSON/fingerprint nhất quán, preview không có ledger;
- payment state đúng timing và booking state;
- mọi item cùng payment timing; online trả ngay toàn bộ, tại cơ sở trả tại chỗ toàn bộ;
- chính xác một trong `SoTienTraNgay`, `SoTienTraTaiCoSo` bằng `TongTien` và giá trị còn lại bằng 0;
- `GiaDemDatCho.ThuePhiTaiCoSo=0` cho mọi booking online;
- cancellation/refund/no-show metadata đúng state, amounts nhất quán;
- no-show online có `DaHoan = max(0, DaThanhToan - PhiNoShow)` khi refund succeeded, còn `DatCho.SoTienHoan=0`;
- waiver `APPROVED` bắt buộc booking `CANCELLED` với fee 0 và không active ledger;
- review đúng khách/property/booking và eligibility;
- provenance tên/địa chỉ/tọa độ và Google fields hợp lệ;
- `ClientRequestID` global unique và không booking nào dùng chung preview.

### 18.3. Khoảng lệch payment timing đã được khép

`database/seeds/006_operational.sql` đã được tái sinh bằng generator `2.1-payment-timing`; generator hiện hành `2.2-image-assets` kế thừa nguyên vẹn các quy tắc này. Với booking `PAY_ONLINE`, phí bắt buộc được đưa vào phần thu trực tuyến, `GiaDemDatCho.ThuePhiTaiCoSo=0`, preview trả ngay toàn bộ và `ThanhToan.TongPhaiTra=DatCho.TongTien`. Với `PAY_AT_PROPERTY`, preview trả tại cơ sở toàn bộ và `ThuePhiTaiCoSo` có thể lớn hơn 0. Validator kiểm tra thêm timing product–booking, split amount, timing trong JSON, tổng nightly–item–booking và cột phí tại cơ sở của online; mọi lần tái sinh phải trả 0 vi phạm.

### 18.4. Quy trình ảnh seed và nguồn gốc tài sản

`huongDan/majestic_hotel_image_pack_v2/image_mapping.json` là nguồn ánh xạ duy nhất cho ảnh seed. Ánh xạ tách rõ ba trường hợp:

- ảnh Wikimedia của Hotel Majestic Saigon chỉ áp dụng cho `CS016`;
- ảnh phòng nguồn chỉ áp dụng cho mã loại phòng được khai báo rõ trong `roomImagesByCode`;
- mọi cơ sở hoặc loại phòng không có ánh xạ riêng dùng ảnh minh họa trung tính dưới `public/assets/images/accommodation/shared/`.

Script `download_images.py` chỉ chấp nhận HTTPS từ Wikimedia Commons, kiểm tra tên file/path traversal, MIME, chữ ký file, kích thước ảnh, giới hạn dung lượng và ghi file nguyên tử. Sau tải, script tạo `public/assets/images/accommodation/majestic/ASSET_LOCK.json` chứa SHA-256 để truy vết đúng file đã dùng. `MANIFEST.csv` và `ATTRIBUTION.md` là hồ sơ giấy phép/ghi công; chúng không được dùng làm dữ liệu nghiệp vụ runtime.

Generator `scripts/data/generate_data.php --properties-only` chỉ sinh lại `database/seeds/003_properties.sql` và manifest dữ liệu; không được ghi lại seed `006_operational.sql`. Trước khi sinh, generator kiểm tra ảnh tồn tại, giải mã được, đường dẫn nằm trong `public/`, thứ tự liên tục, đúng một ảnh bìa và alt của ảnh dùng chung bắt đầu bằng `Ảnh minh họa`. Validator đối chiếu SHA-256 của toàn bộ tài sản được manifest tham chiếu, cấm đường dẫn `/uploads/...` cũ và yêu cầu seed có cả nhánh ảnh Majestic lẫn ảnh minh họa dùng chung.

Quy trình này không thêm bảng hoặc cột: `CoSoLuuTru.AnhJSON` và `LoaiPhong.AnhJSON` tiếp tục lưu mảng đường dẫn tương đối, văn bản thay thế, cờ ảnh bìa và thứ tự như schema ứng dụng đã chốt.

---

## 19. Chia gói công việc

| Gói | Phạm vi | Contract đầu ra | Phụ thuộc |
|---|---|---|---|
| A | bootstrap, router, PDO, session, CSRF, Identity | auth middleware, organization context | không |
| B | Properties | repository có tenant scope, readiness, approval, upload JSON | A |
| C | Commercial | policy/product/rate/restriction/promotion, `PriceCalculator` | B |
| D | Inventory | availability, lock/hold/release/reconcile | B |
| E | Search | criteria, allocator, search/detail/preview | B, C, D |
| F | Booking | transaction tạo booking, snapshot, payment mock | C, D, E |
| G | Sau booking | cancel/refund/waiver/sửa ngày/no-show/completion | F |
| H | Review/Admin | review, audit, report, validator/EXPLAIN | B, F, G |

Quy tắc làm nhóm:

- chốt DTO/contract dùng chung trước khi code nhánh;
- một migration chỉ có một người sở hữu trong thời điểm chỉnh;
- không merge thay đổi bảng mà thiếu validator/test/tài liệu tương ứng;
- commit theo use case hoặc invariant hoàn chỉnh, không theo từng file vụn;
- review transaction phải đọc cả nhánh lỗi và retry, không chỉ happy path.

---

## 20. Liên kết DFD và sequence diagram

- Luồng dữ liệu: [`DFD_HOTEL_BOOKING.md`](DFD_HOTEL_BOOKING.md)
- Trình tự tương tác và transaction: [`SEQUENCE_DIAGRAM_HOTEL_BOOKING.md`](SEQUENCE_DIAGRAM_HOTEL_BOOKING.md)

Hai sơ đồ là tài liệu giải thích. Khi chúng khác V2, ưu tiên `DESCRIPTION_HOTEL_BOOKING_V2.md` rồi tài liệu này; sau đó phải tạo issue sửa sơ đồ, không để sai khác kéo dài.

### 20.1. Ma trận truy vết cốt lõi

| Nghiệp vụ | Thành phần chính | Bảng ghi | Kiểm thử bắt buộc | Sơ đồ liên quan |
|---|---|---|---|---|
| Duyệt cơ sở | `PropertyReadinessChecker`, `PropertyApprovalService` | `CoSoLuuTru`, audit | readiness/state/material edit | DFD 5; Sequence 3 |
| Giá/tồn | `RateCalendarService`, `InventoryService` | rate, inventory, audit | restriction/tenant/concurrency | DFD 5; Sequence 4 |
| Search/detail | `SearchService`, allocator, calculator | không | đủ mọi đêm/deterministic | DFD 6; Sequence 5 |
| Preview | `PreviewService` | `XemTruocDatCho` | 15 phút/no hold/stale | DFD 6; Sequence 6 |
| Booking tại cơ sở | `BookingService`, inventory | booking/item/night/ledger/payment | unit cuối/rollback | DFD 7; Sequence 7 |
| Booking online | `BookingService`, gateway adapter | như trên | provider ngoài lock/race | DFD 7; Sequence 8 |
| Hủy/refund | `CancellationService` | booking/ledger/payment/audit | exactly-once/race | DFD 8; Sequence 9 |
| Waiver | `WaiverService` | waiver và các bảng hủy khi approve | pending không đổi tồn | DFD 8; Sequence 10 |
| Sửa khách/ngày | `BookingModificationService` | booking/item/night/ledger/audit | rollback/idempotency | DFD 8; Sequence 11–12 |
| No-show/completion | lifecycle jobs/services | booking/ledger/audit | no active hold/idempotent | DFD 8; Sequence 13–14 |
| Review | `ReviewService` | `DanhGia`, audit | eligibility/unique | DFD 9; Sequence 15 |
| Báo cáo/audit | read query services | chỉ đọc + audit | tenant/formula/EXPLAIN | DFD 9; Sequence 16 |

---

## 21. Definition of Done

Một chức năng chỉ hoàn thành khi:

1. hành vi khớp DESCRIPTION V2 và transition hợp lệ;
2. controller không vượt ranh giới module;
3. mọi write nhiều bảng có transaction và thứ tự lock được review;
4. validation, authorization, CSRF và tenant scope có test;
5. idempotency/retry và nhánh lỗi được xử lý;
6. snapshot lịch sử không phụ thuộc catalog hiện tại;
7. unit + integration test qua; use case tranh chấp có concurrency test;
8. audit không chứa bí mật/PII dư thừa;
9. query quan trọng có `EXPLAIN` trên fixture mục tiêu;
10. seed/import/validator vẫn chạy và schema vẫn đúng 22 bảng;
11. DFD/sequence/README được cập nhật nếu contract thay đổi;
12. UI hiển thị rõ tổng, khoản trả ngay/tại cơ sở, chính sách và lỗi có thể hành động.

MVP toàn hệ thống hoàn thành khi additionally:

- race unit cuối chỉ một booking thắng;
- preview không bao giờ giữ tồn;
- counter luôn khớp ledger active;
- cancel/refund/no-show/completion chạy lại không gây tác dụng kép;
- `PAY_AT_PROPERTY` luôn `NOT_TRACKED` và không có vòng đời thu tiền giả;
- payment online không gọi provider khi giữ inventory lock;
- `NO_SHOW` giải phóng mọi hold active;
- đổi ngày thất bại giữ nguyên booking cũ;
- partner không đọc/ghi ngoài tổ chức;
- không có route/query/tài liệu triển khai tham chiếu bảng đã loại;
- báo cáo dùng tên “tỷ lệ bán trên tồn mở bán”, không gọi sai là công suất khách sạn.

---

## 22. Các bất biến phải nhớ khi triển khai

1. Schema có đúng 22 bảng.
2. Một booking chỉ thuộc một property, một customer và một khoảng ngày chung.
3. Mỗi `HangMucDatCho` là một phòng; không bỏ hoặc tự phân bổ lại khách âm thầm.
4. Mọi item có cùng payment timing; MVP không hỗ trợ split payment schedule.
5. Giá, policy, khách và điều kiện của lịch sử nằm trong snapshot.
6. Preview 15 phút, single-use, không giữ tồn và luôn được revalidate.
7. `ClientRequestID` duy nhất toàn hệ thống; cùng key không tạo booking thứ hai.
8. Inventory ở `LoaiPhong + NgayLuuTru`, không ở product.
9. Counter bằng số ledger active; mọi thay đổi counter và ledger cùng transaction.
10. Chỉ `PENDING_PAYMENT/CONFIRMED` có hold active.
11. Không gọi provider khi đang giữ inventory lock.
12. `PAY_AT_PROPERTY + NOT_TRACKED` không phải khoản nợ do nền tảng theo dõi.
13. Cancel, refund, waiver approve, payment retry, no-show và completion đều idempotent.
14. `NO_SHOW` không có biến thể một phần và release mọi hold active tại chỗ.
15. Đổi ngày all-or-nothing; ngày mới lỗi thì dữ liệu cũ không đổi.
16. Mọi truy vấn partner được scope bằng tổ chức ngay trong SQL.
17. Chỉ cơ sở `ACTIVE` thuộc tổ chức `HOAT_DONG` xuất hiện trong tìm kiếm, xem trước và đặt chỗ mới.
18. Google chỉ là đối chiếu/enrichment có provenance, không phải master data mặc định.
19. Không lưu dữ liệu thẻ, token thô hoặc bí mật trong DB/audit/log.
20. Review chỉ từ booking completed, đúng khách, trong 90 ngày, tối đa một lần.
21. Báo cáo inventory là sell-through của phần mở bán trên nền tảng, không phải occupancy vật lý.
