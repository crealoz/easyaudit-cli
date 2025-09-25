FROM php:8.2-alpine

# Install dependencies
RUN apk add --no-cache libxml2-dev curl-dev \
    && docker-php-ext-install xml mbstring curl

WORKDIR /app

#Copy the application files
COPY bin/easyaudit /usr/local/bin/easyaudit
COPY src /app/src
COPY composer.json composer.lock /app/

# Make file executable
RUN chmod +x /usr/local/bin/easyaudit

ENTRYPOINT ["easyaudit"]
CMD ["--help"]
