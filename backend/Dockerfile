FROM yiisoftware/yii2-php:8.4-apache

# Document root -> backend/web
RUN sed -i -e 's|/app/web|/app/backend/web|g' /etc/apache2/sites-available/000-default.conf

# Same-origin WebSocket proxy, see frontend/Dockerfile.
RUN a2enmod proxy proxy_wstunnel >/dev/null \
 && printf '%s\n' \
    'ProxyPass        "/ws/" "ws://${WS_UPSTREAM}/"' \
    'ProxyPassReverse "/ws/" "ws://${WS_UPSTREAM}/"' \
    > /etc/apache2/conf-enabled/echo-ws-proxy.conf
