<?php 

// http://localhost/Approval-InfosERP/sendmail.php?to_email_id=sooraj_kaithakkat@yahoo.co.in&cc_email=support@infoserp.com&user_requestor=Sooraj&user_approver=Varughese&doc_type=MR&doc_no=123456
$to_email_id= $_GET["to_email_id"];
$cc_email= $_GET["cc_email"];
$doc_type= $_GET["doc_type"];
$user_requestor= $_GET["user_requestor"];
$user_approver= $_GET["user_approver"];
$doc_no= $_GET["doc_no"];
if($doc_type=="MR")
    $doc_type_caption="Material Request";
 elseif($doc_type=="PR")
    $doc_type_caption="Purchase Request";
 elseif($doc_type=="PO")
    $doc_type_caption="Purchase Order";
 elseif($doc_type=="QT")
    $doc_type_caption="Sales Quotation";
 elseif($doc_type=="SO")
    $doc_type_caption="Sales Order";
  elseif($doc_type=="PI")
    $doc_type_caption="Proforma Invoice";
    elseif($doc_type=="DR")
    $doc_type_caption="Delivery Request";
 
    $message = "<p>Dear ".$user_requestor.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user_approver.".</p>";


$subject = $doc_type_caption." ".$doc_no." has been approved";
$headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
$headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
$headers .= "CC: ".$cc_email. "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    mail($to_email_id, $subject, $message, $headers);
    if($_SERVER['HTTP_HOST']=="localhost")
        $url="approvalline.php?doc_type=".$doc_type;
     else 
        $url="http://infosapproval.gearhostpreview.com/approvalline.php?doc_type=".$doc_type;
    header("location: $url");
?>