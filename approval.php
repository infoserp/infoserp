<?php
session_start();
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';

if (!isset($_GET["doc_type"])) {
    echo "Invalid Usage";
    exit;
}

$doc_type = $_GET["doc_type"];
$target   = erp_iframe_url('approvalline?doc_type=' . rawurlencode($doc_type));

switch ($doc_type) {
    case "MR":
        if ($_SESSION["MR"] <> "Y") {
            echo "Unauthrozed accesss";
            exit;
        }
        header("Location: $target");
        break;
    case "PR":
        if ($_SESSION["PR"] <> "Y") {
            echo "Unauthrozed accesss";
            exit;
        }
        header("Location: $target");
        break;
    case "PO":
        if ($_SESSION["PO"] <> "Y") {
            echo "Unauthrozed accesss";
            exit;
        }
        header("Location: $target");
        break;
    case "QT":
        if (!($_SESSION['QT'] == "Y")) {
            echo "Unauthrozed accesss";
            exit;
        }
        header("Location: $target");
        break;
    case "SO":
        if (!($_SESSION['SO'] == "Y")) {
            echo "Unauthrozed accesss";
            exit;
        }
        header("Location: $target");
        break;
    case "PI":
        if (!($_SESSION['PI'] == "Y")) {
            echo "Unauthrozed accesss";
            exit;
        }
        header("Location: $target");
        break;
    case "DR":
        if (!($_SESSION['DR'] == "Y")) {
            echo "Unauthrozed accesss";
            exit;
        }
        header("Location: $target");
        break;
}

?>
