<?
Class SessionCache {
	public static function get() {
		$filename = dirname(realpath ( __FILE__ ))."/ascio-session.txt";
		$fp = fopen($filename,"r");
		$contents = fread($fp, filesize($filename));
		fclose($fp);
		if(trim($contents) == "false") $contents = false;
		return $contents;
	}
	public static function put($sessionId) {
		$filename = dirname(realpath ( __FILE__ ))."/ascio-session.txt";
		$fp = fopen($filename,"w");		
		fwrite($fp,$sessionId);
		fclose($fp);
	}
	public static function clear() {
		SessionCache::put("false");
	}
}
Class Ascio extends DomainModule {
	protected $version = '1.0';
	protected $description = 'Ascio domain registrar module';
	protected $lang = array( 'english' => array( 
		'Asciologin' 	=> 'Username', 
		'Asciopassword' => 'Password', 
		'Asciocontext' 	=> 'Context', 
		'Ascioemail' 	=> 'Email (to reply)', 
		'Asciolanguage' => 'Language', 
		'Asciotestmode' => 'Test Mode' ) );
	protected $commands = array( 0 => 'Register', 1 => 'Transfer', 2 => 'Renew', 3 => 'ContactInfo', 4 => 'DNSmanagement', 5 => 'EmailForwarding', 6 => 'RegisterNameServers', 7 => 'EppCode' );
	protected $clientCommands = array( 0 => 'ContactInfo', 1 => 'EmailForwarding', 2 => 'DNSmanagement', 3 => 'RegisterNameServers', 4 => 'EppCode' );
	protected $configuration = array( 'username' => array( 'value' => '', 'type' => 'input', 'default' => false ), 'password' => array( 'value' => '', 'type' => 'password', 'default' => false ), 'testmode' => array( 'value' => '0', 'type' => 'check', 'default' => '0' ) );
	protected $lastError = false;
	
	function login() {
		//echo(nl2br(print_r($this->configuration,1)));
		syslog(LOG_INFO, "login, username: ".$this->configuration['login']['value']. " p: ", $this->configuration['password']['value']  );
		$session = array(
		             'Account'=> $this->configuration['username']['value'],
		             'Password' =>  $this->configuration['password']['value']
		);
		return $this->sendRequest('LogIn',array('session' => $session ));
		 
	}
	function request($functionName, $ascioParams, $outputResult=false)  {	
		$sessionId = SessionCache::get();	
		if (!$sessionId) {		
			$loginResult = $this->login(); 
			if($loginResult->ResultCode == 401) return $loginResult;
			$ascioParams["sessionId"] = $loginResult->sessionId; 
			
			SessionCache::put($loginResult->sessionId);
		} else {		
			$ascioParams["sessionId"] = $sessionId; 
		}
		$requestResult = $this->sendRequest($functionName,$ascioParams);
		if($requestResult->ResultCode == 401) {
			syslog(LOG_INFO,"new Login");
			SessionCache::clear();
			return $this->request($functionName, $ascioParams, $outputResult);		
		} else {
			syslog(LOG_INFO,"no Login:".$requestResult->ResultCode);	
			return $requestResult;
			
		}	
		return;
	}
	function sendRequest($functionName,$ascioParams,$try) {
			//echo(nl2br(print_r($ascioParams,1)));
			syslog(LOG_INFO, "Do ".$functionName  );
			syslog(LOG_INFO,  $this->cleanAscioParams($ascioParams));
			$ascioParams = $this->cleanAscioParams($ascioParams);
			$wsdl = $this->configuration["testmode"]["value"] == 1 ? "https://awstest.ascio.com/2012/01/01/AscioService.wsdl" : "https://aws.ascio.com/2012/01/01/AscioService.wsdl";
	        $client = new SoapClient($wsdl,array( "trace" => 1 ));
	        $result = $client->__call($functionName, array('parameters' => $ascioParams));        
			$resultName = $functionName . "Result";	
			$status = $result->$resultName;
			if ( $status->ResultCode==200) {
				return $result;
			} else if($result->CreateOrderResult->ResultCode==554)  {
				$error =  $result." transaction failed: 554. Ascio test environemnt overloaded. Please contact partnerservice";
				syslog(LOG_ERROR,$error);
				$this->addError($error);
				return;
			} else if (count($status->Values->string) > 1 ){
				$messages = join("<br/>\n",$status->Values->string);	
			} else {
				$messages = $status->Values->string;
			}				
			$this->addError($status->Message . "<br/>\n" .$messages);
			return $status;
			     
	}
	function Register() {
		if (!$this->checkAvailability()) {
			$this->addError( 'Domain not available' );
			return false;
		}
		$ascioParams = $this->mapToOrder("Register_Domain");
		$result = $this->request("CreateOrder",$ascioParams);
		if (!$this->lastError) $this->addDomain( 'Pending Registration' ); 
		return $result; 
	}
	function Renew() {
		$ascioParams = $this->mapToOrder("Renew_Domain");
		$result = $this->request("CreateOrder",$ascioParams);
		if (!$this->lastError) $this->addDomain( 'Pending Renew' );			
		return $result; 
	}
	function Transfer() {
		$ascioParams = $this->mapToOrder("Transfer_Domain");
		$result = $this->request("CreateOrder",$ascioParams);
		if (!$this->lastError) $this->addDomain( 'Pending Transfer' );			
		return $result; 
	}
	function GetEppCode($params) {
		echo "get epp code";
		$params = $this->setParams($params);
	    $ascioParams = $this->mapToOrder($params,"Update_AuthInfo");
	    // todo: set AuthInfo before order;	
		$result = $this->request("CreateOrder",$ascioParams,true);
		if(is_array($result)) {
			return $result;
		} else {
			return array("eppcode" => $ascioParams->Order->Domain->AuthInfo);
		}
	}	

	function synchInfo() {
			$domain = $this->searchDomain();
			$return = array();
			$return['expires'] = date( 'Y-m-d', strtotime( $domain->ExpDate ));
			$return['status'] = $domain->Status;
			return $return; 
	}
	function searchDomain() {
		$criteria= array(
			'Mode' => 'Strict',
			'Clauses' => Array(
				'Clause' => Array(
					'Attribute' => 'DomainName', 
					'Value' => $this->name , '
					Operator' => 'Is'
				)
			)
		);
		$ascioParams = array(
			'sessionId' => 'mySessionId',
			'criteria' => $criteria
		);
		$result = $this->request("SearchDomain",$ascioParams,true);
		return $result->domains->Domain;
	}

	function checkAvailability($quality="fast") {
        return true; 
        $params =
        array(
	        'sessionId'    			=> "set-it-later",
	        'domains'               => array("string" => $domains),
	        'tlds'                  => array("string" => $tlds),
	        'quality'               => $quality );
    	$result = $this->sendRequest('AvailabilityCheck',$params);
    	return $results->results->AvailabilityCheckResult->StatusCode == 200;
	}
	function testConnection() {
			$loginResult = $this->login();
			if($loginResult->sessionId)  return true;
		}	
	function mapToOrder($orderType) {
		$order = 
			array( 
			'Type' => $orderType, 
			'Domain' => array( 
				'DomainName' 	=> $this->name,
				'RegPeriod' 	=>  $this->period,
				'AuthInfo'		=> 	$this->options['epp_code'],
				'Registrant' 	=> $this->mapToContact("registrant"),
				'AdminContact' 	=> $this->mapToContact("admin"), 
				'TechContact' 	=> $this->mapToContact("tech"), 
				'BillingContact'=> $this->mapToContact("billing"),
				'NameServers' 	=> $this->mapToNameservers(),
				'Comment'		=> "Managed by Hostbill"
				),
			'Comments'	=>	"Hostbill Order"
		); 

		return array(
				'sessionId' => "set-it-later",
				'order' => $order
	    );
	}
	// map contact from Ascio to Hostbill
	function mapToContact($type,$params) {  
		if(!$params) $params = $this->domain_contacts[$type];
		

		$contactName = array();
		if($type == "registrant") {
			$contactName["Name"] = $params["firstname"] . " " . $params["lastname"];
		} else {
			$prefix = strtolower($type);
			$contactName["FirstName"] = $params["firstname"];
			$contactName["LastName"] = $params["lastname"];
		}
		$country =  $params[$prefix . "country"];
		$contact = Array(
			'OrgName' 		=>  $params["companyname"],
			'Address1' 		=>  $params["address1"],	
			'Address2' 		=>  $params["address2"],
			'PostalCode' 	=>  $params["postcode"],
			'City' 			=>  $params["city"],
			'State' 		=>  $params["state"],		
			'CountryCode' 	=>  $params["country"],
			'Email' 		=>  $params["email"],
			'Phone'			=>  $this->isoPhone($params["phonenumber"],$params["country"]),
			'Fax' 			=> 	$this->isoPhone($params["phonenumber"],$params["country"])
		);
		foreach($this->domain_config as $key =>  $value) {
			$tokens = split("\.",$key);
			$prefix = $tokens[0];
			if(strtolower($prefix)==$type) {
				$contact[$tokens[1]] = $value["value"] | $value["variable_id"];
			}
		}
		return array_merge($contactName,$contact);
	}
	function mapToNameservers() {
		$out = Array();
		for ($i=1; $i < 9; $i++) {
			if($this->options['ns'.$i]) $out['NameServer'.$i] = array('HostName' => $this->options['ns'.$i]);
		}
		return $out;
	}
	function cleanAscioParams($ascioParams) {
		foreach ($ascioParams as $key => $value) {
			if(is_array($value)) {
				$ascioParams[$key] = $this->cleanAscioParams($value);			
			} elseif (strlen($value) > 0) {
				$ascioParams[$key] =$value;	
			}
		}
		return $ascioParams;
	}	
	function isoPhone ($phonenumber,$country) {
		$num = Utilities::get_phone_info($phonenumber,$country);
		return "+".$num["ccode"] . "." . $num["number"];
	}
}


?>