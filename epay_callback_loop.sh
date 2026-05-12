#!/bin/bash
while true; do
    php /www/wwwroot/vmq.okekrr.com/epay_callback_cron.php 2>/dev/null
    sleep 10
done
