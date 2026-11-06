-- Làm tồn phòng và điều kiện bán phản ánh rõ booking/ledger hiện hữu.
USE hotel_booking;
SET NAMES utf8mb4;
START TRANSACTION;

-- SoLuongDaGiu tiếp tục do ledger quyết định. Seed chỉ thu hẹp số phòng còn lại:
-- dòng đã có booking chỉ còn 0-2 phòng; dòng chưa có booking còn 1-4 phòng.
-- Vì vậy các dòng sold-out do booking luôn có TongSoLuong = SoLuongDaGiu.
UPDATE TonPhongNgay inventory
INNER JOIN LoaiPhong room_type ON room_type.LoaiPhongID = inventory.LoaiPhongID
SET inventory.TongSoLuong = inventory.SoLuongDaGiu + CASE
      WHEN room_type.CoSoLuuTruID BETWEEN 25 AND 27
        AND room_type.MaLoaiPhong = 'FAM' THEN 5
      WHEN inventory.SoLuongDaGiu > 0 THEN MOD(
        room_type.CoSoLuuTruID + room_type.LoaiPhongID + DAYOFYEAR(inventory.NgayLuuTru),
        3
      )
      ELSE 1 + MOD(
        (room_type.CoSoLuuTruID * 3) + room_type.LoaiPhongID + DAYOFYEAR(inventory.NgayLuuTru),
        4
      )
    END,
    inventory.PhienBan = inventory.PhienBan + 1,
    inventory.CapNhatLuc = '2026-11-06 13:00:00.000000'
WHERE room_type.CoSoLuuTruID BETWEEN 1 AND 27
  AND room_type.TrangThai = 'ACTIVE';

-- Đóng bán theo cấp cơ sở/ngày và cấp sản phẩm/ngày. Điều kiện được tính toán
-- ổn định để fixture tái lập, đồng thời không đóng phần lớn thị trường cùng lúc.
UPDATE GiaPhongNgay rate_day
INNER JOIN SanPhamPhong product ON product.SanPhamPhongID = rate_day.SanPhamPhongID
INNER JOIN LoaiPhong room_type ON room_type.LoaiPhongID = product.LoaiPhongID
SET rate_day.DongBan = CASE
      WHEN MOD((room_type.CoSoLuuTruID * 11) + DAYOFYEAR(rate_day.NgayLuuTru), 19) = 0 THEN 1
      WHEN MOD((product.SanPhamPhongID * 7) + DAYOFYEAR(rate_day.NgayLuuTru), 31) = 0 THEN 1
      ELSE 0
    END,
    rate_day.SoDemToiThieu = CASE
      WHEN DAYOFWEEK(rate_day.NgayLuuTru) IN (1, 7)
        AND MOD(room_type.CoSoLuuTruID, 5) = 0 THEN 2
      WHEN MONTH(rate_day.NgayLuuTru) = 12
        AND DAY(rate_day.NgayLuuTru) BETWEEN 24 AND 31
        AND MOD(room_type.CoSoLuuTruID, 7) = 0 THEN 3
      ELSE 1
    END,
    rate_day.SoDemToiDa = CASE
      WHEN MOD(room_type.CoSoLuuTruID, 4) = 0 THEN 5
      WHEN MOD(room_type.CoSoLuuTruID, 4) = 1 THEN 7
      WHEN MOD(room_type.CoSoLuuTruID, 4) = 2 THEN 10
      ELSE 14
    END,
    rate_day.SoNgayDatTruoc = CASE MOD(room_type.CoSoLuuTruID, 4)
      WHEN 0 THEN 0
      WHEN 1 THEN 1
      WHEN 2 THEN 3
      ELSE 7
    END,
    rate_day.PhienBan = rate_day.PhienBan + 1,
    rate_day.CapNhatLuc = '2026-11-06 13:00:00.000000'
WHERE room_type.CoSoLuuTruID BETWEEN 1 AND 27;

COMMIT;
