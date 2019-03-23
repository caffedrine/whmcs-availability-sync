<?php

# Only for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

# Database configs
require ("../../configuration.php");
$conn = new mysqli($db_host, $db_username, $db_password, $db_name);
if( $conn->connect_error )
{
	die("Connection failed: " . $conn->connect_error);
}
