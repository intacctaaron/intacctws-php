<?
class api_returnFormat {
	
    const PHPOBJ = 'phpobj';
    const CSV = 'csv';
    const XML = 'xml';
    const JSON = 'json';
    
    /**
     * simple mechanism to ensure a valid value is passed
     */
    public static function validateReturnFormat($format) {
	if (!in_array($format, array(self::PHPOBJ,
				     self::CSV,
				     self::XML,
				     self::JSON))) {
	    throw new Exception("$format is not a valid return format.");
	}
    }
    
}
?>