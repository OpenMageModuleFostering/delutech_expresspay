<?php

class Delutech_Expresspay_Model_Currency {

    public function toOptionArray() {
        return array(
            array(
                'value' => 'USD',
                'label' => 'USD',
            ),
            array(
                'value' => 'GHS',
                'label' => 'GHS',
            ),
        );
    }
}
