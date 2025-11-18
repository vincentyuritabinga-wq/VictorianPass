<?php
include 'connect.php';

// Check if reservations table exists
$result = $con->query("SHOW TABLES LIKE 'reservations'");
if ($result && $result->num_rows > 0) {
    echo "Reservations table exists.\n";
    
    // Get table structure
    $structure = $con->query("DESCRIBE reservations");
    if ($structure) {
        echo "\nReservations table structure:\n";
        while ($row = $structure->fetch_assoc()) {
            echo "Field: " . $row['Field'] . ", Type: " . $row['Type'] . ", Null: " . $row['Null'] . ", Key: " . $row['Key'] . ", Default: " . ($row['Default'] ?? 'NULL') . ", Extra: " . $row['Extra'] . "\n";
        }
    }
} else {
    echo "Reservations table does not exist.\n";
}

// Check other related tables
$tables = ['resident_reservations', 'guest_forms'];
foreach ($tables as $table) {
    $result = $con->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "\n$table table exists.\n";
    } else {
        echo "\n$table table does not exist.\n";
    }
}

$con->close();
?>