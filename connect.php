<?php
// Database connection settings
$host = "127.0.0.1";
$user = "root";           // default XAMPP MySQL user
$pass = "";               // default is empty in XAMPP
$db   = "victorianpass_db"; // your database name

// Create connection
$con = new mysqli($host, $user, $pass, $db);

// Check connection
if ($con->connect_error) {
    die("Connection failed: " . $con->connect_error);
}
?>
