USE hotel_booking;

ALTER TABLE DatCho
  DROP CONSTRAINT CK_DatCho_TrangThai,
  ADD COLUMN NhanPhongLuc DATETIME(6) NULL AFTER XacNhanLuc,
  ADD COLUMN NguoiNhanPhongID BIGINT UNSIGNED NULL AFTER NhanPhongLuc,
  ADD KEY IX_DatCho_NhanPhong (TrangThai, NgayNhanPhong, NhanPhongLuc),
  ADD CONSTRAINT FK_DatCho_NguoiNhanPhong
    FOREIGN KEY (NguoiNhanPhongID) REFERENCES NguoiDung(NguoiDungID),
  ADD CONSTRAINT CK_DatCho_TrangThai CHECK (
    TrangThai IN (
      'PENDING_PAYMENT', 'CONFIRMED', 'CHECKED_IN',
      'PAYMENT_FAILED', 'CANCELLED', 'NO_SHOW', 'COMPLETED'
    )
  ),
  ADD CONSTRAINT CK_DatCho_NhanPhongMetadata CHECK (
    (NhanPhongLuc IS NULL AND NguoiNhanPhongID IS NULL) OR
    (NhanPhongLuc IS NOT NULL AND NguoiNhanPhongID IS NOT NULL)
  );
