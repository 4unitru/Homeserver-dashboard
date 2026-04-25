# Установка на сервер и права

Инструкция для Linux-сервера (Nginx + PHP-FPM). Для Apache логика аналогичная: главное — правильно настроить права на запись.

## 1) Размещение проекта

```bash
sudo mkdir -p /var/www/homeserver-dashboard
sudo cp -r . /var/www/homeserver-dashboard/
cd /var/www/homeserver-dashboard
```

## 2) Подготовка директорий и данных

```bash
mkdir -p icons-cache
test -f links.json || echo '{"rows":{"_default":{"name":"","order":0,"collapsed":false}},"links":{}}' > links.json
```

## 3) Права (критично)

Процесс PHP должен писать в `links.json` и `icons-cache/`.

Пример для `www-data`:

```bash
sudo chown -R www-data:www-data /var/www/homeserver-dashboard
sudo chmod 755 /var/www/homeserver-dashboard
sudo chmod 775 /var/www/homeserver-dashboard/icons-cache
sudo chmod 664 /var/www/homeserver-dashboard/links.json
```

## 4) Nginx (пример)

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

Применить:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 5) Проверка после деплоя

- открывается `Homeserver dashboard`;
- создание карточки и категории работает;
- drag&drop карточек и категорий сохраняется после reload;
- сворачивание/разворачивание категорий сохраняется;
- иконки пишутся в `icons-cache`.

## 6) Что важно про данные

- `links.json` хранит категории (`rows`) и карточки (`links`) в одном файле;
- при обновлениях приложение может автоматически мигрировать структуру и дополнять поля (`order`, `collapsed`, `row_id`);
- резервная копия `links.json` перед большими изменениями — хорошая практика.

## 7) Частые проблемы

- **Не сохраняются карточки/категории**  
  Нет прав записи в `links.json`.

- **Не сохраняются иконки**  
  Нет прав записи в `icons-cache/`.

- **Ошибка 403/500**  
  Неверный пользователь/группа, SELinux/AppArmor policy или ограничения `open_basedir`.

