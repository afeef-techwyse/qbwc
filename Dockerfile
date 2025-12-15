FROM php:7.4-apache

RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libxml2-dev \
    libssl-dev \
    && docker-php-ext-install soap mysqli pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# 3️⃣ Copy Composer binary from official Composer image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 4️⃣ Set working directory
WORKDIR /var/www/html

# 5️⃣ Copy all project files
COPY . .

# 6️⃣ Install PHP dependencies via Composer
RUN composer install --no-dev --optimize-autoloader

# 7️⃣ Expose Apache port
EXPOSE 80

# 8️⃣ Start Apache server
CMD ["apache2-foreground"]
