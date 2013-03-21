<?
class api_viewFilters {
  
    public $filters;
    public $operator;

    /**
     * Combine one or more filters to pass to the readView method.
     * @param Array $filters array of api_viewFilter objects
     * @param string $operator
     * @throws Exception
     */
    function __construct(array $filters, $operator = 'AND') {
	
        foreach($filters as $filter) {
            if (!is_object($filter) || !get_class($filter) == "api_viewFilter") {
                throw new Exception("Filters must be an instance of the api_viewFilter object");
            }
        }

        $this->filters = $filters;
        $this->operator = $operator;
    }
}
?>