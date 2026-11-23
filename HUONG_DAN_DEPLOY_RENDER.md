# 🚀 Hướng dẫn đưa LMS lên mạng miễn phí (Render + Aiven MySQL)

Kết quả: web có link dạng `https://lms-smart-ai.onrender.com`, ai cũng mở được.

- **Render**: chạy code Laravel (gói Free).
- **Aiven**: cung cấp database MySQL miễn phí (Render không có MySQL free).

> ⚠️ Lưu ý gói Free: web "ngủ" sau ~15 phút không ai dùng, lần mở đầu chờ 30–60 giây.
> File người dùng upload (bài nộp, avatar) **sẽ mất khi web khởi động lại**. Dữ liệu trong database thì vẫn còn.
> Học liệu demo được tạo lại tự động mỗi lần khởi động.

---

## Bước 1. Đưa code lên GitHub (của bạn)

Render cần đọc code từ một repo GitHub mà tài khoản của bạn có quyền.
Cách dễ nhất: **Fork** repo `PhamHiu/LMS_SDLC_SE08201` về tài khoản của bạn, rồi đẩy nhánh có các file deploy lên đó.

Các file đã thêm để deploy:
- `LMS_SDLC_SE08201/Dockerfile`, `LMS_SDLC_SE08201/docker/start.sh`, `LMS_SDLC_SE08201/.dockerignore`, `LMS_SDLC_SE08201/.gitattributes`
- `render.yaml` (ở thư mục gốc repo)
- Sửa `LMS_SDLC_SE08201/bootstrap/app.php`: thêm `trustProxies` để web nhận đúng HTTPS.

## Bước 2. Tạo database MySQL miễn phí trên Aiven

1. Vào https://aiven.io → **Sign up** (đăng nhập bằng Google/GitHub cho nhanh).
2. **Create service** → chọn **MySQL** → gói **Free** → chọn vùng gần Việt Nam nhất có sẵn → **Create**.
3. Chờ trạng thái thành **Running** (khoảng 2–5 phút).
4. Ở tab **Overview**, ghi lại: **Host**, **Port**, **User** (thường là `avnadmin`), **Password**, **Database name** (mặc định `defaultdb`).

## Bước 3. Tạo APP_KEY

Mở PowerShell, chạy lệnh dưới, copy kết quả (dạng `base64:....`):

```powershell
"base64:" + [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
```

> Nếu lệnh trên báo lỗi (PowerShell cũ), dùng lệnh này:
> `$b = New-Object byte[] 32; [Security.Cryptography.RNGCryptoServiceProvider]::Create().GetBytes($b); "base64:" + [Convert]::ToBase64String($b)`

## Bước 4. Tạo web trên Render

1. Vào https://render.com → **Sign up** bằng GitHub.
2. Bấm **New +** → **Blueprint** → chọn repo GitHub của bạn → chọn nhánh có file `render.yaml`.
3. Render đọc `render.yaml` và hỏi các biến còn trống. Điền như sau:

| Biến | Giá trị |
|---|---|
| `APP_KEY` | chuỗi `base64:...` ở Bước 3 |
| `APP_URL` | `https://lms-smart-ai.onrender.com` (sửa lại cho đúng link Render cấp sau khi tạo) |
| `DB_HOST` | Host của Aiven |
| `DB_PORT` | Port của Aiven |
| `DB_DATABASE` | `defaultdb` |
| `DB_USERNAME` | `avnadmin` |
| `DB_PASSWORD` | Password của Aiven |
| `GEMINI_API_KEY` | key Gemini (lấy ở https://aistudio.google.com/apikey) |
| `HF_API_TOKEN`, `VILAO_API_KEY` | để trống nếu không dùng |

4. Bấm **Apply**. Render bắt đầu build (lần đầu khoảng 5–10 phút).
5. Xem tab **Logs**. Thấy dòng `==> Khởi động Apache trên cổng 10000` là xong.

Lần chạy đầu tiên, web tự tạo bảng và nạp dữ liệu demo.

## Bước 5. Đăng nhập thử

Mở link Render cấp → đăng nhập `admin@lms.vn` / `password`
(giảng viên `teacher1@lms.vn`, sinh viên `student1@lms.vn`, cùng mật khẩu `password`).

> 🔐 Web đã công khai trên mạng: nên đổi mật khẩu tài khoản admin sau khi demo xong.

---

## Cập nhật code sau này

Mỗi lần `git push` lên nhánh đã chọn, Render tự build và deploy lại.

## Xử lý lỗi thường gặp

| Hiện tượng | Cách sửa |
|---|---|
| Log báo `SQLSTATE[HY000] [2002]` / `Connection refused` | Sai `DB_HOST`/`DB_PORT`, hoặc service Aiven chưa Running |
| Log báo `Access denied` | Sai `DB_USERNAME`/`DB_PASSWORD` |
| Log báo lỗi SSL khi kết nối DB | Kiểm tra biến `MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt` |
| Trang báo `500 Server Error` | Tạm đặt `APP_DEBUG=true` trên Render để xem lỗi, sửa xong đặt lại `false` |
| `No application encryption key` | Thiếu hoặc sai `APP_KEY` (phải có tiền tố `base64:`) |
| Giao diện mất CSS, link chuyển về `http://` | Kiểm tra `APP_URL` bắt đầu bằng `https://` |
| AI chat không trả lời | Kiểm tra `GEMINI_API_KEY` trong tab Environment |
| Muốn xóa sạch dữ liệu làm lại | Trong Aiven xóa và tạo lại database, rồi **Manual Deploy** trên Render |
