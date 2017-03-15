<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class FastwayTracking extends Module {
    public function __construct() {
        $this->name = 'fastwaytracking';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Nevar - v01d@inventati.org';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Fastway Tracking');
        $this->description = $this->l('Check tracking status and update order. See https://github.com/v01d-cypher/fastwaytracking for more details.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

    }

    public function install() {
        if (!parent::install() ||
            !Configuration::updateValue('FW_TR_CRON_SEC_KEY', 'cron_secure_key_123') ||
            !Configuration::updateValue('FW_TR_API_KEY', ''))
            return false;

        return true;
    }

    public function uninstall() {
        if (!parent::uninstall() ||
            !Configuration::deleteByName('FW_TR_CRON_SEC_KEY') ||
            !Configuration::deleteByName('FW_TR_API_KEY'))
            return false;

      return true;
    }

    public function getContent() {
        $output = null;
        $success = false;

        if (Tools::isSubmit('submit'.$this->name)) {
            $secure_key = preg_replace('/\s+/', '+', strval(Tools::getValue('FW_TR_CRON_SEC_KEY')));
            $api_key = strval(Tools::getValue('FW_TR_API_KEY'));
            if (!$secure_key
              || empty($secure_key)
              || !Validate::isUrl($secure_key)) {
                $output .= $this->displayError($this->l('Secure Key must be URL encoded and not empty.'));
            }
            else {
                Configuration::updateValue('FW_TR_CRON_SEC_KEY', $secure_key);
                $success = true;
            }

            if (!$api_key
              || empty($api_key)
              || !Validate::isMd5($api_key)) {
                $output .= $this->displayError($this->l('Fastway API Key must be a valid and not empty.'));
            }
            else {
                Configuration::updateValue('FW_TR_API_KEY', $api_key);
                $success = true;
            }

            if($success) {
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        return $output.$this->displayForm();
    }

    public function displayForm() {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Secure Key'),
                    'name' => 'FW_TR_CRON_SEC_KEY',
                    'size' => 20,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Fastway API Key'),
                    'name' => 'FW_TR_API_KEY',
                    'size' => 20,
                    'required' => true
                )
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            )
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;        // false -> remove toolbar
        $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = array(
            'save' =>
            array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current value
        $helper->fields_value['FW_TR_CRON_SEC_KEY'] = Configuration::get('FW_TR_CRON_SEC_KEY');
        $helper->fields_value['FW_TR_API_KEY'] = Configuration::get('FW_TR_API_KEY');

        return $helper->generateForm($fields_form);
    }

    public function fastway_status($tracking_number) {
        $json = file_get_contents('http://api.fastway.org/v2/tracktrace/detail.json?LabelNo=' . $tracking_number . '&api_key=' . Configuration::get('FW_TR_API_KEY'));
        $obj = json_decode($json);

        if(isset($obj->result)) {
            $scans = $obj->result->Scans;

            if(count($scans) > 0) {
                $last_status = end($scans);
                $type = $last_status->Type;
                $status = $last_status->Status;

                if ($type == 'D' && $status != 'YES') {
                    return '';
                }
                else {
                    return $type;
                }
            }
        }

        return '';
    }

    public function update_status($id, $fastway_status) {
        $payment = 2;
        $processing = 3;
        $collected = 17;
        $in_transit = 15;
        $delivered = 5;

        $status_map = array(
            'P' => $collected,
            'T' => $in_transit,
            'D' => $delivered
        );


        $new_history = new OrderHistory();
        $new_history->id_order = $id;
        $new_history->changeIdOrderState($status_map[$fastway_status], $id, true);
        if($fastway_status != 'P') {
            $new_history->addWithemail(true);
        }
    }

    public function update_tracking_status() {
        $delivered = 5;

        $done_states = array($delivered);
        $sql = "SELECT id_order, shipping_number FROM " . _DB_PREFIX_ . "orders WHERE shipping_number != '' AND current_state != " . $delivered;
        $res = Db::getInstance()->executeS($sql);

        if (count($res) > 0) {
            for($i = 0; $i < count($res); $i++) {
                $id = (int)$res[$i]['id_order'];
                $tracking_number = $res[$i]['shipping_number'];
                $fastway_status = $this->fastway_status($tracking_number);

                if ($fastway_status != '') {
                    $this->update_status($id, $fastway_status);
                }
            }
        }
    }
}
