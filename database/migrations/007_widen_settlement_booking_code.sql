USE hotel_booking;

-- MaDatCho nguồn cho phép tối đa 24 ký tự. Snapshot đối soát phải giữ nguyên
-- toàn bộ mã thay vì cắt ngầm mã booking mới dạng SB + random hex.
ALTER TABLE ChiTietDoiSoat
  MODIFY MaDatCho VARCHAR(24) NOT NULL;
