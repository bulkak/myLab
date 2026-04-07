FROM nginx:alpine

# Копирование конфигурации
COPY default.conf /etc/nginx/conf.d/default.conf

# Создание директории для uploads
RUN mkdir -p /var/www/uploads && chmod 777 /var/www/uploads

EXPOSE 80
