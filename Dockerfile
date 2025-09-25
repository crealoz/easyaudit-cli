FROM php:8.2-alpine

# Install dependencies
RUN apk add --no-cache \
      libxml2-dev \
      curl-dev \
      oniguruma-dev \
    && docker-php-ext-install xml mbstring curl


#Copy the application files
COPY bin/easyaudit /usr/local/bin/easyaudit
COPY src /usr/local/src

# Make file executable
RUN chmod +x /usr/local/bin/easyaudit && sed -i 's/\r$//' /usr/local/bin/easyaudit
ENTRYPOINT ["php","/usr/local/bin/easyaudit"]

CMD ["--help"]
