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

require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/ProductsFeedGenerator.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/FruugoCountriesSeeder.php';

class Fruugo_Integration_ProductsController extends Mage_Core_Controller_Front_Action
{
    public function indexAction()
    {
        $productsFeedGenerator = new Fruugo_Integration_ProductsFeedGenerator();
        $productsXml = $productsFeedGenerator->generateProdcutsFeed(false);
        $this->writeProductsFile($productsXml->asXML());
        $this->streamXmlFile($productsXml->asXML());
    }

    public function dataFeedAction()
    {
        $productsFeedGenerator = new Fruugo_Integration_ProductsFeedGenerator();
        $cachedFile = $productsFeedGenerator->generateProdcutsFeed(true);
        if (file_exists($cachedFile)) {
            $productsXmlStr = file_get_contents($cachedFile);
            $this->streamXmlFile($productsXmlStr);
        } else {
            $this->indexAction();
        }
    }

    private function writeProductsFile($productsXmlStr)
    {
        $outputDir = Mage::getModuleDir('', 'Fruugo_Integration') . '/controllers/products.xml';
        $productsFeedFile = fopen($outputDir, "w");
        fwrite($productsFeedFile, $productsXmlStr);
        fclose($productsFeedFile);
    }

    private function streamXmlFile($productsXmlStr)
    {
        if (function_exists('mb_strlen')) {
            $filesize = mb_strlen($productsXmlStr, '8bit');
        } else {
            $filesize = strlen($productsXmlStr);
        }

        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Description: File Transfer');
        header('Content-type: text/xml');
        header('Expires: 0');
        header('Pragma: public');
        header('Content-Disposition: attachment; filename=products.xml');
        header('Content-Length: ' . $filesize);

        $file = fopen('php://output', 'w');
        fwrite($file, $productsXmlStr);
        fclose($file);
        exit;
    }

    public function onCountriesSavedAction()
    {
        $countries = $this->getRequest()->getParam('allowed-countries');
        $productId = $this->getRequest()->getParam('productId');
        $offFruugo = $this->getRequest()->getParam('disable-prd-fruugo');

        if (isset($productId) && $productId != null) {
            $allowedCountries = '';

            if ($offFruugo == 'checked') {
                $allowedCountries = 'Disabled';
            } elseif (!empty($countries)) {
                foreach ($countries as $country) {
                    $allowedCountries .= $country . ' ';
                }

                $allowedCountries = trim($allowedCountries);
            }

            if (!empty($allowedCountries)) {
                $currentDate = new DateTime('NOW');
                $currentDateStr = $currentDate->format('Y-m-d H:i:s');

                try {
                    $productCountries = Mage::getModel('integration/countries')->load($productId, 'product_id');
                    $existingId = isset($productCountries) ? $productCountries->getProductId() : null;
                    if (!empty($existingId)) {
                        $productCountries->setFruugoCountries($allowedCountries);
                        $productCountries->setUpdatedAt($currentDateStr);
                    } else {
                        $data = array(
                            'product_id' => $productId,
                            'fruugo_countries' => $allowedCountries,
                            'created_at' => $currentDateStr
                        );
                        $productCountries = Mage::getModel('integration/countries')->setData($data);
                    }
                    $productCountries->save();
                } catch (Exception $e) {
                    Mage::logException($e);
                }
            } else {
                $productCountries = Mage::getModel('integration/countries')->load($productId, 'product_id');
                if (isset($productCountries) && $productCountries->getProductId() != null) {
                    $productCountries->delete();
                }
            }
        }
        $this->_redirectReferer();
    }

    public function onCountriesRefreshedAction()
    {
        Fruugo_Integration_Helper_FruugoCountriesSeeder::getFruugoCountries();
        $this->_redirectReferer();
    }
}
