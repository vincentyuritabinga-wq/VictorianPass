<?php
include 'connect.php';
if ($con instanceof mysqli) {
    $res = $con->query("SHOW COLUMNS FROM resident_reservations");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo $row['Field'] . "\n";
        }
    } else {
        echo "Table resident_reservations not found or error: " . $con->error;
    }
}
?>