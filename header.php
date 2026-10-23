<?php 
require('db.php');
include("auth.php");
 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="">
    <meta name="author" content="">
	 
   <link href="vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/metisMenu/metisMenu.min.css" rel="stylesheet">
    <link href="dist/css/sb-admin-2.css" rel="stylesheet">
    <link href="vendor/datatables-plugins/dataTables.bootstrap.css" rel="stylesheet">
    <link href="vendor/datatables-responsive/dataTables.responsive.css" rel="stylesheet">
    <link href="vendor/morrisjs/morris.css" rel="stylesheet">
    <link href="vendor/font-awesome/css/font-awesome.min.css" rel="stylesheet" type="text/css">
     <link rel="stylesheet" href="//cdnjs.cloudflare.com/ajax/libs/bootstrap-select/1.6.3/css/bootstrap-select.min.css" />
     <script src="js/jquery-3.1.1.min.js"></script>
     
     <style type="text/css">
    .dm-content
    {
        margin-left:10px;
        margin-right:10px;
    }
     
     </style>
        <script type="text/javascript">
           
			window.onload = loaddata;
			function loaddata()
			{
			 
			 
			  $.ajax({
			  type: 'post',
			  url: 'loaddata.php',
			  success: function (response) {
			   // We get the element having id of display_info and put the response inside it
			   $( '#display_info' ).html(response);
			  }
			  });
			 
			}

        </script>
</head>