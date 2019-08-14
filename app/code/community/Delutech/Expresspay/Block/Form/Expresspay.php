<?php

class Delutech_Expresspay_Block_Form_Expresspay extends Mage_Payment_Block_Form {

    protected function _construct() {
        parent::_construct();
        $this->setTemplate('expresspay/expresspay.phtml');
    }

}
