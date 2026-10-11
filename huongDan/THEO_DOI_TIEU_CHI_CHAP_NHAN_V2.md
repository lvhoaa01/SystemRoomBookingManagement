# THEO DÕI TIÊU CHÍ CHẤP NHẬN MINI OTA V2

**Nguồn:** mục 27 `DESCRIPTION_HOTEL_BOOKING_V2.md`  
**Trạng thái cho phép:** `NOT_STARTED`, `IN_PROGRESS`, `PASSED`  
**Nguyên tắc:** chỉ chuyển sang `PASSED` khi có test tự động hoặc biên bản kiểm tra lặp lại được. Quan sát giao diện đơn lẻ không phải bằng chứng hoàn thành.

## Tài khoản, đối tác và cơ sở

| # | Tiêu chí rút gọn | Trạng thái | Bằng chứng |
|---:|---|---|---|
| 1 | Khách vãng lai tìm/xem nhưng không tạo preview máy chủ | NOT_STARTED | — |
| 2 | Đối tác thiếu membership không quản lý cơ sở | NOT_STARTED | — |
| 3 | Cách ly dữ liệu giữa các tổ chức đối tác | NOT_STARTED | — |
| 4 | Cơ sở thiếu readiness không gửi duyệt | NOT_STARTED | — |
| 5 | Trả về sửa dùng `PENDING_REVIEW → DRAFT` | NOT_STARTED | — |
| 6 | Từ chối dứt điểm dùng `REJECTED` và đủ metadata | NOT_STARTED | — |
| 7 | Chỉ cơ sở/tổ chức hoạt động xuất hiện công khai | NOT_STARTED | — |

## Tìm kiếm, chi tiết và preview

| # | Tiêu chí rút gọn | Trạng thái | Bằng chứng |
|---:|---|---|---|
| 8 | Search kiểm tra ngày, số phòng và khách | NOT_STARTED | — |
| 9 | Kết quả có phân bổ hợp lệ cho mọi đêm | NOT_STARTED | — |
| 10 | Không tự ý bỏ hoặc đổi khách | NOT_STARTED | — |
| 11 | Giá/timing search khớp mặc định detail | NOT_STARTED | — |
| 12 | Allocation thuần timing và xác định | NOT_STARTED | — |
| 13 | Search cũ không là quyết định cuối | NOT_STARTED | — |
| 14 | Preview đọc lại giá, hạn chế và tồn | NOT_STARTED | — |
| 15 | Preview hiển thị đủ tổng, timing và chính sách | NOT_STARTED | — |
| 16 | Preview tối đa 15 phút, một lần, không giữ tồn | NOT_STARTED | — |
| 17 | Preview không hợp lệ không tạo booking | NOT_STARTED | — |

## Booking, tồn và lịch sử

| # | Tiêu chí rút gọn | Trạng thái | Bằng chứng |
|---:|---|---|---|
| 18 | Booking nhiều phòng chỉ thuộc một cơ sở | NOT_STARTED | — |
| 19 | Giữ đủ mọi phòng/đêm hoặc rollback | NOT_STARTED | — |
| 20 | Tranh unit cuối chỉ một request thắng | NOT_STARTED | — |
| 21 | Retry cùng idempotency key trả booking cũ | NOT_STARTED | — |
| 22 | Trùng key khác chủ thể/payload trả 409 an toàn | NOT_STARTED | — |
| 23 | Counter tồn hợp lệ và khớp ledger | NOT_STARTED | — |
| 24 | Chỉ booking đang hoạt động giữ tồn | NOT_STARTED | — |
| 25 | Snapshot lịch sử không đổi theo catalog hiện tại | NOT_STARTED | — |

## Thanh toán

| # | Tiêu chí rút gọn | Trạng thái | Bằng chứng |
|---:|---|---|---|
| 26 | Trả tại cơ sở tạo `CONFIRMED/NOT_TRACKED` | NOT_STARTED | — |
| 27 | Booking trả tại cơ sở hoàn tất với `NOT_TRACKED` | NOT_STARTED | — |
| 28 | Phí tại cơ sở không bị coi là đã thu | NOT_STARTED | — |
| 29 | Online trả toàn bộ ngay, không phí tại cơ sở | NOT_STARTED | — |
| 30 | Online chỉ xác nhận khi `PAID + CONFIRMED` | NOT_STARTED | — |
| 31 | Không gọi provider khi giữ khóa tồn | NOT_STARTED | — |
| 32 | Retry payment không tạo dữ liệu trùng | NOT_STARTED | — |
| 33 | Payment thất bại/hết hạn trả tồn đúng một lần | NOT_STARTED | — |
| 34 | Late success không hồi sinh booking thất bại | NOT_STARTED | — |

## Hủy, miễn phí hủy, đổi ngày và no-show

| # | Tiêu chí rút gọn | Trạng thái | Bằng chứng |
|---:|---|---|---|
| 35 | Phí hủy dùng snapshot và đúng múi giờ | NOT_STARTED | — |
| 36 | Hủy lưu đủ metadata và snapshot | NOT_STARTED | — |
| 37 | Số tiền hoàn chỉ tăng sau refund thành công | NOT_STARTED | — |
| 38 | Retry hủy không trả tồn/hoàn tiền hai lần | NOT_STARTED | — |
| 39 | Refund không vượt tiền đã thu | NOT_STARTED | — |
| 40 | `CANCELLED` không đồng nghĩa refund hoàn tất | NOT_STARTED | — |
| 41 | Waiver chờ duyệt không đổi booking/tồn | NOT_STARTED | — |
| 42 | Chỉ đúng tổ chức xử lý waiver/no-show | NOT_STARTED | — |
| 43 | Preview đổi ngày không giữ tồn | NOT_STARTED | — |
| 44 | Đổi ngày overlap thực hiện trong một transaction | NOT_STARTED | — |
| 45 | Đổi ngày thất bại giữ nguyên booking cũ | NOT_STARTED | — |
| 46 | Retry đổi ngày không thực thi lần hai | NOT_STARTED | — |
| 47 | No-show toàn booking và trả mọi hold | NOT_STARTED | — |
| 48 | No-show không vi phạm invariant tồn | NOT_STARTED | — |
| 49 | Tác vụ hoàn tất chạy lặp an toàn | NOT_STARTED | — |

## Đánh giá, dữ liệu và báo cáo

| # | Tiêu chí rút gọn | Trạng thái | Bằng chứng |
|---:|---|---|---|
| 50 | Chỉ đúng khách/booking/khoảng 90 ngày được đánh giá | NOT_STARTED | — |
| 51 | Một booking một review; no-show không review | NOT_STARTED | — |
| 52 | Chỉ review công khai được tính điểm | NOT_STARTED | — |
| 53 | Google đúng provenance, không là master data sai quyền | NOT_STARTED | — |
| 54 | Báo cáo gọi đúng tỷ lệ bán trên tồn mở bán | NOT_STARTED | — |
| 55 | Báo cáo tách giá trị, tiền online và phí đánh giá | NOT_STARTED | — |
| 56 | Schema đúng 22 bảng, không tham chiếu bảng ngoài danh sách | PASSED | Migration và SQL validator ngày 11/10/2026 |
| 57 | Import/seed/validator chạy và mọi invariant bằng 0 | PASSED | PHP validator và 33 SQL checks ngày 11/10/2026 |
| 58 | Fixture online không có phí trả tại cơ sở | PASSED | Check `pay_online_nightly_at_property_nonzero = 0` |
| 59 | `EXPLAIN` phù hợp fixture mục tiêu | NOT_STARTED | — |
| 60 | DESCRIPTION/DESIGN thống nhất trạng thái, bảng và transaction | PASSED | Audit V2.2 và sơ đồ đã đồng bộ ngày 11/10/2026 |

## Quy tắc cập nhật

1. Khi bắt đầu một use case, đổi đúng các tiêu chí liên quan sang `IN_PROGRESS`.
2. Ghi đường dẫn test, tên test hoặc biên bản kiểm tra vào cột bằng chứng.
3. Nếu một thay đổi làm test hồi quy thất bại, hạ tiêu chí về `IN_PROGRESS`.
4. Không sửa câu đầy đủ tại DESCRIPTION mục 27 thông qua bảng này; bảng chỉ là bản rút gọn để theo dõi.
