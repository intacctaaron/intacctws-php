<?php
include_once('api_util.php');
include_once('api_viewFilter.php');
include_once('api_viewFilters.php');
include_once('api_returnFormat.php');
include_once('api_objDef.php');

class api_post {

    private static $lastRequest;
    private static $lastResponse;
    private static $lastResponseHeader;
    private static $dryRun;

    const DEFAULT_PAGESIZE = 1000;
    const DEFAULT_MAXRETURN = 200000;

    /**
     * Read one or more records by their key.  For platform objects, the key is the 'id' field.
     * For standard objects, the key is the 'recordno' field.  Results are returned as a php structured array
     * @param  String $object the integration name for the object
     * @param  String $id a comma separated list of keys for each record you wish to read
     * @param  String $fields a comma separated list of fields to return
     * @param  \api_session|Object $session an instance of the php_session object
     * @param  String $response_type csv (default), xml
     * @return Array of records
     */
    public static function read($object, $id, $fields, api_session $session, $response_type = 'csv') {

        $readXml = "<read><object>$object</object><keys>$id</keys><fields>$fields</fields><returnFormat>$response_type</returnFormat></read>";
        $response = api_post::post($readXml, $session);
        api_post::validateReadResults($response);
        switch ($response_type) {
            case 'xml':
                $resultRecallsArr = new SimpleXMLElement($response);
                $result = $resultRecallsArr->operation->result->data;
                break;
            case 'csv':
                $objAry = api_util::csvToPhp($response);
                if (count(explode(",",$id)) > 1) {
                    $result = $objAry;
                }
                else {
                    $result = $objAry[0];
                }
                break;
            default:
                $result = false;
                break;
        }
        return $result;
    }

    /**
     * ReadDocument one or more records by their key.  For platform objects, the key is the 'id' field.
     * For standard objects, the key is the 'recordno' field.  Results are returned as a php structured array
     * @param String $object the integration name for the object
     * @param String $docparid the transaction type
     * @param String $id a comma separated list of keys for each record you wish to read
     * @param String $fields a comma separated list of fields to return
     * @param \api_session|Object $session an instance of the php_session object
     * @return Array of records
     */
    public static function readDocument($object, $docparid, $id, $fields, api_session $session) {

        $readXml = "<read><object>$object</object><keys>$id</keys><fields>$fields</fields><returnFormat>csv</returnFormat><docparid>$docparid</docparid></read>";
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
    public static function create($records, api_session $session,$policy=null) {

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
        $res = api_post::post($createXml, $session, "3.0", false, $policy);
        if ($policy !== null) {
            return $res;
        }
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
        if (count($records) > 10000) throw new Exception("Attempting to update more than 10000 records.");

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
     * Checks to see if a record exists.  If so, it updates, else it creates
     *
     * @param String      $object       The type of object to perform upsert on
     * @param Array       $records      Array of records to upsert.  Should be passed in the same format as used with
     *                                  create and update
     * @param Mixed       $nameField    the field name used for lookup of existence
     * @param Mixed       $keyField     the field name used for the internal key (needed for update)
     * @param api_session $session      An active api_session object
     * @param bool        $readOnlyName Optional.  You shouldn't normally set this to true unless the value in the
     *                                  name field is actually set by the platform and you're passing a formulated value
     *                                  that should not be passed in the create or update
     *
     * @throws Exception
     * @return null
     */
    public static function upsert($object, $records, $nameField, $keyField, api_session $session, $readOnlyName = false)
    {
        if (count($records) > 100) {
            throw new Exception("You can only upsert up to 100 records at a time.  You passed " . count($records) . " $object records.");
        }
        $keys = array();
        foreach ($records as $record) {
            $keys[] = htmlspecialchars(str_replace('\'', '\\', $record[$object][$nameField]));
        }

        $where = "$nameField in ('" . join("','", $keys) . "')";
        $existingRecords = api_post::readByQuery($object, $where, "$nameField,$keyField", $session);
        if (!is_array($existingRecords)) {
            $existingRecords = array();
        }
        $toUpdate = array(); $toCreate = array();
        if (count($existingRecords) == 0) {
            if ($readOnlyName == true) {
                foreach ($records as $key => $rec) {
                    unset($records[$key][$object][$nameField]);
                }
            }
            api_post::create($records, $session);
        } else {
            // convert the result into an array of keys
            $existingKeys = array();
            foreach ($existingRecords as $rec) {
                $existingKeys[] = $rec[$nameField];
            }

            // also create an index by name
            $existingByName = array();
            foreach ($existingRecords as $rec) {
                $existingByName[$rec[$nameField]] = $rec[$keyField];
            }

            foreach ($records as $rec) {
                if (in_array($rec[$object][$nameField], $existingKeys)) {
                    $toUpdate[] = $rec;
                } else {
                    $toCreate[] = $rec;
                }
            }

            // convert the create and update arrays into operable structures
            if (count($toCreate) > 0) {
                if ($readOnlyName === true) {
                    foreach ($toCreate as $key => $rec) {
                        unset($toCreate[$key][$object][$nameField]);
                    }
                }
                return api_post::create($toCreate, $session);
            }
            if (count($toUpdate) > 0) {
                foreach ($toUpdate as $updateKey => $updateRec) {
                    $toUpdate[$updateKey][$object][$keyField] = $existingByName[$updateRec[$object][$nameField]];
                    if ($readOnlyName === true) {
                        unset($toUpdate[$updateKey][$object][$nameField]);
                    }
                }
                return api_post::update($toUpdate, $session);
            }
        }
    }

    /**
     * Delete one or more records
     * @param String $object integration code of object type to delete
     * @param String $ids String a comma separated list of keys.  use 'id' values for custom
     * objects and 'recordno' values for standard objects
     * @param api_session $session instance of api_session object
     */
    public static function delete($object, $ids, api_session $session,$policy=null) {
        $deleteXml = "<delete><object>$object</object><keys>$ids</keys></delete>";
        api_post::post($deleteXml, $session,"3.0",false,$policy);
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

    public static function send_xml($xml, api_session $session) {
        return api_post::post("<content>$xml</content>", $session,"3.0",true);
    }

    public static function get_xml($obj) {
        $xml = api_util::phpToXml('content',array($obj));
        return $xml;
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
     * Run any Intacct API method not directly implemented in this class.  You must pass
     * valid XML for the method you wish to invoke.
     * @param Array $phpObj an array for all the functions .
     * @param api_session $session  an api_session instance with a valid connection
     * @param string $dtdVersion DTD Version.  Either "2.1" or "3.0".  Defaults to "2.1"
     * @return String the XML response from Intacct
     */
    public static function sendFunctions($phpObj, api_session $session, $dtdVersion="3.0", $returnFormat = api_returnFormat::XML,$policy = null) {
        $xml = api_util::phpToXml('content',array($phpObj));
        $res = api_post::post($xml, $session,$dtdVersion, true, $policy);
        if ($returnFormat == api_returnFormat::PHPOBJ) {
            $res_xml = simplexml_load_string($res);
            $json = json_encode($res_xml->operation->result->data,JSON_FORCE_OBJECT);
            $array = json_decode($json,TRUE);
            return $array;
        } else {
            return $res;
        }
    }

    public static function prune_empty_element($a) {
        foreach ($a as $k => $v) {
            if (is_array($v) && empty($v)) {
                $a[$k] = "";
            }
        }
        return $a;
    }

    /**
     * Run any Intacct API method not directly implemented in this class.  You must pass
     * valid XML for the method you wish to invoke.
     * @param Array $phpObj an array for all the functions .
     * @param api_session $session  an api_session instance with a valid connection
     * @param string $dtdVersion DTD Version.  Either "2.1" or "3.0".  Defaults to "2.1"
     * @return String the XML response from Intacct
     */
    public static function query(api_session $session, $call, int $limit = null, $returnFormat = api_returnFormat::PHPOBJ) {
        $obj = $call['object'];
        $call['offset'] = $call['offset'] ?? '0';
        $call['pagesize'] = $call['pagesize'] ?? '100';
        if ($limit !== null) {
            $call['pagesize'] = min($limit,$call['pagesize']);
        }
        
        $phpObj = array (
            'function' => array (
                '@controlid' => uniqid(),
                'query' => $call,
            )
        );
        $rows = array();
        dbg("**RBQ**: (QUERY) " . $obj );

        do {
            $xml = api_util::phpToXml('content',array($phpObj));
            $res = api_post::post($xml, $session,'3.0', true);
            //dbg($res);
            $res_xml = simplexml_load_string($res);
            $json = json_encode($res_xml->operation->result->data,JSON_FORCE_OBJECT);
            $array = json_decode($json,TRUE);
            if (!isset($array[$obj])) {
                $array[$obj] = array();
            }  else if (!is_numeric(key($array[$obj]))) {
                $array[$obj] = array(
                    $array[$obj] 
                );
            }

            $row = array_map(array('api_post','prune_empty_element'),$array[$obj]);
            //dbg("READ this many rows" . count($row));
            $rows = array_merge($rows,$row);
            $num_remaining = $array['@attributes']['numremaining']; 
            $total = count($rows);
            if ($num_remaining > 0 && $limit !== null) { 
                $num_remaining = min($limit - $total,$num_remaining);
            }

            $phpObj['function']['query']['offset'] += $phpObj['function']['query']['pagesize'] ; 
            dbg("REMAINING: $num_remaining.  OFFSET is now : " . $phpObj['function']['query']['offset']);

            if ($limit !== null && $phpObj['function']['query']['offset'] >= $limit) {
                $num_remaining = 0;
            }
            dbg("   **NUM REMAINING**:  " . $num_remaining);

        } while ($num_remaining > 0);

        if ($returnFormat == api_returnFormat::PHPOBJ) {
            return $rows;
        } else {
            die("only php return format supported");
        }
    }
    /**
     * Run any Intacct API method not directly implemented in this class.  You must pass
     * valid XML for the method you wish to invoke.
     * @param string $object the object to list
     * @param Array $filters filters in a phpObj that will convert to get_list filters in phpToXml
     * @param api_session $session  an api_session instance with a valid connection
     * @param string $dtdVersion DTD Version.  Either "2.1" or "3.0".  Defaults to "2.1"
     * @return String the XML response from Intacct
     */
    public static function get_list($object, $filter, $sorts, $fields, api_session $session, $dtdVersion="2.1", $max_desired = null) {
        $get_list = array();
        $get_list['@object'] = $object;
        $get_list['@start'] = 0;
        $get_list['@maxitems'] = min(1000,$max_desired);

        if ($filter != null) {
            $get_list['filter'] = $filter;
        }
        if ($sorts != null) {
            $get_list['sorts'] = $sorts;
        }
        if ($fields != null) {
            $get_list['fields'] = $fields;
        }

        $func['function'][] = array (
            '@controlid' => 'control1',
            'get_list' => $get_list
        );

        $xml = api_util::phpToXml('content',array($func));
        $res = api_post::post($xml, $session,$dtdVersion, true);
        if (self::$dryRun == true) {
            return;
        }
        $ret = api_post::processListResults($res, api_returnFormat::PHPOBJ, $count);
        $toReturn = null;
        if (array_key_exists($object,$ret)) {
            $toReturn = $ret[$object];
        } else {
            return array();
        }
        if (is_array($toReturn)) {
            $keys = array_keys($toReturn);
            if (!is_numeric($keys[0])) {
                $toReturn = array ($toReturn);
            }
        }

        // now get more if there are any
        $xml = simplexml_load_string($res);
        $total = $xml->operation->result->listtype;
        $attrs = $total->attributes();
        $total = $attrs['total'];
        $c = count($toReturn);

        if ($c < $total && ($max_desired == null || $c < $max_desired )) {

            do {
                dbg("FETCH MORE " . count($toReturn) . " of $total ($max_desired)");
                // we need to fetch more
                $get_list['@start'] = count($toReturn);
                $func['function'] = array();

                $func['function'][] = array (
                    '@controlid' => 'control1',
                    'get_list' => $get_list
                );

                $xml = api_util::phpToXml('content',array($func));
                $res = api_post::post($xml, $session,$dtdVersion, true);
                $ret = api_post::processListResults($res, api_returnFormat::PHPOBJ, $count);

                if (!is_array($ret) || empty($ret)) {
                    break;
                }

                $nextBatch = null;
                if (array_key_exists($object,$ret)) {
                    $nextBatch = $ret[$object];
                }
                if (is_array($nextBatch)) {
                    $keys = array_keys($nextBatch);
                    if (!is_numeric($keys[0])) {
                        $nextBatch = array ($nextBatch);
                    }
                }
                $toReturn = array_merge($toReturn,$nextBatch);

            } while ( count($toReturn) < $total) ;
        }

        return $toReturn;
    }

    /**
     * Run any Intacct API method not directly implemented in this class.  You must pass
     * valid XML for the method you wish to invoke.
     * @param String $function for 2.1 function (create_sotransaction, etc)
     * @param String $key The attribute key
     * @param Array $phpObj an array for the object.  Do not nest in another array() wrapper
     * @param api_session $session  an api_session instance with a valid connection
     * @param string $dtdVersion DTD Version.  Either "2.1" or "3.0".  Defaults to "3.0"
     * @return String the XML response from Intacct
     */
    public static function call21UpdateMethod($function, $key, $phpObj, api_session $session) {
        $xml = api_util::phpToXml($function,array($phpObj));
        $xml = str_replace("<$function", "<$function key=\"$key\"", $xml);
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
    public static function readView($viewName, api_session $session, api_viewFilters $filterObj=null, $maxRecords = self::DEFAULT_MAXRETURN, $returnFormat = api_returnFormat::PHPOBJ) {

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
        if ($filterObj !== null) {
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
            while (true) {
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
                        $csv .= implode("\n",$page);
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
        dbg("RBQ: $object -> $query with $fields");

        $pageSize = ($maxRecords <= self::DEFAULT_PAGESIZE) ? $maxRecords : self::DEFAULT_PAGESIZE;

        if ($returnFormat == api_returnFormat::PHPOBJ) {
            $returnFormatArg = api_returnFormat::CSV;
        }
        else {
            $returnFormatArg = $returnFormat;
        }

        // TODO: Implement returnFormat.  Today we only support PHPOBJ
        $query = HTMLSpecialChars($query);

        $readXml = "<readByQuery><object>$object</object><query>$query</query><fields>$fields</fields><returnFormat>$returnFormatArg</returnFormat>";
        $readXml .= "<pagesize>$pageSize</pagesize>";
        $readXml .= "</readByQuery>";
        //dbg($readXml);

        $response = api_post::post($readXml,$session);
        if ($returnFormatArg == api_returnFormat::CSV && trim($response) == "") {
            // csv with no records will have no response, so avoid the error from validate and just return
            return '';
        }
        if ($object == 'PROJECT') {
            dbg(api_post::getLastRequest());
            dbg($response);
        }
        api_post::validateReadResults($response);


        $phpobj = array(); $csv = ''; $json = ''; $xml = ''; $count = 0; $thiscount = 0;
        $$returnFormat = self::processReadResults($response, $returnFormat, $thiscount);

        $totalcount = $thiscount;
        //dbg("$thiscount == $pageSize && $totalcount <= $maxRecords");

        // we have no idea if there are more if CSV is returned, so just check
        // if the last count returned was  $pageSize
        while($thiscount == $pageSize && $totalcount < $maxRecords) {
            $readXml = "<readMore><object>$object</object></readMore>";
            try {
                $response = api_post::post($readXml, $session);
                if ($object == 'PROJECT') {
                    dbg("READMORE PROJECT");
                    dbg(api_post::getLastRequest());
                    dbg($response);
                }
                api_post::validateReadResults($response);
                $page = self::processReadResults($response, $returnFormat, $pageCount);
                $totalcount += $pageCount;
                $thiscount = $pageCount;

                switch($returnFormat) {
                    case api_returnFormat::PHPOBJ:
                        foreach($page as $objRec) {
                            $phpobj[] = $objRec;
                        }
                    break;
                    case api_returnFormat::CSV:
                        $page = explode("\n", $page);
                        array_shift($page);
                        $csv .= implode("\n",$page);
                    break;
                    case api_returnFormat::XML:
                        $xml .= $page;
                    break;
                    default:
                        throw new Exception("Invalid return format: " . $returnFormat);
                    break;
                }
                dbg("READMORE GOT: $thiscount, Total now: $totalcount");

            }
            catch (Exception $ex) {
                // we've probably exceeded the limit
                break;
            }
        }
        return $$returnFormat;
    }
    /**
     * Read records using a query.  Specify the object you want to query and something like a "where" clause"
     * @param api_session $session An instance of the api_session object with a valid connection
     * @param String $object the object upon which to run the query
     * @param String $fields A comma separated list of fields to return
     * @param String $query the query string to execute.  Use SQL operators
     * @param int $maxRecords number of records to return.  Defaults to 100000
     * @param string $returnFormat defaults to php object.  Pass one of the valid constants from api_returnFormat class
     * @return mixed either string or array of objects depending on returnFormat argument
     */
    public static function query_bad(api_session $session, $object, $fields=null, $query=null, $maxRecords=self::DEFAULT_MAXRETURN, $returnFormat=api_returnFormat::PHPOBJ) {

        $pageSize = ($maxRecords <= self::DEFAULT_PAGESIZE) ? $maxRecords : self::DEFAULT_PAGESIZE;

        if ($returnFormat == api_returnFormat::PHPOBJ) {
            $returnFormatArg = api_returnFormat::CSV;
        }
        else {
            $returnFormatArg = $returnFormat;
        }

        $field_xml = "";
        if ($fields !== null) {
            $field_xml = "<field>" . str_replace(",","</field><field>",$fields) . "</field>";
        }
        if ($query !== NULL) {
            $query_xml = api_util::phpToXml($query);

        }

        // TODO: Implement returnFormat.  Today we only support PHPOBJ
//        $query = HTMLSpecialChars($query);

        $readXml = "<query><object>$object</object><filter>$query_xml</filter><select>$field_xml</select>";
        $readXml .= "<pagesize>$pageSize</pagesize>";
        $readXml .= "</query>";
        //dbg($readXml);

        $response = api_post::post($readXml,$session);
        //dbg($response);
        die();
        if ($returnFormatArg == api_returnFormat::CSV && trim($response) == "") {
            // csv with no records will have no response, so avoid the error from validate and just return
            return '';
        }
        api_post::validateReadResults($response);


        $phpobj = array(); $xml = ''; $count = 0; $thiscount = 0;
 
        $simpleXml = simplexml_load_string($response);
        $data = $simpleXml->xpath("/response/operation/result/data");
        $thiscount = $data[0]['count'];
        $rows = $simpleXml->xpath("/response/operation/result/data/$object");
        foreach ($rows as $row) {
            $phpobj[] = (array)$row;
        }

        if ($data[0]['numremaining'])

//        $thiscount = $data->
        
        //$$returnFormat = self::processReadResults($response, $returnFormat, $thiscount);

        $totalcount = $thiscount;
        //dbg("$thiscount == $pageSize && $totalcount <= $maxRecords");

        // we have no idea if there are more if CSV is returned, so just check
        // if the last count returned was  $pageSize
        while($thiscount == $pageSize && $totalcount < $maxRecords) {
            $readXml = "<readMore><object>$object</object></readMore>";
            try {
                $response = api_post::post($readXml, $session);
                //dbg($response);
                //dbg(api_post::getLastRequest());
                api_post::validateReadResults($response);
                $page = self::processReadResults($response, $returnFormat, $pageCount);
                $totalcount += $pageCount;
                $thiscount = $pageCount;

                switch($returnFormat) {
                    case api_returnFormat::PHPOBJ:
                        foreach($page as $objRec) {
                            $phpobj[] = $objRec;
                        }
                    break;
                    case api_returnFormat::CSV:
                        $page = explode("\n", $page);
                        array_shift($page);
                        $csv .= implode($page, "\n");
                    break;
                    case api_returnFormat::XML:
                        $xml .= $page;
                    break;
                    default:
                        throw new Exception("Invalid return format: " . $returnFormat);
                    break;
                }

            }
            catch (Exception $ex) {
                // we've probably exceeded the limit
                break;
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
    public static function readDocumentByQuery($object, $docparid, $query, $fields, api_session $session, $maxRecords=self::DEFAULT_MAXRETURN, $returnFormat=api_returnFormat::PHPOBJ) {

        $pageSize = ($maxRecords <= self::DEFAULT_PAGESIZE) ? $maxRecords : self::DEFAULT_PAGESIZE;

        if ($returnFormat == api_returnFormat::PHPOBJ) {
            $returnFormatArg = api_returnFormat::CSV;
        }
        else {
            $returnFormatArg = $returnFormat;
        }

        // TODO: Implement returnFormat.  Today we only support PHPOBJ
        $query = HTMLSpecialChars($query);

        $readXml = "<readByQuery><object>$object</object><query>$query</query><fields>$fields</fields><returnFormat>$returnFormatArg</returnFormat>";
        $readXml .= "<docparid>$docparid</docparid>";
        $readXml .= "<pagesize>$pageSize</pagesize>";
        $readXml .= "</readByQuery>";

        $response = api_post::post($readXml,$session);
        if ($returnFormatArg == api_returnFormat::CSV && trim($response) == "") {
            // csv with no records will have no response, so avoid the error from validate and just return
            return '';
        }
        api_post::validateReadResults($response);


        $phpobj = array(); $csv = ''; $json = ''; $xml = ''; $count = 0;
        $$returnFormat = self::processReadResults($response, $returnFormat, $thiscount);

        $totalcount = $thiscount;

        // we have no idea if there are more if CSV is returned, so just check
        // if the last count returned was  $pageSize
        while($thiscount == $pageSize && $totalcount < $maxRecords) {
            $readXml = "<readMore><object>$object</object></readMore>";
            try {
                $response = api_post::post($readXml, $session);
                api_post::validateReadResults($response);
                $page = self::processReadResults($response, $returnFormat, $pageCount);
                $totalcount += $pageCount;
                $thiscount = $pageCount;

                switch($returnFormat) {
                    case api_returnFormat::PHPOBJ:
                        foreach($page as $objRec) {
                            $phpobj[] = $objRec;
                        }
                    break;
                    case api_returnFormat::CSV:
                        $page = explode("\n", $page);
                        array_shift($page);
                        $csv .= implode($page, "\n");
                    break;
                    case api_returnFormat::XML:
                        $xml .= $page;
                    break;
                    default:
                        throw new Exception("Invalid return format: " . $returnFormat);
                    break;
                }

            }
            catch (Exception $ex) {
                // we've probably exceeded the limit
                break;
            }
        }
        return $$returnFormat;
    }


    /**
     * Inspect an object to get a list of its fields
     *
     * @param String      $object  The integration name of the object.  Pass '*' to get a complete list of objects
     * @param bool|String $detail  Whether or not to return data type information for the fields.
     * @param api_session $session Instance of an api_session object with a valid connection
     *
     * @return String the raw xml returned by Intacct
     */
    public static function inspect($object, $detail, api_session $session) {
        $inspectXML = "<inspect detail='$detail'><object>$object</object></inspect>";
        $objXml = api_post::post($inspectXML, $session);
        $simpleXml = simplexml_load_string($objXml);
        $objDefXml = $simpleXml->operation->result->data->Type;
        $objDef = new api_objDef($objDefXml);
        return $objDef;
        //return $objAry;
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

        if (trim($objCsv) == "") {
            // csv with no records will have no response, so avoid the error from validate and just return
            return '';
        }
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
        //if we receive an empty response we return it
        if (trim($objCsv) == "") {
           return '';
        }
        api_post::validateReadResults($objCsv);
        $objAry = api_util::csvToPhp($objCsv);
        return $objAry;
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
    public static function readReport($report, api_session $session, $arguments=null, $waitTime=0, $pageSize=100) {
        $maxRecords = self::DEFAULT_MAXRETURN;
        $max_try = 1000;
        $try = 0;

        if (is_array($arguments) ) {
            $argxml= "<arguments>";
            foreach ($arguments as $key => $arg) {
                $argxml.= "<$key>$arg</$key>";
            }
            $argxml .= "</arguments>";
        }
        $readXml = "<readReport><report>$report</report><returnFormat>csv</returnFormat><waitTime>$waitTime</waitTime>$argxml<pagesize>$pagesize</pagesize></readReport>";
        $objCsv = api_post::post($readXml, $session);
        api_post::validateReadResults($objCsv);
        $objAry = api_util::csvToPhp($objCsv);

        if (is_array($objAry) && count($objAry) == 1) {
            $id = $objAry[0]['REPORTID'];
            do {
                $readXml = "<readMore><reportId>$id</reportId></readMore>";
                try {
                    $response = api_post::post($readXml, $session);
                    //dbg("READMORE:");
                    //dbg($response);
                    //dbg("TRIMMED:");
                    //dbg(trim($response));
                    if (trim($response) == "") {
                        return array();
                    }
                    api_post::validateReadResults($response);
                    $_obj = api_util::csvToPhp($response);
                    if (isset($_obj[0]['STATUS']) && $_obj[0]['STATUS'] == 'PENDING') {
                        //dbg("Sleeping 10, try = $try");
                        sleep("10");
                        $try++;
                        continue;
                    }

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
            } while ($try < $max_try);

            //dbg("FINISHED LOOP");
        }
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
     * WARNING: This method will attempt to delete all records of a given object type given a query
     * Deletes first 10000 by default
     * @param String $object object type
     * @param String $query the query string to execute.  Use SQL operators
     * @param api_session $session instance of api_session object.
     * @param Integer $max [optional] Maximum number of records to delete.  Default is 10000
     * @return Integer count of records deleted
     */
    public static function deleteByQuery($object, $query, $key_field, api_session $session, $max=100000) {

        $num_per_func= 100;
        // read all the record ids for the given object
        $ids = api_post::readByQuery($object, "$key_field > 0 and $query", $key_field, $session, $max);
        if (!is_array($ids)) {
            $ids = array();
        }

        if ((!is_array($ids) && trim($ids) == '') || !count($ids) > 0) {
            return 0;
        }

        dbg("COUNT of things to delete: " . count($ids));

        $count = 0;
        $delIds = array();
        $_count = count($ids);

        foreach($ids as $rec) {
            $delIds[] = $rec[$key_field];
            if (count($delIds) == $num_per_func) {
                try {
                    $_count -= $num_per_func;
                    dbg($_count);
                    api_post::delete($object, implode(",", $delIds), $session);
                }
                catch (Exception $ex) {
                    $delIds = array();
                    print_r($ex);
                    continue;
                }
                $count += $num_per_func;
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
    private static function post($xml, api_session $session, $dtdVersion="3.0",$multiFunc=false, $policy=null) {

        $sessionId = $session->sessionId;
        $endPoint = $session->endPoint;
        $senderId = $session->senderId;
        $senderPassword = $session->senderPassword;

        $transaction = ( $session->transaction ) ? 'true' : 'false' ;

        /*
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
    <operation transaction='{5%}'>
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
        $xml = str_replace("{5%}", $transaction, $xml);

        */

        $templateHead =
"<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<request>
    <control>
        <senderid>{$senderId}</senderid>
        <password>{$senderPassword}</password>
        <controlid>".uniqid()."</controlid>
        <uniqueid>false</uniqueid>
        <dtdversion>{$dtdVersion}</dtdversion>";
        if ($policy !== null) {
            $templateHead .= "<policyid>$policy</policyid>";
        }
    $templateHead .= "</control>
    <operation transaction='{$transaction}'>
        <authentication>
            <sessionid>{$sessionId}</sessionid>
        </authentication>";

        $contentHead =
        "<content>
            <function controlid=\"foobar\">";

        $contentFoot =
            "</function>
        </content>";

        $templateFoot =
    "</operation>
</request>";


        if ($multiFunc) {
            $xml = $templateHead . $xml . $templateFoot;
        }
        else {
            $xml = $templateHead . $contentHead . $xml . $contentFoot . $templateFoot;
        }

        if (self::$dryRun == true) {
            self::$lastRequest = $xml;
            self::$lastResponse= null;
            return;
        }


        $count = 0; // retry five times on too many operations
        $res = "";
        while (true) {
            $res = api_post::execute($xml, $endPoint);

            // If we didn't get a response, we had a poorly constructed XML request.
            try {
                api_post::validateResponse($res, $xml);
                break;
            } catch (Exception $ex) {
                dbg("JPC EXCEPTION");
                if ($count >= 5) {
                    throw new Exception($ex->getMessage(),$ex->getCode(),$ex);
                }
                dbg("EX getMessage");
                dbg($ex->getMessage());
                if (strpos($ex->getMessage(), "too many operations") !== false) {
                    $count++;
                } else if (strpos($ex->getMessage(), "UJPP0007") !== false) {
                    $count++;
                    dbg("Got UJPP007. Sleeping one minute and trying again.");
                    dbg("RESPONSE HEADER:");
                    dbg(self::$lastResponseHeader);
                    dbg("END RESPONSE HEADER:");
                    sleep(60);
                } else {
                    throw new Exception($ex->getMessage(),$ex->getCode(),$ex);
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
        self::$lastResponse = null;

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $endPoint );
        curl_setopt( $ch, CURLOPT_HEADER, true );
        curl_setopt( $ch, CURLINFO_HEADER_OUT, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 6000 ); //Seconds until timeout
        curl_setopt( $ch, CURLOPT_POST, 1 );
        curl_setopt( $ch, CURLOPT_VERBOSE, 0);
        // TODO: Research and correct the problem with CURLOPT_SSL_VERIFYPEER
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false); // yahoo doesn't like the api.intacct.com CA

        $body = "xmlrequest=" . urlencode( $body );

        curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
        $response = curl_exec( $ch );
        $error = curl_error($ch);

        //$info = curl_getinfo( $ch, CURLINFO_HEADER_OUT );
        //$info = curl_getinfo( $ch );
        
        //dbg($response);
        //
        // Then, after your curl_exec call:
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $response_header = substr($response, 0, $header_size);
        $response_body = substr($response, $header_size);

        self::$lastResponse = $response_body; 
        self::$lastResponseHeader = $response_header; 
        if (strpos($response,"UJPP00") !== FALSE) {
            dbg("FULL RESPONSE with header");
            dbg($response);
        }

        if ($error != "") {
            throw new exception($error);
        }
        curl_close( $ch );

        return $response_body;

    }

    /**
     * Validate the response from Intacct and look for request level errors
     * @param String $response The XML response document
     * @throws Exception
     */
    public static function findResponseErrors($response,$multi=false) {

        $errorArray = array();

        // don't send errors to the log
        libxml_use_internal_errors(true);
        // the client asked for a non-xml response (csv or json)
        $simpleXml = @simplexml_load_string($response);

        if ($simpleXml === false) {
            return;
        }
        libxml_use_internal_errors(false);

        // look for a failure in the operation, but not the result
        if (isset($simpleXml->operation->errormessage)) {
            $error = $simpleXml->operation->errormessage->error[0];
            $errorArray[] = array ( 'desc' =>  api_util::xmlErrorToString($simpleXml->operation->errormessage,$multi));
        }

        // if we didn't get an operation, the request failed and we should raise an exception
        // with the error details
        // did the method invocation fail?
        if (!isset($simpleXml->operation)) {
            if (isset($simpleXml->errormessage)) {
                $errorArray[] = array ( 'desc' =>  api_util::xmlErrorToString($simpleXml->errormessage,$multi));
            }
        }
        else {
            $results = $simpleXml->xpath('/response/operation/result');
            foreach ($results as $result) {
                if ((string)$result->status == "failure" || (string)$result->status == "aborted") {
                    $errorArray[] = array ( 'controlid' => (string)$result->controlid, 'desc' =>  api_util::xmlErrorToString($result->errormessage,$multi));
                }
            }
        }
        return $errorArray;
    }

    /**
     * Validate the response from Intacct and look for request level errors
     * @param String $response The XML response document
     * @throws Exception
     */
    private static function validateResponse($response) {

        // don't send errors to the log
        libxml_use_internal_errors(true);
        // the client asked for a non-xml response (csv or json)
        $simpleXml = @simplexml_load_string($response);
        if ($simpleXml === false) {
            return;
        }
        libxml_use_internal_errors(false);

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
            $results = $simpleXml->operation->result;
            foreach ($results as $res) {
                if ($res->status == "failure" || $res->status == "aborted") {
                    $msg = api_util::xmlErrorToString($res->errormessage);
                    throw new Exception("[Error] " . $msg);
                }
            }
        }
        return;
    }

    /**
     * Parses the response document from update requests and returns an array of ids affected
     * @param String $response
     * @param String $objectName
     * @return array of IDs
     * @throws Exception
     */
    private static function processUpdateResults($response, $objectName) {
        //Fix Intacct bug, by trim spaces from the returned xml response string
        $response  = trim($response);
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
            if ($record->id) {
                $updates[] = (string)$record->id;
            }
            else if ($record->RECORDNO) {
                $updates[] = (string)$record->RECORDNO;
            }
            else {
                $updates[] = 'Record updated did not have id or RECORDNO';
            }
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
        libxml_use_internal_errors(true);
        $simpleXml = simplexml_load_string($response);
        libxml_use_internal_errors(false);

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
     * Process results from any of the get_list method and convert into the appropriate structure
     * @param String $response result from post to Intacct Web Services
     * @param string $returnFormat valid returnFormat value
     * @param Integer $count by reference count of records returned
     * @throws Exception
     * @return Mixed string or object depending on return format
     */
    public static function processListResults($response, $returnFormat, &$count) {
        //dbg($response);

        $xml = simplexml_load_string($response);

        $success = $xml->operation->result->status;
        if ($success != "success") {
            throw new Exception("Get List failed");
            return;
        }

        if ($returnFormat != api_returnFormat::PHPOBJ) {
            throw new Exception("Only PHPOBJ is supported for returnFormat currently.");
            return;
        }

        $json = json_encode($xml->operation->result->data,JSON_FORCE_OBJECT);
        if ($json == "{}")  {
            return array();
        }
            
        $array = json_decode($json,TRUE);

        $obj = key($array);

        if (!is_numeric(key($array[$obj]))) {
            $array[$obj] =  array ( $array[$obj] );
        }

        // check for known line item issues
        // lame, but not sure how else to fix this. the json_decode removes the level from a single line item and it needs to be restored
        // make this generic if it works
        if (isset($array['sotransaction'])) {
            foreach ($array['sotransaction'] as $key => $txn) {
                if (isset($txn['sotransitems']['sotransitem'])) {
                    if (!is_numeric(key($txn['sotransitems']['sotransitem']))) {
                        $array['sotransaction'][$key]['sotransitems']['sotransitem'] = array ($txn['sotransitems']['sotransitem']);
                    }
                }
            }
        }
        if (isset($array['dimensions'])) {
            $array['dimensions'] = $array['dimensions'][0]['dimension'];
        }
        if (isset($array['arpayment'])) {
            foreach ($array['arpayment'] as $key => $txn) {
                if (isset($txn['lineitems']['lineitem'])) {
                    if (!is_numeric(key($txn['lineitems']['lineitem']))) {
                        $array['arpayment'][$key]['lineitems']['lineitem'] = array ($txn['lineitems']['lineitem']);
                    }
                }
            }
        }

        if (isset($array['recursotransaction'])) {
            foreach ($array['recursotransaction'] as $key => $txn) {
                if (isset($txn['recursotransitems']['recursotransitem'])) {
                    if (!is_numeric(key($txn['recursotransitems']['recursotransitem']))) {
                        $array['recursotransaction'][$key]['recursotransitems']['recursotransitem'] = array ($txn['recursotransitems']['recursotransitem']);
                    }
                }
            }
        }
        return $array;
    }

    /**
     * Process results from any of the read methods and convert into the appropriate structure
     * @param String $response result from post to Intacct Web Services
     * @param string $returnFormat valid returnFormat value
     * @param Integer $count by reference count of records returned
     * @throws Exception
     * @return Mixed string or object depending on return format
     */
    private static function processReadResults($response, $returnFormat, &$count) {
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
            throw new Exception("The JSON return format is not implemented yet.");
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
            throw new Exception("Unknown return format $returnFormat.  Refer to the api_returnFormat class.");
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

    public static function getLastResponseHeader() {
      return self::$lastResponseHeader;
    }

    public static function setDryRun($tf=true)
    {
        self::$dryRun = $tf;
    }

}
