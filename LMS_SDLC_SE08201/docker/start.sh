#!/bin/sh
# Script khởi động container: cấu hình cổng, migrate DB, seed lần đầu, rồi chạy Apache
set -e
cd /var/www/html

# Render cấp cổng qua biến PORT (mặc định 10000)
PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Ổ đĩa trên gói free bị xóa sau mỗi lần khởi động lại -> tạo lại thư mục storage
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

# Cắt khoảng trắng / xuống dòng thừa do copy-paste trong biến kết nối DB
for v in DB_HOST DB_PORT DB_DATABASE DB_USERNAME; do
    eval "val=\${$v:-}"
    export "$v=$(printf '%s' "$val" | tr -d '[:space:]')"
done

echo "==> DB_HOST='${DB_HOST}' (dai ${#DB_HOST} ky tu), DB_PORT='${DB_PORT}'"
# Chờ tối đa ~60 giây cho tên miền database tra được
i=0
until getent hosts "$DB_HOST" >/dev/null 2>&1; do
    i=$((i+1))
    if [ "$i" -ge 12 ]; then
        echo "!! Khong tra duoc ten mien DB_HOST. Kiem tra lai gia tri DB_HOST tren Render."
        break
    fi
    echo "   ...cho DNS cua database ($i/12)"
    sleep 5
done
getent hosts "$DB_HOST" || true

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan config:clear >/dev/null 2>&1 || true

echo "==> Chạy migration"
php artisan migrate --force

# Seed dữ liệu demo nếu database còn trống
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tail -n 1 | tr -dc '0-9')
if [ "${USER_COUNT:-0}" = "0" ]; then
    echo "==> Database trống, nạp dữ liệu demo"
    php artisan db:seed --force
else
    # Database đã có dữ liệu nhưng file học liệu mất sau khi restart -> tạo lại
    echo "==> Tạo lại file học liệu demo"
    php artisan db:seed --class=SubjectMaterialsSeeder --force || true
fi

# Tài liệu cho trợ lý BTEC (storage bị xóa mỗi lần khởi động lại -> chép lại từ bản trong image)
echo "==> Nạp tài liệu trợ lý BTEC"
mkdir -p storage/btec_ai_kb
cp -f docker/btec_ai_kb/* storage/btec_ai_kb/ || true
php artisan db:seed --class=BtecPolicyRuleSeeder --force || true

# KHÔNG chạy config:cache: các service AI đọc key trực tiếp bằng env(),
# khi config bị cache thì env() trả về rỗng -> AI báo thiếu API key.
php artisan route:cache || true
php artisan view:cache || true

chown -R www-data:www-data storage bootstrap/cache

echo "==> Khởi động Apache trên cổng ${PORT}"
exec apache2-foreground
