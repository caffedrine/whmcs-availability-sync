<?php

# Only for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

# Database configs
require ("../configuration.php");

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);
if( $conn->connect_error )
{
	die("Connection failed: " . $conn->connect_error);
}

# Setting up headers to enable newlines and tabs
header('Content-Type: text/plain');

/**
 * Fetch products from https://console.online.net/en/order/server then sync with 1way
 */

# Link to fetch servers list
# $ONLINE_PRODUCTS_LINK = "https://console.online.net/en/order/server";
$ONLINE_PRODUCTS_LINK = "https://console.online.net/en/order/server";

# Util function to get html element by class
function getHTMLElementsByClass($classname, $htmlstring, $tags = array('*'))
{
	$contents = array();
	$pattern = "/<([\w]+)([^>]*?)(([\s]*\/>)|(>((([^<]*?|<\!\-\-.*?\-\->)|(?R))*)<\/\\1[\s]*>))/sm";
	$dom = new DOMDocument();
	$libxml_previous_state = libxml_use_internal_errors(true);
	$dom->loadHTML($htmlstring);
	$errors = libxml_get_errors();
	libxml_clear_errors();
	libxml_use_internal_errors($libxml_previous_state);
	$xpath = new DOMXPath($dom);
	foreach($tags as $tagname)
	{
		$elements = $xpath->query('//' . $tagname . '[@class="' . $classname . '"]');
		foreach($elements as $element)
		{
			$elementhtml = $dom->saveXML($element);
			preg_match_all($pattern, $elementhtml, $matches, PREG_OFFSET_CAPTURE);
			foreach($matches[0] as $key => $match)
			{
				$x = new SimpleXMLElement("<element " . ( isset($matches[2][$key][0]) ? $matches[2][$key][0] : '' ) . " />");
				$plaintext = isset($matches[6][$key][0]) ? $matches[6][$key][0] : '';
				$plaintext = preg_replace('/<[^>]*>/', ' ', $plaintext);
				$plaintext = str_replace(array("\r", "\n", "\t", "&#13;"), ' ', $plaintext);
				$plaintext = trim(preg_replace('/ {2,}/', ' ', $plaintext));
				$contents[] = array(
					'tagname' => $matches[1][$key][0],
					'attributes' => current($x->attributes()),
					'outer_html' => $match[0],
					'inner_html' => isset($matches[6][$key][0]) ? $matches[6][$key][0] : '',
					'plain_text' => $plaintext,
					'LibXMLError' => $errors
				);
			}
		}
	}
	return $contents;
}

# Return a three dimensions array with online.net available servers
# Return format: $result[servers_table][index]['param']
function getOnlineServers($link)
{
	# Download source
	$source = file_get_contents($link);
	if( $source == "" || $source == NULL )
		die("ERR_FETCH_SRC");

	# Store results in this variable in a 2 dimensions array: $results[SERVERS_GROUP][INDEX]
	# There are 5 tables on online.net. Every table have an array of servers
	$results =
		[
			0 => array(array()),
			1 => array(array()),
			2 => array(array()),
			3 => array(array()),
			4 => array(array())
		];

	# This is for every server
	$result =
		[
			"offer" => "",
			"cpu" => "",
			"memory" => "",
			"disk" => "",
			"connectivity" => "",
			"availability" => "",
			"price" => ""
		];

	# Servers table class name to get elements by
	$SERVERS_CLASS_NAME = "server-availability col-striped";

	# store every table with servers in this array
	$serversTablesHTML = getHTMLElementsByClass("$SERVERS_CLASS_NAME", $source);

	for($i = 0; $i <= 4; $i++)
	{
		$table_html = $serversTablesHTML[$i]["inner_html"];

		# Load html table so we can parse
		$dom = new DOMDocument();
		$dom->encoding = 'utf-8';
		$dom->loadHTML($table_html);

		$tbody = $dom->getElementsByTagName('tbody');
		$rows = $tbody->item(0)->getElementsByTagName('tr');

		for($j = 0; $j < $rows->length; $j++)
		{
			$cols = $rows->item($j)->getElementsByTagName('td');

			$result['offer'] = trim($cols->item(0)->nodeValue);
			$result['cpu'] = trim($cols->item(1)->nodeValue);
			$result['memory'] = trim($cols->item(2)->nodeValue);
			$result['disk'] = trim($cols->item(3)->nodeValue);
			$result['connectivity'] = trim($cols->item(4)->nodeValue);
			$result['availability'] = is_numeric(trim($cols->item(5)->nodeValue))?trim($cols->item(5)->nodeValue):"0";
			$result['price'] = trim($cols->item(6)->nodeValue);

			array_push($results[$i], $result);
		}
	}
	return $results;
}

# Debug retrieved servers
// $results = getOnlineServers($ONLINE_PRODUCTS_LINK);	// Format: $result[servers_table][index]['param']
// foreach($results as $index)
// {
// 	foreach( $index as $r )
// 		//print_r($index);
// 		if(  isset($r['offer']) )
// 		echo $r['offer'] . " \t " . $r['availability'] . " \t " . $r['memory'] . " \t " . $r['disk'] . " \t " . $r['connectivity'] . " \t " . $r['cpu'] . " \t " . $r['price'] . "\n";
// }
// exit(1);

# Function used to update database
function updateDatabase($onlineQty, mysqli $conn)
{
	# Bridging elements - ProductID with server names
	$bridge =
		[
			2 => "Start-2-S-SATA",

			7 => "Pro-6-S",
			8 => "Pro-4-L",
			9 => "Core-4-S-SATA",

			33 => "Start-2-M-SSD",
			34 => "Start-2-M-SATA",

			36 => "Start-3-L",

			40 => "Store-1-S",

		];

	# First of all, alter online.net results by adding the servers available from Servers menu, on WHMCS
	#############################################################################
	$query_str = "SELECT * FROM `tblservers` WHERE `type` = 'online'";
	$result = $conn->query($query_str);

	if( !$result )
		return "ERR_QUERY_FAIL";

	if( $result->num_rows <= 0 )
		return "ERR_NO_PROD_IDENTIFIERS";

	while( $row = $result->fetch_assoc() )
	{
		if(!isset($row['name']) || $row['name'] == null)
			continue;

		###################################################################################################
		# Also we have to make sure that fetched servers are still available for other clients
		# So check every client if it have this product asigned
		$curr_id = $row['id'];
		$query_str = "SELECT * FROM `tblhosting` WHERE `server` = '$curr_id'";
		$result2 = $conn->query($query_str);
		if( !$result2 )
			return "ERR_QUERY_FAIL";

		if( $result2->num_rows > 0 )	# if server already assigned
			continue;

		###################################################################################################

		# Remove "_Offer" suffix from premium servers
		$row['name'] = strtolower($row['name']);
		$row['name'] - str_replace("_offer", "", $row['name']);

		# Extract configuration os servers retrievede from whmcs
		$ramQty = strtolower(explode("_", $row['name'])[1]); if(!isset($ramQty) || $ramQty == null) continue;
		$diskQty = strtolower(explode("_", $row['name'])[2]);
		$diskType = (strpos($diskQty, "hdd") !== false)?"hdd":"ssd";

		# Convert quantities to int comparable int values
		$ramQty = explode("gb", $ramQty)[0] . "gb";
		$diskQty = str_replace('hdd', '', $diskQty);
		$diskQty = str_replace('ssd', '', $diskQty);

		# Debug
		# echo $curr_id . "\t" . $ramQty . "\t" . $diskQty . "\t" . $diskType . "\n"; continue;

		# Loop through all online.net servers ans see whene quantities have to be summed up
		$found = false;
		for($i=0; $i <=4; $i++)
		{
			foreach($onlineQty[$i] as $product)
			{
				if(!isset($product['offer']) || $product['offer'] == "")
					continue;

				# Debug
				# echo $product['offer'] . " \t " . $product['cpu'] . " \t " . $product['memory'] . " \t " . $product['disk'] . " \t " . $product['connectivity'] . " \t " . $product['availability'] . " \t " . $product['price'] . "\n"; continue;

				$ramQtyOnline = str_replace('go', 'gb', strtolower(str_replace(' ', '', $product['memory'])));
				$diskQtyOnline = trim(str_replace('to', 'tb', str_replace('go', 'gb', str_replace(" x ", "x", strtolower($product['disk'])))));

				$diskTypeOnline = (strpos($diskQtyOnline, 'ssd') !== false)?"ssd":"hdd";


				$diskQtyOnline = str_replace("ssd", "", $diskQtyOnline);

				// calculate total disk
				$disk_split = explode(" ", $diskQtyOnline);

				$unit = $disk_split[1];
				$operands = explode("x", $disk_split[0]);
				$operand1 = $operands[0];
				$operand2 = $operands[1];
				$total = $operand1*$operand2;

				# convert to TB is needed
				if($total >= 1000 && $unit == "gb")
				{
					$total = $total/1000;
					$unit = "tb";
				}

				# write back the calculated sum
				$diskQtyOnline = $total . $unit;

				# Now that we have the some units, now qtty can be compared
				if($ramQty == $ramQtyOnline && $diskQty == $diskQtyOnline && $diskType == $diskTypeOnline)
				{
					# echo $ramQty . "-" . $ramQtyOnline . "___" . $diskQty . "-" . $diskQtyOnline . "___" . $diskType . "-" . $diskTypeOnline . "<br>";

					$curr_array_index = array_search($product, $onlineQty[$i]);

					if(is_numeric($product['availability']))
						$product['availability']++;
					else
						$product['availability'] = 1;

					// Assign new values to array
					$onlineQty[$i][$curr_array_index] = $product;

					$found = true;
					break;
				}
			}
			if($found == true)
				break;
		}
	}


	# Debug - Make sure quantities are ok and the servers from whmcs are summed up with servers from online.net
	// for($i=0; $i<=4; $i++)
	// {
	// 	foreach($onlineQty[$i] as $product)
	// 	{
	// 		if(!isset($product['offer']) || $product['offer'] == "")
	// 			continue;

	// 		# Debug
	// 		echo $product['offer'] . " \t " . $product['availability'] . " \t " . $product['memory'] . " \t " . $product['disk'] . " \t " . $product['connectivity'] . " \t " . $product['cpu'] . " \t " . $product['price'] . "\n"; continue;
	// 	}
	// }


	#############################################################################
	# Retrieve all products IDs
	$query_str = "SELECT * from `tblproducts` WHERE `type` = 'server'";

	$result = $conn->query($query_str);

	if( !$result )
		return "ERR_QUERY_FAIL";

	if( $result->num_rows <= 0 )
		return "ERR_NO_PROD_IDENTIFIERS";

	while( $row = $result->fetch_assoc() )
	{
		$id = (int)$row['id'];
		$found = false;

		if( !isset($bridge[$id]) )
			continue;

		for($i = 0; $i <= 4; $i++)
		{
			foreach($onlineQty[$i] as $product)
			{
				if(!isset($product['offer']) || $product['offer'] == "")
					continue;

				if( trim($product['offer']) == trim($bridge[$id]) )
				{
					# DISPLAY RESULT
					echo $id . " " . (strlen($product['offer'])>23?substr($product['offer'], 0, 17)."...":$product['offer']) . "\t" . ( is_numeric($product['availability']) ? $product['availability'] : "0" ) . "\t" . $product['memory'] . "\t" . $product["disk"] . "\n";

					#### Insert into DB ####
					$newQty = $product['availability'];
					$query_str = "UPDATE `tblproducts` SET `qty` = '$newQty' WHERE `tblproducts`.`id` = '$id';";
					$result2 = $conn->query($query_str);


					########################
					$found = true;
					break;
				}
			}
			if( $found == true )
				break;
		}
		$found = false;
	}
}


$online_offers = getOnlineServers($ONLINE_PRODUCTS_LINK);
updateDatabase($online_offers, $conn);