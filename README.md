# wow_ah - A php api for the World Of Warcraft Auction House website
wow_ah is a complete php api for the WoW Armory AH by https://github.com/kaysond that is carefully designed to mimic a normal browser.
It supports all of the features of the website and comes with an example command line interface for the api.
Errors are handled through a custom exception, and while everything has been tested, there are likely some scenarios that are not yet handled explicitly. Enabling logging can help debug.

## Features
 * Saves login session
 * AH Searching
 * Place bids/buyouts
 * Create auctions
 * Cancel auctions
 * Claim money from mailbox
 * Check auctions and inventory
 * Logging

## Documentation
Full documentation is in the docs folder. Also in the code.

### Summary of selected API methods
 * `is_logged_in()` - Returns true if the API has a valid login session (it attempts to save sessions between uses)
 * `login()` - Logs the user in
 * `get_character_list()` - Lists the characters on the account
 * `select_character()` - Chooses the character to use for transactions
 * `search()` - Perform a search of the AH
 * `bid()` - Bid on or buyout an auction
 * `update_inventory()` - Get the selected character's bags, mail, and bank inventory
 * `create_auction()` - Create one or more auctions for an item (supports splitting into stacks)
 * `update_auctions()` - Get the selected character's active, sold, and ended auctions
 * `cancel_auction()` - Cancel a specific auction
 * `take_money()` - Take the money from a specific piece of mail
 * `take_all_money()` - Take all money in the selected character's mailbox
 * `close()` - Shuts down the API cleanly, especially saving the cookies to a file. Use this!

## Command line Interface
The command line test interface is implemented in wow_ah_test.php. Run with `php -f wow_ah_test.php` and enter `help` to see commands.

## Requirements
 * php7
 * curl
 * openssl
 * libxml
 * [v8js](https://github.com/phpv8/v8js)
 * [murmurhash3](https://github.com/lastguest/murmurhash-php)

[Pre-compiled 32-bit and 64-bit Windows php binaries](https://www.apachelounge.com/viewtopic.php?t=6359)


## Example usage

```php
require("wow_ah.class.php");

$browser = array(
	"useragent"      => "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:50.0) Gecko/20100101 Firefox/50.0",
	"language"       => "en-US",
	"resolution"     => array(1080, 1920),
	"timezoneoffset" => 480,
	"randomseed"     => "fiftydkpminus",
	"headers"        => "Accept-Language: en-US,en;q=0.5\nDNT: 1"
);

try {
	$wow_ah = new wow_ah\wow_ah("us","en","cookiejar.txt", "log.txt", $browser);
	$wow_ah->login("leeroy", "jenkins");

	//Useful if we want to see how much of an item we have already bought
	//and how much is in our inventory, before making purchases
	$wow_ah->update_inventory();
	$wow_ah->update_auctions();

	$wow_ah->take_all_money();

	//Search for the 40 cheapest copper ore auctions
	$results = $wow_ah->search("Copper Ore", "-1", "-1", "-1", "-1", "unitBuyout", false, 40);

	//Buy ore under 1g/unit up to 10 units
	$units_purchased = 0;
	foreach ($results as $result) {
		if ($result->unitBuyout < 10000 && $units_purchased + $result->quantity <= 10) {
			$wow_ah->bid($result->id, $result->buyout);
			$units_purchased += $result->quantity;
		}
		if ($units_purchased == 10)
			break;
	}

}
catch (wow_ah\Exception $e) {
	echo "Caught exception: " . implode(" ", $e->getAllMessages()) . "\n";
}
```