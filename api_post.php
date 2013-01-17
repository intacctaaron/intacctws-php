<?
include_once('api_util.php');

class api_post {

    private static $lastRequest;
    private static $lastResponse;

    const DEFAULT_PAGESIZE = 1000;
    const DEFAULT_MAXRETURN = 100000;

    public static function read($object, $id, $fields, $session="") {

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

    public static function createEx($objects, $session="") {

      if (count($objects) > 100) throw new Exception("Attempting to create more than 100 objects. (" . count($objects) . ") ");

        // Convert the $object into an xml structure                                                                                 
        $createXml = "<create>";
        $node = "";
        foreach($objects as $object) {
            $nodeAry = array_keys($object);
            $node = $nodeAry[0];
            $objXml = api_util::phpToXml($node, $object[$node]);
            $createXml = $createXml . $objXml;
        }
        $createXml = $createXml . "</create>";
        $res = api_post::post($createXml, $session);
        $objects = api_post::processUpdateResults($res, $node);

        return $objects;
    }

    public static function create($xml, $session="") {

        $createXml = "<create>" . $xml . "</create>";
        return api_post::post($createXml, $session);

    }

    public static function update($xml, $session="") {
        $updateXml = "<update>" . $xml . "</update>";
        return api_post::post($updateXml, $session);
    }

    public static function updateEx($objects, $session="") {
        if (count($objects) > 100) throw new Exception("Attempting to update more than 100 objects.");

        // conver the $objectgs array into an xml structure                                                                          
        $updateXml = "<update>";
        $node = '';
        foreach($objects as $object) {
            $nodeAry = array_keys($object);
            $node = $nodeAry[0];
            $objXml = api_util::phpToXml($node, $object[$node]);
            $updateXml = $updateXml . $objXml;
        }
        $updateXml = $updateXml . "</update>";

        $res = api_post::post($updateXml, $session);
        return api_post::processUpdateResults($res, $node);

    }

    public static function deleteEx($object, $ids, $session) {
        $deleteXml = "<delete><object>$object</object><keys>$ids</keys></delete>";
        api_post::post($deleteXml, $session);
    }

    public static function xml2_1_method($xml, $session="") {
        return api_post::post($xml, $session);
    }


    /**
     * Return the records defined in a platform view.  Views define an object, a collection of field, sorting, and filtering.  You may pass additional filters 
     * via the api_viewFilters object
     * @arg viewName String either the textual name of the view or the original id of the view (object#originalid).  Note view names are not guaranteed to be 
     * unique, so you are always safer referencing the original id
     * @arg session Object instance of the api session object
     * @arg optional filterObj Object instance of the api_viewFilters object
     * @arg optional maxRecords Integer defaults to 100000
     * @arg optional returnFormat String defaults to phpobj.  Use one of the constants defined in api_returnFormat class
     * @return Mixed Depends on the return format argument.  Returns a string unless phpobj is the return format in which case returns an array
     */
    public static function readView($viewName, $session, api_viewFilters $filterObj=NULL, $maxRecords = self::DEFAULT_MAXRETURN, $returnFormat = api_returnFormat::PHPOBJ) {
     
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
	$condition = $filterObJ->condition;
	             
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
	    elseif ($returnFormat = api_returnFormat::CSV) {
	      // append all but the first row to the CSV file
	      $page = explode("\n", $page); 
	      array_shift($page); 
	      $csv .= implode($page, "\n");
	    }
	    elseif ($returnFormat = api_returnFormat::XML) {
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
     * @arg string object the object upon which to run the query
     * @arg string query the query string to execute.  Use SQL operators 
     * @arg string fields A comma separated list of fields to return
     * @arg integer optional maxRecords number of records to return.  Defaults to 100000
     * @arg string optional returnFormat defaults to php object.  Pass one of the valid constants from api_returnFormat class
     * @return mixed either string or array of objects depending on returnFormat argument
     */
    public static function readByQuery($object, $query, $fields, $session, $maxRecords=self::DEFAULT_MAXRETURN, $returnFormat=api_returnFormat::PHPOBJ) {

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

    public static function inspect($object, $detail, $session) {
      $inspectXML = "<inspect detail='$detail'><object>$object</object></inspect>";
      $objAry = api_post::post($inspectXML, $session);
      return $objAry;
    }

    /**                                                                                                                              
     * Read an object by its name field (vid for standard objects)                                                                   
     * @arg object String object type                                                                                                
     * @arg name String comma separated list of names.                                                                               
     * @arg fields String comma separated list of fields.                            
     * @arg session Optional Object api_session object. If not passed, the post method will look for session information in the REQUEST object
     * @return Array of objects.  If only one name is passed, the fields will be directly accessible.                                
     */
    public static function readByName($object, $name, $fields, $session="") {
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

    public static function readRelated($object, $keys, $relation, $fields,$session="") {
        $readXml = "<readRelated><object>$object</object><keys>$keys</keys><relation>$relation</relation><fields>$fields</fields><returnFormat>csv</returnFormat></readRelated>";
        $objCsv = api_post::post($readXml, $session);
        api_post::validateReadResults($objCsv);
        $objAry = api_util::csvToPhp($objCsv);
        return $objAry;
    }

    /**                                                                                                                              
     * WARNING: This method will attempt to delete all records of a given object type                                                
     * Deletes first 10000 by default                                                                                                
     * @arg object String object type                                                                                                
     * @arg session Object api_session object.                                                                                       
     * @arg max Optional Integer Maximum number of records to delete.  Default is 10000                                              
     * @return Integer count of records deleted                                                                                      
     */
    public static function deleteAll($object, $session, $max=10000) {

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
                api_post::deleteEx($object, implode(",", $delIds));
                $count += 100;
                $delIds = array();
            }
        }

        if (count($delIds) > 0) {
            api_post::deleteEx($object, implode(",", $delIds));
            $count += count($delIds);
        }

        return $count;
    }

    private static function post($xml, $session="") {

        if ($session == "") {
            $sessionId = $_GET['sessionId'];
            $endPoint = $_GET['endPoint'];
            $senderId = $_GET['senderId'];
            $senderPassword = $_GET['senderPassword'];
        }
        else {
            $sessionId = $session->sessionId;
            $endPoint = $session->endPoint;
            $senderId = $session->senderId;
            $senderPassword = $session->senderPassword;
        }

        $templateHead =
"<?xml version=\"1.0\" encoding=\"UTF-8\"?>                                                                                          
<request>                                                                                                                            
    <control>                                                                                                                        
        <senderid>{2%}</senderid>                                                                                                    
        <password>{3%}</password>                                        
	<controlid>foobar</controlid>                                                                                                
        <uniqueid>false</uniqueid>                                                                                                   
        <dtdversion>3.0</dtdversion>                                                                                                 
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

	$count = 0; // retry five times on too many operations
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

    public static function execute($body, $endPoint) {

        self::$lastRequest = $body;

        $ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $endPoint );
        curl_setopt( $ch, CURLOPT_HEADER, 0 );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	//        curl_setopt( $ch, CURLOPT_MUTE, 1 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 3000 ); //Seconds until timeout
        curl_setopt( $ch, CURLOPT_POST, 1 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false); // yahoo doesn't like the api.intacct.com CA

        $body = "xmlrequest=" . urlencode( $body );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );

        $response = curl_exec( $ch );
        $error = curl_error($ch);
        if ($error != "") {
	  //            throw new exception("[" . $endPoint . "] " . $error);
            throw new exception($error);
        }
        curl_close( $ch );

        self::$lastResponse = $response;
        return $response;

    }

    private static function validateResponse($response, $xml="") {

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
        else {
            return;
        }

    }

    private static function processUpdateResults($response, $objectName) {
        $simpleXml = simplexml_load_string($response);
        if ($simpleXml === false) {
            throw new Exception("Invalid XML response: \n " . var_export($response, true));
        }

        $objects = array();
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
     * @var response String result from post to Intacct Web Services
     * @var returnFormat String valid returnFormat value
     * @var count Integer by reference count of records returned
     * @response Mixed string or object depending on return format
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
    
    public static function getLastRequest() {
      return self::$lastRequest;
    }
    
    public static function getLastResponse() {
      return self::$lastResponse;
    }
}
?>
