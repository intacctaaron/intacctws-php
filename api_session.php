<?php
/**
 * Copyright (c) 2013, Intacct OpenSource Initiative
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following
 * disclaimer in the documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
 * STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN
 * IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * OVERVIEW
 * The general pattern for using this SDK is to first create an instance of api_session and call either
 * connectCredentials or connectSessionId to start an active session with the Intacct Web Services gateway.
 * You will then pass the api_session as an argument in the api_post class methods.  intacctws-php handles all
 * XML serialization and de-serialization and HTTPS transport.
 */

include_once('api_post.php');

/**
 * Class api_session  Object used to maintain connection information with Intacct Web Services
 */
class api_session
{

    public $sessionId;
    public $endPoint;
    public $companyId;
    public $userId;
    public $senderId;
    public $senderPassword;
    public $transaction = false;

    const XML_HEADER = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<request>
    <control>
        <senderid>{4%}</senderid>
        <password>{5%}</password>
        <controlid>foobar</controlid>
        <uniqueid>false</uniqueid>
        <dtdversion>2.1</dtdversion>
        {6%}
    </control>
    <operation>
        <authentication>";

    const XML_FOOTER = "</authentication>
        <content>
                <function controlid=\"foobar\"><getAPISession></getAPISession></function>
        </content>
    </operation>
</request>";

    const XML_LOGIN = "<login>
                        <userid>{1%}</userid>
                        <companyid>{2%}</companyid>
                        <password>{3%}</password>
                        {%entityid%}
                </login>";

    const XML_SESSIONID = "<sessionid>{1%}</sessionid>";

    const DEFAULT_LOGIN_URL = "https://api.intacct.com/ia/xml/xmlgw.phtml";

    private $responseValidation;

    const VALIDATE_NONE = 'none';
    const VALIDATE_SERVER = 'server';
    const VALIDATE_CLIENT = 'client';


    /**
     * Build the XML Request header
     *
     * @param String $companyId      company to connect to
     * @param String $userId         user
     * @param String $password       The users's password
     * @param String $senderId       Your Intacct Partner sender id
     * @param String $senderPassword Your Intacct Partner password
     * @param String $entityType     location || client
     * @param String $entityId       The sub entity id
     *
     * @return mixed
     * @throws Exception this method returns no value, but will raise any connection exceptions
     */
    private function buildHeaderXML(
        $companyId, $userId, $password, $senderId, $senderPassword, $entityType = null, $entityId = null
    ) {

        $xml = self::XML_HEADER . self::XML_LOGIN . self::XML_FOOTER;

        $xml = str_replace("{1%}", $userId, $xml);
        $xml = str_replace("{2%}", $companyId, $xml);
        $xml = str_replace("{3%}", $password, $xml);
        $xml = str_replace("{4%}", $senderId, $xml);
        $xml = str_replace("{5%}", $senderPassword, $xml);

        if (is_null($this->responseValidation)) {
            $xml = str_replace("{6%}", "", $xml);
        } else {
            $xml = str_replace("{6%}", "<validate>" . $this->responseValidation . "</validate>", $xml);
        }

        if ($entityType == 'location') {
            $xml = str_replace("{%entityid%}", "<locationid>$entityId</locationid>", $xml);
        } else if ($entityType == 'client') {
            $xml = str_replace("{%entityid%}", "<clientid>$entityId</clientid>", $xml);
        } else {
            $xml = str_replace("{%entityid%}", "", $xml);
        }

        return $xml;
    }

    /**
     * Connect to the Intacct Web Service using a set of user credentials
     *
     * @param String $companyId      company to connect to
     * @param String $userId         user
     * @param String $password       The users's password
     * @param String $senderId       Your Intacct Partner sender id
     * @param String $senderPassword Your Intacct Partner password
     * @param null   $entityType     location or client
     * @param null   $entityId       ID of the location or client
     * @param null   $loginUrl       Optional login URL if you are not posting to production
     *
     * @return null
     */
    public function connectCredentials(
        $companyId, $userId, $password, $senderId, $senderPassword, $entityType=null, $entityId=null, $loginUrl=null
    ) {

        $xml = $this->buildHeaderXML(
            $companyId, $userId, $password, $senderId, $senderPassword, $entityType, $entityId
        );

        $loginUrl = (!is_null($loginUrl)) ? $loginUrl : self::DEFAULT_LOGIN_URL;
        $response = api_post::execute($xml, $loginUrl);

        self::validateConnection($response);

        $responseObj = simplexml_load_string($response);

        $this->sessionId = (string)$responseObj->operation->result->data->api->sessionid;
        $this->endPoint = (string)$responseObj->operation->result->data->api->endpoint;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->senderId = $senderId;
        $this->senderPassword = $senderPassword;
    }


    /**
     * Connect to the Intacct Web Service using a set of user credentials for a subentity
     *
     * @param String $companyId      company to connect to
     * @param String $userId         user
     * @param String $password       The users's password
     * @param String $senderId       Your Intacct Partner sender id
     * @param String $senderPassword Your Intacct Partner password
     * @param String $entityType     location || client
     * @param String $entityId       Entity ID or Client ID
     *
     * @internal param String $clientid The sub entity id
     * @return null
     */
    public function connectCredentialsEntity(
        $companyId, $userId, $password, $senderId, $senderPassword,$entityType, $entityId
    ) {

        $xml = $this->buildHeaderXML(
            $companyId, $userId, $password, $senderId, $senderPassword, $entityType, $entityId
        );

        $response = api_post::execute($xml, self::DEFAULT_LOGIN_URL);

        self::validateConnection($response);

        $responseObj = simplexml_load_string($response);

        $this->sessionId = (string)$responseObj->operation->result->data->api->sessionid;
        $this->endPoint = (string)$responseObj->operation->result->data->api->endpoint;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->senderId = $senderId;
        $this->senderPassword = $senderPassword;
    }

    /**
     * Create a session with the Intacct Web Services with an existing session.
     * You'll normally get the sessionid using a merge field (or injection parameter)
     * in an HTTP trigger or integration link
     *
     * @param String $sessionId      a valid Intacct session Id
     * @param String $senderId       Your Intacct partner sender id
     * @param String $senderPassword Your Intacct partner password
     *
     * @throws Exception This method returns no values, but will raise an exception if there's a connection error
     * @return null
     */
    public function connectSessionId($sessionId, $senderId, $senderPassword)
    {

        $xml = self::XML_HEADER . self::XML_SESSIONID . self::XML_FOOTER;
        $xml = str_replace("{1%}", $sessionId, $xml);
        $xml = str_replace("{4%}", $senderId, $xml);
        $xml = str_replace("{5%}", $senderPassword, $xml);

        if (is_null($this->getResponseValidation())) {
            $xml = str_replace("{6%}", "", $xml);
        } else {
            $xml = str_replace("{6%}", "<validate>" . $this->getResponseValidation() . "</validate>", $xml);
        }

        $response = api_post::execute($xml, self::DEFAULT_LOGIN_URL);

        self::validateConnection($response);

        $responseObj = simplexml_load_string($response);
        $this->sessionId = (string)$responseObj->operation->result->data->api->sessionid;
        $this->companyId = (string)$responseObj->operation->authentication->companyid;
        $this->userId = (string)$responseObj->operation->authentication->userid;
        $this->endPoint = (string)$responseObj->operation->result->data->api->endpoint;
        $this->senderId = $senderId;
        $this->senderPassword = $senderPassword;
    }

    /**
     * Set the ResponseValidation property
     *
     * @param mixed $responseValidation One of the VALIDATE* constants
     *
     * @return null
     * @throws Exception
     */
    public function setResponseValidation($responseValidation)
    {
        $validValues = array(
            self::VALIDATE_SERVER, self::VALIDATE_CLIENT, self::VALIDATE_NONE
        );
        if (!in_array($responseValidation, $validValues)) {
            throw new Exception("$responseValidation is invalid.  Use one of the class constants.");
        }
        $this->responseValidation = $responseValidation;
    }

    /**
     * Get the response validation value
     *
     * @return mixed
     */
    public function getResponseValidation()
    {
        return $this->responseValidation;
    }



    /**
     * Validate the repsonse from Intacct to see if we successfully created a session
     *
     * @param String $response The XML response from Intacct
     *
     * @throws Exception
     * @return null
     */
    private static function validateConnection($response)
    {
        $simpleXml = simplexml_load_string($response);
        if ($simpleXml === false) {
            throw new Exception("Invalid XML response: \n" . var_export($response, true));
        }

        if ((string)$simpleXml->control->status == 'failure') {
            throw new Exception(api_util::xmlErrorToString($simpleXml->errormessage));
        }

        if (!isset($simpleXml->operation)) {
            if (isset($simpleXml->errormessage)) {
                throw new Exception(api_util::xmlErrorToString($simpleXml->errormessage->error[0]));
            }
        }

        if (isset($simpleXml->operation->authentication->status)) {
            if ($simpleXml->operation->authentication->status != 'success') {
                $error = $simpleXml->operation->errormessage;
                throw new Exception(" [Error] " . (string)$error->error[0]->description2);
            }
        }

        $status = $simpleXml->operation->result->status;
        if ((string)$status != 'success') {
            $error = $simpleXml->operation->result->errormessage;
            throw new Exception(" [Error] " . (string)$error->error[0]->description2);
        } else {
            return; // no error found.
        }
    }
}
