<?php
/**
 * wow_ah_test.php is a simple command line interface for testing the wow_ah API
 *
 * Commands:
 * + help
 * + login:`username`:`password`
 * + get_character_list
 * + select_character:`#`
 * + get_money
 * + get_mail
 * + get_mail_info
 * + get_character
 * + update_inventory
 * + update_auctions
 * + search:`name`:`category`:`minlevel`:`maxlevel`:`quality`:`sort`:`reverse`:`limit`:`item_id`
 * + bid:`auction_id`:`amount`
 * + create_auction:`item_id`:`duration`:`quantity`:`stacks`:`buyout per item`:`bid per item`
 * + cancel_auction:`auction_id`
 * + take_money:`mail_ids`
 * + take_all_money
 * + flush_log
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

require("wow_ah.class.php");

$browser = array(
	"useragent"      => "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:48.0) Gecko/20100101 Firefox/48.0",
	"language"       => "en-US",
	"resolution"     => array(1080, 1920),
	"timezoneoffset" => 420,
	"randomseed"     => "somesalt",
	"headers"        => "Accept-Language: en-US,en;q=0.5\nDNT: 1"
);

$commands = array(
	"login:<username>:<password>",
	"get_character_list",
	"select_character:<#>",
	"get_money",
	"get_mail",
	"get_mail_info",
	"get_character",
	"update_inventory",
	"update_auctions",
	"search:<name>:<category>:<minlevel>:<maxlevel>:<quality>:<sort>:<reverse>:<limit>:<item_id>",
	"bid:<auction_id>:<amount>",
	"create_auction:<item_id>:<duration>:<quantity>:<stacks>:<buyout per item>:<bid per item>",
	"cancel_auction:<auction_id>",
	"take_money:<mail_ids>",
	"take_all_money",
	"flush_log"
);

try {
	$wow_ah = new wow_ah("us","en","wow_ah_test_cookiejar.txt", "wow_ah_test_log.txt", $browser);
}
catch (Exception $e) {
	$e->log_last_http();
	die("Error constructing API: " . implode(" ", $e->getAllMessages()) . "\n");
}

if (!$wow_ah->is_logged_in()) {
	echo "Session not restored. Please log in using login:<username>:<password>\n";
}
else {
	echo "Session restored. Characters:\n";
	foreach($wow_ah->get_character_list() as $index => $char)
		echo "$index: $char\n";
}

while(true) {
	$line = trim(fgets(STDIN));
	$terms = explode(":", $line);

	try {
		if ($terms[0] == "login") {
			try {
				echo "Logging in...\n";
				$wow_ah->login($terms[1], $terms[2]);
				echo "Done. Retrieving characters.\nCharacters:\n";
				foreach($wow_ah->get_character_list() as $index => $char)
					echo "$index: $char\n";
				}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error logging in: " . implode(" ", $e->getAllMessages()) . "\n";
			}
		}
		else if ($terms[0] == "get_character_list") {
			foreach($wow_ah->get_character_list() as $index => $char)
				echo "$index: $char\n";
		}
		else if ($terms[0] == "select_character") {
			try {
				$wow_ah->select_character($terms[1]);
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error selecting character: " . implode(" ", $e->getAllMessages()) . "\n";
			}
			echo "Selected character {$terms[1]}\n";
			foreach($wow_ah->get_character_list() as $index => $char)
				echo "$index: $char\n";
		}
		else if ($terms[0] == "get_money") {
			echo $wow_ah::format_money_string($wow_ah->get_money()) . "\n";
		}
		else if ($terms[0] == "get_mail") {
			print_r($wow_ah->get_mail());
			echo "\n";
		}
		else if ($terms[0] == "get_mail_info") {
			print_r($wow_ah->get_mail_info());
			echo "\n";
		}
		else if ($terms[0] == "get_character") {
			print_r($wow_ah->get_character());
			echo "\n";
		}
		else if ($terms[0] == "take_money") {
			try {
				$money_obtained = $wow_ah->take_money($terms[1]);
				echo "Took " . $wow_ah::format_money_string($money_obtained) . "g.\n";
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error taking money: " . implode(" ", $e->getAllMessages()) . "\n";
			}
			echo "\n";
		}
		else if ($terms[0] == "take_all_money") {
			try {
				$money_obtained = $wow_ah->take_all_money();
				if ($money_obtained == 0)
					echo "Nothing to take money from\n";
				else
					echo "Took " . $wow_ah::format_money_string($money_obtained) . "g.\n";
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error taking all money: " . implode(" ", $e->getAllMessages()) . "\n";
			}
			echo "\n";
		}
		else if ($terms[0] == "search") {
			try {
				$search = $wow_ah->search($terms[1], $terms[2], $terms[3], $terms[4], $terms[5], $terms[6], $terms[7] == "false" ? false : true, $terms[8],date("Y-m-d H:i:s"), $terms[9]);
				foreach ($search as $result) {
					foreach ($result as $key => $value) {
						echo "$key: $value, ";
					}
					echo "\n";
				}
				echo "Returned " . count($search) . " results.\n";
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error searching: " . implode(" ", $e->getAllMessages()) . "\n";
				if (!$wow_ah->is_logged_in()) {
					echo "Not logged in. Attempting to login.\n";
					try {
						$wow_ah->login(openssl_decrypt($login["username"], "aes256", "wow_ah", 0, "1234567812345678"),openssl_decrypt($login["password"], "aes256", "wow_ah", 0, "1234567812345678"));
					}
					catch (Exception $e) {
						echo "Error searching: " . implode(" ", $e->getAllMessages()) . "\n";
					}
				}
			}	
		}
		else if ($terms[0] == "bid") {
			try {
				if ($wow_ah->bid($terms[1], $terms[2]))
					echo "Auction won.\n";
				else
					echo "Bid accepted.\n";
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error placing bid: " . implode(" ", $e->getAllMessages()) . "\n";
			}
		}
		else if ($terms[0] == "update_inventory") {
			try {
				foreach ($wow_ah->update_inventory() as $item) {
					foreach ($item as $key => $value) {
						echo "$key: $value, ";
					}
					echo "\n";
				}
				
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error updating inventory: " . implode(" ", $e->getAllMessages()) . "\n";
			}
		}
		else if ($terms[0] == "update_auctions") {
			try {
				$auctions = $wow_ah->update_auctions();
				echo "Active:\n";
				foreach ($auctions->active as $auction) {
					foreach ($auction as $key => $value) {
						echo "$key: $value, ";
					}
					echo "\n";
				}
				echo "Sold:\n";
				foreach ($auctions->sold as $auction) {
					foreach ($auction as $key => $value) {
						echo "$key: $value, ";
					}
					echo "\n";
				}
				echo "Ended:\n";
				foreach ($auctions->ended as $auction) {
					foreach ($auction as $key => $value) {
						echo "$key: $value, ";
					}
					echo "\n";
				}
				
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error updating auctions: " . implode(" ", $e->getAllMessages()) . "\n";
			}
		}
		else if ($terms[0] == "create_auction") {
			try {
				$wow_ah->create_auction($terms[1], $terms[2], $terms[3], $terms[4], $terms[5], $terms[6], true, 0);
				echo "Created auctions.\n";
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error creating auction: " . implode(" ", $e->getAllMessages()) . "\n";
			}
		}
		else if ($terms[0] == "cancel_auction") {
			try {
				$wow_ah->cancel_auction($terms[1]);
				echo "Canceled auction {$terms[1]}.\n";
			}
			catch (Exception $e) {
				$e->log_last_http();
				echo "Error canceling auction: " . implode(" ", $e->getAllMessages()) . "\n";
			}
		}
		else if ($terms[0] == "flush_log") {
			$wow_ah->flush_log();
			echo "Flushed log\n";
		}
		else if ($terms[0] == "help") {
			echo "Commands:\n\n";
			foreach ($commands as $command) {
				echo $command . "\n";
			}
		}
		else if ($terms[0] == "exit") {
			$wow_ah->close();
			die();
		}
		else {
			echo "Unknown command\n";
		}
	} //try
	catch (\Exception $e) {
		echo "Caught exception: " . $e->getMessage();
	}
} //while

?>