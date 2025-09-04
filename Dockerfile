FROM php:8.2-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev \
    && docker-php-ext-install zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy application files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Copy .env.example to .env if needed
RUN if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || echo "" > .env; fi

# The app runs on port 8080
EXPOSE 8080

# Run the application using composer serve
CMD ["composer", "serve", "--host=0.0.0.0"]
