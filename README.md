# fastwaytracking
A PrestaShop module to check tracking status of an order and update it accordingly.

This module will use a cron job to check the status of an order with Fastway API. When there is a new status, it will update the order and send an email if that status is set to do so. Use by creating a cron job for http://yourserver/modules/fastwaytracking/cron.php?secure_key=cron_secure_key_123 and call it every half hour or so.

You must configure a Secure Key and the Fastway API.
