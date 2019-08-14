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

class Fruugo_Integration_Block_Catalog_Product_Tab extends Mage_Adminhtml_Block_Template implements Mage_Adminhtml_Block_Widget_Tab_Interface
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('integration/catalog/product/fruugo-allowed-countries.phtml');
    }

    public function getTabLabel()
    {
        return $this->__('Countries allowed on Fruugo');
    }

    public function getTabTitle()
    {
        return $this->__('Countries allowed on Fruugo');
    }

    public function canShowTab()
    {
        return true;
    }

    public function isHidden()
    {
        return false;
    }

    public function getProduct()
    {
        return Mage::registry('current_product');
    }

    public function getCountrySelectForm()
    {
        $productId = $this->getProduct()->getId();
        $actionLink = Mage::getUrl(
            'integration/products/onCountriesSaved',
            array('productId' => $productId)
        );

        $existingProductCountries = Mage::getModel('integration/countries')->load($productId, 'product_id');
        $existingList = isset($existingProductCountries) ? $existingProductCountries->getFruugoCountries() : '';

        $countryList = array();
        $fruugoCountriesFilePath = Mage::getModuleDir('', 'Fruugo_Integration') . '/'. Fruugo_Integration_Helper_Defines::FRUUGO_COUNTRIES_FILE_NAME;
        if (file_exists($fruugoCountriesFilePath)) {
            $fruugoCountriesJson = file_get_contents($fruugoCountriesFilePath);
            $countryList = json_decode($fruugoCountriesJson, true);
        } else {
            $countryList = Mage::getModel('directory/country')->getResourceCollection()
                    ->loadByStore()
                    ->toOptionArray(false);
        }

        $countrySelect = '<select name="allowed-countries[]" id="prd-country-select" multiple>';

        foreach ($countryList as $country) {
            if (!empty($country['value'])) {
                if (strpos($existingList, $country['value']) !== false) {
                    $countrySelect .= '<option value="'.$country['value'].'" selected>'.$country['label'].'</option>';
                } else {
                    $countrySelect .= '<option value="'.$country['value'].'">'.$country['label'].'</option>';
                }
            }
        }

        $countrySelect .= '</select>';
        $countrySelectForm = '<form action="'.$actionLink.'">'
                            .$countrySelect
                            .'<p><input type="checkbox" name="disable-prd-fruugo" value="checked" '. ($existingList == 'Disabled' ? 'checked' : '') .'> Disable this product completely from Fruugo</p>'
                            .'<p><input type="submit" id="restrict-prd-country" /></p></form>';

        return $countrySelectForm;
    }
}
