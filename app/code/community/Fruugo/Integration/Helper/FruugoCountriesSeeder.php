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
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Logger.php';
require_once Mage::getModuleDir('', 'Fruugo_Integration') . '/Helper/Defines.php';
use \DOMDocument as DOMDocument;
use \DOMXpath as DOMXpath;

class Fruugo_Integration_Helper_FruugoCountriesSeeder extends Mage_Core_Helper_Abstract
{
    public static function getFruugoCountries()
    {
        Fruugo_Integration_Helper_Logger::log('Pulling Fruugo countries information');
        $fruugoCountriesXml = @file_get_contents(Fruugo_Integration_Helper_Defines::FRUUGO_COUNTRIES_ENDPOINT);
        if ($fruugoCountriesXml !== false) {
            $doc = new DOMDocument();
            $doc->loadXML($fruugoCountriesXml);
            $xpath = new DOMXpath($doc);
            $countriesXml = $xpath->query('//CountryInfo');
            $orderFeedProcessor = new Fruugo_Integration_OrdersFeedProcessor();

            $fruugoCountries = array();
            foreach ($countriesXml as $countryXml) {
                $countryArray = $orderFeedProcessor->convertXmlToArray($countryXml);
                $countryName = $countryArray['CountryName'];
                $countryCode = $countryArray['CountryCode'];

                array_push($fruugoCountries, array(
                    'value' => $countryCode,
                    'label' => $countryName
                ));
            }

            self::arraySortByColumn($fruugoCountries, 'label');
            $fruugoCountriesJson = json_encode($fruugoCountries);
            $path = Mage::getModuleDir('', 'Fruugo_Integration') . '/'. Fruugo_Integration_Helper_Defines::FRUUGO_COUNTRIES_FILE_NAME;
            file_put_contents($path, $fruugoCountriesJson);
        } else {
            Fruugo_Integration_Helper_Logger::log('Failed pulling Fruugo countries information. Either the URL is not valid any more or there was a network error.');
        }
    }

    private static function arraySortByColumn(&$arr, $col, $dir = SORT_ASC)
    {
        $sort_col = array();
        foreach ($arr as $key => $row) {
            $sort_col[$key] = $row[$col];
        }

        array_multisort($sort_col, $dir, $arr);
    }
}
