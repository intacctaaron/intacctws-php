<?php
include_once "api_fieldDef.php";
/**
 * Created by JetBrains PhpStorm.
 * User: aaron
 * Date: 4/14/13
 * Time: 1:16 PM
 * To change this template use File | Settings | File Templates.
 */
class api_objDef {

    public $Name;
    public $SingularName;
    public $PluralName;
    public $Description;
    public $Fields;

    public function __construct(simpleXmlElement $simpleXml) {

        //var_export($simpleXml);
        $this->Name = (string)$simpleXml['Name'];
        $this->SingularName = (string)$simpleXml->Attributes->SingularName;
        $this->PluralName = (string)$simpleXml->Attributes->PluralName;
        $this->Description = (string)$simpleXml->Attributes->Description;

        $fields = $simpleXml->Fields;

        $this->Fields = array();
        foreach($fields->Field as $field) {
            $fieldDef = new api_fieldDef($field);
            $this->Fields[$fieldDef->Name] = $fieldDef;
        }
    }

}
