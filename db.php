<?php

class fm_db_class{

public $formsTable;
public $itemsTable;
public $settingsTable;
public $templatesTable;
public $showerr;
public $conn;


private $lastErrorMessage;
private $lastPostFailed;
private $lastUniqueName;

function __construct($formsTable, $itemsTable, $settingsTable, $templatesTable, $conn){
	$this->formsTable = $formsTable;
	$this->itemsTable = $itemsTable;
	$this->settingsTable = $settingsTable;
	$this->templatesTable = $templatesTable;
	$this->conn = $conn;
	$this->cachedInfo = array();
	$this->lastPostFailed = false;
	$this->showerr = true;
	$this->initDefaultSettings();
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////

function query($q){
	//echo '<p>'.$q.'</p>';
	$res = mysql_query($q, $this->conn);
	if($this->showerr && mysql_errno()) die(mysql_error());
	return $res;
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//Cache 

//the fm_db_class appears stateless to the user; however we can keep track of some things to make fewer queries.
//	the $cachedInfo variable is an array of arrays indexed by formID; this should only be used to cache data
//	that will not change (such as data table names) and may be queried more than once. 
protected $cachedInfo;

protected function getCache($formID, $key){
	if(!isset($this->cachedInfo[$formID]) || !isset($this->cachedInfo[$formID][$key])) return null; //return null on cache miss, in order to distinguish from 'false'
	else return $this->cachedInfo[$formID][$key];
}
protected function setCache($formID, $key, $value){
	if(!isset($this->cachedInfo[$formID])) $this->cachedInfo[$formID] = array($key => $value);
	else $this->cachedInfo[$formID][$key] = $value;
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//values are the form defaults

public $formSettingsKeys;
public $itemKeys;
public $globalSettings;

function initDefaultSettings(){

$this->formSettingsKeys = array(
					'title' => '',
					'submitted_msg' => '', 
					'submit_btn_text' => __('Submit', 'wordpress-form-manager'), 
					'required_msg' => '', 
					'action' => '',
					'data_index' => '',
					'shortcode' => '',
					'type' => 'form',
					'email_list' => '',
					'behaviors' => '',
					'email_user_field' => '',
					'form_template' => '',
					'email_template' => '',
					'summary_template' => '',
					'template_values' => '',
					'show_summary' => 0,
					'use_advanced_email' => 0,
					'advanced_email' => '',
					'publish_post' => 0,
					'publish_post_category' => '',
					'publish_post_title' => __('%s Submission', 'wordpress-form-manager'),
					'auto_redirect' => 0,
					'auto_redirect_page' => 0,
					'auto_redirect_timeout' => 5,
					'conditions' => ''
					);
					
$this->itemKeys = array (
					'type' => 0,
					'index' => 0,
					'extra' => 0,
					'nickname' => 0,
					'label' => 0,
					'required' => 0,
					'db_type' => 0,
					'description' => 0
					);


$this->globalSettings = array(
					'recaptcha_public' => '',
					'recaptcha_private' => '',
					'recaptcha_theme' => 'red',
					/* translators: the default name of a new form */				
					'title' =>				__("New Form", 'wordpress-form-manager'),	
					'submitted_msg' => 		__('Thank you! Your data has been submitted.', 'wordpress-form-manager'), 
					/* translators: the default message given if a required item is left blank.  You must include a backslash before any single quotes */
					'required_msg' => 		__("\'%s\' is required.", 'wordpress-form-manager'),
					'email_admin' => "YES",
					'email_reg_users' => "YES",
					'template_form' => '',
					'text_validator_count' => 4,
					'text_validator_0' => array('name' => 'number',
												/* translators: the following are for the numbers only validator */
												'label' => __('Numbers Only', 'wordpress-form-manager'),
												'message' => __("'%s' must be a valid number", 'wordpress-form-manager'),
												'regexp' => '/^\s*[0-9]*[\.]?[0-9]+\s*$/'
												),
					'text_validator_1' => array('name' => 'phone',
												/* translators: the following are for the phone number validator */
												'label' => __('Phone Number', 'wordpress-form-manager'),
												'message' => __("'%s' must be a valid phone number", 'wordpress-form-manager'),
												/* translators: the regular expression for US phone numbers (XXX XXX XXXX). */
												'regexp' => __('/^.*[0-9]{3}.*[0-9]{3}.*[0-9]{4}.*$/', 'wordpress-form-manager')
												),
					'text_validator_2' => array('name' => 'email',
												/* translators: the following are for the e-mail validator */
												'label' => __("E-Mail", 'wordpress-form-manager'),
												'message' => __("'%s' must be a valid e-mail address", 'wordpress-form-manager'),
												'regexp' => '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,4}$/'
												),
					'text_validator_3' => array('name' => 'date',
												/* translators: the following are for the date validator */
												'label' => __("Date (MM/DD/YY)", 'wordpress-form-manager'),
												'message' => __("'%s' must be a date (MM/DD/YY)", 'wordpress-form-manager'),
												'regexp' => '/^([0-9]{1,2}[/]){2}([0-9]{2}|[0-9]{4})$/'
												)
					);
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Database setup & removal


function setupFormManager(){
	
	$charset_collate = $this->getCharsetCollation();

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	
	//////////////////////////////////////////////////////////////////
	//form definitions table - stores ID, title, options, and name of data table for each form
	
	/*
		ID					- stores the unique integer ID of the form
		title				- form title
		submitted_msg   	- message displayed when user submits data
		submit_btn_text		- text on the 'submit' button
		required_msg 		- message shown in the 'required' popup; use %s in the string to show the field label. If no string is given, default message is used.				
		data_table 			- table where the form's submissions are stored
		action 				- form 'action' attribute
		data_index 			- data table primary key, if it has one
		shortcode 			- shortcode for the form in question (wordpress only)		
		type 				- type of form ('form', 'template')
		email_list			- list of e-mails to send notifications to
		behaviors			- comma separated list of 'behaviors', such as reg_user_only, etc.
		email_user_field	- the unique name of a field within the form that will contain an e-mail address upon submission, which will be sent a notification
		form_template		- the file name of the form template to use. if blank, uses the default template
		email_template		- same as above, as applies to email notifications
		summary_template	- same as aoove, as applies to the summaries displayed for single submission / user profile style forms
		template_values		- associative array of the template specific values, as set in the form editor
		show_summary		- whether or not to show a summary of the submitted data along with the submission acknowledgment
		use_advanced_email	- whether or not to override the 'E-Mail Notifications' settings on the main form editor
		advanced_email		- the advanced email settings, a block of text defining e-mails, headers, etc.
		publish_post		- whether or not to publish form submissions to a post category
		publish_post_category - the post category to publish submissions to
		publish_post_title 	- the title of published posts
		auto_redirect		- whether or not to do the automatic redirect
		auto_redirect_page	- the page / post ID of the page to go to after (timeout) seconds
		auto_redirect_timeout - the timeout for the automatic redirect
		conditions			- associative array structure, specifying form interface behavior conditions (e.g., only show elements if other elements have certain values)
	*/	
	
	
	$sql = "CREATE TABLE `".$this->formsTable."` (
		`ID` INT DEFAULT '0' NOT NULL,
		`title` TEXT NOT NULL,
		`submitted_msg` TEXT NOT NULL,
		`submit_btn_text` VARCHAR( 32 ) DEFAULT '' NOT NULL,
		`required_msg` TEXT NOT NULL,
		`data_table` VARCHAR( 32 ) DEFAULT '' NOT NULL,
		`action` TEXT NOT NULL,
		`data_index` VARCHAR( 32 ) DEFAULT '' NOT NULL,
		`shortcode` VARCHAR( 64 ) DEFAULT '' NOT NULL,
		`type` VARCHAR( 32 ) DEFAULT '' NOT NULL,
		`email_list` TEXT NOT NULL,
		`behaviors` VARCHAR( 256 ) DEFAULT '' NOT NULL,
		`email_user_field` VARCHAR( 64 ) DEFAULT '' NOT NULL,
		`form_template` VARCHAR( 128 ) DEFAULT '' NOT NULL,
		`email_template` VARCHAR( 128 ) DEFAULT '' NOT NULL,
		`summary_template` VARCHAR( 128 ) DEFAULT '' NOT NULL,
		`template_values` TEXT NOT NULL,
		`show_summary` BOOL DEFAULT '0' NOT NULL,
		`use_advanced_email` BOOL DEFAULT '0' NOT NULL,
		`advanced_email` TEXT NOT NULL,
		`publish_post` BOOL DEFAULT '0' NOT NULL,
		`publish_post_category` TEXT NOT NULL,
		`publish_post_title` TEXT NOT NULL,
		`auto_redirect` BOOL DEFAULT '0' NOT NULL,
		`auto_redirect_page` INT DEFAULT '0' NOT NULL,
		`auto_redirect_timeout` INT DEFAULT '5' NOT NULL,
		`conditions` TEXT NOT NULL,
		PRIMARY KEY  (`ID`)
		) ".$charset_collate.";";

	
	dbDelta($sql);
	
	//create a settings row
	$this->initFormsTable();
	
	//////////////////////////////////////////////////////////////////
	//global settings table
	
	$sql = "CREATE TABLE " . $this->settingsTable . " (
		`setting_name` VARCHAR( 32 ) NOT NULL,
		`setting_value` TEXT NOT NULL,
		PRIMARY KEY  (`setting_name`)
		) ".$charset_collate.";";
	
	dbDelta($sql);	
	
	$this->initSettingsTable();
	
	//////////////////////////////////////////////////////////////////
	//form items - stores the items on all forms
	
	$sql = "CREATE TABLE `".$this->itemsTable."` ( 
				`ID` INT NOT NULL ,							
				`index` INT NOT NULL ,
				`unique_name` VARCHAR( 64 ) DEFAULT '' NOT NULL ,
				`type` VARCHAR( 32 ) DEFAULT '' NOT NULL ,
				`extra` TEXT NOT NULL ,
				`nickname` TEXT NOT NULL ,
				`label` TEXT NOT NULL ,
				`required` BOOL NOT NULL ,
				`db_type` VARCHAR( 16 ) DEFAULT '' NOT NULL ,
				`description` TEXT NOT NULL ,
				INDEX ( `ID` ) ,
				UNIQUE (`unique_name`)
				) ".$charset_collate.";";
		
	dbDelta($sql);
	
	//////////////////////////////////////////////////////////////////
	//templates - even though these are used as files, they are kept track of by and stored in the database so they persist across updates
	
	$sql = "CREATE TABLE `".$this->templatesTable."` (
		`title` TEXT NOT NULL,
		`filename` TEXT NOT NULL,
		`content` TEXT NOT NULL,
		`status` VARCHAR( 32 ) DEFAULT '' NOT NULL,
		`modified` BIGINT NOT NULL
		) ".$charset_collate.";";
	
	dbDelta($sql);
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// for upgrading the database

//fix the collation on the data tables
function fixCollation(){
	
	$charset_collate = $this->getCharsetCollation();
	
	//build a list of tables to fix
	$tableList = array($this->formsTable, $this->itemsTable, $this->settingsTable);

	$formList = $this->getFormList();
	if(sizeof($formList) > 0)
		foreach($formList as $form)
			$tableList[] = $form['data_table'];
	
	//fix the tables
	foreach($tableList as $table){
		$q = "ALTER TABLE `".$table."` DEFAULT ".$charset_collate;
		$this->query($q);
		
		$q = "SHOW FULL COLUMNS FROM `".$table."`";
		$res = $this->query($q);
		$cols = array();
		while($row = mysql_fetch_assoc($res))
			if(!is_null($row['Collation']))
				$cols[] = $row;		
		
		if(sizeof($cols)>0){
			$q = "ALTER TABLE `".$table."` ";
			for($x=0;$x<sizeof($cols);$x++)
				$cols[$x] = "CHANGE `".$cols[$x]['Field']."` `".$cols[$x]['Field']."` ".$cols[$x]['Type']." ".$charset_collate." NOT NULL";
			$q.= implode(" , ", $cols);
			$this->query($q);
		}
			
		mysql_free_result($res);
	}
}

function getCharsetCollation(){
	global $wpdb;
	
	//establish the current charset / collation
	if (!empty($wpdb->charset))
		$charset_collate = "CHARACTER SET ".$wpdb->charset;
	if (!empty($wpdb->collate))
		$charset_collate.= " COLLATE ".$wpdb->collate;
	
	return $charset_collate;
}

//converts from storing certain appearance settings as columns in the database versus options in the standard template
function convertAppearanceSettings(){
	
	//check if this is a fresh install
	$q = "SHOW TABLES LIKE '".$this->formsTable."'";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0) return false;
	
	//check if the old columns exist; if not, no need to do anything
	$q = "SHOW COLUMNS FROM `".$this->formsTable."`";
	$res = $this->query($q);
	$found = false;
	while($row = mysql_fetch_assoc($res))
		if($row['Field'] == 'labels_on_top')
			$found = true;
	
	mysql_free_result($res);	

	if(!$found) return false;
	
	$q = "ALTER TABLE `".$this->formsTable."` ADD `template_values` TEXT NOT NULL ";
	$this->query($q);		
	
	$q = "SELECT * FROM `".$this->formsTable."`";
	$res = $this->query($q);
	$forms = array();
	while($row = mysql_fetch_assoc($res)){
		$forms[] = $row;
	}	
	mysql_free_result($res);
	
	foreach($forms as $form){		
		$values = array( 'showFormTitle' => ($form['show_title']==1?"true":"false"),
						'showBorder' => ($form['show_border']==1?"true":"false"),
						'labelPosition' => ($form['labels_on_top']==1?"top":"left"),
						'labelWidth' => $form['label_width']
						);
		$q = "UPDATE `".$this->formsTable."` SET `template_values` = '".addslashes(serialize($values))."' WHERE `ID` = '".$form['ID']."'";
		$this->query($q);
	}
	
	$q = "ALTER TABLE `".$this->formsTable."`
		  DROP `labels_on_top`,
		  DROP `show_title`,
		  DROP `show_border`,
		  DROP `label_width`;";
	$this->query($q);
}

//fix data tables for versions prior to 1.4.3; adds a column for the user's IP address
function updateDataTables(){
	$q = "SELECT `ID`, `data_table` FROM `".$this->formsTable."` WHERE `ID` > 0";
	$res = $this->query($q);
	$dataTables = array();
	while($row = mysql_fetch_assoc($res)){
		$dataTables[] = $row['data_table'];
	}
	mysql_free_result($res);
	foreach($dataTables as $dataTable){
		$q = "SHOW COLUMNS FROM `".$dataTable."`";
		$res = $this->query($q);
		$found = false;
		$postIDfound = false;
		$uniqueIDfound = false;
		
		while($row = mysql_fetch_assoc($res)){
			if($row['Field'] == 'user_ip')
				$found = true;
			if($row['Field'] == 'post_id')
				$postIDfound = true;
			if($row['Field'] == 'unique_id')
				$uniqueIDfound = true;
		}
		mysql_free_result($res);
		
		if(!$found){
			$q = "ALTER TABLE `".$dataTable."` ADD `user_ip` VARCHAR( 64 ) DEFAULT '' NOT NULL";
			$this->query($q);
		}
		if(!$postIDfound){
			$q = "ALTER TABLE `".$dataTable."` ADD `post_id` INT DEFAULT '0' NOT NULL";
			$this->query($q);
		}
		if(!$uniqueIDfound){
			$q = "ALTER TABLE `".$dataTable."` ADD `unique_id` VARCHAR( 32 ) DEFAULT '' NOT NULL";
			$this->query($q);
			$q = "ALTER TABLE `".$dataTable."` ADD INDEX (`unique_id`)";
			$this->query($q);
		}
	}
}

function fixTemplatesTableModified(){
	$q = "SHOW COLUMNS FROM `".$this->templatesTable."`";
	$res = $this->query($q);
	while($row = mysql_fetch_assoc($res)){
		if($row['Field'] == 'modified' && strpos(strtolower($row['Type']), 'bigint') !== false){
			$q = "ALTER TABLE `".$this->templatesTable."` CHANGE `modified` `modified` BIGINT NOT NULL ";
			$this->query($q);
		}
	}
	mysql_free_result($res);	
}

function fixDBTypeBug(){
	$q = "SELECT `unique_name`, `db_type` FROM `".$this->itemsTable."`";
	$res = $this->query($q);
	while($row = mysql_fetch_assoc($res)){
		$dbType = $row['db_type'];
		if(trim($dbType) != "NONE"){
			$q = "UPDATE `".$this->itemsTable."` SET `db_type` = 'DATA' WHERE `unique_name` = '".$row['unique_name']."'";
			$this->query($q);
		}
	}
	mysql_free_result($res);
}
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////

function removeFormManager(){
	$q = "SHOW TABLES LIKE '{$this->formsTable}'";
	$res = $this->query($q);
	if(mysql_num_rows($res)>0){
		mysql_free_result($res);
		$q = "SELECT `data_table` FROM `{$this->formsTable}`";	
		$res = $this->query($q);
		while($row=mysql_fetch_assoc($res)){
			if($row['data_table'] != ""){			
				$q = "SHOW TABLES LIKE '".$row['data_table']."'";
				$r = $this->query($q);
				if(mysql_num_rows($r) > 0){
					$q="DROP TABLE IF EXISTS `".$row['data_table']."`";				
					$this->query($q);
				}
				mysql_free_result($r);
			}
		}
		mysql_free_result($res);
	}
	else
		mysql_free_result($res);	
		
	$q = "DROP TABLE IF EXISTS `{$this->formsTable}`";	
	$this->query($q);
	$q = "DROP TABLE IF EXISTS `{$this->itemsTable}`";	
	$this->query($q);
	$q = "DROP TABLE IF EXISTS `{$this->settingsTable}`";	
	$this->query($q);
	$q = "DROP TABLE IF EXISTS `{$this->templatesTable}`";	
	$this->query($q);
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Templates

function storeTemplate($filename, $title, $content, $modified){
	$this->removeTemplate($filename);
	
	$q = "INSERT INTO `".$this->templatesTable."` SET ".
			"`title` = '".addslashes($title)."', ".
			"`filename` = '".addslashes($filename)."', ".
			"`content` = '".addslashes($content)."', ".
			"`modified` = '".addslashes($modified)."'";
	$this->query($q);
}
function getTemplate($filename, $content = true){
	if($content)
		$q = "SELECT * FROM `".$this->templatesTable."` WHERE `filename` = '".$filename."'";
	else
		$q = "SELECT `title`, `filename`, `status`, `modified` FROM `".$this->templatesTable."` WHERE `filename` = '".$filename."'";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row;
}
function getTemplateList(){
	$q = "SELECT `title`, `filename`, `modified` FROM `".$this->templatesTable."`";
	$res = $this->query($q);
	$list = array();
	while($row = mysql_fetch_assoc($res)){
		$list[$row['filename']] = $row;
	}
	mysql_free_result($res);
	return $list;
}
function removeTemplate($filename){
	$q = "DELETE FROM `".$this->templatesTable."` WHERE `filename` = '".$filename."'";	
	$this->query($q);
}

function flushTemplates(){
	$q = "TRUNCATE TABLE `".$this->templatesTable."`";
	$this->query($q);
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Form Settings

public function getTextValidators(){
	$arr = array();
	$count = $this->getGlobalSetting('text_validator_count');
	for($x=0;$x<$count;$x++){
		$val = $this->getGlobalSetting('text_validator_'.$x);
		$arr[$val['name']] = $val;
	}
	return $arr;
}

public function setTextValidators($validatorList){
	$x=0;
	foreach($validatorList as $validator)
		$this->setGlobalSetting('text_validator_'.$x++, $validator, true);
	$this->setGlobalSetting('text_validator_count', sizeof($validatorList));
}

public function setFormSettingsDefaults($opt){
	foreach($this->formSettingsKeys as $k=>$v){
		if(array_key_exists($k, $opt)) $this->formSettingsKeys[$k] = $opt[$k];
	}
}

function initFormsTable(){	
	//see if a settings row exists
	$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` < 0";	
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0){
		$q = $this->getDefaultSettingsQuery();
	}	
	mysql_free_result($res);
	$this->query($q);
}

function initSettingsTable(){	
	foreach($this->globalSettings as $k=>$v){
		$this->setGlobalSetting($k, $v, false);
	}
	
	//Add any default validators that aren't in the database
	$validators = $this->getTextValidators();
	for($x=0;$x<$this->globalSettings['text_validator_count'];$x++){
		$name = $this->globalSettings['text_validator_'.$x]['name'];
		if(!isset($validators[$name]))
			$validators[$name] = $this->globalSettings['text_validator_'.$x];
	}
	$this->setTextValidators($validators);
}

//returns true if something was written, false otherwise.
// $overwrite : overwrite the old setting, if one exists.
function setGlobalSetting($settingName, $settingValue, $overwrite = true){
	$val = $this->getGlobalSetting($settingName);
	if(is_array($settingValue))
		$settingValue = addslashes(serialize($settingValue));
	if($val === false){
		$q = "INSERT INTO `".$this->settingsTable."` SET `setting_name` = '{$settingName}', `setting_value` = '{$settingValue}'";
		$this->query($q);
		return true;
	}
	else if($overwrite){
		$q = "UPDATE `".$this->settingsTable."` SET `setting_value` = '{$settingValue}' WHERE `setting_name` = '{$settingName}'";
		$this->query($q);
		return true;
	}
	return false;
}

function getGlobalSetting($settingName){
	$q = "SELECT `setting_value` FROM `".$this->settingsTable."` WHERE `setting_name` = '".$settingName."'";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0) return false;
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	if(is_serialized($row['setting_value']))
		return unserialize($row['setting_value']);
	return $row['setting_value'];
}

function getGlobalSettings(){
	$q = "SELECT * FROM `".$this->settingsTable."`";
	$res = $this->query($q);
	$vals = array();
	while($row = mysql_fetch_assoc($res)){
		if(is_serialized($row['setting_value']))
			$row['setting_value'] = unserialize($row['setting_value']);
		$vals[$row['setting_name']] = $row['setting_value'];		
	}
	mysql_free_result($res);
	return $vals;
}

//generate the default form settings query
function getDefaultSettingsQuery(){
	$q = "INSERT INTO `".$this->formsTable."` SET `ID` = '-1' ";
	foreach($this->formSettingsKeys as $setting=>$value)
		$q.=", `{$setting}` = '{$value}'";
	return $q;
}

// Get the default settings row
function getSettings(){
	$formSettingsRow = $this->formSettingsKeys;
	$settingsTableData = $this->getGlobalSettings();
	foreach($formSettingsRow as $k=>$v){
		if(isset($settingsTableData[$k]))
			$formSettingsRow[$k] = $settingsTableData[$k];
	}
	return $formSettingsRow;
}

// Get a particular setting
function getSetting($settingName){
	$val = $this->getGlobalSetting($settingName);
	if($val !== false) return $val;
	$q = "SELECT `".$settingName."` FROM `".$this->formsTable."` WHERE `ID` < 0";
	$this->query($q);
	$row = mysql_fetch_assoc($res);	
	mysql_free_result($res);
	return stripslashes($row[$settingName]);
}

// Get a new unique form (integer) ID
function getUniqueFormID(){
	$q = "SELECT `ID` FROM `".$this->formsTable."` WHERE `ID` < 0";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	$intID = (int)$row['ID'];
	$nextID = $intID - 1;
	$q = "UPDATE `".$this->formsTable."` SET `ID` = '".$nextID."' WHERE `ID` = '".$intID."'";
	$this->query($q);
	mysql_free_result($res);
	return $intID*(-1);
}

function getUniqueItemID($type){
	return uniqid($type."-");
}

//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Forms

//////////////////////////////////////////////////////////////////
//Submission data
function isForm($formID){
	$q = "SELECT `ID` FROM `{$this->formsTable}` WHERE `ID` = '{$formID}'";
	$res = $this->query($q);
	$n = mysql_num_rows($res);
	mysql_free_result($res);
	return ($n>0);
}

function processPost($formID, $extraInfo = null, $overwrite = false){	
	global $fm_controls;
	global $msg;
	$this->lastPostFailed = false;
	$formInfo = $this->getForm($formID);
	$dataTable = $this->getDataTableName($formID);
	$postData = array();
	foreach($formInfo['items'] as $item){
		$processed = $fm_controls[$item['type']]->processPost($item['unique_name'], $item);
		if($processed === false){
			$this->lastPostFailed = true;
		}
		if($this->isDataCol($item['unique_name']))						
			$postData[$item['unique_name']] = $processed;
	}
	if($extraInfo != null && is_array($extraInfo) && sizeof($extraInfo)>0){
		$postData = array_merge($postData, $extraInfo);
	}
	
	//generate a timestamp
	$q = "SELECT NOW()";
	$res = $this->query($q);
	$row = mysql_fetch_array($res);
	mysql_free_result($res);
	$postData['timestamp'] = $row[0];
		
	if($this->lastPostFailed === false){
		if($overwrite){	
			$q = "DELETE FROM `{$dataTable}` WHERE `user` = '".$postData['user']."'";
			$this->query($q);
		}
		if($this->insertSubmissionData($formID, $dataTable, $postData) === false){
			$this->lastPostFailed = true;
		}
	}
	
	return $postData;
}

function processFailed(){
	return $this->lastPostFailed;
}
function setErrorMessage($message, $for = ""){
	$this->lastErrorMessage = $message;
	$this->lastUniqueName = $for;
}
function getErrorMessage(){
	return $this->lastErrorMessage;
}
function getErrorUniqueName(){
	return $this->lastUniqueName;
}

function insertSubmissionData($formID, $dataTable, $postData){
	$q = "SELECT `unique_id` FROM `{$dataTable}` WHERE `unique_id` = '".$postData['unique_id']."'";
	$res = $this->query($q);
	
	if(mysql_num_rows($res) > 0){
		mysql_free_result($res);
		return false;
	}
	
	$q = "INSERT INTO `{$dataTable}` SET ";
	$arr = array();
	$postData['timestamp'] = gmdate( 'Y-m-d H:i:s', ( time() + ( get_option( 'gmt_offset' ) * 3600 ) ) );
	
	foreach($postData as $k=>$v)
		$arr[] = "`{$k}` = '".$v."'";
	$q .= implode(",",$arr);
	$this->query($q);
}

function getFormSubmissionDataCSV($formID){
	$formInfo = $this->getForm($formID);
	$data = $this->getFormSubmissionData($formID, 'timestamp', 'DESC', 0, 0);
	
	$data = $data['data'];
	//store the lines in an array
	$csvRows = array();
	
	//store each name in an array
	$fieldNames=array();
	
	//index form fields by unique_name, remove fields with no data
	$newItems = array();
	foreach($formInfo['items'] as $item){
		if($this->isDataCol($item['unique_name']))
			$newItems[$item['unique_name']] = $item;
	}
	$formInfo['items'] = $newItems;
	
	//add the field headers
	$fieldNames[] = 'timestamp';
	$fieldNames[] = 'user';
	$fieldNames[] = 'user_ip';
	foreach($formInfo['items'] as $k=>$v){
		if(!isset($formInfo['items'][$k]))							$label = $k;
		else if(trim($formInfo['items'][$k]['nickname']) != '') 	$label = $formInfo['items'][$k]['nickname'];
		else														$label = $formInfo['items'][$k]['label'];
		
		$fieldNames[] = $label;		
	}
	
	$csvRows[] = $fieldNames;	
	
	if($data !== false){
		foreach($data as $dataRow){
			$dataItems=array();
			$dataItems[] = $dataRow['timestamp'];
			$dataItems[] = $dataRow['user'];
			$dataItems[] = $dataRow['user_ip'];
			foreach($formInfo['items'] as $k=>$v){
				$dataItems[] = $dataRow[$k];
			}
			$csvRows[] = $dataItems;
		}
	}

	//use the stream capture to get the CSV formatted data, since we need to mess with the encoding later
	ob_start();
	
	$fp = fopen("php://output",'w');
	
	//use fputcsv instead of reinventing the wheel:
	foreach($csvRows as $csvRow){
		fputcsv($fp, $csvRow, chr(9));	
	}
	
	fclose($fp);
		
	$str = ob_get_contents();
	ob_end_clean();
	
	//Properly encode the CSV so Excel can open it: Credit for this goes to someone called Eugene Murai
	$str = chr(255).chr(254).mb_convert_encoding( $str, 'UTF-16LE', 'UTF-8');
	return $str;
}

function getFormSubmissionData($formID, $orderBy = 'timestamp', $ord = 'DESC', $startIndex = 0, $numItems = 30){
	global $fm_controls;
	
	$formInfo = $this->getForm($formID);	
	$postData = $this->getFormSubmissionDataRaw($formID, $orderBy, $ord, $startIndex, $numItems);
	$postCount = $this->getSubmissionDataNumRows($formID);
	
	if($postData === false) return false;
	foreach($postData as $index=>$dataRow){
		foreach($formInfo['items'] as $item){
			$postData[$index][$item['unique_name']] = $fm_controls[$item['type']]->parseData($item['unique_name'], $item, $dataRow[$item['unique_name']]);
		}
	}
	
	$dataInfo = array();
	$dataInfo['data'] = $postData;
	$dataInfo['count'] = $postCount;
	return $dataInfo;
}

// returns all the form rows, but only the timestamp, user, and IP address columns
function getFormSubmissionUserData($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT `timestamp`, `user`, `user_ip` FROM `{$dataTable}`";
	$data = array();
	$res = $this->query($q);
	while($row = mysql_fetch_assoc($res))
		$data[] =  $row;
	mysql_free_result($res);
	return $data;
}

function getFormSubmissionDataRaw($formID, $orderBy = 'timestamp', $ord = 'DESC', $startIndex = 0, $numItems = 30){
	$dataTable = $this->getDataTableName($formID);
	if( $numItems == 0 )
		$q = "SELECT * FROM `{$dataTable}` ORDER BY `{$orderBy}` {$ord}";
	else
		$q = "SELECT * FROM `{$dataTable}` ORDER BY `{$orderBy}` {$ord} LIMIT {$startIndex}, {$numItems}";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0) return array();
	$data=array();
	while($row = mysql_fetch_assoc($res)){
		$data[] = $row;
	}	
	mysql_free_result($res);
	return $data;
}

function clearSubmissionData($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "TRUNCATE TABLE `{$dataTable}`";
	$this->query($q);
}

function deleteSubmissionDataRow($formID, $data){
	$dataTable = $this->getDataTableName($formID);
	$q = "DELETE FROM `{$dataTable}` WHERE `timestamp` = '".$data['timestamp']."' AND `user` = '".$data['user']."' AND `user_ip` = '".$data['user_ip']."' LIMIT 1";
	$this->query($q);
}

function updateDataSubmissionRow($formID, $timestamp, $user, $user_ip, $newData){
	$dataTable = $this->getDataTableName($formID);
	$q = "UPDATE `{$dataTable}` SET";
	$arr = array();
	foreach($newData as $k=>$v){
		$arr[] = "`{$k}` = '{$v}'";
	}
	$q.= implode(", ", $arr);
	$q.= " WHERE `timestamp` = '{$timestamp}' AND `user` = '{$user}' AND `user_ip` = '{$user_ip}'";
	$this->query($q);
}

function dataHasPublishedSubmissions($formID){
	$hasPosts = false;
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT COUNT(*) FROM `{$dataTable}` WHERE `post_id` > 0";
	$res = $this->query($q);
	$row = mysql_fetch_array($res);
	mysql_free_result($res);
	if($row[0] > 0) return  true;
	return false;
}
							
function isDataCol($uniqueName){
	$cacheKey = $uniqueName."-is-data";
	$type = $this->getCache(1, $cacheKey);
	if($type == null){
		$q = "SELECT `db_type` FROM `".$this->itemsTable."` WHERE `unique_name` = '{$uniqueName}'";
		$res = $this->query($q);
		$row = mysql_fetch_assoc($res);
		mysql_free_result($res);
		$type = $row['db_type'];
		$this->setCache($formID, $cacheKey, $type);
	}
	return ($type != "NONE");	
}

function getDataType($uniqueName){
	$cacheKey = $uniqueName."-type";
	$fullType = $this->getCache(1, $cacheKey);
	if($fullType == null){
		$q = "SELECT `ID`, `db_type` FROM `".$this->itemsTable."` WHERE `unique_name` = '".$uniqueName."'";
		$res = $this->query($q);		
		$row = mysql_fetch_assoc($res);		
		mysql_free_result($res);
		
		if($row['db_type'] == "NONE") return "NONE";
		
		$dataTable = $this->getDataTableName($row['ID']);
		
		$q = "SHOW FULL COLUMNS FROM `{$dataTable}` LIKE '".$uniqueName."'";
		$res = $this->query($q);		
		$row = mysql_fetch_assoc($res);		
		mysql_free_result($res);
		
		//VARCHAR( 1000 ) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'doop'
		if($row['Collation'] == "")
			$charCollate = "";
		else{
			$q = "SHOW COLLATION LIKE '".$row['Collation']."'";
			$res = $this->query($q);
			$collRow = mysql_fetch_assoc($res);
			mysql_free_result($res);
			$charCollate = " CHARACTER SET ".$collRow['Charset']." COLLATE ".$row['Collation'];	
		}
		
		switch($row['Type']){
			case 'text':
			case 'tinytext':
			case 'mediumtext':
			case 'longtext':
			case 'blob':
			case 'tinyblob':
			case 'mediumblob':
			case 'longblob':
				$default = "";
				break;
			default:
				$default = " DEFAULT '".$row['Default']."'";
		}

		$null = ($row['Null'] == "NO" ? " NOT NULL" : " NULL");
		
		$fullType = $row['Type'].$charCollate.$null.$default;
						
		$fullType = trim($fullType);
		$this->setCache($formID, $cacheKey, $fullType);
	}
	return $fullType;
}

function updateDataType($formID, $uniqueName, $newType){
	$dataTable = $this->getDataTableName($formID);
	$q = "ALTER TABLE `".$dataTable."` CHANGE `".$uniqueName."` `".$uniqueName."` ".$newType;
	$this->query($q);
}

function getSubmissionDataCount($formID){ return $this->getSubmissionDataNumRows($formID); }
function getSubmissionDataNumRows($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT COUNT(*) FROM `{$dataTable}`";
	$res = $this->query($q);
	$row = mysql_fetch_row($res);
	mysql_free_result($res);
	return $row[0];
}

function getLastSubmission($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT * FROM `{$dataTable}` WHERE `timestamp` = ( SELECT MAX(`timestamp`) FROM `{$dataTable}` )";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row;
}

function getUserSubmissions($formID, $user, $lastOnly = false){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT * FROM `{$dataTable}` WHERE `user` = '".$user."' ORDER BY `timestamp` DESC".($lastOnly?" LIMIT 1":'');
	$res = $this->query($q);
	$data = array();
	while($row = mysql_fetch_assoc($res))
		$data[] = $row;
	mysql_free_result($res);
	return $data;
}

function getUserSubmissionCount($formID, $user){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT COUNT(*) FROM `{$dataTable}` WHERE `user` = '".$user."'";
	$res = $this->query($q);
	$row = mysql_fetch_array($res);
	mysql_free_result($res);
	return $row[0];
}

function getSubmission($formID, $timestamp, $user, $cols = "*"){
	$dataTable = $this->getDataTableName($formID);
	$q = "SELECT ".$cols." FROM `".$dataTable."` WHERE `timestamp` = '".$timestamp."' AND `user` = '".$user."'";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row;
}	

//////////////////////////////////////////////////////////////////

function getFormList(){
	$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` >= 0 ORDER BY `ID` ASC";
	$res = $this->query($q);
	$formList=array();
	while($row=mysql_fetch_assoc($res)){
		$row['title']=stripslashes($row['title']);
		$formList[]=$row;		
	}
	mysql_free_result($res);
	return $formList;
}

//gets an associative array containing the form settings and items; the array is the same format as that passed to 'updateForm'
function getForm($formID){
	$formInfo = $this->getFormSettings($formID, $this->formsTable, $this->conn);	
	$formInfo['items']=$this->getFormItems($formID, $this->itemsTable, $this->conn);
	return $formInfo;
}

//does not change the database; returns a form identical to $formID, but with new unique names for the form items
function copyForm($formID){
	$formInfo = $this->getForm($formID);
	for($x=0;$x<sizeof($formInfo['items']);$x++){
		$formInfo['items'][$x]['unique_name'] = $this->getUniqueItemID($item['type']);
	}
	return $formInfo;
}

function isValidItem($formInfo, $uniqueName){
	if(sizeof($formInfo['items']) == 0) return false;
	foreach($formInfo['items'] as $item)
		if($item['unique_name'] == $uniqueName) return true;
	return false;
}

function getFormID($slug){
	$q = "SELECT `ID` FROM `".$this->formsTable."` WHERE `shortcode` = '".$slug."'";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0) return false;
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row['ID'];
}

function getFormShortcode($formID){
	$q = "SELECT `shortcode` FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0) return false;
	$row = mysql_fetch_assoc($res);
	mysql_free_result($res);
	return $row['shortcode'];
}

//gets a particular form's settings; uses defaults where blank
function getFormSettings($formID){
	global $msg;
	
	$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";

	$res = $this->query($q);
 	if(mysql_num_rows($res)==0) return null;
 	$row = mysql_fetch_assoc($res);
 	foreach($row as $k=>$v){
		$row[$k]=stripslashes($v);
		if(is_serialized($row[$k])) $row[$k] = unserialize($row[$k]);
	}
	
 	mysql_free_result($res);
	foreach($this->formSettingsKeys as $k=>$v){
		if(!is_array($row[$k]) && trim($row[$k]) == "") $row[$k] = $v;
	}

 	return $row;
}

//update the settings for a particular form
function updateFormSettings($formID, $formInfo){
	if($formInfo!=null){
		$toUpdate = array_intersect_key($formInfo,$this->formSettingsKeys);
		$toUpdate = $this->sanitizeFormSettings($toUpdate);
		//make sure we have sanitized settings remaining
		if(sizeof($toUpdate)>0){
			$strArr=array();
			foreach($toUpdate as $k=>$v){
				if(is_array($formInfo[$k])) $formInfo[$k] = serialize($formInfo[$k]);
				$strArr[] = "`{$k}` = '".addslashes($formInfo[$k])."'";
			}
			$q = "UPDATE `".$this->formsTable."` SET ".implode(", ",$strArr)." WHERE `ID` = '".$formID."'";
			$this->query($q);
		}
	}
}

//update the form. If $formInfoOld is 'null', assumes everything is new
function updateForm($formID, $formInfoNew){
	//update the settings
	$this->updateFormSettings($formID, $formInfoNew);
	//check the old form structure
	
	$formInfoOld = $this->getForm($formID);
	
	$compare = $this->compareFormItems($formInfoOld, $formInfoNew);
	
	foreach($compare['delete'] as $toDelete)  //deletions are stored as their unique name
		$this->deleteFormItem($formID, $toDelete);
	foreach($compare['update'] as $toUpdate) //updates list the entire item arrays
		$this->updateFormItem($formID, $toUpdate['unique_name'], $toUpdate);
	foreach($compare['create'] as $toCreate) //creations give the entire item arrays
		$this->createFormItem($formID, $toCreate['unique_name'], $toCreate);
}

//returns an array with three keys, 'delete', 'update', and 'create'
// 'delete' is an array of the unique names of the items to be deleted
// 'update' and 'create' contain 'item info' arrays of the updated / new values respectively
function compareFormItems($formInfoOld, $formInfoNew){
	$ret = array();
	$ret['delete'] = array();
	$ret['update'] = array();
	$ret['create'] = array();
	
	if(!isset($formInfoNew['items'])) // nothing to change
		return $ret;
		
	//special case: if $formInfoOld is 'null', then everything is new
	if($formInfoOld == null){
		$ret['create'] = $formInfoNew['items'];
		return $ret;
	}
	
	//loop through the old items to determine deletions and updates
	foreach($formInfoOld['items'] as $item){
		//see if the item from the old list is in the new list
		$newItem = $this->formInfoGetItem($item['unique_name'], $formInfoNew);
		//if not, to be deleted
		if($newItem == null) $ret['delete'][] = $item['unique_name'];
		//otherwise needs to be updated, unless nothing has changed
		else if(!$this->itemInfoIsEqual($item, $newItem)) $ret['update'][] = $newItem;
	}
	//loop through the new items to determine creations
	foreach($formInfoNew['items'] as $item){
		//see if the item from the new list is in the old list
		$tempItem = $this->formInfoGetItem($item['unique_name'], $formInfoOld);
		//if not, it is a new item; otherwise it was already added to the update list
		if($tempItem == null) $ret['create'][] = $item;
	}
	return $ret;
}

function deleteForm($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "DELETE FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";
	$this->query($q);
	$q = "DELETE FROM `".$this->itemsTable."` WHERE `ID` = '".$formID."'";
	$this->query($q);
	$q = "DROP TABLE IF EXISTS `".$dataTable."`";
	$this->query($q);	
}

//creates a form; returns the ID of the created form
function createForm($formInfo=null){	
	$dataTablePrefix = $this->dataTablePrefix();	
	$newID = $this->getUniqueFormID();
	$dataTable = $dataTablePrefix."_".$newID;
	$q = "INSERT INTO `".$this->formsTable."` SET `ID` = '".$newID."', `data_table` = '".$dataTable."'";
	$this->query($q);
	if($formInfo == null)	//use the default settings
		$formInfo = $this->getSettings();
	$formInfo['shortcode'] = 'form-'.$newID; //give new forms a shortcode based on numerical ID	
	$this->updateForm($newID, $formInfo);			
	$this->createDataTable($formInfo, $dataTable);
	return $newID;
}

//creates a data table associated with a form
function createDataTable($formInfo, $dataTable){
	
	$charset_collate = $this->getCharsetCollation();
		
	$q = "CREATE TABLE `{$dataTable}` (".
		"`timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ,".
		"`user` VARCHAR( 64 ) DEFAULT '' NOT NULL ,".
		"`user_ip` VARCHAR( 64 ) DEFAULT '' NOT NULL ,".
		"`post_id` INT DEFAULT '0' NOT NULL ,".
		"`unique_id` VARCHAR( 32 ) DEFAULT '' NOT NULL ,".
		"INDEX (`unique_id`)";
	$q.= ") ".$charset_collate.";";
	$this->query($q);
}

function dataTablePrefix(){
	global $wpdb;
	return $wpdb->prefix.get_option('fm-data-table-prefix');
}
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////
// Items


//returns an indexed array of all items in a form
function getFormItems($formID){
	$items=array();
	$q = "SELECT * FROM `".$this->itemsTable."` WHERE `ID` = '".$formID."' ORDER BY `index` ASC";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0) return array();
	$n = mysql_num_rows($res);
	for($x=0;$x<$n;$x++){		
		$items[] = $this->unpackItem(mysql_fetch_assoc($res));
	}
	mysql_free_result($res);
	return $items;
}

//gets an associative array for an individual form item
function getFormItem($uniqueName){
	$q = "SELECT * FROM `".$this->itemsTable."` WHERE `unique_name` = '".$uniqueName."'";
	$res = $this->query($q);
	if(mysql_num_rows($res)==0)	return false;
	$row = $this->unpackItem(mysql_fetch_assoc($res));
	mysql_free_result($res);
	return $row;
}

//by default, new items are placed at the end of the form. 
function createFormItem($formID, $uniqueName, $itemInfo){

	//see if an index has been specified
	if($itemInfo['index']== -1){
		//find the last index in the current table
		$q = "SELECT `index` FROM `".$this->itemsTable."` WHERE `ID` = '".$formID."' ORDER BY `index` DESC";
		$res = $this->query($q);
		$row = mysql_fetch_assoc($res);
		$itemInfo['index'] = $row['index'] + 1;
		mysql_free_result($res);
	}
	
	//now add the item to the items table
	$itemInfo = $this->packItem($itemInfo);

	$ignoreKeys = array();
	$setKeys = array();
	$setValues = array();
	$q = "INSERT INTO `".$this->itemsTable."` (`ID`, `unique_name`, ";
	foreach($this->itemKeys as $k=>$v){
		if(!in_array($k,$ignoreKeys)){
			$setKeys[] = "`".$k."`";
			$setValues[] = "'".$itemInfo[$k]."'";
		}
	}
	$q.= implode(",",$setKeys).") VALUES ( '".$formID."', '".$uniqueName."', ".implode(",",$setValues).")";				
	$this->query($q);
	
	//add a field to the data table
	if($this->isDataCol($itemInfo['unique_name'])) $this->createFormItemDataField($formID, $uniqueName, $itemInfo);
}	

function createFormItemDataField($formID, $uniqueName, $itemInfo){	
	global $fm_controls;
	
	$dataTable = $this->getDataTableName($formID);
	$q = "ALTER TABLE `".$dataTable."` ADD `".$uniqueName."` ".($fm_controls[$itemInfo['type']]->getColumnType())." NOT NULL";
	$this->query($q);
}

//$itemList contains associative array; key is 'unique_name', 'value' is an $itemInfo for updateFormItem()
function updateFormItemList($formID, $itemList){
	foreach($itemList as $uniqueName => $itemInfo)
		$this->updateFormItem($formID, $uniqueName, $itemInfo);
}

function updateFormItem($formID, $uniqueName, $itemInfo){
	$itemInfo = $this->packItem($itemInfo);
					
	$toUpdate = array_intersect_key($itemInfo,$this->itemKeys);
	$strArr=array();
	foreach($toUpdate as $k=>$v){
		$strArr[] = "`{$k}` = '".$itemInfo[$k]."'";		
	}
	$q = "UPDATE `".$this->itemsTable."` SET ".implode(", ",$strArr)." WHERE `unique_name` = '".$uniqueName."'";

	$this->query($q);	
}

function getDataFieldIndex($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SHOW INDEXES FROM `".$dataTable."`";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0) return null;
	$row = mysql_fetch_assoc($res);	
	mysql_free_result($res);
	return $row['Column_name'];
}

function removeDataFieldIndex($formID){
	$dataTable = $this->getDataTableName($formID);
	$q = "SHOW INDEXES FROM `".$dataTable."`";
	$res = $this->query($q);
	while($row = mysql_fetch_assoc($res)){				
		$q = "ALTER TABLE `".$dataTable."` DROP INDEX `".$row['Column_name']."`";
		$this->query($q);
	}	
	mysql_free_result($res);	
}

function deleteFormItem($formID, $uniqueName){
	$q = "SELECT `db_type` FROM `".$this->itemsTable."` WHERE `unique_name` = '".$uniqueName."'";
	$res = $this->query($q);
	$row = mysql_fetch_assoc($res);
	$dbType = $row['db_type'];
	mysql_free_result($res);
	
	$q = "DELETE FROM `".$this->itemsTable."` WHERE `unique_name` = '".$uniqueName."'";
	$this->query($q);
	if($dbType != "NONE") $this->deleteDataField($formID, $uniqueName);
}

function deleteDataField($formID, $uniqueName){	
	$dataTable = $this->getDataTableName($formID);	
	$q = "ALTER TABLE `".$dataTable."` DROP `".$uniqueName."`";	
	$this->query($q);
}

function formInfoGetItem($uniqueName, $formInfo){
	foreach($formInfo['items'] as $item)
		if($item['unique_name'] == $uniqueName) return $item;
	return null;
}

function getItemByNickname($formID, $nickname){
	$q = "SELECT * FROM `".$this->itemsTable."` WHERE `nickname` = '".$nickname."' AND `ID` = '".$formID."'";
	$res = $this->query($q);
	if(mysql_num_rows($res) == 0) return false;
	$row = $this->unpackItem(mysql_fetch_assoc($res));
	mysql_free_result($res);
	return $row;
}

function itemInfoIsEqual($itemA, $itemB){
	foreach($itemA as $k=>$v)
		if(!isset($itemB[$k]) || $itemB[$k] != $itemA[$k]) 
			return false;
	foreach($itemB as $k=>$v)
		if(!isset($itemA[$k])) return false;
	return true;
}

//////////////////////////////////////////////////////////////////
// Nonce

function getNonce(){
	if(!isset($_SESSION['fm-nonce']))
		$this->initNonces();
	
	$nonce = uniqid("fm-nonce-");
	$_SESSION['fm-nonce'][] = $nonce;
	return $nonce;
}

// if $remove is set to false, will not remove the nonce from the session variable
function checkNonce($nonce, $remove = true){
	if(!isset($_SESSION['fm-nonce'])) return false;
	foreach($_SESSION['fm-nonce'] as $k=>$v){
		if($v == $nonce){
			if($remove) unset($_SESSION['fm-nonce'][$k]);
			return true;
		}
	}
	return false;
}

function initNonces(){
	$_SESSION['fm-nonce'] = array();
}

//////////////////////////////////////////////////////////////////
// Helpers
function unpackItem($item){
	foreach($item as $k=>$v){
		if($k != 'extra')
			$item[$k] = stripslashes($item[$k]);
		else			
			$item['extra'] = unserialize($item['extra']);
	}
	return $item;
}

function packItem($item){
	if(!isset($item['extra']) || $item['extra']=="")
		$item['extra'] = array();
		
	foreach($item as $k=>$v){
		if($k == 'extra' && is_array($item['extra']))
			$item['extra'] = addslashes(serialize($item['extra']));
		else
			$item[$k] = addslashes($item[$k]);			
	}
	return $item;
}

//removes any settings that are improperly formed
function sanitizeFormSettings($settings){
	if(isset($settings['labels_on_top']) && !((int)$settings['labels_on_top']==1 || (int)$settings['labels_on_top']==0))
		unset($settings['labels_on_top']);
	return $settings;
}

function sanitizeUniqueName($name){
	//replace spaces with '-' just to be nice
	$name = str_replace(" ","-",$name);
	//must be lowercase, alphanumeric, exceptions are dash and underscore
	$name = strtolower(preg_replace("/[^a-zA-Z0-9\-_]/","",$name));
	//must begin with a letter; if not, fail
	$firstChar = substr($name,0,1);	
	if(!preg_match("/[a-z]/",$name)) return false;
	return $name;		
}

//cached data table name (should not be changing within a page load)
function getDataTableName($formID){
	$dataTable = $this->getCache($formID, 'data-table');
	if($dataTable == null){
		$q = "SELECT `data_table` FROM `".$this->formsTable."` WHERE `ID` = '".$formID."'";
		$res = $this->query($q);
		$row = mysql_fetch_assoc($res);
		$dataTable = $row['data_table'];
		mysql_free_result($res);
		$this->setCache($formID, 'data-table', $dataTable);
	}
	return $dataTable;
}

// database consistency check; returns a string telling what was checked and the results
function consistencyCheck(){
	
	//first see if the basic tables exist
	$tbls = array($this->formsTable, $this->itemsTable, $this->templatesTable, $this->settingsTable);
	$names = array('Forms', 'Items', 'Templates', 'Settings');
	$found = array();
	
	for($x=0;$x<sizeof($tbls);$x++){
		echo  $names[$x]." table (".$tbls[$x].")... ";
		if($this->tableExists($tbls[$x])){
			echo  "OK\n";
			$found[$x] = true;
		}else{
			echo "FAIL\n";
			$found[$x] = false;
		}
	}	
	
	// do the table checks	
	if($found[0]){
		echo  "Forms table...\n";
	
		//make sure a data table exists for each form
		$q = "SELECT `ID`, `data_table` FROM `".$this->formsTable."` WHERE `ID` > 0";
		$res = $this->query($q);
		while($row = mysql_fetch_assoc($res)){
			echo  "Form ".$row['ID']." for data table: ";
			$q = "SHOW TABLES LIKE '".$row['data_table']."'";
			if(mysql_num_rows($this->query($q)) == 1)
				echo  "OK\n";
			else
				echo  "FAIL\n";
		}
		mysql_free_result($res);		
		
		//no duplicate form IDs or slugs
		echo  "For duplicate IDs and slugs...\n";
		$q = "SELECT * FROM `".$this->formsTable."` WHERE `ID` > 0";
		$res = $this->query($q);
		$ids = array();
		$slugs = array();
		while($row = mysql_fetch_assoc($res)){
			$ids[] = $row['ID'];
			$slugs[] = $row['shortcode'];
		}		
		mysql_free_result($res);
		
		sort($ids);
		sort($slugs);
		$fail = false;
		$last = $ids[0];
		for($x=1;$x<sizeof($ids);$x++){
			if($last == $ids[$x]){
				" DUPLICATE ID FOUND (".$ids[$x].")\n";
				$fail = true;
			}			
			$last = $ids[$x];
		}
		$last = $slugs[$x];
		for($x=1;$x<sizeof($slugs);$x++){
			if($last == $slugs[$x]){
				" DUPLICATE SLUG FOUND (".$slugs[$x].")\n";
				$fail = true;
			}
			$last = $slugs[$x];
		}
		if(!$fail) echo  "OK\n";
	}
	
	//list of entries in the items table
	if($found[1] && $found[0]){
		echo  "Items table...\n";
		echo "Checking form IDs exists...\n";
		$q = "SELECT * FROM `".$this->itemsTable."`";
		$res = $this->query($q);
		$items = array();
		$err = false;
		while($row = mysql_fetch_assoc($res)){
			$items[] = $row;
			if(!in_array($row['ID'], $ids)){
				echo  $row['unique_name'].": nonexistent form ".$row['ID']."\n";
				$err = true;
			}
		}
		mysql_free_result($res);
		if(!$err)
			echo  "OK\n";
		
		echo "Checking unique names...\n";
		$last = $items[0]['unique_name'];
		$err = false;
		for($x=1;$x<sizeof($items);$x++){
			if($last == $items[$x]['unique_name']){
				$err = true;
				echo  "Duplicate: ".$last."\n";
			}			
		}
		if(!$err)
			echo "SLUGS OK\n";
	}
	
	//list of entries in the templates table
	if($found[2]){
		echo  "Templates entries: \n";
		$q = "SELECT `title`, `filename`, `modified` FROM `".$this->templatesTable."`";
		$res = $this->query($q);
		if(mysql_num_rows($res) > 0){
			while($row = mysql_fetch_assoc($res)){
				echo " Title: ".$row['title']."  Filename: ".$row['filename']."  Modified: ".$row['modified']."\n";
			}
			mysql_free_result($res);
		}
	}
	
	//list of entries in the settings table
	if($found[3]){
		echo  "Settings entries: \n";
		$q = "SELECT * FROM `".$this->settingsTable."`";
		$res = $this->query($q);
		while($row = mysql_fetch_assoc($res)){
			echo " Name: ".$row['setting_name']."  Value: ".$row['setting_value']."\n";
		}
		mysql_fetch_assoc($res);
	}
	
	echo "Done.\n";
}

function tableExists($tableName){
	$q = "SHOW TABLES LIKE '".$this->formsTable."'";
	$res = mysql_query($q);
	if(mysql_num_rows($res) == 0) return false;
	return true;		
}


}

function fm_set_form_defaults($options){
	global $fmdb;
	$fmdb->setFormSettingsDefaults($options);
}
?>