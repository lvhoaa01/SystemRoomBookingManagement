# Accommodation Booking

> **Bản nháp:** README này chỉ giới thiệu đặc tả tổng quan của dự án. Nội dung sẽ được hoàn thiện sau khi toàn bộ lộ trình phát triển và nghiệm thu kết thúc.

## Giới thiệu

Accommodation Booking là hệ thống Mini OTA hỗ trợ tìm kiếm và đặt chỗ lưu trú đa cơ sở tại Thành phố Hồ Chí Minh. Hệ thống hướng đến việc kết nối khách hàng với các cơ sở lưu trú, đồng thời cung cấp công cụ quản trị cho đối tác và quản trị viên nền tảng.

## Mục tiêu

- Cho phép khách hàng tìm kiếm cơ sở lưu trú theo thời gian ở, số phòng và nhóm khách.
- Hiển thị giá, chính sách, tình trạng phòng và thời điểm thanh toán nhất quán.
- Hỗ trợ đặt chỗ trả trực tuyến hoặc trả tại cơ sở theo một phương thức thuần nhất cho mỗi booking.
- Giúp đối tác quản lý cơ sở, loại phòng, sản phẩm phòng, giá, tồn và chính sách.
- Giúp quản trị viên duyệt cơ sở, quản lý tài khoản, theo dõi audit và báo cáo vận hành.

## Vai trò người dùng

- **Khách hàng:** tìm kiếm, xem chi tiết, đặt chỗ, thanh toán, hủy và đánh giá.
- **Đối tác:** quản lý dữ liệu lưu trú trong phạm vi tổ chức của mình.
- **Quản trị viên:** kiểm duyệt, giám sát và vận hành nền tảng.

## Phạm vi chức năng dự kiến

1. Đăng ký, đăng nhập và phân quyền theo vai trò.
2. Quản lý cơ sở lưu trú, loại phòng, tiện nghi và hình ảnh.
3. Quản lý sản phẩm phòng, giá theo ngày, khuyến mại và chính sách hủy.
4. Quản lý tồn phòng an toàn khi có nhiều yêu cầu đồng thời.
5. Tìm kiếm, phân bổ phòng và tính giá xác định.
6. Xem trước booking, đặt chỗ và thanh toán mô phỏng.
7. Quản lý booking, hủy, hoàn tiền, đổi ngày và no-show.
8. Đánh giá sau lưu trú, audit và báo cáo quản trị.

## Nguyên tắc nghiệp vụ cốt lõi

- Tồn phòng được quản lý theo loại phòng và ngày lưu trú.
- Một booking chỉ sử dụng một thời điểm thanh toán: `PAY_ONLINE` hoặc `PAY_AT_PROPERTY`.
- Giá và chính sách của booking được lưu snapshot để không bị thay đổi theo dữ liệu hiện tại.
- Các thao tác giữ và trả tồn phải bảo đảm transaction, idempotency và không vượt bán.
- Dữ liệu của đối tác phải luôn được giới hạn theo phạm vi tổ chức.
- Tiền tệ của MVP là VND và không được tính bằng số thực `float`.

## Kiến trúc định hướng

Dự án sử dụng PHP 8.2+, MySQL/MariaDB và kiến trúc Modular Monolith. Mỗi module sở hữu nghiệp vụ riêng; các primitive và contract thực sự dùng chung được đặt trong Shared Kernel.

Các module dự kiến gồm Identity, Properties, Commercial, Inventory, Search, Bookings, Reviews và Admin.

## Trạng thái

Dự án đang trong quá trình phát triển. Các chức năng, giao diện và tài liệu vận hành chưa phải phiên bản hoàn chỉnh.
