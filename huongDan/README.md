# Tài liệu dự án

Hai tài liệu chuẩn hiện hành của hệ thống tìm kiếm và đặt chỗ lưu trú là:

- `DESCRIPTION_HOTEL_BOOKING_V2.md`: đặc tả nghiệp vụ và tiêu chí chấp nhận đã đối chiếu luồng công khai của Booking.com;
- `DESIGN_HOTEL_BOOKING_V2.md`: thiết kế kỹ thuật, module, ranh giới dữ liệu và transaction tương ứng;
- `DFD_HOTEL_BOOKING.md`: sơ đồ luồng dữ liệu mức ngữ cảnh, mức 0 và mức 1 bằng Mermaid;
- `SEQUENCE_DIAGRAM_HOTEL_BOOKING.md`: sơ đồ tuần tự cho các use case và transaction chính bằng Mermaid.
- `THEO_DOI_TIEU_CHI_CHAP_NHAN_V2.md`: bảng trạng thái và bằng chứng cho 60 tiêu chí chấp nhận;
- `BASELINE_GIAI_DOAN_0.md`: biên bản kiểm tra database, môi trường và tài liệu khi khóa baseline;
- `majestic_hotel_image_pack_v2/`: hồ sơ nguồn, giấy phép, ánh xạ và script tải ảnh cho seed; ảnh Majestic chỉ áp dụng cho `CS016`, các bản ghi còn lại dùng ảnh minh họa trung tính.
- `LO_TRINH_PHAT_TRIEN_THU_CONG_HOTEL_BOOKING_V2.md`: lộ trình tự phát triển toàn bộ hệ thống theo thứ tự phụ thuộc, test và tiêu chí hoàn thành của V2.2.

Hai file `*_DRAFT.md` của Hotel Booking là bản trước V2, chỉ giữ để truy vết quyết định. DFD và sequence diagram hiện đã được chốt lại theo V2.2; nếu phát sinh khác biệt mới thì DESCRIPTION V2 vẫn là nguồn ưu tiên.

`DESCRIPTION_ROOM_BOOKING_DRAFT.md` và `DESIGN_ROOM_BOOKING_DRAFT.md` mô tả hệ thống đặt phòng học trước đây. Chúng chỉ được giữ làm tài liệu lịch sử để tham khảo phong cách kiến trúc, không phải yêu cầu của hệ thống hiện tại và không được dùng làm nguồn triển khai.

Thứ tự ưu tiên khi có mâu thuẫn: `DESCRIPTION_HOTEL_BOOKING_V2.md` → `DESIGN_HOTEL_BOOKING_V2.md` → mã/schema đã được kiểm thử → sơ đồ giải thích → tài liệu lịch sử.
