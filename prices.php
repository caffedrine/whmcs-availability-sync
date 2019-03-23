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
	libxml_use_internal_errors(true);
	$dom->loadHTML($htmlstring);
	$errors = libxml_get_errors();
	libxml_clear_errors();
	
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
			"bandwidth" => "",
			"price" => ""
		];

	# Servers table class name to get elements by
	$SERVERS_CLASS_NAME = "server-availability col-striped";

	# store every table with servers in this array
	$serversTablesHTML = getHTMLElementsByClass("$SERVERS_CLASS_NAME", $source);

	for($i = 0; $i < count($serversTablesHTML); $i++)
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
			$result['bandwidth'] = trim($cols->item(5)->nodeValue);
			$result['availability'] = is_numeric(trim($cols->item(6)->nodeValue))?trim($cols->item(6)->nodeValue):"0";
			$result['price'] = trim($cols->item(7)->nodeValue);

			$not_duplicate = true;
			foreach ($results as $index) 
			{
				foreach($index as $res)
				{
					if( isset($resp['offer']) && $res['offer'] == $result['offer'])
					{
						$not_duplicate = false;
						$res['availability'] += $result['availability'];
					}
				}
			}
			if($not_duplicate)
				array_push($results[$i], $result);
		}
	}
	return $results;
}

# Debug retrieved servers
$results = getOnlineServers($ONLINE_PRODUCTS_LINK);	// Format: $result[servers_table][index]['param']
foreach($results as $index)
{
	foreach( $index as $r )
		//print_r($index);
		if(  isset($r['offer']) ) 
		echo $r['offer'] . " \t " . $r['availability'] . " \t " . $r['memory'] . " \t " . $r['disk'] . " \t " . $r['connectivity'] . " \t " . $r['cpu'] . " \t " . $r['price'] . "\n";
}
exit(1);