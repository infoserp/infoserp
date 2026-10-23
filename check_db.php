<?php
require('db.php');
$sql = "SELECT TOP 1 * FROM ac_subledger";
$res = odbc_exec($con, $sql);
if ($res) {
    print_r(odbc_fetch_array($res));
} else {
    echo odbc_errormsg($con);
}
?>