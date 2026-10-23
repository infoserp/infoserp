<html>
	<head>
		<script type="text/javascript">
		window.onload = function(){
		    var button = document.getElementById('submit');
		   
		 button.click();
		}
		</script>
	</head>
</html>
<body>
<?php
require_once __DIR__ . '/includes/erp_paths.php';
$url = erp_url('login');
?>
 <form method=post action="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>">
     <input type="hidden" name="customer_id" value="1"><br>
     <input type="hidden" name="application_id" value="1"><br>
    <button  type="submit" value="" id="submit"  style="display: none;"></button>
</form>
</body>
</html>
