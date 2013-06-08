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

/**
 * class api_util
 * Utility methods for the intacctws-php classes
 */
class api_util {

    /**
     * Convert a currency amount to a decimal amount
     * @param String $value
     * @internal param String $value amount in currency format
     * @return Number amount in decimal format
     */
    public static function currToDecimal($value) {
        $new = "";
        for($x=0; $x < strlen($value); $x++) {
            if (substr($value, $x, 1) != '$' && substr($value, $x, 1) != ',') {
                $new .= substr($value, $x, 1);
            }
        }
        return $new;

    }

    /**
     * Convert a date to the old 2.1 date format
     * @param String $date date in format "m/d/Y"
     * @return String in the old 2.1 xml date format
     */
    public static function dateToOldDate($date) {
        $xml = "";
        $xml .= "<year>" . date("Y",strToTime($date)) . "</year>";
        $xml .= "<month>" . date("m",strToTime($date)) . "</month>";
        $xml .= "<day>" . date("d", strToTime($date)) . "</day>";
        return $xml;
    }

    /**
     * Given a starting date and a number of periods, return an array of dates
     * where the first date is the last day of the month of the first period
     * and every subsequent date is the last day of the following month
     * @param String $date starting date
     * @param int $count number of periods to compute
     * @return array
     */
    public static function getRangeOfDates($date, $count) {
        // the first date is the first of the following month                                                                        
        $month = date("m", strToTime($date)) + 1;
        $year = date("Y", strToTime($date));
        if ($month == 13) {
            $month = 1;
            $year++;
        }
        $dateTime = new DateTime($year . "-" . $month . "-01");
        $dateTime->modify("-1 day");

        $dates = array($dateTime->format("Y-m-d"));
        // now, iterate $count - 1 times adding one month to each                                                                    
        for ($x=1; $x < $count; $x++) {
            $dateTime->modify("+1 day");
            $dateTime->modify("+1 month");
            $dateTime->modify("-1 day");
            array_push($dates, $dateTime->format("Y-m-d"));
        }
        return $dates;
    }

    /**                                                                                                                              
     * Convert a php structure to an XML element                                                                                     
     * @param String $key element name
     * @param Array $values element values
     * @return string xml                                                                                                            
     */
    public static function phpToXml($key, $values) {
        $xml = "";
        if (!is_array($values)) {
            return "<$key>$values</$key>";
        }

        if (!is_numeric(array_shift(array_keys($values)))) {
            $xml = "<" . $key . ">";
        }
        foreach($values as $node => $value) {
            $attrString = "";
            $_xml = "";
            if (is_array($value)) {
                if (is_numeric($node)) {
                    $node = $key;
                }
                // collect any attributes
                foreach ($value as $_k => $v) {
                    if (!is_array($v)) {
                        if (substr($_k,0,1) == '@') {
                            $pad = ($attrString == "") ? " " : "";
                            $aname = substr($_k,1);
                            $aval  = $v;
                            //$attrs = explode(':', substr($v,1));
                            //$attrString .= $pad . $attrs[0].'="'.$attrs[1].'" ';
                            $attrString .= $pad . $aname.'="'.$aval.'" ';
                            unset($value[$_k]);
                        }
                    }
                }

                $firstKey = array_shift(array_keys($value));
                if (is_array($value[$firstKey]) || count($value) > 1 ) {
                    $_xml = self::phpToXml($node,$value) ; 
                }
                else {
                    $v = $value[$firstKey];
                    $_xml .= "<$node>" . htmlspecialchars($v) . "</$node>";
                }

                if ($attrString != "") {
                    $_xml = preg_replace("/^<$node/","<$node $attrString", $_xml);
                }

                $xml .= $_xml;
            }
            else {
                if (is_numeric($node)) {
                    $xml .= "<" . $key. $attrString . ">" . htmlspecialchars($value) . "</" . $key. ">";
                }
                else {
                    $xml .= "<" . $node . $attrString . ">" . htmlspecialchars($value) . "</" . $node . ">";
                }
            }
        }
        if (!is_numeric(array_shift(array_keys($values)))) {
            $xml .= "</" . $key . ">";
        }
        return $xml;
    }

    /**                                                                                                                              
     * Convert a CSV string result into a php array.                                                                                 
     * This work for Intacct API results.  Not a generic method                                                                      
     */
    public static function csvToPhp($csv) {

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, trim($csv));

        rewind($fp);

        $table = array();
        // get the header row                                                                                                        
        $header = fgetcsv($fp, 10000, ',','"');
        if (is_null($header) || is_null($header[0])) {
            throw new exception ("Unable to determine header.  Is there garbage in the file?");
        }

        // get the rows                                                                                                              
        while (($data = fgetcsv($fp, 10000, ',','"')) !== false) {
            $row = array();
            foreach($header as $key => $value) {
                $row[$value] = $data[$key];
            }
            $table[] = $row;
        }

        return $table;
    }

    /**
     * Convert a error object into nice text
     * @param Object $error simpleXmlObject
     * @return string formatted error message
     */
    public static function xmlErrorToString($error) {

        if (!is_object($error)) {
            return "Malformed error: " . var_export($error, true);
        }

        $error = $error->error[0];
        if (!is_object($error)) {
            return "Malformed error: " . var_export($error, true);
        }

        $errorno = is_object($error->errorno) ? (string)$error->errorno : ' ';
        $description = is_object($error->description) ? (string)$error->description : ' ';
        $description2 = is_object($error->description2) ? (string)$error->description2 : ' ';
        $correction = is_object($error->correction) ? (string)$error->correction : ' ';
        return "$errorno: $description: $description2: $correction";
    }


}
