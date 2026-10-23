<?php     
    include("auth.php");  
    require('db.php');
    if (!isset($_SESSION["customer_id"]))
    
    {
        echo "Invalid usage" ;
    }
    else 
    {
       ?>
        <html>
        <head>
            <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
            .simple-login-container h2{
            text-align:center;
            font-size:20px;}
            </style>
        </head>
    <body>
    <br/>
    <br/>
    <br/>
    <br/>
    <br/>
    <br/>
    
    
    <form method="post">
      <div class="form-group col-md-6" style="margin:auto;">
        <label for="company"><b>Select Company:</b></label>
         <form action="#" >       
     
                	
                        <select class="form-control" name="branch" style="margin:5px;">            
                        <?php            
                        $sel_query="select branch_code,branch_name from infoserp_branch_master where customer_id=".$_SESSION['customer_id']." order by branch_name asc";
                        $result = mysqli_query($con,$sel_query) or die (mysqli_error());
                        while($row = mysqli_fetch_assoc($result))
                        {
                            ?>      
                        
                          <option value=<?php echo $row["branch_code"]; ?>><?php echo $row["branch_name"]; ?></option>
                          <?php }?>               
                        </select>
        <input class="btn btn-primary"  type="submit" name="submit" value="Proceed" />                
      </div>
      
      	
     
       
    </form>
    
     
        				
                        
                    
    <?php
    if(isset($_POST['submit'])){    
  
    $_SESSION['branch_code']= $_POST['branch'];    
    
    //Checking is user existing in the database or not
    $query = "SELECT qt_approval,so_approval,inv_approval,pi_approval,do_approval FROM `infoserp_user_rights` WHERE  customer_id='".$_SESSION["customer_id"]."' and branch_code='".$_SESSION['branch_code']."' and user_id='".$_SESSION['username']."'" ;
    $result = mysqli_query($con,$query) or die(mysqli_error());
    $rows = mysqli_num_rows($result);    
    if($rows>=1)
    {
        
        $d=mysqli_fetch_assoc($result) ;
        if (strtoupper($d["qt_approval"])=="Y")
            $_SESSION['qt']= "Y";
        ELSE
            $_SESSION['qt']= "N";
            
        if (strtoupper($d["so_approval"])=="Y")
             $_SESSION['so']= "Y";
        ELSE
            $_SESSION['so']= "N";
        if (strtoupper($d["inv_approval"])=="Y")
            $_SESSION['si']= "Y";
        ELSE
            $_SESSION['si']= "N";
        if (strtoupper($d["pi_approval"])=="Y")
            $_SESSION['pi']= "Y";
        ELSE
            $_SESSION['pi']= "N";
                        
        if (strtoupper($d["do_approval"])=="Y")
            $_SESSION['do']= "Y";
        ELSE
            $_SESSION['do']= "N";
        
            
            $userquery = "SELECT document_path FROM infoserp_branch_master where branch_code='".$_POST['branch']."'";
            $userresult = mysqli_query($con, $userquery) or die (mysqli_error());
            $d=mysqli_fetch_assoc($userresult) ;
            if ($d['document_path']<>"")
                $_SESSION['document_path']   ="DOCUMENTS/".$d['document_path'];
             else
                $_SESSION['document_path']   ="";       
            
        
                require_once __DIR__ . '/includes/erp_paths.php';
                erp_redirect('home');
                
    }
                else 
                {
                    echo "No approval privilage for the selected user";
        }
}
 ?>
</body>
</html>
<?php }?>
