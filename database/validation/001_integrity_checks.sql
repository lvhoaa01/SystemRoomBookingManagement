USE hotel_booking;

-- Mọi dòng kết quả phải có Violations = 0.
SELECT 'schema_table_count_not_22' AS CheckName, ABS(COUNT(*) - 22) AS Violations
FROM information_schema.tables
WHERE table_schema = 'hotel_booking' AND table_type = 'BASE TABLE'
UNION ALL
SELECT 'inventory_negative_or_oversold', COUNT(*) FROM TonPhongNgay WHERE SoLuongDaGiu > TongSoLuong
UNION ALL
SELECT 'inventory_counter_ledger_mismatch', COUNT(*)
FROM TonPhongNgay t
LEFT JOIN (
  SELECT LoaiPhongID, NgayLuuTru, COUNT(*) AS ActiveCount
  FROM GiuTonPhongDem WHERE TrangThai = 'ACTIVE' GROUP BY LoaiPhongID, NgayLuuTru
) l ON l.LoaiPhongID = t.LoaiPhongID AND l.NgayLuuTru = t.NgayLuuTru
WHERE t.SoLuongDaGiu <> COALESCE(l.ActiveCount, 0)
UNION ALL
SELECT 'active_ledger_without_inventory', COUNT(*)
FROM GiuTonPhongDem l
LEFT JOIN TonPhongNgay t ON t.LoaiPhongID=l.LoaiPhongID AND t.NgayLuuTru=l.NgayLuuTru
WHERE l.TrangThai='ACTIVE' AND t.LoaiPhongID IS NULL
UNION ALL
SELECT 'active_ledger_bad_booking_state', COUNT(*)
FROM GiuTonPhongDem l
JOIN HangMucDatCho i ON i.HangMucDatChoID=l.HangMucDatChoID
JOIN DatCho b ON b.DatChoID=i.DatChoID
WHERE l.TrangThai='ACTIVE' AND b.TrangThai NOT IN ('PENDING_PAYMENT','CONFIRMED')
UNION ALL
SELECT 'terminal_booking_has_active_ledger', COUNT(*)
FROM GiuTonPhongDem l
JOIN HangMucDatCho i ON i.HangMucDatChoID=l.HangMucDatChoID
JOIN DatCho b ON b.DatChoID=i.DatChoID
WHERE l.TrangThai='ACTIVE' AND b.TrangThai IN ('CANCELLED','PAYMENT_FAILED','NO_SHOW','COMPLETED')
UNION ALL
SELECT 'released_ledger_missing_release_data', COUNT(*)
FROM GiuTonPhongDem WHERE TrangThai='RELEASED' AND (NhaLuc IS NULL OR LyDoNha IS NULL)
UNION ALL
SELECT 'ledger_outside_stay_or_wrong_room', COUNT(*)
FROM GiuTonPhongDem l
JOIN HangMucDatCho i ON i.HangMucDatChoID=l.HangMucDatChoID
JOIN DatCho b ON b.DatChoID=i.DatChoID
WHERE l.LoaiPhongID <> i.LoaiPhongID OR l.NgayLuuTru < b.NgayNhanPhong OR l.NgayLuuTru >= b.NgayTraPhong
UNION ALL
SELECT 'booking_item_property_mismatch', COUNT(*)
FROM HangMucDatCho i
JOIN DatCho b ON b.DatChoID=i.DatChoID
JOIN LoaiPhong r ON r.LoaiPhongID=i.LoaiPhongID
JOIN SanPhamPhong p ON p.SanPhamPhongID=i.SanPhamPhongID
JOIN LoaiPhong pr ON pr.LoaiPhongID=p.LoaiPhongID
WHERE r.CoSoLuuTruID <> b.CoSoLuuTruID OR pr.CoSoLuuTruID <> b.CoSoLuuTruID OR p.LoaiPhongID <> i.LoaiPhongID
UNION ALL
SELECT 'product_cross_property_reference', COUNT(*)
FROM SanPhamPhong p
JOIN LoaiPhong r ON r.LoaiPhongID=p.LoaiPhongID
JOIN ChinhSachHuy c ON c.ChinhSachHuyID=p.ChinhSachHuyID
WHERE r.CoSoLuuTruID <> c.CoSoLuuTruID
UNION ALL
SELECT 'booking_night_count_mismatch', COUNT(*)
FROM HangMucDatCho i
JOIN DatCho b ON b.DatChoID=i.DatChoID
LEFT JOIN (SELECT HangMucDatChoID, COUNT(*) c FROM GiaDemDatCho GROUP BY HangMucDatChoID) n ON n.HangMucDatChoID=i.HangMucDatChoID
WHERE COALESCE(n.c,0) <> DATEDIFF(b.NgayTraPhong,b.NgayNhanPhong)
UNION ALL
SELECT 'booking_total_mismatch', COUNT(*)
FROM DatCho b
LEFT JOIN (SELECT DatChoID, SUM(TongTien) s FROM HangMucDatCho GROUP BY DatChoID) i ON i.DatChoID=b.DatChoID
WHERE b.TongTien <> COALESCE(i.s,0)
UNION ALL
SELECT 'booking_item_total_mismatch', COUNT(*)
FROM HangMucDatCho i
LEFT JOIN (SELECT HangMucDatChoID, SUM(ThanhTien) s FROM GiaDemDatCho GROUP BY HangMucDatChoID) n ON n.HangMucDatChoID=i.HangMucDatChoID
WHERE i.TongTien <> COALESCE(n.s,0)
UNION ALL
SELECT 'booking_item_product_timing_mismatch', COUNT(*)
FROM HangMucDatCho i
JOIN DatCho b ON b.DatChoID=i.DatChoID
JOIN SanPhamPhong p ON p.SanPhamPhongID=i.SanPhamPhongID
WHERE p.ThoiDiemThanhToan<>b.ThoiDiemThanhToan
UNION ALL
SELECT 'pay_online_amount_split_mismatch', COUNT(*)
FROM DatCho b
JOIN XemTruocDatCho x ON x.XemTruocDatChoID=b.XemTruocDatChoID
JOIN ThanhToan p ON p.DatChoID=b.DatChoID
WHERE b.ThoiDiemThanhToan='PAY_ONLINE'
  AND (x.SoTienTraNgay<>b.TongTien OR x.SoTienTraTaiCoSo<>0
       OR p.ThoiDiemThanhToan<>b.ThoiDiemThanhToan OR p.TongPhaiTra<>b.TongTien)
UNION ALL
SELECT 'pay_at_property_amount_split_mismatch', COUNT(*)
FROM DatCho b
JOIN XemTruocDatCho x ON x.XemTruocDatChoID=b.XemTruocDatChoID
JOIN ThanhToan p ON p.DatChoID=b.DatChoID
WHERE b.ThoiDiemThanhToan='PAY_AT_PROPERTY'
  AND (x.SoTienTraNgay<>0 OR x.SoTienTraTaiCoSo<>b.TongTien
       OR p.ThoiDiemThanhToan<>b.ThoiDiemThanhToan OR p.TongPhaiTra<>b.TongTien)
UNION ALL
SELECT 'preview_json_payment_timing_mismatch', COUNT(*)
FROM XemTruocDatCho x
JOIN DatCho b ON b.XemTruocDatChoID=x.XemTruocDatChoID
WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(x.PhanBoJSON, '$.paymentTiming')), '')<>b.ThoiDiemThanhToan
UNION ALL
SELECT 'pay_online_nightly_at_property_nonzero', COUNT(*)
FROM GiaDemDatCho n
JOIN HangMucDatCho i ON i.HangMucDatChoID=n.HangMucDatChoID
JOIN DatCho b ON b.DatChoID=i.DatChoID
WHERE b.ThoiDiemThanhToan='PAY_ONLINE' AND n.ThuePhiTaiCoSo<>0
UNION ALL
SELECT 'preview_room_count_mismatch', COUNT(*)
FROM XemTruocDatCho p
JOIN DatCho b ON b.XemTruocDatChoID=p.XemTruocDatChoID
LEFT JOIN (SELECT DatChoID, COUNT(*) c FROM HangMucDatCho GROUP BY DatChoID) i ON i.DatChoID=b.DatChoID
WHERE JSON_LENGTH(p.PhanBoJSON, '$.rooms') <> COALESCE(i.c,0)
UNION ALL
SELECT 'booking_preview_context_mismatch', COUNT(*)
FROM DatCho b
JOIN XemTruocDatCho p ON p.XemTruocDatChoID=b.XemTruocDatChoID
WHERE p.KhachHangID<>b.KhachHangID OR p.CoSoLuuTruID<>b.CoSoLuuTruID OR p.NgayNhanPhong<>b.NgayNhanPhong OR p.NgayTraPhong<>b.NgayTraPhong OR p.TongTien<>b.TongTien OR p.TrangThai<>'USED'
UNION ALL
SELECT 'payment_booking_mismatch', COUNT(*)
FROM DatCho b
LEFT JOIN ThanhToan p ON p.DatChoID=b.DatChoID
WHERE p.ThanhToanID IS NULL OR p.TongPhaiTra<>b.TongTien OR p.ThoiDiemThanhToan<>b.ThoiDiemThanhToan
   OR (b.ThoiDiemThanhToan='PAY_AT_PROPERTY' AND (p.TrangThai<>'NOT_TRACKED' OR p.DaThanhToan<>0 OR p.DaHoan<>0))
   OR (b.TrangThai='PENDING_PAYMENT' AND p.TrangThai<>'PROCESSING')
   OR (b.TrangThai='PAYMENT_FAILED' AND p.TrangThai<>'FAILED')
   OR (b.ThoiDiemThanhToan='PAY_ONLINE' AND b.TrangThai IN ('CONFIRMED','COMPLETED','NO_SHOW','CANCELLED') AND p.TrangThai<>'PAID')
UNION ALL
SELECT 'cancellation_metadata_or_refund_mismatch', COUNT(*)
FROM DatCho b
LEFT JOIN ThanhToan p ON p.DatChoID=b.DatChoID
WHERE (b.TrangThai='CANCELLED' AND (b.HuyIdempotencyKey IS NULL OR b.NguoiHuyID IS NULL OR b.HuyLuc IS NULL OR b.TinhHuySnapshotJSON IS NULL OR (b.ThoiDiemThanhToan='PAY_ONLINE' AND b.SoTienHoan<>p.DaHoan)))
   OR (b.TrangThai<>'CANCELLED' AND (b.HuyIdempotencyKey IS NOT NULL OR b.NguoiHuyID IS NOT NULL OR b.HuyLuc IS NOT NULL OR b.PhiHuy<>0 OR b.SoTienHoan<>0))
UNION ALL
SELECT 'no_show_metadata_or_inventory_mismatch', COUNT(*)
FROM DatCho b
WHERE b.TrangThai='NO_SHOW' AND (b.NguoiDanhDauNoShowID IS NULL OR b.NoShowLuc IS NULL OR b.NoShowSnapshotJSON IS NULL OR EXISTS (
  SELECT 1 FROM HangMucDatCho i JOIN GiuTonPhongDem l ON l.HangMucDatChoID=i.HangMucDatChoID
  WHERE i.DatChoID=b.DatChoID AND l.TrangThai='ACTIVE'
))
UNION ALL
SELECT 'review_not_eligible', COUNT(*)
FROM DanhGia r
JOIN DatCho b ON b.DatChoID=r.DatChoID
WHERE b.TrangThai<>'COMPLETED' OR b.KhachHangID<>r.KhachHangID OR b.CoSoLuuTruID<>r.CoSoLuuTruID
   OR DATEDIFF('2026-10-05', b.NgayTraPhong) NOT BETWEEN 0 AND 90
UNION ALL
SELECT 'approved_waiver_booking_not_cancelled', COUNT(*)
FROM YeuCauHuyMienPhi w JOIN DatCho b ON b.DatChoID=w.DatChoID
WHERE w.TrangThai='APPROVED' AND b.TrangThai<>'CANCELLED'
UNION ALL
SELECT 'multiple_pending_waiver', COUNT(*)
FROM (SELECT DatChoID FROM YeuCauHuyMienPhi WHERE TrangThai='PENDING' GROUP BY DatChoID HAVING COUNT(*)>1) x
UNION ALL
SELECT 'expired_active_payment_hold', COUNT(*)
FROM GiuTonPhongDem l
JOIN HangMucDatCho i ON i.HangMucDatChoID=l.HangMucDatChoID
JOIN DatCho b ON b.DatChoID=i.DatChoID
WHERE l.TrangThai='ACTIVE' AND b.TrangThai='PENDING_PAYMENT' AND l.HetHanLuc <= '2026-10-05 12:00:00.000000'
UNION ALL
SELECT 'partner_membership_wrong_role', COUNT(*)
FROM ThanhVienDoiTac m JOIN NguoiDung u ON u.NguoiDungID=m.NguoiDungID WHERE u.VaiTro<>'PARTNER'
UNION ALL
SELECT 'facility_scope_mismatch',
  (SELECT COUNT(*) FROM CoSoTienNghi x JOIN TienNghi f ON f.TienNghiID=x.TienNghiID WHERE f.PhamVi<>'PROPERTY')
  + (SELECT COUNT(*) FROM LoaiPhongTienNghi x JOIN TienNghi f ON f.TienNghiID=x.TienNghiID WHERE f.PhamVi<>'ROOM')
UNION ALL
SELECT 'property_outside_hcmc', COUNT(*) FROM CoSoLuuTru WHERE MaThanhPho<>'HCM'
UNION ALL
SELECT 'verified_property_missing_provenance', COUNT(*)
FROM CoSoLuuTru
WHERE TrangThaiDoiChieu IN ('KHOP_CHINH_XAC','KHOP_TEN_CU_BIET_DANH')
  AND (NguonTen<>'HCMC_TOURISM_XLSX_2024' OR MaBanGhiNguon IS NULL OR TenTrongNguon IS NULL
       OR NguonDiaChi IS NULL OR DiaChiXacMinhLuc IS NULL OR DuongDanBanDo IS NULL)
UNION ALL
SELECT 'google_metadata_inconsistent', COUNT(*)
FROM CoSoLuuTru
WHERE (GoogleMatchStatus='NOT_CHECKED' AND (GooglePlaceID IS NOT NULL OR GoogleVerifiedAt IS NOT NULL))
   OR (GoogleMatchStatus='MATCHED' AND (GooglePlaceID IS NULL OR GoogleVerifiedAt IS NULL))
   OR (GoogleMatchStatus IN ('AMBIGUOUS','NOT_FOUND') AND (GooglePlaceID IS NOT NULL OR GoogleVerifiedAt IS NULL))
UNION ALL
SELECT 'daily_rate_invalid_restriction', COUNT(*)
FROM GiaPhongNgay
WHERE SoDemToiThieu<1 OR SoDemToiThieu>30 OR (SoDemToiDa IS NOT NULL AND (SoDemToiDa<SoDemToiThieu OR SoDemToiDa>30));
