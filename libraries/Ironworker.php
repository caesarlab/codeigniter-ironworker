<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Ironworker
 * 
 *
 * @author Sherief Mursjidi <sherief@caesarlab.com>
 */

class Ironworker {
	
	/* Set in the config file */
	protected $project_id,
		$token,
		$_api_url,
		$_api_version,
		$_project_url,
		$_code_url,
		$_task_url,
		$_schedule_url,
		$_arrRequests,
		$_arrResponses,
		$_mch;
	
	/* Are we debugging */
	protected $_debug = false;
	
	/* List of errors */
    protected $_arrErrors = array();
	
	/* Where errors live */
    protected $_error_message = null;
	
	/* Properties for the cURL - used when decoding the response */
    protected $_arrProperties = array(
        'code' => CURLINFO_HTTP_CODE,
        'time' => CURLINFO_TOTAL_TIME,
        'length' => CURLINFO_CONTENT_LENGTH_DOWNLOAD,
        'type' => CURLINFO_CONTENT_TYPE,
    );
	
	public function __construct() {
        /* Load the config */
        $this->load->config('ironworker');
        
        $this->load->helper('url');
        
        $arrConfig = $this->config->item('ironworker');
        
        if(is_array($arrConfig) && count($arrConfig) > 0) {
            foreach($arrConfig as $key => $value) {
                $this->$key = $value;
            }
        }
    }
	
	/**
     * Destruct
     * 
     * Shows errors 
     */
	public function __destruct() {
        if($this->_debug && count($this->_arrErrors) > 0) {
            echo '<pre>'.print_r($this->_arrErrors, true).'</pre>';exit;
        }
    }
	
	/**
     * __get
     *
     * Allows models to access CI's loaded classes using the same
     * syntax as controllers.
     *
     * @param string $key
     * @access private
     */
    public function __get($key) {
        $CI =& get_instance();
        return $CI->$key;
    }
	
	/**
     * Add cURL
     * 
     * Add cURL stuff
     * 
     * @param string $url
     * @param array $arrParams
     * @return mixed
     */
	protected function _add_curl($url, $arrParams = array()) {
        if(!empty($arrParams['oauth'])) {
            $this->_add_oauth_headers($this->_curl, $url, $arrParams['oauth']);
        }

        $ch = $this->_curl;

        $key = (string) $ch;
        $this->_arrRequests[$key] = $ch;
        
        if(is_null($this->_mch)) {
            $this->_mch = curl_multi_init();
        }

        $response = curl_multi_add_handle($this->_mch, $ch);

        if($response === CURLM_OK || $response === CURLM_CALL_MULTI_PERFORM) {
            do {
                $mch = curl_multi_exec($this->_mch, $active);
            } while($mch === CURLM_CALL_MULTI_PERFORM);

            return $this->_get_response($key);
        } else {
            return $response;
        }
    }
	
	/**
     * Add OAuth Headers
     * 
     * Add the headers to the OAuth connection
     * 
     * @param resource $ch
     * @param string $url
     * @param array $arrHeaders
     */
	protected function _add_oauth_headers(&$ch, $url, $arrHeaders) {
        $_h = array('Expect:');
        $arrUrl = parse_url($url);
        $oauth = 'Authorization: OAuth realm="'.$arrUrl['path'].'",';
		
        if(count($arrHeaders) > 0) {
            foreach($arrHeaders as $name => $value ) {
                $oauth .= "{$name}=\"{$value}\",";
            }
        }
        
        $_h[] = substr($oauth, 0, -1);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $_h);
    }
	
	/**
     * Get
     * 
     * Performs a GET request
     * 
     * @param string $url
     * @param array $arrParams
     * @return array
     */
	protected function _get($url, $arrParams) {
		
		if(isset($arrParams['oauth']) && count($arrParams['oauth']) > 0 ) {
            $url .= '?';
            foreach($arrParams['oauth'] as $k => $v ) {
                $url .= "{$k}={$v}&";
            }

            $url = substr($url, 0, -1);
        }
		
        if(isset($arrParams['request']) && count($arrParams['request']) > 0 ) {
            $url .= '&';
            foreach($arrParams['request'] as $k => $v ) {
                $url .= "{$k}={$v}&";
            }

            $url = substr($url, 0, -1);
        }
        
		
        $this->_init_connection($url);
        $response = $this->_add_curl($url, $arrParams);
        
        return $response;
    }
	
	/**
     * Get Response
     * 
     * Gets the response from the API
     * 
     * @param string $key
     * @return array
     */
	protected function _get_response($key = null) {
        if(is_null($key)) { return false; }
        
        if(is_array($this->_arrResponses) && array_key_exists($key, $this->_arrResponses)) {
            return $this->_arrResponses[$key];
        } else {
            $running = null;
            
            do {
                $response = curl_multi_exec($this->_mch, $running_curl);
                
                if(is_null($running) === false && $running_curl != $running) {
                    $this->_set_response($key);
                    
                    if(is_array($this->_arrResponses) && array_key_exists($key, $this->_arrResponses)) {
                        
                        /* Convert to an object - reduces errors if key not present */
                        $objData = (object) $this->_arrResponses[$key];
                        
                        /* Decode the response */
                        $arrResponse = $this->_decode_response($objData);
                        
                        /* If not 200, throw an error */
                        if($objData->code != 200) {
                            $message = '';
                            /* How do we do errors */
                            if($this->_debug) {
                                /* Output errors */
                                if(array_key_exists('error', $arrResponse)) {
                                    $message = ' - ';
                                    $message .= $arrResponse['error'];
                                } else {
                                    $message = $objData->data;
                                }
                                throw new Ironworker_Exception($objData->code.' | Request failed'.$message);
                            }
                        }
                        
                        $arrReturn = array(
                            'data' => $arrResponse, /* The data lives here */
                            '_raw' => $objData, /* Used for debugging purposes */
                        );
                        
                        return $arrReturn;
                        
                    }
                }
                
                $running = $running_curl;
            } while($running_curl > 0);
        }
    }
	
	/**
     * Get Error
     * 
     * Checks the return array from Ironworker for an
     * error
     * 
     * @param array $arrResult
     * @return string/false
     */
	protected function _get_error($arrResult) {
        
        /* Default to no error */
        $error = false;
        if(is_array($arrResult) && array_key_exists('error', $arrResult)) {
            /* Has error - return the message */
            $error = $arrResult['error'];
            
            /* Save the error message */
            $this->_error_message = $error;
        }
        
        return $error;
        
    }
	
	/**
     * HTTP Request
     * 
     * Performs an HTTP request
     * 
     * @param string $method
     * @param string $url
     * @param array $arrParams
     * @return resource/null
     */
	protected function _http_request($method = null, $url = null, $arrParams = null) {
        if(empty($method) || empty($url)) { return null; }
        
        /* Add OAuth signature - not for public calls */
        $arrParams = $this->_prepare_parameters($method, $url, $arrParams);
        
		$arrReturn = null;
		
        if(is_null($arrReturn)) {
        
            switch($method) {
                case 'GET':
                    $arrReturn = $this->_get($url, $arrParams);
                    break;

                case 'POST':
                    $arrReturn = $this->_post($url, $arrParams);
                    break;

                case 'PUT':
                    $arrReturn = null;
                    break;

                case 'DELETE':
                    $arrReturn = $this->_delete($url, $arrParams);
                    break;
            }
        
        }
        
        return $arrReturn;
    }
	
	/**
     * Init Connection
     * 
     * Initialize the cURL connection
     * 
     * @param string $url 
     */
	protected function _init_connection($url) {
        $this->_curl = curl_init($url);
        curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, true);
    }
	
	/**
     * Normalize URL
     * 
     * Make sure the URL is in the correct format
     * 
     * @param string $url
     * @return string
     */
	protected function _normalize_url($url = NULL) {
        $arrUrl = parse_url($url);

        if(!isset($arrUrl['port'])) { $arrUrl['port'] = 80; }

        $scheme = strtolower($arrUrl['scheme']);
        $host = strtolower($arrUrl['host']);
        $port = intval($arrUrl['port']);

        $retval = "{$scheme}://{$host}";

        if ( $port > 0 && ( $scheme === 'http' && $port !== 80 ) || ( $scheme === 'https' && $port !== 443 ) ) {
            $retval .= ":{$port}";
        }

        $retval .= $arrUrl['path'];

        if(!empty($arrUrl['query'])) {
            $retval .= "?{$arrUrl['query']}";
        }

        return $retval;
    }
	
	/**
     * Post
     * 
     * Performs a POST request
     * 
     * @param string $url
     * @param array $arrParams
     * @return array
     */
	protected function _post($url, $arrParams) {
		$post = '';
        if(is_array($arrParams['request']) && count($arrParams['request']) > 0 ) {
            foreach($arrParams['request'] as $k => $v) {
                $post .= "{$k}={$v}&";
            }
            
            $post = substr($post, 0, -1);
            
        }
        
        $this->_init_connection($url);
        curl_setopt($this->_curl, CURLOPT_POST, 1);
        curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $post);
        
        $response = $this->_add_curl($url, $arrParams);
        
        return $response;
    }
	
	/**
     * Delete
     * 
     * Performs a DELETE request
     * 
     * @param string $url
     * @param array $arrParams
     * @return array
     */
	protected function _delete($url, $arrParams) {
		$post = '';
        if(is_array($arrParams['request']) && count($arrParams['request']) > 0 ) {
            foreach($arrParams['request'] as $k => $v) {
                $post .= "{$k}={$v}&";
            }
            
            $post = substr($post, 0, -1);
            
        }
        
        $this->_init_connection($url);
        curl_setopt($this->_curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $post);
        
        $response = $this->_add_curl($url, $arrParams);
        
        return $response;
    }
	
	/**
     * Prepare Parameters
     * 
     * Prepares the parameters for the uery
     * 
     * @param string $method
     * @param string $url
     * @param array $arrParams
     * @return array
     */
	protected function _prepare_parameters($method = NULL, $url = NULL, $arrParams = NULL) {
        if(empty($method) || empty($url)) { return FALSE; }
        
        $public = false;
        $arrOauth = array();
        
        if($public === false) {
            /* Set the main OAuth info */
            $arrOauth = array(
                'oauth' => $this->token
            );
        }
        
        $arrReturn = array(
            'request' => $arrParams,
            'oauth' => $arrOauth,
        );
        
        return $arrReturn;
    }
	
	/**
     * Set Response
     * 
     * Set the responses from the server
     * 
     * @param string $key 
     */
	protected function _set_response($key) {
        while($done = curl_multi_info_read($this->_mch)) {
            $key = (string) $done['handle'];
            
            $this->_arrResponses[$key]['data'] = curl_multi_getcontent($done['handle']);
            
            foreach($this->_arrProperties as $curl_key => $value) {
                $this->_arrResponses[$key][$curl_key] = curl_getinfo($done['handle'], $value);
                
                curl_multi_remove_handle($this->_mch, $done['handle']);
            }
        }
    }
	
	/**
     * Decode Response
     * 
     * Decode the response from the server
     * 
     * @param object $objData 
     * @return array
     */
	protected function _decode_response($objData) {
        /* Decode the data based on type */
        $arrResponse = array();
        if(preg_match('/(json)/', $objData->type)) {
            /* Decode a JSON string */
            $arrResponse = _object_to_array(json_decode($objData->data));
        } elseif(preg_match('/(xml)/', $objData->type)) {
            /* Decode an XML string */
            $objXML = new SimpleXMLElement($objData->data, null, false);
            $arrResponse = $this->_build_xml_array($objXML);
            
            /* Make the last element the array */
            $arrResponse = $arrResponse[end(array_keys($arrResponse))];
        } else {
            /* Text string */
            if(isset($objData->data)) {
                parse_str($objData->data, $arrResponse);
            }
        }
        return $arrResponse;
    }
	
	/**
     * Call
     * 
     * Call the API and return the data
     * 
     * '_cache' => false in the $arrArgs will not allow
     * the call to be cached
     * 
     * @param string $method
     * @param string $path
     * @param array $arrArgs
     * @param bool $public
     * @param bool $debug
     * @return array
     */
	public function call($method, $path, $arrArgs = null, $debug = false) {
        $arrResponse = $this->_http_request(strtoupper($method), $this->_api_url.'/'.$this->_api_version.'/'.$path, $arrArgs);
        
        if(is_null($arrResponse)) {
            return array();
        } elseif($debug === true || array_key_exists('data', $arrResponse) === false) {
            echo '<pre>'.print_r($arrResponse['_raw'], true).'</pre>';exit;
        } else {
            return $arrResponse['data'];
        }
    }
	
	/**
     * Enable Debugging
     */
	public function debug() { $this->_debug = true; }
	
	/**
     * Get Error
     * 
     * Gets the errors - used for API error messages
     * that can be returned to users
     * 
     * @return string
     */
	public function get_error() {
        $error = $this->_error_message;
        $this->_error_message = null;
        
        return $error;
    }
	
	/**
     * Set Project ID
     * 
     * Sets projectid
     * 
     * @param string $projectid
     */
	public function set_projectid($projectid) {
        if (!empty($projectid)){
          $this->project_id = $projectid;
        }
        if (empty($this->projectid)){
            throw new InvalidArgumentException("Please set project_id");
        }
    }
	
	/**
     * Get Projects
     * 
     * Get list of projects
     * 
     * @return array
     */
	public function get_projects() {
		$url = 'projects';		
        $result = $this->call('GET',$url);
		return $result;
    }
	
	/**
     * Get Projects Details
     * 
     * Get details of selected project
     * 
     * @return array
     */
	public function get_projectdetails(){
		$url = 'projects/'.$this->project_id;
        $details = $this->call('GET',$url);
		return $details;
    }
	
	
	/**
     * Get Codes
     * 
     * Get list of code packages
     * 
     * @return array
     */
	public function get_codes($page = 0, $per_page = 30){
        $url = 'projects/'.$this->project_id.'/codes';
        $params = array(
            'page'     => $page,
            'per_page' => $per_page
        );
        $codes = $this->call('GET',$url,$params);
        return $codes;
    }
	
	/**
     * Get Code Package Details
     * 
     * Get details of selected code package
     * 
	 * @param string $code_id
     * @return array
     */
	public function get_codedetails($code_id){
        if (empty($code_id)){
            throw new InvalidArgumentException("Please set code_id");
        }
        $url = 'projects/'.$this->project_id.'/codes/'.$code_id;
        $details = $this->call('GET',$url);
		return $details;
    }
	
	/**
     * Delete Code Package
     * 
     * Get details of selected code package
     * 
	 * @param string $code_id
     * @return array
     */
	public function delete_code($code_id){
        $url = "projects/{$this->project_id}/codes/$code_id";
        return $this->apiCall(self::DELETE, $url);
    }
	
	/**
     * List Tasks
     *
     * @param integer $page Page. Default is 0, maximum is 100.
     * @param integer $per_page The number of tasks to return per page. Default is 30, maximum is 100.
     * @param array $options Optional URL Parameters
     * Filter by Status: the parameters queued, running, complete, error, cancelled, killed, and timeout will all filter by their respective status when given a value of 1. These parameters can be mixed and matched to return tasks that fall into any of the status filters. If no filters are provided, tasks will be displayed across all statuses.
     * - "from_time" Limit the retrieved tasks to only those that were created after the time specified in the value. Time should be formatted as the number of seconds since the Unix epoch.
     * - "to_time" Limit the retrieved tasks to only those that were created before the time specified in the value. Time should be formatted as the number of seconds since the Unix epoch.
     * @return mixed
     */
    public function get_tasks($page = 0, $per_page = 30, $options = array()){
    	$url = 'projects/'.$this->project_id.'/tasks';
        $params = array(
            'page'     => $page,
            'per_page' => $per_page
        );
        $params = array_merge($options, $params);
        $tasks = $this->call('GET', $url, $params);
        return $tasks;
    }
	
	/**
     * Get Tasks Details
     * 
     * Get details of selected task
     * 
	 * @param string $task_id
     * @return array
     */
	public function get_taskdetails($task_id){
        if (empty($task_id)){
            throw new InvalidArgumentException("Please set task_id");
        }
		$url = 'projects/'.$this->project_id.'/tasks/'.$task_id;
        $details = $this->call('GET', $url);
		return $details;
    }
	
	/**
     * Get Scheduled Tasks
	 * 
	 * Get information about all schedules for project
     *
     * @param integer $page
     * @param integer $per_page
     * @return mixed
     */
    public function get_scheduledtasks($page = 0, $per_page = 30){
        $url = 'projects/'.$this->project_id.'/schedules';
        $params = array(
            'page'     => $page,
            'per_page' => $per_page
        );
        $schedules = $result = $this->call('GET', $url, $params);
        return $schedules;
    }
	
	/**
     * Get Schedule Task Details
	 * 
	 * Get information about schedule
     *
     * @param string $schedule_id Schedule ID
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function get_scheduletaskdetails($schedule_id){
        if (empty($schedule_id)){
            throw new InvalidArgumentException("Please set schedule_id");
        }
        $url = 'projects/'.$this->project_id.'/schedules/'.$schedule_id;

        $details = $result = $this->call('GET', $url);
		return $details;
    }
	
}

class Ironworker_Exception extends Exception {
    
    public function __toString() {
        echo '<pre>'.print_r($this->getMessage(), true).'</pre>';exit;
    }

}

/**
 * Object To Array
 * 
 * Convert an object to an array
 * 
 * @param mixed $object
 * @return array 
 */
if(!function_exists('_object_to_array')) {
    function _object_to_array($object) {
        if(!is_object($object) && !is_array($object)) {
            return $object;
        }
        if(is_object($object)) {
            $object = get_object_vars($object);
        }
        return array_map( '_object_to_array', $object );
    }
}

/**
 * Array Keys Exist
 *
 * Does the array_key_exist function for many
 * keys
 *
 * @param array $arrKey
 * @param array $arrArray
 * @return bool
 */
if(!function_exists('array_keys_exist')) {
    function array_keys_exist($arrKey, $arrArray) {
        if(count($arrKey) > 0) {
            foreach($arrKey as $key) {
                if(!array_key_exists($key, $arrArray)) {
                    return false;
                }
            }
        }
        return true;
    }
}

?>