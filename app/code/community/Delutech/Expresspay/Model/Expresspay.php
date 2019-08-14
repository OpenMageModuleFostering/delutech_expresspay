<?php

class Delutech_Expresspay_Model_Expresspay extends Mage_Payment_Model_Method_Abstract {

    protected $_code = 'expresspay';
    protected $_isGateway = true;

    public function getOrderPlaceRedirectUrl() {
        return Mage::getUrl('expresspay/process', array('_secure' => false));
    }

}
