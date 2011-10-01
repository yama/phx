<?php
/*####
#
#	Name: PHx (Placeholders Xtended)
#	Version: 2.1.5
#	Modified by Nick to include external files
#	Author: Armand "bS" Pondman (apondman@zerobarrier.nl)
#	Date: July 13, 2007
#
####*/

class PHxParser {
	var $placeholders = array();
	
	function PHxParser($debug=0,$maxpass=50) {
		global $modx;
		$this->name = 'PHx';
		$this->version = '2.1.5';
		$this->user['mgrid'] = intval($_SESSION['mgrInternalKey']);
		$this->user['usrid'] = intval($_SESSION['webInternalKey']);
		$this->user['id'] = ($this->user['usrid'] > 0 ) ? (-$this->user['usrid']) : $this->user['mgrid'];
		$this->cache['cm'] = array();
		$this->cache['ui'] = array();
		$this->cache['mo'] = array();
		$this->safetags[0][0] = '~(?<![\[]|^\^)\[(?=[^\+\*\(\[]|$)~s';
		$this->safetags[0][1] = '~(?<=[^\+\*\)\]]|^)\](?=[^\]]|$)~s';
		$this->safetags[1][0] = '&_PHX_INTERNAL_091_&';
		$this->safetags[1][1] = '&_PHX_INTERNAL_093_&';
		$this->safetags[2][0] = '[';
		$this->safetags[2][1] = ']';
		$this->console = array();
		$this->debug = ($debug!='') ? $debug : 0;
		$this->debugLog = false;
		$this->curPass = 0;
		$this->maxPasses = ($maxpass!='') ? $maxpass : 50;
		$this->swapSnippetCache = array();
		$modx->setPlaceholder('phx', '&_PHX_INTERNAL_&');
	}
	
	// Plugin event hook for MODX
	function OnParseDocument() {
		global $modx;
		// Get document output from MODX
		$template = $modx->documentOutput;
		// To the parse cave .. let's go! *insert batman tune here*
		$template = $this->Parse($template);
		// Set processed document output in MODX
		$modx->documentOutput = $template;
	}
	
	// Parser: Preparation, cleaning and checkup
	function Parse($template='') {
		global $modx;
		// If we already reached max passes don't get at it again.
		if ($this->curPass == $this->maxPasses) return $template;
		// Set template pre-process hash
		$st = md5($template);
		// Replace non-call characters in the template: [, ]
		$template = preg_replace($this->safetags[0],$this->safetags[1],$template);
		// To the parse mobile.. let's go! *insert batman tune here*
		$template = $this->ParseValues($template);
		// clean up unused placeholders that have modifiers attached (MODX can't clean them)
		preg_match_all('~\[(\+|\*|\()([^:\+\[\]]+)([^\[\]]*?)(\1|\))\]~s', $template, $matches);
		if ($matches[0]) {
//			$template = str_replace($matches[0], '', $template);
			$this->Log("Found unsolved tags: \n" . implode("\n",$matches[2]) );
		}
		// Restore non-call characters in the template: [, ]
		$template = str_replace($this->safetags[1],$this->safetags[2],$template);
		// Set template post-process hash
		$et = md5($template);
		// If template has changed, parse it once more...
		if ($st!=$et) $template = $this->Parse($template);
		// Write an event log if debugging is enabled and there is something to log
		if ($this->debug && $this->debugLog) {
			$modx->logEvent($this->curPass,1,$this->createEventLog(), $this->name.' '.$this->version);
			$this->debugLog = false;
		}
		// Return the processed template
		return $template;
	}
	
	// Parser: Tag detection and replacements
	function ParseValues($template='') {
		global $modx;
		
		$this->curPass = $this->curPass + 1;
		$st = md5($template);
		
		//$this->LogSource($template);
		$this->LogPass();
		
		// MODX Chunks
		$this->Log('MODX Chunks -> Merging all chunk tags');
		if(strpos($template,'{{')!==false) $template = $modx->mergeChunkContent($template);
		
		// MODX Snippets
		if(strpos($template,'[[')!==false) $template = $this->evalSnippets($template);
		
		// MODX TVs
		if(strpos($template,'[*')!==false) $template = $this->mergeContent($template,'*');
		
		// MODX Setting eXtended
		if(strpos($template,'[(')!==false) $template = $this->mergeContent($template,'(');
		
		// PHx / MODX Tags
		if ( preg_match_all('~\[(\+)([^:\+\[\]]+)([^\[\]]*?)\+\]~s',$template, $matches)) {

			//$matches[0] // Complete string that's need to be replaced
			//$matches[1] // Type
			//$matches[2] // The placeholder(s)
			//$matches[3] // The modifiers
			//$matches[4] // Type (end character)
					
			$count = count($matches[0]);
			$var_search = array();
			$var_replace = array();
			for($i=0; $i<$count; $i++)
			{
				$replace = NULL;
				$match = $matches[0][$i];
				$type = $matches[1][$i];
				$input = $matches[2][$i];
				$modifiers = $matches[3][$i];
				$var_search[] = $match;
				$this->Log('MODX / PHx placeholder variable: ' . $input);
				// Check if placeholder is set
				if ( !array_key_exists($input, $this->placeholders) && !array_key_exists($input, $modx->placeholders) )
				{
					// not set so try again later.
					$replace = $match;
					$this->Log("  |--- Skipping - hasn't been set yet.");
				}
				else
				{
					// is set, get value and run filter
					$input = $this->getPHxVariable($input);
			  		$replace = $this->Filter($input,$modifiers);
				}
				$var_replace[] = $replace;
			 }
			 $template = str_replace($var_search, $var_replace, $template);
		}
		$et = md5($template); // Post-process template hash
		
		// Log an event if this was the maximum pass
		if($this->curPass == $this->maxPasses)
		{
			$this->Log("Max passes reached. infinite loop protection so exiting.\n If you need the extra passes set the max passes to the highest count of nested tags in your template.");
		}
		// If this pass is not at maximum passes and the template hash is not the same, get at it again.
		if(($this->curPass < $this->maxPasses) && ($st!=$et))  $template = $this->ParseValues($template);

		return $template;
	}
	
	function evalSnippets($src)
	{
		global $modx;
		
		$pieces = explode('[[',$src);
		$c = count($pieces);
		for($i=0; $i<$c; $i++)
		{
			$piece = '[[' . $pieces[$i];
			$begins = substr_count($piece,'[[');
			$ends   = substr_count($piece,']]');
			if($begins == $ends)
			{
				$calls[] = substr($piece,0,strrpos($piece,']]')) . ']]';
			}
			elseif($i<$c)
			{
				$pieces[$i+1] = $piece . $pieces[$i+1];
			}
		}
		
		$count = count($calls);
		$var_search  = array();
		$var_replace = array();
		
		// for each detected snippet
		for($i=0; $i<$count; $i++)
		{
			$this->Log('MODX Snippet -> '.$snippet);
			
			// Let MODX evaluate snippet
			$replace = $modx->evalSnippets($calls[$i]);
			$this->LogSnippet($replace);
			
			// Replace values
			$var_search[]  = $calls[$i];
			$var_replace[] = $replace;
		}
		$src = str_replace($var_search, $var_replace, $src);
		return $src;
	}
	
	function mergeContent($src, $type='*')
	{
		global $modx;
		
		$tag_head = '[' . $type;
		$tag_tail = $type . ']';
		
		while(strpos($src,$tag_tail)!==false)
		{
			$before_size = md5($src);
			$var_search  = array();
			$var_replace = array();
			$pieces = explode($tag_head,$src);
			$c = count($pieces);
			for($i=0; $i<$c; $i++)
			{
				$tv_call = $pieces[$i];
				if(strpos($tv_call,$tag_tail)===false) continue;
				
				$tv_call = substr($tv_call,0,strpos($tv_call,$tag_tail));
				
				if(strpos($tv_call,':')!==false)
				{
					list($tv_name, $modifiers) = explode(':',$tv_call, 2);
				}
				else
				{
					$tv_name   = $tv_call;
					$modifiers = '';
				}
				$tv_name   = trim($tv_name);
				$modifiers = trim($modifiers);
				if($type=='*') $this->Log('MODX TV/DV: ' . $tv_name);
				if($type=='(') $this->Log('MODX Setting variable: ' . $tv_name);
				$replace   = $modx->mergeDocumentContent($tag_head. $tv_name .$tag_tail);
				
				if($modifiers)
				{
					$modifiers = ':' . $modifiers;
					$replace = $this->Filter($replace, $modifiers);
				}
				
				$var_search[]  = $tag_head . $tv_call . $tag_tail;
				$var_replace[] = $replace;
			}
			
			$src = str_replace($var_search, $var_replace, $src);
			$after_size = md5($src);
			if($before_size===$after_size) break;
		}
		
		return $src;
	}
	
	// Parser: modifier detection and eXtended processing if needed
	function Filter($input, $modifiers) {
		global $modx;
		$output = $input;
		$this->Log("  |--- Input = '". $output ."'");
		if (preg_match_all('~:([^:=]+)(?:=`(.*?)`(?=:[^:=]+|$))?~s',$modifiers, $matches)) {
			$modifier_cmd = $matches[1]; // modifier command
			$modifier_value = $matches[2]; // modifier value
			$count = count($modifier_cmd);
			$condition = array();
			for($i=0; $i<$count; $i++) {
				$output = trim($output);
				$this->Log("  |--- Modifier = '". $modifier_cmd[$i] ."'");
				if ($modifier_value[$i] != '') $this->Log("  |--- Options = '". $modifier_value[$i] ."'");
				switch ($modifier_cmd[$i]) {
					#####  Conditional Modifiers 
					case 'input':
					case 'if':
						$output = $modifier_value[$i]; break;
					case 'equals':
					case 'is':
					case 'eq':
						$condition[] = intval(($output==$modifier_value[$i])); break;
					case 'notequals':
					case 'isnot':
					case 'isnt':
					case 'ne':
						$condition[] = intval(($output!=$modifier_value[$i]));break;
					case 'isgreaterthan':
					case 'isgt':
					case 'eg':
						$condition[] = intval(($output>=$modifier_value[$i]));break;
					case 'islowerthan':
					case 'islt':
					case 'el':
						$condition[] = intval(($output<=$modifier_value[$i]));break;
					case 'greaterthan':
					case 'gt':
						$condition[] = intval(($output>$modifier_value[$i]));break;
					case 'lowerthan':
					case 'lt':
						$condition[] = intval(($output<$modifier_value[$i]));break;
					case 'isinrole':
					case 'ir':
					case 'memberof':
					case 'mo':
						// Is Member Of  (same as inrole but this one can be stringed as a conditional)
						if ($output == '&_PHX_INTERNAL_&') $output = $this->user['id'];
						$grps = (strlen($modifier_value) > 0 ) ? explode(',',$modifier_value[$i]) :array();
						$condition[] = intval($this->isMemberOfWebGroupByUserId($output,$grps));
						break;
					case 'or':
						$condition[] = '||';break;
					case 'and':
						$condition[] = '&&';break;
					case 'show':
						$conditional = implode(' ',$condition);
						$isvalid = intval(eval('return ('. $conditional. ');'));
						if (!$isvalid) { $output = NULL;}
					case 'then':
						$conditional = implode(' ',$condition);
						$isvalid = intval(eval('return ('. $conditional. ');'));
						if ($isvalid) { $output = $modifier_value[$i]; }
						else { $output = NULL; }
						break;
					case 'else':
						$conditional = implode(' ',$condition);
						$isvalid = intval(eval('return ('. $conditional. ');'));
						if (!$isvalid) { $output = $modifier_value[$i]; }
						break;
					case 'select':
						$raw = explode('&',$modifier_value[$i]);
						$map = array();
						$c = count($raw);
						for($m=0; $m<$c; $m++) {
							$mi = explode('=',$raw[$m]);
							$map[$mi[0]] = $mi[1];
						}
						$output = $map[$output];
						break;
					##### End of Conditional Modifiers
					
					#####  String Modifiers 
					case 'lcase':
					case 'strtolower':
						$output = strtolower($output); break;
					case 'ucase':
					case 'strtoupper':
						$output = strtoupper($output); break;
					case 'htmlent':
					case 'htmlentities':
						$output = htmlentities($output,ENT_QUOTES,$modx->config['etomite_charset']); break;
					case 'html_entity_decode':
						$output = html_entity_decode($output,ENT_QUOTES,$modx->config['etomite_charset']); break;
					case 'esc':
						$output = preg_replace('/&amp;(#[0-9]+|[a-z]+);/i', '&$1;', htmlspecialchars($output));
  						$output = str_replace(array('[', ']', '`'),array('&#91;', '&#93;', '&#96;'),$output);
						break;
					case 'strip':
						$output = preg_replace("~([\n\r\t\s]+)~",' ',$output); break;
					case 'notags':
					case 'strip_tags':
					$output = strip_tags($output); break;
					case 'length':
					case 'len':
					case 'strlen':
						$output = strlen($output); break;
					case 'reverse':
					case 'strrev':
						$output = strrev($output); break;
					case 'wordwrap':
						// default: 70
					  	$wrapat = intval($modifier_value[$i]) ? intval($modifier_value[$i]) : 70;
						$output = preg_replace("~(\b\w+\b)~e","wordwrap('\\1',\$wrapat,' ',1)",$output);
						break;
					case 'limit':
						// default: 100
					  	$limit = intval($modifier_value[$i]) ? intval($modifier_value[$i]) : 100;
						$output = substr($output,0,$limit);
						break;
					case 'str_shuffle':
					case 'shuffle':
						$output = str_shuffle($output); break;
					case 'str_word_count':
					case 'word_count':
					case 'wordcount':
						$output = str_word_count($output); break;
					
					// These are all straight wrappers for PHP functions
					case 'ucfirst':
					case 'lcfirst':
					case 'ucwords':
					case 'addslashes':
					case 'ltrim':
					case 'rtrim':
					case 'trim':
					case 'nl2br':
					case 'md5':
						$output = $modifier_cmd[$i]($output); break;
					
					
					#####  Special functions 
					case 'math':
						$filter = preg_replace("~([a-zA-Z\n\r\t\s])~",'',$modifier_value[$i]);
						$filter = str_replace('?',$output,$filter);
						$output = eval('return '.$filter.';');
						break;
					case 'ifempty':
						if (empty($output)) $output = $modifier_value[$i]; break;
					case 'date':
						$output = $this->mb_strftime($modifier_value[$i],0+$output); break;
					case 'set':
						$c = $i+1;
						if ($count>$c&&$modifier_cmd[$c]=='value') $output = preg_replace('~([^a-zA-Z0-9])~','',$modifier_value[$i]);
						break;
					case 'value':
						if ($i>0&&$modifier_cmd[$i-1]=='set') { $modx->SetPlaceholder('phx.'.$output,$modifier_value[$i]); }
						$output = NULL;
						break;
					case 'userinfo':
						if ($output == '&_PHX_INTERNAL_&') $output = $this->user['id'];
						$output = $this->ModUser($output,$modifier_value[$i]);
						break;
					case 'inrole':
						// deprecated
						if ($output == '&_PHX_INTERNAL_&') $output = $this->user['id'];
						$grps = (strlen($modifier_value) > 0 ) ? explode(',',$modifier_value[$i]) :array();
						$output = intval($this->isMemberOfWebGroupByUserId($output,$grps));
						break;
						
					// If we haven't yet found the modifier, let's look elsewhere	
					default:
						// Is a snippet defined?
						if (!array_key_exists($modifier_cmd[$i], $this->cache['cm'])) {
							$tbl_site_snippets = $modx->getFullTableName('site_snippets');
							$cmd = ''; $cmd = $modifier_cmd[$i];
							$sql = "SELECT snippet FROM {$tbl_site_snippets} WHERE {$tbl_site_snippets}.name='phx:{$cmd}';";
			             	$result = $modx->db->query($sql);
			             	if ($modx->recordCount($result) == 1) {
								$row = $modx->fetchRow($result);
						 		$cm = $this->cache['cm'][$modifier_cmd[$i]] = $row['snippet'];
						 		$this->Log('  |--- DB -> Custom Modifier');
						 	} else if ($modx->recordCount($result) == 0){ // If snippet not found, look in the modifiers folder
								$filename = $modx->config['rb_base_dir'] . 'plugins/phx/modifiers/'.$modifier_cmd[$i].'.phx.php';
								if (@file_exists($filename)) {
									$file_contents = @file_get_contents($filename);
									$file_contents = str_replace('<?php', '', $file_contents);
									$file_contents = str_replace('?>', '', $file_contents);
									$file_contents = str_replace('<?', '', $file_contents);
									$cm = $this->cache['cm'][$modifier_cmd[$i]] = $file_contents;
									$this->Log("  |--- File ($filename) -> Custom Modifier");
								} else {
									$cm = '';
									$this->Log("  |--- PHX Error:  {$modifier_cmd[$i]} could not be found");
								}
							}
						 } else {
						 	$cm = $this->cache['cm'][$modifier_cmd[$i]];
						 	$this->Log('  |--- Cache -> Custom Modifier');
						 }
						 ob_start();
						 $options = $modifier_value[$i];
		        	     $custom = eval($cm);
		    		     $msg = ob_get_contents();
						 $output = $msg.$custom;
				         ob_end_clean();
						 break;
				}
				if (count($condition)) $this->Log("  |--- Condition = '". $condition[count($condition)-1] ."'");
				$this->Log("  |--- Output = '". $output ."'");
			}
		}
		return $output;
	}
	
	// Event logging (debug)
	function createEventLog() {
		if($this->console) {
			$console = implode("\n",$this->console);
			$this->console = array();
			return '<pre style="overflow: auto;">' . $console . '</pre>';
		}
	}
	
	// Returns a cleaned string escaping the HTML and special MODX characters
	function LogClean($string) {
		$string = preg_replace('/&amp;(#[0-9]+|[a-z]+);/i', '&$1;', htmlspecialchars($string));
		$string = str_replace(array('[', ']', '`'),array('&#91;', '&#93;', '&#96;'),$string);
		return $string;
	}
	
	// Simple log entry
	function Log($string) {
		if ($this->debug) {$this->debugLog = true; $this->console[] = (count($this->console)+1-$this->curPass). ' ['. $this->mb_strftime('%H:%M:%S',time()). '] ' . $this->LogClean($string);}
	}
	
	// Log snippet output
	function LogSnippet($string) {
		if ($this->debug) {$this->debugLog = true; $this->console[] = (count($this->console)+1-$this->curPass). ' ['. $this->mb_strftime('%H:%M:%S',time()). '] ' . '  |--- Returns: <div style="margin: 10px;">' . $this->LogClean($string).'</div>';}
	}
	
	// Log pass
	function LogPass() {
		$this->console[] = '<div style="margin: 2px;margin-top: 5px;border-bottom: 1px solid black;">Pass ' . $this->curPass . '</div>';
	}
	
	// Log pass
	function LogSource($string) {
		$this->console[] = '<div style="margin: 2px;margin-top: 5px;border-bottom: 1px solid black;">Source:</div>' . $this->LogClean($string);
	}
	
	
	// Returns the specified field from the user record
	// positive userid = manager, negative integer = webuser
	function ModUser($userid,$field) {
		global $modx;
		if (!array_key_exists($userid, $this->cache['ui'])) {
			if (intval($userid) < 0) {
				$user = $modx->getWebUserInfo(-($userid));
			} else {
				$user = $modx->getUserInfo($userid);
			}
			$this->cache['ui'][$userid] = $user;
		} else {
			$user = $this->cache['ui'][$userid];
		}
		return $user[$field];
	}
	 
	 // Returns true if the user id is in one the specified webgroups
	 function isMemberOfWebGroupByUserId($userid=0,$groupNames=array()) {
		global $modx;
		
		// if $groupNames is not an array return false
		if(!is_array($groupNames)) return false;
		
		// if the user id is a negative number make it positive
		if (intval($userid) < 0) { $userid = -($userid); }
		
		// Creates an array with all webgroups the user id is in
		if (!array_key_exists($userid, $this->cache['mo'])) {
			$tbl = $modx->getFullTableName('webgroup_names');
			$tbl2 = $modx->getFullTableName('web_groups');
			$sql = "SELECT wgn.name FROM $tbl wgn INNER JOIN $tbl2 wg ON wg.webgroup=wgn.id AND wg.webuser='{$userid}'";
			$this->cache['mo'][$userid] = $grpNames = $modx->db->getColumn('name',$sql);
		} else {
			$grpNames = $this->cache['mo'][$userid];
		}
		// Check if a supplied group matches a webgroup from the array we just created
		foreach($groupNames as $k=>$v)
			if(in_array(trim($v),$grpNames)) return true;
		
		// If we get here the above logic did not find a match, so return false
		return false;
	 }
	 
	// Returns the value of a PHx/MODX placeholder.
	function getPHxVariable($name) {
		global $modx;
		// Check if this variable is created by PHx 
		if (array_key_exists($name, $this->placeholders)) {
			// Return the value from PHx
			return $this->placeholders[$name];
		} else {
			// Return the value from MODX
			return $modx->getPlaceholder($name);
		}
	}
	
	// Sets a placeholder variable which can only be access by PHx
	function setPHxVariable($name, $value) {
		if ($name != 'phx') $this->placeholders[$name] = $value;
	}
	
	function mb_strftime($format='', $timestamp='')
	{
		global $modx;
		
		if(empty($format)) $format = $modx->toDateFormat(null, 'formatOnly') . ' %H:%M';
		
		if(method_exists($modx,'mb_strftime'))
		{
			$str = $modx->mb_strftime($format,$timestamp);
		}
		else $str = strftime($format,$timestamp);
	    return $str;
	}
}
?>
