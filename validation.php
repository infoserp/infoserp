<?php
 	if(!isset($_SESSION['customer_id']))
    {
        echo "Customer Id is not selected";
        exit();
    }
    elseif(!isset($_SESSION['branch_code']))
    {
        echo "Branch code not selected";
        exit();
    }  
 ?>