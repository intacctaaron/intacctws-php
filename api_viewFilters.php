<?
class api_viewFilters {
  
  public $filters;
  public $operator;

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