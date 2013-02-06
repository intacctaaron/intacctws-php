<?
include_once('api_post.php');

class api_session {

    public $sessionId;
    public $endPoint;
    public $companyId;
    public $userId;
    public $senderId;
    public $senderPassword;

    const XML_HEADER = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>                                                                   
<request>                                                                                                                            
    <control>                                                                                                                        
        <senderid>{4%}</senderid>                                                                                                    
        <password>{5%}</password>                                                                                                    
        <controlid>foobar</controlid>                                                                                                
        <uniqueid>false</uniqueid>                                                                                                   
        <dtdversion>3.0</dtdversion>                                                                                                 
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
                </login>";

    const XML_SESSIONID = "<sessionid>{1%}</sessionid>";

    const DEFAULT_LOGIN_URL = "https://api.intacct.com/ia/xml/xmlgw.phtml";

    /**
     * Connect to the Intacct Web Service using a set of user credntials
     * @param String $companyId company to connect to
     * @param String $userId user
     * @param String $password The users's password
     * @param String $senderId Your Intacct Partner sender id
     * @param String $senderPassword Your Intacct Partner password
     * @param String $endPoint the URL to connect with.
     * @throws Exception this method returns no value, but will raise any connection exceptions
     */
    public function connectCredentials($companyId, $userId, $password, $senderId, $senderPassword) {

        $xml = self::XML_HEADER . self::XML_LOGIN . self::XML_FOOTER;

        $xml = str_replace("{1%}", $userId, $xml);
        $xml = str_replace("{2%}", $companyId, $xml);
        $xml = str_replace("{3%}", $password, $xml);
        $xml = str_replace("{4%}", $senderId, $xml);
        $xml = str_replace("{5%}", $senderPassword, $xml);
        $response = api_post::execute($xml, self::DEFAULT_LOGIN_URL);

        self::validateConnection($response);

        $responseObj = simplexml_load_string($response);

        $this->sessionId = (string)$responseObj->operation->result->data->api->sessionid;
	//        $this->endPoint = (string)$responseObj->operation->result->data->api->endpoint;
	// There's currently a defect in the getAPISession method where the URL returned is not HTTPS
	$this->endPoint = self::DEFAULT_LOGIN_URL;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->senderId = $senderId;
        $this->senderPassword = $senderPassword;

    }

    /**
     * Create a session with the Intacct Web Services with an existing session.
     * You'll normally get the sessionid using a merge field (or injection parameter)
     * in an HTTP trigger or integration link
     * @param String $sessionId a valid Intacct session Id
     * @param String $senderId Your Intacct partner sender id
     * @param String $senderPassword Your Intacct partner password
     * @throws Exception This method returns no values, but will raise an exception if there's a connection error
     */
    public function connectSessionId($sessionId, $senderId, $senderPassword) {

        $xml = self::XML_HEADER . self::XML_SESSIONID . self::XML_FOOTER;
        $xml = str_replace("{1%}", $sessionId, $xml);
        $xml = str_replace("{4%}", $senderId, $xml);
        $xml = str_replace("{5%}", $senderPassword, $xml);

        $response = api_post::execute($xml, self::DEFAULT_LOGIN_URL);

        self::validateConnection($response);

        $responseObj = simplexml_load_string($response);
        $this->sessionId = (string)$responseObj->operation->result->data->api->sessionid;
        $this->companyId = (string)$responseObj->operation->authentication->companyid;
        $this->userId = (string)$responseObj->operation->authentication->userid;
	//        $this->endPoint = (string)$responseObj->operation->result->data->sessioninfo->endpoint;
	// there's a defect in the getAPISession method where the URL returned is not HTTPS
	$this->endPoint = self::DEFAULT_LOGIN_URL;
        $this->senderId = $senderId;
        $this->senderPassword = $senderPassword;

    }

    private static function validateConnection($response) {
        $simpleXml = simplexml_load_string($response);	
        if ($simpleXml === false) {
            throw new Exception("Invalid XML response: \n" . var_export($response, true));
        }

	if ((string)$simpleXml->control->status == 'failure') {
	  throw new Exception(api_util::xmlErrorToString($simpleXml->errormessage));
	}

        if (!isset($simpleXml->operation)) {
            if (isset($simpleXml->errormessage)) {
	      throw new Exception(api_util::xmlErrorToString($sipmleXml->errormessage->error[0]));
            }
        }

        $status = $simpleXml->operation->result->status;
        if ((string)$status != 'success') {
            $error = $simpleXml->operation->result->errormessage;
            throw new Exception(" [Error] " . (string)$error->error[0]->description2);
        }
        else {
            return; // no error found.                                                                                               
        }

    }

}

?>
