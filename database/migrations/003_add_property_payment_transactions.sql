USE hotel_booking;

ALTER TABLE ThanhToan
  DROP CONSTRAINT CK_ThanhToan_TaiCoSo,
  ADD CONSTRAINT CK_ThanhToan_TaiCoSo CHECK (
    ThoiDiemThanhToan <> 'PAY_AT_PROPERTY' OR
    (
      TrangThai = 'NOT_TRACKED' AND DaThanhToan = 0 AND DaHoan = 0
      AND Provider IS NULL AND IdempotencyKey IS NULL AND ProviderReference IS NULL
      AND SoLanThu = 0 AND MaLoiCuoi IS NULL AND XuLyLuc IS NULL
      AND HoanTienIdempotencyKey IS NULL AND TrangThaiHoanTien = 'NONE'
      AND ProviderRefundReference IS NULL AND LyDoHoanTien IS NULL
      AND HoanTienHoanTatLuc IS NULL
    ) OR (
      TrangThai = 'PAID' AND DaThanhToan = TongPhaiTra AND DaHoan = 0
      AND Provider IS NULL AND IdempotencyKey IS NOT NULL
      AND SoLanThu = 1 AND MaLoiCuoi IS NULL AND XuLyLuc IS NOT NULL
      AND HoanTienIdempotencyKey IS NULL AND TrangThaiHoanTien = 'NONE'
      AND ProviderRefundReference IS NULL AND LyDoHoanTien IS NULL
      AND HoanTienHoanTatLuc IS NULL
    )
  );

CREATE TABLE GiaoDichThanhToan (
  GiaoDichThanhToanID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ThanhToanID BIGINT UNSIGNED NOT NULL,
  LoaiGiaoDich VARCHAR(16) NOT NULL,
  PhuongThuc VARCHAR(24) NOT NULL,
  SoTien DECIMAL(14,2) NOT NULL,
  TienTe CHAR(3) NOT NULL DEFAULT 'VND',
  TrangThai VARCHAR(16) NOT NULL,
  IdempotencyKey CHAR(36) NOT NULL,
  ThamChieu VARCHAR(100) NULL,
  NguoiGhiNhanID BIGINT UNSIGNED NOT NULL,
  GhiNhanLuc DATETIME(6) NOT NULL,
  MetadataJSON JSON NOT NULL,
  TaoLuc DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY UQ_GiaoDichThanhToan_Key (IdempotencyKey),
  UNIQUE KEY UQ_GiaoDichThanhToan_ThanhToanLoai (ThanhToanID, LoaiGiaoDich),
  KEY IX_GiaoDichThanhToan_GhiNhan (GhiNhanLuc, TrangThai),
  KEY IX_GiaoDichThanhToan_NguoiGhiNhan (NguoiGhiNhanID),
  CONSTRAINT FK_GiaoDichThanhToan_ThanhToan
    FOREIGN KEY (ThanhToanID) REFERENCES ThanhToan(ThanhToanID),
  CONSTRAINT FK_GiaoDichThanhToan_NguoiGhiNhan
    FOREIGN KEY (NguoiGhiNhanID) REFERENCES NguoiDung(NguoiDungID),
  CONSTRAINT CK_GiaoDichThanhToan_Loai CHECK (LoaiGiaoDich IN ('COLLECTION')),
  CONSTRAINT CK_GiaoDichThanhToan_PhuongThuc CHECK (
    PhuongThuc IN ('CASH','BANK_TRANSFER','CARD_AT_PROPERTY')
  ),
  CONSTRAINT CK_GiaoDichThanhToan_Tien CHECK (TienTe = 'VND' AND SoTien > 0),
  CONSTRAINT CK_GiaoDichThanhToan_TrangThai CHECK (TrangThai = 'SUCCEEDED'),
  CONSTRAINT CK_GiaoDichThanhToan_ThamChieu CHECK (
    (PhuongThuc = 'CASH' AND ThamChieu IS NULL) OR
    (PhuongThuc <> 'CASH' AND ThamChieu IS NOT NULL)
  )
) ENGINE=InnoDB;
