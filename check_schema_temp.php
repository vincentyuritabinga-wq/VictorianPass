<?php
include 'connect.php';

function describeTable($con, $table) {
    echo "Table: $table\n";
    $result = $con->query("DESCRIBE $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo $row['Field'] . " - " . $row['Type'] . "\n";
        }
    } else {
        echo "Error: " . $con->error . "\n";
    }
    echo "-------------------\n";
}

describeTable($con, 'reservations');
describeTable($con, 'resident_reservations');
describeTable($con, 'guest_forms');
?>