<?php
include 'connect.php';

function checkColumn($con, $table, $col) {
    $res = $con->query("SHOW COLUMNS FROM $table LIKE '$col'");
    if ($res && $res->num_rows > 0) {
        echo "$table has $col: YES\n";
    } else {
        echo "$table has $col: NO\n";
    }
}

checkColumn($con, 'reservations', 'scanned_at');
checkColumn($con, 'guest_forms', 'scanned_at');
checkColumn($con, 'resident_reservations', 'scanned_at');
?>
