# Установка на сервер и права

Инструкция для Linux-сервера с Nginx + PHP-FPM (аналогично для Apache).

## 1) Размещение проекта

Пример:

```bash
sudo mkdir -p /var/www/homeserver-dashboard
sudo cp -r . /var/www/homeserver-dashboard/
cd /var/www/homeserver-dashboard
```

## 2) Создание каталога кэша иконок

```bash
mkdir -p icons-cache
```

## 3) Права доступа (обязательно)

Приложение должно иметь право записи в `links.json` и `icons-cache`.

Пример для пользователя веб-сервера `www-data`:

```bash
sudo chown -R www-data:www-data /var/www/homeserver-dashboard
sudo chmod 755 /var/www/homeserver-dashboard
sudo chmod 775 /var/www/homeserver-dashboard/icons-cache
sudo chmod 664 /var/www/homeserver-dashboard/links.json
```

Если `links.json` отсутствует:

```bash
touch /var/www/homeserver-dashboard/links.json
echo '{"rows":{"_default":{"name":"","order":0,"collapsed":false}},"links":{}}' > /var/www/homeserver-dashboard/links.json
sudo chown www-data:www-data /var/www/homeserver-dashboard/links.json
sudo chmod 664 /var/www/homeserver-dashboard/links.json
```

## 4) Nginx конфигурация (пример)

```nginx
server {
    listen 80;
    server_name your-host-or-ip;

    root /var/www/homeserver-dashboard;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Проверка и перезапуск:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 5) Проверка работы

- открывается главная страница;
- добавляются карточки;
- создаются категории;
- drag&drop сохраняется после перезагрузки;
- иконки загружаются и пишутся в `icons-cache/`.

## 6) Частые проблемы

- **Ошибка сохранения карточек/категорий**: нет прав записи в `links.json`.
- **Не сохраняются иконки**: нет прав записи в `icons-cache/`.
- **403/500 при PHP**: неверный пользователь/группа или ограничение `open_basedir`.

