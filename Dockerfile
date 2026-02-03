FROM php:8.2-alpine

RUN apk add --no-cache \
      git \
      libxml2-dev \
      curl-dev \
      oniguruma-dev \
      bash \
    && docker-php-ext-install xml mbstring curl

COPY easyaudit.phar /usr/local/bin/easyaudit.phar

# Wrapper so 'easyaudit' command works inside the container
RUN printf '#!/bin/sh\nphp /usr/local/bin/easyaudit.phar "$@"\n' > /usr/local/bin/easyaudit \
    && chmod +x /usr/local/bin/easyaudit

ENTRYPOINT ["php", "/usr/local/bin/easyaudit.phar"]

CMD ["--help"]
