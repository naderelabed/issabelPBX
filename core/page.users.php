<?php /* $Id: page.users.php 1131 2006-03-14 13:47:35Z mheydon1973 $ */
//Copyright (C) 2004 Coalescent Systems Inc. (info@coalescentsystems.ca)
//

if (!defined('ISSABELPBX_IS_AUTH')) { die('No direct script access allowed'); }
?>

<?php 
$extens = core_users_list();
drawListMenu($extens, $type, $display, $extdisplay);
?>

<?php
// Javascript functions could be put into the configpageinit function but I personally prefer
// to code JavaScript in a web page //
?>

<script language="javascript">
<!--

function checkBlankUserPwd() {
	msgConfirmBlankUserPwd = "<?php echo __('You have not entered a User Password.  While this is acceptable, this user will not be able to login to an AdHoc device.\n\nAre you sure you wish to leave the User Password empty?'); ?>";

	// check for password and warn if none entered
	if (isEmpty(theForm.password.value)) {
		var cnf = confirm(msgConfirmBlankUserPwd);
		if (!cnf) {
			theForm.password.focus();
			return false;
		}
	}
	return true;
}

//-->
</script>
