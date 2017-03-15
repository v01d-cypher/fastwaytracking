<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/fastwaytracking.php');

if (Tools::getValue('secure_key')) {
    $secure_key = Configuration::get('FW_TR_CRON_SEC_KEY');

    if (!empty($secure_key) && $secure_key === preg_replace('/ /', '+', Tools::getValue('secure_key'))) {
        $fastway = new FastwayTracking();
        $fastway->update_tracking_status();
        echo 'Update tracking status successful';
    } else {
        echo 'Secure key is incorrect';
    }
} else {
    echo 'Secure key is missing';
}
