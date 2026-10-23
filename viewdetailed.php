<?php 
require('db.php');
include("auth.php");
require_once __DIR__ . '/includes/erp_paths.php';

$company_code=$_SESSION["company_code"];
$branch_code=$_SESSION["branch_code"];
$doc_no= $_REQUEST['doc_id'];
$doc_type=$_REQUEST['doc_type'];
$username=$_SESSION["username"];

$approvalline_embed_url = erp_approval_list_url($doc_type);

if (!function_exists('erp_fetch_val')) {
    function erp_fetch_val($row, $key, $default = '') {
        return (is_array($row) && array_key_exists($key, $row)) ? $row[$key] : $default;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reject_po'])) {
  $doc_no = $_POST['doc_no'];
  $comment = $_POST['comment'];
  $username = $_SESSION["username"];
  $current_date = date('Y-m-d');

  // Check if comment is present
  if (!empty($comment) && $doc_type == "PO") {
      
      // Update rejected status and insert the comment in purchase_order_h
      $REJECT_QUERY = "UPDATE purchase_order_h 
                       SET rejected = 'Y', 
                           rejected_by = ?, 
                           rejected_date = ?, 
                           rejected_remark = ?
                       WHERE company_code = ? 
                       AND branch_code = ? 
                       AND doc_no = ? 
                       AND doc_type = 'LPO'"; // Corrected doc_type from LPO to PO

      // Use PDO Prepared Statement to prevent SQL Injection
      $stmt = $con->prepare($REJECT_QUERY);
      $result = $stmt->execute(array($username, $current_date, $comment, $company_code, $branch_code, $doc_no));

      if ($result) {
          echo "<script>
                  alert('PO Rejected successfully!');
                  window.location.href=" . json_encode($approvalline_embed_url) . ";
                </script>";
      } else {
          echo "<script>
                  alert('Failed to reject PO. Please try again.');
                  window.location.href=" . json_encode(erp_iframe_url('viewdetailed?doc_id=' . rawurlencode($doc_no) . '&doc_type=' . rawurlencode($doc_type))) . ";
                </script>";
      }
  } else {
      echo "<script>
              alert('Comment is required to reject this PO.');
               window.location.href=" . json_encode(erp_iframe_url('viewdetailed?doc_id=' . rawurlencode($doc_no) . '&doc_type=' . rawurlencode($doc_type))) . ";
            </script>";
  }
}

$user = $_SESSION['username'];

switch ($doc_type) {
    case 'PO': $doc_type_caption = 'Purchase Order'; break;
    case 'MR': $doc_type_caption = 'Material Request'; break;
    case 'PR': $doc_type_caption = 'Purchase Request'; break;
    case 'QT': $doc_type_caption = 'Quotation'; break;
    case 'SO': $doc_type_caption = 'Sales Order'; break;
    case 'DR': $doc_type_caption = 'Delivery Request'; break;
    case 'PI': $doc_type_caption = 'Proforma Invoice'; break;
    default:   $doc_type_caption = 'Document'; break;
}

if (strtoupper($_SERVER['REQUEST_METHOD']) == 'GET')
{
$page_title  = $doc_type_caption . ' — ' . $doc_no;
$active_page = $doc_type;
include __DIR__ . '/includes/approval_layout_start.php';

if( $doc_type=="PO" && $_SESSION["PO"]=="Y")
{
   ///////////////////////////////
   
    ?>
    <form method="post" >
    <input type="hidden" name="doc_no" value="<?php echo $doc_no;?>"> 
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="9"   class= "btn-primary"><h5 > Purchase Order Details <?php echo " ".$doc_no; ?></h5></td>
  </tr>	
    <tr>
     <th>No</th>
      <th>Item Code</th>
      <th>Item Description</th>
      <th>Remark</th>
      
	  <th class="text-center">Qty</th>
      <th class="text-center">Unit</th>
       
      <th class="text-right">Rate</th> 
        <th class="text-right">Gross</th>   
      <th class="text-right">Discount</th> 
      <th class="text-right">Net</th>    
        </tr>
  </thead>
  <tbody>
   
  	
  	<?php
  	$QUERY="SELECT        item_no, item_code,  item_description,remark,trans_qty, trans_unit, trans_rate, discount_amount, doc_no, serial_no, tax1_amount
FROM            purchase_order_d
WHERE        (company_code = '$company_code') AND (branch_code = '$branch_code') AND (doc_no = '$doc_no')";
        

$stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
$rejected_remark = '';
$stmt2 = $con->prepare("SELECT rejected_remark 
                 FROM purchase_order_h 
                 WHERE company_code = ? 
                 AND branch_code = ? 
                 AND doc_no = ? 
                 AND doc_type = 'LPO'");
$stmt2->execute(array($company_code, $branch_code, $doc_no));

if ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    $rejected_remark = $row['rejected_remark'] ?? '';
}
while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
{
    //
    ?>
    <tr>
       
      
      <td scope="row"></input> <input type="hidden" value="<?php echo $REPORT["item_no"]; ?>" name="serial_no[]"></input><?php echo $REPORT["item_no"]; ?></td>
      <td><?php echo $REPORT["item_code"]; ?></td>
      <td><?php echo $REPORT["item_description"]; ?></td>
      <td><?php echo $REPORT["remark"]; ?></td>
      
      <td class="text-center"><?php echo round( $REPORT["trans_qty"],2);  ?>   </td>
      <td class="text-center"><?php echo  $REPORT["trans_unit"];  ?>   </td>
      
      <td class="text-right"><?php echo round( $REPORT["trans_rate"],2);  ?></td>
       <td class="text-right"><?php echo round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2);  ?></td>
      <td class="text-right"><?php echo    round( $REPORT["discount_amount"],2);  ?></td>
      <td class="text-right"><?php echo round( round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2)- $REPORT["discount_amount"],2);  ?></td>
       
  	 <tr>
 <?php }  	?>
  </tbody>
  </table>
</div>
  <div class="al-form-actions">
   <button type="submit" class="al-btn al-btn-approve" name="approvepo"><i class="fa fa-check"></i> Approve</button>
   <a class="al-home-btn al-home-btn--ghost" href="<?php echo htmlspecialchars($approvalline_embed_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-arrow-left"></i> Back to list</a>
   </div>
   <div class="al-detail-comment">
    <label for="comment">Add Comment:</label>
    <textarea name="comment" id="comment" rows="3"><?php echo htmlspecialchars($rejected_remark); ?></textarea>
    <div class="al-form-actions" style="padding-top:0.75rem;">
    <button type="submit" class="al-btn al-btn-reject" name="reject_po"><i class="fa fa-times"></i> Reject</button>
</div>
</div>
   </form>
 <?php   
   
    
    
    /////////////////////////////////////////////////
   
    
    
}
elseif( $doc_type=="MR" && $_SESSION["MR"]=="Y")
{ ?>
    <form method="post" >
    <input type="hidden" name="doc_no" value="<?php echo $doc_no;?>"> 
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="9"   class= "btn-primary"><h5 > Material Request Details <?php echo " ".$doc_no; ?></h5></td>
  </tr>	
    <tr>
     <th>No</th>
      <th>Item Code</th>
      <th>Item Description</th>
      
	  <th class="text-center">Requested Qty</th>
      <th class="text-center">Approved Qty</th>
      <th class="text-right">Unit</th>
      <th class="text-center">Expected Date</th>    
      <th class="text-left">Remarks</th>   
        </tr>
  </thead>
  <tbody>
   
  	
  	<?php
  	$stmt = $con->prepare("SELECT   material_request_h.doc_no, material_request_h.site_no, material_request_d.item_description, 
material_request_d.trans_qty, material_request_d.trans_unit, replace(convert(char(11), material_request_d.expected_date,113),' ','-') as expected_date,
 material_request_d.item_code, 
 material_request_d.item_no, material_request_d.serial_no,
 material_request_d.request_qty, material_request_h.request_to, material_request_h.doc_type,
 material_request_h.approved_to, 
 material_request_d.approved_remark
FROM            material_request_h INNER JOIN
material_request_d ON material_request_h.company_code = material_request_d.company_code AND material_request_h.branch_code = material_request_d.branch_code AND 
material_request_h.doc_no = material_request_d.doc_no
WHERE (material_request_h.company_code = ?)
 AND (material_request_h.branch_code = ?)
 AND (material_request_d.approved = 'N') AND 
(material_request_d.cancelled = 'N') 
AND (material_request_h.doc_no = ?)");
	$stmt->execute(array($company_code, $branch_code, $doc_no));

while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
{
    //
    ?>
    <tr>
       
      
      <td scope="row"></input> <input type="hidden" value="<?php echo $REPORT["serial_no"]; ?>" name="serial_no[]"></input><?php echo $REPORT["serial_no"]; ?></td>
      <td><?php echo $REPORT["item_code"]; ?></td>
      <td><?php echo $REPORT["item_description"]; ?></td>
      <td class="text-center"><input type="hidden" value="<?php echo $REPORT["request_qty"]; ?>" name="request_qty[]"> <?php echo $REPORT["request_qty"]; ?></td>
      <td class="text-center"><input type="text" class="tb1" maxlength="10" name="approvedqty[]" value= "<?php echo   $REPORT['trans_qty'];  ?>"></input>  </td>
      <td class="text-center"><?php echo  $REPORT["trans_unit"];  ?>   </td>
      
      <td class="text-right"><?php echo  $REPORT["expected_date"];  ?></td>
       <td class="text-right"><input type="text" class="tb1"  name="remarks[]" maxlength="50" value= "<?php echo $REPORT['approved_remark'];  ?>"> </td>
  	 <tr>
 <?php 
} 

?>
  </tbody>
  </table>
</div>
  <div class="al-form-actions">
   <button type="submit" class="al-btn al-btn-approve" name="approvemr"><i class="fa fa-check"></i> Approve</button>
   <a class="al-home-btn al-home-btn--ghost" href="<?php echo htmlspecialchars($approvalline_embed_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-arrow-left"></i> Back to list</a>
   </div>
   </form>
 <?php   
}
elseif( $doc_type=="PR" && $_SESSION["PR"]=="Y")
{ ?>
    <form method="post" >
    <input type="hidden" name="doc_no" value="<?php echo $doc_no;?>"> 
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="9"   class= "btn-primary"><h5 > Purchase Request Details <?php echo " ".$doc_no; ?></h5></td>
  </tr>	
    <tr>
     <th>No</th>
      <th>Item Code</th>
      <th>Item Description</th>
      
	  <th class="text-center">Requested Qty</th>
      <th class="text-center">Approved Qty</th>
      <th class="text-right">Unit</th>
      <th class="text-center">Expected Date</th>    
      
        </tr>
  </thead>
  <tbody>
   
  	
  	<?php
 
  	
  	$stmt = $con->prepare(" SELECT        purchase_request_d.item_no, purchase_request_d.item_code, purchase_request_d.item_description, purchase_request_d.request_qty, purchase_request_d.trans_qty, purchase_request_d.trans_unit,
  	purchase_request_d.expected_date, purchase_request_h.company_code, purchase_request_h.branch_code, purchase_request_d.serial_no
  	FROM            purchase_request_d INNER JOIN
  	purchase_request_h ON purchase_request_d.company_code = purchase_request_h.company_code AND purchase_request_d.branch_code = purchase_request_h.branch_code AND
  	purchase_request_d.doc_no = purchase_request_h.doc_no
  	WHERE        (purchase_request_h.company_code = ?)
    AND (purchase_request_h.branch_code = ?) AND
     (purchase_request_d.cancelled = 'N') AND
     (purchase_request_d.approved = 'N') AND
  	(purchase_request_h.doc_no = ?)");
$stmt->execute(array($company_code, $branch_code, $doc_no));
while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
{
    //
    ?>
    <tr>
       
      
      <td scope="row"></input> <input type="hidden" value="<?php echo $REPORT["serial_no"]; ?>" name="serial_no[]"></input><?php echo $REPORT["serial_no"]; ?></td>
      <td><?php echo $REPORT["item_code"]; ?></td>
      <td><?php echo $REPORT["item_description"]; ?></td>
      <td class="text-center"><input type="hidden" value="<?php echo $REPORT["request_qty"]; ?>" name="request_qty[]"> <?php echo $REPORT["request_qty"]; ?></td>
      <td class="text-center"><input type="text" class="tb1" maxlength="10" name="approvedqty[]" value= "<?php echo   $REPORT['trans_qty'];  ?>"></input>  </td>
      <td class="text-center"><?php echo  $REPORT["trans_unit"];  ?>   </td>
      
      <td class="text-right"><?php echo  $REPORT["expected_date"];  ?></td>
  	 <tr>
 <?php 
} 

?>
  </tbody>
  </table>
</div>
  <div class="al-form-actions">
   <button type="submit" class="al-btn al-btn-approve" name="approvepr"><i class="fa fa-check"></i> Approve</button>
   <a class="al-home-btn al-home-btn--ghost" href="<?php echo htmlspecialchars($approvalline_embed_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-arrow-left"></i> Back to list</a>
   </div>
   </form>
 <?php   
}
elseif( $doc_type=="QT" && $_SESSION["QT"]=="Y")
{
    
    
    $QUERY="SELECT        ac_master.description, salesman.salesman_name,sales_quotation_h.payment_terms,sales_quotation_h.warranty_terms,sales_quotation_h.exclusion,
    delivery_terms,sales_quotation_h.delivery_period,sales_quotation_h.validity_terms
    FROM            sales_quotation_h INNER JOIN
    ac_master ON sales_quotation_h.company_code = ac_master.company_code AND sales_quotation_h.ac_code = ac_master.ac_code
 AND sales_quotation_h.ac_code = ac_master.ac_code LEFT OUTER JOIN
                         salesman ON sales_quotation_h.company_code = salesman.company_code
    WHERE        (sales_quotation_h.company_code = '$company_code') AND (sales_quotation_h.branch_code = '$branch_code') AND (sales_quotation_h.doc_no = '$doc_no')";
    $stmt = $con->prepare($QUERY); 
    $stmt->execute(array($company_code, $branch_code, $doc_no));
    $CUSTOMER_RECORDS= $stmt->fetch(PDO::FETCH_ASSOC);
    $ROWS = ($CUSTOMER_RECORDS !== False) ? 1 : 0;
    if($ROWS>0)
    {
        $customer=$CUSTOMER_RECORDS["description"];
        $salesman_name=$CUSTOMER_RECORDS["salesman_name"];
        $payment_terms=$CUSTOMER_RECORDS["payment_terms"];
        $delivery_terms= $CUSTOMER_RECORDS["delivery_terms"];
        $delivery_period=  $CUSTOMER_RECORDS["delivery_period"];
        $validity_terms=$CUSTOMER_RECORDS["validity_terms"];
        $exclusion=$CUSTOMER_RECORDS["exclusion"];
        $warranty_terms=$CUSTOMER_RECORDS["warranty_terms"];
    }
    
    ?>
    <form method="post" >
    <input type="hidden" name="doc_no" value="<?php echo $doc_no;?>"> 
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="10"   class= "btn-primary"><h5 ><b> Quotation Details (Doc No:  <?php echo " ".$doc_no."; Customer: $customer; Salesman: $salesman_name)"; ?></b></h5></td>
  </tr>	
    <tr>
     <th>Sl. No.</th>
      <th>Item Code</th>
      <th>Item Description</th>
      
	  <th class="text-center">Qty</th>
      <th class="text-center">Unit</th>
       
      <th class="text-right">Rate</th> 
        <th class="text-right">Gross</th>   
      <th class="text-right">Discount</th> 
      <th class="text-right">VAT</th> 
      <th class="text-right">Net</th>   
        </tr>
  </thead>
  <tbody>
   
  	
  	<?php

  	$QUERY="SELECT   sales_quotation_d.item_no, sales_quotation_d.item_code,
 sales_quotation_d.item_description, sales_quotation_d.trans_qty, 
sales_quotation_d.trans_unit, sales_quotation_d.trans_rate, sales_quotation_d.tax1_amount,sales_quotation_d.remark,sales_quotation_d.narration,
                         sales_quotation_d.discount_amount
FROM            sales_quotation_h INNER JOIN
                         sales_quotation_d ON sales_quotation_h.company_code = sales_quotation_d.company_code
 AND sales_quotation_h.branch_code = sales_quotation_d.branch_code AND 
                         sales_quotation_h.doc_no = sales_quotation_d.doc_no
WHERE        (sales_quotation_h.company_code = '$company_code') AND
 (sales_quotation_h.branch_code = '$branch_code') AND (sales_quotation_h.approved = 'N') 
AND (sales_quotation_h.cancelled = 'N') AND (sales_quotation_h.doc_no = '$doc_no')";
        

$stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
$nettotal=0;
$i=1;
while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
{
    //
    ?>
    <tr>
       
      
     	 <td><?php echo $i; ?></td>
       <td><?php echo $REPORT["item_code"]; ?></td>
       <?php 
       $remarks=$REPORT["item_description"];
       if( $REPORT["remark"]<>"" )
       		$remarks=$remarks."</br>".$REPORT["item_description"];
       	
       	 if( $REPORT["narration"]<>"" )
       		$remarks=$remarks."</br>".$REPORT["narration"];
       		?>
      <td><?php echo $remarks; ?></td>
      
      <td class="text-center"><?php echo round( $REPORT["trans_qty"],2);  ?>   </td>
      <td class="text-center"><?php echo  $REPORT["trans_unit"];  ?>   </td>
      
      <td class="text-right"><?php echo round( $REPORT["trans_rate"],2);  ?></td>
       <td class="text-right"><?php echo round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2);  ?></td>
      <td class="text-right"><?php echo  round( $REPORT["discount_amount"],2);  ?></td>
      
      <td class="text-right"><?php echo  round( $REPORT["tax1_amount"],2);  ?></td>
      <td class="text-right"><?php echo round( round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2)- $REPORT["discount_amount"],2)+ round( $REPORT["tax1_amount"],2);  ?></td>
     
  	 <tr>
 <?php 
 $nettotal=$nettotal+round( round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2)- $REPORT["discount_amount"],2)+ round( $REPORT["tax1_amount"],2);
 $i=$i+1;
} 
 

?>
<tr><td colspan="7"></td><td colspan="2"><b>Net Total</b></td><td><b><?php echo  $nettotal;  ?></b></td> </tr>
  </tbody>
  </table>
</div>
  
  <div class="al-table-wrap mt-4"><table class="al-table">
   <thead>
    <tr>
    <td colspan="9"    ><h4><b> Terms & Conditions</b></h4></td>
  </tr>	
    </thead>
  <tbody>
  	<tr>
  		<td><b>1). Payment Terms: <?php echo $payment_terms;?></b></td>
  	
  	</tr>
  	
  	<tr>
  		<td><b>2). Delivery Terms:<?php echo $delivery_terms;?></b></td>
  	
  	</tr>
  	
  	<tr>
  		<td><b>3). Delivery Period :<?php echo $delivery_period;?></b></td>
  	
  	</tr>
  	
  	
  	 
  	
  		<tr>
  		<td><b>4). Validity :<?php echo $validity_terms;?></b></td>
  	
  	</tr>
  	
  	 
  	<tr>
  		<td><b>5). Exclusions :<?php echo $exclusion;?></b></td>
  	
  	</tr>
  	<tr>
  		<td><b>6). Warranty :<?php echo $warranty_terms;?></b></td>
  	
  	</tr>
  	
  </tbody>
  
  </table>
</div>
  </br>
  
  <div class="al-form-actions">
   <button type="submit" class="al-btn al-btn-approve" name="QT"><i class="fa fa-check"></i> Approve</button>
   <a class="al-home-btn al-home-btn--ghost" href="<?php echo htmlspecialchars($approvalline_embed_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-arrow-left"></i> Back to list</a>
   </div>
   </form>
 <?php   
}
elseif( $doc_type=="SO" && $_SESSION["SO"]=="Y")
{ ?>
    <form method="post" >
    <input type="hidden" name="doc_no" value="<?php echo $doc_no;?>"> 
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="9"   class= "btn-primary"><h5 > Sales Order Details <?php echo " ".$doc_no; ?></h5></td>
  </tr>	
    <tr>
     
      <th>Item Code</th>
      <th>Item Description</th>
      
	  <th class="text-center">Qty</th>
      <th class="text-center">Unit</th>
       
      <th class="text-right">Rate</th> 
        <th class="text-right">Gross</th>   
      <th class="text-right">Discount</th> 
      <th class="text-right">Net</th>   
        </tr>
  </thead>
  <tbody>
   
  	
  	<?php
 
  	
  	$QUERY="SELECT   sales_order_d.item_code, sales_order_d.item_description,
     sales_order_d.trans_qty, sales_order_d.trans_unit, sales_order_d.discount_amount,
     sales_order_d.trans_rate FROM            sales_order_h INNER JOIN
  	sales_order_d ON sales_order_h.company_code = sales_order_d.company_code 
AND sales_order_h.branch_code = sales_order_d.branch_code AND 
sales_order_h.doc_no = sales_order_d.doc_no
  	WHERE        (sales_order_h.company_code = '$company_code') AND
 (sales_order_h.branch_code = '$branch_code') AND (sales_order_h.approved = 'N')
 AND (sales_order_h.cancelled = 'N') and sales_order_d.doc_no ='$doc_no'";
        

$stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
{
    //
    ?>
    <tr>
       
      
     
       <td><?php echo $REPORT["item_code"]; ?></td>
      <td><?php echo $REPORT["item_description"]; ?></td>
      
      <td class="text-center"><?php echo round( $REPORT["trans_qty"],2);  ?>   </td>
      <td class="text-center"><?php echo  $REPORT["trans_unit"];  ?>   </td>
      
      <td class="text-right"><?php echo round( $REPORT["trans_rate"],2);  ?></td>
       <td class="text-right"><?php echo round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2);  ?></td>
      <td class="text-right"><?php echo  $REPORT["discount_amount"];  ?></td>
      <td class="text-right"><?php echo round( round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2)- $REPORT["discount_amount"],2);  ?></td>
  	 <tr>
 <?php 
} 

?>
  </tbody>
  </table>
</div>
  <div class="al-form-actions">
   <button type="submit" class="al-btn al-btn-approve" name="SO"><i class="fa fa-check"></i> Approve</button>
   <a class="al-home-btn al-home-btn--ghost" href="<?php echo htmlspecialchars($approvalline_embed_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-arrow-left"></i> Back to list</a>
   </div>
   </form>
 <?php   
}
elseif( $doc_type=="PI" && $_SESSION["PI"]=="Y")
{ ?>
    <form method="post" >
    <input type="hidden" name="doc_no" value="<?php echo $doc_no;?>"> 
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="9"   class= "btn-primary"><h5 > Proforma Invoice Details <?php echo " ".$doc_no; ?></h5></td>
  </tr>	
    <tr>
     
      <th>Item Code</th>
      <th>Item Description</th>
      
	  <th class="text-center">Qty</th>
      <th class="text-center">Unit</th>
       
      <th class="text-right">Rate</th> 
        <th class="text-right">Gross</th>   
      <th class="text-right">Discount</th> 
      <th class="text-right">Net</th>   
        </tr>
  </thead>
  <tbody>
   
  	
  	<?php
 
  	
 
  	
  	$QUERY="SELECT        proforma_d.item_code, proforma_d.item_description,
     proforma_d.trans_qty, proforma_d.trans_unit, proforma_d.trans_rate,
  proforma_d.discount_amount
  	FROM            proforma_d INNER JOIN
  	proforma_h ON proforma_d.company_code = proforma_h.company_code AND
 proforma_d.branch_code = proforma_h.branch_code AND proforma_d.doc_no = proforma_h.doc_no
  	WHERE    (proforma_h.company_code = '$company_code')  
AND (proforma_h.branch_code = '$branch_code')  AND   
  (proforma_h.doc_no = '$doc_no')  ";
        

$stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
{
    //
    ?>
    <tr>
       
      
     
       <td><?php echo $REPORT["item_code"]; ?></td>
      <td><?php echo $REPORT["item_description"]; ?></td>
      
      <td class="text-center"><?php echo round( $REPORT["trans_qty"],2);  ?>   </td>
      <td class="text-center"><?php echo  $REPORT["trans_unit"];  ?>   </td>
      
      <td class="text-right"><?php echo round( $REPORT["trans_rate"],2);  ?></td>
       <td class="text-right"><?php echo round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2);  ?></td>
      <td class="text-right"><?php echo round( $REPORT["discount_amount"],2);  ?></td>
      <td class="text-right"><?php echo round( round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2)- $REPORT["discount_amount"],2);  ?></td>
  	 <tr>
 <?php 
} 

?>
  </tbody>
  </table>
</div>
  <div class="al-form-actions">
   <button type="submit" class="al-btn al-btn-approve" name="PI"><i class="fa fa-check"></i> Approve</button>
   <a class="al-home-btn al-home-btn--ghost" href="<?php echo htmlspecialchars($approvalline_embed_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-arrow-left"></i> Back to list</a>
   </div>
   </form>
 <?php   
}
elseif( $doc_type=="DR" && $_SESSION["DR"]=="Y")
{ ?>
    <form method="post" >
    <input type="hidden" name="doc_no" value="<?php echo $doc_no;?>"> 
    <div class="al-table-wrap"><table class="al-table">
    <thead>
    <tr>
    <td colspan="9"   class= "btn-primary"><h5 > Delivery Request Details <?php echo " ".$doc_no; ?></h5></td>
  </tr>	
    <tr>
     
      <th>Item Code</th>
      <th>Item Description</th>
      
	  <th class="text-center">Qty</th>
      <th class="text-center">Unit</th>
       <!--  
      <th class="text-right">Rate</th> 
        <th class="text-right">Gross</th>   
      <th class="text-right">Discount</th> 
      <th class="text-right">Net</th>   -->
        </tr>
  </thead>
  <tbody>
   
  	
  	<?php
 
  	
  	$QUERY="SELECT   delivery_request_d.item_code, delivery_request_d.item_description,
 delivery_request_d.trans_qty, delivery_request_d.trans_unit,
 delivery_request_d.trans_rate, delivery_request_d.discount_amount
FROM  delivery_request_d INNER JOIN
  delivery_request_h ON delivery_request_d.company_code = delivery_request_h.company_code AND delivery_request_d.branch_code = delivery_request_h.branch_code AND 
  delivery_request_d.doc_no = delivery_request_h.doc_no
WHERE        (delivery_request_h.company_code = '$company_code') 
AND (delivery_request_h.branch_code = '$branch_code') AND (delivery_request_h.doc_no = '$doc_no')";
        

$stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
while($REPORT = $stmt->fetch(PDO::FETCH_ASSOC))
{
    //
    ?>
    <tr>
       
      
     
       <td><?php echo $REPORT["item_code"]; ?></td>
      <td><?php echo $REPORT["item_description"]; ?></td>
      
      <td class="text-center"><?php echo round( $REPORT["trans_qty"],2);  ?>   </td>
      <td class="text-center"><?php echo  $REPORT["trans_unit"];  ?>   </td>
      
   <!--    <td class="text-right"><?php echo round( $REPORT["trans_rate"],2);  ?></td>
       <td class="text-right"><?php echo round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2);  ?></td>
      <td class="text-right"><?php echo  $REPORT["discount_amount"];  ?></td>
      <td class="text-right"><?php echo round( round( $REPORT["trans_rate"]*$REPORT["trans_qty"],2)- $REPORT["discount_amount"],2);  ?></td>
      --> 
  	 <tr>
 <?php 
} 

?>
  </tbody>
  </table>
</div>
  <div class="al-form-actions">
   <button type="submit" class="al-btn al-btn-approve" name="DR"><i class="fa fa-check"></i> Approve</button>
   <a class="al-home-btn al-home-btn--ghost" href="<?php echo htmlspecialchars($approvalline_embed_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa fa-arrow-left"></i> Back to list</a>
   </div>
   </form>
 <?php   
}
include __DIR__ . '/includes/approval_layout_end.php';
}
else
{
     
   if(isset($_POST["approvemr"]))
   {
    $arrlength = count($_POST['approvedqty']);
    $doc_no=$_POST["doc_no"];
    for($x = 0; $x < $arrlength; $x++) 
    { 
        
        
         
       $appqty=$_POST['approvedqty'][$x];
       $serial_no=$_POST['serial_no'][$x];  
       $remarks=$_POST['remarks'][$x]; 
       $request_qty=$_POST['request_qty'][$x]; 
      
       if($request_qty==$appqty)
       {
           $approved="Y";
       }
       else
       {
           $approved="N";
       }
       if( $approved=="Y")
       {
           $dt=date("Y-m-d H:i:s");
           $QUERY="UPDATE material_request_d SET approved = 'Y', approved_by = '$user',
         trans_qty = $appqty,approved_remark='$remarks', approved_date ='$dt'
       WHERE company_code = '$company_code'
       AND branch_code = '$branch_code' AND serial_no = '$serial_no' AND doc_no = '$doc_no' ";
             
       }
       else 
       {
           $QUERY="UPDATE material_request_d SET trans_qty = $appqty,approved_remark='$remarks'
       WHERE company_code = '$company_code'
       AND branch_code = '$branch_code' AND serial_no = '$serial_no' AND doc_no = '$doc_no' ";
       }
      //echo $QUERY;
       $stmt = $con->prepare($QUERY); $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));; 
       
    }
   
    
    $doc_type_caption="Material Request";
    
    $QUERY="SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN material_request_h ON branch_user.branch_code =
            material_request_h.branch_code AND branch_user.user_id = material_request_h.user_id
            WHERE doc_no = '$doc_no'  AND  company_code = '$company_code' and material_request_h.branch_code = '$branch_code'";
    //  echo $QUERY;
    $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
    $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $to_email_id = erp_fetch_val($ROWS, 'user_email');
    $to_user = erp_fetch_val($ROWS, 'user_id');
    
    
    $QUERY="SELECT user_email FROM  branch_user WHERE branch_code = '$branch_code' AND user_id = '$user'";
    $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
    $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
    $cc_email_id = erp_fetch_val($ROWS, 'user_email');
    // echo "CC:".$cc_email_id;
    
    
    
    /* echo "To:".$to_email_id;
     echo "User:".$to_user; */
    
    
    
    
    $subject = $doc_type_caption." ".$doc_no." has been approved";
    $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
    $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
    $headers .= "CC: ".$cc_email_id. "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
    
    /*  echo "Subject:".$subject;
     echo "Message:".$message;
     echo "Header:".$headers;*/
    
  // mail($to_email_id, $subject, $message, $headers);
  
  //  header("Location: http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no");
    
    
    
   // header("Location: test.php?to_email_id=$to_email_id&cc_email=$cc_email_id&doc_type=$doc_type&doc_no=$doc_no");
    
    /*
    
    if($_SERVER['HTTP_HOST']=="localhost")
        $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
        else
            $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
            header("Location: $url");
    */
  echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($approvalline_embed_url) . ";</script>";  
        
   }
   elseif(isset($_POST["approvepr"]))
   {
       $arrlength = count($_POST['approvedqty']);
       $doc_no=$_POST["doc_no"];
       for($x = 0; $x < $arrlength; $x++)
       {
           
           
           
           $appqty=$_POST['approvedqty'][$x];
           $serial_no=$_POST['serial_no'][$x];
          // $remarks=$_POST['remarks'][$x];
           $request_qty=$_POST['request_qty'][$x];
           
           if($request_qty==$appqty)
           {
               $approved="Y";
           }
           else
           {
               $approved="N";
           }
           if( $approved=="Y")
           {
               $dt=date("Y-m-d H:i:s");
               
               $QUERY="UPDATE purchase_request_d SET approved = 'Y', 
                approved_by = '$user', approved_date = '$dt' WHERE 
               company_code = '$company_code' AND branch_code = '$branch_code'
             AND doc_no = '$doc_no' AND serial_no = '$serial_no' ";
              
               
           }
           else
           {
               $QUERY="UPDATE purchase_request_d SET trans_qty = $appqty 
       WHERE company_code = '$company_code'
       AND branch_code = '$branch_code' AND serial_no = '$serial_no' AND doc_no = '$doc_no' ";
           }
           //echo $QUERY;
           $stmt = $con->prepare($QUERY); $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));;
           
       }
       
       
       $doc_type_caption="Purchase Request";
       
       $QUERY="SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN purchase_request_h ON branch_user.branch_code =
            purchase_request_h.branch_code AND branch_user.user_id = purchase_request_h.user_id
            WHERE doc_no = '$doc_no'  AND  company_code = '$company_code'
            and purchase_request_h.branch_code = '$branch_code'";
       //  echo $QUERY;
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       
       $to_email_id = erp_fetch_val($ROWS, 'user_email');
       $to_user = erp_fetch_val($ROWS, 'user_id');
       
       
       $QUERY="SELECT user_email FROM  branch_user WHERE branch_code = '$branch_code' AND user_id = '$user'";
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       $cc_email_id = erp_fetch_val($ROWS, 'user_email');
       // echo "CC:".$cc_email_id;
       
       
       
       /* echo "To:".$to_email_id;
        echo "User:".$to_user; */
       
       
       
       
       $subject = $doc_type_caption." ".$doc_no." has been approved";
       $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
       $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
       $headers .= "CC: ".$cc_email_id. "\r\n";
       $headers .= "MIME-Version: 1.0\r\n";
       $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
       
       $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
       
       /*  echo "Subject:".$subject;
        echo "Message:".$message;
        echo "Header:".$headers;*/
       
       // mail($to_email_id, $subject, $message, $headers);
       
       //  header("Location: http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no");
       
       
       
       // header("Location: test.php?to_email_id=$to_email_id&cc_email=$cc_email_id&doc_type=$doc_type&doc_no=$doc_no");
       
       /*
       
       if($_SERVER['HTTP_HOST']=="localhost")
           $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
           else
               $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
               header("Location: $url");
          */     
               echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($approvalline_embed_url) . ";</script>";  
               
   }
   elseif(isset($_POST["approvepo"]))
   {
     // echo "Approve PO";
       
       $doc_type_caption="Purchase Order";
       $dt=date("Y-m-d H:i:s");
       $QUERY="UPDATE purchase_order_h SET approved = 'Y', approved_by = '$user',
            approved_date = '$dt' WHERE company_code = '$company_code'
             AND branch_code = '$branch_code'  AND doc_no ='$doc_no'";
       // echo $QUERY;
       $stmt = $con->prepare($QUERY); $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));;
       
       
       $QUERY="SELECT  purchase_order_h.user_id, branch_user.user_email  FROM   purchase_order_h INNER JOIN
        branch_user ON purchase_order_h.branch_code = branch_user.branch_code AND purchase_order_h.user_id = branch_user.user_id
        WHERE doc_no = '$doc_no'  AND  company_code = '$company_code' and purchase_order_h.branch_code = '$branch_code'";
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       
       $to_email_id = erp_fetch_val($ROWS, 'user_email');
       $to_user = erp_fetch_val($ROWS, 'user_id');
       
       
       
       
       
       $QUERY="SELECT user_email FROM  branch_user WHERE branch_code = '$branch_code' AND user_id = '$user'";
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       $cc_email_id = erp_fetch_val($ROWS, 'user_email');
     /*  echo "CC:".$cc_email_id;*/
       
       
       
     /*  echo "To:".$to_email_id;
        echo "User:".$to_user;  */
       
       
       
       
       $subject = $doc_type_caption." ".$doc_no." has been approved";
       $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
       $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
       $headers .= "CC: ".$cc_email_id. "\r\n";
       $headers .= "MIME-Version: 1.0\r\n";
       $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
       
       $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
       
       /*  echo "Subject:".$subject;
        echo "Message:".$message;
        echo "Header:".$headers; */
       
      // mail($to_email_id, $subject, $message, $headers);
      
    //header("Location: http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no");
       
       /*
       if($_SERVER['HTTP_HOST']=="localhost")
           $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
           else
               $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
               header("Location: $url");
       
       */
       
       
       
       
       echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($approvalline_embed_url) . ";</script>";    
       
       
       
   }
   elseif(isset($_POST["QT"]))
   {
       // echo "Approve PO";
       
       $doc_type_caption="Sales Quotation";
       $dt=date("Y-m-d H:i:s");
       
       $QUERY="UPDATE sales_quotation_h SET approved = 'Y', approved_by = '$user',
        approved_date =  '$dt' WHERE  company_code = '$company_code'
            AND branch_code = '$branch_code' AND doc_no = '$doc_no'";
       
       // echo $QUERY;
       $stmt = $con->prepare($QUERY); $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));;
       
       
       
       $QUERY="SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN sales_quotation_h ON branch_user.branch_code =
            sales_quotation_h.branch_code AND branch_user.user_id = sales_quotation_h.user_id
            WHERE doc_no = '$doc_no'  AND  company_code = '$company_code' and
            sales_quotation_h.branch_code = '$branch_code'";
       
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       
       $to_email_id = erp_fetch_val($ROWS, 'user_email');
       $to_user = erp_fetch_val($ROWS, 'user_id');
       
       
       
       
       
       $QUERY="SELECT user_email FROM  branch_user WHERE branch_code = '$branch_code' AND user_id = '$user'";
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       $cc_email_id = erp_fetch_val($ROWS, 'user_email');
       /*  echo "CC:".$cc_email_id;*/
       
       
       
       /*  echo "To:".$to_email_id;
        echo "User:".$to_user;  */
       
       
       
       
       $subject = $doc_type_caption." ".$doc_no." has been approved";
       $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
       $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
       $headers .= "CC: ".$cc_email_id. "\r\n";
       $headers .= "MIME-Version: 1.0\r\n";
       $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
       
       $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
       
        
     
       
       /*
       if($_SERVER['HTTP_HOST']=="localhost")
           $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
           else
               $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
               header("Location: $url");
       
       */
       
       
       
       
       echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($approvalline_embed_url) . ";</script>";
       
       
       
   }
 
elseif(isset($_POST["DR"]))
{
    // echo "Approve PO";
    
    $doc_type_caption="Delivery Request";
    $dt=date("Y-m-d H:i:s");
    
    $QUERY="UPDATE delivery_request_h SET approved = 'Y', approved_by ='$user',
         approved_date = '$dt'  WHERE company_code = '$company_code'
            AND branch_code = '$branch_code' AND doc_no = '$doc_no'";
    
    // echo $QUERY;
    $stmt = $con->prepare($QUERY); $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));;
    
    
    
    $QUERY="SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN delivery_request_h ON branch_user.branch_code =
            delivery_request_h.branch_code AND branch_user.user_id = delivery_request_h.user_id
            WHERE doc_no = '$doc_no'  AND  company_code = '$company_code' and delivery_request_h.branch_code = '$branch_code'";
    
    $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
    $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $to_email_id = erp_fetch_val($ROWS, 'user_email');
    $to_user = erp_fetch_val($ROWS, 'user_id');
    
    
    
    
    
    $QUERY="SELECT user_email FROM  branch_user WHERE branch_code = '$branch_code' AND user_id = '$user'";
    $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
    $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
    $cc_email_id = erp_fetch_val($ROWS, 'user_email');
    /*  echo "CC:".$cc_email_id;*/
    
    
    
    /*  echo "To:".$to_email_id;
     echo "User:".$to_user;  */
    
    
    
    
    $subject = $doc_type_caption." ".$doc_no." has been approved";
    $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
    $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
    $headers .= "CC: ".$cc_email_id. "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
    
    
    
     /*
    
    if($_SERVER['HTTP_HOST']=="localhost")
        $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
        else
            $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
            header("Location: $url");
            
           */ 
            
            
            
            
            echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($approvalline_embed_url) . ";</script>";
            
            
            
}
   elseif(isset($_POST["SO"]))
   {
      
       
       $doc_type_caption="Sales Order";
       $dt=date("Y-m-d H:i:s");
       
       $QUERY= "UPDATE sales_order_h SET approved = 'Y', approved_by =  '$user',
        approved_date = '$dt' WHERE company_code = '$company_code'
        AND branch_code = '$branch_code' AND doc_no = '$doc_no'";
       
       // echo $QUERY;
       $stmt = $con->prepare($QUERY); $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));;
       
       
       
       $QUERY="SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN sales_order_h ON branch_user.branch_code =
            sales_order_h.branch_code AND branch_user.user_id = sales_order_h.user_id
            WHERE doc_no = '$doc_no'  AND  company_code = '$company_code' and
            sales_order_h.branch_code = '$branch_code'";
       
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       
       $to_email_id = erp_fetch_val($ROWS, 'user_email');
       $to_user = erp_fetch_val($ROWS, 'user_id');
       
       
       
       
       
       $QUERY="SELECT user_email FROM  branch_user WHERE branch_code = '$branch_code' AND user_id = '$user'";
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       $cc_email_id = erp_fetch_val($ROWS, 'user_email');
       /*  echo "CC:".$cc_email_id;*/
       
       
       
       /*  echo "To:".$to_email_id;
        echo "User:".$to_user;  */
       
       
       
       
       $subject = $doc_type_caption." ".$doc_no." has been approved";
       $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
       $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
       $headers .= "CC: ".$cc_email_id. "\r\n";
       $headers .= "MIME-Version: 1.0\r\n";
       $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
       
       $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
       
       
       
       /*
       
       if($_SERVER['HTTP_HOST']=="localhost")
           $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
           else
               $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
               header("Location: $url");
               
             */  
               
               
               
               
               echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($approvalline_embed_url) . ";</script>";
               
               
               
   }
   elseif(isset($_POST["PI"]))
   {
       
       
       $doc_type_caption="Sales Order";
       $dt=date("Y-m-d H:i:s");
       
       $QUERY="UPDATE proforma_h SET  approved_by ='$user'
           WHERE company_code = '$company_code'
            AND branch_code = '$branch_code' AND doc_no = '$doc_no'";
       
       // echo $QUERY;
       $stmt = $con->prepare($QUERY); $stmt->execute(array('Y', $user, $dt, $company_code, $branch_code, $doc_no));;
       
       
       
       $QUERY="SELECT        branch_user.user_email, branch_user.user_id
            FROM            branch_user INNER JOIN proforma_h ON branch_user.branch_code =
            proforma_h.branch_code AND branch_user.user_id = proforma_h.user_id
            WHERE doc_no = '$doc_no'  AND  company_code = '$company_code' and proforma_h.branch_code = '$branch_code'";
       
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       
       $to_email_id = erp_fetch_val($ROWS, 'user_email');
       $to_user = erp_fetch_val($ROWS, 'user_id');
       
       
       
       
       
       $QUERY="SELECT user_email FROM  branch_user WHERE branch_code = '$branch_code' AND user_id = '$user'";
       $stmt = $con->prepare($QUERY); $stmt->execute(array($company_code, $branch_code, $doc_no));
       $ROWS = $stmt->fetch(PDO::FETCH_ASSOC);
       $cc_email_id = erp_fetch_val($ROWS, 'user_email');
       /*  echo "CC:".$cc_email_id;*/
       
       
       
       /*  echo "To:".$to_email_id;
        echo "User:".$to_user;  */
       
       
       
       
       $subject = $doc_type_caption." ".$doc_no." has been approved";
       $headers = "From: " . strip_tags("support@infoserp.com" )."\r\n";
       $headers .= "Reply-To: ". strip_tags($to_email_id) . "\r\n";
       $headers .= "CC: ".$cc_email_id. "\r\n";
       $headers .= "MIME-Version: 1.0\r\n";
       $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
       
       $message = "<p>Dear ".$to_user.", <br/><br/>Your ".$doc_type_caption." <strong>".$doc_no."</strong> has been Approved by ".$user.".</p>";
       
       
       
       /*
       
       if($_SERVER['HTTP_HOST']=="localhost")
           $url="sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
           else
               $url="http://bpi.infoserp.com/sendmail.php?to_email_id=$to_email_id&cc_email=$cc_email_id&user_requestor=$to_user&user_approver=$user&doc_type=$doc_type&doc_no=$doc_no";
               header("Location: $url");
               
             */  
               
               
               
               
               echo "<script type='text/javascript'>
              alert('". $doc_type_caption." ".$doc_no." has been approved successfully');
               window.location.href=" . json_encode($approvalline_embed_url) . ";</script>";
               
               
               
   }
}
?>