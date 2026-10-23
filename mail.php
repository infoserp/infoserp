<?php 
include(db.php);
$to = 'sooraj@infoserp.com';

$subject = 'Quotation EB/SOR/00004 has been Approved';

$headers = "From: " . strip_tags($to ) . "\r\n";
$headers .= "Reply-To: ". strip_tags($to) . "\r\n";
$headers .= "CC: sooraj_kaithakkat@yahoo.co.in\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

$message = '<p>Dear Sooraj, <br/><br/>Your Quotation <strong>EB/SOR/00004</strong> has been Approved by Jero.</p>';


mail($to, $subject, $message, $headers);
header("Location: test.php");
?>