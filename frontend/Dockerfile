FROM yiisoftware/yii2-php:8.4-apache

# Document root -> frontend/web
RUN sed -i -e 's|/app/web|/app/frontend/web|g' /etc/apache2/sites-available/000-default.conf

# Same-origin WebSocket: browsers open /ws/ on this host and Apache tunnels it
# to the relay (WS_UPSTREAM, e.g. ws:8080). One origin, works behind HTTPS
# (wss://) and behind port-forwarding proxies such as GitHub Codespaces.
RUN a2enmod proxy proxy_wstunnel >/dev/null \
 && printf '%s\n' \
    'ProxyPass        "/ws/" "ws://${WS_UPSTREAM}/"' \
    'ProxyPassReverse "/ws/" "ws://${WS_UPSTREAM}/"' \
    > /etc/apache2/conf-enabled/echo-ws-proxy.conf
