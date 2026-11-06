-- Đa dạng hóa sức chứa và tồn phòng phục vụ các kịch bản tìm kiếm/demo.
-- Seed này chỉ cập nhật dữ liệu danh mục hiện có, không đổi khóa chính hay số dòng.
USE hotel_booking;
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TEMPORARY TABLE SeedRoomProfile (
  NhomTu SMALLINT UNSIGNED NOT NULL,
  NhomDen SMALLINT UNSIGNED NOT NULL,
  MaLoaiPhong VARCHAR(24) NOT NULL,
  TenLoaiPhong VARCHAR(150) NOT NULL,
  MoTa VARCHAR(255) NOT NULL,
  SoKhachToiDa SMALLINT UNSIGNED NOT NULL,
  SoNguoiLonToiDa SMALLINT UNSIGNED NOT NULL,
  SoTreEmToiDa SMALLINT UNSIGNED NOT NULL,
  CauHinhGiuongJSON JSON NOT NULL,
  DienTichM2 DECIMAL(7,2) NOT NULL,
  TonPhongCoBan SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (NhomTu, NhomDen, MaLoaiPhong)
);

INSERT INTO SeedRoomProfile (
  NhomTu, NhomDen, MaLoaiPhong, TenLoaiPhong, MoTa,
  SoKhachToiDa, SoNguoiLonToiDa, SoTreEmToiDa,
  CauHinhGiuongJSON, DienTichM2, TonPhongCoBan
) VALUES
  -- Cơ sở 1-3: phân khúc khách đi một mình.
  (1, 3, 'STD', 'Phòng đơn tiêu chuẩn', 'Phòng gọn nhẹ dành cho một khách đi công tác hoặc lưu trú ngắn ngày.', 1, 1, 0, '[{"type":"SINGLE","quantity":1}]', 18, 3),

  -- Cơ sở 4-8: phân khúc cặp đôi và khách công tác, không nhận thêm trẻ em.
  (4, 8, 'STD', 'Phòng đôi tiêu chuẩn', 'Phòng đôi dành cho tối đa hai người lớn.', 2, 2, 0, '[{"type":"DOUBLE","quantity":1}]', 24, 5),
  (4, 8, 'DLX', 'Phòng Twin công tác', 'Phòng hai giường đơn phù hợp đồng nghiệp hoặc bạn bè.', 2, 2, 0, '[{"type":"SINGLE","quantity":2}]', 28, 3),

  -- Cơ sở 9-14: phân khúc gia đình nhỏ, tối đa một trẻ em.
  (9, 14, 'STD', 'Phòng đôi tiêu chuẩn', 'Phòng đôi dành cho tối đa hai người lớn.', 2, 2, 0, '[{"type":"DOUBLE","quantity":1}]', 24, 6),
  (9, 14, 'DLX', 'Phòng gia đình nhỏ', 'Phòng dành cho hai người lớn và một trẻ em.', 3, 2, 1, '[{"type":"DOUBLE","quantity":1},{"type":"SINGLE","quantity":1}]', 31, 4),
  (9, 14, 'STE', 'Suite gia đình nhỏ', 'Suite có giường sofa, phù hợp gia đình có một trẻ em.', 3, 2, 1, '[{"type":"KING","quantity":1},{"type":"SOFA_BED","quantity":1}]', 40, 2),

  -- Cơ sở 15-20: phân khúc gia đình tiêu chuẩn.
  (15, 20, 'STD', 'Phòng đôi tiêu chuẩn', 'Phòng đôi dành cho tối đa hai người lớn.', 2, 2, 0, '[{"type":"DOUBLE","quantity":1}]', 24, 7),
  (15, 20, 'DLX', 'Phòng Deluxe ba khách', 'Phòng linh hoạt cho hai người lớn và một trẻ em.', 3, 2, 1, '[{"type":"DOUBLE","quantity":1},{"type":"SINGLE","quantity":1}]', 32, 5),
  (15, 20, 'FAM', 'Phòng gia đình', 'Phòng dành cho hai người lớn và tối đa hai trẻ em.', 4, 2, 2, '[{"type":"DOUBLE","quantity":1},{"type":"SINGLE","quantity":2}]', 42, 3),
  (15, 20, 'STE', 'Suite ba người lớn', 'Suite phù hợp ba người lớn hoặc gia đình có một trẻ em.', 4, 3, 1, '[{"type":"KING","quantity":1},{"type":"SOFA_BED","quantity":1}]', 48, 2),

  -- Cơ sở 21-24: phân khúc gia đình lớn và nhóm bốn người lớn.
  (21, 24, 'STD', 'Phòng đôi tiêu chuẩn', 'Phòng đôi dành cho tối đa hai người lớn.', 2, 2, 0, '[{"type":"DOUBLE","quantity":1}]', 25, 6),
  (21, 24, 'DLX', 'Phòng Deluxe ba khách', 'Phòng linh hoạt cho hai người lớn và một trẻ em.', 3, 2, 1, '[{"type":"DOUBLE","quantity":1},{"type":"SINGLE","quantity":1}]', 33, 4),
  (21, 24, 'FAM', 'Phòng gia đình lớn', 'Phòng rộng cho tối đa bốn người lớn và hai trẻ em.', 6, 4, 2, '[{"type":"DOUBLE","quantity":2},{"type":"SINGLE","quantity":2}]', 52, 3),
  (21, 24, 'STE', 'Suite nhóm bốn khách', 'Suite dành cho nhóm tối đa bốn người lớn.', 4, 4, 0, '[{"type":"DOUBLE","quantity":2}]', 50, 2),

  -- Cơ sở 25-27: phân khúc nhóm lớn, vẫn giữ lựa chọn phòng đôi phổ thông.
  (25, 27, 'STD', 'Phòng đôi tiêu chuẩn', 'Phòng đôi dành cho tối đa hai người lớn.', 2, 2, 0, '[{"type":"DOUBLE","quantity":1}]', 26, 5),
  (25, 27, 'DLX', 'Phòng Deluxe ba khách', 'Phòng linh hoạt cho hai người lớn và một trẻ em.', 3, 2, 1, '[{"type":"DOUBLE","quantity":1},{"type":"SINGLE","quantity":1}]', 34, 4),
  (25, 27, 'FAM', 'Phòng liên thông nhóm lớn', 'Cụm phòng liên thông cho nhóm lớn hoặc gia đình nhiều thế hệ.', 9, 6, 3, '[{"type":"DOUBLE","quantity":3},{"type":"SINGLE","quantity":2}]', 72, 2),
  (25, 27, 'STE', 'Suite nhóm sáu khách', 'Suite lớn phù hợp nhóm bạn hoặc gia đình đông người.', 7, 5, 2, '[{"type":"KING","quantity":2},{"type":"SINGLE","quantity":2},{"type":"SOFA_BED","quantity":1}]', 68, 2);

-- Các loại phòng không thuộc hồ sơ của phân khúc 1-14 được giữ để bảo toàn
-- lịch sử đặt chỗ nhưng không còn xuất hiện trong danh mục tìm kiếm mới.
UPDATE LoaiPhong
SET TrangThai = 'INACTIVE',
    CapNhatLuc = '2026-11-06 12:00:00.000000'
WHERE CoSoLuuTruID BETWEEN 1 AND 14;

UPDATE LoaiPhong lp
INNER JOIN SeedRoomProfile profile
  ON lp.CoSoLuuTruID BETWEEN profile.NhomTu AND profile.NhomDen
 AND lp.MaLoaiPhong = profile.MaLoaiPhong
SET lp.TenLoaiPhong = profile.TenLoaiPhong,
    lp.MoTa = profile.MoTa,
    lp.SoKhachToiDa = profile.SoKhachToiDa,
    lp.SoNguoiLonToiDa = profile.SoNguoiLonToiDa,
    lp.SoTreEmToiDa = profile.SoTreEmToiDa,
    lp.CauHinhGiuongJSON = profile.CauHinhGiuongJSON,
    lp.DienTichM2 = profile.DienTichM2,
    lp.TrangThai = 'ACTIVE',
    lp.CapNhatLuc = '2026-11-06 12:00:00.000000';

-- Tồn phòng thay đổi theo phân khúc, cơ sở và cuối tuần. GREATEST bảo đảm
-- tổng tồn không bao giờ thấp hơn số phòng đã được giữ bởi dữ liệu vận hành.
UPDATE TonPhongNgay inventory
INNER JOIN LoaiPhong room_type ON room_type.LoaiPhongID = inventory.LoaiPhongID
INNER JOIN SeedRoomProfile profile
  ON room_type.CoSoLuuTruID BETWEEN profile.NhomTu AND profile.NhomDen
 AND room_type.MaLoaiPhong = profile.MaLoaiPhong
SET inventory.TongSoLuong = GREATEST(
      inventory.SoLuongDaGiu,
      profile.TonPhongCoBan
        + MOD(room_type.CoSoLuuTruID, 3)
        - IF(DAYOFWEEK(inventory.NgayLuuTru) IN (1, 7), 1, 0)
    ),
    inventory.PhienBan = inventory.PhienBan + 1,
    inventory.CapNhatLuc = '2026-11-06 12:00:00.000000';

DROP TEMPORARY TABLE SeedRoomProfile;
COMMIT;
