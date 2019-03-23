<?php

require_once("init.php");

class ServerDesc
{
    public $Offer = "";
    public $Cpu = "";
    public $Memory = "";
    public $Disk = "";
    public $Connectivity = "";
    public $Bandwidth = "";
    public $Traffic = "Unlimited";
    public $Availability = 0;
    public $Price = 10.01;
}

class ServersGroup
{
    public $Id;
    public $Name;
    public $Desc;
    public $Servers = array();
}


class OnlineNetServers
{
    # Link to fetch servers list
    public $ONLINE_PRODUCTS_LINK = "https://console.online.net/en/order/server";

    # Class constructor
    function __construct()
    {

    }

    # Util function to get html element by class
    public function GetServers()
    {
        # Download source
        $source = file_get_contents($this->ONLINE_PRODUCTS_LINK);
        if ($source == "" || $source == NULL)
            die("ERR_FETCH_SRC");

        # Store gropus
        $serverGroups = array();

        # Servers table class name to get elements by
        $SERVERS_CLASS_NAME = "server-availability col-striped";
        # store every table with servers in this array
        $serversTablesHTML = $this->getHTMLElementsByClass("$SERVERS_CLASS_NAME", $source);

        # Loop through each table with servers
        for ($i = 0; $i < count($serversTablesHTML); $i++)
        {
            # Parsing engine
            $dom = new DOMDocument();
            $dom->encoding = 'utf-8';

            # Current group
            $currGroup = new ServersGroup();
            $dom->loadHTML($source);
            $currGroup->Name = $dom->getElementsByTagName('h2')->item($i)->nodeValue;

            # Load tables to be parsed
            $table_html = $serversTablesHTML[$i]["inner_html"];
            $dom->loadHTML( utf8_decode($table_html) );

            # Parse groups tables
            $tbody = $dom->getElementsByTagName('tbody');
            $rows = $tbody->item(0)->getElementsByTagName('tr');

            # Loop through each server from the table
            for ($j = 0; $j < $rows->length; $j++)
            {

                $cols = $rows->item($j)->getElementsByTagName('td');

                $currServer = new ServerDesc();
                $currServer->Offer = trim($cols->item(0)->nodeValue);
                $currServer->Cpu = trim($cols->item(1)->nodeValue);
                $currServer->Memory = trim($cols->item(2)->nodeValue);
                $currServer->Disk = trim($cols->item(3)->nodeValue);
                $currServer->Connectivity = str_replace("  ", " ", trim($cols->item(4)->nodeValue) );
                $currServer->Bandwidth = str_replace("  ", " ", trim($cols->item(5)->nodeValue) );
                $currServer->Availability = is_numeric(trim($cols->item(6)->nodeValue)) ? trim($cols->item(6)->nodeValue) : "0";
                $currServer->Price = $this->toFloat( trim($cols->item(7)->nodeValue) );

                # Check whether the current server was already added - duplicates are because of the multiple locations
                $not_duplicate = true;
                foreach ($currGroup->Servers as $groupServer)
                {
                    if ($groupServer->Offer == $currServer->Offer)
                    {
                        $not_duplicate = false;
                        $groupServer->Availability += $currServer->Availability;
                    }
                }
                if ($not_duplicate)
                    array_push($currGroup->Servers, $currServer); # Add server to it's group
            }
            # Add group to results
            array_push($serverGroups, $currGroup);
        }
        return $serverGroups;
    }

    # Return a three dimensions array with online.net available servers
    # Return format: $result[servers_table][index]['param']
    private function getHTMLElementsByClass($classname, $htmlstring, $tags = array('*'))
    {
        $contents = array();
        $pattern = "/<([\w]+)([^>]*?)(([\s]*\/>)|(>((([^<]*?|<\!\-\-.*?\-\->)|(?R))*)<\/\\1[\s]*>))/sm";
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($htmlstring);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        foreach ($tags as $tagname)
        {
            $elements = $xpath->query('//' . $tagname . '[@class="' . $classname . '"]');
            foreach ($elements as $element)
            {
                $elementhtml = $dom->saveXML($element);
                preg_match_all($pattern, $elementhtml, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as $key => $match)
                {
                    $x = new SimpleXMLElement("<element " . (isset($matches[2][$key][0]) ? $matches[2][$key][0] : '') . " />");
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

    private function toFloat($num)
    {
        $dotPos = strrpos($num, '.');
        $commaPos = strrpos($num, ',');
        $sep = (($dotPos > $commaPos) && $dotPos) ? $dotPos :
            ((($commaPos > $dotPos) && $commaPos) ? $commaPos : false);

        if (!$sep)
        {
            return floatval(preg_replace("/[^0-9]/", "", $num));
        }

        return floatval(
            preg_replace("/[^0-9]/", "", substr($num, 0, $sep)) . '.' .
            preg_replace("/[^0-9]/", "", substr($num, $sep + 1, strlen($num)))
        );
    }
}


/*
	This function will fetch servers from both online.net and whmcs control panel 
	Final servers availability = online.net servers + whmcs cp servers
*/
function FetchServersAvailable(mysqli $conn)
{
    $returnResult =
        [
            'ERR_CODE' => 0,
            'ERR_DESC' => "",
            'RESULT' => array()
        ];

    $OnlineServHandler = new OnlineNetServers();
    $onlineGroupsServers = $OnlineServHandler->GetServers();

    #############################################################################
    $query_str = "SELECT * FROM `tblservers` WHERE `type` = 'online'";
    $result = $conn->query($query_str);
    if (!$result)
    {
        $returnResult['ERR_CODE'] = 1;
        $returnResult['ERR_DESC'] = "ERR_QUERY_FAIL";
        return $returnResult;
    }
    if ($result->num_rows <= 0)
    {
        $returnResult['ERR_CODE'] = 1;
        $returnResult['ERR_DESC'] = "ERR_NO_PROD_IDENTIFIERS";
        return $returnResult;
    }
    while  ($row = $result->fetch_assoc())
    {
        if (!isset($row['name']) || $row['name'] == null)
            continue;

        ###################################################################################################
        # Also we have to make sure that fetched servers are still available for other clients (maximum number of assined servers per client)
        # So check every client if it have this product asigned
        $curr_id = $row['id'];
        $query_str = "SELECT * FROM `tblhosting` WHERE `server` = '$curr_id' AND `domainstatus` LIKE 'Active'";
        $result2 = $conn->query($query_str);
        if (!$result2)
        {
            $returnResult['ERR_CODE'] = 1;
            $returnResult['ERR_DESC'] = $conn->error .  " -> ERR_QUERY_FETCH_ACTIVE_SERVER_CLIENT";
            return $returnResult;
        }

        if ($result2->num_rows > 0)    # if server already assigned
            continue;
        ###################################################################################################

        # Loop through all online.net servers and see where quantities have to be summed up
        foreach ( $onlineGroupsServers as $group )
        {
            foreach ( $group->Servers as $onlineServer )
            {
                if (  strpos( strtolower($row['name']), strtolower( $onlineServer->Offer) ) !== false)
                {
                    //echo "Found match: '" . $onlineServer->Offer . "'' is in '" . $row['name'] . "' ---\n";
                    $onlineServer->Availability++;
                }
            }
        }
    }
    $returnResult['RESULT'] = $onlineGroupsServers;
    return $returnResult;
}

function TestOnline()
{
    # Setting up headers to enable newlines and tabs
    header('Content-Type: text/plain');
    # Debug retrieved servers
    $OnlineServHandler = new OnlineNetServers();
    $fetchedServerGroups = $OnlineServHandler->GetServers();   // Format: $result[servers_table][index]['param']

    foreach ($fetchedServerGroups as $group)
    {
        echo "\nGroup: " . $group->Name . "\n";
        foreach ($group->Servers as $server)
        {
            echo $server->Offer . " \t ";
            echo $server->Availability . " \t ";
            echo $server->Memory . " \t ";
            echo $server->Disk . " \t ";
            echo $server->Connectivity . " \t ";
            echo $server->Bandwidth . " \t ";
            echo $server->Cpu . " \t ";
            echo $server->Price . "\n";
        }
    }
}/* Function */

function TestAll(mysqli $conn)
{
    # Setting up headers to enable newlines and tabs
    header('Content-Type: text/plain');
    # Fetch all servers
    $fetchedServerGroups = FetchServersAvailable($conn);   // Format: $result[servers_table][index]['param']
    if( $fetchedServerGroups['ERR_CODE'] != 0  )
    {
        echo "Error while getting servers: " . $fetchedServerGroups['ERR_DESC'] . " (" . $fetchedServerGroups['ERR_CODE'] . ")\n";
        return;
    }

    foreach ($fetchedServerGroups['RESULT'] as $group)
    {
        echo "\nGroup: " . $group->Name . "\n";
        foreach ($group->Servers as $server)
        {
            echo $server->Offer . " \t ";
            echo $server->Availability . " \t ";
            echo $server->Memory . " \t ";
            echo $server->Disk . " \t ";
            echo $server->Connectivity . " \t ";
            echo $server->Bandwidth . " \t ";
            echo $server->Cpu . " \t ";
            echo $server->Price . "\n";
        }
    }
}

if (isset($_GET['test']))
{
    TestAll($conn);
}

?>