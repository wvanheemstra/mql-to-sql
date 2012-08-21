<?php
include 'config.php';

/**
*   Benchmarking
*/
$callstack = array();
function callstack_push($name){
    global $callstack;
    $callstack[] = array(
        'name'      =>  $name
    ,   'microtime' =>  microtime()
    );
}
/*****************************************************************************
*   General Functions
******************************************************************************/
function get_last_json_error(){
    if (function_exists('json_last_error')){
        $error = json_last_error();
        $message = $error.': ';
        switch($error){
            case JSON_ERROR_NONE:
                $message .= 'No error has occurred';
                break;
            case JSON_ERROR_DEPTH:
                $message .= 'The maximum stack depth has been exceeded';
                break;
            case JSON_ERROR_CTRL_CHAR   :
                $message .= 'Control character error, possibly incorrectly encoded';
                break;
            case JSON_ERROR_SYNTAX:
                $message .= 'Syntax error';
                break;
            case JSON_ERROR_UTF8:
                $message .= 'Malformed UTF-8 characters, possibly incorrectly encoded';
                break;
        }
    } 
    else {
        $message = 'function json_last_error() does not exist - no error information available. PHP version: '.phpversion();
    }
    return $message;
}
/*****************************************************************************
*   MQL processing Functions
******************************************************************************/
function analyze_type($type) {
    $type_pattern = '/^\/(\w+)\/(\w+)$/';
    $matches = array();
    if (preg_match($type_pattern, $type, $matches)){
        return array(
            'domain'    => $matches[1]
        ,   'type'     => $matches[2]
        );
    }
    return FALSE;
}

function is_filter_property($value){    
    if ($value===NULL) {
        return FALSE;
    }
    else 
    if (is_object($value) && count(get_object_vars($value))===0){
        return FALSE;
    }
    else 
    if (is_array($value) && count($value)===0) {
        return FALSE;
    }
    else {
        return TRUE;
    }
}

function analyze_property($property_name, $property_value){
    //                      12   2 1 345          5  4 6      647                                 7
    $property_pattern = '/^(((\w+):)?(((\/\w+\/\w+)\/)?(\w+|\*))(=|<=?|>=?|~=|!=|\|=|!\|=|\?=|!\?=)?)$/';
    $matches = array();
    if (preg_match($property_pattern, $property_name, $matches)){
        return array(
            'prefix'        =>  $matches[3]
        ,   'qualifier'     =>  $matches[6]
        ,   'name'          =>  $matches[7]
        ,   'operator'      =>  isset($matches[8])? $matches[8] : NULL
        ,   'qualified'     =>  $matches[5]? TRUE : FALSE
        ,   'value'         =>  $property_value
        ,   'is_filter'     =>  is_filter_property($property_value)
        ,   'is_directive'  =>  FALSE
        ,   'schema'        =>  NULL
        );
    }
    return FALSE;
}

function get_type_from_schema($domain, $type){
    global $metadata;
        $domains = $metadata['domains'];
        if (isset($domains[$domain])){
                $domain = $domains[$domain];
        } else {
                return NULL;
        }
        $types = $domain['types'];
        if (isset($types[$type])){
                return $types[$type];
        }
        else {
                return NULL;
        }    
}

//helper for process_mql_object
function get_parent_type($parent, &$types){
    if (isset($parent) && isset($parent['schema'])) {
        $parent_schema_type_name = $parent['schema']['type'];
        $parent_schema_type = analyze_type($parent_schema_type_name);
        $parent_schema_type_domain = $parent_schema_type['domain'];
        $parent_schema_type_type = $parent_schema_type['type'];
        $parent_schema_type = get_type_from_schema($parent_schema_type_domain, $parent_schema_type_type);		
        if (!$parent_schema_type) {		
            exit('The parent type "/'
            .$parent_schema_type_domain.'/'.$parent_schema_type_type
            .'" was not found in the schema.'
            .' This indicates a logical error in the schema.'
            );
        }
        $types[$parent_schema_type_name] = $parent_schema_type;
    }
}

//helper for process_mql_object
function check_types($types) {
    switch (count($types)) {
        case 0:
            exit('Could not find a type. Currently we rely on a known type');
            break;
        case 1:
            //assigning the contents of the array to the $type variable.
            //php gurus, any better way to do this?
            foreach($types as $type_name => $type){} 
            break;
        default:
            exit('Found more than one type. Currently we can handle only one type.');
    }
}

//helper for process_mql_object
function expand_star($source_properties, &$target_properties) {
    unset($target_properties['*']);
    foreach ($source_properties as $property_name => $property) {
        if (isset($target_properties[$property_name])){
            continue;
        }
        if (isset($property['column_name'])) {
            $target_properties[$property_name] = array(
                'is_directive'  =>  FALSE,
                'qualifier'     =>  '',
                'name'          =>  $property_name,
                'value'         =>  NULL,
                'is_filter'     =>  FALSE,
                'operator'      =>  NULL
            );
        }
    }
}






//helper for process_mql_object
function pre_process_properties($object_vars, &$properties, &$types, &$star_property){
    foreach ($object_vars as $property_key => $property_value) {
        if (!($property = analyze_property($property_key, $property_value))){
            exit('Property "'.$property_key.'" is not valid.');
        }
        $operator = $property['operator'];
        if ($operator) {            
            $operator_in = ($operator==='|=')||($operator==='!|=');
            if ($property_value === NULL
            ||  is_object($property_value)
            || ($operator_in && is_array($property_value) && count($property_value)===0)
            ){
                exit("Operator ".$operator.' '
                .(($operator==='|=' || $operator==='!|=')
                ? 'takes a non-empty list of values' 
                : 'takes a single value (not an object or an array)')
                );
            }
        }
        $property_qualifier = $property['qualifier'];
        $property_name      = $property['name'];
		
        switch ($property_name) {
            case 'type':
            case 'creator':
            case 'guid':
            case 'id':         
            case 'key':         
            case 'name':
            case 'permission':
            case 'timestamp':
                if ($property_qualifier==='') {
                    $property['qualifier'] = '/type/object';
                }
                break;
            case 'limit':
            case 'optional':
            case 'return':
            case 'sort':
            case '*':
                if ($property_qualifier==='' ) {
                    $property['is_directive'] = TRUE;
                    switch ($property_name) {
                        case 'optional':
                            $parent['optional'] = ($property_value===TRUE || $property_value==='optional');
                            break;
                        case '*':
                            $star_property = TRUE;
                            break;
                    }
                }
            default:
                if ($property_qualifier === '/type/object') {
                    exit('"'.$property_name.'" is not a universal property, and may not have the qualifier "'.$property_qualifier.'".');
                }
        }
		
        if ($property['qualifier'] === '/type/object'
        &&  $property_name         === 'type'
        &&  isset($property_value)
        && !isset($types[$property_value])
        ) {     
            $type = analyze_type($property_value);
            if (!$type) {
                exit('"'.$property_value.'" is not a valid type identifier.');
            }
            $domain = $type['domain'];
            $domain_type = $type['type'];
            $type = get_type_from_schema($domain, $domain_type);
            if (!$type) {
                exit('Type "/'.$domain.'/'.$domain_type.'" not found in schema.');
            }
            $types[$property_value] = $type;
        }            
        $properties[$property_key] = $property;		
	}
}

//helper for process_mql_object
function process_properties(&$properties, $type_name, $type) {
    foreach ($properties as $property_name => &$property){
        if ($property['is_directive']===TRUE) {
            continue;
        }
        switch ($property['qualifier']) {
            case '/type/object':
                continue;
            case '':
                $schema_property = $type['properties'][$property['name']];
                if ($schema_property) {
                    $property['qualifier'] = $type_name;
                    $property['schema'] = $schema_property;
                    if (isset($schema_property['join_condition'])) {
                        $property['types'][] = $schema_property['type'];
                        $property_value = &$property['value'];
                        if (is_object($property_value) || is_array($property_value)) {
                            process_mql($property_value, $property);
                        }                        
                    }
                }
                else {
                    exit('No property "'.$property['name'].'" in type "'.$type_name.'".');
                }
                break;
            default:
                if ($property['qualifier']!==$type_name) {
                    exit('Property "'.$property['qualifier'].'/'.$property['name']
                    .'" does not belong to the type "'.$type_name.'". This feature is not supported yet.');
                }
        }
    }
}












function process_mql_object(&$mql_object, &$parent){
    $object_vars = get_object_vars($mql_object);    
    $properties = array();
    $type = NULL;
    $types = array();
    $star_property = FALSE;
    $parent['properties'] = &$properties;
	
    get_parent_type($parent, $types);
    pre_process_properties($object_vars, $properties, $types, $star_property);
    check_types($types);
    foreach($types as $type_name => $type){}    //extract the type name
    $parent['types'] = array_keys($types);
    if ($star_property===TRUE) {
        expand_star($type['properties'], $properties);
    }
    process_properties($properties, $type_name, $type);
}	

function process_mql_array($mql_array, &$parent){
    $count = count($mql_array);
    switch ($count) {
        case 0:
            break;
        case 1:
            $parent['entries'] = array();
            if (array_key_exists('schema', $parent)) {
                $parent['entries']['schema'] = $parent['schema'];
            }
            process_mql($mql_array[0], $parent['entries']);
            break;
        default:
            exit('Expected a dictionary or a list with one element in a read (were you trying to write?)');
    }
}

function process_mql($mql, &$parent){
    if ($mql===NULL) {
    }
    else 
    if (is_object($mql)){
        process_mql_object($mql, $parent);
    }
    else 
    if (is_array($mql)){ 
        process_mql_array($mql, $parent);
    }
    else {
        exit('mql query must be an object or an array, not "'.gettype($mql).'".');
    }
}
/*****************************************************************************
*   SQL generation Functions
******************************************************************************/
$t_alias_id = 0;
$c_alias_id = 0;
$p_id = 0;

function reset_ids(){
    global $t_alias_id, $c_alias_id, $p_id;
    $t_alias_id = 0;
    $c_alias_id = 0;
    $p_id = 0;
}

function get_t_alias(){
    global $t_alias_id;
    return 't'.(++$t_alias_id);
}

function get_c_alias($new=TRUE){
    global $c_alias_id;
    if ($new){
        $c_alias_id++;
    }
    return 'c'.$c_alias_id;
}

function get_p_name(){
    global $p_id;
    return 'p'.(++$p_id);
}

// ADDED by wvh: UPDATE behaves like FROM but not completely
function get_update_clause(&$mql_node, $t_alias, $child_t_alias, $schema_name, $table_name, &$query){
    $schema = isset($mql_node['schema'])?$mql_node['schema']:NULL;
    $update = &$query['update'];
    $update_line = array();
    $update_line['table'] = ($schema_name? $schema_name.'.' : '').$table_name;
    $update_line['alias'] = $t_alias;
    $update[$t_alias] = $update_line;	
}

function get_from_clause(&$mql_node, $t_alias, $child_t_alias, $schema_name, $table_name, &$query){
    $schema = isset($mql_node['schema'])?$mql_node['schema']:NULL;
    $from = &$query['from'];
    $count_from = count($from);
    $from_line = array();
    $join_condition = '';
    if (isset($schema['direction'])) {
                $direction = $schema['direction'];	
        if (($optional = is_optional($mql_node))===TRUE){
            $mql_node['outer_join'] = TRUE;
            $outer_join = TRUE;
        }	
        else
                if (isset($mql_node['outer_join'])) {
            $outer_join = $mql_node['outer_join'];
        }
                else {
                        $outer_join = FALSE;
                }
        
        $from_line['join_type'] = ($outer_join===TRUE) ? 'LEFT' : 'INNER';	
	
        switch ($direction) {
            case 'referencing->referenced':     //lookup (n:1 relationship)           
                break;
            case 'referenced<-referencing':     //lookdown (1:n relationship) - starts a separate query.
                $select = &$query['select'];
                $order_by = &$query['order_by'];
                $merge_into = &$query['merge_into'];
                $merge_into_columns = &$merge_into['columns'];
                break;
        }
		
        foreach ($schema['join_condition'] as $columns) {
            $join_condition .= ($join_condition==='')? 'ON':"\nAND";
            switch ($direction){
                case 'referencing->referenced':
                    $referenced_column = $t_alias.'.'.$columns['referenced_column'];

                    if ($outer_join===TRUE && $join_condition === 'ON'){
                        if ($optional===TRUE) {
                            $from_line['optionality_group'] = $t_alias;
                        }
                        else {
                            if ($count_from) {                        
                                $from_line['optionality_group'] = $from[$child_t_alias]['optionality_group'];
                            }
                            else {
                                $from_line['optionality_group'] = $child_t_alias;
                            }
                        }
                        $from_line['optionality_group_column'] = $referenced_column;
                    }

                    $join_condition .= ' '  .$child_t_alias.'.'.$columns['referencing_column']
                                    .  ' = '.$referenced_column;

                    break;
                case 'referenced<-referencing':
                    $column_ref = $t_alias.'.'.$columns['referencing_column'];
                    $alias = $t_alias.get_c_alias();
                    $merge_into_columns[] = $alias;
                    $select[$column_ref] = $alias;
                    $order_by .= ($order_by===''? 'ORDER BY ' : "\n, ");
                    $order_by .= $alias;
                    break;
            }
        } 
	}
    $from_line['table'] = ($schema_name? $schema_name.'.' : '').$table_name;
    $from_line['alias'] = $t_alias;
    if ($join_condition) {
        $from_line['join_condition'] = $join_condition;
    }
    $from[$t_alias] = $from_line;	
}

function map_mql_to_pdo_type($mql_type){
    switch ($mql_type){
        case '/type/boolean':
            $pdo_type = PDO::PARAM_BOOL;
            break;
        case '/type/content':
            $pdo_type = PDO::PARAM_LOB;
            break;
        case '/type/datetime':
        case '/type/text':
        case '/type/float': //this feels so wrong, but PDO doesn't seem to support any decimal/float type :(
            $pdo_type = PDO::PARAM_STR;
            break;
        case '/type/int':
            $pdo_type = PDO::PARAM_INT;
            break;
        case '/type/rawstring':
            $pdo_type = PDO::PARAM_STR;
            break;
    }
    return $pdo_type;
}

// RENAMED by wvh: Made this function specific for WHERE
function add_where_parameter(&$where, &$params, $value, $pdo_type){
    $where .= ':'.($param_name = get_p_name());
    $params[] = array(
        'name'  =>  $param_name
    ,   'value' =>  $value
    ,   'type'  =>  $pdo_type
    );
}

// ADDED by wvh: Made this function specific for SET
function add_set_parameter(&$set, &$params, $value, $pdo_type){
    $set .= ':'.($param_name = get_p_name());
    $params[] = array(
        'name'  =>  $param_name
    ,   'value' =>  $value
    ,   'type'  =>  $pdo_type
    );
}

// RENAMED by wvh: Made this function specific for WHERE
function add_parameter_for_where_property(&$where, &$params, $property){
    $property_value = $property['value'];
    $mql_type = $property['schema']['type'];
    $pdo_type = map_mql_to_pdo_type($mql_type);
    if (is_array($property_value)) {
        $num_entries = count($property_value);
        for ($i=0; $i<$num_entries; $i++) {
            if ($i){
                $where .= ', ';
            }
            add_where_parameter($where, $params, $property_value[$i], $pdo_type);
        }
    }
    else {
	
//        add_where_parameter($where, $params, $property_value, $pdo_type);  // TEMPORARILY COMMENTED OUT by wvh to prevent more than one parameter values in WHERE clause
    }
}

// ADDED by wvh: Made this function specific for SET
function add_parameter_for_set_property(&$set, &$params, $property){
    $property_value = $property['value'];
    $mql_type = $property['schema']['type'];
    $pdo_type = map_mql_to_pdo_type($mql_type);
    if (is_array($property_value)) {
        $num_entries = count($property_value);
        for ($i=0; $i<$num_entries; $i++) {
            if ($i){
                $set .= ', ';
            }
            add_set_parameter($set, $params, $property_value[$i], $pdo_type);
        }
    }
    else {
        add_set_parameter($set, $params, $property_value, $pdo_type);
    }
}

function handle_filter_property(&$queries, $query_index, $t_alias, $column_name, $property){
    $query = &$queries[$query_index];
    $from = &$query['from'];
    $params = &$query['params'];
    $where = &$query['where'];
	$set = &$query['set']; // ADDED by wvh: SET behaves like WHERE but not completely
	
    $num_from_lines = count($from);
    if ($num_from_lines > 1){
        $from_line = &$from[$num_from_lines -  1];
        $from_or_where = &$from_line['join_condition'];
        $from_or_where .= "\n".'AND '.$t_alias.'.'.$column_name;
		$set .= ','.$t_alias.'.'.$column_name; // ADDED by wvh: SET behaves like WHERE but not completely
    }	
    else {
        $from_or_where = &$query['where'];	

		// ADDED by wvh: to prevent more than one WHERE occurence in WRITE query
		// NOTE: the indexed WHERE parameter (e.g. customer_id) needs therefor to be the FIRST one listed in the WRITE query!!!
		if($from_or_where==''){
			$from_or_where .= ($from_or_where? 'WHERE' : 'WHERE').' '.$t_alias.'.'.$column_name;
		}
						
		$set .= ($set? ',' : 'SET').' '.$t_alias.'.'.$column_name; // ADDED by wvh: SET behaves like WHERE but not completely									
    }

    //prepare right hand side of the filter expression
		$set .= ' = '; // ADDED by wvh: SET behaves like WHERE but not completely
        $add_closing_parenthesis = FALSE;
        $add_closing_escape_clause = FALSE;
    if ($operator = $property['operator']) {
        //If an operator is specified, 
        //the expression is used in the WHERE clause.
        switch ($operator) {
            case '~=':  //funky mql pattern matcher
                //not implemented yet. 
                //most likely it will be very hard 
                //to implement this in a rdmbs-independent way
                //let alone efficiency
                break;
            case '<': case '>': case '<=': case '>=': case '!=': 
            case '=': //note that = is an extension. Silly it's not standard.
                $from_or_where .= ' '.$operator.' ';
                break;
            case '!|=':
                $from_or_where .= ' NOT';
                //fall through is intentional, keep the !|= and |= together please, in order.
            case '|=':
                $from_or_where .= ' IN (';
                $add_closing_parenthesis = TRUE;            
                break;
            case '!?=': //extension. Ordinary database NOT LIKE
                $from_or_where .= ' NOT';
                //fall through is intentional, keep the !?= and ?= together please, in order.
            case '?=':  //extension. Ordinary database LIKE
                $from_or_where .= ' LIKE ';
                $add_closing_escape_clause = TRUE;
                break;
        }
    }
    else {
        //If no operator is specified, 
        //the comparison is automatically with equals.
        $from_or_where .= ' = ';
    }
    //prepare the right hand side of the comparison expression
    add_parameter_for_set_property($set, $params, $property); // ADDED by wvh: SET behaves like WHERE but not completely

    add_parameter_for_where_property($from_or_where, $params, $property); // RENAMED by wvh: Now specific for WHERE parameters	
	
    if ($add_closing_parenthesis) {
        $from_or_where .= ')';
    }
    else 
    if ($add_closing_escape_clause) {
        $from_or_where .= " ESCAPE '\\'";
    }
}

function handle_non_filter_property($t_alias, $column_name, &$select, &$property){
    $c_alias = $t_alias.get_c_alias();
    $column_ref = $t_alias.'.'.$column_name;
    $select[$column_ref] = $c_alias;
    $property['alias'] = $c_alias;
}

function generate_sql(&$mql_node, &$queries, $query_index, $child_t_alias=NULL, &$merge_into=NULL){
    global $metadata;
    if (isset($mql_node['entries'])) {
        generate_sql($mql_node['entries'], $queries, $query_index, $child_t_alias, $merge_into);
        return;
    }	
	
    if (!isset($mql_node['query_index'])){
        $mql_node['query_index'] = $query_index;
    }	
	
    $query = &$queries[$query_index];
    if (!$query){
        $query = array(
            'update'                =>  array()	// added by wvh: UPDATE behaves like FROM, but not completely
        ,   'set'                	=>  ''		// added by wvh: SET behaves like WHERE, but not completely			
        ,   'select'                =>  array()
        ,   'from'                  =>  array()
        ,   'where'                 =>  ''
        ,   'order_by'              =>  ''
        ,   'limit'                 =>  ''
        ,   'params'                =>  array()
        ,   'mql_node'              =>  &$mql_node
        ,   'indexes'               =>  array()
        ,   'merge_into'            =>  $merge_into
        ,   'results'               =>  array()
        );
        $queries[$query_index] = &$query;        
    }
    $update = &$query['update'];		// added by wvh: UPDATE behaves like FROM, but not completely	
    $set = &$query['set'];				// added by wvh: SET behaves like WHERE, but not completely
    $select = &$query['select'];
    $from   = &$query['from'];
    $where  = &$query['where'];	
    $params = &$query['params'];
    $indexes = &$query['indexes'];
    
    $type = analyze_type($mql_node['types'][0]);
    $domain_name = $type['domain'];
    $domains = $metadata['domains'];
    $schema_domain = $domains[$domain_name];
    $type_name = $type['type'];
    $schema_type = $schema_domain['types'][$type_name];
    
    //table_name is either explicitly specified, or we take the type name
    if (isset($schema_type['table_name'])){
        $table_name = $schema_type['table_name'];
    } 
    else {
        $table_name = $type_name;
    }

    //schema_name is either explicitly specified, or we take the domain name
    if (isset($schema_type['schema_name'])) {   //schema_name is defined at the type level
        $schema_name = $schema_type['schema_name'];
    }
    else                                        //schema_name is defined at the domain level     
    if (isset($schema_domain['schema_name'])){
        $schema_name = $schema_domain['schema_name'];
    }
    else {                                      //schema_name not defined, settle for the domain name
        $schema_name = $domain_name;
    }
	
	$t_alias = get_t_alias();
	
    get_from_clause($mql_node, $t_alias, $child_t_alias, $schema_name, $table_name, $query);
	get_update_clause($mql_node, $t_alias, $child_t_alias, $schema_name, $table_name, $query); // ADDED by wvh: UPDATE behaves like FROM, but not completely
	
	if (array_key_exists('properties', $mql_node)) {
	    $properties = &$mql_node['properties'];
        foreach ($properties as $property_name => &$property) {	
	
            if ($property['is_directive']) {
                switch ($property_name) {
                    case 'limit':
                        $limit = intval($property['value']);
                        if ($limit < 0) {
                            exit('Limit must not be less than zero.');
                        }
                        $query['limit'] = $limit;
                        break;
                }
            }	
            else
            if (isset($mql_node['outer_join'])){
				$property['outer_join'] = $mql_node['outer_join'];
            }	
            $schema = $property['schema'];	
            if (isset($schema['direction'])) {
                                $direction = $schema['direction'];	
                if ($direction === 'referenced<-referencing'){
                    $index_columns = array();
                    $index_columns_string = '';	
                    foreach ($schema['join_condition'] as $columns) {
                        $column_ref = $t_alias.'.'.$columns['referenced_column'];
                        if (isset($select[$column_ref])) {
                            $c_alias = $select[$column_ref];
                        }
                        else {
                            $c_alias = $t_alias.get_c_alias();
                            $select[$column_ref] = $c_alias;
                        }
                        $index_columns_string .= $c_alias;
                        $index_columns[] = $c_alias;
                    }	
                    if (!isset($indexes[$index_columns_string])){
                        $indexes[$index_columns_string] = array(
                            'columns'   =>  $index_columns
                        ,   'entries'   =>  array()
                        );
                    }	
                    $merge_into = array(
                        'query_index'   =>  $query_index                  
                    ,   'index'         =>  $index_columns_string
                    ,   'columns'       =>  array()
                    );
                    $new_query_index = count($queries);
                }
                else 
                if ($direction === 'referencing->referenced') {
                    $merge_into = NULL;
                    $new_query_index = $query_index;
                }            
                $property['query_index'] = $new_query_index;
                generate_sql($property, $queries, $new_query_index, $t_alias, $merge_into);	
            }
            else 
            if ($column_name = $schema['column_name']){	
                if ($property['is_filter']) {        
                    handle_filter_property($queries, $query_index, $t_alias, $column_name, $property);
                }
                else {
                    handle_non_filter_property($t_alias, $column_name, $select, $property);
                }	
            }	
		}
	}
	else 
    if (array_key_exists('default_property', $schema_type)) {
        $default_property_name = $schema_type['default_property'];
        $properties = $schema_type['properties'];
        if (!array_key_exists($default_property_name, $properties)) {
            exit('Default property "'.$default_property_name.'" specified but not found in "/'.$domain_name.'/'.$type_name.'"');
        }
        $default_property = $properties[$default_property_name];
        $column_name = $default_property['column_name'];
        $property = &$mql_node;
        $schema = &$property['schema'];
        $schema['type'] = $default_property['type'];
        if ($property['is_filter']) {        
            handle_filter_property($where, $params, $t_alias, $column_name, $property);
        }
        else {
            handle_non_filter_property($t_alias, $column_name, $select, $property);
        }
	}
}	





















/*****************************************************************************
*   Execute query / render result
******************************************************************************/
function map_mql_to_php_type($mql_type){
    switch ($mql_type){
        case '/type/boolean':
            $php_type = 'bool';
            break;
        case '/type/content':
        case '/type/datetime':
        case '/type/text':
        case '/type/rawstring':
            $php_type = 'string';
            break;
        case '/type/float': 
            $php_type = 'float';
            break;
        case '/type/int':
            $php_type = 'integer';
            break;
        default:
            throw new Exception('No php type defined for mql type: '.$mql_type);
    }
    return $php_type;
}

$statement_cache = array();

function prepare_sql_statement($statement_text){
    global $pdo, $statement_cache;
    if (isset($statement_cache[$statement_text])){
        $statement_handle = $statement_cache[$statement_text];
    } else {
        $statement_handle = $pdo->prepare($statement_text);
        $statement_cache[$statement_text] = $statement_handle;
    }
	
	//echo $statement_handle->debugDumpParams(); // ADDED by wvh for debugging only
	
    return $statement_handle;
}

function &execute_sql($statement_text, $params, $limit){
    global $pdo, $noexecute;
    if ($noexecute){
        return array();
    }
    try {
	
		//for($i=0;$i<count($params);$i++) // ADDED by wvh for debugging only
		//{								// ADDED by wvh for debugging only
		//	$rs=$params[$i];			// ADDED by wvh for debugging only
		//}								// ADDED by wvh for debugging only
		//echo implode(",", $rs);			// ADDED by wvh for debugging only
		//exit;							// ADDED by wvh for debugging only
		
		//echo $statement_text; 	// ADDED by wvh for debugging only
		//exit; 					// ADDED by wvh for debugging only
	
		//$statement_text = 'UPDATE sakila.customer t1\nSET t1.customer_id = :p1, t1.first_name = :p3, t1.last_name = :p5\nWHERE t1.customer_id = :p2'; // ADDED by wvh for debugging only
		//$statement_text = "UPDATE sakila.customer t1 SET t1.customer_id = :p1, t1.first_name = 'MARY', t1.last_name = 'SMITH' WHERE t1.customer_id = :p1"; // ADDED by wvh for debugging only
		
        $statement_handle = prepare_sql_statement($statement_text);
        foreach($params as $param_key => $param){
            $statement_handle->bindValue(
                $param['name']
            ,   $param['value']
            ,   $param['type']
            );
        }
		
		echo $statement_handle->debugDumpParams(); // ADDED by wvh for debugging only
		//exit;										// ADDED by wvh for debugging only
		
        $statement_handle->execute();   // NOTE by wvh: CURRENTLY FAILS ON WHERE CLAUSE WHICH CONTAINS MORE THAN SOLELY THE ID FIELD !!! 
        if ($limit === -1) {
//            $result = $statement_handle->fetchAll(PDO::FETCH_ASSOC);	// COMMENTED OUT by wvh: WRITE queries do not return rows
			$result = []; // ADDED by wvh: for debugging only
        }
        else {
            $result = array();
//            while ($limit-- && $row = $statement_handle->fetch(PDO::FETCH_ASSOC)) {		// COMMENTED OUT by wvh: WRITE queries do not return rows
//                $result[] = $row;
//            }
			$result = []; // ADDED by wvh: for debugging only
        }
        $statement_handle->closeCursor();
    } catch (Exception $exception) {
        throw new Exception(
            $exception->getMessage().
            ' Offending statement: '.$statement_text
			.' '.$statement_handle->debugDumpParams() // ADDED by wvh for debugging only
        );
    }	
    return $result;	
}	

function get_query_sql($query){
        global $sql_dialect;
    
    $identifier_quote_start = $sql_dialect['identifier_quote_start'];
    $identifier_quote_end = $sql_dialect['identifier_quote_end'];

	// ADDED by wvh: UPDATE behaves like FROM, but not completely
    $sql = 'UPDATE';
	
	// ADDED by wvh: UPDATE behaves like FROM, but not completely
    foreach ($query['update'] as $index => $update_line) {
        if (array_key_exists('table', $update_line)) {
            $sql .= " ".$update_line['table'].' '.$update_line['alias'];
        }
	}

	$optionality_groups = array();
/**	COMMENTED OUT by wvh: FROM is not applicable to WRITE queries
    foreach ($query['from'] as $index => $from_line) {
        if (isset($from_line['optionality_group'])) {
                        $optionality_group_name = $from_line['optionality_group'];
            if (!array_key_exists ($optionality_group_name, $optionality_groups)) {
                $optionality_groups[$optionality_group_name] = array();
            }
            $optionality_group = &$optionality_groups[$optionality_group_name];
            $optionality_group[] = $from_line['optionality_group_column'];
        }
        $from_or_join = $index && (isset($from_line['join_type']));
        if ($from_or_join) {
            $sql .= "\n".$from_line['join_type'].' JOIN '
                    .$from_line['table'].' '.$from_line['alias']
                    ."\n".$from_line['join_condition']
            ;
        }
        else
        if (array_key_exists('table', $from_line)) {
            $sql .= "\nFROM ".$from_line['table'].' '.$from_line['alias'];
        }
        else
        if ($from_line['join_condition']){
            //these are filter condition but we write them in the join
            //this is required to handle outer joins.
            $sql .= "\n".$from_line['join_condition'];
        }
    }
*/
	$set = $query['set']; 			// ADDED by wvh: SET behaves like WHERE but not completely
    $sql .= ($set? "\n".$set : '');	// ADDED by wvh: SET behaves like WHERE but not completely
	
    $where = $query['where'];
/**	COMMENTED OUT by wvh: Momentarily not used for WRITE queries
    foreach ($optionality_groups as $k => $v) {
        $condition_null = '';
        $condition_not_null = '';
        foreach ($v as $optionality_group_column) {
            if ($condition_null !== '') {
                $condition_null.= ' AND ';
            }
            $condition_null .= $optionality_group_column.' IS NULL';
            if ($condition_not_null !== '') {
                $condition_not_null.= ' AND ';
            }
            $condition_not_null .= $optionality_group_column.' IS NOT NULL';
        }
        if ($where) {
            $where .= "\nAND";
        }
        else {
            $where .= "\nWHERE";
        }
        $where.= ' (('.$condition_null.') OR ('.$condition_not_null.'))';
    }
*/	
    $sql .= ($where? "\n".$where : '')
//    .       ($query['order_by']? "\n".$query['order_by'] : '')  // wvh: ORDER BY is not applicable to WRITE queries
    ;	
    return $sql;	
}	

function execute_sql_query(&$sql_query){
    global $sql_dialect;
    if ($sql_query['limit'] && !$sql_dialect['supports_limit']) {
        //TODO: this implementation of limit is buggy!
        //It works fine if applied to a top-level mql node,
        //When used for a nested mql node, it does not take into 
        //account that the limit should be applied only to the nested node
        $limit = $sql_query['limit'];
    }
    else {
        //limit has been implemented directly in SQL
        $limit = -1;
    }
    $sql = get_query_sql($sql_query);
    $sql_query['sql'] = $sql;
    return execute_sql($sql, $sql_query['params'], $limit);
}

function get_result_object(&$mql_node, $query_index, &$result_object=NULL, $key=NULL){
    if($mql_node['query_index']!==$query_index){
        return;
    }
    $object = array();
    
    if (is_array($result_object)) {
        $result_object[$key] = &$object;
    } 
    else {
        $result_object = &$object;
    }

    if (isset($mql_node['entries'])) {
         get_result_object($mql_node['entries'], $query_index, $object, 0);
    }
    else 
    if (isset($mql_node['properties'])) {
        foreach ($mql_node['properties'] as $property_key => $property) {
            if ($property['operator'] || $property['is_directive']) {
                continue;
            }
            $value = $property['value'];
            if (is_object($value) || is_array($value)){
                get_result_object($property, $query_index, $object, $property_key);
            }
            else {
                $object[$property_key] = $value;
            }
        }
    }
    $mql_node['result_object'] = $object;
    return $object;
}

function fill_result_object(&$mql_node, $query_index, $data, &$result_object){
    global $explicit_type_conversion;
    if($mql_node['query_index']!==$query_index){
        return;
    }

    if ($entries = &$mql_node['entries']) {
        fill_result_object($entries, $query_index, $data, $result_object[0]);
    }
    else
    if ($properties = &$mql_node['properties']) {
        foreach ($result_object as $key => $value) {
            $property = $properties[$key];
            if (is_object($value) || is_array($value)){
                fill_result_object($property, $query_index, $data, $result_object[$key]);
            }
            else
            if (isset($property['alias'])) {
                                $alias = $property['alias'];
                if ($explicit_type_conversion) {
                    if (!is_null($data[$alias])) {
                        settype($data[$alias], map_mql_to_php_type($property['schema']['type']));
                    }
                }
                $result_object[$key] = $data[$alias];
            }
        }
    }
        
}

function add_entry_to_indexes(&$indexes, $row_index, &$row) {
    foreach($indexes as $index_name => &$index) {
        $entries = &$index['entries'];
        $cols = $index['columns'];
        $colcount = count($cols) - 1;
        for ($i=0; $i<$colcount; $i++){
            $col = $cols[$i];
            $sub_entries = &$entries[$row[$col]];
            if (!$sub_entries) {
                $sub_entries = array();
                $entries[$row[$col]] = &$sub_entries;                
            }
            $entries = &$sub_entries;
        }
        $entries[$row[$cols[$i]]] = $row_index;
    }
}

function &get_entry_from_index(&$query, $index_name, $key){
    $index = $query['indexes'][$index_name]['entries'];
    foreach ($key as $k) {
        $index = $index[$k];
    }
    $results = &$query['results'];
    return $results[$index];    
}

function merge_result_object(&$mql_node, &$result_object, $query_index, &$data, $from, $to){
    if (isset($mql_node['entries'])) {
        merge_result_object($mql_node['entries'], $result_object[0], $query_index, $data, $from, $to);
    }
    else
    if (isset($mql_node['properties'])) {
                $properties = $mql_node['properties'];
        foreach ($properties as $property_key => $property) {
            if ($property['operator']) {
                continue;
            }
            if (isset($property['query_index']) && ($property['query_index']===$query_index)) {
                $result_object[$property_key] = array();
                $target = &$result_object[$property_key];
                for ($i=$from; $i<=$to; $i++){
                    $target[] = &$data[$i];
                }
            }
            else {
                $value = $property['value'];
                if (is_object($value) || is_array($value)){
                    merge_result_object($property, $result_object[$property_key], $query_index, $data, $from, $to);
                }
            }
        }
    }
}

function merge_results(&$queries, $query_index, $key, $from, $to){
    if ($from===-1){
        return;
    }
    $query = &$queries[$query_index];
    $merge_into = $query['merge_into'];
    $target_query_index = $merge_into['query_index'];
    $target_query = &$queries[$target_query_index];
    $index_name = $merge_into['index'];
    $merge_target = &get_entry_from_index($target_query, $index_name, $key);    
    merge_result_object($target_query['mql_node'], $merge_target, $query_index, $query['results'], $from, $to);
}

function create_inline_table_for_index_entry(&$entries, $columns, $column_index, &$statement, &$row){
    global $pdo, $sql_dialect;
    $single_row_from_clause = $sql_dialect['single_row_from_clause'];
    foreach ($entries as $key => $value) {
        $row    .=  ($row === ''? 'SELECT ' : ', ')
                .   (is_string($key)? $pdo->quote($key) : $key)
                .   ($statement === '' ? ' AS '.$columns[$column_index] : '')
                ;

        if (is_array($value)){
            create_inline_table_for_index_entry($value, $columns, $column_index+1, $statement, $row);
        }
        else
        if (is_int($value)) {
            $statement .= ($statement==='' ? '' : "\nUNION ALL\n").$row.$single_row_from_clause;
            $row = '';
        }
    }    
}

function create_inline_table_for_index(&$index){
    $statement = '';
    $row = '';
    create_inline_table_for_index_entry($index['entries'], $index['columns'], 0, $statement, $row);
    $statement = "(\n".$statement."\n)";
    $index['inline_table'] = $statement;
}

function create_inline_tables_for_indexes(&$indexes){
    foreach ($indexes as &$index) {
        create_inline_table_for_index($index);
    }
}

/**
*   execute_sql_queries(&$sql_queries)
*   Executes multiple SQL queries
*
*   arguments:
*   sql_queries:    an array
*
*   return:
*/
function execute_sql_queries(&$sql_queries) {
    foreach($sql_queries as $sql_query_index => &$sql_query){

        $indexes = &$sql_query['indexes'];
                
        $mql_node = $sql_query['mql_node'];
        get_result_object($mql_node, $sql_query_index);
        $result_object = $mql_node['result_object'];

        if ($merge_into = $sql_query['merge_into']) {
            $merge_into_columns = $merge_into['columns'];		
            $select_columns = $sql_query['select'];
			
			$set_columns = $sql_query['set'];	// ADDED by wvh for SET, behaves similar to SELECT but not completely
			
            $merge_into_values_new = array();
            $merge_into_values_old = array();
            $offset = -1;

            $index_name = $merge_into['index'];
            $index = $sql_queries[$merge_into['query_index']]['indexes'][$index_name];
            $index_columns = $index['columns'];
            $extra_from_line = array(
                'table' => $index['inline_table']
            ,   'alias' => $index_name
            );
            $join_condition = '';
            foreach ($index_columns as $position => $index_column) {
                $join_condition .= ($join_condition==='' ? 'ON' : "\nAND").' '
                                .   $index_name.'.'.$index_column.' = '
                                .   array_search($merge_into_columns[$position], $select_columns, TRUE)
                                ;
            }
            $from = &$sql_query['from'];
            //php guru's, isn't there a func to get the first element of an array?
            foreach ($from as &$first_from_line) { break; }
            $first_from_line['join_condition'] = $join_condition;
            $first_from_line['join_type'] = 'INNER';
            array_unshift($from, $extra_from_line); 
		}
		$result = &$sql_query['results'];
        $rows = execute_sql_query($sql_query);		// NOTE by wvh: Make sure that $sql_query['params'] is properly filled

        foreach($rows as $row_index => $row){
            if ($merge_into){            
                foreach ($merge_into_columns as $col_index => $alias){
                    $merge_into_values_new[$col_index] = $row[$alias];
                }
                if ($merge_into_values_new !== $merge_into_values_old){
                    merge_results($sql_queries, $sql_query_index, $merge_into_values_old, $offset, $row_index);
                    $offset = $row_index;
                }
                $merge_into_values_old = $merge_into_values_new;
            }
            fill_result_object($mql_node, $sql_query_index, $row, $result_object);
            $result[$row_index] = $result_object;
            add_entry_to_indexes($indexes, $row_index, $row);
        }
        create_inline_tables_for_indexes($indexes);
        if (isset($merge_into_values_old) && count($merge_into_values_old)) {
            merge_results($sql_queries, $sql_query_index, $merge_into_values_old, $offset, $row_index);
        }		
	
	}
}

/*****************************************************************************
*   Handle request
******************************************************************************/

/**
*   handle_query($mql_query)
*   Executes a single MQL query.
*   
*   arguments:
*   $mql_query: a mql query object (decoded from JSON)
*
*   return:     a result envelope (as associative PHP array)
*/
function handle_query($mql_query_envelop, $query_key=0){
    global $debug_info, $callstack;
    callstack_push('begin query #'.$query_key);
    //check if the query parameter is valid MQL query envelope
    if (!property_exists($mql_query_envelop, 'query')) {
        exit('MQL query envelope must have a query attribute');
    }
    $mql_query = $mql_query_envelop->query;

	$tree = array();
	reset_ids();
    process_mql($mql_query, $tree);	
	
	
	
	// CONTINUE FROM HERE
	
	
    generate_sql($tree, $sql_queries, 0);  	
    execute_sql_queries($sql_queries);
    $result = &$sql_queries[0]['results'];	
	//get the sql statements out for debugging purposes
    $sql_statements = array();
	$return_value = array(
        'code'      =>  '/api/status/ok'
    ,   'result'    =>  $result
    );
	if ($debug_info) {
        foreach ($sql_queries as $sql_query_index => $sql_query) {
            $sql_statements[] = array(
                                    'statement' =>  $sql_query['sql']
                                ,   'params'    =>  $sql_query['params']
                                );
        }
		$return_value['sql'] = $sql_statements;
		callstack_push('end query #'.$query_key);
		$return_value['timing'] = $callstack;
	}
    return $return_value;
}

/**
*   handle_queries($queries)
*   Executes multiple MQL queries.
*   
*   arguments:
*   queries:    an associative array of mql query objects (decoded from JSON)
*
*   return:     an associative array of result envelopes
*/
function handle_queries($queries){
    $queries = get_object_vars($queries);
    $results = array(
        'code' =>  '/api/status/ok'
    );
    foreach ($queries as $query_key => $query){
        $result = handle_query($query, $query_key);
        $results[$query_key] = $result;
    }
    return $results;
}

/*****************************************************************************
*   Schema
******************************************************************************/
//$metadata_file_name is defined in config.php
$metadata = NULL;

function init_metadata(){
    global $metadata_file_name, $metadata;
    if (!file_exists($metadata_file_name)){
        exit('Cannot find schema file "'.$metadata_file_name.'".');
    }

    $metadata_file_contents = file_get_contents($metadata_file_name);

    if (!$metadata = json_decode($metadata_file_contents, TRUE)) {
        exit('schema is not valid json ('.get_last_json_error().').');
    }
}
/*****************************************************************************
*   Database (PDO)
******************************************************************************/
//$connection_file_name is defined in config.php
$pdo = NULL;
$explicit_type_conversion = NULL;

$sql_dialect = NULL;
function init_dialect($pdo_config){
    global $sql_dialect;
    $pdo_dsn = $pdo_config['dsn'];
    $db_type = substr($pdo_dsn, 0, strpos($pdo_dsn, ':'));
    include($db_type.'-dialect.php');
}

function init_pdo(){
    global $connection_file_name, $pdo, 
        $explicit_type_conversion, $sql_dialect
    ;
    if (!file_exists($connection_file_name)){
        exit('Cannot find connection file "'.$connection_file_name.'".');
    }

    $connection_file_contents = file_get_contents($connection_file_name);

    if (!$connection = json_decode($connection_file_contents, TRUE)) {
        exit('connection is not valid json ('.get_last_json_error().').');
    }

    $pdo_config = $connection['pdo'];

    if (!is_array($pdo_config)) {
        exit('schema does not specify a valid pdo configuration.');
    }
    init_dialect($pdo_config);

    $pdo = new PDO(
        $pdo_config['dsn']
    ,   $pdo_config['username']
    ,   $pdo_config['password']
    ,   $pdo_config['driver_options']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, FALSE);
    $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
    $explicit_type_conversion = $connection['explicit_type_conversion'];
}
/*****************************************************************************
*   misc
******************************************************************************/
$args = NULL;
function init_args() {
    global $args;
    switch ($_SERVER['REQUEST_METHOD']){
        case 'GET':
            $args = $_GET;
            break;
        case 'POST':
            $args = $_POST;
            break;
        default:
            exit('Must use either GET or POST');
    }
}
/*****************************************************************************
*   Queries
******************************************************************************/
$query_or_queries = NULL;
$query = NULL;
$queries = NULL;
$debug_info = NULL;
function init_queries(){
    global $args, 
        $query, 
        $queries,
        $query_decode,
        $debug_info,
        $noexecute
    ;
    if (isset($args['query'])) {
        $query = $args['query'];
    }
    if (isset($args['queries'])){
        $queries = $args['queries'];
    }

    //check if the query parameter is present
    if ((!isset($query) && !isset($queries))
    ||  ( isset($query) &&  isset($queries))) {
        exit('Either query or queries must be specified');
    }

    $query_or_queries = $query? $query : $queries;

    //immunize against magic quoting
    if (get_magic_quotes_gpc() === 1) {
        $query_or_queries = stripslashes($query_or_queries);
    }

    //check if the query parameter is valid JSON
    $query_decode = json_decode($query_or_queries);
    if ($query_decode===NULL) {
        exit('query or queries not valid JSON ('.get_last_json_error().')');
    }

    //testing if the envelope is an object (not some other random JSON value)
    if (!is_object($query_decode)) {
        exit('Envelope must be an object');
    }
    
    $debug_info = property_exists($query_decode,'debug_info')? $query_decode->debug_info : FALSE;
    $noexecute = property_exists($query_decode,'noexecute')? $query_decode->noexecute : FALSE;
}

/**
*
*/
function handle_request(){ 
    global $args, 
        $queries,
        $query_decode;

	$result = handle_query($query_decode);

    $json_result = json_encode($result);
	
    $content_type = 'application/json';
    $response = $json_result;

    header('Content-Type: '.$content_type);
    echo $response;
}
/*****************************************************************************
*   run
******************************************************************************/
function run() {
    init_metadata();
    init_pdo();
    init_args();
    init_queries();
    handle_request();
}

/*****************************************************************************
*   main
******************************************************************************/
run();