<?php
/**
 * wow_ah.class.php is a low level API for accessing the World of Warcraft Auction House via the Armory website
 *
 * Contains the main API class (wow_ah\wow_ah) and necessary helper classes, objects, and functions
 *
 * Copyright (C) 2016 Aram Akhavan <kaysond@hotmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package wow_ah
 * @author  Aram Akhavan <kaysond@hotmail.com>
 * @link    https://github.com/kaysond/wow_ah
 * @copyright 2016 Aram Akhavan
 */
namespace wow_ah;

/** Whether the API should log its transactions */
define("wow_ah\LOG_API", false);
/** Whether the API should log all HTTP requests and responses */
define("wow_ah\LOG_HTTP", false);
/** 
 * How many times the battle.net server can redirect the API to the login page before
 * the API considers the user logged out
 */
define("wow_ah\LOGIN_REDIRECT_LIMIT", 4);
/** How many seconds to wait at minimum before flushing the log buffer to the file. */
define("wow_ah\LOG_API_INTERVAL", 30);
/** How many transactions are allowed before certain functions are disabled */
define("wow_ah\TRANSACTION_LIMIT", 200);
/** Location of the file containing additional JavaScript for the login procedure */
define("wow_ah\SRP_CUSTOM_JS_FILE", "wow_ah_srp.js");

require_once("murmurhash3.php");
require_once("object_from_array.class.php");

/**
 * The main API class
 */
class wow_ah {

	/** @var resource cURL resource */
	private $ch;
	/** @var string Location of the cURL cookie jar/file */
	private $cookiefile;
	/** @var string The battle.net region specified by the user in the constructor */
	private $region;
	/** @var string The battle.net language specified by the user in the constructor */
	private $lang;
	/**
	 * @var object A browser_info object the API uses to generate HTTP requests
 	 * @see browser_info
	 */
	private $browser_info;
	/** 
	 * @var string JSON-encoded browser fingerprint
	 * @see wow_ah::generate_fp()
	 */
	private $fp;
	/** @var array An indexed array listing all of the accounts character's, where the 0th index is the selected character for API transactions */
	private $character_list = array();
	/** @var array An associative array containing the character information occasionally sent by the server */
	private $character = array();
	/** @var integer The amount of money the selected character has*/
	private $money = 0;
	/** @var array The contents of the character's mail */
	private $mail = array();
	/** @var array The "mail_info" sent by the servers, containing the number of messages, and the maximum number allowed */
	private $mail_info = array();
	/** 
	 * @var array The contents of the selected character's inventory (bags, mail, and bank)
	 * @see inventory_item
	 */
	private $inventory = array();
	/** 
	 * @var object All of the auctions for the selected character.
	 * @see auctions
	 * @see active_auction
	 * @see ended_auction
	 * @see sold_auction
     */
	private $auctions;
	/** @var integer The number of transactions executed by the API */
	private $transaction_count = 0;
	/** @var string Some sort of cross-site token for battle.net, sent with some requests */
	private $xstoken;
	/** @var boolean Whether the API thinks the character is logged in to battle.net */
	private $logged_in = false;
	/** @var boolean Whether the API has detected that the account is logged into the game client */
	private $char_in_game = false;
	/** @var int How many times an HTTP request has been 302 redirected to the login page (in a row) */
	private $login_redirects = 0;
	/** 
	 * @var object The last HTTP transaction made by the API, for error handling.
	 * @see http_transaction
	 */
	private $last_http;
	/** @var string The location of the log file */
	private $logfile;
	/** @var array A buffer for log entries so the disk access is not excessive */
	private $log = array();
	/** @var integer Timestamp of the last log flush to file */
	private $last_log_time = 0;

	/**
	 * Constructs the API
	 *
	 * The constructor sets the Battle.net region, the language, the location
	 * of the curl cookie file, the log file, and information about the browser
	 * that the API will present
	 * 
	 * @param string $region       Battle.net region to use as the subdomain (e.g. "us") 
	 * @param string $lang         Battle.net language (e.g. "en", used in the url as /wow/en/)
	 * @param string $cookiefile   Location of the file that stores cookies
	 * @param string $logfile      Location of the log file. This contains errors and log entries
	 * @param array $browser_info  An associative array containing information about the browser 
	 * @see browser_info
	 */
	function __construct($region, $lang, $cookiefile, $logfile, $browser_info) {
		$this->logfile = $logfile;
		Exception::set_logfile($logfile);
		$this->log("Initializing API...");
		$this->region = $region;
		$this->lang = $lang;
		$this->browser_info = new browser_info($browser_info);
		$this->fp = $this->generate_fp($this->browser_info);
		$this->cookiefile = $cookiefile;

		libxml_use_internal_errors(true);

		$this->setup_curl();

		if (!file_exists($this->cookiefile) || file_get_contents($this->cookiefile) == "")
			$this->warn("File `$cookiefile` is empty or does not exist. Session could not be restored.");
		else {
			$this->log("Attempting to restore session.");

			//Request auction index page to check if we're logged in, then parse
			try {
				$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index");

				switch ($response->http_code) {
					case "200":
						$this->log("Session restored successfully.");
						$this->parse_ah_index($response);
						$this->retrieve_money("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index");
						$this->retrieve_mail("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index");
						$this->logged_in = true;
						break;
					case "404":
						throw new Exception("HTTP GET request to auction index page returned http code 404. (Account may not have access).", $this->last_http);
						break;
					case "302":
						$this->log("Restored session was not logged in.");
						break;
					default:
						throw new Exception("HTTP GET request to auction index page returned unexpected http code: {$response->http_code}.", $this->last_http);
				}
			}
			catch (Exception $e) {
				throw new Exception("Could not initiate API.", new \stdClass(), 0, $e);
			}
			
		}

	} //__construct()

	/**
	 * Checks if the API thinks the user is logged in
	 *
	 * The API keeps track of when the user has logged in.
	 * There is some issue with the Blizzard servers that intermittently
	 * causes the session cookies to become invalid on their end, resulting
	 * in all requests returning HTTP code 302. If this happens too many times,
	 * the API will assume such an issue has occurred, and the user must re-login.
	 * 
	 * @return boolean True if the user is logged in
	 */
	public function is_logged_in() {
		return $this->logged_in;
	}

	/**
	 * Gets the list of characters associated with the account
	 *
	 * Returns all of the characters on the account. The character
	 * at index 0 is being used for the API transactions.
	 * 
	 * @return array An indexed array of all the characters on the account
	 */
	public function get_character_list() {
		return $this->character_list;
	}

	/**
	 * Gets information about the selected character
	 *
	 * Certain requests to the Armory return a JSON object containing information
	 * about the selected character such as Realm, race, talents, class, faction, etc.
	 * This is stored without any formatting as an associative array.
	 * 
	 * @return array An associative array with information about the selected character
	 */
	public function get_character() {
		return $this->character;
	}

	/**
	 * Gets the amount of money the current character has
	 * 
	 * @return int The amount of money in copper
	 */
	public function get_money() {
		return $this->money;
	}

	/**
	 * Gets the contents of the character's mailbox
	 *
	 * The Armory website occasionally sends AJAX requests to get the character's mail
	 * contents and that is stored without any formatting.
	 * 
	 * @return array An associative array with the character's mail content
	 */
	public function get_mail() {
		return $this->mail;
	}

	/**
	 * Gets the number of messages in the mailbox
	 * 
	 * @return array An associative array containing the max, current, and total messages
	 */
	public function get_mail_info() {
		return $this->mail_info;
	}

	/**
	 * Gets the contents of the selected character's inventory, bank, and mail
	 *
	 * Returns an array of inventory_item objects representing the selected
	 * character's inventory. This must first be populated with update_inventory()
	 *
	 * @see inventory_item
	 * 
	 * @return array An indexed array containing one object per inventory item
	 */
	public function get_inventory() {
		return $this->inventory;
	}

	/**
	 * Gets the auctions of the selected character
	 *
	 * Returns an object containing three properties (active, sold, ended) each of which
	 * is an array containing its respective type of *_auction object. This must first be 
	 * populated with update_auctions()
	 *
	 * @see auctions
	 * @see active_auction
	 * @see sold_auction
	 * @see ended_auction
	 * 
	 * @return object An auctions object
	 */
	public function get_auctions() {
		return $this->auctions;
	}

	/**
	 * Returns the number of transactions executed by the API
	 *
	 * The Armory has a limit of 200 transactions per day, so the API keeps track of every auction won
	 * and every auction created (each stack counts as one). After this number hits a user-defined limit it will disable
	 * bidding and auction creation. It cannot keep track of transactions made outside the API. 
	 * 
	 * @return int The number of transactions executed by the API
	 */
	public function get_transaction_count() {
		return $this->transaction_count;
	}

	/**
	 * Resets the tracked transaction count to zero.
	 */
	public function reset_transaction_count() {
		$this->transaction_count = 0;
	}

	/**
	 * Check if the account is logged into the game
	 *
	 * If the account is logged into the game, certain Armory features are disabled,
	 * and the same are disabled in the API. Searching is essentially the only usable feature
	 * when this happens.
	 * 
	 * @return boolean True if the server response indicates that a character is logged in
	 */
	public function is_char_in_game() {
		return $this->char_in_game;
	}

	/**
	 * Takes the number of copper and returns a "decimal" string of gold (i.e. "gggg.ss.cc")
	 * @param  int $money    The amount of money in copper
	 * @return string        The formatted string
	 */
	public static function format_money_string($money) {
		return substr($money, 0, -4) . "." . substr($money, -4, 2) . "." . substr($money, -2);
	}

	/**
	 * Logs the user in
	 *
	 * Login procedure:
	 * + GET https://worldofwarcraft.com/{lang}-{region}/ - no referrer
  	 * + GET https://worldofwarcraft.com/{lang}-{region}/login - referrer https://worldofwarcraft.com/en-us/
  	 * + Follow redirects to the login page, which should match the url {region}.battle.net/login/{lang}/ and return a code 200
  	 * + Check for captcha
  	 * + POST srp info - referer login form url
  	 * + POST login form - referer login form url
  	 * + If the last url is the login form - bad password.
  	 * + If the last url is authenticator, send status checks until accepted, then GET authenticator url + 17 digit random number - referer authenticator url
  	 * + Should end up at wow website, which should match the url https://worldofwarcraft.com/{lang}-{region}/ with a code 200
  	 * + GET the first character URL (matching http://{region}.battle.net/wow/{lang}/character/{server}/{character}/simple) - referer wow website
  	 * + Make sure you actually get there
  	 * + Get auction house index - referer first character URL
	 * 
	 * @param  string $username Battle.net username
	 * @param  string $password Battle.net password
	 * @return boolean          True on success
	 */
	public function login($username, $password) {
		$this->log("Logging in...");
		
		//Clear the cookies to avoid janky blizzard server problems
		curl_close($this->ch);
		file_put_contents($this->cookiefile, "");
		$this->setup_curl();

		$this->log("Enabling CURLOPT_FOLLOWLOCATION for login requests. (curl will keep all responses, but only the last request header).");
		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);

		try {
			//Get the wow website
			$wow_url = "https://worldofwarcraft.com/{$this->lang}-{$this->region}/";
			$response = $this->make_request($wow_url);

			if ($response->http_code != "200")
				throw new Exception("HTTP GET request to world of warcraft website returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);

			//Get the login form
			$response = $this->make_request($wow_url . "login", array(), $wow_url);

			$loginform_url = $response->last_url;
			$pattern_loginform_url = "#^https://{$this->region}.battle.net/login/{$this->lang}/\?#";

			if ($response->http_code != "200")
				throw new Exception("HTTP GET request to login form returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);

			if (!preg_match($pattern_loginform_url, $loginform_url))
				throw new Exception("HTTP GET request to world of warcraft login form did not successfully return bnet login form. Redirected to $loginform_url.", $this->last_http);
				
			if (stristr($response->body, "/login/captcha.jpg"))
				throw new Exception("Detected captcha in bnet login form. Captcha is not yet supported.", $this->last_http);

			//Get the srp client javascript
			$doc = new \DomDocument;
			$doc->loadHTML($response->body);

			$this->handle_libxml_errors(libxml_get_errors(), "login form");

			$xpath = new \DOMXpath($doc);
			$nodelist = $xpath->query("//script[contains(@src,'srp-client')]");
			$bnet_srpclient_js_loc = count($nodelist) > 0  && $nodelist !== false ? $nodelist[0]->getAttribute("src") : "";

			if($bnet_srpclient_js_loc == "")
				throw new Exception("Could not parse location of bnet srp javascript from login form.", $this->last_http);

			$this->log("Downloading srp-client js from: $bnet_srpclient_js_loc");
			if (substr($bnet_srpclient_js_loc,0,2) == "//")
				$bnet_srpclient_js_loc = "http:" . $bnet_srpclient_js_loc;

			try {
				$bnet_srpclient_js = file_get_contents($bnet_srpclient_js_loc);
			}
			catch(Exception $e) {
				throw new Exception("Could not download bnet srp javascript file.", $this->last_http);
			}

			//Get the session timeout
			$this->log("Parsing session timeout.");
			$nodelist = $xpath->query("//input[@id='sessionTimeout' and @type='hidden']");
			$sessionTimeout = count($nodelist) > 0 && $nodelist !== false ? $nodelist[0]->getAttribute("value") : "";

			if ($sessionTimeout == "")
				throw new Exception("Could not parse session timeout from login form.", $this->last_http);

			//Get the srp info from the server, calculate the client info, and submit the login
			$this->log("Requesting srp info.");
			$response = $this->make_request("https://{$this->region}.battle.net/login/srp?csrfToken=true",array("Accept" => "application/json", "Content-Type" => "application/json","X-Requested-With" => "XMLHttpRequest"),
											$loginform_url, '{"inputs":[{"input_id":"account_name","value":"' . $username . '"}]}');
			
			if ($response->http_code != "200")
				throw new Exception("HTTP POST request to srp info returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);

			$this->log("Calculating srp parameters.");
			$srpIn = json_decode($response->body, true);
			$json = "var e=" . json_encode(array_merge($srpIn, array("password" => $password))) . "; var navigator = {appName:'Netscape'};";
			$wow_ah_js = file_get_contents(SRP_CUSTOM_JS_FILE);

			$v8js = new \V8Js("PHP", array(), array(), false);
			$srpOut = $v8js->executeString($json . $bnet_srpclient_js . $wow_ah_js, "srp", \V8Js::FLAG_FORCE_ARRAY);

			if (!is_null($jsexception = $v8js->getPendingException()))
				throw new Exception("Javascript exception thrown while calculating srp client info.", array());

			$postdata = array(
				"accountName"      => $username,
				"password"         => implode(".", array_fill(0,strlen($password)+1,"")),
				"useSrp"           => "true",
				"publicA"          => $srpOut["publicA"],
				"clientEvidenceM1" => $srpOut["clientEvidenceM1"],
				"csrftoken"        => $srpIn["csrf_token"],
				"sessionTimeout"   => $sessionTimeout,
				"persistLogin"     => "on",
				"fp"               => $this->fp
			);

			$this->log("Submitting login");
			$response = $this->make_request($loginform_url, array(), $loginform_url, http_build_query($postdata));

			if ($response->http_code != "200")
				throw new Exception("HTTP POST request to login form returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);

			$login_redirect_url = $response->last_url;

			if (preg_match($pattern_loginform_url, $login_redirect_url)) {
				$pattern = '#<div id="display-errors" class="alert alert-error alert-icon">\s*(.*?)<br/>#'; //Could do a full DOM parse here as this is weak to page changes
				$log_string = preg_match($pattern, $response->body, $matches) ? $matches[1] : "";
				throw new Exception("Login form submission returned to login form. Likely bad password. Form error: $log_string.", $this->last_http);
			}			

			//Wait for authenticator if that's where it sent us
			$pattern_authenticator_url = "#^https://{$this->region}.battle.net/login/{$this->lang}/authenticator#";
			if (preg_match($pattern_authenticator_url, $login_redirect_url)) {
				$this->log("Authenticator detected. Loaded authenticator page.");

				$flagWaiting = true;
				$count = 0; //Timeout loop. A little over 2min.
				$this->log("Sending authenticator status checks");
				while ($flagWaiting ) {
					$response = $this->make_request("https://{$this->region}.battle.net/login/authenticator/status?check-" . sprintf("%d", round(lcg_value()*1e17)),
													array("Accept" => "*/*", "X-Requested-With" => "XMLHttpRequest"), $login_redirect_url);
					if ($response->http_code != "200")
						throw new Exception("HTTP GET request to the authenticator check returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);

					$response_json = json_decode($response->body, true);
					if (!isset($response_json["two_factor_state"]))
						throw new Exception("Invalid response from authenticator check.", $this->last_http);

					switch($response_json["two_factor_state"]) {
						case "PENDING":
							$this->log("Waiting...");
							break;
						case "TIMEOUT":
							throw new Exception("Authenticator check timed out.", $this->last_http, Exception::ERROR_BUT_RETRY);
							break;
						case "REJECTED":
							throw new Exception("Authenticator check was rejected.", $this->last_http);
							break;
						case "ACCEPTED":
							$flagWaiting = false;
							$this->log("Received authenticator response.");
							break;
						default:
							throw new Exception("Unknown response from authenticator check.", $this->last_http);
					}

					$count++;
					if ($count > 60)
						throw new Exception("Sent 60 authenticator requests with only \"PENDING\" responses. Assumed timed out.", $this->last_http);
					sleep(2); //Match the website's 2s delay between checks
				}

				//Now send a new request to the authenticator page to make it back to the wow homepage
				$response = $this->make_request($login_redirect_url . sprintf("&%d", round(lcg_value()*1e17)), array(), $login_redirect_url);
				if ($response->http_code != "200")
					throw new Exception("HTTP GET request to authenticator page returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);

				//Update the url to what should be the wow homepage
				$login_redirect_url = $response->last_url;

			}

			if (stristr($login_redirect_url, "challenge"))
				throw new Exception("Battle.net issued login challenge. Please login manually once.");
			else if ($login_redirect_url != $wow_url)
				throw new Exception("Redirected to $login_redirect_url. Expected $wow_url.", $this->last_http);

			$this->log("Logged in. Getting first character page.");
			$pattern_character_url = "#http://{$this->region}.battle.net/wow/{$this->lang}/character/.*?/.*?/simple#";
			if (!preg_match($pattern_character_url, $response->body, $matches))
				throw new Exception("Could not find character url in wow homepage.", $this->last_http);
			$character_url = $matches[0];

			$response = $this->make_request($character_url, array(), $wow_url);
			if ($response->http_code != "200")
				throw new Exception("HTTP GET request to character page returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);
			if (!preg_match("#^" . $character_url . "#", $response->last_url))
				throw new Exception("Redirected to {$response->last_url}. Expected $character_url.", $this->last_http);

			//Finally, get the auction house index, expecting to be redirected to https
			$ah_index_url = "http://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/";

			$response = $this->make_request($ah_index_url, array(), $character_url);
			if ($response->http_code != "200")
				throw new Exception("HTTP GET request to auction house index page returned http code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);
			if (!preg_match("#^https://" . substr($ah_index_url,7) . "#", $response->last_url))
				throw new Exception("Redirected to {$response->last_url}. Expected https://" . substr($ah_index_url, 7) . ".", $this->last_http);

			curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
			$this->log("Disabled CURLOPT_FOLLOWLOCATION");

			$this->parse_ah_index($response);
			$this->retrieve_money($response->last_url);
			$this->retrieve_mail($response->last_url);

		} //try
		catch (Exception $e) {
			curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, false);
			$this->log("Disabled CURLOPT_FOLLOWLOCATION");
			throw new Exception("Could not log in.", new \stdClass(), 0, $e);
		}

		$this->logged_in = true;
		return true;

	} //login()

	/**
	 * Selects a character to be used for API transactions
	 *
	 * Whichever character is in the 0'th index of the character list
	 * is used for all API transactions. This function moves the selected character
	 * to that index, so the whole character list will change.
	 * 
	 * @param  int $index    Index of the character to be used, from get_character_list()
	 * @return boolean       True on success
	 */
	public function select_character($index) {
		$index = intval($index);
		$index = $index > count($this->character_list) ? count($this->character_list)-1 : $index;
		$this->log("Selecting character $index.");

		if ($index == 0) {
			return true;
		}

		try {
			$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/pref/character", array("Accept" => "*/*", "X-Requested-With" => "XMLHttpRequest"),
											"https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index", http_build_query(array("index" => $index, "xstoken" => $this->xstoken)));
		
			if ($response->http_code != "200") {
				throw new Exception("HTTP POST request to select character returned code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);
			}

			//Reload the page to get the new character indices
			$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index");
			
			if ($response->http_code != "200") {
				throw new Exception("HTTP GET request to retrieve character indices returned code {$response->http_code}. Expected 200.", $this->last_http, Exception::ERROR_UNEXPECTED_HTTP_CODE);
			}

			$this->parse_ah_index($response);
			$this->retrieve_money("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index");
			$this->retrieve_mail("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index");
		}
		catch (Exception $e) {
			throw new Exception("Failed to select character.", new \stdClass(), 0, $e);
		}

		return true;

	}

	/**
	 * Performs a search of the auction house
	 *
	 * Searches the auction house. For all filters, a value of -1 (int or string) disables them. Specifying an item_id
	 * guarantees only that item is returned, and still allows for filtering. Some error checking is performed on values that are
	 * not entered raw on the website. The API pauses for 1 second between consecutive requests (the website returns a
	 * maximum of 40 results at a time) for a single search. The Armory returns only the first 200 results.
	 * 
	 * @param  string  $name      Item name
	 * @param  string  $category  Category, subcategory, and sub-sub category, separated by commas (see categories.txt) (e.g. 0,2)
	 * @param  int     $minlevel  Minimum level
	 * @param  int     $maxlevel  Maximum level
	 * @param  int     $quality   Minimum item quality (0 = Poor, 1 = Common, 2 = Uncommon, 3 = Rare, 4 = Epic)
	 * @param  string  $sort      How to sort results ("rarity", "quantity", "level", "ilvl", "time", "bid", "unitBid", "buyout", "unitBuyout")
	 * @param  boolean  $reverse   True is descending, false is ascending
	 * @param  int     $limit     How many auctions to return, -1 for all (Armory limits to 200)
	 * @param  string  $timestamp A user-specified time stamp (mysql format, e.g. date("Y-m-d H:i:s")) to add to all results of this search. Useful for data analysis
	 * @param  integer $item_id   WoW item id. When this is not 0, disables name, category, minlevel, maxlevel, and quality fields
	 * @see    search_result
	 * 
	 * @return array              An indexed array of search result objects, in the sort order specified.
	 */
	public function search($name, $category, $minlevel, $maxlevel, $quality, $sort, $reverse, $limit, $timestamp = 0, $item_id = 0) {
		//Only need to validate inputs that aren't entered raw by the user on the website
		$name = rawurlencode($name);

		$pattern = "/^-?\d+(?:,-?\d+){0,2}$/";
		if (preg_match($pattern, $category) != 1)
			$category = "-1";
		$category = urlencode($category);

		$item_id = intval($item_id);
		$minlevel = intval($minlevel);
		$maxlevel = intval($maxlevel);
		$quality = intval($quality);

		if ($quality < -1)
			$quality = -1;
		else if ($quality > 4)
			$quality = 4;

		if ($item_id < 0)
			$item_id = 0;

		switch ($sort) {
			case "rarity":
			case "quantity":
			case "levlel":
			case "ilvl":
			case "time":
			case "bid":
			case "unitBid":
			case "buytout":
			case "unitBuyout":
				break;
			default:
				$sort = "unitBuyout";
		}

		$reverse = boolval($reverse) ? "true" : "false";

		$limit = intval($limit);
		if ($limit > 200 || $limit < 1)
			$limit = 200;

		if ($item_id == 0)
			$this->log("Searching - name: $name; category: $category; minlevel: $minlevel; maxlevel: $maxlevel, quality: $quality; sort: $sort; reverse: $reverse; limit: $limit");
		else
			$this->log("Searching - item_id: $item_id; sort: $sort; reverse: $reverse; limit: $limit");

		$count = 0;
		$prev_url = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/browse";
		$results_out = array();

		while ($count < $limit) {
			$end = $count + 40 > $limit ? $limit : $count + 40;
			if ($item_id == 0)
				$url = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/browse?n=$name&filterId=$category&minLvl=$minlevel&maxLvl=$maxlevel&qual=$quality&start=$count&end=$end&sort=$sort&reverse=$reverse";
			else
				$url = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/browse?itemId=$item_id&start=$count&end=$end&sort=$sort&reverse=$reverse";
			
			try {
				$response = $this->make_request($url, array(), $prev_url);

				if ($response->http_code != "200") {
					throw new Exception("HTTP GET request to retrieve search results returned code {$response->http_code}. Expected 200.", $this->last_http,
											   $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
				}

				$results = $this->parse_search_results($response->body, $timestamp);

				if (count($results["results"]) == 0)
					return array();

				$results_out = array_merge($results_out, $results["results"]);
				if ($results["count"] < $limit)
					$limit = $results["count"];

				$this->retrieve_money($url);
				$this->retrieve_mail($url);

				$prev_url = $url;
				$count += 40;

			}
			catch (Exception $e) {
				throw new Exception("Could not execute search of '$name' from $count to $end.", new \stdClass(), 0, $e);
			}

			//Don't seem like a robot
			sleep(1);
		}

		return $results_out;
		
	}

	/**
	 * Bid on an auction
	 *
	 * If the bid amount is equal to or higher than the buyout, it will automatically
	 * result in buying out the auction.
	 *
	 * @param  int    $auction_id The auction id
	 * @param  int    $bid        The bid amount in copper
	 *
	 * @return boolean            True if the bid results in a buyout, false if it is just a high bid
	 */
	public function bid($auction_id, $bid) {
		if (!$this->char_in_game && $this->transaction_count < TRANSACTION_LIMIT) {
			$auction_id = intval($auction_id);
			$bid = intval($bid);

			$this->log("Bidding " . wow_ah::format_money_string($bid) . "g on auction id $auction_id.");
			$referer = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/browse";

			try {
				$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/bid", array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"),
									$referer, http_build_query(array("auc" => $auction_id, "money" => $bid, "xstoken" => $this->xstoken)));
				if ($response->http_code != 200)
					throw new Exception("HTTP POST request to place bid returned http code {$response->http_code}. Expected 200.",
											   $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);

				$response_json = json_decode($response->body, true);
				if (isset($response_json["error"]) || !isset($response_json["item"]["owner"])) {
					switch ($response_json["error"]["code"]) {
						case 1004:
							$error_code = Exception::ERROR_AUCTION_NOT_FOUND;
							break;
						case 1006:
							$error_code = Exception::ERROR_BID_TOO_LOW;
							break;
						case 10010:
							$error_code = Exception::ERROR_ACCOUNT_DISABLED;
							break;
						default:
							$error_code = 0;
					}
					throw new Exception("Bid placement returned an error: " . $response_json["error"]["message"] . ".", $this->last_http, $error_code);
				}
				if ($response_json["item"]["owner"]) { 
					$this->transaction_count++;
				}
				return $response_json["item"]["owner"];
			}
			catch (Exception $e) {
				throw new Exception("Could not place bid.", new \stdClass(), 0, $e);
			}
		}
	}

	/**
	 * Update the selected character's inventory
	 *
	 * Sends a request to .../auction/create and parses the inventory from the CDATA. Since this link could be clicked
	 * from anywhere, an optional referer parameter is provided. The inventory contains all items in the bags, bank, and mail.
	 * The inventory is stored in the API and can be retrieved without an update by using get_inventory().
	 *
	 * @param  string $referer The URL of the referer to use when making the page request
	 *
	 * @see inventory_item
	 *
	 * @return array           Returns an array of objects representing items in the inventory
	 */
	public function update_inventory($referer = "") {
		if (!$this->char_in_game) {
			try {
				$this->log("Updating inventory...");
				if ($referer == "")
						$referer = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/browse";

				$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/create", array(), $referer);
				if ($response->http_code != 200)
					throw new Exception("HTTP POST request to get inventory returned http code {$response->http_code}. Expected 200.",
											   $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);

				$doc = new \DomDocument;
				$doc->loadHTML('<?xml encoding="utf-8" ?>' . $response->body);

				$this->handle_libxml_errors(libxml_get_errors(), "inventory");

				$xpath = new \DOMXpath($doc);
				$inventory_text = $xpath->evaluate("string(//div[@id='inventories']/script/text())");

				$pattern = "@AuctionCreate\.items = ({['\"\w\s:{},/\._\-&#;]+});@u";
				if (!preg_match($pattern, $inventory_text, $matches))
					throw new Exception("Could not parse inventory CDATA.", $this->last_http);

				//The CDATA contains a javascript object with some keys having quotes, others not.
				$pattern = "@ (\w+): @";
				$inventory_array = json_decode(preg_replace($pattern, " \"\\1\": ", str_replace("'", "\"", $matches[1])), true);

				if (is_null($inventory_array))
					throw new Exception("Could not decode inventory CDATA.", $this->last_http);

				foreach ($inventory_array as $item) {
					$inventory[] = new inventory_item($item);
				}

				$this->inventory = $inventory;

			}
			catch (Exception $e) {
				throw new Exception("Could not update inventory.", new \stdClass(), 0, $e);
			}

			return $this->inventory;
		}
	}

	/**
	 * Create an auction on the ah
	 *
	 * Performs some error checking then submits a number of requests as necessary to list auctions on the auction house
	 * 
	 * @param  int $item_id    		item id
	 * @param  int $duration   		auction duration (0 = 12 hours, 1 = 24 hours, 2 = 48 hours)
	 * @param  int $quantity   		quantity per stack
	 * @param  int $stacks     		number of stacks
	 * @param  int $buyout     		buyout price in copper
	 * @param  int $bid        		bid price in copper
	 * @param  bool $pricePerItem   prices given per item (false = per stack)
	 * @param  int $sourceType 		from where to withdraw items (0 = all [bags, bank, and mail], 1 = bags, 2 = bank, 3 = mail)
	 *
	 * @return boolean  			True on success 
	 */
	public function create_auction($item_id, $duration, $quantity, $stacks, $buyout, $bid, $pricePerItem, $sourceType) {
		if (!$this->char_in_game && $this->transaction_count < TRANSACTION_LIMIT) {
			//Error checking
			//We may want to eventually check relevant parameters against the inventory for added security
			$item_id = intval($item_id);

			$duration = intval($duration);
			if ($duration < 0)
				$duration = 0;
			else if ($duration > 2)
				$duration = 2;

			$quantity = intval($quantity);
			$stacks = intval($stacks);

			$buyout = intval($buyout);
			if ($buyout < 0)
				$buyout = 0;

			$bid = intval($bid);
			if ($bid < 0)
				$bid = 0;

			$pricePerItem = boolval($pricePerItem);

			$sourceType = intval($sourceType);
			if ($sourceType < 0)
				$sourceType = 0;
			else if ($sourceType > 3)
				$sourceType = 3;

			$postdata_deposit = http_build_query(array("item" => $item_id, "duration" => $duration, "quan" => $quantity, "stacks" => $stacks, "sk" => $this->xstoken));
			$referer = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/create";
			
			try {
				//For some reason the website sends an unneccessary POST request to /deposit with either the previous
				//quantities or the maximum single stack quantity before making a request to /getSimilar
				$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/deposit",
												array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"), $referer, $postdata_deposit);

				if ($response->http_code != 200) {
					throw new Exception("HTTP POST request to deposit items (create auction) returned http code {$response->http_code}. Expected 200.",
									    $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
				}

				//jQuery passes a timestamp in ms as parameter _ to avoid IE caching issues
				$postdata_similar = http_build_query(array("sort" => "unitBuyout", "itemId" => $item_id, "reverse" => false, "_" => intval(microtime(true) * 1000)));

				$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/similar",
												array("Accept" => "text/html, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"), $referer, $postdata_similar);

				if ($response->http_code != 200) {
					throw new Exception("HTTP POST request to get similar items (create auction) returned http code {$response->http_code}. Expected 200.",
									    $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
				}

				//Now do the real request to /deposits
				$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/deposit",
												array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"), $referer, $postdata_deposit);

				if ($response->http_code != 200) {
					throw new Exception("HTTP POST request to deposit items (create auction) returned http code {$response->http_code}. Expected 200.",
									    $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
				}

				$response_json = json_decode($response->body, true);
				if (!isset($response_json["ticket"]))
					throw new Exception("Could not get deposit ticket.", $this->last_http);
				else
					$ticket = $response_json["ticket"];

				$postdata_createauction = array(
					"itemId"     => $item_id,
					"quantity"   => $quantity,
					"sourceType" => $sourceType,
					"duration"   => $duration,
					"stacks"     => $stacks,
					"buyout"     => $buyout,
					"bid"        => $bid,
					"type"       => $pricePerItem ? "perItem" : "perStack",
					"xstoken"    => $this->xstoken
				);

				$nextticket = $ticket;
				for ($i = 0; $i < $stacks; $i++) {
					$postdata_createauction["ticket"] = $nextticket;
					$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/createAuction",
													array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"), $referer, http_build_query($postdata_createauction));

					if ($response->http_code != 200) {
						throw new Exception("HTTP POST request to create auction returned http code {$response->http_code}. Expected 200.",
										    $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
					}

					$response_json = json_decode($response->body, true);
					if (isset($response_json["error"])) {
						switch ($response_json["error"]["code"]) {
							case 10010:
								$error_code = Exception::ERROR_ACCOUNT_DISABLED;
								break;
							default:
								$error_code = 0;
						}
						throw new Exception("Auction creation request returned an error: " . $response_json["error"]["message"], $this->last_http, $error_code);
					}
					else if(isset($response_json["auction"]["auctionId"])) {
						$this->log("Created auction number  " . $response_json["auction"]["auctionId"] . ".");
						if ($i < $stacks - 1) {
							if (isset($response_json["auction"]["nextTicket"]))
								$nextticket = $response_json["auction"]["nextTicket"];
							else
								throw new Exception("Could not get next ticket for auction creation.");
						}
					}
					else {
						throw new Exception("Auction object not present in response.", $this->last_http);
					}
					$this->transaction_count++;
					sleep(1);

				}

				return true;

			} //try
			catch (Exception $e) {
				throw new Exception("Could not create auction", new \stdClass(), 0, $e);
			}

		} //if not in game and transaction limit not reached

	} //create_auction()

	/**
	 * Updates the selected characters auctions (active, sold, and ended)
	 * 
	 * @return object An auctions object containing arrays of active_auction, sold_auction, ended_auction
	 */
	public function update_auctions() {
		try {
			$this->log("Updating auctions...");
			$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/auctions", array(),
											"https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/index");

			if ($response->http_code != 200) {
				throw new Exception("HTTP GET request to get auctions returned http code {$response->http_code}. Expected 200.",
								    $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
			}

			$auctions = new auctions();

			$doc = new \DomDocument;
			$doc->loadHTML('<?xml encoding="utf-8" ?>' . $response->body);

			$this->handle_libxml_errors(libxml_get_errors(), "auctions");

			$xpath = new \DOMXpath($doc);

			//Active Auctions
			$nodelist = $xpath->query("//div[@id='auctions-active']//tr[contains(@id,'auction')]");

			foreach($nodelist as $node) {
				$current_auction = array();
				$auction_id_str = $xpath->evaluate("string(@id)", $node);
				$current_auction["id"] = intval(substr($auction_id_str, strpos($auction_id_str, "-")+1));
				$item_id_url = $xpath->evaluate("string(td[@class='item']/a/strong/../@href)", $node);
				$current_auction["item_id"] = intval(substr($item_id_url, strrpos($item_id_url, "/")+1));
				$current_auction["item_name"] = $xpath->evaluate("string(td[@class='item']/a/strong/text())", $node);
				$current_auction["quantity"] = intval($xpath->evaluate("string(td[@class='quantity']/text())", $node));
				$current_auction["time"] = $xpath->evaluate("string(td[@class='time']/span/text())", $node);
				$current_auction["high_bidder"] = $xpath->evaluate("string(td[@class='status']/span/text())", $node);

				$gold = str_replace(",","", $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-bid')]/span[@class='icon-gold'])", $node));
				$silver = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-bid')]/span[@class='icon-silver'])", $node);
				$copper = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-bid')]/span[@class='icon-copper'])", $node);
				$current_auction["bid"] = 10000*intval($gold) + 100*intval($silver) + intval($copper);
				$current_auction["unitBid"] = intdiv($current_auction["bid"], $current_auction["quantity"]);


				$gold = str_replace(",","", $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-buyout')]/span[@class='icon-gold'])", $node));
				$silver = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-buyout')]/span[@class='icon-silver'])", $node);
				$copper = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-buyout')]/span[@class='icon-copper'])", $node);
				$current_auction["buyout"] = 10000*intval($gold) + 100*intval($silver) + intval($copper);
				$current_auction["unitBuyout"] = intdiv($current_auction["buyout"], $current_auction["quantity"]);

				$auctions->active[] = new active_auction($current_auction);
			}

			//Sold Auctions
			$nodelist = $xpath->query("//div[@id='auctions-sold']//tr[contains(@id,'auction')]");

			foreach($nodelist as $node) {
				$current_auction = array();
				$auction_id_str = $xpath->evaluate("string(@id)", $node);
				$current_auction["id"] = intval(substr($auction_id_str, strpos($auction_id_str, "-")+1));
				$item_id_url = $xpath->evaluate("string(td[@class='item']/a/@href)", $node);
				$current_auction["item_id"] = intval(substr($item_id_url, strrpos($item_id_url, "/")+1));
				$item_str = $xpath->evaluate("string(td[@class='item']/@data-raw)", $node);
				$current_auction["item_name"] = substr($item_str, strpos($item_str, " ")+1);
				$current_auction["quantity"] = intval($xpath->evaluate("string(td[@class='quantity']/text())", $node));
				$current_auction["level"] = intval($xpath->evaluate("string(td[@class='level']/text())", $node));
				$current_auction["buyer"] = trim($xpath->evaluate("string(td[@class='align-center']/text())", $node)); //This is a weak search but it's all we have to go on...
				$current_auction["price"] = intval($xpath->evaluate("string(td[@class='price']/@data-raw)", $node));
				$current_auction["claimable"] = boolval(!stristr($xpath->evaluate("string(td[@class='options']/a/@class)", $node), "disabled"));

				if ($current_auction["claimable"])
					$current_auction["mail_id"] = intval($xpath->evaluate("string(td[@class='options']/input[@class='mail-id']/@value)", $node));

				$auctions->sold[] = new sold_auction($current_auction);
			}

			//Ended Auctions
			$nodelist = $xpath->query("//div[@id='auctions-ended']//tr[contains(@id,'auction')]");

			foreach($nodelist as $node) {
				$current_auction = array();
				$auction_id_str = $xpath->evaluate("string(@id)", $node);
				$current_auction["id"] = intval(substr($auction_id_str, strpos($auction_id_str, "-")+1));
				$item_id_url = $xpath->evaluate("string(td[@class='item']/a/@href)", $node);
				$current_auction["item_id"] = intval(substr($item_id_url, strrpos($item_id_url, "/")+1));
				$item_str = $xpath->evaluate("string(td[@class='item']/@data-raw)", $node);
				$current_auction["item_name"] = substr($item_str, strpos($item_str, " ")+1);
				$current_auction["quantity"] = intval($xpath->evaluate("string(td[@class='quantity']/text())", $node));
				$current_auction["level"] = intval($xpath->evaluate("string(td[@class='level']/text())", $node));
				$current_auction["status"] = trim($xpath->evaluate("string(td[@class='status']/span/text())", $node));
				$current_auction["time"] = intval($xpath->evaluate("string(td[@class='time']/@data-raw)", $node));

				$auctions->ended[] = new ended_auction($current_auction);
			}

			$this->auctions = $auctions;

			return $this->auctions;

		} //try
		catch (Exception $e) {
			throw new Exception("Could not update auctions.", new \stdClass(), 0, $e);
		}

	} //update_auctions()

	/**
	 * Cancels an auction
	 * 
	 * @param  int $auction_id id of the auction to cancel
	 */
	public function cancel_auction($auction_id) {
		try {
			$referer = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/auctions";
			$postdata = http_build_query(array("auc" => $auction_id, "xstoken" => $this->xstoken));
			$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/cancel",
											array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"), $referer, $postdata);

			if ($response->http_code != 200) {
				throw new Exception("HTTP POST request to cancel auction returned http code {$response->http_code}. Expected 200.",
								    $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
			}

			$response_json = json_decode($response->body, true);
			if (isset($response_json["error"])) {
				switch ($response_json["error"]["code"]) {
					case 10010:
						$error_code = Exception::ERROR_ACCOUNT_DISABLED;
						break;
					default:
						$error_code = 0;
				}
				throw new Exception("Auction cancellation request returned an error: " . $response_json["error"]["message"], $this->last_http, $error_code);
			}
			else if(isset($response_json["auction"]["auctionId"])) {
				$this->log("Cancelled auction number  " . $response_json["auction"]["auctionId"] . ".");
			}
			else {
				throw new Exception("Auction object not present in response.", $this->last_http);
			}

			return true;
		}
		catch (Exception $e) {
			throw new Exception("Could not cancel auction.", new \stdClass(), 0, $e);
		}
		
	} //cancel_auction()

	/**
	 * Takes money from auction profits in the selected character's mailbox
	 *
	 * Takes the money from the passed mail id's. Mail id's can be obtained either from
	 * get_auctions() or get_mail().
	 *
	 * @param  string $mail_ids Mail id's, separated by commas
	 *
	 * @return int              The amount of money obtained, in copper
	 */
	public function take_money($mail_ids) {
		try {
			$referer = "https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/auctions";
			$postdata = http_build_query(array("mailIds" => $mail_ids, "xstoken" => $this->xstoken));
			$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/takeMoney",
											array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"), $referer, $postdata);

			if ($response->http_code != 200) {
				throw new Exception("HTTP POST request to take money returned http code {$response->http_code}. Expected 200.",
								    $this->last_http, $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
			}

			$response_json = json_decode($response->body, true);
			if (isset($response_json["error"])) {
				switch ($response_json["error"]["code"]) {
					case 10010:
						$error_code = Exception::ERROR_ACCOUNT_DISABLED;
						break;
					default:
						$error_code = 0;
				}
				throw new Exception("Take money request returned an error: " . $response_json["error"]["message"] . ".", $this->last_http, $error_code);
			}
			else if(isset($response_json["claimedMail"])) {
				$money_obtained = $response_json["claimedMail"]["moneyObtained"];
				$log_string = "Claimed " . self::format_money_string($money_obtained) . "g from mails: ";
				foreach ($response_json["claimedMail"]["claimedMails"] as $mailid) {
					$log_string .= "$mailid,";
				}
				$log_string = substr($log_string, 0, -1);
				$this->log($log_string);

				return $money_obtained;
			}
			else {
				throw new Exception("Claimed mail object not present in response.", $this->last_http);
			}

		}
		catch (Exception $e) {
			throw new Exception("Could not take money.", new \stdClass(), 0, $e);
		}
	}

	/**
	 * Helper function to take all available money
	 *
	 * Calls update_auctions(), concatenates mail id's from sold auctions that are claimable
	 * and passes them to take_money()
	 * 
	 * @return int The amount of money taken in copper, 0 if there are no auctions
	 */
	public function take_all_money() {
		$this->log("Taking all money...");
		try {
			$this->update_auctions();
			if (empty($this->auctions->sold)) {
				$this->log("Attempted to take all money, but there was nothing to take.");
				return 0;
			}
			$mail_ids = "";
			foreach ($this->auctions->sold as $auction) {
				if ($auction->claimable)
					$mail_ids .= $auction->mail_id . ",";
			}
			if ($mail_ids == "") {
				$this->log("Attempted to take all money, but there was nothing to take.");
				return 0;
			}
			else {
				$mail_ids = substr($mail_ids, 0, -1);
			}

			return $this->take_money($mail_ids);
		}
		catch (Exception $e) {
			throw new Exception("Could not take all money.", new \stdClass(), 0, $e);
		}
	}

	/**
	 * Cleanly shuts down the API
	 *
	 * This method should always be used to shut down the API
	 * since it will call curl_close() which actually writes the cookies
	 * to the cookie file. It also flushes the log buffer.
	 */
	public function close() {
		$this->flush_log();
		curl_close($this->ch);
	}

	/**
	 * Sets up some curl parameters during construction
	 */
	private function setup_curl() {
		$this->log("Setting up cURL.");
		$this->ch = curl_init();
		$options = array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERAGENT      => $this->browser_info->useragent,
			CURLOPT_ENCODING       => "",
			CURLOPT_HEADER         => true,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLINFO_HEADER_OUT    => true,
			CURLOPT_COOKIEJAR      => $this->cookiefile,
			CURLOPT_COOKIEFILE     => $this->cookiefile
			);
		curl_setopt_array($this->ch, $options);
	}

	/**
	 * Retrieves the character's gold amount and the character information
	 *
	 * Calls to this method happen automatically as necessary in the course
	 * of other transactions, to mimic the website.
	 *
	 * @param  string $referer The url of the referer to use for HTTP requests
	 */
	private function retrieve_money($referer) {
		$this->log("Retrieving money.");
		try {
			$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/money", array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"),
										$referer, "");

			if ($response->http_code != "200") {
				throw new Exception("HTTP GET request to retrieve money returned code {$response->http_code}. Expected 200.", $this->last_http,
										  $response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
			}

		}
		catch (Exception $e) {
			throw new Exception("Could not retrieve money.", new \stdClass(), 0, $e);
		}

		$json = json_decode($response->body, true);
		if (isset($json["error"]) && ($json["error"]["code"] == 100 || $json["error"]["code"] == 115))
			$this->char_in_game = true;
		else if (!isset($json["money"]) || !isset($json["character"]))
			throw new Exception("Unexpected json returned when retrieving money.", $this->last_http);
		else
			$this->char_in_game = false;
	
		if (!$this->char_in_game) {
			$this->money = $json["money"];
			$this->character = $json["character"];
		}
	}

	/**
	 * Retrieves the character's mailbox and mail information
	 *
	 * Calls to this method happen automatically as necessary in the course
	 * of other transactions, to mimic the website.
	 *
	 * @param  string $referer The url of the referer to use for HTTP requests
	 */
	private function retrieve_mail($referer) {
		$this->log("Retrieving mail.");
		try {
			$response = $this->make_request("https://{$this->region}.battle.net/wow/{$this->lang}/vault/character/auction/mail", array("Accept" => "application/json, text/javascript, */*; q=0.01", "X-Requested-With" => "XMLHttpRequest"),
											$referer, http_build_query(array("lastMailId" => "0", "xstoken" => $this->xstoken)));		

			if ($response->http_code != "200") {
				throw new Exception("HTTP GET request to retrieve mail returned code {$response->http_code}. Expected 200.", $this->last_http,
											$response->http_code == 302 ? (Exception::ERROR_BUT_RETRY | Exception::ERROR_UNEXPECTED_HTTP_CODE) : Exception::ERROR_UNEXPECTED_HTTP_CODE);
			}
		}
		catch (Exception $e) {
			throw new Exception("Could not retrieve mail.", new \stdClass(), 0, $e);
		}
		$json = json_decode($response->body, true);
		if (isset($json["error"]) && ($json["error"]["code"] == 100 || $json["error"]["code"] == 115))
			$this->char_in_game = true;
		else if (!isset($json["mail"]) || !isset($json["mailInfo"]) || !isset($json["character"]) )
			throw new Exception("Unexpected json returned when retrieving mail", $this->last_http);
		else
			$this->char_in_game = false;

		if (!$this->char_in_game) {
			$this->mail = $json["mail"];
			$this->mail_info = $json["mailInfo"];
			$this->character = $json["character"];
		}
	}

	//Returns an array containing
	//"results" => array("id" => auction id, "item_id" => item id "item" => item name, "seller" => seller name, "quantity" => quantity,
	//					 "time" => time left, "bid" => bid, "unitBid" => bid per unit, "buyout" => buyout, "unitBuyout" => buyout per unit)
	//"count" => how many total results there are
	/**
	 * Parses search results from an HTTP response into an array of objects
	 *
	 * @param  string $body      An HTTP response body
	 * @param  string $timestamp A timestamp (in mysql format e.g. date("Y-m-d H:i:s")) to add to each of the parsed results (useful for data analysis)
	 *
	 * @see search_result
	 *
	 * @return array             An indexed array of search_result objects
	 */
	private function parse_search_results($body, $timestamp) {
		$this->log("Parsing search results.");
		$doc = new \DomDocument;
		$doc->loadHTML('<?xml encoding="utf-8" ?>' . $body);

		$this->handle_libxml_errors(libxml_get_errors(), "search results");

		$xpath = new \DOMXpath($doc);
		$nodelist = $xpath->query("//tr[contains(@id,'auction')]");
		if (count($nodelist) > 0 && $nodelist !== false) {
			$results = array();
			foreach($nodelist as $node) {
				$current_result = array();
				$current_result["id"] = intval(substr($node->getAttribute("id"),strlen("auction-")));
				$current_result["item_name"] = substr($xpath->evaluate("string(td[@class='item']/span[@class='sort-data hide']/text())", $node), 2);
				$id_url = $xpath->evaluate("string(td[@class='item']/a/strong/../@href)", $node);
				$current_result["item_id"] = intval(substr($id_url, strrpos($id_url, "/")+1));
				$current_result["seller"] = $xpath->evaluate("string(td[@class='item']/a[contains(@href,'character')])", $node);
				$current_result["quantity"] = intval($xpath->evaluate("string(td[@class='quantity']/text())", $node));
				$current_result["time"] = $xpath->evaluate("string(td[@class='time']/span/text())", $node);

				$gold = str_replace(",", "", $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-bid')]/span[@class='icon-gold'])", $node));
				$silver = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-bid')]/span[@class='icon-silver'])", $node);
				$copper = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-bid')]/span[@class='icon-copper'])", $node);
				$current_result["bid"] = 10000*intval($gold) + 100*intval($silver) + intval($copper);
				$current_result["unitBid"] = intdiv($current_result["bid"], $current_result["quantity"]);


				$gold = str_replace(",","", $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-buyout')]/span[@class='icon-gold'])", $node));
				$silver = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-buyout')]/span[@class='icon-silver'])", $node);
				$copper = $xpath->evaluate("string(td[@class='price']/div[contains(@class,'price-buyout')]/span[@class='icon-copper'])", $node);
				$current_result["buyout"] = 10000*intval($gold) + 100*intval($silver) + intval($copper);
				$current_result["unitBuyout"] = intdiv($current_result["buyout"], $current_result["quantity"]);

				$current_result["timestamp"] = $timestamp;

				$results[] = new search_result($current_result);
			}
			$count = intval($xpath->evaluate("string(//strong[@class='results-total'])"));

			$this->log("Parsed " . count($results) . " results.");

			return array("results" => $results, "count" => $count);
		}
		else {
			$this->log("No search results parsed.");
			return array("results" => array(), "count" => 0);
		}

	}

	/**
	 * Parses the character list and xstoken from an HTTP response from ..../auction/index 
	 *
	 * @param  string $response An HTTP response body
	 */
	private function parse_ah_index($response) {
		$this->log("Parsing auction house index.");
		$doc = new \DomDocument;
		//Hack to deal with utf8 properly
		$doc->loadHTML('<?xml encoding="utf-8" ?>' . $response->body);
		$this->handle_libxml_errors(libxml_get_errors(), "auction house index");

		$xpath = new \DOMXpath($doc);
		$nodelist = $xpath->query("//div[@class='char-wrapper']/a[contains(@href,'character')]");
		if (count($nodelist) > 0 && $nodelist !== false) {
			$chars = array();
			foreach($nodelist as $node) {
				$href = explode("/", $node->getAttribute("href"));
				$charname = $href[5] . "-" . $href[4];

				$onclick = $node->getAttribute("onclick");
				if ($onclick == "") {
					$index = 0;
				}
				else {
					preg_match("/\((\d+),/", $onclick, $matches);
					$index = $matches[1];
				}
				$chars[$index] = $charname;
				$this->log("Found character $index: " . $charname);
			}
		}
		else {
			throw new Exception("Parsing of auction house index did not return any characters.", $this->last_http);
		}

		$this->character_list = $chars;

		//This comes from a cookie, but is also output on the page as CDATA
		//Could also pull from cookiejar, or "Cookies:"" header field
		$this->log("Parsing xstoken.");
		$pattern = "/var xsToken = '([a-z0-9\-]+)';/";

		if (!preg_match($pattern, $response->body, $matches)) {
			throw new Exception("Could not parse xstoken from auction house index.", $this->last_http);
		}
		else {
			$this->xstoken = $matches[1];
			$this->log("xstoken: " . $this->xstoken);
		}

	}

	/**
	 * Generates a browser fingerprint
	 *
	 * In constructing the API, information must be supplied describing the browser
	 * the API is to mimic. During login, the website generates a fingerprint for the browser
	 * using javascript.
	 *
	 * <p>The strategy is to use random results for complicated keys, since they're not likely to be reversed,
     * but actually hash more regular things like useragent, language, etc.</p>
	 * <p>Element 2 is always "24", 10 is always "false", 11 is always "0;false;false"</p>
	 * <p>The whole structure is sent as a JSON encoded array</p>
	 * 
	 * <p>Each element is a base64 encoded murmur3 hash (x86,32bit) of the following fingerprintjs2 sources (.join()'ed with ';' so everything is a string):</p>
	 * + 0: userAgentKey (e.g. "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:48.0) Gecko/20100101 Firefox/48.0")
	 * + 1: languageKey (e.g. "en-US"),
	 * + 2: colorDepthKey (e.g. 24)
	 * + 3: screenResolutionKey (e.g. [1200, 1920],[1200,1920])
	 * + 4: timezoneOffsetKey (e.g. 420, from (new Date).getTimezoneOffset, which is minutes behind GMT)
	 * + 5: cpuClassKey (e.g. "navigatorCpuClass: unknown")
	 * + 6: platformKey (e.g. "navigatorPlatform: Win32")
	 * + 7: pluginsKey (e.g. "ActiveTouch General Plugin Container::ActiveTouch General Plugin Container Version 105::application/x-atgpc-plugin~gpc;")"
	 * + 10: getHasLiedLanguages() || getHasLiedResolution() || getHasLiedOs() || getHasLiedBrowser() (send "false")
	 * + 11: touchSupportKey (e.g. [0, false, false]),
	 * + 12: fontsKey (e.g. "Aharoni;Algerian;Andalus;Angsana New;AngsanaUPC;Aparajita;Arabic Typesetting;Arial")
	 *
	 * @param  object $browser_info A browser_info object
	 *
	 * @return string               JSON representation of the fingerprint
	 */
	private function generate_fp($browser_info) {
		$this->log("Generating fp.");
		$fp[0] = murmurhash3_base64($browser_info->useragent);
		$fp[1] = murmurhash3_base64($browser_info->language);
		$fp[2] = "CEURno";
		$fp[3] = murmurhash3_base64(min($browser_info->resolution) . "," . max($browser_info->resolution) . ";" . min($browser_info->resolution) . "," . max($browser_info->resolution));
		$fp[4] = murmurhash3_base64(sprintf("%u", $browser_info->timezoneoffset));
		$fp[5] = murmurhash3_base64(md5($browser_info->randomseed . "salt1"));
		$fp[6] = murmurhash3_base64(md5($browser_info->randomseed . "salt2"));
		$fp[7] = murmurhash3_base64(md5($browser_info->randomseed . "salt3"));
		$fp[10] = "DVYZ4X";
		$fp[11] = "DXpz63";
		$fp[12] = murmurhash3_base64(md5($browser_info->randomseed . "salt4"));

		$fp_json = str_replace("\\","",json_encode($fp));
		$this->log("fp: " . $fp_json);
		return $fp_json;
	}


	/**
	 * Makes an HTTP Request
	 *
	 * Low-level method to actually make HTTP requests using cURL. Keeps track of how many 302 redirects
	 * to the login page are received so the API can detect when the server thinks it is
	 * no longer logged in. POST requests are automatically generated as needed if $postdata
	 * is not false.
	 *
	 * @param  string  $url      The URL to make a request to
	 * @param  array   $header   An array of additional headers where keys are the header, and values are the header value (i.e. array("Accept-Language" => "en"))
	 * @param  boolean $referer  The URL of the referer to use or false to leave it out
	 * @param  boolean $postdata A url-encoded string for data in POST requests
	 *
	 * @see http_transaction
	 *
	 * @return object            An http_transaction object containing request and response information
	 */
	private function make_request($url, $header = array(), $referer = false, $postdata = false) {
		//If nothing is submitted, empty-string postdata for the http_transaction object
		//And set a flag to false
		if ($postdata === false) {
			$post = false;
			$postdata = "";
		}
		else {
			$post = true;
		}
		$this->log("Sending " . ($post ? "POST" : "GET") . " request to: $url");

		//Construct custom headers
		if(!array_key_exists("Accept", $header))
			$header = array_merge($header, array("Accept" => "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"));

		$header = array_merge($header, array("Connection" => "keep-alive"));

		$header_str = $this->browser_info->headers == "" ? "" : ($this->browser_info->headers . "\n");
		foreach($header as $key => $value) {
			$header_str .= "$key: $value\n";
		}
		$header = explode("\n", $header_str);

		curl_setopt_array($this->ch, array(CURLOPT_URL => $url,
										   CURLOPT_POST => $post,
										   CURLOPT_HTTPHEADER => $header));

		if ($post)
			curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postdata);
		if ($referer)
			curl_setopt($this->ch, CURLOPT_REFERER, $referer);

		$response = curl_exec($this->ch);

		if ($response === false)
			throw new Exception("curl request to `$url` failed. curl errorno: " . curl_errno($this->ch) .  ". curl error: " . curl_error($this->ch), array());

		$header = trim(substr($response, 0, strpos($response,"\r\n\r\n")));
		$body = trim(substr($response, strpos($response,"\r\n\r\n")+4));
		$info = curl_getinfo($this->ch);
		$request_header = trim($info["request_header"]);
		$http_code = $info["http_code"];
		$redirect_url = $info["redirect_url"];
		$last_url = $info["url"];

		$this->last_http = new http_transaction(compact("header", "body", "request_header", "http_code", "last_url", "postdata"));

		if (LOG_HTTP)
			$this->log($this->last_http->generate_log_string());

		if ($http_code == 302 && stristr($redirect_url,"login")) {
			$this->login_redirects++;
			if ($this->login_redirects > LOGIN_REDIRECT_LIMIT) {
				$this->log("make_request() detected too many HTTP Code 302's redirecting to a login page. Assuming logged out.");
				$this->logged_in = false;
				$this->login_redirects = 0;
			}
		}
		else {
			$this->login_redirects = 0;
		}

		return $this->last_http;

	}

	/**
	 * A custom function to handle errors from libxml by throwing wow_ah\Exceptions on fatal errors and logging the rest
	 *
	 * @param  array  $dom_errors   An array of the errors from libxml
	 * @param  string $being_parsed A description of what is being parsed, to be used in log and error messages
	 */
	private function handle_libxml_errors($dom_errors, $being_parsed) {
		if (count($dom_errors) > 0) {
			$fatal_log_string = "";
			foreach ($dom_errors as $error) {
				if ($error->level == LIBXML_ERR_FATAL) {
					$fatal_log_string .= "{$error->code}: " . trim($error->message) . " ";
				}
				else {
					$this->log("libxml nonfatal error while parsing $being_parsed - {$error->code}: " . trim($error->message));
				}
			}
			if ($fatal_log_string != "") {
				throw new Exception("libxml fatal error(s) while parsing $being_parsed - " . trim($fatal_log_string), $this->last_http);
			}
		}
	}

	/**
	 * Convenience function for warnings
	 *
	 * @param  string $entry The text of the warning
	 */
	private function warn($entry) {
		$this->log("WARNING: $entry");
	}

	/**
	 * Add an entry to the log
	 *
	 * Log entries are buffered and flushed at a minimum specified interval (LOG_API_INTERVAL)
	 * to avoid a disk write bottleneck when the API is used very fast. The logs only get flushed
	 * when a new log entry is generated, so it is possible to lose entries if the API shuts down
	 * unexpectedly. flush_log() can be used to manually flush the buffer, and is automatically
	 * called by close().
	 *
	 * @param  string $entry The log entry
	 */
	private function log($entry) {
		if (LOG_API) {
			$this->log[] = date("[m-d-y H:i:s] ") . $entry . PHP_EOL;
			$now = time();
			if ($now > $this->last_log_time + LOG_API_INTERVAL) {
				file_put_contents($this->logfile, implode("", $this->log), FILE_APPEND);
				$this->log = array();
				$this->last_log_time = $now;
			}
		}
	}

	/**
	 * Manually flushes the log buffer to disk
	 */
	public function flush_log() {
		if (LOG_API) {
			file_put_contents($this->logfile, implode("", $this->log), FILE_APPEND);
			$this->log = array();
			$this->last_log_time = time();
		}
	}

} //class wow_ah

/**
 * A wow_ah extension of object_from_array that throws wow_ah\exceptions when errors are encountered
 *
 * @see \object_from_array
 */
class object_from_array extends \object_from_array {
	/** 
	 * Throws a wow_ah\Exception with the \object_from_array error message
	 *
	 * @param string $message The message string from \object_from_array
	 */
	protected function error($message) {
		throw new Exception($message, array(), Exception::ERROR_OBJECT_FROM_ARRAY_CONSTRUCTION);
	}
}

/**
 * The result of an HTTP, used by wow_ah's make_request()
 *
 * The result of an HTTP, used by wow_ah's make_request()
 */
class http_transaction extends object_from_array {
	/** @var string The HTTP response header */
	public $header = "";
	/** @var string The HTTP response body */
	public $body = "";
	/** @var string The final HTTP request header send by cURL */
	public $request_header = "";
	/** @var integer The HTTP code returned by the final request by cURL */
	public $http_code = 0;
	/** @var string The last URL requested by cURL (in cases of redirects) */
	public $last_url = "";
	/** @var string The POST request data, if sent */
	public $postdata = "";

	/**
	 * Generates a readable string that can be added to the log file representing this transaction
	 *
	 * @return string A string containing the request header, response header and body
	 */
	public function generate_log_string() {
		$log_string = "[HTTP Transaction]" . PHP_EOL . "[Request]" . PHP_EOL . $this->request_header . PHP_EOL;
		if ($this->postdata != "")
			$log_string .= PHP_EOL . $this->postdata . PHP_EOL;
		if ($this->header != "") {
			$log_string .= PHP_EOL . "[Response]" . PHP_EOL . $this->header;
			if ($this->body != "")
				$log_string .= "\r\n\r\n" . $this->body . PHP_EOL;
			else
				$log_string .= PHP_EOL;
		}

		return $log_string;
	}
}

/**
 * A single auction from a search
 *
 * A single auction from a search
 */
class search_result extends object_from_array {
	/** @var integer The auction id */
	public $id = 0;
	/** @var integer The item id */
	public $item_id = 0;
	/** @var string The item name */
	public $item_name = "";
	/** @var string The seller's name */
	public $seller = "";
	/** @var integer The quantity of the item */
	public $quantity = 0;
	/** @var string The time remaining in the auction ("Short", "Medium", "Long", "Very long") */
	public $time = "";
	/** @var integer The minimum bid, in copper */
	public $bid = 0;
	/** @var integer The minimum bid per unit, in copper (calculated by API) */
	public $unitBid = 0;
	/** @var integer The buyout, in copper */
	public $buyout = 0;
	/** @var integer The buyout per unit, in copper (calculated by API) */
	public $unitBuyout = 0;
	/** @var string Timestamp of the search result, specified by the user, in mysql format */
	public $timestamp = "";
}

/**
 * Represents the browser the API will mimic.
 *
 * Represents the browser the API will mimic.
 *
 * @see wow_ah::generate_fp()
 */
class browser_info extends object_from_array {
	/** @var array The user need not specify additional browser headers */
	protected static $optional = array("headers");

	/** @var string Sent as the User-Agent header */
	public $useragent = "";
	/** @var string A language string used for fingerprint generation (e.g. "en-US" ) */
	public $language = "";
	/** @var array The browser resolution used for fingerprint generation (e.g. array(1080, 1920)) */
	public $resolution = array(0, 0);
	/** @var integer The timezone offset (e.g. 420, from javscript's (new Date).getTimezoneOffset, which is minutes behind GMT)  */
	public $timezoneoffset = 0;
	/** @var string A random seed used to generate certain values in the fingerprint */
	public $randomseed = "";
	/** @var string Additional headers sent by cURL as `Header`:`value`, separated by \n (e.g. "Accept-Language: en-US,en;q=0.5\nDNT: 1")*/
	public $headers = "";
}

/**
 * An item in the character's inventory
 */
class inventory_item extends object_from_array {
	/** @var integer The item id */
	public $id = 0;
	/** @var string The item name */
	public $name = "";
	/** @var integer The item quality (0 = Poor, 1 = Uncommon, 2 = Common, 3 = Rare, 4 = Epic) */
	public $quality = 0;
	/** @var integer The max quantity per stack */
	public $maxQty = 0;
	/** @var integer The total number in the inventory */
	public $q0 = 0;
	/** @var integer The total number in the character's bags */
	public $q1 = 0;
	/** @var integer The total number in the character's bank */
	public $q2 = 0;
	/** @var integer The total number in the character's mail */
	public $q3 = 0;
}

/**
 * Container for all of the selected characters auctions
 */
class auctions {
	/** @var array An indexed array of active_auction objects */
	public $active = array();
	/** @var array An indexed array of sold_auction objects */
	public $sold = array();
	/** @var array An indexed array of ended_auction objects */
	public $ended = array();
}

/**
 * An active auction listed by the selected character
 *
 * An active auction listed by the selected character
 */
class active_auction extends object_from_array {
	/** @var integer The auction id */
	public $id = 0;
	/** @var integer The item id */
	public $item_id = 0;
	/** @var string The item name */
	public $item_name = "";
	/** @var integer The quantity of the item */
	public $quantity = 0;
	/** @var string The time remaining in the auction ("Short", "Medium", "Long", "Very long") */
	public $time = "";
	/** @var string The high bidder of the auction */
	public $high_bidder = "";
	/** @var integer The minimum bid, in copper */
	public $bid = 0;
	/** @var integer The minimum bid per unit, in copper (calculated by API) */
	public $unitBid = 0;
	/** @var integer The buyout, in copper */
	public $buyout = 0;
	/** @var integer The buyout per unit, in copper (calculated by API) */
	public $unitBuyout = 0;
}

/**
 * A sold auction listed by the selected character
 *
 * A sold auction listed by the selected character
 */
class sold_auction extends object_from_array {
	/** @var array mail id's do not exist for sold auctions that are not claimable */
	protected static $optional = array("mail_id");

	/** @var integer The auction id */
	public $id = 0;
	/** @var integer The item id */
	public $item_id = 0;
	/** @var string The item name */
	public $item_name = "";
	/** @var integer The quantity of the item */
	public $quantity = 0;
	/** @var int The level required to use the item */
	public $level = 0;
	/** @var string The winner of the auction */
	public $buyer = "";
	/** @var integer The amount of money earned */
	public $price = 0;
	/** @var boolean Whether the earned money is claimable */
	public $claimable = false;
	/** @var integer The id of the mail containing earned money */
	public $mail_id = 0;

}

/**
 * An ended auction previously listed by the selected character
 *
 * An ended auction previously listed by the selected character
 */
class ended_auction extends object_from_array {
	/** @var integer The auction id */
	public $id = 0;
	/** @var integer The item id */
	public $item_id = 0;
	/** @var string The item name */
	public $item_name = "";
	/** @var integer The quantity of the item */
	public $quantity = 0;
	/** @var int The level required to use the item */
	public $level = 0;
	/** @var string The status of the auction */
	public $status = "";
	/** @var integer The time left before the returned item in the mail expires (in ms)*/
	public $time = 0;
}

/**
 * A custom exception for wow_ah
 *
 * The wow_ah\Exception stores the last http transaction, and allows for 
 * chaining of exceptions, so an error can be traced through the API. The last http
 * transaction is automatically passed up the chain unless a new link has a transaction
 * specified. Exception messages are automatically logged to the file set by set_logfile().
 *
 * Error codes can be or'd with the ERROR_BUT_RETRY when the API suggests the operation did not "hard" fail
 * (e.g. redirect to login page, which happens intermittently but regularly for no reason)
 */
class Exception extends \Exception {
	/** Error flag that indicates the operation should be retried (e.g. received incorrect 302 redirect to login page) */
	const ERROR_BUT_RETRY = 1;
	const ERROR_CHAR_IN_GAME = 2;
	const ERROR_BID_TOO_LOW = 4;
	const ERROR_AUCTION_NOT_FOUND = 6;
	const ERROR_ACCOUNT_DISABLED = 8;
	const ERROR_OBJECT_FROM_ARRAY_CONSTRUCTION = 10;
	const ERROR_UNEXPECTED_HTTP_CODE = 12;

	/** @var object An http_transaction object for the last transaction before the exception was thrown */
	private $last_http;
	/** @var string Location of the error log file */
	protected static $logfile = "";

	/**
	 * Creates the exception
	 *
	 * @param string  $message   The exception message
	 * @param object  $last_http An http_transaction for the last transaction made before the exception was thrown
	 * @param integer $code      The error code
	 * @param object  $previous  The previous exception, if any
	 *
	 * @see http_transaction
	 */
	public function __construct($message, $last_http, $code = 0, $previous = NULL) {
		file_put_contents(static::$logfile, date("[m-d-y H:i:s] ") . "ERROR: " . $message . PHP_EOL, FILE_APPEND);
		if (!LOG_HTTP) {
			if (!empty((array) $last_http)) {
				$this->last_http = $last_http;
			}
			else if (!is_null($previous) && isset($previous->last_http) && !empty((array) $previous->last_http)) {
				$this->last_http = $previous->last_http;
			} 			
		}

		if (!is_null($previous) && $previous->getCode() & self::ERROR_BUT_RETRY)
			parent::__construct($message, $code | self::ERROR_BUT_RETRY, $previous);
		else
			parent::__construct($message, $code, $previous);

	}

	/**
	 * Return all exception messages in a chain
	 *
	 * @return array An indexed array of exception messages
	 */
	public function getAllMessages() {
		$messages[] = $this->getMessage();
		$previous = $this->getPrevious();
		while (!is_null($previous)) {
			$messages[] = $previous->getMessage();
			$previous = $previous->getPrevious();
		}

		return $messages;

	}

	/**
	 * Logs the last http transaction made before the exception in case LOG_HTTP is false
	 */
	public function log_last_http() {
		if (!LOG_HTTP && !empty((array) $this->last_http) && self::$logfile != "") {
			file_put_contents(self::$logfile, date("[m-d-y H:i:s] ") . "Dumping last HTTP transaction." . PHP_EOL . $this->last_http->generate_log_string(), FILE_APPEND);
		}
	}

	/**
	 * Sets the file where exception messages are logged
	 *
	 * @param string $logfile The location of the log file
	 */
	static public function set_logfile($logfile) {
		if (static::$logfile == "")
			static::$logfile = $logfile;
	}

} //class Exception

?>