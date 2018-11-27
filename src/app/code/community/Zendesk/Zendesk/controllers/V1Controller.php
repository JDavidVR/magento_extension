<?php

class Zendesk_Zendesk_V1Controller extends Mage_Core_Controller_Front_Action
{
    public function _authorise()
    {
        // Perform some basic checks before running any of the API methods
        // Note that authorisation will accept either the provisioning or the standard API token, which facilitates API
        // methods being called during the setup process
        $tokenString = $this->getRequest()->getHeader('authorization');

        if (!$tokenString && isset($_SERVER['Authorization'])) {
            $tokenString = $_SERVER['Authorization'];
        }

        if (!$tokenString && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $tokenString = $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (!$tokenString && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $tokenString = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (!$tokenString) {
            // Certain server configurations fail to extract headers from the request, see PR #24.
            Mage::log('Unable to extract authorization header from request.', null, 'zendesk.log');

            $this->getResponse()
                ->setBody(json_encode(array('success' => false, 'message' => 'Unable to extract authorization header from request')))
                ->setHttpResponseCode(403)
                ->setHeader('Content-type', 'application/json', true);

            return false;
        }

        $tokenString = stripslashes($tokenString);


        $token = null;
        $matches = array();
        if (preg_match('/Bearer ([a-z0-9]+)/', $tokenString, $matches)) {
            $token = $matches[1];
        }

        $apiToken = Mage::helper('zendesk')->getApiToken(false);
        $provisionToken = Mage::helper('zendesk')->getProvisionToken(false);

        // Provisioning tokens are always accepted, hence why they are deleted after the initial process
        if (!$provisionToken || $token != $provisionToken) {
            // Use of the provisioning token "overrides" the configuration for the API, so we check this after
            // confirming the provisioning token has not been sent
            if (!Mage::getStoreConfig('zendesk/api/enabled')) {
                $this->getResponse()
                    ->setBody(json_encode(array('success' => false, 'message' => 'API access disabled')))
                    ->setHttpResponseCode(403)
                    ->setHeader('Content-type', 'application/json', true);

                Mage::log('API access disabled.', null, 'zendesk.log');

                return false;
            }

            // If the API is enabled then check the token
            if (!$token) {
                $this->getResponse()
                    ->setBody(json_encode(array('success' => false, 'message' => 'No authorisation token provided')))
                    ->setHttpResponseCode(401)
                    ->setHeader('Content-type', 'application/json', true);

                Mage::log('No authorisation token provided.', null, 'zendesk.log');

                return false;
            }

            if ($token != $apiToken) {
                $this->getResponse()
                    ->setBody(json_encode(array('success' => false, 'message' => 'Not authorised')))
                    ->setHttpResponseCode(401)
                    ->setHeader('Content-type', 'application/json', true);

                Mage::log('Not authorised.', null, 'zendesk.log');

                return false;
            }
        }

        return true;
    }

    public function customerorderAction()
    {
        if (!$this->_authorise()) {
            return $this;
        }

        $email = $this->getRequest()->getParams();
        if (count($email) > 1) {
            return $this;
        }

        $customerOrderData = [];
        $emailStr = key($email);

        $customerOrderData = $this->getCustomerData($emailStr);
        $customerOrderData['orders'] = $this->getOrderListData($emailStr);

        $this->getResponse()
            ->setBody(json_encode($customerOrderData))
            ->setHttpResponseCode(200)
            ->setHeader('Content-type', 'application/json', true);
        return $this;
    }

    /**
     * @param string $email
     * @return array | false
     */
    private function getCustomerData($email)
    {
        $customerData = [];

        /** @var Zendesk_Zendesk_Helper_Data $helperZD */
        $helperZD = Mage::helper('zendesk');

        // Try to load a corresponding customer object for the provided email address
        /** @var Mage_Customer_Model_Customer $customerModel */
        $customerModel = $helperZD->loadCustomer($email);

        $customerGroupId = null;
        if ($customerModel) {
            $createdAt = $customerModel->getCreatedAt();

            $customerModel->loadByEmail($email);
            $customerData = [
                'email' => $customerModel->getEmail(),
                'firstname' => $customerModel->getFirstname(),
                'lastname' => $customerModel->getLastname(),
                'created_at' => $helperZD->getFormatedDateTime($createdAt),
                'group_id' => $customerModel->getGroupId()
            ];
        }

        /** @var Mage_Sales_Model_Resource_Order $orderResource */
        $orderResource = Mage::getResourceModel('sales/order');
        $orderConnection = $orderResource->getReadConnection();
        $orderTable = $orderResource->getMainTable();

        /** Maybe customer is Guest try to load from order  */
        if (!$customerData) {
            $select = $orderConnection->select()->from(
                $orderTable,
                [
                    'email' => 'customer_email',
                    'firstname' => 'customer_firstname',
                    'lastname' => 'customer_lastname',
                    'group_id' => 'customer_group_id'
                ]
            )->where(
                'customer_email = ?',
                $email
            )->order(['entity_id DESC'])
                ->limit(1);
            $customerData = $orderConnection->fetchRow($select);
        }

        // customer group id label
        $customerData['group'] = '-';
        if (isset($customerData['group_id']) && $customerData['group_id'] !== null) {
            /** @var Mage_Customer_Model_Group $groupModel */
            $groupModel = Mage::getModel('customer/group');
            $customerData['group'] = $groupModel->load($customerData['group_id'])->getCustomerGroupCode();
        }
        unset($customerData['group_id']);

        // lifetime sales
        $select = $orderConnection->select()->from(
            $orderTable,
            ['lifetime_sales' => 'SUM(subtotal_invoiced)']
        )->where('customer_email LIKE ?', $email);

        $selectRes = $orderConnection->fetchOne($select);
        $lifetimeSales = isset($selectRes) && is_numeric($selectRes) ? $selectRes : 0;
        $customerData['lifetime_sales'] = $this->formatPrice($lifetimeSales);

        return $customerData;
    }

    private function formatPrice($price, $precision = 2, $addBrackets = false)
    {
        /** @var Mage_Directory_Model_Currency $currencyModel */
        $currencyModel = Mage::getModel('directory/currency');
        return $currencyModel->formatPrecision($price, $precision, array(), false, $addBrackets);
    }

    private function getOrderListData($email)
    {
        /** @var Mage_Sales_Model_Resource_Order_Collection $orderCollection */
        $orderCollection = Mage::getResourceModel('sales/order_collection');

        // Order Limit
        $orderLimit = 5;
        if (isset($orderLimit) && is_numeric($orderLimit)) {
            $orderCollection->setPageSize($orderLimit);
        }
        //Load rest of information
        $orderCollection->addFieldToSelect('entity_id')
            ->addFieldToFilter('customer_email', $email)
            ->setOrder('entity_id', 'DESC');

        $orders = [];
        /** @var Mage_Sales_Model_Order $order */
        foreach ($orderCollection as $order) {
            $orders[] = $this->getOrderData($order->getId());
        }
        return $orders;

    }

    /**
     * Retrieve order information
     *
     * @param $id
     * @return array
     */
    private function getOrderData($id)
    {
        /** @var Zendesk_Zendesk_Helper_Data $helperZD */
        $helperZD = Mage::helper('zendesk');

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->load($id);

        /** @var Mage_Sales_Model_Order_Address $billing */
        $billing = $order->getBillingAddress();
        $billing_address = '-';
        if ($billing) {
            $billing_address = $billing->format('html');
        }

        /** @var Mage_Sales_Model_Order_Address $shipping */
        $shipping = $order->getShippingAddress();
        $shipping_address = '-';
        if ($shipping) {
            $shipping_address = $shipping->format('html');
        }

        $createdAt = $order->getCreatedAt();

        $formattedCreatedAt = $helperZD->getFormatedDateTime($createdAt);

        $storeName = Mage::app()->getWebsite($order->getStoreId())->getName();

        $paymentDescription = $order->getPayment()->getMethodInstance()->getTitle();
        $paymentMethod = $paymentDescription ? $paymentDescription : '-';

        $shippingDescription = $order->getShippingDescription();
        $shippingMethod = $shippingDescription ? $shippingDescription : '-';

        $orderInfo = [
            'increment_id' => $order->getIncrementId(),
            'created_at' => $formattedCreatedAt,
            'status' => $order->getStatus(),
            'store_name' => $storeName,
            'billing_address' => $billing_address,
            'shipping_address' => $shipping_address,
            'subtotal' => $this->formatPrice($order->getSubtotal()),
            'shipping_amount' => $this->formatPrice($order->getShippingAmount()),
            'discount_amount' => $this->formatPrice($order->getDiscountAmount()),
            'tax_amount' => $this->formatPrice($order->getTaxAmount()),
            'grand_total' => $this->formatPrice($order->getGrandTotal()),
            'total_paid' => $this->formatPrice($order->getTotalPaid()),
            'total_refunded' => $this->formatPrice($order->getTotalRefunded()),
            'total_due' => $this->formatPrice($order->getTotalDue()),
            'payment_method' => $paymentMethod,
            'shipping_method' => $shippingMethod,
            'items' => []
        ];

        foreach ($order->getAllVisibleItems() as $item) {
            // original float values
            $originalPrice = $item->getOriginalPrice();
            $price = $item->getPrice();
            $qtyOrdered = $item->getQtyOrdered() * 1;
            $subtotal = $qtyOrdered * $price;
            $taxAmount = $item->getTaxAmount();
            $taxPercent = $item->getTaxPercent() * 1;
            $discountAmount = $item->getDiscountAmount();
            $rowTotal = $item->getRowTotal() - $discountAmount;

            $itemInfo['name'] = $item->getName();
            $itemInfo['sku'] = $item->getSku();
            $itemInfo['status'] = $item->getStatus();
            $itemInfo['original_price'] = $this->formatPrice($originalPrice);
            $itemInfo['price'] = $this->formatPrice($price);
            $itemInfo['qty_ordered'] = $qtyOrdered;
            $itemInfo['subtotal'] = $this->formatPrice($subtotal);
            $itemInfo['tax_amount'] = $this->formatPrice($taxAmount);
            $itemInfo['tax_percent'] = $taxPercent;
            $itemInfo['discount'] = $this->formatPrice($discountAmount);
            $itemInfo['total'] = $this->formatPrice($rowTotal);
            $orderInfo['items'][] = $itemInfo;
        }

        return $orderInfo;
    }
}
