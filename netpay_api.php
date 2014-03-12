<?php

/**
 * Rest Connection class that allows to perform basic
 * operations and get their result
 */
class Connection {

    //Formats of data for content-type and accept headers supported by application
    private $supported_formats = array(
        'xml' => 'application/xml',
        'json' => 'application/json'
    );
    
    //Url to application API
    private $base_url;
    
    //Full url including method on API
    private $url;
    
    //Method set for current request, defaults to GET
    private $request_method = 1;
    
    //cURL connection to API
    private $curl_connection;
    
    //Last part of API url, goes after $base_url to create full $url
    private $api_method;
    
    //Variables that hold info on Accept and Content-Type header specified to application
    private $accept;
    private $content_type;
    
    //Username and password used for authentication with API
    private $username;
    private $password;
    
    //Arrays holding headers and options for cURL connection with API
    private $headers = array();
    private $options = array();
    
    //Unformatted text of last API server response
    private $last_response;
    private $last_request;
    
    private $response_code;
    
    //In case of error those will be set
    private $error_code;
    private $error_string;
            
    /**
     * Checks if cURL is available and sets basic
     * data for all calls for object
     * 
     * @param string $base_url URL for API
     * @param string $username Username for authorization with API
     * @param string $password Password for authorization with API
     */
    function __construct($base_url = '', $header = array())
    {
        if(!$this->curl_enabled())
        {
            echo "cURL is not enabled on server. This application needs cURL to function, please enable it.";
            exit();
        }
        
        $this->set_base_url($base_url);
        $this->set_username($header);
        $this->set_password($header);
        $this->set_accept($header);
        $this->set_content_type($header);
        
        
    }
    
    /**
     * Gets response code for request after request was made
     * 
     * @return string Response code for request
     */
    public function get_response_code()
    {
        return $this->response_code;
    }
    
    /**
     * Returns mime of Content-Type Http header that was automatically
     * determined based on parameters passed to constructor
     * 
     * @return string Accept Http header that was set for request
     */
    public function get_accept_type()
    {
        return $this->accept;
    }
    
    /**
     * Returns mime of Accept Http header that was automatically
     * determined based on parameters passed to constructor
     * 
     * @return string Content-Type Http header that was set for request
     */
    public function get_content_type()
    {
        return $this->content_type;
    }
    
    /**
     * Gets full url that was used for request
     * 
     * @return string full url for request
     */
    public function get_full_url()
    {
        return $this->url;
    }
    
    /**
     * Sets URL to API
     * 
     * @param string $base_url URL to API
     */
    public function set_base_url($base_url)
    {
        $this->base_url = $base_url;
    }
    
    /**
     * Sets username to username key from array
     * that constructor was initialized with
     * 
     * @param array $header init array of parameters from constructor
     */
    public function set_username($header) {
        if (isset($header['username'])) {
            $this->username = $header['username'];
        }else {
            $this->username = '';
        }
    }
    
    /**
     * Sets password to password key from array
     * that constructor was initialized with
     * 
     * @param array $header init array of parameters from constructor
     */
    public function set_password($header) {
        if (isset($header['password'])) {
            $this->password = $header['password'];
        }else {
            $this->password = '';
        }
    }
    
    /**
     * Sets Accept header to accept key from array
     * that constructor was initialized with
     * 
     * @param array $header init array of parameters from constructor
     */
    public function set_accept($header) {
        if(array_key_exists($header['accept'], $this->supported_formats))
        {
            //Set Accept header as mime value from array
            $this->accept = $this->supported_formats[$header['accept']];
        } else {
            echo 'Accept format not supported.';
            exit();
        }
    }
    
    /**
     * Sets Content-Type header to accept key from array
     * that constructor was initialized with
     * 
     * @param array $header init array of parameters from constructor
     */
    public function set_content_type($header) {
        if(array_key_exists($header['content_type'], $this->supported_formats))
        {
            //Set Accept header as mime value from array
            $this->content_type = $this->supported_formats[$header['content_type']];
        } else {
            echo 'Content Type not supported.';
            exit();
        }
    }
    
    /**
     * call function wrapper for GET request method
     * 
     * @param string $api_method Last part of API url, goes after $base_url to create full URL to API
     * @param string|array $params Parameters to be passed to API
     * @return object Decoded response from API or FALSE if it failed
     */
    public function get($api_method, $params)
    {
        return $this->call($api_method, 'GET', $params);
    }
    
    /**
     * call function wrapper for POST request method
     * 
     * @param string $api_method Last part of API url, goes after $base_url to create full URL to API
     * @param string|array $params Parameters to be passed to API
     * @return object Decoded response from API or FALSE if it failed
     */
    public function post($api_method, $params)
    {
        return $this->call($api_method, 'POST', $params);
    }
    
    /**
     * call function wrapper for PUT request method
     * 
     * @param string $api_method Last part of API url, goes after $base_url to create full URL to API
     * @param string|array $params Parameters to be passed to API
     * @return object Decoded response from API or FALSE if it failed
     */
    public function put($api_method, $params)
    {
        return $this->call($api_method, 'PUT', $params);
    }
    
    /**
     * call function wrapper for DELETE request method
     * 
     * @param string $api_method Last part of API url, goes after $base_url to create full URL to API
     * @param string|array $params Parameters to be passed to API
     * @return object Decoded response from API or FALSE if it failed
     */
    public function delete($api_method, $params)
    {
        return $this->call($api_method, 'DELETE', $params);
    }
    
    /**
     * Main function setting all data, parameters and options
     * 
     * @param string $api_method Last part of API url, goes after $base_url to create full URL to API
     * @param int $request_method Request method as defined in class static variables
     * @param string|array $params Parameters to be passed to API
     * @param string $accept Accept HTTP header, tells what kind of response formatting is expected
     * @param string $content Content-Type HTTP header, tells what kind of parameters formatting we are going to send
     * @return object Decoded response from API or FALSE if it failed
     */
    private function call($api_method, $request_method, $params)
    {
        $this->api_method = $api_method; 
        
        $this->curl_set_header();
        $this->curl_set_method($request_method);
        $this->curl_set_params($params);

        $this->curl_init_connection();
        
        $this->curl_no_error_fail();
        $this->curl_set_ssl(FALSE);
        $this->curl_apply_headers();
        $this->curl_apply_options();
        
        $response = $this->curl_execute();
        
        return $response;
    }

    /**
     * Displays debug info for created request
     * 
     * @param boolean $strip If should strip tags from response
     */
    public function debug($strip = FALSE) {
        $str = '';

        $str .= "-----------------------------------------------------<br/>\n";
        $str .= "<h2>Sample Application</h2>\n";
        $str .= "-----------------------------------------------------<br/>\n";
        $str .= "<h3>Request</h3>\n";
        $str .= $this->url . "<br/>\n";
        
        if ($this->last_request) {
            $str .= "<code>" . nl2br(htmlentities($this->last_request)) . "</code><br/>\n\n";
        }
        else 
        {
            $str .= "No response<br/>\n\n";
        }
        
        $str .= "-----------------------------------------------------<br/>\n";
        $str .= "<h3>Response</h3>\n";
        
        if ($this->response_code)
        {
            $str .= "<strong>Response Code:</strong> " . $this->response_code . "<br/>\n";
        }

        if ($this->last_response) {
            $str .= "<code>" . nl2br(htmlentities($this->last_response)) . "</code><br/>\n\n";
        }
        else 
        {
            $str .= "No response<br/>\n\n";
        }

        $str .= "-----------------------------------------------------<br/>\n";

        if ($this->error_string) {
            $str .= "<h3>Errors</h3>";
            $str .= "<strong>Code:</strong> " . $this->error_code . "<br/>\n";
            $str .= "<strong>Message:</strong> " . $this->error_string . "<br/>\n";
            $str .= "-----------------------------------------------------<br/>\n";
        }

        $str .= "<h3>Call details</h3>";

        if ($strip === FALSE) {
            $str .= "<pre>";

            echo $str;
            print_r($this->info);
            echo "</pre>";
        }
        else 
        {
            $str = strip_tags($str);

            echo nl2br($str);
            print_r($this->info);
        }
    }
    
    /**
     * Function tells application if cURL is available to use
     * on server to make requests to API
     * 
     * @return boolean whether cURL is enabled on server
     */
    public function curl_enabled()
    {
        return function_exists('curl_init');
    }
    
    /**
     * Adds basic headers set in constructor
     */
    private function curl_set_header()
    {
        $this->curl_add_header("Accept", $this->accept);
        $this->curl_add_header("Content-Type", $this->content_type);
        
        $credentials = $this->username . ':' . $this->password;
        $this->curl_add_header("Authorization", "Basic " . base64_encode($credentials));
    }
    
    /**
     * Initializes cURL object
     */
    private function curl_init_connection()
    {
        $this->curl_connection = curl_init($this->url);
    }
    
    /**
     * Function sending request to API and processing response
     * 
     * @param string $accept Accept HTTP header format type
     * @return string|boolean FALSE on request fail or formatted string on success
     */
    private function curl_execute()
    {
        $response = curl_exec($this->curl_connection);

        $this->info = curl_getinfo($this->curl_connection);
        
        $this->response_code = $this->info['http_code'];
        
        // Request failed
        if ($response === FALSE) {
            $this->error_code = curl_errno($this->curl_connection);
            $this->error_string = curl_error($this->curl_connection);

            curl_close($this->curl_connection);
                  
            return FALSE;
        }
        // Request successful
        else
        {
            curl_close($this->curl_connection);
            
            $this->last_response = $response;
            
            return $this->format_response($this->last_response);
        }
    }
    
    /**
     * Adds header to array of headers to be applied
     * later to current cURL request
     * 
     * @param string $name Header name
     * @param string $value Value of the header
     */
    private function curl_add_header($name, $value)
    {
        //Depending on if value is set use it or just add empty header
        $this->headers[] = $value ? $name . ': ' . $value : $name;
    }
    
    /**
     * Applies all headers set by curl_add_header function
     * current cURL request
     */
    private function curl_apply_headers()
    {
        curl_setopt($this->curl_connection, CURLOPT_HTTPHEADER, $this->headers);
    }
    
    /**
     * Adds option for cURL connection to be applied later
     * 
     * @param int $name Valid cURL option from http://uk1.php.net/manual/en/function.curl-setopt.php
     * @param mixed $value Value to be assigned to passed option
     */
    private function curl_add_option($name, $value)
    {
        $this->options[$name] = $value;
    }
    
    /**
     * Applies all options set by curl_add_option function
     * to current cURL request
     */
    private function curl_apply_options()
    {
        //Set default curl options if they were not set before
        if (!isset($this->options[CURLOPT_RETURNTRANSFER])) {
            $this->curl_add_option(CURLOPT_RETURNTRANSFER, TRUE);
        }
        if (!isset($this->options[CURLOPT_FAILONERROR])) {
            $this->curl_add_option(CURLOPT_FAILONERROR, FALSE);
        }
        
        foreach ($this->options as $option => $value)
        {
            curl_setopt($this->curl_connection, $option, $value);
        }
    }

    /**
     * Sets request method that cURL will use
     * to create HTTP request
     * 
     * @param int $method Request method as set in static variables in this class
     */
    private function curl_set_method($method = null)
    {
        $this->request_method = $method;
        $this->curl_add_option(CURLOPT_CUSTOMREQUEST, $method);
    }
    
    /**
     * Converts parameters to format specified
     * in $content, also sets full URL for cURL request
     * 
     * @param string|array $params Parameters to be sent with cURL request
     * @param string $accept Accept HTTP header format
     * @param string $content Content-Type HTTP header format
     */
    private function curl_set_params($params)
    {
        //If content type is xml
        if ($this->content_type === 'application/xml') {
            //Convert array to xml string
            $data = $this->format_to_xml($params);

            $this->last_request = $data;
            //Encode xml string to escape charcaters
            $params = urlencode($data);
        }
        //If content type is json
        else if ($this->content_type === 'application/json')
        {
            $params = json_encode($params);
            
            $this->last_request = $params;
        }
         
        //If request method will be GET add parameters to URL
        if($this->request_method === 'GET')
        {
            $this->url = $this->base_url . $this->api_method . '?' . $params;
        }
        else // POST, PUT, DELETE
        {
            $this->url = $this->base_url . $this->api_method;
            
            //Set params to be sent for other methods than GET
            $this->curl_add_option(CURLOPT_POSTFIELDS, $params);
        }
    }

    /**
     * Changes behaviour of ssl check for cURL
     */
    private function curl_set_ssl($verify_peer = TRUE, $verify_host = 2, $path_to_cert = NULL) {
        if ($verify_peer) {
            $this->curl_add_option(CURLOPT_SSL_VERIFYPEER, TRUE);
            $this->curl_add_option(CURLOPT_SSL_VERIFYHOST, $verify_host);
            $this->curl_add_option(CURLOPT_CAINFO, $path_to_cert);
        }
        else {
            $this->curl_add_option(CURLOPT_SSL_VERIFYPEER, FALSE);
        }
        return $this;
    }
    
    /**
     * Disables cURL fail on error codes over 400
     * since API returns those errors and still
     * provides useful info in response
     */
    private function curl_no_error_fail()
    {
        $this->curl_add_option(CURLOPT_FAILONERROR, FALSE);
    }
    
   
    /**
     * Creates XML string from passed data
     * 
     * @param array $data Array of data to be converted to XML
     * @param SimpleXMLElement $structure SimpleXMLElement to start XML from
     * @param string $basenode Name of main node of created XML
     * @return string XML string created from data
     */
    private function format_to_xml($data = null, $structure = null, $basenode = 'xml') 
    {
        //Turn off compatibility mode as simple xml throws a wobbly if you don't.
        if (ini_get('zend.ze1_compatibility_mode') == 1) {
            ini_set('zend.ze1_compatibility_mode', 0);
        }

        if ($structure === null) {
            $structure = simplexml_load_string("<?xml version='1.0' encoding='utf-8'?><$basenode />");
        }

        //Force it to be something useful
        if (!is_array($data) AND !is_object($data)) {
            $data = (array) $data;
        }

        foreach ($data as $key => $value) {

            //Change false/true to 0/1
            if (is_bool($value)) {
                $value = (int) $value;
            }

            //No numeric keys in our xml please!
            if (is_numeric($key)) {
                //Make string key
                $key = ($this->singular($basenode) != $basenode) ? $this->singular($basenode) : 'item';
            }

            //Replace anything not alpha numeric
            $key = preg_replace('/[^a-z_\-0-9]/i', '', $key);

            //If there is another array found recursively call this function
            if (is_array($value) || is_object($value)) {
                $node = $structure->addChild($key);

                //Recursive call.
                $this->format_to_xml($value, $node, $key);
            }
            else {
                //Add single node.
                $value = htmlspecialchars(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, "UTF-8");

                $structure->addChild($key, $value);
            }
        }

        return $structure->asXML();
    }

    /**
     * Returns singular form of string
     * 
     * @param string $str String to make singular
     * @return string Word in singular form
     */
    public function singular($str) {
        $result = strval($str);

        $singular_rules = array(
            '/(matr)ices$/' => '\1ix',
            '/(vert|ind)ices$/' => '\1ex',
            '/^(ox)en/' => '\1',
            '/(alias)es$/' => '\1',
            '/([octop|vir])i$/' => '\1us',
            '/(cris|ax|test)es$/' => '\1is',
            '/(shoe)s$/' => '\1',
            '/(o)es$/' => '\1',
            '/(bus|campus)es$/' => '\1',
            '/([m|l])ice$/' => '\1ouse',
            '/(x|ch|ss|sh)es$/' => '\1',
            '/(m)ovies$/' => '\1\2ovie',
            '/(s)eries$/' => '\1\2eries',
            '/([^aeiouy]|qu)ies$/' => '\1y',
            '/([lr])ves$/' => '\1f',
            '/(tive)s$/' => '\1',
            '/(hive)s$/' => '\1',
            '/([^f])ves$/' => '\1fe',
            '/(^analy)ses$/' => '\1sis',
            '/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/' => '\1\2sis',
            '/([ti])a$/' => '\1um',
            '/(p)eople$/' => '\1\2erson',
            '/(m)en$/' => '\1an',
            '/(s)tatuses$/' => '\1\2tatus',
            '/(c)hildren$/' => '\1\2hild',
            '/(n)ews$/' => '\1\2ews',
            '/([^u])s$/' => '\1',
        );

        foreach ($singular_rules as $rule => $replacement) {
            if (preg_match($rule, $result)) {
                $result = preg_replace($rule, $replacement, $result);
                break;
            }
        }

        return $result;
    }
    
    /**
     * Decodes XML string into array
     * 
     * @param string $string XML string to change into array
     * @return array Array decoded from passed XML
     */
    private function format_from_xml($string)
    {
        $obj = simplexml_load_string($string, 'SimpleXMLElement', LIBXML_NOCDATA);
        return @json_decode(@json_encode($obj), 1);
    }

    /**
     * Decodes JSON string into array
     * 
     * @param string $string JSON string to change into array
     * @return array Array decoded from passed JSON
     */
    private function format_from_json($string)
    {
        return json_decode(trim($string));
    }
    
    /**
     * Formats response according to
     * which accept header was set
     * 
     * @param string $response Formatted string to be decoded
     * @param string $accept Accept format to decode from
     * @return array Array decoded based on accept headers
     */
    private function format_response($response)
    {
        if($this->accept === "application/xml")
        {
            return $this->format_from_xml($response);
        }
        elseif($this->accept === "application/json")
        {
            return $this->format_from_json($response);
        }
        else
        {
            echo "Unrecognized Accept header.";
            exit();
        }
    }
    
    /**
     * Returns pretty printed string of last response
     * for easier readability while debugging
     * 
     * @return string|boolean Pretty printed string of last response, FALSE on failure
     */
    public function format_get_last_response()
    {
        try
        {
            if($this->accept === 'application/json')
            {
                if(version_compare(phpversion(), '5.4', '>='))
                {
                    return json_encode(json_decode($this->last_response), JSON_PRETTY_PRINT);
                } 
                else 
                {
                    return $this->last_response;
                }
            }
            elseif($this->accept === 'application/xml')
            {
                $req = new DOMDocument;
                $req->preserveWhiteSpace = TRUE;
                $req->formatOutput = TRUE;
                $req->loadXML($this->last_response);
                return $req->saveXml();
            }
        } catch (Exception $exc) { }
        return FALSE;
    }
    
    /**
     * Returns pretty printed string of last request
     * for easier readability while debugging
     * 
     * @return string|boolean Pretty printed string of last request, FALSE on failure
     */
    public function format_get_last_request()
    {
        try
        {
            if($this->content_type === 'application/json')
            {
                if(version_compare(phpversion(), '5.4', '>='))
                {
                    return json_encode(json_decode($this->last_request), JSON_PRETTY_PRINT);
                } 
                else 
                {
                    return $this->last_request;
                }
            }
            elseif($this->content_type === 'application/xml')
            {
                $req = new DOMDocument;
                $req->preserveWhiteSpace = TRUE;
                $req->formatOutput = TRUE;
                $req->loadXML($this->last_request);
                return $req->saveXml();
            }
        } catch (Exception $exc) { }
        return FALSE;
    }
    
}