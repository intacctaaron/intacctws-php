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
        <dtdversion>2.1</dtdversion>                                                                                                 
    </control>                                                                                                                       
    <operation>                                                                                                                      
        <authentication>";

    const XML_FOOTER = "</authentication>                                                                                            
        <content>                                                                                                                    
                <function controlid=\"foobar\"><init_session/></function>                                                            
        </content>                                                                                                                   
    </operation>                                                                                                                     
</request>";

    const XML_LOGIN = "<login>                                                                                                       
                        <userid>{1%}</userid>                                                                                        
                        <companyid>{2%}</companyid>                                                                                  
                        <password>{3%}</password>                                                                                    
                </login>";

    const XML_SESSIONID = "<sessionid>{1%}</sessionid>";

    public function connectCredentials($companyId, $userId, $password, $senderId, $senderPassword, $endPoint) {

        $xml = self::XML_HEADER . self::XML_LOGIN . self::XML_FOOTER;

        $xml = str_replace("{1%}", $userId, $xml);
        $xml = str_replace("{2%}", $companyId, $xml);
        $xml = str_replace("{3%}", $password, $xml);
        $xml = str_replace("{4%}", $senderId, $xml);
        $xml = str_replace("{5%}", $senderPassword, $xml);
        $response = api_post::execute($xml, $endPoint);

        self::validateConnection($response);

        $responseObj = simplexml_load_string($response);

        $this->sessionId = $responseObj->operation->result->data->sessioninfo->session;
        $this->endPoint = $endPoint;
        $this->companyId = $companyId;
        $this->userId = $userId;
        $this->senderId = $senderId;
        $this->senderPassword = $senderPassword;

    }

    public function connectSessionId($sessionId, $senderId, $senderPassword, $endPoint) {

        $xml = self::XML_HEADER . self::XML_SESSIONID . self::XML_FOOTER;
        $xml = str_replace("{1%}", $sessionId, $xml);
        $xml = str_replace("{4%}", $senderId, $xml);
        $xml = str_replace("{5%}", $senderPassword, $xml);

	// debug only
	//	$endPoint = "https://www.intacct.com/ia/xml/xmlgw.phtml";
        $response = api_post::execute($xml, $endPoint);

        self::validateConnection($response);

        $responseObj = simplexml_load_string($response);

        $this->sessionId = $responseObj->operation->result->data->sessioninfo->session;
        $this->companyId = $responseObj->operation->authentication->companyid;
        $this->userId = $responseObj->operation->authentication->userid;
        $this->endPoint = $endPoint;
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
