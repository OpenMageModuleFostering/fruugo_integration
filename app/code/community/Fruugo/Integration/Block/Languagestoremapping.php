<?php

class Fruugo_Integration_Block_Languagestoremapping extends Mage_Adminhtml_Block_System_Config_Form_Field_Array_Abstract
{
    /**
     * Returns html part of the setting
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $storelangsSettings = Mage::getStoreConfig('integration_options/products_options/language_store');
        $storelangsMapping = unserialize($storelangsSettings);
        $this->setElement($element);

        $html = '<div id="fruugo_integration_language_store_template" style="display:none">';
        $html .= '</div>';

        $html .= '<table class="grid" id="fruugo_integration_language_store_table">
        <tbody>
            <tr class="headings">
                <th>Language</th>
                <th>Default Store</th>
            </tr>';

        $stores = Mage::app()->getStores(false);
        $localeCodes = array();

        foreach ($stores as $store) {
            if ($store->getCode() != 'fruugo') {
                $localeCode = Mage::getStoreConfig('general/locale/code', $store->getId());
                if (!in_array($localeCode, $localeCodes)) {
                    array_push($localeCodes, $localeCode);
                }
            }
        }

        $languageNames = Zend_Locale::getTranslationList('language', Mage::app()->getLocale()->getLocaleCode());

        foreach ($localeCodes as $localeCode) {
            $language = substr($localeCode, 0, strpos($localeCode, '_'));
            $row = '<tr><td><label>'.$languageNames[$language].'</label></td><td><select name="'
                    .$this->getElement()->getName() . '['.$localeCode.']" id="'.$language.'">';
            $row .= '<option value="">- Please choose a store -</option>';

            foreach ($stores as $store) {
                if ($store->getCode() != 'fruugo') {
                    $storeLocaleCode = Mage::getStoreConfig('general/locale/code', $store->getId());
                    if ($localeCode == $storeLocaleCode) {
                        if ($storelangsMapping[$localeCode] == $store->getCode()) {
                            $row .= '<option value="'.$store->getCode().'" selected>'.$store->getCode().'</option>';
                        } else {
                            $row .= '<option value="'.$store->getCode().'">'.$store->getCode().'</option>';
                        }

                    }
                }
            }
            $row .= '</select></td></tr>';
            $html .= $row;
        }

        $html .= '</tbody></table>';

        return $html;
    }
}
