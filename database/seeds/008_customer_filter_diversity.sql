-- Đa dạng hóa các bộ lọc công khai mà không thay đổi booking lịch sử.
USE hotel_booking;
SET NAMES utf8mb4;
START TRANSACTION;

-- Phân bố sao tổng hợp phục vụ môi trường phát triển. Tên cơ sở vẫn lấy từ
-- nguồn công khai; số sao dưới đây không được xem là dữ liệu xếp hạng chính thức.
UPDATE CoSoLuuTru
SET SoSao = CASE
      WHEN MOD(CoSoLuuTruID, 4) = 0 THEN 3
      WHEN MOD(CoSoLuuTruID, 2) = 0 THEN 4
      ELSE 5
    END,
    CapNhatLuc = '2026-11-06 13:00:00.000000'
WHERE CoSoLuuTruID BETWEEN 1 AND 30;

-- Mở rộng điểm trung bình theo từng cơ sở và thay đổi tỷ lệ review công khai.
-- Review vẫn giữ nguyên booking/customer tham chiếu và không thay đổi số dòng.
UPDATE DanhGia
SET DiemTong = LEAST(10, GREATEST(1,
      CASE MOD(CoSoLuuTruID, 5)
        WHEN 0 THEN 7
        WHEN 1 THEN 8
        WHEN 2 THEN 8
        WHEN 3 THEN 9
        ELSE 10
      END
      + CASE MOD(DanhGiaID, 5) WHEN 0 THEN -1 WHEN 4 THEN 1 ELSE 0 END
    )),
    TrangThai = CASE
      WHEN MOD(DanhGiaID + CoSoLuuTruID, 10) < MOD(CoSoLuuTruID, 4) THEN 'HIDDEN'
      ELSE 'PUBLISHED'
    END;

-- Chính sách linh hoạt có nhiều cửa sổ hủy khác nhau. Một số cơ sở không có
-- khoảng hủy miễn phí nhưng sản phẩm vẫn được bán bình thường.
UPDATE ChinhSachHuy
SET SoGioHuyMienPhi = CASE
      WHEN LoaiChinhSach = 'NON_REFUNDABLE' THEN NULL
      WHEN MOD(CoSoLuuTruID, 5) = 0 THEN 0
      WHEN MaChinhSach = 'FLEX_1D' THEN 12 + (MOD(CoSoLuuTruID, 2) * 12)
      ELSE 36 + (MOD(CoSoLuuTruID, 3) * 12)
    END,
    TenChinhSach = CASE
      WHEN LoaiChinhSach = 'NON_REFUNDABLE' THEN 'Không hoàn tiền'
      WHEN MOD(CoSoLuuTruID, 5) = 0 THEN 'Linh hoạt có phí từ khi đặt'
      WHEN MaChinhSach = 'FLEX_1D' THEN CONCAT('Hủy miễn phí trước ', 12 + (MOD(CoSoLuuTruID, 2) * 12), ' giờ')
      ELSE CONCAT('Hủy miễn phí trước ', 36 + (MOD(CoSoLuuTruID, 3) * 12), ' giờ')
    END;

-- PAY_AT_PROPERTY và ROOM_ONLY luôn còn lựa chọn nền. PAY_ONLINE và bữa sáng
-- chỉ xuất hiện ở một phần cơ sở để các bộ lọc tạo ra kết quả khác nhau.
UPDATE SanPhamPhong product
INNER JOIN LoaiPhong room_type ON room_type.LoaiPhongID = product.LoaiPhongID
SET product.TrangThai = CASE
      WHEN product.ThoiDiemThanhToan = 'PAY_ONLINE'
        AND MOD(room_type.CoSoLuuTruID, 3) = 0 THEN 'INACTIVE'
      WHEN product.LoaiBuaAn = 'BREAKFAST_INCLUDED'
        AND room_type.CoSoLuuTruID BETWEEN 21 AND 27 THEN 'INACTIVE'
      ELSE 'ACTIVE'
    END;

-- Hai chương trình khuyến mãi của mỗi cơ sở được tách thành ưu đãi nền và ưu
-- đãi theo mùa, với mức giảm và phạm vi áp dụng khác nhau.
UPDATE KhuyenMai
SET PhanTramGiam = CASE
      WHEN MOD(KhuyenMaiID, 2) = 1 THEN 5 + (MOD(CoSoLuuTruID, 6) * 2)
      ELSE 10 + (MOD(CoSoLuuTruID, 4) * 3)
    END,
    TenKhuyenMai = CASE
      WHEN MOD(KhuyenMaiID, 2) = 1 THEN 'Ưu đãi thành viên'
      ELSE 'Ưu đãi theo mùa'
    END,
    DatTu = CASE
      WHEN MOD(KhuyenMaiID, 2) = 1 THEN '2026-10-01 00:00:00.000000'
      ELSE '2026-11-15 00:00:00.000000'
    END,
    DatDen = CASE
      WHEN MOD(KhuyenMaiID, 2) = 1 THEN '2027-03-15 23:59:59.000000'
      ELSE '2027-01-15 23:59:59.000000'
    END,
    LuuTruTu = CASE
      WHEN MOD(KhuyenMaiID, 2) = 1 THEN '2026-10-05'
      ELSE '2026-12-01'
    END,
    LuuTruDen = CASE
      WHEN MOD(KhuyenMaiID, 2) = 1 THEN '2027-03-31'
      ELSE '2027-01-31'
    END,
    TrangThai = CASE
      WHEN MOD(KhuyenMaiID, 2) = 1 OR MOD(CoSoLuuTruID, 3) = 0 THEN 'ACTIVE'
      ELSE 'INACTIVE'
    END;

COMMIT;
