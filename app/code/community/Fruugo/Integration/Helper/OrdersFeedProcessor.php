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

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Defines.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/ConfigLoader.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Model/Observer.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';

use \DOMDocument as DOMDocument;
use \DOMXpath as DOMXpath;

class Fruugo_Integration_OrdersFeedProcessor extends Mage_Core_Helper_Abstract
{
    protected static $ALWAYS = Fruugo_Integration_Helper_Logger::ALWAYS;
    protected static $ERROR = Fruugo_Integration_Helper_Logger::ERROR;
    protected static $WARNING = Fruugo_Integration_Helper_Logger::WARNING;
    protected static $INFO = Fruugo_Integration_Helper_Logger::INFO;
    protected static $DEBUG = Fruugo_Integration_Helper_Logger::DEBUG;

    public function processOrders($from = null)
    {
        $apiUrl = Fruugo_Integration_Helper_Defines::FRUUGO_ORDERS_ENDPOINT;
        $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');

        if ($devMode == '1') {
            $apiUrl = Mage::getStoreConfig('integration_options/orders_options/order_api_url');
        }

        $apiUrl .= '/download';

        $username = Mage::getStoreConfig('integration_options/orders_options/username');
        $password = Mage::getStoreConfig('integration_options/orders_options/password');

        $context = stream_context_create(array(
            'http' => array(
                'header'  => "Authorization: Basic " . base64_encode("$username:$password")
            )
        ));

        if (empty($from)) {
            $from = Fruugo_Integration_Helper_ConfigLoader::load('integration_options/orders_options/orders_endpoint_last_checked');
            if (empty($from)) {
                $from = new DateTime('NOW');
            } else {
                try {
                    $from = new DateTime($from);
                } catch (Exception $ex) {
                    $this->_writeLog('Invalid $from paramter format. Value: ' . $from, self::$ERROR);
                    $from = new DateTime('NOW');
                }
            }

            $from = $from->format(DateTime::ISO8601);
        }

        $apiUrl .= ("?from=".urlencode($from));

        try {
            $this->_writeLog("Getting new orders from Integration. From: " . $from, self::$ALWAYS);
            $data = @file_get_contents($apiUrl, false, $context);

            if ($data === false) {
                throw new Exception("Failed to download orders from the Fruugo API at the URL $apiUrl.  Make sure your Fruugo username and password are correct.");
            }

            $lastChecked = new DateTime('NOW');
            $lastChecked = $lastChecked->format(DateTime::ISO8601);

            Fruugo_Integration_Helper_ConfigLoader::save('integration_options/orders_options/orders_endpoint_last_checked', $lastChecked);

            $doc = new DOMDocument();
            $doc->loadXML($data);

            $xpath = new DOMXpath($doc);
            $xpath->registerNameSpace('o', 'https://www.fruugo.com/orders/schema');
            $orders = $xpath->query('//o:order');

            if ($orders->length == 0) {
                $this->_writeLog('No new Fruugo orders to process.', self::$WARNING);
            } else {
                foreach ($orders as $orderXml) {
                    $this->_mapOrderFromXml($orderXml);
                }

                $this->_writeLog('Processing new orders finished.', self::$ALWAYS);
            }
        } catch (Exception $ex) {
            Mage::logException($ex);
        }
    }

    public function _mapOrderFromXml($orderXml)
    {
        $orderArray = $this->convertXmlToArray($orderXml);
        $fruugoId = $orderArray['orderId'];

        if ($orderArray['orderStatus'] !== 'PENDING') {
            $this->_writeLog('Fruugo order ' . $fruugoId . ' status is not PENDING. Order skipped.', self::$WARNING);
            return false;
        }

        $salesModel = Mage::getModel("sales/order");
        $existingCount = $salesModel->getCollection()->addAttributeToFilter('fruugo_order_id', $fruugoId)->count();

        if ($existingCount > 0) {
            // Fruugo_Integration_Helper_Logger::log();
            $this->_writeLog("The Fruugo order $fruugoId already exists and is being skipped", self::$WARNING);
            return false;
        }

        $orderDate = $orderArray['orderDate'];
        $shippingAddress = $orderArray['shippingAddress'];
        $firstName = $shippingAddress['firstName'];
        $lastName = $shippingAddress['lastName'];
        $streetAddress = $shippingAddress['streetAddress'];
        $city = $shippingAddress['city'];
        $province = $shippingAddress['province'];
        $postcode = $shippingAddress['postalCode'];
        $countryCode = $shippingAddress['countryCode'];
        $phoneNumber = $shippingAddress['phoneNumber'];
        $email = 'help+order-' . $fruugoId . '@fruugo.com';
        $password = $this->_randomPassword();

        $websiteId = Mage::app()->getWebsite()->getId();
        $fruugoStore = null;
        $stores = Mage::app()->getStores();
        foreach ($stores as $id => $store) {
            if ($store->getCode() == 'fruugo') {
                $fruugoStore = $store;
            }
        }

        if (empty($fruugoStore)) {
            $fruugoStore = Mage::app()->getStore();
        }

        // create customer
        $customer = Mage::getModel("customer/customer");
        $customer->setWebsiteId($websiteId)
            ->setStore($fruugoStore);

        $customer->loadByEmail($email);
        $customer->setFirstname($firstName)
          ->setLastname($lastName)
          ->setEmail($email)
          ->setPassword($password);
        $customer->setCreatedAt($orderDate);
        $customer->save();

        // create quote
        $quote = Mage::getModel('sales/quote')->setStoreId($fruugoStore->getId());
        $quote->setStore($fruugoStore);

        $currencyCode = '';
        // for deleted products
        $orderItemsInfo = '';
        $nonExistProductInfo = '';
        foreach ($orderArray['orderLines'] as $orderLine) {
            $productId = $orderLine['productId'];
            $productSku = $orderLine['skuId'];
            $currencyCode = $orderLine['currencyCode'];
            $fruugoProductId = $orderLine['fruugoProductId'];
            $fruugoSkuId = $orderLine['fruugoSkuId'];
            $quantity = $orderLine['totalNumberOfItems'];

            $quoteItem = $this->_mapLineItem($orderLine);

            if (!$quoteItem) {
                // product does not exist any more, cancel this item
                $orderItemsInfo .= '&item=' . $fruugoProductId.','
                            .$fruugoSkuId.','
                            .(int)$quantity;
                $nonExistProductInfo .= "ProductId:{$productId}, Sku:{$productSku}";
                continue;
            }

            $quoteItem->setQuote($quote);
            $quoteItem->setStoreId($fruugoStore->getId());
            // set the fruugo skuid and fruugo
            $quoteItem->setFruugoProductId($fruugoProductId);
            $quoteItem->setFruugoSkuId($fruugoSkuId);
            $quoteItem->setData('fruugo_product_id', $fruugoProductId);
            $quoteItem->setData('fruugo_sku_id', $fruugoSkuId);

            $quote->addItem($quoteItem);
        }

        $quote->setQuoteCurrencyCode($currencyCode);
        $quote->assignCustomer($customer);

        $addressData = array(
            'firstname' => $firstName,
            'lastname' => $lastName,
            'street' => $streetAddress,
            'city' => $city,
            'postcode' => $postcode,
            'region_id' => empty($province) ? 'No region' : $this->getRegionIdByProvince($province, $countryCode),
            'telephone' => $phoneNumber,
            'country_id' => $countryCode
        );

        $billingAddress = $quote->getBillingAddress()->addData($addressData);
        $shippingAddress = $quote->getShippingAddress()->addData($addressData);

        // Create custom shipping method and set payment method
        $rate = Mage::getModel('sales/quote_address_rate');
        $shippingCode = 'fruugo_shipping';
        $rate->setCode($shippingCode)
            ->setCarrier($shippingCode)
            ->setCarrierTitle('Fruugo')
            ->setMethod('fruugo_' . strtolower($orderArray['shippingMethod']))
            ->setMethodTitle('Fruugo ' . $orderArray['shippingMethod'])
            ->setPrice($orderArray['shippingCostInclVAT'])
            ->setCost(0);

        $paymentMethod = Fruugo_Integration_Helper_Defines::FRUUGO_PAYMENT_METHOD;
        $shippingAddress->addShippingRate($rate)
            ->setShippingMethod($shippingCode)
            ->setPaymentMethod($paymentMethod);
        $quote->getPayment()->importData(array('method' => $paymentMethod));

        $quote->setFruugoOrderId($fruugoId);
        $quote->setData('fruugo_order_id', $fruugoId);

        $quote->collectTotals()->save();

        $service = Mage::getModel('sales/service_quote', $quote);
        $order = $service->submitOrder();

        $order->addStatusHistoryComment("Note: Discard billing address as it is not valid.")
                                    ->setIsVisibleOnFront(false)
                                    ->setIsCustomerNotified(false);

        $order->setCreatedAt(Varien_Date::formatDate($orderDate, true));
        $order->setOrderCurrencyCode($currencyCode);
        $order->setData('fruugo_order_id', $fruugoId);
        $order->addStatusHistoryComment("Order created from Fruugo orders feed.  The Fruugo Order ID is $fruugoId")
                                    ->setIsVisibleOnFront(false)
                                    ->setIsCustomerNotified(false);

        foreach ($order->getAllItems() as $orderItem) {
            $orderItem->setBaseTaxBeforeDiscount(null);

            // Save the order item so fruugo_product_id and fruugo_sku_id are persisted
            $orderItem->save();

            // Override the order item's prices based on data from Fruugo
            $this->_setOrderItemPrices($orderArray['orderLines'], $orderItem);
        }

        // Override the order's prices based on data from Fruugo
        $this->_setOrderPrices($orderArray['orderLines'], $order, $orderArray['shippingCostInclVAT']);
        $order->save();

        // Remove items from shopping cart
        $quote->removeAllItems();
        $quote->save();

        if (count($order->getAllItems()) == 0) {
            foreach ($orderArray['orderLines'] as $orderLine) {
                $productId = $orderLine['productId'];
                $productSku = $orderLine['skuId'];
                $nonExistProductInfo .= "ProductId:{$productId}, Sku:{$productSku}";
                $orderItemsInfo .= '&item=' . $orderLine['fruugoProductId'].','
                            .$orderLine['fruugoSkuId'].','
                            .(int)$orderLine['totalNumberOfItems'];
                $this->cancelItemsOnProductNotExist($order, $fruugoId, $nonExistProductInfo, $orderItemsInfo, $orderLine['fruugoProductId']);
            }
        } elseif (!empty($orderItemsInfo)) {
            $this->cancelItemsOnProductNotExist($order, $fruugoId, $nonExistProductInfo, $orderItemsInfo);
        }
    }

    protected function _setOrderPrices($orderLines, $order, $shippingCost)
    {
        // Set tax percentage to null, since we don't know it
        $order->setTaxPercent(null);
        $order->setBaseTaxPercent(null);

        // Set subtotal
        $subtotal = $this->_getOrderTotal($orderLines);

        $order->setBaseSubtotal($subtotal);
        $order->setSubtotal($subtotal);

        // Set tax amount
        $tax = $this->_getOrderTax($orderLines);

        $order->setTaxAmount($tax);
        $order->setBaseTaxAmount($tax);

        // Set total
        $total = $this->_getOrderTotal(
            $orderLines,
            $withTax = true,
            $withShipping = $shippingCost
        );

        $order->setBaseGrandTotal($total);
        $order->setGrandTotal($total);
        $order->setBaseTotalDue($total);
        $order->setTotalDue($total);
    }

    protected function _getOrderTax($orderLines)
    {
        return array_sum(array_map(function ($item) {
            return $item['totalVat'];
        }, $orderLines));
    }

    protected function _getOrderTotal($orderLines, $withTax = false, $withShipping = false)
    {
        $price = array_sum(array_map(function ($item) {
            return $item['totalPriceInclVat'];
        }, $orderLines));

        if (!$withTax) {
            $vat = array_sum(array_map(function ($item) {
                return $item['totalVat'];
            }, $orderLines));

            $price = $price - $vat;
        }

        if ($withShipping) {
            $price = $price + $withShipping;
        }

        return $price;
    }

    protected function _setOrderItemPrices($orderLines, $orderItem)
    {
        $orderLine = $this->_getOrderItemByFruugoSku($orderLines, $orderItem->getData('fruugo_sku_id'));

        // Set unit price
        $price = $this->_getOrderLinePrice($orderLine);

        $orderItem->setBasePrice($price);
        $orderItem->setPrice($price);
        $orderItem->setBaseOriginalPrice($price);
        $orderItem->setOriginalPrice($price);

        // Set tax-inclusive unit price
        $priceInclTax = $this->_getOrderLinePrice($orderLine, true);

        $orderItem->setPriceInclTax($priceInclTax);
        $orderItem->setBasePriceInclTax($priceInclTax);

        // Set tax percentage
        $orderItem->setTaxPercent($orderLine['vatPercentage']);
        $orderItem->setBaseTaxPercent($orderLine['vatPercentage']);

        // Set unit tax amount
        $orderItem->setTaxAmount($orderLine['totalVat']);
        $orderItem->setBaseTaxAmount($orderLine['totalVat']);

        // Set total
        $total = $this->_getOrderLineTotal($orderLine);

        $orderItem->setBaseRowTotal($total);
        $orderItem->setRowTotal($total);

        // Set tax-inclusive total
        $totalInclTax = $this->_getOrderLineTotal($orderLine, true);

        $orderItem->setBaseRowTotalInclTax($totalInclTax);
        $orderItem->setRowTotalInclTax($totalInclTax);
    }

    protected function _getOrderLinePrice($orderLine, $withTax = false)
    {
        $price = $orderLine['itemPriceInclVat'];

        if (!$withTax) {
            $price = $price - $orderLine['itemVat'];
        }

        return $price;
    }

    protected function _getOrderLineTotal($orderLine, $withTax = false)
    {
        $price = $orderLine['totalPriceInclVat'];

        if (!$withTax) {
            $price = $price - $orderLine['totalVat'];
        }

        return $price;
    }

    protected function _getOrderItemByFruugoSku($orderLines, $fruugoSku)
    {
        $orderItem;

        foreach ($orderLines as $orderLine) {
            if ($orderLine['fruugoSkuId'] == $fruugoSku) {
                $orderItem = $orderLine;
            };
        }

        return $orderItem;
    }

    private function _mapLineItem($orderLine)
    {
        $sku = $orderLine['skuId'];
        $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $sku);

        if ($product == null || empty($product)) {
            return false;
        }

        $quoteItem = Mage::getModel('sales/quote_item')->setProduct($product);
        $quoteItem->setQty($orderLine['totalNumberOfItems']);
        return $quoteItem;
    }

    public function convertXmlToArray($xmlNode)
    {
        $arr = array();
        $arrayTypes = array('orderLine', 'shipment', 'shipmentLine', 'attribute');

        $previous_name = '';
        foreach ($xmlNode->childNodes as $childNode) {
            $arrName = str_replace('o:', '', $childNode->nodeName);

            $arrayType = in_array($arrName, $arrayTypes);
            if ($arrayType) {
                array_push($arr, $this->convertXmlToArray($childNode));
            } else {
                if ($childNode->nodeName != '#text' && $childNode->childNodes->length == 1) {
                    $arr[$arrName] = $childNode->nodeValue;
                } elseif ($childNode->nodeName != '#text' && $childNode->childNodes->length > 1) {
                    $arr[$arrName] = $this->convertXmlToArray($childNode);
                }
            }
        }

        return $arr;
    }


    private function getRegionIdByProvince($province, $countryCode)
    {
        $resource = Mage::getSingleton('core/resource');
        $readConnection = $resource->getConnection('core_read');
        $writeConnection = $resource->getConnection('core_write');
        $tablePrefix = Mage::getConfig()->getTablePrefix();
        $regionTable = $tablePrefix . 'directory_country_region';
        $provinceUpper = strtoupper($province);
        $countryUpper = strtoupper($countryCode);

        // get region if exist
        $selectRegionQuery = "SELECT * FROM $regionTable WHERE UPPER(country_id) = '{$countryUpper}' AND (UPPER(default_name) ='{$provinceUpper}' OR UPPER(code) ='{$provinceUpper}')";
        $regionId = $readConnection->fetchOne($selectRegionQuery);

        if (!$regionId) {
            // insert region
            $regoinCode = strtoupper(preg_replace('/\s+/', '', $province));
            $createRegionQuery = "INSERT INTO $regionTable (country_id, code , default_name) VALUES ('{$countryCode}', '{$regoinCode}', '{$province}')";
            $writeConnection->query($createRegionQuery);

            // get new region id
            $regionId = $writeConnection->lastInsertId();
        }

        return $regionId;
    }

    private function _randomPassword()
    {
        $alphabet = "abcdefghijklmnopqrstuwxyzABCDEFGHIJKLMNOPQRSTUWXYZ0123456789";
        $pass = array();
        $alphaLength = strlen($alphabet) - 1;

        for ($i = 0; $i < 8; $i++) {
            $n = rand(0, $alphaLength);
            $pass[] = $alphabet[$n];
        }

        return implode($pass);
    }

    private function cancelItemsOnProductNotExist($order, $fruugoId, $nonExistProductInfo, $orderItemsInfo = null, $fruugoProductId = null)
    {
        $observer = new Fruugo_Integration_Model_Observer;
        $data = array();
        $devMode = Mage::getStoreConfig('integration_options/orders_options/dev_mode');

        if ($devMode == '1') {
            $apiUrl = Mage::getStoreConfig('integration_options/orders_options/order_api_url');

            if (strpos($apiUrl, '127.0.0.1')) {
                $data['mock_api_operation'] = 'cancel';
                $data['orderId'] = $fruugoId;

                if ($fruugoProductId) {
                    $data['mock_api_operation'] = 'cancel_item';
                    $data['orderId'] = $fruugoId;
                    $data['fruugoProductId'] = $fruugoProductId;
                }
            }
        }

        $apiUrl .=  '/cancel';

        $postFields = 'orderId='.$fruugoId;
        if (!empty($orderItemsInfo)) {
            $postFields .= $orderItemsInfo;
        }

        $postFields .= '&cancellationReason=product_discontinued';
        $data['postFields'] = $postFields;
        list($httpcode, $response) = $observer->_sendToApi($apiUrl, $data);

        if ($httpcode == 200) {
            $observer->_saveHistoryComment($order, "Sent notification to Fruugo of cancellation of order {$fruugoId} because the following products do not exist: {$nonExistProductInfo}");
        } else {
            $observer->_saveHistoryComment($order, "Failed to send notification to Fruugo of cancellation of order {$fruugoId}. Server response code: {$httpcode}, response message: {$response}");
        }
    }

    protected function _writeLog($message, $level = Fruugo_Integration_Helper_Logger::DEBUG)
    {
        Fruugo_Integration_Helper_Logger::log($message, $level);
    }
}
