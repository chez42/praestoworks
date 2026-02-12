<?php
	if(isset($_REQUEST['id'])){
		echo '<script>window.opener.afterRedirect('.  $_REQUEST['id'] . ');window.close();</script>';
	} else {
		echo '<script>window.opener.afterRedirect();window.close();</script>';
	}
	exit;
?>