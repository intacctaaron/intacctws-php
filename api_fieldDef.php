<?php
/**
 * Created by JetBrains PhpStorm.
 * User: aaron
 * Date: 4/14/13
 * Time: 1:21 PM
 * To change this template use File | Settings | File Templates.
 */
class api_fieldDef {

    public $Name;
    public $GroupName;
    public $dataName;
    public $externalDataName;
    public $isRequired;
    public $isReadOnly;
    public $maxLength;
    public $DisplayLabel;
    public $Description;
    public $id;

    public function __construct(simpleXmlElement $simpleXml) {
        $this->Name = (string)$simpleXml->Name;
        $this->GroupName = (string)$simpleXml->GroupName;
        $this->dataName = (string)$simpleXml->dataName;
        $this->externalDataName = (string)$simpleXml->externalDataName;
        $this->isRequired = (strtolower((string)$simpleXml->isRequired) == 'true') ? true : false;
        $this->isReadOnly = (strtolower((string)$simpleXml->isReadOnly) == 'true') ? true : false;
        $this->maxLength = (int)$simpleXml->maxLengt;
        $this->DisplayLabel = (string)$simpleXml->DisplayLabel;
        $this->Description = (string)$simpleXml->Description;
        $this->id = (string)$simpleXml->id;
    }

    public function __toString() {
        return $this->Name;
    }
}