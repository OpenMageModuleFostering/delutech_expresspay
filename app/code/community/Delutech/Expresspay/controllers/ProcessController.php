<?php

class Delutech_Expresspay_ProcessController extends Mage_Core_Controller_Front_Action {

    public function indexAction() {
        try {
            $store_currency_code = Mage::app()->getStore()->getCurrentCurrencyCode();
            //$expresspay_supported_currency = Mage::getModel('expresspay/currency')->toOptionArray();
            if ($store_currency_code <> "GHS") {
                Mage::getSingleton('core/session')->addNotice("Kindly set your magento currency to Ghana Cedi.");
                $this->loadLayout();
                $this->renderLayout();
            } else {
                $session = Mage::getSingleton('checkout/session');

                $order = Mage::getModel('sales/order');
                $order->loadByIncrementId($session->getLastRealOrderId());
                if (!$order->getId()) {
                    Mage::throwException('No order for processing found');
                    exit;
                }
                $total = $order->getGrandTotal();
                $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage::helper('expresspay')->__('The customer was redirected to Expresspay Ghana.'));
                $order->save();

                //$session->clear();
                $r = $this->beginPayment($order, $session->getLastRealOrderId(), $total);
                if ($r) {
                    $this->_redirectUrl($r);
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
            parent::_redirect('checkout/cart');
        }
    }

    public function responseAction() {
        try {
            $params = $this->getRequest()->getParams();
            $token = (isset($params["token"])) ? $params["token"] : "";
            $cancel = (isset($params["cancel"])) ? $params["cancel"] : "";
            $order_id = (isset($params["order-id"])) ? $params["order-id"] : "";
            if (trim($token) <> "") {
                $this->completePayment($token, $order_id, $cancel);
            } else {
                Mage::getSingleton('core/session')->addNotice("Payment could not be verified.");
                $this->_redirect('checkout/onepage/failure');
            }
        } catch (Exception $e) {
            Mage::logException($e);
            parent::_redirect('checkout/cart');
        }
    }

    function post_to_url($url, $data) {
        $fields_string = "";
        foreach ($data as $key => $value) {
            $fields_string .= $key . '=' . $value . '&';
        }
        $fields_string = rtrim($fields_string, "&");
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    function beginPayment($order, $order_id, $total) {
        $sandbox = Mage::getStoreConfig('expresspay/expresspay/sandbox');
        $mert_id = Mage::getStoreConfig('expresspay/expresspay/mert_id');
        $currency = Mage::app()->getStore()->getCurrentCurrencyCode();
        $api_key = Mage::getStoreConfig('expresspay/expresspay/api_key');
        $url = ($sandbox == "1") ?
                "https://sandbox.expresspaygh.com/api/submit.php" :
                "https://expresspaygh.com/api/submit.php";
        $checkout_redirect_url = ($sandbox == "1") ?
                "https://sandbox.expresspaygh.com/api/checkout.php" :
                "https://expresspaygh.com/api/checkout.php";
        $new_total = number_format($total, 2, '.', '');
        $billingaddress = $order->getBillingAddress();
        $redirect_url = Mage::getUrl('expresspay/process/response');
        $fields = array(
            'merchant-id' => $mert_id,
            'api-key' => $api_key,
            'firstname' => $billingaddress->getData('firstname'),
            'lastname' => $billingaddress->getData('lastname'),
            'phonenumber' => $billingaddress->getTelephone(),
            'email' => $billingaddress->getData('email'),
            'username' => $billingaddress->getData('email'),
            'currency' => $currency,
            'amount' => $new_total,
            'order-id' => $order_id,
            'order-desc' => "Payment of $currency$new_total, for order: $order_id",
            'redirect-url' => $redirect_url,
        );
        $response = $this->post_to_url($url, $fields);
        $response_decoded = json_decode($response);
        $status = $response_decoded->status;
        if ($status == 1) {
            $token = $response_decoded->token;
            return $checkout_redirect_url . "?token={$token}";
        } else {
            #display error message
            //var_dump($response_decoded);
            return false;
        }
    }

    protected function _placePayment() {
        $this->getPayment()->place();
        return $this;
    }

    function completePayment($token, $order_id, $cancel) {
        $sandbox = Mage::getStoreConfig('expresspay/expresspay/sandbox');
        $mert_id = Mage::getStoreConfig('expresspay/expresspay/mert_id');
        $api_key = Mage::getStoreConfig('expresspay/expresspay/api_key');
        $url = ($sandbox == "1") ?
                "https://sandbox.expresspaygh.com/api/query.php" :
                "https://expresspaygh.com/api/query.php";

        $fields = array(
            'merchant-id' => $mert_id,
            'api-key' => $api_key,
            "token" => $token
        );

        $response = $this->post_to_url($url, $fields);
        $response_decoded = json_decode($response);
        $result = $response_decoded->result;
        switch ($result) {
            case 1:
                $this->paymentReceived($order_id);
                parent::_redirect('checkout/onepage/success/');
                break;
            case 2:
                #request declined
                $this->paymentDeclined($order_id);
                Mage::getSingleton('core/session')->addNotice("Payment was declined by Expresspay Ghana");
                $this->_redirect('checkout/onepage/failure');
                break;
            default:
                if ($cancel == "true") {
                    #user cancel request
                    $order_id_text = "order-id";
                    $order_id = $response_decoded->{$order_id_text};
                    $this->paymentCancelled($order_id);
                    Mage::getSingleton('core/session')->addNotice("Payment was canceled by user");
                    $this->_redirect('checkout/onepage/failure');
                } else {
                    #system error 
                    $this->paymentError($order_id);
                    Mage::getSingleton('core/session')->addNotice("Payment was declined by Expresspay Ghana");
                    $this->_redirect('checkout/onepage/failure');
                }
                break;
        }
    }

    function paymentDeclined($order_id) {
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);
        if (!$order->getId()) {
            Mage::throwException('No order for processing found');
            exit;
        }

        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, Mage_Sales_Model_Order::STATE_CANCELED, Mage::helper('expresspay')->__('Payment was declined by Expresspay Ghana.'));
        $order->save();
    }

    function paymentError($order_id) {
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);
        if (!$order->getId()) {
            Mage::throwException('No order for processing found');
            exit;
        }

        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, Mage_Sales_Model_Order::STATE_CANCELED, Mage::helper('expresspay')->__('Expresspay Ghana could not process payment at the moment.'));
        $order->save();
    }

    function paymentCancelled($order_id) {
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);
        if (!$order->getId()) {
            Mage::throwException('No order for processing found');
            exit;
        }

        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, Mage_Sales_Model_Order::STATE_CANCELED, Mage::helper('expresspay')->__('Payment was cancelled by user on Expresspay Ghana.'));
        $order->save();
    }

    function paymentReceived($order_id) {
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);
        if (!$order->getId()) {
            Mage::throwException('No order for processing found');
            exit;
        }

        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PROCESSING, Mage::helper('expresspay')->__('Payment has been received by Expresspay Ghana.'));
        $order->save();
    }

}
