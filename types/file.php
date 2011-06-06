<?php
/* translators: file element settings */

class fm_fileControl extends fm_controlBase{
	
	public function getTypeName(){ return "file"; }
	
	/* translators: this appears in the 'Add Form Element' menu */
	public function getTypeLabel(){ return __("File", 'wordpress-form-manager'); }
	
	public function showItem($uniqueName, $itemInfo){
		return "<input type=\"hidden\" name=\"MAX_FILE_SIZE\" value=\"".($itemInfo['extra']['max_size']*1024)."\" />".
				"<input name=\"".$uniqueName."\" id=\"".$uniqueName."\" type=\"file\" />";
	}	

	public function itemDefaults(){
		$itemInfo = array();
		$itemInfo['label'] = __("New File Upload", 'wordpress-form-manager');
		$itemInfo['description'] = __("Item Description", 'wordpress-form-manager');
		$itemInfo['extra'] = array('max_size' => 1000);
		$itemInfo['nickname'] = '';
		$itemInfo['required'] = 0;
		$itemInfo['validator'] = "";
		$ItemInfo['validation_msg'] = "";
		$itemInfo['db_type'] = "LONGBLOB";
		
		return $itemInfo;
	}

	public function editItem($uniqueName, $itemInfo){
		return "<input type=\"file\" disabled>";
	}
	
	public function processPost($uniqueName, $itemInfo){
		global $fmdb;
		
		if($_FILES[$uniqueName]['error'] > 0){
			if($_FILES[$uniqueName]['error'] == 2)
				$fmdb->setErrorMessage("(".$itemInfo['label'].") ".__("File upload exceeded maximum allowable size.", 'wordpress-form-manager'));
			else if($_FILES[$uniqueName]['error'] == 4) // no file
				return "";
			$fmdb->setErrorMessage("(".$itemInfo['label'].") ".__("There was an error with the file upload.", 'wordpress-form-manager'));
			return false;
		}
		
		$ext = pathinfo($_FILES[$uniqueName]['name'], PATHINFO_EXTENSION);
		if(strpos($itemInfo['extra']['exclude'], $ext) !== false){
			/* translators: this will be shown along with the item label and file extension, as in, "(File Upload) Cannot be of type '.txt'" */
			$fmdb->setErrorMessage("(".$itemInfo['label'].") ".__("Cannot be of type", 'wordpress-form-manager')." '.".$ext."'");
			return false;
		}
		else if(trim($itemInfo['extra']['restrict'] != "") && strpos($itemInfo['extra']['restrict'], $ext) === false){
		/* translators: this will be shown along with the item label and a list of file extensions, as in, "(File Upload) Can only be of types '.txt, .doc, .pdf'" */
			$fmdb->setErrorMessage("(".$itemInfo['label'].") ".__("Can only be of types", 'wordpress-form-manager')." ".$itemInfo['extra']['restrict']);
			return false;
		}
			
				
		$filename = $_FILES[$uniqueName]['tmp_name'];			
		$handle = fopen($filename, "rb");
		$contents = fread($handle, filesize($filename));
		fclose($handle);
		
		$saveVal = array('filename' => basename($_FILES[$uniqueName]['name']),
							'contents' => $contents,
							'size' => $_FILES[$uniqueName]['size']);
		return addslashes(serialize($saveVal));
		
	}
	
	public function parseData($uniqueName, $itemInfo, $data){
		if(trim($data) == "") return "";
		$fileInfo = unserialize($data);
		if($fileInfo['size'] < 1024)
			$sizeStr = $fileInfo['size']." B";
		else
			$sizeStr = ((int)($fileInfo['size']/1024))." kB";
			
		return $fileInfo['filename']." (".$sizeStr.")";
	}
	
	public function getPanelItems($uniqueName, $itemInfo){
		$arr=array();
		
		$arr[] = new fm_editPanelItemBase($uniqueName, 'label', __('Label', 'wordpress-form-manager'), array('value' => $itemInfo['label']));
		$arr[] = new fm_editPanelItemBase($uniqueName, 'max_size', __('Max file size (in kB)', 'wordpress-form-manager'), array('value' => $itemInfo['extra']['max_size']));
		$arr[] = new fm_editPanelItemNote($uniqueName, '', "<span class=\"fm-small\" style=\"padding-bottom:10px;\">".__("Your host restricts uploads to", 'wordpress-form-manager')." ".ini_get('upload_max_filesize')."B</span>", '');
		$arr[] = new fm_editPanelItemNote($uniqueName, '', "<span style=\"padding-:10px;font-weight:bold;\">".__("File Types", 'wordpress-form-manager')."</span>", '');
		$arr[] = new fm_editPanelItemNote($uniqueName, '', "<span class=\"fm-small\" style=\"padding-bottom:10px;\">".__("Enter a list of extensions separated by commas, e.g. \".txt, .rtf, .doc\"", 'wordpress-form-manager')."</span>", '');
		$arr[] = new fm_editPanelItemBase($uniqueName, 'restrict', __('Only allow', 'wordpress-form-manager'), array('value' => $itemInfo['extra']['restrict']));		
		$arr[] = new fm_editPanelItemBase($uniqueName, 'exclude', __('Do not allow', 'wordpress-form-manager'), array('value' => $itemInfo['extra']['exclude']));
		
		return $arr;
	}
	
	public function getPanelScriptOptions(){
		$opt = $this->getPanelScriptOptionDefaults();		
		$opt['extra'] = $this->extraScriptHelper(array('restrict' => 'restrict', 'exclude' => 'exclude', 'max_size' => 'max_size'));
		return $opt;
	}
	
	public function getShowHideCallbackName(){
		return "fm_".$this->getTypeName()."_show_hide";
	}
	
	public function getSaveValidatorName(){
		return "fm_file_save_validator";
	}
	
	protected function showExtraScripts(){
		?><script type="text/javascript">
		function fm_<?php echo $this->getTypeName(); ?>_show_hide(itemID, isDone){
			if(isDone){
				document.getElementById(itemID + '-edit-label').innerHTML = document.getElementById(itemID + '-label').value;
							
			}
		}		
		
		function fm_file_save_validator(itemID){
			var itemLabel = document.getElementById(itemID + '-label').value;
			var restrictExtensions = document.getElementById(itemID + '-restrict').value.toString();
			var excludeExtensions = document.getElementById(itemID + '-restrict').value.toString();
				
			if(!restrictExtensions.match(/^(\s*\.[a-zA-Z]+\s*)?(,\s*\.[a-zA-Z]+\s*)*$/)){
				alert(itemLabel + ": <?php _e("'Only allow' must be a list of extensions separated by commas", 'wordpress-form-manager');?>");
				return false;
			}
			if(!excludeExtensions.match(/^(\s*\.[a-zA-Z]+\s*)?(,\s*\.[a-zA-Z]+\s*)*$/)){
				alert(itemLabel + ": <?php _e("'Do not allow' must be a list of extensions separated by commas", 'wordpress-form-manager');?>");
				return false;
			}
			
			return true;
		}
		</script>
		<?php
	}
	
	public function showUserScripts(){
		
	}

	protected function getPanelKeys(){
		return array('label');
	}
}
?>