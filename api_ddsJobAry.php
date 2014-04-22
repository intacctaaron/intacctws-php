<?php
/**
 * Created by PhpStorm.
 * User: aharris
 * Date: 4/21/14
 * Time: 4:09 PM
 */

require_once 'intacctws-php/api_ddsJob.php';

class api_ddsJobAry extends api_ddsJob
{

    public function __construct(array $ddsJobAry)
    {
        $simpleXml = new simpleXmlElement("<ddsjob/>");
        foreach ($ddsJobAry as $key => $val) {
            $simpleXml->addChild($key, $val);
        }

        parent::__construct($simpleXml);
    }
} 