<?php
/**
* NOTICE OF LICENSE
*
* Magento extension which extracts a product feed from Magento, imports the feed into Fruugo and uses the Fruugo Order API to export all Fruugo orders into Magento.
*
* Copyright (C) 2015  Fruugo.com Ltd
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
* See the GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License along with this program.
* If not, see <http://www.gnu.org/licenses/>.
*/

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/OrdersFeedProcessor.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Defines.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';

use \DOMDocument as DOMDocument;
use \DOMXpath as DOMXpath;

class Fruugo_Integration_Model_Observer
{
    protected static $ALWAYS = Fruugo_Integration_Helper_Logger::ALWAYS;
    protected static $ERROR = Fruugo_Integration_Helper_Logger::ERROR;
    protected static $WARNING = Fruugo_Integration_Helper_Logger::WARNING;
    protected static $INFO = Fruugo_Integration_Helper_Logger::INFO;
    protected static $DEBUG = Fruugo_Integration_Helper_Logger::DEBUG;

    public function beforeSaveOrder(Varien_Event_Observer $observer)
    {
        try {
            $event = $observer->getEvent();
            $order = $event->getOrder();
            $fruugoId = $order->getFruugoOrderId();

            if (empty($fruugoId) && $fruugoId === null) {
                $message = "Cannot process order, no fruugoId found for order: " . $order->getId();
                $this->_writeLog($message, self::$ERROR);
                throw new Exception($message);
            }

            if ($order->getStatus() == 'canceled') {
                $this->beforeCancelOrder($order, $fruugoId);
            }

            // When creating credit memo, order status changes to "processing"
            if ($order->getStatus() == 'processing') {
                $this->beforeSaveRefund($order, $fruugoId);
            }
        } catch (Exception $ex) {
            Mage::logException($ex);
            Mage::getSingleton('core/session')->addError($ex->getMessage());
            throw $ex;
        }
    }

    public function beforeSaveInvoice(Varien_Event_Observer $observer)
    {
        $apiUrl = Fruugo_Integration_Helper_Defines::FRUUGO_ORDERS_ENDPOINT;

        try {
            $event = $observer->getEvent();
            $invoice = $observer->getEvent()->getInvoice();
            $order = $invoice->getOrder();
            $fruugoId = $order->getFruugoOrderId();

            if (!empty($fruugoId) && $fruugoId !== null && $order->getStatus() == 'pending') {
                $data = array();
                $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');

                $postFields = 'orderId='.$fruugoId;

                $this->_writeLog("Creating invoice Fruugo order {$fruugoId}", self::$ALWAYS);

                $itemsInvoiced = $invoice->getAllItems();
                foreach ($itemsInvoiced as $invoiceItem) {
                    $orderItem = $invoiceItem->getOrderItem();
                    $itemInfo = $orderItem->getFruugoProductId().','
                                        .$orderItem->getFruugoSkuId().','
                                        .(int)$invoiceItem->getQty();

                    $postFields .= '&item=' . $itemInfo;
                }

                if ($devMode == '1') {
                    $apiUrl = Mage::getStoreConfig('integration_options/orders_options/order_api_url');

                    if (strpos($apiUrl, '127.0.0.1')) {
                        $data['mock_api_operation'] = 'confirm';
                        $data['orderId'] = $fruugoId;
                    }
                }

                $apiUrl .= '/confirm';
                $data['postFields'] = $postFields;
                list($httpcode, $response) = $this->_sendToApi($apiUrl, $data);

                if ($httpcode == 200) {
                    $message = "Sent confirmation to Fruugo of order {$fruugoId}. Details: {$postFields}.";
                    $this->_saveHistoryComment($order, $message);
                    $this->_writeLog("Invoice created for Fruugo order {$fruugoId}", self::$ALWAYS);
                    $this->_writeLog($message, self::$INFO);
                } else {
                    $message = "Failed to send notification to Fruugo of confirmation of order {$fruugoId}. Server response code: {$httpcode}, response message: {$response}";
                    $this->_writeLog($message, self::$ERROR);
                    throw new Exception($message);
                }
            }
        } catch (Exception $ex) {
            Mage::logException($ex);
            // Throw exception to stop invoice/order save
            Mage::getSingleton('core/session')->addError($message);
            throw $ex;
        }
    }

    public function beforeCancelOrder($order, $fruugoId)
    {
        $apiUrl = Fruugo_Integration_Helper_Defines::FRUUGO_ORDERS_ENDPOINT;
        try {
            $data = array();
            $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');

            if ($devMode == '1') {
                $apiUrl = Mage::getStoreConfig('integration_options/orders_options/order_api_url');
            }

            foreach ($order->getAllItems() as $orderItem) {
                if ($devMode == '1') {
                    if (strpos($apiUrl, '127.0.0.1')) {
                        $data['orderId'] = $fruugoId;
                        $data['mock_api_operation'] = 'cancel_item';
                        $data['fruugoProductId'] = $orderItem->getFruugoProductId();
                    }
                }

                $apiUrl .=  '/cancel';
                $postFields = 'orderId='.$fruugoId;

                if ($orderItem->getQtyOrdered() > 0) {
                    $itemInfo = $orderItem->getFruugoProductId().','
                                    .$orderItem->getFruugoSkuId().','
                                    .(int)$orderItem->getQtyOrdered();

                    $postFields .= '&item=' . $itemInfo;
                }

                $postFields .= '&cancellationReason=other';
                $data['postFields'] = $postFields;
                list($httpcode, $response) = $this->_sendToApi($apiUrl, $data);

                if ($httpcode == 200) {
                    $this->_saveHistoryComment($order, "Sent notification to Fruugo of cancellation of order {$fruugoId}. Details: {$postFields}");
                    $this->_writeLog("Sent notification to Fruugo of cancellation of order {$fruugoId}. Details: {$postFields}", self::$ALWAYS);
                } else {
                    $message = "Failed to send notification to Fruugo of cancellation of order {$fruugoId}. Server response code: {$httpcode}, response message: {$response}";
                    $this->_writeLog($message, self::$ERROR);
                    throw new Exception($message);
                }
            }
        } catch (Exception $ex) {
            Mage::logException($ex);
             // Throw exception to stop invoice/order save
            throw $ex;
        }
    }

    public function beforeSaveShipment(Varien_Event_Observer $observer)
    {
        $apiUrl = Fruugo_Integration_Helper_Defines::FRUUGO_ORDERS_ENDPOINT;

        try {
            $event = $observer->getEvent();
            $shipment = $event->getShipment();
            $order = $shipment->getOrder();
            $fruugoId = $order->getFruugoOrderId();

            if (!empty($fruugoId) && $fruugoId !== null) {
                if ($order->canInvoice()) {
                    $qtys = array();
                    $orderItemsNotToShip = $order->getAllItems();

                    foreach ($shipment->getAllItems() as $shipmentItem) {
                        $orderItemFromShipmentItem = $shipmentItem->getOrderItem();

                        foreach ($orderItemsNotToShip as $key => $value) {
                            if ($value->getId() == $orderItemFromShipmentItem->getId()) {
                                unset($orderItemsNotToShip[$key]);
                            }
                        }

                        $qty = (int)$shipmentItem->getQty();
                        $qtys[$orderItemFromShipmentItem->getId()] = $qty;
                        $itemFruugoProductId = $orderItemFromShipmentItem->getFruugoProductId();
                        $itemFruugoSkuId = $orderItemFromShipmentItem->getFruugoSkuId();
                        $order->addStatusHistoryComment("Automatically set to invoiced and paid by Fruugo for item: Fruugo ProductId: {$itemFruugoProductId}, Fruugo SkuId: {$itemFruugoSkuId}, quantity: {$qty}.", false);
                    }

                    // for items not being shipped, it needs to be put in qtys as 0 qty
                    foreach ($orderItemsNotToShip as $orderItemNotToShip) {
                        $qtys[$orderItemNotToShip->getId()] = 0;
                    }

                    $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice($qtys);
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                    $invoice->register();
                    $invoice->getOrder()->setCustomerNoteNotify(false);
                    $invoice->getOrder()->setIsInProcess(true);

                    $transactionSave = Mage::getModel('core/resource_transaction')
                      ->addObject($invoice)
                      ->addObject($invoice->getOrder());
                    $transactionSave->save();
                }

                $trackingNumber = null;
                $shippingCompany = null;
                $tracks = $shipment->getAllTracks();
                $postFields = '';
                if (is_array($tracks) && count($tracks) > 0) {
                    $track = $tracks[0];
                    $trackingNumber = $track->getTrackNumber();
                    $shippingCompany = $track->getCarrierCode();

                    $postFields .= '&trackingCode=' . $trackingNumber;
                    $postFields .= '&messageToCustomer=' . $shippingCompany;
                } elseif (!is_array($tracks) && isset($tracks)) {
                    $trackingNumber = $tracks;
                    $postFields .= '&trackingCode=' . $trackingNumber;
                }

                $data = array();
                $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');

                if ($devMode == '1') {
                    $apiUrl = Mage::getStoreConfig('integration_options/orders_options/order_api_url');

                    if (strpos($apiUrl, '127.0.0.1')) {
                        $data['mock_api_operation'] = 'ship';
                        $data['orderId'] = $fruugoId;
                    }
                }

                $apiUrl .= '/ship';
                $postFields = 'orderId='.$fruugoId;
                foreach ($shipment->getAllItems() as $shipmentItem) {
                    $orderItem = $shipmentItem->getOrderItem();
                    $itemInfo = $orderItem->getFruugoProductId().','
                                        .$orderItem->getFruugoSkuId().','
                                        .(int)$shipmentItem->getQty();

                    $postFields .= '&item=' . $itemInfo;
                }

                $data['postFields'] = $postFields;
                $ch = $this->_getCurlRequest($apiUrl, $data);
                $response = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpcode == 200) {
                    $shippedOrder = $response;
                    $ordersFeedProcessor = new Fruugo_Integration_OrdersFeedProcessor();
                    $doc = new DOMDocument();
                    $doc->loadXML($shippedOrder);

                    $xpath = new DOMXpath($doc);
                    $xpath->registerNameSpace('o', 'https://www.fruugo.com/orders/schema');
                    $orders = $xpath->query('//o:order');

                    if ($orders->length == 0) {
                        $this->_saveHistoryComment($order, "Fruugo returned invalid data for shipped order $fruugoId. Response: $shippedOrder");
                        $message = "Fruugo returned invalid data for shipped order $fruugoId. Response: $shippedOrder";
                        $this->_writeLog($message, self::$WARNING);
                        throw new Exception($message);
                    } else {
                        $this->_saveHistoryComment($order, "Sent notification to Fruugo of shipment of order $fruugoId");
                        $this->_writeLog("Sent notification to Fruugo of shipment of order $fruugoId", self::$INFO);
                        foreach ($orders as $orderXml) {
                            $orderArray = $ordersFeedProcessor->convertXmlToArray($orderXml);
                            foreach ($orderArray['shipments'] as $shipment) {
                                $shipmentId = $shipment['shipmentId'];
                                $data = array('fruugo_shipment_id' => $shipmentId,
                                    'fruugo_order_id' => $fruugoId,
                                    'created_at' => date_format(new DateTime('NOW'), 'Y-m-d H:i:s'));

                                $model = Mage::getModel('integration/shipment')->setData($data);
                                $insertId = $model->save()->getId();
                                $this->_writeLog("Saved shipment info of Fruugo order {$fruugoId}. ShipmentId: {$shipmentId}", self::$INFO);
                            }
                        }
                    }

                    $this->_writeLog("Sent notification to Fruugo of shipment of order {$fruugoId}. Details: {$postFields}", self::$ALWAYS);
                } else {
                    $message = "Failed to send notification to Fruugo of shipment of order {$fruugoId}. Server response code: {$httpcode}. Response message: {$response}";
                    $this->_writeLog($message, self::$ERROR);
                    throw new Exception($message);
                }
            }
        } catch (Exception $ex) {
            Mage::logException($ex);
            // Throw exception to stop shipment/order save
            Mage::getSingleton('core/session')->addError($ex->getMessage());
            throw $ex;
        }
    }

    public function beforeSaveRefund($order, $fruugoId)
    {
        try {
            $creditMemos = Mage::getResourceModel('sales/order_creditmemo_collection')
                        ->addFieldToFilter('order_id', $order->getId());

            if (count($creditMemos) == 0) {
                return;
            }

            $apiUrl = Fruugo_Integration_Helper_Defines::FRUUGO_ORDERS_ENDPOINT;
            $data = array();
            $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');

            if ($devMode == '1') {
                $apiUrl = Mage::getStoreConfig('integration_options/orders_options/order_api_url');

                if (strpos($apiUrl, '127.0.0.1')) {
                    $data['mock_api_operation'] = 'return';
                    $data['orderId'] = $fruugoId;
                }
            }

            $apiUrl .= '/return';
            $postFields = 'orderId='.$fruugoId;

            foreach ($creditMemos as $creditmemo) {
                $itemsRefunded = $creditmemo->getAllItems();

                foreach ($itemsRefunded as $refundedItem) {
                    $orderItem = $refundedItem->getOrderItem();
                    $itemInfo = $orderItem->getFruugoProductId().','
                                        .$orderItem->getFruugoSkuId().','
                                        .(int)$refundedItem->getQty();

                    $postFields .= '&item=' . $itemInfo;
                }
            }

            $postFields .= '&returnReason=other';
            $data['postFields'] = $postFields;
            list($httpcode, $response) = $this->_sendToApi($apiUrl, $data);

            if ($httpcode == 200) {
                $this->_saveHistoryComment($order, "Sent return notification to Fruugo of order {$fruugoId}. Details: {$postFields}");
                $this->_writeLog("Sent return notification to Fruugo of order {$fruugoId}. Details: {$postFields}", self::$ALWAYS);
            } else {
                $message = "Failed to send notification to Fruugo of return of order {$fruugoId}. Server response code: {$httpcode}, response message: {$response}";
                $this->_writeLog($message, self::$ERROR);
                throw new Exception($message);
            }
        } catch (Exception $ex) {
            Mage::logException($ex);
            // Throw exception to stop invoice/order save
            throw $ex;
        }
    }

    //Use this when the order is being saved in a transaction in the process
    //that triggered the event otherwise you will get MySql errors
    public function _saveHistoryComment($order, $comment)
    {
        $history = Mage::getModel('sales/order_status_history')
            ->setStatus($order->getStatus())
            ->setComment($comment)
            ->setIsVisibleOnFront(false)
            ->setIsCustomerNotified(false)
            ->setEntityName(Mage_Sales_Model_Order::HISTORY_ENTITY_NAME);
        $history->setOrder($order);
        $history->save();
    }

    private function _getCurlRequest($url, $data)
    {
        $ch = curl_init($url);
        $username = Mage::getStoreConfig('integration_options/orders_options/username');
        $password = Mage::getStoreConfig('integration_options/orders_options/password');
        curl_setopt($ch, CURLOPT_POST, 1);

        if (strpos($url, '127.0.0.1')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data['postFields']);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);

        return $ch;
    }

    public function _sendToApi($url, $data)
    {
        $ch = $this->_getCurlRequest($url, $data);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array($httpcode, $response);
    }

    protected function _writeLog($message, $level = Fruugo_Integration_Helper_Logger::DEBUG)
    {
        Fruugo_Integration_Helper_Logger::log($message, $level);
    }
}
