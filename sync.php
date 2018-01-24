<?php
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
	$results =
		[
			0 => array(array()),
			1 => array(array()),
			2 => array(array()),
			3 => array(array()),
			4 => array(array())
		];
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
			$result['availability'] = trim($cols->item(5)->nodeValue);
			$result['price'] = trim($cols->item(6)->nodeValue);

			array_push($results[$i], $result);
		}
	}
	return $results;
}

// $results = getOnlineServers($ONLINE_PRODUCTS_LINK);

// for($i=0; $i <= 4; $i++)
// {
// 	foreach($results[$i] as $r)
// 	{
// 		echo $r['offer'] . " - " . $r['cpu'] . " - " . $r['memory'] . " - " . $r['connectivity'] . " - " . $r['availability'] . " - " . $r['price'] . "<br>";
// 	}
// 	break;
// }

# Update database
/**
 *
 */
function updateDatabase($onlineQty)
{
	$db_host = 'localhost';
	$db_username = 'whmcs';
	$db_password = 'Rdn36s9@rertretr';
	$db_name = 'whmcs';

	$conn = new mysqli($db_host, $db_username, $db_password, $db_name);
	if( $conn->connect_error )
	{
		die("Connection failed: " . $conn->connect_error);
	}

	# Bridging elements - ProductID with server names
	$bridge =
		[
			2 => "Dedibox SC SATA 2016",
			5 => "Dedibox LT SSD 2017",
			7 => "Dedibox MD SSD 2017",
			8 => "Dedibox PRO 2016",
			9 => "Dedibox ENT SSD 2015 Gen2",
			10 => "Dedibox mWOPR SATA 2015 Gen2",
			11 => "Dedibox WOPR SATA 2015 Gen2",
			13 => "Dedibox ST 18 2017",
			16 => "Dedibox PST 24 2017",
			17 => "Dedibox ST72v2",
			33 => "Dedibox XC SSD 2016",
			34 => "Dedibox XC SATA 2016",
			36 => "Dedibox Classic 2016",
			37 => "Dedibox ST12 SSD 2016",
			40 => "Dedibox ST8 SATA 2016",

		];

	# First of all, alter online.net results by adding the servers available from
	#############################################################################
	$query_str = "SELECT * FROM `tblservers` WHERE `type` = 'online'";
	$result = $conn->query($query_str);

	if( !$result )
		return "ERR_QUERY_FAIL";

	if( $result->num_rows <= 0 )
		return "ERR_NO_PROD_IDENTIFIERS";

	while( $row = $result->fetch_assoc() )
	{
		$ramQty = strtolower(explode("_", $row['name'])[1]);
		$diskQty = strtolower(explode("_", $row['name'])[2]);
		$diskType = (strpos($diskQty, "hdd") !== false)?"hdd":"ssd";

		#Convert quantities to int comparable int values
		$ramQty = explode("gb", $ramQty)[0] . "gb";
		$diskQty = str_replace('hdd', '', $diskQty);
		$diskQty = str_replace('ssd', '', $diskQty);

		# echo $ramQty . " -> " . $diskQty . " -> " . $diskType . "<br>";
		# echo $ramQty . " -> " . $diskQty . " -> " . $diskType . "<br>";

		$found = false;
		for($i=0; $i <=4; $i++)
		{
			foreach($onlineQty[$i] as $product)
			{

				$ramQtyOnline = str_replace('go', 'gb', strtolower(str_replace(' ', '', $product['memory'])));
				$diskQtyOnline = str_replace('go', 'gb', str_replace(" x ", "x", strtolower($product['disk'])));
				$diskTypeOnline = "hdd";//(explode(" ", $diskQtyOnline)[2] == "")?"hdd":"ssd";

				echo $ramQtyOnline . "->" . $diskQtyOnline . " -> " . $diskTypeOnline . "<br>";

				//$found = true;
				//break;
			}
		}
		if($found == true)
			break;
	}
	break;
}

return;
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
			if( trim($product['offer']) == trim($bridge[$id]) )
			{
				echo "found it: " . $id . " -> " . $product['offer'] . " -> " . ( is_numeric($product['availability']) ? $product['availability'] : "0" ) . " -> " . $product['memory'] . " -> " . $product["disk"] . "<br>";
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
updateDatabase($online_offers);