<?php

class fm_recaptchaControl extends fm_controlBase{

	var $err;

	public function getTypeName(){ return "recaptcha"; }

	/* translators: this appears in the 'Add Form Element' menu */
	public function getTypeLabel(){ return __("reCAPTCHA", 'wordpress-form-manager'); }

	public function getThemeList(){
		return array('dark' => __("Dark", 'wordpress-form-manager'), 'light' => __("Light", 'wordpress-form-manager'));
	}

	public function showItem($uniqueName, $itemInfo){
		global $fmdb;
		$publickey = $fmdb->getGlobalSetting('recaptcha_public');
		if ( empty($publickey) ) {
      return __("(No reCAPTCHA API public key found)", 'wordpress-form-manager');
    }

		$theme = $itemInfo['extra']['theme'];
		$themeList = $this->getThemeList();

		if ( ! (isset($theme) && isset($themeList[$theme]) )){
			$theme = $fmdb->getGlobalSetting('recaptcha_theme');
		}

		return sprintf('
      <script src="https://www.google.com/recaptcha/api.js" async defer></script>
      <div class="g-recaptcha"
        data-sitekey = "%s"
        data-theme = "%s"
        data-type = "%s"
        data-size = "%s"
        data-tabindex = "%s"
        data-callback = "%s"
        data-expired-callback = "%s"
        data-error-callback = "%s"
      ></div>',
      $publickey,
      $theme,
      'image',
      'normal',
      100,
      '',
      '',
      ''
    );
	}

	public function processPost($uniqueName, $itemInfo){
		global $fmdb;
		$publickey = $fmdb->getGlobalSetting('recaptcha_public');
		$privatekey = $fmdb->getGlobalSetting('recaptcha_private');
		if($privatekey == "" || $publickey == "" ) return "";

		$resp = $this->recaptcha_v2_check(
      $privatekey,
  		$_POST["g-recaptcha-response"],
      $_SERVER["REMOTE_ADDR"]
    );

    if ( is_wp_error( $resp ) ) {
      $this->err = $resp->get_error_message();
        return false;
    } elseif ( ! $resp->is_valid === true ) {
      $this->err = $resp->error;
        return false;
    }
		$this->err = false;
		return "";
	}

	public function itemDefaults(){
		$itemInfo = array();
		$itemInfo['label'] = "New reCAPTCHA";
		$itemInfo['description'] = "Item Description";
		$itemInfo['extra'] = array( 'theme' => 'default' );
		$itemInfo['nickname'] = '';
		$itemInfo['required'] = 0;
		$itemInfo['validator'] = "";
		$ItemInfo['validation_msg'] = "";
		$itemInfo['db_type'] = "NONE";

		return $itemInfo;
	}

	public function editItem($uniqueName, $itemInfo){
		global $fmdb;
		$publickey = $fmdb->getGlobalSetting('recaptcha_public');
		$privatekey = $fmdb->getGlobalSetting('recaptcha_private');
		if($publickey == "" || $privatekey == "") return __("You need reCAPTCHA API keys.", 'wordpress-form-manager')." <br /> ".__("Fix this in", 'wordpress-form-manager')." <a href=\"".get_admin_url(null, 'admin.php')."?page=fm-global-settings\">".__("Settings", 'wordpress-form-manager')."</a>.";
		return __("(reCAPTCHA field)", 'wordpress-form-manager');
	}

	public function getPanelItems($uniqueName, $itemInfo){
		$arr=array();
		$arr[] = new fm_editPanelItemBase($uniqueName, 'label', __('Label', 'wordpress-form-manager'), array('value' => $itemInfo['label']));
		$arr[] = new fm_editPanelItemDropdown($uniqueName, 'theme', __('Style', 'wordpress-form-manager'),
						array('options' => array_merge( array( 'default' => 'light' ), $this->getThemeList() ),
							'value' => $itemInfo['extra']['theme'])
						);
		return $arr;
	}

	public function getPanelScriptOptions(){
		$opt = $this->getPanelScriptOptionDefaults();
		$opt['extra'] = $this->extraScriptHelper(array('theme' => 'theme'));
		return $opt;
	}

	public function getShowHideCallbackName(){
		return "fm_recaptcha_show_hide";
	}

	protected function showExtraScripts(){
		?><script type="text/javascript">
//<![CDATA[
		function fm_recaptcha_show_hide(itemID, isDone){
			if(isDone){
				document.getElementById(itemID + '-edit-label').innerHTML = document.getElementById(itemID + '-label').value;
			}
		}
//]]>
</script>
		<?php
	}

	public function showUserScripts(){

	}

	protected function getPanelKeys(){
		return array('label');
	}

  function recaptcha_v2_check ($secret, $response, $remoteip) {
    if ($secret == null || $secret == '') {
      die ("To use reCAPTCHA you must get an API key from <a href='https://www.google.com/recaptcha/admin/create'>https://www.google.com/recaptcha/admin/create</a>");
    }

    if ($remoteip == null || $remoteip == '') {
      die ("For security reasons, you must pass the remote ip to reCAPTCHA");
    }

    //discard spam submissions
    if ($response == null || strlen($response) == 0) {
      $recaptcha_response = new \stdClass();
      $recaptcha_response->is_valid = false;
      $recaptcha_response->error = 'incorrect-captcha-sol';
      return $recaptcha_response;
    }

    $call = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
      'method' => 'POST',
      'timeout' => 45,
      'redirection' => 5,
      'httpversion' => '1.0',
      'blocking' => true,
      'headers' => array(),
      'body' => array (
        'secret'    => $secret,
        'remoteip'  => $remoteip,
        'response'  => $response
      ),
      'cookies' => array()
    ) );

    // Check the response code
    $response_code       = wp_remote_retrieve_response_code( $call );
    $response_message = wp_remote_retrieve_response_message( $call );

    if ( 200 != $response_code && ! empty( $response_message ) ) {
      return new WP_Error( $response_code, $response_message );
    } elseif ( 200 != $response_code ) {
      return new WP_Error( $response_code, 'Unknown error occurred' );
    } else {
      $body = json_decode( wp_remote_retrieve_body( $call ) );

      $recaptcha_response = new \stdClass();

      if ($body->success) {
        $recaptcha_response->is_valid = true;
      } else {
        $recaptcha_response->is_valid = false;
        $recaptcha_response->error = ! empty( $body->error_codes ) ? implode(', ', $body->error_codes) : 'recaptcha error';
      }
      return $recaptcha_response;
    }
  }

}
?>