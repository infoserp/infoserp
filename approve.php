<?php 
require('db.php');
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';

$company_code=$_SESSION["company_code"];
$branch_code=$_SESSION["branch_code"];
$user=$_SESSION["username"];

if (! isset($_REQUEST['doc_id']))
{
    echo "Document Id not set";
    
}
elseif (! isset($_REQUEST['doc_type']))
{
    
    echo "Document type not set";
}
else 
{
    $doc_no= $_REQUEST['doc_id'];
    $doc_type=$_REQUEST['doc_type'];
        
    if( $doc_type=="PO" && $_SESSION["PO"]=="Y")
    {
        
        $doc_type_caption="Purchase Order";
            $dt=date("Y-m-d H:i:s");
            $stmt = $con->prepare("UPDATE purchase_order_h SET approved = ?, approved_by = ?, 
            approved_date = ? WHERE company_code = ?
             AND branch_code = ?  AND doc_no = ?");
            $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));
            
            
           
           $stmt = $con->prepare("SELECT  purchase_order_h.user_id, branch_user.user_email  FROM   purchase_order_h INNER JOIN
        branch_user ON purchase_order_h.branch_code = branch_user.branch_code AND purchase_order_h.user_id = branch_user.user_id
        WHERE doc_no = ?  AND  company_code = ? and purchase_order_h.branch_code = ?");
           $stmt->execute(array($doc_no, $company_code, $branch_code));
           $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
           
           $to_email_id = $ROWS['user_email'] ?? '';
           $to_user=$ROWS['user_id'] ?? '';
                
    }
    elseif( $doc_type=="QT" && $_SESSION["QT"]=="Y")
    {
        
        $doc_type_caption="Quotation";
        $dt=date("Y-m-d H:i:s");       
        
        $stmt = $con->prepare("UPDATE sales_quotation_h SET approved = ?, approved_by = ?, 
        approved_date =  ? WHERE  company_code = ?
            AND branch_code = ? AND doc_no = ?");
        $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));
        
        $stmt = $con->prepare("SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN sales_quotation_h ON branch_user.branch_code =
            sales_quotation_h.branch_code AND branch_user.user_id = sales_quotation_h.user_id
            WHERE doc_no = ?  AND  company_code = ? and 
            sales_quotation_h.branch_code = ?");
        $stmt->execute(array($doc_no, $company_code, $branch_code));
        $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $to_email_id = $ROWS['user_email'] ?? '';
        $to_user=$ROWS['user_id'] ?? '';
        
        
    }
    elseif( $doc_type=="SO" && $_SESSION["SO"]=="Y")
    {
        
        $doc_type_caption="Sales Order";
        $dt=date("Y-m-d H:i:s");
        
        $stmt = $con->prepare("UPDATE sales_order_h SET approved = ?, approved_by =  ?,
        approved_date = ? WHERE company_code = ?
        AND branch_code = ? AND doc_no = ?");
        $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));
        
        $stmt = $con->prepare("SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN sales_order_h ON branch_user.branch_code =
            sales_order_h.branch_code AND branch_user.user_id = sales_order_h.user_id
            WHERE doc_no = ?  AND  company_code = ? and
            sales_order_h.branch_code = ?");
        $stmt->execute(array($doc_no, $company_code, $branch_code));
        $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $to_email_id = $ROWS['user_email'] ?? '';
        $to_user=$ROWS['user_id'] ?? '';
        
        
    }
    elseif( $doc_type=="MR" && $_SESSION["MR"]=="Y")
    {
        
        $doc_type_caption="Material Request";
            $dt=date("Y-m-d H:i:s");
            
            $stmt = $con->prepare("UPDATE material_request_d SET approved = ?, approved_by =?,
            approved_date = ?, trans_qty =request_qty WHERE company_code = ?
            AND branch_code = ? AND doc_no = ?");
            $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));
            
            $stmt = $con->prepare("SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN material_request_h ON branch_user.branch_code = 
            material_request_h.branch_code AND branch_user.user_id = material_request_h.user_id
            WHERE doc_no = ?  AND  company_code = ? and material_request_h.branch_code = ?");
            $stmt->execute(array($doc_no, $company_code, $branch_code));
            $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $to_email_id = $ROWS['user_email'] ?? '';
            $to_user=$ROWS['user_id'] ?? '';
            
            
    }
    elseif( $doc_type=="PR" && $_SESSION["PR"]=="Y")
    {
        
        $doc_type_caption="Purchase Request";
        $dt=date("Y-m-d H:i:s");
        
        
         $stmt = $con->prepare("UPDATE purchase_request_d SET approved = ?, approved_by =?,
         approved_date = ?, trans_qty =request_qty
         WHERE company_code = ? AND 
         branch_code = ? AND doc_no = ?");
        $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));
        
        $stmt = $con->prepare("SELECT branch_user.user_email, branch_user.user_id
            FROM branch_user INNER JOIN purchase_request_h ON branch_user.branch_code =
            purchase_request_h.branch_code AND branch_user.user_id = purchase_request_h.user_id
            WHERE doc_no = ?  AND  company_code = ? and
            purchase_request_h.branch_code = ?");
        $stmt->execute(array($doc_no, $company_code, $branch_code));
        $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $to_email_id = $ROWS['user_email'] ?? '';
        $to_user=$ROWS['user_id'] ?? '';
        $dt=date("Y-m-d H:i:s");
        
        
        $stmt = $con->prepare("UPDATE proforma_h SET  approved_by =?
           WHERE company_code = ?
            AND branch_code = ? AND doc_no = ?");
        $stmt->execute(array($user, $company_code, $branch_code, $doc_no));
        
        $stmt = $con->prepare("SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN proforma_h ON branch_user.branch_code =
            proforma_h.branch_code AND branch_user.user_id = proforma_h.user_id
            WHERE doc_no = ?  AND  company_code = ? and proforma_h.branch_code = ?");
        $stmt->execute(array($doc_no, $company_code, $branch_code));
        $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $to_email_id = $ROWS['user_email'] ?? '';
        $to_user=$ROWS['user_id'] ?? '';
        
        
    }
    
    elseif( $doc_type=="DR" && $_SESSION["DR"]=="Y")
    {
        
        $doc_type_caption="Delivery Request";
        $dt=date("Y-m-d H:i:s");
        
        
        $stmt = $con->prepare("UPDATE delivery_request_h SET approved = ?, approved_by =?,
         approved_date = ?  WHERE company_code = ?
            AND branch_code = ? AND doc_no = ?");
        $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));
        
        $stmt = $con->prepare("SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN delivery_request_h ON branch_user.branch_code =
            delivery_request_h.branch_code AND branch_user.user_id = delivery_request_h.user_id
            WHERE doc_no = ?  AND  company_code = ? and delivery_request_h.branch_code = ?");
        $stmt->execute(array($doc_no, $company_code, $branch_code));
        $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $to_email_id = $ROWS['user_email'] ?? '';
        $to_user=$ROWS['user_id'] ?? '';
        
        
    }
            
        
    
       $stmt = $con->prepare("SELECT user_email FROM  branch_user WHERE branch_code = ? AND user_id = ?");
        $stmt->execute(array($branch_code, $user));
        $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
        $cc_email_id=$ROWS["user_email"] ?? '';
     
            /*  
        
        
       
        $subject = $doc_type_caption." ".$doc_no." has been approved";        
        $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
        $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
        $headers .= "CC: ".$cc_email_id. "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
        
  
    
        if($_SERVER['HTTP_HOST']=="localhost")
            $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
         else
            $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
        header("Location: $url");
        */
        
        
      
        
        
        
        
        
     $return_url = erp_approval_list_url($_REQUEST['doc_type']);
     echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($return_url) . ";</script>";    
     
}

?>