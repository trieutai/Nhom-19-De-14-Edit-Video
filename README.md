# DukTay Edit — Video Tool (Đề tài 14)

**Công cụ cắt, chuyển đổi và xử lý nhiều video theo cấu hình**, xây dựng bằng **PHP, MySQL và FFmpeg**, chạy cục bộ qua **XAMPP**.

Dự án hướng tới giảng viên, người làm nội dung và nhóm truyền thông cần lựa chọn đoạn video, thay đổi độ phân giải, theo dõi hàng đợi và tải kết quả. Giao diện web có chế độ xem trước, thanh thời gian (timeline) một video và màn hình quản lý công việc.

> **Trạng thái:** Bản phục vụ học tập/đồ án. Chưa kiểm thử tích hợp đầy đủ trên mọi cấu hình XAMPP; **không triển khai công khai trên Internet** khi chưa bổ sung đăng nhập, phân quyền và giới hạn các endpoint xử lý video.

## Chức năng hiện có

- **Nhập nhiều video:** hỗ trợ MP4, MOV, AVI, MKV, WebM; kiểm tra thông số thật với `ffprobe` (thời lượng, kích thước, có/không có âm thanh).
- **Biên tập đoạn cắt:** chọn điểm bắt đầu/kết thúc qua trình phát và timeline; xem trước video nguồn.
- **Cấu hình xuất:** các preset SD 480p, HD 720p, Full HD 1080p và video dọc; giữ tỷ lệ nguồn bằng `scale + pad` thay vì kéo giãn hình.
- **Xử lý nền:** thêm công việc vào MySQL, worker PHP CLI lần lượt xử lý bằng FFmpeg; theo dõi các trạng thái `pending`, `processing`, `completed`, `error`, `cancelled`.
- **Quản lý lần xử lý:** hủy công việc, thử lại và lưu thông tin từng lần chạy; giữ nguyên tệp nguồn.
- **Kết quả:** tạo file MP4, thumbnail và kiểm tra thông số đầu ra bằng `ffprobe` trước khi báo hoàn tất.
- **Giao diện:** trình phát xem trước, tua video, chọn tốc độ/chất lượng phát (nếu endpoint tương ứng sẵn có), timeline một video, hàng đợi và lịch sử kết quả.

**Giới hạn hiện tại:** timeline chỉ làm việc với **một video tại một thời điểm**, chưa phải phần mềm dựng phim đa track; chức năng chuyển chất lượng phát và ảnh xem trước cần các file `video_quality.php` và `preview_frame.php` tương ứng. Một số thao tác như ghép nhiều clip, thêm chữ/hiệu ứng chưa được hoàn thiện.

## Công nghệ

| Thành phần | Vai trò |
|---|---|
| PHP 8.0+ | Xử lý upload, yêu cầu biên tập và hàng đợi |
| MySQL/MariaDB (XAMPP) | Video, preset, job, lịch sử lần chạy và kết quả |
| FFmpeg & FFprobe | Cắt, mã hóa, lấy thumbnail, xác minh video |
| HTML/CSS/JavaScript trong các trang PHP | Giao diện và tương tác trình duyệt |
| XAMPP / Apache | Máy chủ chạy cục bộ |

Không yêu cầu dự án frontend riêng hay Node.js để chạy phiên bản PHP hiện tại.

## Cài đặt trên Windows với XAMPP

**1. Yêu cầu trước khi chạy**

- XAMPP với Apache, MySQL/MariaDB và PHP **8.0 trở lên**; bật `pdo_mysql`.
- Cài FFmpeg và FFprobe, thêm thư mục `bin` vào biến môi trường `PATH`.
- Cho phép PHP sử dụng các hàm `proc_open`, `exec`, `shell_exec` trong môi trường cục bộ.

Kiểm tra trong CMD:

```bat
php -v
ffmpeg -version
ffprobe -version
```

**2. Đặt mã nguồn vào XAMPP**

```text
C:\xampp\htdocs\video_tool\
```

Không đưa video cá nhân, video tải lên và kết quả xuất lên GitHub. Hai thư mục `uploads/` và `outputs/` sẽ được tạo tại máy chạy ứng dụng nếu chưa có.

**3. Khởi động cơ sở dữ liệu**

Bật **Apache** và **MySQL** trong XAMPP. Thiết lập kết nối trong `db.php` theo cấu hình MySQL cục bộ. Phiên bản hiện tại dùng tên cơ sở dữ liệu `VideoToolDB`; các bảng được tạo/cập nhật khi kết nối lần đầu.

> Trước khi nâng cấp từ bản cũ, hãy sao lưu `VideoToolDB` trong phpMyAdmin và sao lưu các file PHP. Không xóa thư mục `uploads/` và `outputs/` đang có dữ liệu.

**4. Cấu hình giới hạn upload (nếu cần)**

Trong `php.ini`, ví dụ:

```ini
upload_max_filesize=512M
post_max_size=520M
max_file_uploads=20
```

Khởi động lại Apache sau khi sửa `php.ini`. Ứng dụng còn có kiểm tra giới hạn ở phía máy chủ (bản nâng cấp Đề tài 14 giới hạn khoảng 500 MiB/tệp và tối đa 4 giờ/video).

**5. Chạy worker nền**

Mở CMD riêng và giữ cửa sổ này hoạt động trong khi cần xuất video:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\video_tool\process_worker.php
```

Chỉ xử lý tối đa một công việc rồi thoát:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\video_tool\process_worker.php --once
```

Nếu worker chưa chạy, công việc mới sẽ giữ trạng thái **chờ xử lý**.

**6. Mở giao diện**

```text
http://localhost:8080/video_tool/video_editor.php
```

Dùng cổng Apache thực tế của máy: nếu XAMPP chạy cổng mặc định 80, thay `localhost:8080` bằng `localhost`.

## Cách sử dụng nhanh

1. Mở trình biên tập, chọn **Tải lên** để đưa một hoặc nhiều video vào thư viện.
2. Chọn video để xem trước và tua trên timeline.
3. Chọn thời điểm **bắt đầu** và **kết thúc**; kiểm tra lại khoảng cắt.
4. Vào **Xuất file**, chọn preset độ phân giải và thêm công việc vào hàng đợi.
5. Đảm bảo worker PHP đang chạy; theo dõi trạng thái từng công việc.
6. Sau khi hoàn tất, mở/tải video đầu ra. Nếu lỗi, xem thông báo rồi thử lại.

## Kiến trúc và mã nguồn

| File | Mô tả |
|---|---|
| `video_editor.php` | Giao diện biên tập, upload video và quản lý job |
| `VideoProcessor.php` | Gọi FFmpeg/FFprobe, kiểm tra tham số và đầu ra |
| `db.php` | Kết nối, tạo và cập nhật các bảng MySQL |
| `process_worker.php` | Worker PHP CLI xử lý hàng đợi |
| `preview_frame.php` | Tạo khung hình xem trước trên thanh tua/timeline |
| `video_quality.php` | Tạo phiên bản video với chất lượng xem trước khác nhau |
| `image_editor.php`, `upload_temp.php` | Công cụ và upload ảnh |
| `index.php` | Trang bắt đầu |
| `test_de_tai_14.php` | Kiểm thử tự động phần FFmpeg |

**Luồng xử lý:** `Upload → FFprobe → MySQL (videos) → Tạo job (pending) → Worker (processing) → FFmpeg → FFprobe/xác minh → outputs + lịch sử → completed/error/cancelled`.

## Các phép thử theo Đề tài 14

Chạy bộ kiểm thử kỹ thuật:

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\video_tool\test_de_tai_14.php
```

Các kịch bản cần kiểm tra và lưu bằng chứng khi nghiệm thu:

1. Video 30 giây, cắt từ **5 đến 20 giây**, đầu ra dài xấp xỉ 15 giây và đúng nội dung (theo sai số công bố).
2. Chuyển video ngang/dọc sang kích thước khác mà **không kéo méo tỷ lệ**; kiểm tra bằng `ffprobe`.
3. Cấu hình **giữ âm thanh** hoặc **không giữ âm thanh**; xử lý chính xác video không có track âm thanh.
4. Một lô gồm tệp tốt, tệp hỏng, job hủy và job thử lại; từng trạng thái/lần chạy độc lập, không sửa video nguồn.
5. Từ chối tham số ngoài giới hạn, tên đường dẫn bất thường và truy cập không hợp lệ; không gán `completed` khi đầu ra sai hoặc FFmpeg thất bại.

**Phân biệt kết quả:** bộ thử FFmpeg từng được chạy trong môi trường phát triển, nhưng kiểm thử tích hợp đầy đủ trên MySQL/XAMPP, phân quyền và nhiều worker đồng thời **chưa được xác nhận**. Không coi phần này đã đạt chỉ dựa vào giao diện.

## Lưu ý bảo mật và dữ liệu

- Không commit `uploads/`, `outputs/`, cache, file video, log hoặc bản sao cơ sở dữ liệu.
- Không commit mật khẩu/token/khóa API. Cấu hình cơ sở dữ liệu mặc định trong `db.php` chỉ dành cho XAMPP cục bộ; thay đổi khi triển khai ở môi trường khác.
- Không mở ứng dụng ra Internet trước khi bổ sung đăng nhập, phân quyền, kiểm soát quyền đối với các endpoint sinh thumbnail/chuyển mã và hạn mức tài nguyên.
- Chỉ xử lý video do bạn sở hữu hoặc được phép sử dụng.

## Phạm vi đề tài và hướng phát triển

Mục tiêu **Đề tài 14** là cắt, chuyển đổi, xử lý nhiều video theo cấu hình và quản lý hàng đợi **an toàn, có kiểm thử**. Các tính năng dự kiến phát triển thêm gồm timeline nhiều clip, ghép video, nhiều track âm thanh, thêm chữ/phụ đề, hiệu ứng và lưu dự án — đây là định hướng, **không phải chức năng hiện đã hoàn thành**.

## Giấy phép

Xem file [`LICENSE`](LICENSE) trong repository.
