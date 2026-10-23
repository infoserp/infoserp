<?php 
require('db.php');
include("auth.php");

$company_code = $_SESSION["company_code"];
$branch_code  = $_SESSION["branch_code"];
$user         = $_SESSION['username'];

if (!isset($_REQUEST["doc_type"])) 
{
  echo "No doc_type passed";
  exit;
}

switch ($_REQUEST["doc_type"] )
{
    case "PO":
        $doc_type_caption = "Purchase Order";
        $back_color = "btn-primary";
        if( !($_SESSION['PO']=="Y")) { echo "Unauthrozed accesss"; exit; }
        break;
    case "PR":
        $doc_type_caption = "Purchase Request";
        $back_color = "btn-primary";
        if( !($_SESSION['PO']=="Y")) { echo "Unauthrozed accesss"; exit; }
        break;
    case "MR":
        $doc_type_caption = "Material Request";
        $back_color = "bg-primary";
        if( !($_SESSION['MR']=="Y")) { echo "Unauthrozed accesss"; exit; }
        break;
    case "QT":
        $doc_type_caption = "Quotation";
        $back_color = "bg-primary";
        if( !($_SESSION['QT']=="Y")) { echo "Unauthrozed accesss"; exit; }
        break;
    case "SO":
        $doc_type_caption = "Sales Order";
        $back_color = "bg-primary";
        if( !($_SESSION['SO']=="Y")) { echo "Unauthrozed accesss"; exit; }
        break;
    case "DR":
        $doc_type_caption = "Delivery Request";
        $back_color = "bg-primary";
        if( !($_SESSION['DR']=="Y")) { echo "Unauthrozed accesss"; exit; }
        break;
    case "PI":
        $doc_type_caption = "Proforma Invoice";
        $back_color = "bg-primary";
        if( !($_SESSION['PI']=="Y")) { echo "Unauthrozed accesss"; exit; }
        break;
    default:
        echo "Invalid Usage";
        exit;
}

$page_title  = $doc_type_caption . ' — Pending Approval';
$active_page = $_REQUEST["doc_type"];

include __DIR__ . '/includes/approval_layout_start.php';
?>
<?php 
if($_REQUEST["doc_type"]=="PO" &&  ($_SESSION['PO']=="Y"))
{ ?>
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="10"  <?php echo "class= ".$back_color; ?>><h5 ><?php echo $doc_type_caption; ?> Pending for Approval</h5></td>
  </tr>	
    <tr>
      <th>Doc No</th>
      <th>Doc Date</th>
      <th>Requested By</th>
      <th>Account Description</th>
	  <th class="text-center">Currency</th>
      <th class="text-right">Amount</th>
      <th class="text-right">Discount</th>
      <th class="text-right">Net</th> 
      <th class="text-center">View</th>     
      <th class="text-center">Attachment</th>
      <th>Approve</th>
      </tr>
  </thead>
  <tbody>
  <?php 
    $stmt = $con->prepare("SELECT DISTINCT    replace(convert(char(11), purchase_order_h.doc_dt,113),' ','-') as doc_dt , purchase_order_h.doc_type, purchase_order_h.amount,
     purchase_order_h.discount_amount, purchase_order_h.user_id, purchase_order_h.doc_no,
     purchase_order_h.currency_code, ac_master.description FROM  purchase_order_h INNER JOIN
     ac_master ON purchase_order_h.company_code = ac_master.company_code AND purchase_order_h.ac_code = ac_master.ac_code
    WHERE purchase_order_h.company_code = ? AND purchase_order_h.branch_code = ?
     AND purchase_order_h.cancelled = 'N' AND purchase_order_h.approved = 'N'  AND purchase_order_h.confirmed = 'Y' AND (purchase_order_h.rejected IS NULL OR purchase_order_h.rejected = 'N')");
    $stmt->execute(array($company_code, $branch_code));
    while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC)) 
    {
        //
        ?>
        <?php
          $stmtCnt = $con->prepare("SELECT COUNT(*) AS cnt FROM doc_attachment WHERE doc_no = ?");
          $stmtCnt->execute(array($REPORT['doc_no']));
          $rowCnt = $stmtCnt->fetch(PDO::FETCH_ASSOC);
          $attachCount = (int) ($rowCnt['cnt'] ?? 0);
        ?>
        <tr>
          <th scope="row"><?php echo $REPORT["doc_no"]; ?></th>
          <td><?php echo $REPORT["doc_dt"]; ?></td>
          <td><?php echo $REPORT["user_id"]; ?></td>
          <td><?php echo $REPORT["description"]; ?></td>
          <td class="text-center"><?php echo $REPORT["currency_code"]; ?></td>
          
          <td class="text-right"><?php echo round( $REPORT["amount"],2); ?></td>
           <td class="text-right"><?php echo  round($REPORT["discount_amount"],2); ?></td>
          
            <td class="text-right"><?php echo round($REPORT["amount"],2)- round($REPORT["discount_amount"],2); ?></td>
         <?php  
       
          
    ?>
    		 <td class="text-center"><a class="al-btn al-btn-view" href="<?php echo htmlspecialchars(erp_approval_view_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-eye"></i> View</a></td>
         <!-- ATTACHMENT WITH COUNT -->
          <td class="text-center">
          <?php if ($attachCount > 0) { ?>
              <a class="al-btn al-btn-attach" href="<?php echo htmlspecialchars(erp_po_attachments_url($REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"
                target="_blank"
                title="View PO Attachments">
                  <i class="fa fa-paperclip"></i> (<?php echo $attachCount; ?>)
              </a>
          <?php } else { ?>
              -
          <?php } ?>
          </td>

           <td class="text-center"><a class="al-btn al-btn-approve" href="<?php echo htmlspecialchars(erp_approval_approve_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>" title="Approve"><i class="fa fa-check"></i></a></td>
            
        </tr>
    <?php
    } // While

} //If

elseif($_REQUEST["doc_type"]=="MR" &&  ($_SESSION['MR']=="Y"))
{ ?>
    
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="10"  <?php echo "class= ".$back_color; ?>><h5 ><?php echo $doc_type_caption; ?> Pending for Approval</h5></td>
  </tr>	
    <tr>
      
      <th>Doc No</th>
      <th>Doc Date</th>
      <th>Site No.</th>
      <th>Requested By</th>
      <th>Requested To</th>
      <th>Remarks</th>
      <th class="text-center">No. of Items</th>
	   <th>View</th>  
      <th>Approve</th>     
      </tr>
  </thead>
  <tbody>
  <?php 
    $stmt = $con->prepare("SELECT material_request_h.doc_no, replace(convert(char(11), material_request_h.doc_dt,113),' ','-') as doc_dt, material_request_h.tracking_no,
 material_request_h.request_by, material_request_h.site_no, material_request_h.request_to,
 material_request_h.narration, 
                         COUNT(material_request_d.item_code) AS cnt
FROM            material_request_h INNER JOIN
                         material_request_d ON material_request_h.company_code = material_request_d.company_code AND material_request_h.branch_code = material_request_d.branch_code AND 
                         material_request_h.doc_no = material_request_d.doc_no
WHERE        material_request_h.company_code = ? 
 AND material_request_h.branch_code = ?
 AND (material_request_d.approved = 'N') AND (material_request_d.cancelled = 'N')
GROUP BY material_request_h.doc_no, material_request_h.doc_dt,
 material_request_h.tracking_no, material_request_h.request_by,
 material_request_h.site_no, material_request_h.request_to, material_request_h.narration
ORDER BY material_request_h.doc_dt, material_request_h.doc_no");
    $stmt->execute(array($company_code, $branch_code));
    while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
    {
        
        ?>
   <tr>
      <th scope="row"><?php echo $REPORT["doc_no"]; ?></th>
      <td><?php echo $REPORT["doc_dt"]; ?></td>
      
       <td><?php echo $REPORT["site_no"]; ?></td>
      <td><?php echo $REPORT["request_by"]; ?></td>
      <td><?php echo $REPORT["request_to"]; ?></td>
      <td><?php echo $REPORT["narration"]; ?></td>
      
      <td class="text-center"><?php echo $REPORT["cnt"]; ?></td>   
      <td class="text-center"><a class="al-btn al-btn-view" href="<?php echo htmlspecialchars(erp_approval_view_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-eye"></i> View</a></td>  
     <td class="text-center"><a class="al-btn al-btn-approve" href="<?php echo htmlspecialchars(erp_approval_approve_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>" title="Approve"><i class="fa fa-check"></i></a></td>
    </tr>
<?php 
    } //while
} // if

elseif($_REQUEST["doc_type"]=="PR" &&  ($_SESSION['PO']=="Y"))
{ ?>
    
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="10"  <?php echo "class= ".$back_color; ?>><h5 ><?php echo $doc_type_caption; ?> Pending for Approval</h5></td>
  </tr>	
    <tr>
      
      <th>Doc No</th>
      <th>Doc Date</th>
      <th>Site No.</th>
      <th>Requested By</th>
      <th>Requested To</th>
      
      <th class="text-center">No. of Items</th>
	   <th>View</th>  
      <th>Approve</th>     
      </tr>
  </thead>
  <tbody>
  <?php 
    $stmt = $con->prepare("SELECT        purchase_request_h.doc_no,replace(convert(char(11), purchase_request_h.doc_dt,113),' ','-')  AS doc_dt,
 COUNT(DISTINCT purchase_request_d.item_code) AS cnt, purchase_request_h.request_by,
 purchase_request_h.request_to, purchase_request_h.site_no
FROM            purchase_request_d INNER JOIN
                         purchase_request_h ON purchase_request_d.company_code = 
purchase_request_h.company_code AND purchase_request_d.branch_code = purchase_request_h.branch_code AND 
                         purchase_request_d.doc_no = purchase_request_h.doc_no
WHERE        (purchase_request_h.company_code = ?)
 AND (purchase_request_h.branch_code = ?)
 AND (purchase_request_d.cancelled = 'N')
 AND (purchase_request_d.approved = 'N')
GROUP BY purchase_request_h.doc_no, purchase_request_h.doc_dt, 
purchase_request_h.request_by, purchase_request_h.request_to,site_no ORDER BY purchase_request_h.doc_no");
    $stmt->execute(array($company_code, $branch_code));
    while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
    {
        
        ?>
   <tr>
      <th scope="row"><?php echo $REPORT["doc_no"]; ?></th>
      <td><?php echo $REPORT["doc_dt"]; ?></td>
      
       <td><?php echo $REPORT["site_no"]; ?></td>
      <td><?php echo $REPORT["request_by"]; ?></td>
      <td><?php echo $REPORT["request_to"]; ?></td>
      
      
      <td class="text-center"><?php echo $REPORT["cnt"]; ?></td>   
      <td class="text-center"><a class="al-btn al-btn-view" href="<?php echo htmlspecialchars(erp_approval_view_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-eye"></i> View</a></td>  
     <td class="text-center"><a class="al-btn al-btn-approve" href="<?php echo htmlspecialchars(erp_approval_approve_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>" title="Approve"><i class="fa fa-check"></i></a></td>
    </tr>
<?php 
    } //while
} // if
elseif($_REQUEST["doc_type"]=="QT" &&  ($_SESSION["QT"]=="Y"))
{ ?>
<div class="al-table-wrap"><table class="al-table"> 
  <thead>
   <tr>
  	<td colspan="10"  <?php echo "class= ".$back_color; ?>><h5 ><?php echo $doc_type_caption; ?> Pending for Approval</h5></td>
  </tr>	
    <tr>
    <th>Doc No</th>
    <th>Doc Date</th>
    <th>Account Description</th>
 <!--    <th>Project</th>  -->
    <th class="text-center">Currency</th>
    <th class="text-right">Amount</th>
    <th class="text-right">Discount</th>
    <th class="text-right">VAT</th>
    <th class="text-right">Net</th>
    <th class="text-center">View</th>
    <th>Approve</th>  
    </tr>
  </thead>
  <tbody>
    <?php 
    $stmt = $con->prepare("SELECT replace(convert(char(11), sales_quotation_h.doc_dt,113),' ','-') as doc_dt , sales_quotation_h.subject, sales_quotation_h.amount, sales_quotation_h.tax1_amount, 
sales_quotation_h.discount_amount, ac_master.description,
 sales_quotation_h.doc_no, sales_quotation_h.currency_code
FROM  sales_quotation_h INNER JOIN
ac_master ON sales_quotation_h.company_code = ac_master.company_code
 AND sales_quotation_h.ac_code = ac_master.ac_code
WHERE        (sales_quotation_h.company_code = ?)
 AND (sales_quotation_h.branch_code = ?) 
AND (sales_quotation_h.approved = 'N') 
AND (sales_quotation_h.cancelled = 'N') ORDER BY doc_num");
    $stmt->execute(array($company_code, $branch_code));
    while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
    {
        
        ?>
   <tr>
      <th scope="row"><?php echo $REPORT["doc_no"]; ?></th>
      <td><?php echo $REPORT["doc_dt"]; ?></td>
      
       <td><?php echo $REPORT["description"]; ?></td>
      <td class="text-center"><?php echo $REPORT["currency_code"]; ?></td>
      <td class="text-right"><?php echo round($REPORT["amount"],2); ?></td>
      <td class="text-right"><?php echo round( $REPORT["discount_amount"],2); ?></td>
       <td class="text-right"><?php echo round( $REPORT["tax1_amount"],2); ?></td>
      <td class="text-right"><?php echo round($REPORT["amount"]-$REPORT["discount_amount"],2)+round( $REPORT["tax1_amount"],2); ?></td>
      
    
      <td class="text-center"><a class="al-btn al-btn-view" href="<?php echo htmlspecialchars(erp_approval_view_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-eye"></i> View</a></td>  
     <td class="text-center"><a class="al-btn al-btn-approve" href="<?php echo htmlspecialchars(erp_approval_approve_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>" title="Approve"><i class="fa fa-check"></i></a></td>
    </tr>
<?php 
    } //while
} // if
elseif($_REQUEST["doc_type"]=="SO" &&  ($_SESSION["SO"]=="Y"))
{ ?>
<div class="al-table-wrap"><table class="al-table"> 
  <thead>
   <tr>
  	<td colspan="10"  <?php echo "class= ".$back_color; ?>><h5 ><?php echo $doc_type_caption; ?> Pending for Approval</h5></td>
  </tr>	
    <tr>
    <th>Doc No</th>
    <th>Doc Date</th>
    <th>Account Description</th>
 <!--    <th>Project</th>  -->
    <th class="text-center">Currency</th>
    <th class="text-right">Amount</th>
    <th class="text-right">Discount</th>
    <th class="text-right">Net</th>
    <th class="text-center">View</th>
    <th>Approve</th>  
    </tr>
  </thead>
  <tbody>
    <?php 
  
    
    $stmt = $con->prepare("SELECT        sales_order_h.doc_no,replace(convert(char(11), doc_dt,113),' ','-')  AS doc_dt, ac_master.description,
    sales_order_h.amount, sales_order_h.discount_amount, sales_order_h.currency_code
    FROM            ac_master INNER JOIN  sales_order_h ON ac_master.company_code = sales_order_h.company_code
    AND ac_master.ac_code = sales_order_h.ac_code
    WHERE  sales_order_h.company_code = ?  AND sales_order_h.branch_code = ? 
    AND (sales_order_h.approved = 'N') AND (sales_order_h.cancelled = 'N') ORDER BY doc_num");
    $stmt->execute(array($company_code, $branch_code));
    while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
    {
        
        ?>
   <tr>
      <th scope="row"><?php echo $REPORT["doc_no"]; ?></th>
      <td><?php echo $REPORT["doc_dt"]; ?></td>
      
       <td><?php echo $REPORT["description"]; ?></td>
      <td class="text-center"><?php echo $REPORT["currency_code"]; ?></td>
      <td class="text-right"><?php echo round($REPORT["amount"],2); ?></td>
      <td class="text-right"><?php echo round( $REPORT["discount_amount"],2); ?></td>
      <td class="text-right"><?php echo round($REPORT["amount"]-$REPORT["discount_amount"],2); ?></td>
      
    
      <td class="text-center"><a class="al-btn al-btn-view" href="<?php echo htmlspecialchars(erp_approval_view_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-eye"></i> View</a></td>  
     <td class="text-center"><a class="al-btn al-btn-approve" href="<?php echo htmlspecialchars(erp_approval_approve_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>" title="Approve"><i class="fa fa-check"></i></a></td>
    </tr>
<?php 
    } //while
} // if
elseif($_REQUEST["doc_type"]=="DR" &&  ($_SESSION["DR"]=="Y"))
{ ?>
<div class="al-table-wrap"><table class="al-table"> 
  <thead>
   <tr>
  	<td colspan="10"  <?php echo "class= ".$back_color; ?>><h5 ><?php echo $doc_type_caption; ?> Pending for Approval</h5></td>
  </tr>	
    <tr>
    <th>Doc No</th>
    <th>Doc Date</th>
    <th>Account Description</th>
 
    <th class="text-center">View</th>
    <th>Approve</th>  
    </tr>
  </thead>
  <tbody>
    <?php 
  
    
    $stmt = $con->prepare("SELECT        delivery_request_h.doc_no,replace(convert(char(11), doc_dt,113),' ','-')  AS doc_dt
 , delivery_request_h.currency_code, delivery_request_h.amount, ac_master.description,
 COUNT(DISTINCT delivery_request_d.item_code) AS cnt
FROM   delivery_request_h INNER JOIN
 ac_master ON delivery_request_h.company_code = ac_master.company_code AND delivery_request_h.ac_code = ac_master.ac_code INNER JOIN
                         delivery_request_d ON delivery_request_h.company_code = delivery_request_d.company_code AND delivery_request_h.branch_code = delivery_request_d.branch_code AND 
                         delivery_request_h.doc_no = delivery_request_d.doc_no
WHERE        (delivery_request_h.company_code = ?)
AND (delivery_request_h.branch_code = ?) 
AND (delivery_request_h.approved = 'N') 
AND (delivery_request_h.cancelled = 'N') 
GROUP BY delivery_request_h.doc_no, delivery_request_h.doc_dt,
delivery_request_h.currency_code, delivery_request_h.amount, ac_master.description 
ORDER BY delivery_request_h.doc_no");
    $stmt->execute(array($company_code, $branch_code));
    while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
    {
        
        ?>
   <tr>
      <th scope="row"><?php echo $REPORT["doc_no"]; ?></th>
      <td><?php echo $REPORT["doc_dt"]; ?></td>
      
       <td><?php echo $REPORT["description"]; ?></td>
    
     
      
    
      <td class="text-center"><a class="al-btn al-btn-view" href="<?php echo htmlspecialchars(erp_approval_view_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-eye"></i> View</a></td>  
     <td class="text-center"><a class="al-btn al-btn-approve" href="<?php echo htmlspecialchars(erp_approval_approve_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>" title="Approve"><i class="fa fa-check"></i></a></td>
    </tr>
<?php 
    } //while
} // if

elseif($_REQUEST["doc_type"]=="PI" &&  ($_SESSION["PI"]=="Y"))
{ ?>
<div class="al-table-wrap"><table class="al-table"> 
  <thead>
   <tr>
  	<td colspan="10"  <?php echo "class= ".$back_color; ?>><h5 ><?php echo $doc_type_caption; ?> Pending for Approval</h5></td>
  </tr>	
    <tr>
    <th>Doc No</th>
    <th>Doc Date</th>
    <th>Account Description</th>
 <!--    <th>Project</th>  -->
    <th class="text-center">Currency</th>
    <th class="text-right">Amount</th>
    <th class="text-right">Discount</th>
    <th class="text-right">Net</th>
    <th class="text-center">View</th>
    <th class="text-center">Action</th>
    </tr>
  </thead>
  <tbody>
    <?php 
  
    
    $stmt = $con->prepare("SELECT ac_master.description, proforma_h.doc_no, 
replace(convert(char(11), doc_dt,113),' ','-')  AS doc_dt,
 proforma_h.amount, proforma_h.discount_amount,proforma_h.currency_code
FROM   ac_master INNER JOIN proforma_h ON ac_master.company_code = proforma_h.company_code
 AND ac_master.ac_code = proforma_h.ac_code
WHERE        (proforma_h.company_code = ?) AND
 (proforma_h.branch_code = ?) 
AND (proforma_h.approved_by IS NULL) ORDER BY doc_num");
    $stmt->execute(array($company_code, $branch_code));
    while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
    {
        
        ?>
   <tr>
      <th><?php echo $REPORT["doc_no"]; ?></th>
      <td><?php echo $REPORT["doc_dt"]; ?></td>
      
        
        <td><?php echo $REPORT["description"]; ?></td>
      <td class="text-center"><?php echo $REPORT["currency_code"]; ?></td>
      <td class="text-right"><?php echo round($REPORT["amount"],2); ?></td>
      <td class="text-right"><?php echo round( $REPORT["discount_amount"],2); ?></td>
      <td class="text-right"><?php echo round($REPORT["amount"]-$REPORT["discount_amount"],2); ?></td>
    
     
      
    
      <td class="text-center"><a class="al-btn al-btn-view" href="<?php echo htmlspecialchars(erp_approval_view_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-eye"></i> View</a></td>  
     <td class="text-center"><a class="al-btn al-btn-approve" href="<?php echo htmlspecialchars(erp_approval_approve_url($_REQUEST['doc_type'], $REPORT['doc_no']), ENT_QUOTES, 'UTF-8'); ?>" title="Approve"><i class="fa fa-check"></i></a></td>
    </tr>
<?php 
    } //while
} // if
?>
       
  </tbody>
</table>
</div>
<?php include __DIR__ . '/includes/approval_layout_end.php'; ?>

