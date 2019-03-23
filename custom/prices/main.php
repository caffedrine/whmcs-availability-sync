<?php

# Setting up headers to enable newlines and tabs
header('Content-Type: text/plain');

require_once("init.php");
require_once('servers.php');

function GetProductLinkByName(mysqli $conn, $name)
{
    $query_str = "SELECT * FROM `tblproducts` WHERE `name` LIKE '$name'";
    $result = $conn->query($query_str);
    if (!$result)
    {
        die("ERR_QUERY_FAIL");
    }
    if ($result->num_rows <= 0)
    {
        return "";
    }

    while  ($row = $result->fetch_assoc())
    {
        if (!isset($row['id']) || $row['id'] == null)
            continue;

        return "https://1way.pro/cart.php?a=add&pid=" . $row['id'];

    }
    return "";
}

function GetProductIdByName(mysqli $conn, $name)
{
    $query_str = "SELECT * FROM `tblproducts` WHERE `name` LIKE '$name'";
    $result = $conn->query($query_str);
    if (!$result)
    {
        die("ERR_QUERY_FAIL");
    }
    if ($result->num_rows <= 0)
    {
        return "";
    }

    while  ($row = $result->fetch_assoc())
    {
        if (!isset($row['id']) || $row['id'] == null)
            continue;

        return $row['id'];

    }
    return "";
}

function SetProductPrice(mysqli $conn, $name, $new_price)
{
    $ProductId = GetProductIdByName($conn, $name);
    if($ProductId == "")
        return 1;
    $query_str = "UPDATE `tblpricing` SET `msetupfee`=20,`qsetupfee`=20,`ssetupfee`=20,`asetupfee`=20,`monthly`=$new_price,`quarterly`=$new_price*3,`semiannually`=$new_price*6,`annually`=$new_price*12 WHERE `relid` = $ProductId AND `type`='product'";
    $result = $conn->query($query_str);
    if (!$result)
    {
        die("ERR_QUERY_FAIL");
    }
    return 0;
}

function UpdatePrices(mysqli $conn, $serverGroups)
{
    foreach ($serverGroups as $Group)
    {
        foreach ($Group->Servers as $server)
        {
            if ($server->Price < 15)
            {
                $server->Price *= 3;
                $server->Price = intval($server->Price);
            }
            else
            {
                $server->Price *= 2.5;
                $server->Price = intval($server->Price);
            }

            # Also update prices from database
            SetProductPrice($conn, $server->Offer, $server->Price);
        }
    }
    return $serverGroups;
}

function WriteHTML($HTML_Content)
{
    $TEMPLATE_FILE = "../../templates/webhoster/dedicated_servers.tpl";
    $START_TOKEN = "<!--START_LIST_IDENTIFICATOR-->";
    $END_TOKEN = "<!--END_LIST_IDENTIFICATOR-->";
    ############################################################

    $file_content = file_get_contents($TEMPLATE_FILE);
    # If file does not contain expected tokens
    if ((strpos($file_content, $START_TOKEN) === false) || (strpos($file_content, $END_TOKEN) === false))
    {
        return 1;
    }

    # Extract header of content
    $header = explode($START_TOKEN, $file_content)[0];
    $footer = explode($END_TOKEN, $file_content)[1];

    # Result
    $result = $header . $START_TOKEN . "\n" . $HTML_Content . "\n" . $END_TOKEN . $footer;

    # Write data to html file
    file_put_contents($TEMPLATE_FILE, $result);

    return 0;

}

function GenerateHTML(mysqli $conn, $serverGroups)
{
    $htmlContent = "<!-- LAST UPDATE: " . date('h:i:s m/d/Y', time()) . " -->\n";
    foreach ($serverGroups as $Group)
    {
        $htmlContent .= "<h3>" . $Group->Name . "</h3>\n";
        # $htmlContent .= "<table class=\"table table-bordered table-striped table-hover tc-table footable\">\n";
        $htmlContent .= "<table class=\"table table-boarded table-hover table-striped tc-table\">\n";
        $htmlContent .= "<thead>\n";
        $htmlContent .= "<tr>\n";
        $htmlContent .= "<th>ID</th>\n";                                              # Name
        $htmlContent .= "<th>CPU</th>\n";                                             # CPU
        $htmlContent .= "<th data-hide=\"phone\">Memory</th>\n";                      # Memory
        $htmlContent .= "<th data-hide=\"phone,tablet\">Default Drive(s)</th>\n";     # Drives
        $htmlContent .= "<th data-hide=\"phone,tablet\">Connectivity</th>\n";         # Connectivity
        $htmlContent .= "<th data-hide=\"phone,tablet\">Bandwidth</th>\n";            # Bandwidth
        $htmlContent .= "<th data-hide=\"phone\">Monthly</th>\n";                     # Price
        $htmlContent .= "<th data-hide=\"phone\">Availability</th>\n";                # Availability
        $htmlContent .= "<th data-sort-ignore=\"true\"></th>\n";                      # Order link
        $htmlContent .= "</tr>\n";
        $htmlContent .= "</thead>\n";
        $htmlContent .= "<tbody>\n";
        foreach ($Group->Servers as $server)
        {
            //$htmlContent .= "<tr class=\"warning\">n";
            $htmlContent .= "<tr>\n";
            $htmlContent .= "    <td>" . $server->Offer . "</td>\n";
            $htmlContent .= "    <td>" . $server->Cpu . "</td>\n";
            $htmlContent .= "    <td>" . $server->Memory . "</td>\n";
            $htmlContent .= "    <td>" . $server->Disk . "</td>\n";
            $htmlContent .= "    <td>" . $server->Connectivity . "</td>\n";
            $htmlContent .= "    <td>" . $server->Bandwidth . "</td>\n";
            $htmlContent .= "    <td>$" . $server->Price . "</td>\n";
            $htmlContent .= "    <td>" . $server->Availability . "</td>\n";
            if ($server->Availability > 0)
            {
                $ProductOrderLink = GetProductLinkByName($conn, $server->Offer);
                if($ProductOrderLink == "")
                {
                    $ProductOrderLink = "https://1way.pro/contact.php";
                }
                $htmlContent .= "    <td class=\"col-medium center\"><div class=\"action-buttons\"><a href=\"" . $ProductOrderLink . "\" target=\"_blank\" class=\"text-success\">Order Now</a></div></td>\n";
            }
            else
            {
                $htmlContent .= "<td></td>\n";
            }
            $htmlContent .= "</tr>\n";
        }
        $htmlContent .= "</tbody>\n";
        $htmlContent .= "</table>\n\n";
    }
    return $htmlContent;
}

# Fetch the content
$ServerGroups = FetchServersAvailable($conn);
if ($ServerGroups['ERR_CODE'] != 0)
{
    die("Failed to fetch servers: " . $ServerGroups['ERR_DESC'] . " (" . $ServerGroups['ERR_CODE'] . ")");
}
# Update prices
$GroupsServers = UpdatePrices($conn, $ServerGroups['RESULT']);
$GeneratedHTML = GenerateHTML($conn, $GroupsServers);

# Update template files to display new changes
if (WriteHTML($GeneratedHTML) != 0)
{
    die("Failed to write to html file!");
}

echo "Success!\n";