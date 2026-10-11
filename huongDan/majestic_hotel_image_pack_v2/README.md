# Hotel Majestic Saigon — Image Pack v2

Bộ tài nguyên này phục vụ fixture và môi trường phát triển của Mini OTA. Nó tách rõ hai loại ảnh:

1. ảnh thật từ Wikimedia Commons, chỉ gắn cho `CS016 – Hotel Majestic Saigon`;
2. ảnh minh họa trung tính do dự án tạo, dùng cho các cơ sở và loại phòng còn lại.

Không được dùng ảnh Hotel Majestic để mô tả một khách sạn khác.

## Cấu trúc

- `MANIFEST.csv`: nguồn, tác giả, giấy phép và URL tải của 15 ảnh Wikimedia;
- `image_mapping.json`: nguồn duy nhất quy định ảnh nào gắn vào seed;
- `ATTRIBUTION.md`: nội dung ghi công rút gọn;
- `download_images.py`: tải và kiểm tra ảnh, sinh `ASSET_LOCK.json`;
- `download_images.ps1`: wrapper PowerShell.

`SOURCE_LINKS.txt` và file profile riêng đã được loại bỏ để tránh có nhiều nguồn cấu hình trùng nhau.

## Ánh xạ vào dữ liệu

- `CS016`: dùng tối đa 12 ảnh property của Hotel Majestic;
- `CS016/DLX`: dùng ảnh phòng Deluxe Twin thật;
- các phòng `STD/FAM/STE` của `CS016`: vẫn dùng placeholder vì ảnh nguồn không chứng minh đúng loại phòng;
- 29 cơ sở khác: dùng ảnh minh họa chung, không dùng ảnh Hotel Majestic.

Schema `AnhJSON` vẫn giữ dạng:

```json
[
  {
    "path": "/assets/images/accommodation/shared/accommodation-cover.png",
    "alt": "Ảnh minh họa cơ sở lưu trú",
    "sort": 1,
    "cover": true
  }
]
```

Mỗi mảng phải có đúng một ảnh bìa, thứ tự liên tục và tối đa 12 ảnh.

## Vị trí public

```text
public/assets/images/accommodation/
├── shared/
│   ├── accommodation-cover.png
│   ├── accommodation-interior.png
│   └── room-placeholder.png
└── majestic/
    ├── 01_lobby_panoramic.jpg
    ├── ...
    ├── 15_exterior_night.jpg
    └── ASSET_LOCK.json
```

Đường dẫn public không chứa nhãn môi trường và có thể dùng trực tiếp trong `AnhJSON`.

## Tải ảnh

Chạy tại thư mục gốc repository:

```powershell
python huongDan/majestic_hotel_image_pack_v2/download_images.py `
  --output public/assets/images/accommodation/majestic
```

hoặc:

```powershell
huongDan/majestic_hotel_image_pack_v2/download_images.ps1 `
  --output public/assets/images/accommodation/majestic
```

Script kiểm tra:

- tên file không được thoát khỏi thư mục đích;
- URL ban đầu và URL sau redirect thuộc Wikimedia;
- MIME và chữ ký JPEG/PNG;
- dung lượng, kích thước tối thiểu;
- SHA-256;
- ghi file qua `.part` rồi thay thế nguyên tử.

`direct_url` trong manifest là URL phân giải chính thức của Commons. Để tránh tải bản gốc quá lớn hoặc bị giới hạn tại máy chủ file gốc, script suy ra URL thumbnail chuẩn rộng 960 px từ chính xác `original_file`; trang nguồn và giấy phép vẫn được lấy từ manifest.

Kết quả tải được khóa trong `ASSET_LOCK.json`. Không sửa tay lock file.

## Sinh lại seed ảnh

Sau khi ảnh tồn tại:

```powershell
php scripts/data/generate_data.php --properties-only
php scripts/data/validate_data.php
```

Chế độ này chỉ ghi lại `database/seeds/003_properties.sql` và manifest dữ liệu; không ghi lại seed vận hành `006`.

## Quy tắc giao diện

- ảnh minh họa phải dùng alt bắt đầu bằng `Ảnh minh họa`;
- khi giao diện được đưa ra ngoài môi trường phát triển, nên hiển thị nhãn nhìn thấy được “Ảnh minh họa” cho ảnh chung;
- không đổi nhãn ảnh sảnh thành hồ bơi, gym, spa hoặc tiện nghi khác;
- phải cung cấp liên kết “Nguồn ảnh” cho ảnh Wikimedia;
- production thật phải dùng ảnh do đối tác cung cấp hoặc ảnh đã xác minh quyền sử dụng riêng.
