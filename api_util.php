<?
/**                                                                                                                                  
 * Utility methods for the fixed assets application                                                                                  
 */
class api_util {

    /**                                                                                                                              
     * Convert a currency amount to a decimal amount                                                                                 
     * @param value String amount in currency format                                                                                 
     * @return Decimal amount in decimal format                                                                                      
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
     * @param date String date in format "m/d/Y"                                                                                     
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
     * @param date String starting date                                                                                              
     * @param count Integer number of periods to compute                                                                             
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
     * @param key String element name                                                                                                
     * @param values Array element values                                                                                            
     * @return string xml                                                                                                            
     */
    public static function phpToXml($key, $values) {
        $xml = "<" . $key . ">";
        foreach($values as $node => $value) {
            $xml .= "<" . $node . ">" . htmlspecialchars($value) . "</" . $node . ">";
        }
        $xml .= "</" . $key . ">";
        return $xml;
    }

    /**                                                                                                                              
     * Convert a CSV string result into a php array.                                                                                 
     * This work for Intacct API results.  Not a generic method                                                                      
     */
    public static function csvToPhp($csv) {

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $csv);

        rewind($fp);

        $table = array();
        // get the header row                                                                                                        
        $header = fgetcsv($fp, 10000, ',','"');

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
     * @arg error simpleXmlObject
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
?>