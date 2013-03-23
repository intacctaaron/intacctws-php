<?
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

include_once('api_util.php');
include_once('api_viewFilter.php');
include_once('api_viewFilters.php');
include_once('api_returnFormat.php');

/**
 * Class api_post
 */
class api_post {

    private static $lastRequest;
    private static $lastResponse;

    const DEFAULT_PAGESIZE = 1000;
    const DEFAULT_MAXRETURN = 100000;

    /**
     * Read one or more records by their key.  For platform objects, the key is the 'id' field.
     * For standard objects, the key is the 'recordno' field.  Results are returned as a php structured array
     * @param String $object the integration name for the object
     * @param String $id a comma separated list of keys for each record you wish to read
     * @param String $fields a comma separated list of fields to return
     * @param \api_session|Object $session an instance of the php_session object
     * @return Array of records
     */
    public static function read($object, $id, $fields, api_session $session) {

        $readXml = "<read><object>$object</object><keys>$id</keys><fields>$fields</fields><returnFormat>csv</returnFormat></read>";
        $objCsv = api_post::post($readXml, $session);
        api_post::validateReadResults($objCsv);
        $objAry = api_util::csvToPhp($objCsv);
        if (count(explode(",",$id)) > 1) {
            return $objAry;
        }
        else {
            return $objAry[0];
        }
    }

    /**
     * Create one or more records.  Object types can be mixed and can be either standard or custom.
     * Check the developer documentation to see which standard objects are supported in this method
     * @param Array $records is an array of records to create.  Follow the pattern
     * $records = array(array('myobjecttype' => array('field1' => 'value',
     *                                                'field2' => 'value')),
     *                  array('myotherobjecttype' => array('field1' => 'value',
     *                                                     'field2' => 'value')));
     * @param api_session $session instance of api_session object with valid connection
     * @throws Exception
     * @return Array array of keys to the objects created
     */
    public static function create($records, api_session $session) {

        if (count($records) > 100) throw new Exception("Attempting to create more than 100 records. (" . count($records) . ") ");

        // Convert the record into an xml structure                                                                                 
        $createXml = "<create>";
        $node = "";
        foreach($records as $record) {
            $nodeAry = array_keys($record);
            $node = $nodeAry[0];
            $objXml = api_util::phpToXml($node, $record[$node]);
            $createXml = $createXml . $objXml;
        }
        $createXml = $createXml . "</create>";
        $res = api_post::post($createXml, $session);
        $records = api_post::processUpdateResults($res, $node);

        return $records;
    }

    /**
     * Update one or more records.  Object types can be mixed and can be either standard or custom.
     * Check the developer documentation to see which standard objects are supported in this method
     * @param Array $records an array of records to update.  Follow the pattern
     * $records = array(array('mycustomobjecttype' => array('id' => 112233, // you must pass the id value
     *                                                      'updatefield' => 'updateValue')),
     *                  array('mystandardobjecttype' => array('recordno' => 555, // you must pass the recordno value for standard objects
     *                                                        'updatefield' => 'updateValue')));
     * @param api_session $session
     * @throws Exception
     * @return array An array of 'ids' updated in the method invocation
     */
    public static function update($records, api_session $session) {
        if (count($records) > 100) throw new Exception("Attempting to update more than 100 records.");

        // convert the $records array into an xml structure                                                                          
        $updateXml = "<update>";
        $node = '';
        foreach($records as $record) {
            $nodeAry = array_keys($record);
            $node = $nodeAry[0];
            $objXml = api_util::phpToXml($node, $record[$node]);
            $updateXml = $updateXml . $objXml;
        }
        $updateXml = $updateXml . "</update>";

        $res = api_post::post($updateXml, $session);
        return api_post::processUpdateResults($res, $node);

    }

    /**
     * Delete one or more records
     * @param String $object integration code of object type to delete
     * @param String $ids String a comma separated list of keys.  use 'id' values for custom
     * objects and 'recordno' values for standard objects
     * @param api_session $session instance of api_session object
     */
    public static function delete($object, $ids, api_session $session) {
        $deleteXml = "<delete><object>$object</object><keys>$ids</keys></delete>";
        api_post::post($deleteXml, $session);
    }

    /**
     * Run any Intacct API method not directly implemented in this class.  You must pass
     * valid XML for the method you wish to invoke.
     * @param String $xml valid XML for the method you wish to invoke
     * @param api_session $session an api_session instance with a valid connection
     * @param string $dtdVersion Either "2.1" or "3.0" defaults to "3.0"
     * @return String the XML response from Intacct
     */
    public static function otherMethod($xml, api_session $session, $dtdVersion="3.0") {
        return api_post::post($xml, $session,$dtdVersion);
    }

    /**
     * Run any Intacct API method not directly implemented in this class.  You must pass
     * valid XML for the method you wish to invoke.
     * @param String $function for 2.1 function (create_sotransaction, etc)
     * @param Array $phpObj an array for the object.  Do not nest in another array() wrapper
     * @param api_session $session  an api_session instance with a valid connection
     * @return String the XML response from Intacct
     */
    public static function call21Method($function, $phpObj, api_session $session) {
        $xml = api_util::phpToXml($function,array($phpObj));
        return api_post::post($xml, $session,"2.1");
    }

    /**
     * Return the records defined in a platform view.  Views define an object, a collection of field, sorting, and filtering.  You may pass additional filters
     * via the api_viewFilters object
     * @param String $viewName either the textual name of the view or the original id of the view (object#originalid).  Note view names are not guaranteed to be
     * unique, so you are always safer referencing the original id
     * @param api_session $session instance of the api session object
     * @param api_viewFilters $filterObj Object instance of the api_viewFilters object
     * @param int $maxRecords defaults to 100000
     * @param string $returnFormat String defaults to phpobj.  Use one of the constants defined in api_returnFormat class
     * @throws Exception
     * @return Mixed Depends on the return format argument.  Returns a string unless phpobj is the return format in which case returns an array
     */
    public static function readView($viewName, api_session $session, api_viewFilters $filterObj=NULL, $maxRecords = self::DEFAULT_MAXRETURN, $returnFormat = api_returnFormat::PHPOBJ) {

        $pageSize = ($maxRecords <= self::DEFAULT_PAGESIZE) ? $maxRecords : self::DEFAULT_PAGESIZE;

        // set the return format
        api_returnFormat::validateReturnFormat($returnFormat);
        if ($returnFormat == api_returnFormat::PHPOBJ) {
            $returnFormatArg = api_returnFormat::CSV;
        }
        else {
            $returnFormatArg = $returnFormat;
        }

        // process the filters array
        $filtersXmlStr = '';
        if ($filterObj !== NULL) {
            $filters = $filterObj->filters;
            $condition = $filterObj->operator;
            $filtersXml = array();
            foreach($filters as $filter) {
                $filtersXml[] = "<filterExpression><field>{$filter->field}</field><operator>{$filter->operator}</operator><value>{$filter->value}</value></filterExpression>";
            }
            $filtersXmlStr = "<filters><filterCondition>$condition</filterCondition>" . join("",$filtersXml) . "</filters>";
        }

        $viewName = HTMLSpecialChars($viewName);

        $readXml="<readView><view>$viewName</view><pagesize>$pageSize</pagesize><returnFormat>$returnFormatArg</returnFormat>$filtersXmlStr</readView>";
        $response = api_post::post($readXml, $session);
        api_post::validateReadResults($response);
        $phpobj = array(); $csv = ''; $json = ''; $xml = ''; $count = 0;
        $$returnFormat = self::processReadResults($response, $returnFormat, $count);

        if ($count == $pageSize && $count < $maxRecords) {
            while (TRUE) {
                $readXml = "<readMore><view>$viewName</view></readMore>";
                try {
                    $response = api_post::post($readXml, $session);
                    api_post::validateReadResults($response);
                    $page = self::processReadResults($response, $returnFormat, $pageCount);
                    $count += $pageCount;
                    if ($returnFormat == api_returnFormat::PHPOBJ) {
                        foreach($page as $objRec) {
                            $phpobj[] = $objRec;
                        }
                    }
                    elseif ($returnFormat == api_returnFormat::CSV) {
                        // append all but the first row to the CSV file
                        $page = explode("\n", $page);
                        array_shift($page);
                        $csv .= implode($page, "\n");
                    }
                    elseif ($returnFormat == api_returnFormat::XML) {
                        // just add the xml string
                        $xml .= $page;
                    }
                }
                catch (Exception $ex) {
                    // for now, pass the exception on
                    Throw new Exception($ex);
                }
                if ($pageCount < $pageSize || $count >= $maxRecords) break;
            }
        }
        return $$returnFormat;
    }

    /**
     * Read records using a query.  Specify the object you want to query and something like a "where" clause"
     * @param String $object the object upon which to run the query
     * @param String $query the query string to execute.  Use SQL operators
     * @param String $fields A comma separated list of fields to return
     * @param api_session $session An instance of the api_session object with a valid connection
     * @param int $maxRecords number of records to return.  Defaults to 100000
     * @param string $returnFormat defaults to php object.  Pass one of the valid constants from api_returnFormat class
     * @return mixed either string or array of objects depending on returnFormat argument
     */
    public static function readByQuery($object, $query, $fields, api_session $session, $maxRecords=self::DEFAULT_MAXRETURN, $returnFormat=api_returnFormat::PHPOBJ) {
        // TODO: Implement returnFormat.  Today we only support PHPOBJ
        $query = HTMLSpecialChars($query);
        $readXml = "<readByQuery><object>$object</object><query>$query</query><fields>$fields</fields><returnFormat>csv</returnFormat>";
        if ($maxRecords < 100) {
            $readXml .= "<pagesize>$maxRecords</pagesize>";
        }
        $readXml .= "</readByQuery>";
        $objCsv = api_post::post($readXml,$session);
        api_post::validateReadResults($objCsv);
        $objAry = api_util::csvToPhp($objCsv);
        $count = count($objAry);
        while(count($objAry) >= 100 && $count <= $maxRecords) {
            $readXml = "<readMore><object>$object</object></readMore>";
            try {
                $objCsv = api_post::post($readXml, $session);
                api_post::validateReadResults($objCsv);
                $objPage = api_util::csvToPhp($objCsv);
                if (!is_array($objPage) || count($objPage) == 0) break;
                foreach($objPage as $objRec) {
                    $objAry[] = $objRec;
                }
                $count += count($objPage); // could just do a count of objAry, but this performs better                              
            }
            catch (Exception $ex) {
                // we've probably exceeded the limit                                                                                 
                break;
            }
        }
        return $objAry;
    }

    /**
     * Inspect an object to get a list of its fields
     * @param String $object The integration name of the object.  Pass '*' to get a complete list of objects
     * @param String $detail Whether or not to return data type information for the fields.
     * @param api_session $session Instance of an api_session object with a valid connection
     * @return String the raw xml returned by Intacct
     */
    public static function inspect($object, $detail, api_session $session) {
      $inspectXML = "<inspect detail='$detail'><object>$object</object></inspect>";
      $objAry = api_post::post($inspectXML, $session);
      return $objAry;
    }

    /**                                                                                                                              
     * Read an object by its name field (vid for standard objects)                                                                   
     * @param String $object object type                                                                                                
     * @param String $name comma separated list of names.                                                                               
     * @param String $fields comma separated list of fields.                            
     * @param api_session $session  instance of api_session object.
     * @return Array of objects.  If only one name is passed, the fields will be directly accessible.                                
     */
    public static function readByName($object, $name, $fields, api_session $session) {
        $name = HTMLSpecialChars($name);
        $readXml = "<readByName><object>$object</object><keys>$name</keys><fields>$fields</fields><returnFormat>csv</returnFormat></readByName>";
        $objCsv = api_post::post($readXml,$session);
        api_post::validateReadResults($objCsv);
        $objAry = api_util::csvToPhp($objCsv);
        if (count(explode(",",$name)) > 1) {
            return $objAry;
        }
        else {
            return $objAry[0];
        }
    }

    /**
     * Reads all the records related to a source record through a named relationship.
     * @param String $object the integration name of the object
     * @param String $keys a comma separated list of 'id' values of the source records from which you want to read related records
     * @param String $relation the name of the relationship.  This will determine the type of object you are reading
     * @param String $fields a comma separated list of fields to return
     * @param api_session $session
     * @return Array of objects
     */
    public static function readRelated($object, $keys, $relation, $fields, api_session $session) {
        $readXml = "<readRelated><object>$object</object><keys>$keys</keys><relation>$relation</relation><fields>$fields</fields><returnFormat>csv</returnFormat></readRelated>";
        $objCsv = api_post::post($readXml, $session);
        api_post::validateReadResults($objCsv);
        $objAry = api_util::csvToPhp($objCsv);
        return $objAry;
    }

    /**                                                                                                                              
     * WARNING: This method will attempt to delete all records of a given object type                                                
     * Deletes first 10000 by default                                                                                                
     * @param String $object object type                                                                                                
     * @param api_session $session instance of api_session object.
     * @param Integer $max [optional] Maximum number of records to delete.  Default is 10000                                              
     * @return Integer count of records deleted                                                                                      
     */
    public static function deleteAll($object, api_session $session, $max=10000) {

        // read all the record ids for the given object                                                                              
        $ids = api_post::readByQuery($object, "id > 0", "id", $session, $max);

        if (!count($ids) > 0) {
            return 0;
        }

        $count = 0;
        $delIds = array();
        foreach($ids as $rec) {
            $delIds[] = $rec['id'];
            if (count($delIds) == 100) {
                api_post::delete($object, implode(",", $delIds), $session);
                $count += 100;
                $delIds = array();
            }
        }

        if (count($delIds) > 0) {
            api_post::delete($object, implode(",", $delIds), $session);
            $count += count($delIds);
        }

        return $count;
    }

    /**
     * Internal method for posting the invocation to the Intacct XML Gateway
     * @param String $xml the XML request document
     * @param api_session $session
     * @param string $dtdVersion
     * @throws Exception
     * @return String the XML response document
     */
    private static function post($xml, api_session $session, $dtdVersion="3.0") {

        $sessionId = $session->sessionId;
        $endPoint = $session->endPoint;
        $senderId = $session->senderId;
        $senderPassword = $session->senderPassword;

        $templateHead =
"<?xml version=\"1.0\" encoding=\"UTF-8\"?>                                                                                          
<request>                                                                                                                            
    <control>                                                                                                                        
        <senderid>{2%}</senderid>                                                                                                    
        <password>{3%}</password>                                        
        <controlid>foobar</controlid>
        <uniqueid>false</uniqueid>                                                                                                   
        <dtdversion>{4%}</dtdversion>                                                                                                 
    </control>                                                                                                                       
    <operation>                                                                                                                      
        <authentication>                                                                                                             
            <sessionid>{1%}</sessionid>                                                                                              
        </authentication>                                                                                                            
        <content>                                                                                                                    
            <function controlid=\"foobar\">";

        $templateFoot =
            "</function>                                                                                                             
        </content>                                                                                                                   
    </operation>                                                                                                                     
</request>";

        $xml = $templateHead . $xml . $templateFoot;
        $xml = str_replace("{1%}", $sessionId, $xml);
        $xml = str_replace("{2%}", $senderId, $xml);
        $xml = str_replace("{3%}", $senderPassword, $xml);
        $xml = str_replace("{4%}", $dtdVersion, $xml);

        $count = 0; // retry five times on too many operations
        $res = "";
        while (true) {
            $res = api_post::execute($xml, $endPoint);

            // If we didn't get a response, we had a poorly constructed XML request.
            try {
                api_post::validateResponse($res, $xml);
                break;
            }
            catch (Exception $ex) {
                if (strpos($ex->getMessage(), "too many operations") !== false) {
                    $count++;
                    if ($count >= 5) {
                        throw new Exception($ex);
                    }
                } else {
                    throw new Exception($ex);
                }
            }
        }
        return $res;
    }

    /**
     * You won't normally use this function, but if you just want to pass a fully constructed XML document
     * to Intacct, then use this function.
     * @param String $body a Valid XML string
     * @param String $endPoint URL to post the XML to
     * @throws exception
     * @return String the raw XML returned by Intacct
     */
    public static function execute($body, $endPoint) {

        self::$lastRequest = $body;

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $endPoint );
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 3000 ); //Seconds until timeout
        curl_setopt( $ch, CURLOPT_POST, 1 );
        // TODO: Research and correct the problem with CURLOPT_SSL_VERIFYPEER
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false); // yahoo doesn't like the api.intacct.com CA

        $body = "xmlrequest=" . urlencode( $body );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );

        $response = curl_exec( $ch );
        $error = curl_error($ch);
        if ($error != "") {
            throw new exception($error);
        }
        curl_close( $ch );

        self::$lastResponse = $response;
        return $response;

    }

    /**
     * Validate the response from Intacct and look for request level errors
     * @param String $response The XML response document
     * @throws Exception
     */
    private static function validateResponse($response) {

        // don't send errors to the log
        libxml_use_internal_errors(TRUE);
        // the client asked for a non-xml response (csv or json)
        $simpleXml = @simplexml_load_string($response);
        if ($simpleXml === false) {
            return;
        }
        libxml_use_internal_errors(FALSE);

        // look for a failure in the operation, but not the result
        if (isset($simpleXml->operation->errormessage)) {
            $error = $simpleXml->operation->errormessage->error[0];
            throw new Exception("[ERROR: " . $error->errorno . "] " . $error->description2);
        }
        // if we didn't get an operation, the request failed and we should raise an exception                                         
        // with the error details                                                                                                    
        // did the method invocation fail?
        if (!isset($simpleXml->operation)) {

            if (isset($simpleXml->errormessage)) {
                throw new Exception("[Error] " . api_util::xmlErrorToString($simpleXml->errormessage));
            }
        }
        else if ($simpleXml->operation->result->status == "failure") {
            throw new Exception("[Error] " . api_util::xmlErrorToString($simpleXml->operation->result->errormessage));
        }
        else {
            return;
        }
    }

    /**
     * Parses the response document from update requests and returns an array of ids affected
     * @param String $response
     * @param String $objectName
     * @return array of IDs
     * @throws Exception
     */
    private static function processUpdateResults($response, $objectName) {
        $simpleXml = simplexml_load_string($response);
        if ($simpleXml === false) {
            throw new Exception("Invalid XML response: \n " . var_export($response, true));
        }

        // check to see if there's an error in the response                                                                          
        $status = $simpleXml->operation->result->status;
        if ($status != "success") {
            //find the problem and raise an exception                                                                                
            $error = $simpleXml->operation->result->errormessage;
            throw new Exception("[Error] " . api_util::xmlErrorToString($error));
        }

        $updates = array();
        foreach($simpleXml->operation->result->data->{$objectName} as $record) {
            $updates[] = (string)$record[0]->id;
        }

        return $updates;
    }

    /**
     * Valid responses from read methods
     * @param $response
     * @throws Exception
     */
    private static function validateReadResults($response) {

        // don't send warnings to the error log
        libxml_use_internal_errors(TRUE);
        $simpleXml = simplexml_load_string($response);
        libxml_use_internal_errors(FALSE);

        if ($simpleXml === false) {
            return; // the result is csv or json, so there's no error
        }

        // Is there a problem with the XML request?
        if ((string)$simpleXml->operation->result->status == 'false') {
            $error = $simpleXml->operation->errormessage[0];
            throw new Exception("[Error] " . api_util::xmlErrorToString($error));
        }

        // is there a problem with the method invocation?
        $status = $simpleXml->operation->result->status;
        if ((string)$status != 'success') {
            $error = $simpleXml->operation->result->errormessage;
            throw new Exception("[Error] " . api_util::xmlErrorToString($error));
        }
        else {
            return; // no error found.
        }

    }

    /**
     * Process results from any of the read methods and convert into the appropriate structure
     * @param String $response result from post to Intacct Web Services
     * @param string $returnFormat valid returnFormat value
     * @param Integer $count by reference count of records returned
     * @throws Exception
     * @return Mixed string or object depending on return format
     */
    private static function processReadResults($response, $returnFormat = api_returnFormat::PHPOBJ, &$count) {
        $objAry = array(); $csv = ''; $json = ''; $xml = '';
        if ($returnFormat == api_returnFormat::PHPOBJ) {
            $objAry = api_util::csvToPhp($response);
            $count = count($objAry);
            return $objAry;
        }
        elseif ($returnFormat == api_returnFormat::JSON) {
            // this seems really expensive
            $objAry = json_decode($response);
            // todo: JSON doesn't work because we don't know what object to refer to
            $json = $response;
            $count = eval("foobar");
            return $json;
        }
        elseif ($returnFormat == api_returnFormat::XML) {
            $xmlObj = simplexml_load_string($response);
            foreach($xmlObj->operation->result->data->attributes() as $attribute => $value) {
                if ($attribute == 'count') {
                    $count = $value;
                    break;
                }
            }
            $xml = $xmlObj->operation->result->data->view->asXml();
            return $xml;
        }
        elseif ($returnFormat == api_returnFormat::CSV) {
            $objAry = api_util::csvToPhp($response);
            $count = count($objAry);
            $csv = $response;
            return $csv;
        }
        else {
            throw new Exception('bad code.  you suck.');
        }

    }

    /**
     * Helpful for debugging purposes.  Get the last full XML document passed to Intacct
     * @return String XML request
     */
    public static function getLastRequest() {
      return self::$lastRequest;
    }

    /**
     * Helpful for debugging purposes.  Get the last response from Intacct
     * @return String the raw respons from Intacct
     */
    public static function getLastResponse() {
      return self::$lastResponse;
    }
}
