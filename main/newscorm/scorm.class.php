<?php
/**
 * Defines the scorm class, which is meant to contain the scorm items (nuclear elements)
 * @package dokeos.learnpath.scorm
 * @author	Yannick Warnier <ywarnier@beeznest.org>
 */
/**
 * Defines the "scorm" child of class "learnpath"
 * @package dokeos.learnpath.scorm
 */
require_once('scormItem.class.php');
require_once('scormMetadata.class.php');
require_once('scormOrganization.class.php');
require_once('scormResource.class.php');
class scorm extends learnpath {
	var $manifest = array();
	var $resources = array();
	var $resources_att = array();
	var $organizations = array();
	var $organizations_att = array();
	var $metadata = array();
	var $idrefs = array(); //will hold the references to resources for each item ID found
	var $refurls = array(); //for each resource found, stores the file url/uri
	var $subdir = ''; //path between the scorm/ directory and the imsmanifest.xml e.g. maritime_nav/maritime_nav. This is the path that will be used in the lp_path when importing a package
	var $items = array(); 
	var $zipname = ''; //keeps the zipfile safe for the object's life so that we can use it if no title avail
	var $lastzipnameindex = 0; //keeps an index of the number of uses of the zipname so far
	var $manifest_encoding = 'UTF-8';
	var $debug = 0;
	/**
	 * Class constructor. Based on the parent constructor.
	 * @param	string	Course code
	 * @param	integer	Learnpath ID in DB
	 * @param	integer	User ID
	 */
    function scorm($course_code=null,$resource_id=null,$user_id=null) {
    	if($this->debug>0){error_log('New LP - scorm::scorm('.$course_code.','.$resource_id.','.$user_id.') - In scorm constructor',0);}
    	if(!empty($course_code) and !empty($resource_id) and !empty($user_id))
    	{
    		parent::learnpath($course_code, $resource_id, $user_id);
    	}else{
    		//do nothing but still build the scorm object
    	}
    }
    /**
     * Opens a resource
     * @param	integer	Database ID of the resource
     */
    function open($id)
    {
    	if($this->debug>0){error_log('New LP - scorm::open() - In scorm::open method',0);}
    	// redefine parent method
    }
    /**
     * Possible SCO status: see CAM doc 2.3.2.5.1: passed, completed, browsed, failed, not attempted, incomplete
     */
    /**
     * Prerequisites: see CAM doc 2.3.2.5.1 for pseudo-code
     */
    /**
     * Parses an imsmanifest.xml file and puts everything into the $manifest array
     * @param	string	Path to the imsmanifest.xml file on the system. If not defined, uses the base path of the course's scorm dir
	 * @return	array	Structured array representing the imsmanifest's contents 
     */
     function parse_manifest($file='')
     {
    	if($this->debug>0){error_log('In scorm::parse_manifest('.$file.')',0);}
		if(empty($file)){
     		//get the path of the imsmanifest file
     	}
     	if(is_file($file) and is_readable($file))
     	{
			$v = substr(phpversion(),0,1);
			if($v == 4){
		    	if($this->debug>0){error_log('In scorm::parse_manifest() - Parsing using PHP4 method',0);}
	     		$var = file_get_contents($file);
	     		//quick ugly hack to remove xml:id attributes from the file (break the system if xslt not installed)
	     		$var = preg_replace('/xml:id=["\']id_\d{1,4}["\']/i','',$var);
	     		$doc = xmldoc($var);
	     		//error_reporting(E_ALL);
	     		//$doc = xmldocfile($file);
	     		if(!empty($doc->encoding)){
	     			$this->manifest_encoding = strtoupper($doc->encoding);
	     		}
	     		if($this->debug>1){error_log('New LP - Called xmldoc() (encoding:'.strtoupper($doc->encoding).')',0);}
	     		if(!$doc)
	     		{
		     		if($this->debug>1){error_log('New LP - File '.$file.' is not an XML file',0);}
		     		//$this->set_error_msg("File $file is not an XML file");
	     			return null;
	     		}else{
	     			if($this->debug>1){error_log('New LP - File '.$file.' is XML',0);}
	     			$root = $doc->root();
	     			$attributes = $root->attributes();
	     			for($a=0; $a<sizeof($attributes);$a++)
	     			{//<manifest> element attributes
	     				if($attributes[$a]->type == XML_ATTRIBUTE_NODE){
							$this->manifest[$attributes[$a]->name] = $attributes[$a]->value;
	     				}
	     			}
	     			$this->manifest['name'] = $root->name;
	     			$children = $root->children();
	     			for($b=0; $b<sizeof($children);$b++)
	     			{
	     				//<manifest> element children (can be <metadata>, <organizations> or <resources> )
	     				if($children[$b]->type == XML_ELEMENT_NODE){
		     				switch($children[$b]->tagname){
		     					case 'metadata':
		     						//parse items from inside the <metadata> element
		     						$this->metadata = new scormMetadata('manifest',$children[$b]);
		     						break;
		     					case 'organizations':
		     						//contains the course structure - this element appears 1 and only 1 time in a package imsmanifest. It contains at least one 'organization' sub-element
		     						$orgs_attribs = $children[$b]->attributes();
		     						for($c=0; $c<sizeof($orgs_attribs);$c++)
		     						{//attributes of the <organizations> element
	     								if($orgs_attribs[$c]->type == XML_ATTRIBUTE_NODE){
			     							$this->manifest['organizations'][$orgs_attribs[$c]->name] = $orgs_attribs[$c]->value;
	     								}
		     						}
		     						$orgs_nodes = $children[$b]->children();
		     						$i = 0;
		     						$found_an_org = false;
		     						foreach($orgs_nodes as $c => $dummy)
		     						{
		     							//<organization> elements - can contain <item>, <metadata> and <title>
		     							//Here we are at the 'organization' level. There might be several organization tags but
		     							//there is generally only one.
		     							//There are generally three children nodes we are looking for inside and organization:
		     							//-title
		     							//-item (may contain other item tags or may appear several times inside organization)
		     							//-metadata (relative to the organization)
		     							$found_an_org = false;
	     								$orgnode =& $orgs_nodes[$c];
	     								switch($orgnode->type){
		     								case XML_TEXT_NODE:
		     									//ignore here
		     									break;
		     								case XML_ATTRIBUTE_NODE:
		     									//just in case ther would be interesting attributes inside the organization tag. There shouldn't
		     									//as this is a node-level, not a data level
		     									//$manifest['organizations'][$i][$orgnode->name] = $orgnode->value;
		     									//$found_an_org = true;
		     									break;
		     								case XML_ELEMENT_NODE:
		     									//<item>,<metadata> or <title> (or attributes)
			     								$organizations_attributes = $orgnode->attributes();
			     								foreach($organizations_attributes as $d1 => $dummy)
			     								{
													//$manifest['organizations'][$i]['attributes'][$organizations_attributes[$d1]->name] = $organizations_attributes[$d1]->value;
			     									//$found_an_org = true;
			     									$this->organizations_att[$organizations_attributes[$d1]->name] = $organizations_attributes[$d1]->value;
			     								}
		     									$oOrganization = new scormOrganization('manifest',$orgnode);
		     									if($oOrganization->identifier != ''){
		     										$name = $oOrganization->get_name();
		     										if(empty($name)){
		     											//if the org title is empty, use zip file name
		     											$myname = $this->zipname;
		     											if($this->lastzipnameindex != 0){
		     												$myname = $myname + $this->lastzipnameindex;
		     												$this->lastzipnameindex++;
		     											}
		     											$oOrganization->set_name($this->zipname);
		     										}
		     										$this->organizations[$oOrganization->identifier] = $oOrganization;
		     									}
		     									break;
		     							}
		     						}
		     						break;
		     					case 'resources':
		     						$resources_attribs = $children[$b]->attributes();
		     						for($c=0; $c<sizeof($resources_attribs);$c++)
		     						{
	     								if($resources_attribs[$c]->type == XML_ATTRIBUTE_NODE){
			     							$this->manifest['resources'][$resources_attribs[$c]->name] = $resources_attribs[$c]->value;
	     								}
		     						}
		     						$resources_nodes = $children[$b]->children();
		     						$i = 0;
		     						foreach($resources_nodes as $c => $dummy)
		     						{
			     						/*
			     						$my_res = scorm::parse_items($resources_nodes[$c]);
			     						scorm::parse_items_get_refurls($resources_nodes[$c],$refurls);
			     						if(count($my_res)>0)
			     						{
			     							$manifest['resources'][$i] = $my_res;
			     						}
			     						*/
		     							$oResource = new scormResource('manifest',$resources_nodes[$c]);
			     						if($oResource->identifier != ''){
											$this->resources[$oResource->identifier] = $oResource;
			     							$i++;
			     						}
		     						}
		     						//contains links to physical resources
		     						break;
		     					case 'manifest':
		     						//only for sub-manifests
		     						break;
		     				}
	     				}
	     			}
	     		}
		     	unset($doc);
	     	}elseif($v==5){
		    	if($this->debug>0){error_log('In scorm::parse_manifest() - Parsing using PHP5 method',0);}
	     		$doc = new DOMDocument();
	     		$res = $doc->load($file);
	     		if($res===false){
	     			if($this->debug>0){error_log('New LP - In scorm::parse_manifest() - Exception thrown when loading '.$file.' in DOMDocument',0);}
	     			//throw exception?
	     			return null;	     			
	     		}

	     		if(!empty($doc->xmlEncoding)){
	     			$this->manifest_encoding = strtoupper($doc->xmlEncoding);
	     		}
	     		if($this->debug>1){error_log('New LP - Called  (encoding:'.$doc->xmlEncoding.')',0);}
	     		
				$root = $doc->documentElement;
				if($root->hasAttributes()){
	     			$attributes = $root->attributes;
	     			if($attributes->length !== 0){
		     			foreach($attributes as $attrib)
		     			{//<manifest> element attributes
							$this->manifest[$attrib->name] = $attrib->value;
		     			}
	     			}
				}
     			$this->manifest['name'] = $root->tagName;
				if($root->hasChildNodes()){
	     			$children = $root->childNodes;
	     			if($children->length !== 0){
		     			foreach($children as $child)
		     			{
		     				//<manifest> element children (can be <metadata>, <organizations> or <resources> )
		     				if($child->nodeType == XML_ELEMENT_NODE){
			     				switch($child->tagName){
			     					case 'metadata':
			     						//parse items from inside the <metadata> element
			     						$this->metadata = new scormMetadata('manifest',$child);
			     						break;
			     					case 'organizations':
			     						//contains the course structure - this element appears 1 and only 1 time in a package imsmanifest. It contains at least one 'organization' sub-element
			     						$orgs_attribs = $child->attributes;
			     						foreach($orgs_attribs as $orgs_attrib)
			     						{//attributes of the <organizations> element
		     								if($orgs_attrib->nodeType == XML_ATTRIBUTE_NODE){
				     							$this->manifest['organizations'][$orgs_attrib->name] = $orgs_attrib->value;
		     								}
			     						}
			     						$orgs_nodes = $child->childNodes;
			     						$i = 0;
			     						$found_an_org = false;
			     						foreach($orgs_nodes as $orgnode)
			     						{
			     							//<organization> elements - can contain <item>, <metadata> and <title>
			     							//Here we are at the 'organization' level. There might be several organization tags but
			     							//there is generally only one.
			     							//There are generally three children nodes we are looking for inside and organization:
			     							//-title
			     							//-item (may contain other item tags or may appear several times inside organization)
			     							//-metadata (relative to the organization)
			     							$found_an_org = false;
		     								switch($orgnode->nodeType){
			     								case XML_TEXT_NODE:
			     									//ignore here
			     									break;
			     								case XML_ATTRIBUTE_NODE:
			     									//just in case there would be interesting attributes inside the organization tag. There shouldn't
			     									//as this is a node-level, not a data level
			     									//$manifest['organizations'][$i][$orgnode->name] = $orgnode->value;
			     									//$found_an_org = true;
			     									break;
			     								case XML_ELEMENT_NODE:
			     									//<item>,<metadata> or <title> (or attributes)
				     								$organizations_attributes = $orgnode->attributes;
				     								foreach($organizations_attributes as $orgs_attr)
				     								{
				     									$this->organizations_att[$orgs_attr->name] = $orgs_attr->value;
				     								}
			     									$oOrganization = new scormOrganization('manifest',$orgnode);
			     									if($oOrganization->identifier != ''){
			     										$name = $oOrganization->get_name();
			     										if(empty($name)){
			     											//if the org title is empty, use zip file name
			     											$myname = $this->zipname;
			     											if($this->lastzipnameindex != 0){
			     												$myname = $myname + $this->lastzipnameindex;
			     												$this->lastzipnameindex++;
			     											}
			     											$oOrganization->set_name($this->zipname);
			     										}
			     										$this->organizations[$oOrganization->identifier] = $oOrganization;
			     									}
			     									break;
			     							}
			     						}
			     						break;
			     					case 'resources':
			     						if($child->hasAttributes()){
				     						$resources_attribs = $child->attributes;
				     						foreach($resources_attribs as $res_attr)
				     						{
			     								if($res_attr->type == XML_ATTRIBUTE_NODE){
					     							$this->manifest['resources'][$res_attr->name] = $res_attr->value;
			     								}
				     						}
			     						}
			     						if($child->hasChildNodes()){
				     						$resources_nodes = $child->childNodes;
				     						$i = 0;
				     						foreach($resources_nodes as $res_node)
				     						{
				     							$oResource = new scormResource('manifest',$res_node);
					     						if($oResource->identifier != ''){
													$this->resources[$oResource->identifier] = $oResource;
					     							$i++;
					     						}
				     						}
			     						}
			     						//contains links to physical resources
			     						break;
			     					case 'manifest':
			     						//only for sub-manifests
			     						break;
			     				}
		     				}
			     		}
	     			}
				}
		     	unset($doc);
			}else{
		    	if($this->debug>0){error_log('In scorm::parse_manifest() - PHP version is not 4 nor 5, cannot parse',0);}
	     		$this->set_error_msg("Parsing impossible because PHP version is not 4 nor 5");
		    	return null;
			}
     	}else{
     		if($this->debug>1){error_log('New LP - Could not open/read file '.$file,0);}
     		$this->set_error_msg("File $file could not be read");
     		return null;
     	}
     	//TODO close the DOM handler
     	return $this->manifest;
     }
     /**
      * Import the scorm object (as a result from the parse_manifest function) into the database structure
      * @param	string	Unique course code 
      * @return	bool	Returns -1 on error
      */
     function import_manifest($course_code){
     	if($this->debug>0){error_log('New LP - Entered import_manifest('.$course_code.')',0);}
     	
		$sql = "SELECT * FROM ".Database::get_main_table(TABLE_MAIN_COURSE)." WHERE code='$course_code'";
        $res = api_sql_query($sql,__FILE__,__LINE__);
        if(Database::num_rows($res)<1){ error_log('Database for '.$course_code.' not found '.__FILE__.' '.__LINE__,0);return -1;}
        $row = Database::fetch_array($res);
        $dbname = Database::get_course_table_prefix().$row['db_name'];

		//get table names
		$new_lp = Database::get_course_table('lp',$dbname);
		$new_lp_item = Database::get_course_table('lp_item',$dbname);
		
		foreach($this->organizations as $id => $dummy)
		{
			$oOrganization =& $this->organizations[$id];
	     	//prepare and execute insert queries
	     	//-for learnpath
	     	//-for items
	     	//-for views?
	     	
	     	$myname = $oOrganization->get_name();
	     		$this->manifest_encoding = 'UTF-8';
	     	if($this->manifest_encoding != 'ISO-8859-1'){
	     		$myname = mb_convert_encoding($myname,'ISO-8859-1',$this->manifest_encoding);
	     		//error_log('New LP - Converting name from '.$this->manifest_encoding.' to ISO-8859-1',0);
	     	}
			$sql = "INSERT INTO $new_lp (lp_type, name, ref, description, path, force_commit, default_view_mod, default_encoding, js_lib)" .
					"VALUES (2,'".$myname."', '".$oOrganization->get_ref()."','','".$this->subdir."', 0, 'embedded', '".$this->manifest_encoding."','scorm_api.php')";
			if($this->debug>1){error_log('New LP - In import_manifest(), inserting path: '. $sql,0);}
			$res = api_sql_query($sql,__FILE__,__LINE__);
			$lp_id = Database::get_last_insert_id();
			$this->lp_id = $lp_id;
			
			//now insert all elements from inside that learning path
			//make sure we also get the href and sco/asset from the resources
			$list = $oOrganization->get_flat_items_list();
			$parents_stack = array(0);
			$parent = 0;
			$previous = 0;
			$level = 0;
			foreach($list as $item){
				if($item['level'] > $level){
					//push something into the parents array
					array_push($parents_stack,$previous);
					$parent = $previous;
				}elseif($item['level'] < $level){
					$diff = $level - $item['level'];
					//pop something out of the parents array
					for($j=1;$j<=$diff;$j++){
						$outdated_parent = array_pop($parents_stack);
					}
					$parent = array_pop($parents_stack); //just save that value, then add it back
					array_push($parents_stack,$parent);
				}
				$path = '';
				$type = 'dir';
				if(isset($this->resources[$item['identifierref']])){
					$oRes =& $this->resources[$item['identifierref']];
					$path = @$oRes->get_path();
					if(!empty($path)){
						$temptype = $oRes->get_scorm_type();
						if(!empty($temptype)){
							$type = $temptype;
						}
					}
				}
				$level = $item['level'];
				$field_add = '';
				$value_add = '';
				if(!empty($item['masteryscore'])){
					$field_add = 'mastery_score, ';
					$value_add = $item['masteryscore'].',';
				}
				$title = mysql_real_escape_string($item['title']);
		     	//if($this->manifest_encoding != 'ISO-8859-1'){
		     		//$title = mb_convert_encoding($title,'ISO-8859-1',$this->manifest_encoding);
		     	//}
				$identifier = mysql_real_escape_string($item['identifier']);
				$prereq = mysql_real_escape_string($item['prerequisites']);
				$sql_item = "INSERT INTO $new_lp_item " .
						"(lp_id,item_type,ref,title," .
						"path,min_score,max_score, $field_add" .
						"parent_item_id,previous_item_id,next_item_id," .
						"prerequisite,display_order,launch_data," .
						"parameters) " .
						"VALUES " .
						"($lp_id, '$type','".$identifier."','".$title."'," .
						"'$path',0,100, $value_add" .
						"$parent, $previous, 0, " .
						"'$prereq', ".$item['rel_order'] .", '".$item['datafromlms']."'," .
						"'".$item['parameters']."'" .
						")";
				$res_item = api_sql_query($sql_item);
				if($this->debug>1){error_log('New LP - In import_manifest(), inserting item : '.$sql_item.' : '.mysql_error(),0);}
				$item_id = Database::get_last_insert_id();
				//now update previous item to change next_item_id
				$upd = "UPDATE $new_lp_item SET next_item_id = $item_id WHERE id = $previous";
				$upd_res = api_sql_query($upd);
				//update previous item id
				$previous = $item_id;
			}
		}
     }
	 /**
	  * Intermediate to import_package only to allow import from local zip files
	  * @param	string	Path to the zip file, from the dokeos sys root 
	  * @param	string	Current path (optional)
	  * @return string	Absolute path to the imsmanifest.xml file or empty string on error
	  */
	 function import_local_package($file_path,$current_dir='')
	 {
	 	//todo prepare info as given by the $_FILES[''] vector
	 	$file_info = array();
	 	$file_info['tmp_name'] = $file_path;
	 	$file_info['name'] = basename($file_path);
	 	//call the normal import_package function
	 	return $this->import_package($file_info,$current_dir); 
	 }
     /**
      * Imports a zip file into the Dokeos structure
      * @param	string	Zip file info as given by $_FILES['userFile']
      * @return	string	Absolute path to the imsmanifest.xml file or empty string on error
      */
     function import_package($zip_file_info,$current_dir = '')
     {
     	if($this->debug>0){error_log('In scorm::import_package('.print_r($zip_file_info,true).',"'.$current_dir.'") method',0);}
     	$maxFilledSpace = 1000000000;
     	$zip_file_path = $zip_file_info['tmp_name'];
     	$zip_file_name = $zip_file_info['name'];
     	if($this->debug>1){error_log('New LP - import_package() - zip file path = '.$zip_file_path.', zip file name = '.$zip_file_name,0);}
     	$course_rel_dir  = api_get_course_path().'/scorm'; //scorm dir web path starting from /courses
		$course_sys_dir = api_get_path(SYS_COURSE_PATH).$course_rel_dir; //absolute system path for this course
		$current_dir = replace_dangerous_char(trim($current_dir),'strict'); //current dir we are in, inside scorm/
     	if($this->debug>1){error_log('New LP - import_package() - current_dir = '.$current_dir,0);}
     	
 		//$uploaded_filename = $_FILES['userFile']['name'];
		//get name of the zip file without the extension
		if($this->debug>1){error_log('New LP - Received zip file name: '.$zip_file_path,0);}
		$file_info = pathinfo($zip_file_name);
		$filename = $file_info['basename'];
		$extension = $file_info['extension'];
		$file_base_name = str_replace('.'.$extension,'',$filename); //filename without its extension
		$this->zipname = $file_base_name; //save for later in case we don't have a title
		
		if($this->debug>1){error_log("New LP - base file name is : ".$file_base_name,0);}
		$new_dir = replace_dangerous_char(trim($file_base_name),'strict');
		$this->subdir = $new_dir;
		if($this->debug>1){error_log("New LP - subdir is first set to : ".$this->subdir,0);}
	
		$zipFile = new pclZip($zip_file_path);
	
		// Check the zip content (real size and file extension)

		$zipContentArray = $zipFile->listContent();

		$package_type='';
		$at_root = false;
		$manifest = '';
		$manifest_list = array();

		//the following loop should be stopped as soon as we found the right imsmanifest.xml (how to recognize it?)
		foreach($zipContentArray as $thisContent)
		{
			//error_log('Looking at  '.$thisContent['filename'],0);
			if ( preg_match('~.(php.*|phtml)$~i', $thisContent['filename']) )
			{
	     		$this->set_error_msg("File $file contains a PHP script");
				//return api_failure::set_failure('php_file_in_zip_file');
			}
			elseif(stristr($thisContent['filename'],'imsmanifest.xml'))
			{
				//error_log('Found imsmanifest at '.$thisContent['filename'],0);
				if($thisContent['filename'] == basename($thisContent['filename'])){
					$at_root = true;
				}else{
					//$this->subdir .= '/'.dirname($thisContent['filename']);
					if($this->debug>2){error_log("New LP - subdir is now ".$this->subdir,0);}
				}
				$package_type = 'scorm';
				$manifest_list[] = $thisContent['filename'];
				$manifest = $thisContent['filename']; //just the relative directory inside scorm/
			}
			else
			{
				//do nothing, if it has not been set as scorm somewhere else, it stays as '' default
			}
			$realFileSize += $thisContent['size'];
		}
		//now get the shortest path (basically, the imsmanifest that is the closest to the root)
		$shortest_path = $manifest_list[0];
		$slash_count = substr_count($shortest_path,'/');
		foreach($manifest_list as $manifest_path){
			$tmp_slash_count = substr_count($manifest_path,'/'); 
			if($tmp_slash_count<$slash_count){
				$shortest_path = $manifest_path;
				$slash_count = $tmp_slash_count;
			}
		}
		$this->subdir .= '/'.dirname($shortest_path); //do not concatenate because already done above
		$manifest = $shortest_path;
		
		if($this->debug>1){error_log('New LP - Package type is now '.$package_type,0);}
	
		if($package_type== '')
		 // && defined('CHECK_FOR_SCORM') && CHECK_FOR_SCORM)
		{
			return api_failure::set_failure('not_scorm_content');
		}
	
		if (! enough_size($realFileSize, $course_sys_dir, $maxFilledSpace) )
		{
			return api_failure::set_failure('not_enough_space');
		}
	
		// it happens on Linux that $new_dir sometimes doesn't start with '/'
		if($new_dir[0] != '/')
		{
			$new_dir='/'.$new_dir;
		}
	
		if($new_dir[strlen($new_dir)-1] == '/')
		{
			$new_dir=substr($new_dir,0,-1);
		}
	
		/*
		--------------------------------------
			Uncompressing phase
		--------------------------------------
		*/
		/*
			The first version, using OS unzip, is not used anymore
			because it does not return enough information.
			We need to process each individual file in the zip archive to
			- add it to the database
			- parse & change relative html links
		*/
		if (PHP_OS == 'Linux' && ! get_cfg_var('safe_mode') && false)	// *** UGent, changed by OC ***
		{
			// Shell Method - if this is possible, it gains some speed
			//check availability of 'unzip' first!
			exec("unzip -d \"".$course_sys_dir."".$new_dir." ".$zip_file_path);
			if($this->debug>=1){error_log('New LP - found Linux system, using unzip',0);}
		}
		elseif(is_dir($course_sys_dir.$new_dir) OR @mkdir($course_sys_dir.$new_dir))
		{
			// PHP method - slower...
			if($this->debug>=1){error_log('New LP - Changing dir to '.$course_sys_dir.$new_dir,0);}
			$saved_dir = getcwd();
			chdir($course_sys_dir.$new_dir);
			$unzippingState = $zipFile->extract();
			for($j=0;$j<count($unzippingState);$j++)
			{
				$state=$unzippingState[$j];
	
				//TODO fix relative links in html files (?)
				$extension = strrchr($state["stored_filename"], ".");
				if($this->debug>=1){error_log('New LP - found extension '.$extension.' in '.$state['stored_filename'],0);}
				
			}
	
			if(!empty($new_dir))
			{
				$new_dir = $new_dir.'/';
			}
			//rename files, for example with \\ in it
			if($dir=@opendir($course_sys_dir.$new_dir))
			{
				if($this->debug==1){error_log('New LP - Opened dir '.$course_sys_dir.$new_dir,0);}
				while($file=readdir($dir))
				{
					if($file != '.' && $file != '..')
					{
						$filetype="file";
	
						if(is_dir($course_sys_dir.$new_dir.$file)) $filetype="folder";
						
						//TODO RENAMING FILES CAN BE VERY DANGEROUS SCORM-WISE, avoid that as much as possible!
						//$safe_file=replace_dangerous_char($file,'strict');
						$safe_file = str_replace('\\','/',$file);
	
						if($safe_file != $file){
							//@rename($course_sys_dir.$new_dir,$course_sys_dir.'/'.$safe_file);
							$mydir = dirname($course_sys_dir.$new_dir.$safe_file);
							if(!is_dir($mydir)){
								$mysubdirs = split('/',$mydir);
								$mybasedir = '/';
								foreach($mysubdirs as $mysubdir){
									if(!empty($mysubdir)){
										$mybasedir = $mybasedir.$mysubdir.'/';
										if(!is_dir($mybasedir)){
											@mkdir($mybasedir);
											if($this->debug==1){error_log('New LP - Dir '.$mybasedir.' doesnt exist. Creating.',0);}
										}
									}
								}
							}
							@rename($course_sys_dir.$new_dir.$file,$course_sys_dir.$new_dir.$safe_file);
							if($this->debug==1){error_log('New LP - Renaming '.$course_sys_dir.$new_dir.$file.' to '.$course_sys_dir.$new_dir.$safe_file,0);}
						}	
						//set_default_settings($course_sys_dir,$safe_file,$filetype);
					}
				}
	
				closedir($dir);
				chdir($saved_dir);
			}
		}else{
			return '';
		}
		return $course_sys_dir.$new_dir.$manifest;
	}
	/**
	 * Sets the proximity setting in the database
	 * @param	string	Proximity setting
	 */
	 function set_proximity($proxy=''){
		if($this->debug>0){error_log('In scorm::set_proximity('.$proxy.') method',0);}
	 	$lp = $this->get_id();
	 	if($lp!=0){
	 		$tbl_lp = Database::get_course_table('lp');
	 		$sql = "UPDATE $tbl_lp SET content_local = '$proxy' WHERE id = ".$lp;
	 		$res = api_sql_query($sql);
	 		return $res;
	 	}else{
	 		return false;
	 	}
	 }
	/**
	 * Sets the content maker setting in the database
	 * @param	string	Proximity setting
	 */
	 function set_maker($maker=''){
		if($this->debug>0){error_log('In scorm::set_maker method('.$maker.')',0);}
	 	$lp = $this->get_id();
	 	if($lp!=0){
	 		$tbl_lp = Database::get_course_table('lp');
	 		$sql = "UPDATE $tbl_lp SET content_maker = '$maker' WHERE id = ".$lp;
	 		$res = api_sql_query($sql);
	 		return $res;
	 	}else{
	 		return false;
	 	}
	 }
	 /**
	  * Exports the current SCORM object's files as a zip. Excerpts taken from learnpath_functions.inc.php::exportpath()
	  * @param	integer	Learnpath ID (optional, taken from object context if not defined)
	  */
	  function export_zip($lp_id=null){
		if($this->debug>0){error_log('In scorm::export_zip method('.$lp_id.')',0);}
	 	if(empty($lp_id)){
			if(!is_object($this))
			{
				return false;
			}
			else{
				$id = $this->get_id();
				if(empty($id)){
					return false;
				}
	 			else{
	 				$lp_id = $this->get_id();
	 			}
			}
	 	}
	 	//error_log('New LP - in export_zip()',0);
	 	//zip everything that is in the corresponding scorm dir
	 	//write the zip file somewhere (might be too big to return)
		require_once (api_get_path(LIBRARY_PATH)."fileUpload.lib.php");
		require_once (api_get_path(LIBRARY_PATH)."fileManage.lib.php");
		require_once (api_get_path(LIBRARY_PATH)."document.lib.php");
		require_once (api_get_path(LIBRARY_PATH)."pclzip/pclzip.lib.php");
		require_once ("learnpath_functions.inc.php");
		$tbl_lp = Database::get_course_table('lp');
		$_course = Database::get_course_info(api_get_course_id());

		$sql = "SELECT * FROM $tbl_lp WHERE id=".$lp_id;
		$result = api_sql_query($sql, __FILE__, __LINE__);
		$row = mysql_fetch_array($result);
		$LPname = $row['path'];
		$list = split('/',$LPname);
		$LPnamesafe = $list[0];
		//$zipfoldername = '/tmp';
		//$zipfoldername = '../../courses/'.$_course['directory']."/temp/".$LPnamesafe;
		$zipfoldername = api_get_path('SYS_COURSE_PATH').$_course['directory']."/temp/".$LPnamesafe;
		$scormfoldername = api_get_path('SYS_COURSE_PATH').$_course['directory']."/scorm/".$LPnamesafe;
		$zipfilename = $zipfoldername."/".$LPnamesafe.".zip";
	
		//Get a temporary dir for creating the zip file	
		
		//error_log('New LP - cleaning dir '.$zipfoldername,0);
		deldir($zipfoldername); //make sure the temp dir is cleared
		$res = mkdir($zipfoldername);
		//error_log('New LP - made dir '.$zipfoldername,0);
		
		//create zipfile of given directory
		$zip_folder = new PclZip($zipfilename);
		$zip_folder->create($scormfoldername.'/', PCLZIP_OPT_REMOVE_PATH, $scormfoldername.'/');
		
		//$zipfilename = '/var/www/dokeos-comp/courses/TEST2/scorm/example_document.html';
		//this file sending implies removing the default mime-type from php.ini
		//DocumentManager :: file_send_for_download($zipfilename, true, $LPnamesafe.".zip");
		DocumentManager :: file_send_for_download($zipfilename, true);

		// Delete the temporary zip file and directory in fileManage.lib.php
		my_delete($zipfilename);
		my_delete($zipfoldername);
	
		return true;
	}	 
	/**
	  * Gets a resource's path if available, otherwise return empty string
	  * @param	string	Resource ID as used in resource array
	  * @return string	The resource's path as declared in imsmanifest.xml
	  */
	  function get_res_path($id){
		if($this->debug>0){error_log('In scorm::get_res_path('.$id.') method',0);}
	  	$path = '';
	  	if(isset($this->resources[$id])){
			$oRes =& $this->resources[$id];
			$path = @$oRes->get_path();
		}
		return $path;
	  }
	 /**
	  * Gets a resource's type if available, otherwise return empty string
	  * @param	string	Resource ID as used in resource array
	  * @return string	The resource's type as declared in imsmanifest.xml
	  */
	  function get_res_type($id){
		if($this->debug>0){error_log('In scorm::get_res_type('.$id.') method',0);}
	  	$type = '';
		if(isset($this->resources[$id])){
			$oRes =& $this->resources[$id];
			$temptype = $oRes->get_scorm_type();
			if(!empty($temptype)){
				$type = $temptype;
			}
		}
		return $type;
	  }
	  /**
	   * Gets the default organisation's title
	   * @return	string	The organization's title
	   */
	  function get_title(){
		if($this->debug>0){error_log('In scorm::get_title() method',0);}
	  	$title = '';
	  	if(isset($this->manifest['organizations']['default'])){
	  		$title = $this->organizations[$this->manifest['organizations']['default']]->get_name();
	  	}elseif(count($this->organizations)==1){
	  		//this will only get one title but so we don't need to know the index
	  		foreach($this->organizations as $id => $value){
	  			$title = $this->organizations[$id]->get_name();
	  			break;
	  		}
	  	}
	  	return $title;
	  }
	  /**
	   * //TODO @TODO implement this function to restore items data from an imsmanifest,
	   * updating the existing table... This will prove very useful in case initial data
	   * from imsmanifest were not imported well enough
	   * @param	string	course Code
	   * @param string	LP ID (in database)
	   * @param string	Manifest file path (optional if lp_id defined)
	   * @return	integer	New LP ID or false on failure
	   * TODO @TODO Implement imsmanifest_path parameter
	   */
	  function reimport_manifest($course,$lp_id=null,$imsmanifest_path=''){
		if($this->debug>0){error_log('In scorm::reimport_manifest() method',0);}
		global $_course;
		//RECOVERING PATH FROM DB
		$main_table = Database::get_main_table(TABLE_MAIN_COURSE);
		//$course = Database::escape_string($course);
		$course = $this->escape_string($course);
		$sql = "SELECT * FROM $main_table WHERE code = '$course'";
		if($this->debug>2){error_log('New LP - scorm::reimport_manifest() '.__LINE__.' - Querying course: '.$sql,0);}
		//$res = Database::query($sql);
		$res = api_sql_query($sql);
		if(Database::num_rows($res)>0)
		{
			$this->cc = $course;
		}
		else
		{
			$this->error = 'Course code does not exist in database ('.$sql.')';
			return false;
   		}
   		
   		//TODO make it flexible to use any course_code (still using env course code here)
		//$lp_table = Database::get_course_table(LEARNPATH_TABLE);
    	$lp_table = Database::get_course_table('lp');

		//$id = Database::escape_integer($id);
		$lp_id = $this->escape_string($lp_id);
		$sql = "SELECT * FROM $lp_table WHERE id = '$lp_id'";
		if($this->debug>2){error_log('New LP - scorm::reimport_manifest() '.__LINE__.' - Querying lp: '.$sql,0);}
		//$res = Database::query($sql);
		$res = api_sql_query($sql);
		if(Database::num_rows($res)>0)
		{
    			$this->lp_id = $lp_id;
    			$row = Database::fetch_array($res);
    			$this->type = $row['lp_type'];
    			$this->name = stripslashes($row['name']);
    			$this->encoding = $row['default_encoding'];
    			$this->proximity = $row['content_local'];
    			$this->maker = $row['content_maker'];
    			$this->prevent_reinit = $row['prevent_reinit'];
    			$this->license = $row['content_license'];
    			$this->scorm_debug = $row['debug'];
	   			$this->js_lib = $row['js_lib'];
	   			$this->path = $row['path'];
	   			if($this->type == 2){
    				if($row['force_commit'] == 1){
    					$this->force_commit = true;
    				}
    			}
    			$this->mode = $row['default_view_mod'];
				$this->subdir = $row['path'];
		}
	  	//parse the manifest (it is already in this lp's details)
	  	$manifest_file = api_get_path('SYS_COURSE_PATH').$_course['directory'].'/scorm/'.$this->subdir.'/imsmanifest.xml';
	  	if($this->subdir == ''){
	  		$manifest_file = api_get_path('SYS_COURSE_PATH').$_course['directory'].'/scorm/imsmanifest.xml';
	  	}
	  	echo $manifest_file;
	  	if(is_file($manifest_file) && is_readable($manifest_file)){
		  	//re-parse the manifest file
	  		if($this->debug>1){error_log('New LP - In scorm::reimport_manifest() - Parsing manifest '.$manifest_file,0);}
			$manifest = $this->parse_manifest($manifest_file);
		  	//import new LP in DB (ignore the current one)
	  		if($this->debug>1){error_log('New LP - In scorm::reimport_manifest() - Importing manifest '.$manifest_file,0);}
			$this->import_manifest(api_get_course_id());
	  	}else{
	  		if($this->debug>0){error_log('New LP - In scorm::reimport_manifest() - Could not find manifest file at '.$manifest_file,0);}
	  	}  	
	  	return false;
	  }
}
?>