<?
class api_viewFilter {
    public $field;
    public $operator;
    public $value;
    
    /**
     * Create a view filter.  Combine multiple filters together in a api_viewFilters object
     * @param String $field filter to filter against
     * @param String $operator One of the valid operators for filtering.  Changes based on the
     * field type.  Refer to the edit view page in any record to see a list of valid operators
     * @param String $value The value to apply to the filter
     */ 
    function __construct($field, $operator, $value) {
	$this->field = $field;
	$this->operator = $operator;
	$this->value = HTMLSpecialChars($value);
    }
}
?>