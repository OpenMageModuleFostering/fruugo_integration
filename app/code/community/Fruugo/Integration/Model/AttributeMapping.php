<?php

class Fruugo_Integration_Model_AttributeMapping
    extends Mage_Adminhtml_Model_System_Config_Backend_Serialized_Array
{
    public function save()
    {
        $values = $this->getValue();
        $session = Mage::getSingleton('core/session');

        if (isset($values['__empty'])) {
            unset($values['__empty']);
        }

        if (!sizeof($values)) {
            return parent::save();
        }

        $usedPriorities = array();
        $validMappings = array();

        foreach ($values as $key => $value) {
            if (in_array($value['priority'], $usedPriorities)) {
                $session->addError('Attribute mapping priorities must be unique.');

                continue;
            }

            $usedPriorities[] = $value['priority'];

            if (empty($value['attribute'])) {
                $session->addError('Attribute mapping attributes must not be blank.');

                continue;
            }

            if (empty($value['priority'])) {
                $session->addError('Attribute mapping priorities must not be blank.');

                continue;
            }

            if (!is_numeric($value['priority']) || $value['priority'] < 1) {
                $session->addError('Attribute mapping priorities be positive whole numbers.');

                continue;
            }

            $validMappings[$key] = $value;
        }

        $this->setValue($validMappings);

        return parent::save();
    }
}
