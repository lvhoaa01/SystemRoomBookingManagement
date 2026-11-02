# Reviews

Sở hữu `DanhGia` và kiểm duyệt đánh giá đã xác minh.

- Customer chỉ tạo review cho booking của chính mình ở trạng thái `COMPLETED`,
  từ ngày checkout đến hết ngày thứ 90 theo múi giờ cơ sở.
- Transaction khóa booking trước khi kiểm unique; mỗi booking tối đa một review.
- Review mới ở trạng thái `PUBLISHED`; search/detail chỉ tính điểm và số lượng
  từ các dòng đang public.
- Admin chỉ chuyển `PUBLISHED ↔ HIDDEN` và ghi audit, không có API sửa điểm hay
  bình luận thay khách.
- `ReviewRequest` kiểm điểm tổng và từng tiêu chí trong khoảng 1–10, bình luận
  tùy chọn tối đa 3.000 ký tự.
