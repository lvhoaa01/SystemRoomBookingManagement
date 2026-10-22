# Identity

Module Identity sở hữu danh tính, đăng nhập, session, vai trò và ranh giới tổ chức.

## Bảng sở hữu

- `NguoiDung`: tài khoản, mật khẩu đã băm, vai trò và trạng thái.
- `ToChucDoiTac`: thông tin và trạng thái tổ chức.
- `ThanhVienDoiTac`: liên kết duy nhất giữa tài khoản partner và tổ chức trong MVP.

## Cấu trúc

- `Domain`: `UserAccount`, `CurrentUser`, `OrganizationContext` và các bất biến của chúng.
- `Application`: đăng ký khách hàng, đăng nhập và đăng xuất.
- `Infrastructure`: repository PDO, session identity và giới hạn thử đăng nhập.
- `Http`: controller cho form và command xác thực.

## Route đã cung cấp

```text
GET  /auth/register
POST /auth/register
GET  /auth/login
POST /auth/login
POST /auth/logout
```

Mọi route POST đều qua CSRF. Logout yêu cầu phiên đã xác thực.

## Quy tắc an toàn

- Mật khẩu thô không được lưu; đăng ký dùng `password_hash()` và đăng nhập dùng `password_verify()`.
- Hash chỉ nằm trong `UserAccount` nội bộ, không có trong `CurrentUser`, session hoặc view.
- Session ID và CSRF token được đổi sau đăng nhập.
- Lỗi sai email, sai mật khẩu và tài khoản bị khóa dùng cùng thông báo.
- Thử đăng nhập bị giới hạn theo định danh và IP trong session.
- Organization context của partner chỉ được đọc từ membership trong database.
- Partner không có membership hoặc tổ chức không hoạt động bị middleware từ chối.
- Mọi truy vấn nghiệp vụ đối tác về sau phải nhận `ToChucDoiTacID` từ context máy chủ; không tin ID từ route, query hoặc form.

Phần màn hình quản trị khóa/mở tài khoản và audit tương ứng thuộc giai đoạn Admin; repository đã cung cấp primitive cập nhật trạng thái để dùng ở giai đoạn đó.
