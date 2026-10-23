<?php
require("db.php");
$stmt = $con->query("SELECT top 1 * FROM invoice_d");
print_r(array_keys($stmt->fetch(PDO::FETCH_ASSOC)));
