# SƠ ĐỒ LUỒNG DỮ LIỆU (DFD) — HỆ THỐNG ĐẶT CHỖ LƯU TRÚ

**Phiên bản:** 2.2  
**Phạm vi:** mini OTA nhiều cơ sở lưu trú tại Thành phố Hồ Chí Minh  
**Tài liệu nguồn:** `DESCRIPTION_HOTEL_BOOKING_V2.md`, `DESIGN_HOTEL_BOOKING_V2.md`  
**Schema:** 22 bảng

## 1. Quy ước

Mermaid chưa có ký pháp DFD riêng, vì vậy tài liệu dùng `flowchart` với quy ước:

| Ký hiệu | Ý nghĩa |
|---|---|
| Hình chữ nhật | Tác nhân/hệ thống bên ngoài |
| Hình tròn | Tiến trình xử lý |
| Hình trụ | Kho dữ liệu |
| Mũi tên | Dữ liệu được truyền, không biểu thị quyền truy cập trực tiếp |

Tác nhân không đọc hoặc ghi thẳng kho dữ liệu. Mọi dữ liệu đều đi qua tiến trình có kiểm tra quyền, validation và transaction tương ứng.

## 2. DFD mức ngữ cảnh

```mermaid
flowchart LR
    E1["Khách chưa đăng nhập"]
    E2["Khách hàng"]
    E3["Đối tác lưu trú"]
    E4["Quản trị viên"]
    E5["Cổng thanh toán mô phỏng"]
    E6["Google Places / Maps"]

    SYS(("Hệ thống tìm kiếm và<br/>đặt chỗ lưu trú đa cơ sở"))

    E1 -->|"Tiêu chí tìm kiếm, yêu cầu xem chi tiết"| SYS
    SYS -->|"Kết quả tìm kiếm, giá và khả năng còn phòng"| E1

    E2 -->|"Tài khoản, preview, yêu cầu đặt/hủy/đổi ngày, đánh giá"| SYS
    SYS -->|"Xác nhận, trạng thái booking, phí, kết quả thanh toán"| E2

    E3 -->|"Hồ sơ cơ sở, phòng, sản phẩm, giá, tồn, quyết định waiver/no-show"| SYS
    SYS -->|"Booking thuộc cơ sở, báo cáo và kết quả xử lý"| E3

    E4 -->|"Duyệt cơ sở, quản trị tài khoản, kiểm duyệt, yêu cầu báo cáo"| SYS
    SYS -->|"Hồ sơ chờ duyệt, báo cáo và nhật ký hệ thống"| E4

    SYS -->|"Yêu cầu thanh toán/hoàn tiền có idempotency key"| E5
    E5 -->|"Kết quả thành công, thất bại hoặc đang xử lý"| SYS

    SYS -.->|"Truy vấn xác minh khi được triển khai"| E6
    E6 -.->|"Place ID và kết quả đối chiếu tạm thời"| SYS
```

Google Places/Maps chỉ là nguồn enrichment. Dữ liệu Google không được dùng làm master data thay thế tên và provenance nguồn; cache runtime không nằm trong schema MVP.

## 3. DFD mức 0 — toàn hệ thống

```mermaid
flowchart TB
    KH["Khách hàng / khách vãng lai"]
    DT["Đối tác"]
    AD["Quản trị viên"]
    PG["Cổng thanh toán mô phỏng"]
    GP["Google Places / Maps"]
    CLOCK["Đồng hồ hệ thống / tác vụ định kỳ"]

    P1(("1.0<br/>Tài khoản và<br/>tổ chức đối tác"))
    P2(("2.0<br/>Cơ sở, phòng và<br/>sản phẩm bán"))
    P3(("3.0<br/>Tìm kiếm, tính giá<br/>và preview"))
    P4(("4.0<br/>Tạo booking và<br/>thanh toán ban đầu"))
    P5(("5.0<br/>Vận hành vòng đời<br/>booking"))
    P6(("6.0<br/>Đánh giá"))
    P7(("7.0<br/>Duyệt, báo cáo<br/>và audit"))

    D1[("D1 — Identity<br/>3 bảng")]
    D2[("D2 — Properties<br/>5 bảng")]
    D3[("D3 — Commercial<br/>4 bảng")]
    D4[("D4 — Inventory<br/>2 bảng")]
    D5[("D5 — Bookings<br/>6 bảng")]
    D6[("D6 — Reviews<br/>1 bảng")]
    D7[("D7 — Audit<br/>1 bảng")]

    KH -->|"Đăng ký, đăng nhập, cập nhật hồ sơ"| P1
    DT -->|"Đăng nhập, thông tin thành viên/tổ chức"| P1
    AD -->|"Khóa/mở tài khoản"| P1
    P1 <-->|"Tài khoản và membership"| D1
    P1 -->|"Kết quả xác thực và phạm vi quyền"| KH
    P1 -->|"Kết quả xác thực và organization scope"| DT

    DT -->|"Hồ sơ cơ sở, loại phòng, tiện nghi, chính sách, giá, tồn"| P2
    AD -->|"Quyết định duyệt/tạm ngưng"| P2
    P2 <-->|"Hồ sơ cơ sở và loại phòng"| D2
    P2 <-->|"Sản phẩm, giá, chính sách, khuyến mãi"| D3
    P2 <-->|"Tồn mở bán"| D4
    P2 -.->|"Yêu cầu xác minh"| GP
    GP -.->|"Kết quả đối chiếu"| P2

    KH -->|"Điểm đến, ngày, số khách/phòng, bộ lọc"| P3
    D2 -->|"Cơ sở, phòng, tiện nghi"| P3
    D3 -->|"Giá, restriction, chính sách, khuyến mãi"| P3
    D4 -->|"Tồn khả dụng hiện hành"| P3
    P3 -->|"Kết quả, phương án phân phòng và breakdown giá"| KH
    P3 -->|"Preview có hạn, không giữ tồn"| D5

    KH -->|"Preview token, thông tin liên hệ, client request ID"| P4
    D5 -->|"Preview và snapshot"| P4
    D3 -->|"Giá/điều kiện hiện hành để revalidate"| P4
    P4 <-->|"Khóa, giữ và bộ đếm tồn"| D4
    P4 -->|"Booking, item, giá đêm, payment ban đầu"| D5
    P4 -->|"Yêu cầu thanh toán sau khi commit"| PG
    PG -->|"Kết quả thanh toán"| P4
    P4 -->|"Mã booking hoặc lỗi"| KH

    KH -->|"Sửa thông tin, đổi ngày, hủy, waiver"| P5
    DT -->|"Xử lý waiver, no-show, hoàn tất"| P5
    CLOCK -->|"Booking/hold hết hạn"| P5
    P5 <-->|"Booking, payment, waiver"| D5
    P5 <-->|"Nhả/giữ tồn đúng một lần"| D4
    P5 -->|"Hoàn tiền ngoài transaction khóa tồn"| PG
    PG -->|"Kết quả hoàn tiền"| P5
    P5 -->|"Trạng thái, phí và số tiền hoàn"| KH
    P5 -->|"Kết quả xử lý"| DT

    KH -->|"Điểm và bình luận cho booking đã hoàn tất"| P6
    D5 -->|"Điều kiện booking đã hoàn tất"| P6
    P6 <-->|"Đánh giá"| D6
    P6 -->|"Kết quả tạo/xem đánh giá"| KH

    AD -->|"Duyệt, kiểm duyệt, truy vấn báo cáo/audit"| P7
    DT -->|"Yêu cầu báo cáo trong organization scope"| P7
    D1 --> P7
    D2 --> P7
    D3 --> P7
    D4 --> P7
    D5 --> P7
    D6 --> P7
    P7 <-->|"Nhật ký append-only"| D7
    P7 -->|"Kết quả duyệt, báo cáo"| AD
    P7 -->|"Báo cáo phạm vi tổ chức"| DT

    P1 -->|"Sự kiện bảo mật/quản trị"| D7
    P2 -->|"Lịch sử thay đổi"| D7
    P4 -->|"Sự kiện booking/payment"| D7
    P5 -->|"Sự kiện hủy/sửa/no-show"| D7
    P6 -->|"Sự kiện đánh giá"| D7
```

## 4. DFD mức 1 — tài khoản và tổ chức đối tác

```mermaid
flowchart LR
    KH["Khách hàng"]
    DT["Nhân viên đối tác"]
    AD["Quản trị viên"]

    P11(("1.1<br/>Đăng ký tài khoản"))
    P12(("1.2<br/>Đăng nhập / đăng xuất"))
    P13(("1.3<br/>Cập nhật hồ sơ"))
    P14(("1.4<br/>Quản lý tổ chức<br/>và thành viên"))
    P15(("1.5<br/>Khóa / mở tài khoản"))
    P16(("1.6<br/>Xác lập phạm vi<br/>truy cập đối tác"))

    U[("NguoiDung")]
    O[("ToChucDoiTac")]
    M[("ThanhVienDoiTac")]
    A[("NhatKyHeThong")]

    KH -->|"Họ tên, email, điện thoại, mật khẩu"| P11
    P11 -->|"Password hash, vai trò CUSTOMER"| U
    P11 -->|"Kết quả đăng ký"| KH

    KH -->|"Thông tin đăng nhập"| P12
    DT -->|"Thông tin đăng nhập"| P12
    AD -->|"Thông tin đăng nhập"| P12
    U -->|"Hash, vai trò, trạng thái"| P12
    P12 -->|"Session và vai trò"| KH
    P12 -->|"Session và vai trò"| DT
    P12 -->|"Session và vai trò"| AD

    KH -->|"Thông tin hồ sơ mới"| P13
    DT -->|"Thông tin hồ sơ mới"| P13
    P13 <--> U

    AD -->|"Tổ chức, chủ sở hữu, thành viên"| P14
    P14 <--> O
    P14 <--> M
    U -->|"Người dùng vai trò PARTNER"| P14

    AD -->|"Lệnh khóa/mở và lý do"| P15
    P15 <--> U
    P15 -->|"Sự kiện quản trị"| A

    DT -->|"Session"| P16
    M -->|"Membership duy nhất"| P16
    O -->|"Tổ chức đang hoạt động"| P16
    P16 -->|"ToChucDoiTacID bắt buộc cho repository"| DT
```

## 5. DFD mức 1 — cơ sở, sản phẩm, giá và tồn

```mermaid
flowchart TB
    DT["Đối tác đúng organization scope"]
    AD["Quản trị viên"]
    GP["Google Places / Maps"]

    P21(("2.1<br/>Tạo và cập nhật<br/>hồ sơ cơ sở"))
    P22(("2.2<br/>Quản lý loại phòng,<br/>ảnh, giường, tiện nghi"))
    P23(("2.3<br/>Quản lý chính sách hủy<br/>và sản phẩm phòng"))
    P24(("2.4<br/>Quản lý giá ngày<br/>và điều kiện mở bán"))
    P25(("2.5<br/>Quản lý tồn mở bán<br/>theo loại phòng/ngày"))
    P26(("2.6<br/>Quản lý khuyến mãi"))
    P27(("2.7<br/>Gửi duyệt và<br/>quyết định duyệt"))
    P28(("2.8<br/>Đối chiếu địa điểm"))

    CS[("CoSoLuuTru")]
    LP[("LoaiPhong")]
    TN[("TienNghi")]
    CSTN[("CoSoTienNghi")]
    LPTN[("LoaiPhongTienNghi")]
    CSH[("ChinhSachHuy")]
    SP[("SanPhamPhong")]
    GIA[("GiaPhongNgay")]
    KM[("KhuyenMai")]
    TON[("TonPhongNgay")]
    AUD[("NhatKyHeThong")]

    DT -->|"Tên, địa chỉ, provenance, mô tả, ảnh, thuế/phí"| P21
    P21 <--> CS
    P21 -->|"Lịch sử thay đổi"| AUD

    DT -->|"Sức chứa, giường JSON, ảnh JSON, tiện nghi"| P22
    P22 <--> LP
    TN -->|"Danh mục tiện nghi đúng phạm vi"| P22
    P22 <--> CSTN
    P22 <--> LPTN
    CS -->|"Cơ sở sở hữu"| P22

    DT -->|"Chính sách, bữa ăn, cách thanh toán"| P23
    P23 <--> CSH
    P23 <--> SP
    LP -->|"Loại phòng cùng cơ sở"| P23

    DT -->|"Giá, đóng bán, min/max stay, advance days"| P24
    SP -->|"Sản phẩm thuộc cơ sở"| P24
    P24 <--> GIA

    DT -->|"Tổng số lượng mở bán từng ngày"| P25
    LP -->|"Loại phòng"| P25
    P25 <--> TON

    DT -->|"Phần trăm và cửa sổ áp dụng"| P26
    CS -->|"Cơ sở áp dụng"| P26
    P26 <--> KM

    DT -->|"Yêu cầu duyệt"| P27
    AD -->|"Chấp thuận, từ chối, tạm ngưng và ghi chú"| P27
    P27 <--> CS
    P27 -->|"Sự kiện duyệt"| AUD
    P27 -->|"Kết quả duyệt"| DT

    AD -->|"Yêu cầu xác minh khi tích hợp"| P28
    P28 -.->|"Tên/địa chỉ tìm kiếm"| GP
    GP -.->|"Place ID và kết quả tạm"| P28
    P28 -->|"Place ID, trạng thái, thời điểm đã duyệt"| CS
    P28 -->|"Sự kiện xác minh"| AUD
```

Quản lý tồn ở đây chỉ thay đổi `TongSoLuong`. `SoLuongDaGiu` chỉ được thay đổi bởi transaction booking/lifecycle cùng ledger, không phải từ màn hình chỉnh tồn thông thường.

## 6. DFD mức 1 — tìm kiếm, availability, giá và preview

```mermaid
flowchart LR
    KH["Khách / khách hàng"]

    P31(("3.1<br/>Kiểm tra tiêu chí<br/>tìm kiếm"))
    P32(("3.2<br/>Lọc cơ sở<br/>ứng viên"))
    P33(("3.3<br/>Kiểm tra giá,<br/>restriction và tồn"))
    P34(("3.4<br/>Phân bổ nhiều phòng"))
    P35(("3.5<br/>Tính giá, thuế/phí<br/>và khuyến mãi"))
    P36(("3.6<br/>Tạo preview<br/>15 phút"))

    CS[("CoSoLuuTru")]
    LP[("LoaiPhong")]
    TN[("TienNghi và hai bảng nối")]
    SP[("SanPhamPhong")]
    GIA[("GiaPhongNgay")]
    KM[("KhuyenMai")]
    TON[("TonPhongNgay")]
    CSH[("ChinhSachHuy")]
    PRE[("XemTruocDatCho")]

    KH -->|"TP.HCM, check-in/out, số phòng, người lớn, tuổi trẻ em, bộ lọc"| P31
    P31 -->|"Tiêu chí hợp lệ, khoảng đêm [nhận, trả)"| P32

    CS -->|"Cơ sở ACTIVE, loại cơ sở, thông tin hiển thị"| P32
    TN -->|"Tiện nghi được chọn"| P32
    P32 -->|"Danh sách CoSoLuuTruID ứng viên"| P33

    LP -->|"Sức chứa từng loại phòng"| P33
    SP -->|"Sản phẩm ACTIVE và cách thanh toán"| P33
    GIA -->|"Giá đủ mọi đêm, không đóng bán; restriction ngày đến"| P33
    TON -->|"TongSoLuong - SoLuongDaGiu"| P33
    P33 -->|"Ma trận loại phòng/sản phẩm còn bán"| P34

    P34 -->|"Phương án phòng thỏa sức chứa, ưu tiên ít phòng rồi giá thấp"| P35
    GIA -->|"Giá gốc từng đêm"| P35
    KM -->|"Khuyến mãi hợp lệ lớn nhất"| P35
    CS -->|"Thuế/phí của cơ sở"| P35
    CSH -->|"Điều kiện hủy/no-show"| P35
    P35 -->|"Breakdown và snapshot"| KH

    KH -->|"Chọn đúng một cơ sở và các sản phẩm"| P36
    P35 -->|"Giá, policy, payment snapshot"| P36
    GIA -->|"Phiên bản giá hiện hành"| P36
    TON -->|"Phiên bản tồn hiện hành"| P36
    P36 -->|"Token hash, PhanBoJSON, fingerprint, hạn dùng"| PRE
    P36 -->|"Preview token và tổng tiền"| KH
```

Các quy tắc quan trọng:

- search và preview chỉ đọc tồn, không tạo `GiuTonPhongDem` và không tăng `SoLuongDaGiu`;
- cache tìm kiếm, nếu có sau này, chỉ dùng chọn ứng viên; availability cuối luôn đọc dữ liệu hiện hành;
- một preview và một booking chỉ thuộc một `CoSoLuuTru`;
- mọi sản phẩm được chọn phải có đủ giá cho từng đêm và cùng cơ sở.

## 7. DFD mức 1 — tạo booking và thanh toán ban đầu

```mermaid
flowchart TB
    KH["Khách hàng đã đăng nhập"]
    PG["Cổng thanh toán mô phỏng"]

    P41(("4.1<br/>Nhận lệnh đặt chỗ<br/>và idempotency key"))
    P42(("4.2<br/>Khóa preview<br/>và kiểm tra single-use"))
    P43(("4.3<br/>Khóa tồn theo thứ tự<br/>và revalidate"))
    P44(("4.4<br/>Tạo booking,<br/>item và snapshot"))
    P45(("4.5<br/>Giữ tồn và tạo<br/>payment ban đầu"))
    P46(("4.6<br/>Commit transaction"))
    P47(("4.7<br/>Gọi thanh toán online<br/>sau commit"))
    P48(("4.8<br/>Ghi kết quả payment<br/>trong transaction ngắn"))

    PRE[("XemTruocDatCho")]
    RATE[("GiaPhongNgay, SanPhamPhong,<br/>ChinhSachHuy, KhuyenMai")]
    TON[("TonPhongNgay")]
    BOOK[("DatCho")]
    ITEM[("HangMucDatCho")]
    NIGHT[("GiaDemDatCho")]
    HOLD[("GiuTonPhongDem")]
    PAY[("ThanhToan")]
    AUD[("NhatKyHeThong")]

    KH -->|"Preview token, contact, yêu cầu đặc biệt, ClientRequestID"| P41
    BOOK -->|"Booking đã có cùng ClientRequestID nếu retry"| P41
    P41 -->|"Lệnh mới hoặc kết quả idempotent"| P42

    P42 <-->|"SELECT FOR UPDATE"| PRE
    P42 -->|"Preview VALID, chưa hết hạn, chưa dùng"| P43
    RATE -->|"Giá và điều kiện hiện hành"| P43
    P43 <-->|"Khóa các dòng loại phòng/ngày theo thứ tự ổn định"| TON
    P43 -->|"Fingerprint và availability còn hợp lệ"| P44

    P44 -->|"Booking PENDING_PAYMENT hoặc CONFIRMED"| BOOK
    P44 -->|"Mỗi phòng là một item, KhachJSON, snapshot"| ITEM
    P44 -->|"Giá lịch sử từng đêm"| NIGHT
    P44 -->|"Sự kiện tạo booking"| AUD

    P45 -->|"Một hold cho mỗi item/đêm"| HOLD
    P45 -->|"Tăng SoLuongDaGiu"| TON
    P45 -->|"PAY_AT_PROPERTY = NOT_TRACKED;<br/>PAY_ONLINE = PROCESSING"| PAY
    P45 -->|"Đánh dấu preview USED"| PRE
    P45 --> P46

    P46 -->|"PAY_AT_PROPERTY: mã booking CONFIRMED"| KH
    P46 -->|"PAY_ONLINE: payment command"| P47
    P47 -->|"Provider, amount, IdempotencyKey"| PG
    PG -->|"Thành công / thất bại / chưa rõ"| P47
    P47 --> P48
    P48 <--> PAY
    P48 <--> BOOK
    P48 <-->|"Thất bại chắc chắn: nhả hold đúng một lần"| HOLD
    P48 <-->|"Cập nhật counter khi nhả"| TON
    P48 -->|"Kết quả booking/payment"| KH
    P48 -->|"Sự kiện payment"| AUD
```

Không có lời gọi provider trong lúc transaction đang giữ khóa preview hoặc tồn. Nếu hai request tranh đơn vị tồn cuối, transaction khóa trước thắng; request còn lại rollback và không tạo booking dở dang.

## 8. DFD mức 1 — hủy, waiver, đổi ngày, no-show và hoàn tất

```mermaid
flowchart TB
    KH["Khách hàng"]
    DT["Đối tác đúng organization scope"]
    CLOCK["Đồng hồ hệ thống / tác vụ hết hạn"]
    PG["Cổng thanh toán mô phỏng"]

    P51(("5.1<br/>Sửa thông tin khách"))
    P52(("5.2<br/>Preview đổi ngày"))
    P53(("5.3<br/>Áp dụng đổi ngày<br/>nguyên tử"))
    P54(("5.4<br/>Tính và xác nhận hủy"))
    P55(("5.5<br/>Hủy và nhả tồn<br/>đúng một lần"))
    P56(("5.6<br/>Yêu cầu miễn phí hủy"))
    P57(("5.7<br/>Đánh dấu no-show"))
    P58(("5.8<br/>Hoàn tất lưu trú"))
    P59(("5.9<br/>Xử lý booking/payment<br/>hết hạn"))
    P510(("5.10<br/>Hoàn tiền sau commit"))

    B[("DatCho")]
    I[("HangMucDatCho")]
    N[("GiaDemDatCho")]
    T[("TonPhongNgay")]
    H[("GiuTonPhongDem")]
    R[("GiaPhongNgay và dữ liệu tính giá")]
    P[("ThanhToan")]
    W[("YeuCauHuyMienPhi")]
    A[("NhatKyHeThong")]

    KH -->|"Thông tin khách mới, RequestID"| P51
    P51 <--> B
    P51 <--> I
    P51 -->|"Before/after và idempotency"| A

    KH -->|"Ngày mới"| P52
    B -->|"Booking hiện tại đủ điều kiện"| P52
    R -->|"Giá/restriction ngày mới"| P52
    T -->|"Tồn ngày mới"| P52
    P52 -->|"Chênh lệch giá và phương án mới; chưa đổi booking"| KH

    KH -->|"Xác nhận phương án đổi ngày"| P53
    P53 <-->|"Khóa booking và tồn cũ/mới theo thứ tự"| T
    P53 <-->|"Hold cũ và hold mới"| H
    P53 <--> B
    P53 <--> I
    P53 <--> N
    P53 -->|"Before/after"| A
    P53 -->|"Thành công hoặc rollback giữ nguyên booking cũ"| KH

    KH -->|"Yêu cầu xem phí hủy"| P54
    B -->|"Trạng thái và tổng tiền"| P54
    I -->|"Snapshot chính sách tại lúc đặt"| P54
    P54 -->|"Phí hủy, số hoàn, calculation snapshot"| KH

    KH -->|"Xác nhận hủy và HuyIdempotencyKey"| P55
    P55 <--> B
    P55 <-->|"Release ACTIVE → RELEASED"| H
    P55 <-->|"Giảm counter cùng transaction"| T
    P55 -->|"Phí, refund linkage"| P
    P55 -->|"Sự kiện hủy"| A
    P55 -->|"Refund command sau commit nếu cần"| P510

    KH -->|"Lý do xin miễn phí"| P56
    P56 <--> W
    DT -->|"APPROVED / REJECTED"| P56
    P56 -->|"Kết quả waiver"| KH
    P56 -->|"Nếu duyệt và hủy: lệnh hủy"| P55
    P56 -->|"Sự kiện xử lý"| A

    DT -->|"Booking CONFIRMED và bằng chứng vận hành"| P57
    P57 <--> B
    P57 <-->|"Nhả mọi hold ACTIVE"| H
    P57 <-->|"Giảm counter"| T
    P57 -->|"Phí no-show và snapshot"| B
    P57 -->|"Sự kiện no-show"| A

    DT -->|"Xác nhận kết thúc lưu trú"| P58
    P58 <--> B
    P58 <-->|"Nhả hold còn lại"| H
    P58 <--> T
    P58 -->|"Sự kiện hoàn tất"| A

    CLOCK -->|"Hết hạn thanh toán/hold"| P59
    P59 <--> B
    P59 <--> P
    P59 <--> H
    P59 <--> T
    P59 -->|"PAYMENT_FAILED và audit"| A

    P510 -->|"Refund IdempotencyKey và số tiền"| PG
    PG -->|"Kết quả refund"| P510
    P510 -->|"Trạng thái hoàn, số đã hoàn, provider reference"| P
    P510 -->|"Sự kiện hoàn tiền"| A
    P510 -->|"Kết quả cuối"| KH
```

Các bất biến của luồng:

- thất bại khi lấy tồn ngày mới không được thay đổi booking cũ;
- hủy, no-show, hoàn tất và hết hạn chỉ release một hold một lần;
- `NO_SHOW` không còn hold `ACTIVE`; phí no-show không đồng nghĩa với tiếp tục khóa phòng;
- `PAY_AT_PROPERTY` không ghi nhận tiền mặt thực thu và luôn có payment `NOT_TRACKED`;
- refund provider được gọi ngoài transaction đang khóa tồn.

## 9. DFD mức 1 — đánh giá, quản trị, báo cáo và audit

```mermaid
flowchart LR
    KH["Khách hàng"]
    DT["Đối tác"]
    AD["Quản trị viên"]

    P61(("6.1<br/>Tạo đánh giá<br/>đã xác minh"))
    P62(("6.2<br/>Xem đánh giá"))
    P71(("7.1<br/>Kiểm duyệt đánh giá"))
    P72(("7.2<br/>Báo cáo booking<br/>và doanh số gộp"))
    P73(("7.3<br/>Tỷ lệ bán trên<br/>tồn mở bán"))
    P74(("7.4<br/>Báo cáo hủy,<br/>khuyến mãi, review"))
    P75(("7.5<br/>Tra cứu audit"))

    B[("DatCho")]
    R[("DanhGia")]
    CS[("CoSoLuuTru")]
    T[("TonPhongNgay")]
    H[("GiuTonPhongDem")]
    N[("GiaDemDatCho")]
    KM[("KhuyenMai")]
    P[("ThanhToan")]
    A[("NhatKyHeThong")]

    KH -->|"Booking ID, điểm 1–10, bình luận"| P61
    B -->|"COMPLETED, đúng khách/cơ sở, trong thời hạn"| P61
    P61 -->|"Một review duy nhất cho booking"| R
    P61 -->|"Sự kiện tạo review"| A
    P61 -->|"Kết quả"| KH

    KH -->|"Yêu cầu xem đánh giá công khai"| P62
    R -->|"Review PUBLISHED"| P62
    P62 -->|"Danh sách và điểm tổng hợp"| KH

    AD -->|"Ẩn/hiện review"| P71
    P71 <--> R
    P71 -->|"Sự kiện kiểm duyệt"| A

    AD -->|"Khoảng ngày, cơ sở"| P72
    DT -->|"Khoảng ngày trong organization scope"| P72
    B --> P72
    N --> P72
    P --> P72
    CS --> P72
    P72 -->|"Số booking, giá trị đặt chỗ, tiền online ghi nhận"| AD
    P72 -->|"Báo cáo giới hạn theo tổ chức"| DT

    AD -->|"Khoảng ngày, cơ sở/loại phòng"| P73
    DT -->|"Khoảng ngày trong organization scope"| P73
    T -->|"Tổng tồn mở bán"| P73
    H -->|"Số đêm đã giữ/bán"| P73
    P73 -->|"Booked inventory / offered inventory"| AD
    P73 -->|"Tỷ lệ bán trên tồn mở bán"| DT

    AD --> P74
    DT --> P74
    B --> P74
    KM --> P74
    R --> P74
    P74 -->|"Tỷ lệ hủy, mức dùng khuyến mãi, điểm review"| AD
    P74 -->|"Báo cáo trong organization scope"| DT

    AD -->|"Actor, action, entity, thời gian"| P75
    A -->|"Dữ liệu before/after đã loại bí mật"| P75
    P75 -->|"Nhật ký append-only"| AD
```

“Tỷ lệ bán trên tồn mở bán” chỉ phản ánh capacity mà đối tác đã mở trên nền tảng, không phải công suất vật lý toàn khách sạn.

## 10. Danh mục kho dữ liệu

| Mã | Module | Bảng |
|---|---|---|
| D1.1 | Identity | `NguoiDung` |
| D1.2 | Identity | `ToChucDoiTac` |
| D1.3 | Identity | `ThanhVienDoiTac` |
| D2.1 | Properties | `TienNghi` |
| D2.2 | Properties | `CoSoLuuTru` |
| D2.3 | Properties | `LoaiPhong` |
| D2.4 | Properties | `CoSoTienNghi` |
| D2.5 | Properties | `LoaiPhongTienNghi` |
| D3.1 | Commercial | `ChinhSachHuy` |
| D3.2 | Commercial | `SanPhamPhong` |
| D3.3 | Commercial | `GiaPhongNgay` |
| D3.4 | Commercial | `KhuyenMai` |
| D4.1 | Inventory | `TonPhongNgay` |
| D4.2 | Inventory | `GiuTonPhongDem` |
| D5.1 | Bookings | `XemTruocDatCho` |
| D5.2 | Bookings | `DatCho` |
| D5.3 | Bookings | `HangMucDatCho` |
| D5.4 | Bookings | `GiaDemDatCho` |
| D5.5 | Bookings | `ThanhToan` |
| D5.6 | Bookings | `YeuCauHuyMienPhi` |
| D6.1 | Reviews | `DanhGia` |
| D7.1 | Admin | `NhatKyHeThong` |

## 11. Ma trận chức năng và tiến trình DFD

| Chức năng | Tiến trình |
|---|---|
| Đăng ký, đăng nhập, hồ sơ | 1.1–1.3 |
| Tổ chức và phân quyền đối tác | 1.4–1.6 |
| Tạo/cập nhật/duyệt cơ sở | 2.1, 2.7 |
| Quản lý loại phòng, ảnh, giường, tiện nghi | 2.2 |
| Quản lý policy và sản phẩm phòng | 2.3 |
| Giá ngày, restriction, đóng bán | 2.4 |
| Tồn mở bán | 2.5 |
| Khuyến mãi | 2.6 |
| Google enrichment/verification | 2.8 |
| Search và availability | 3.1–3.3 |
| Phân bổ nhiều phòng | 3.4 |
| Tính giá | 3.5 |
| Preview không giữ tồn | 3.6 |
| Booking trả tại cơ sở | 4.1–4.6 |
| Booking thanh toán online mô phỏng | 4.1–4.8 |
| Sửa thông tin khách | 5.1 |
| Đổi ngày an toàn | 5.2–5.3 |
| Hủy trực tiếp | 5.4–5.5 |
| Yêu cầu miễn phí hủy | 5.6 |
| No-show | 5.7 |
| Hoàn tất lưu trú | 5.8 |
| Hết hạn thanh toán/hold | 5.9 |
| Hoàn tiền | 5.10 |
| Tạo/xem/kiểm duyệt đánh giá | 6.1, 6.2, 7.1 |
| Báo cáo và audit | 7.2–7.5 |

Không có tiến trình notification center trong MVP. Kết quả thao tác được trả trực tiếp cho giao diện để hiển thị thông báo phiên; email, SMS, push và hộp thư ứng dụng thuộc giai đoạn sau.
