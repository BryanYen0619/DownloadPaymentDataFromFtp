<?php

$serverName = "localhost";
$userName = "root";
$password = "Abcd1234";
$dbName = "tokyo_payment_test";

// Create connection
$mysql = mysql_connect($serverName, $userName, $password);

// Check connection
if (!$mysql) {
    die("Connection failed: " . $mysqli->connect_error);
}

mysql_select_db($dbName, $mysql)  or die("mysql_select_db() 資料庫無法連結！");

?>
