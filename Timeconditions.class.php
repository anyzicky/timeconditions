<?php
namespace FreePBX\modules;
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//	Copyright 2015 Sangoma Technologies.

class Timeconditions implements \BMO {
	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new Exception("Not given a FreePBX Object");
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->errormsg ='';
	}
	public function install() {
		$this->cleanuptcmaint();
	}
	public function uninstall() {}
	public function backup() {}
	public function restore($backup) {}
	public function doConfigPageInit($page) {
		$request = $_REQUEST;
		switch($page) {
			case "timeconditions":
				isset($request['action'])?$action = $request['action']:$action='';
				isset($request['itemid'])?$itemid=$request['itemid']:$itemid='';
				isset($request['view'])?$view=$request['view']:$view='';
				$invert_hint = isset($request['invert_hint'])?$request['invert_hint']:'0';
				$fcc_password = isset($request['fcc_password'])?$request['fcc_password']:'';
				//if submitting form, update database
				switch ($action) {
					case "add":
						$itemid = timeconditions_add($request);
						$_REQUEST['extdisplay'] = $itemid;
						needreload();
					break;
					case "delete":
						timeconditions_del($itemid);
						$_REQUEST['extdisplay'] = NULL;
						needreload();
					break;
					case "edit":  //just delete and re-add
						timeconditions_edit($itemid,$request);
						needreload();
					break;
				}
			break;
			case "timegroups":
				$action= isset($request['action'])?$request['action']:null;
				$timegroup= isset($request['extdisplay'])?$request['extdisplay']:null;
				$description= isset($request['description'])?$request['description']:null;
				$times = isset($request['times'])?$request['times']:null;
				switch($action){
					case 'add':
						$_REQUEST['extdisplay'] = timeconditions_timegroups_add_group($description,$times);
						break;
					case 'edit':
						timeconditions_timegroups_edit_group($timegroup,$description);
						timeconditions_timegroups_edit_times($timegroup,$times);
						break;
					case 'del':
						$usage =  timeconditions_timegroups_list_usage($timegroup);
						if(isset($usage) && sizeof($usage) >= 1){
							$this->errormsg = _("Could not delete time group as it is in use");
							return;
						}
						timeconditions_timegroups_del_group($timegroup);
						break;
					case 'getJSON':
						header('Content-Type: application/json');
						switch ($request['jdata']) {
							case 'grid':
								$timegroupslist = timeconditions_timegroups_list_groups();
								$rdata = array();
								foreach($timegroupslist as $tg){
									$rdata[] = array('text' => $tg['text'],'value' => $tg['value'], 'link' => array($tg['text'],$tg['value']));
								}
								echo json_encode($rdata);
								exit();
								break;
							default:
								echo json_encode(array("error"=>"Unknown Request"));
								exit();
								break;
						}
						break;
				}
				break;
		}
	}

	/**
	 * Gets rid of the millions of rows of tc-maint
	 * slowly over time
	 */
	public function cleanuptcmaint($limit = 4000) {
		$ASTVARLIBDIR = $this->FreePBX->Config->get("ASTVARLIBDIR");
		foreach($this->FreePBX->Cron->getAll() as $cron) {
			$str = str_replace("/", "\/", $ASTVARLIBDIR."/bin/cleanuptcmaint.php");
			if(preg_match("/cleanuptcmaint.php$/",$cron)) {
				$this->FreePBX->Cron->remove($cron);
			}
		}
		try {
			$dbh = $this->FreePBX->Cel->cdrdb;
			$limit = !empty($limit) ? " LIMIT ".$limit : "";
			$del = $dbh->prepare("DELETE from asteriskcdrdb.cel WHERE context LIKE '%tc-maint%'".$limit);
			$del->execute();
			$count = $del->rowCount();
			if($count > 0 && !empty($limit)) {
				$this->FreePBX->Cron->add("0 */6 * * * ".$ASTVARLIBDIR."/bin/cleanuptcmaint.php");
				return true;
			}
		} catch(\Exception $e) {
			return;
		}
		return false;
	}

	/**
	 * Update or remove the TC-Maint cron job
	 * @return [type]           [description]
	 */
	public function updateCron() {
		$ASTVARLIBDIR = $this->FreePBX->Config->get("ASTVARLIBDIR");
		$TCMAINT = $this->FreePBX->Config->get("TCMAINT");
		$TCINTERVAL = $this->FreePBX->Config->get("TCINTERVAL");

		foreach($this->FreePBX->Cron->getAll() as $cron) {
			$str = str_replace("/", "\/", $ASTVARLIBDIR."/bin/schedtc.php");
			if(preg_match("/schedtc.php$/",$cron)) {
				$this->FreePBX->Cron->remove($cron);
			}
		}
		if(!$TCMAINT) {
			return;
		}
		switch($TCINTERVAL) {
			case "900":
			case "600":
			case "300":
			case "240":
			case "180":
			case "120":
				$t = $TCINTERVAL / 60;
				$time = "*/".$t." * * * *";
			break;
			case "60":
			default:
				$time = "* * * * *";
			break;
		}
		$line = $time." ".$ASTVARLIBDIR."/bin/schedtc.php";
		$this->FreePBX->Cron->add($line);
	}

	public function getActionBar($request) {
		switch($request['display']) {
			case 'timeconditions':
			case 'timegroups':
				$buttons = array(
					'delete' => array(
						'name' => 'delete',
						'id' => 'delete',
						'value' => _('Delete')
					),
					'reset' => array(
						'name' => 'reset',
						'id' => 'reset',
						'value' => _('Reset')
					),
					'submit' => array(
						'name' => 'submit',
						'id' => 'submit',
						'value' => _('Submit')
					)
				);
				if (empty($request['itemid']) && $request['display'] == 'timeconditions') {
					unset($buttons['delete']);
				}
				if (empty($request['extdisplay']) && $request['display'] == 'timegroups') {
					unset($buttons['delete']);
				}
				if ($request['view'] != 'form'){
					unset($buttons);
				}
			break;
		}
		return $buttons;
	}
	public function serverTime(){
		return localtime();
	}
	public function ajaxRequest($req, &$setting) {
		switch ($req) {
			case 'getGroups':
			case 'getJSON':
				return true;
			break;
			default:
				return false;
			break;
		}
	}
	public function ajaxHandler(){
		$request = $_REQUEST;
		switch ($_REQUEST['command']) {
			case 'getGroups':
				$sql = 'SELECT id FROM timegroups_groups order by id desc limit 1';
				$sth = $this->db->prepare($sql);
				$sth->execute();
				$row = $sth->fetch(\PDO::FETCH_ASSOC);
				$timegroupslist = $this->listTimegroups();
				return array("status" => true, "groups" => $timegroupslist, "last" => $row['id']);
			break;
			case 'getJSON':
			switch ($request['jdata']) {
				case 'tggrid':
					$timegroupslist = $this->listTimegroups();
					$rdata = array();
					foreach($timegroupslist as $tg){
					$rdata[] = array('text' => $tg['text'],'value' => $tg['value'], 'link' => array($tg['text'],$tg['value']));
					}
					return $rdata;
				break;
				case 'tcgrid':
					$timeconditions = $this->listTimeconditions();
					$timegroups = $this->listTimegroups();
					$tgs = array();
					foreach($timegroups as $tg){
						$tgs[$tg['value']] = $tg['text'];
					}
					foreach ($timeconditions as $key => $value) {
						$tgstime = isset($tgs[$value['time']])?$tgs[$value['time']]:'';
						$timeconditions[$key]['group'] = $tgstime;
					}
					return array_values($timeconditions);
				break;
				default:
					return false;
				break;
			}
			break;

			default:
				return false;
			break;
		}
	}
	public function listTimegroups(){
		$tmparray = array();
		$sql = "SELECT id, description FROM timegroups_groups ORDER BY description";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$results = $stmt->fetchall();
		if(!$results) {
			$results = array();
		}
		foreach ($results as $val) {
			$tmparray[] = array($val[0], $val[1], "value" => $val[0], "text" => $val[1]);
		}
		return $tmparray;
	}
	public function listTimeconditions($getall=false) {
		$sql = "SELECT * FROM timeconditions ORDER BY priority ASC";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();
		$results = $stmt->fetchall(\PDO::FETCH_ASSOC);
		if(is_array($results)){
			return $results;
		}
		return array();
	}
	public function getRightNav($request) {
		switch ($request['display']) {
			case 'timegroups':
				if(isset($request['view']) && $request['view'] == 'form'){
					return load_view(__DIR__."/views/timegroups/bootnav.php",array('request'=>$request));
				}
			break;
			case 'timeconditions':
				if(isset($request['view']) && $request['view'] == 'form'){
					return load_view(__DIR__."/views/timeconditions/bootnav.php",array('request'=>$request));
				}
			break;
		}
	}
	/**
	* checkTime:
	* Attempts to faithfuly replicate the logic portion of the dialplan application
	* gotoiftime. You pass a string with the same format and it returns true or false.
	* most items can be a *, single (mon), or range (mon-fri) see the gotiftime docs.
	* @time: <time range>,<days of week>,<days of month>,<months>
	* return bool
	*/
	public function checkTime($time){
	  $monthA = array(
	    'jan' => 1,
	    'feb' => 2,
	    'mar' => 3,
	    'apr' => 4,
	    'may' => 5,
	    'jun' => 6,
	    'jul' => 7,
	    'aug' => 8,
	    'sep' => 9,
	    'oct' => 10,
	    'nov' => 11,
	    'dec' => 12
	  );
	  $daysA = array(
	    'sun' => 0,
	    'mon' => 1,
	    'tue' => 2,
	    'wed' => 3,
	    'thu' => 4,
	    'fri' => 5,
	    'sat' => 6,
	  );
	  //match all don't take time to parse out anything
	  if($time == '*|*|*|*'){
	    return true;
	  }
	  $return = false;
	  list($hour, $dow, $dom, $month, $tz) = explode("|", $time);
	  if($month === '*'){
	    $return = true;
	  }else{
	    $months = explode('-',$month);
	    $range = isset($months[1]);
	    if(!$range){
	        if($monthA[$month] == date('n')){
	          $return = true;
	        }else{
	          return false;
	        }
	    }else{
	      if((date('n') >= $monthA[$months[0]]) && (date('n') <= $monthA[$months[1]])){
	        $return = true;
	      }else{
	        return false;
	      }
	    }
	  }
	  if($dom === '*'){
	    $return = true;
	  }else{
	    $daysom = explode('-',$dom);
	    $range = isset($daysom[1]);
	    if(!$range){
	        if($dom == date('j')){
	          $return = true;
	        }else{
	          return false;
	        }
	    }else{
	      if((date('j') >= $daysom[0]) && (date('j') <= $daysom[1])){
	        $return = true;
	      }else{
	        return false;
	      }
	    }
	  }
	  if($dow === '*'){
	    $return = true;
	  }else{
	    $days = explode('-',$dow);
	    $range = isset($days[1]);
	    if(!$range){
	        if($daysA[$dow] == date('w')){
	          $return = true;
	        }else{
	          return false;
	        }
	    }else{
	      if((date('w') >= $daysA[$days[0]]) && (date('w') <= $daysA[$days[1]])){
	        $return = true;
	      }else{
	        return false;
	      }
	    }
	  }
	  if($hour === '*'){
	    $return = true;
	  }else{
	    $hours = explode('-',$hour);
	    $range = isset($hours[1]);
	    if(!$range){
	        if(strtotime(date('H:i')) == strtotime($hour)){
	          $return = true;
	        }else{
	          return false;
	        }
	    }else{
	      if((strtotime(date('H:i')) >= strtotime($hours[0])) && (strtotime(date('H:i')) <= strtotime($hours[1]))){
	        $return = true;
	      }else{
	        return false;
	      }
	    }
	  }
	  return $return;
	}

	/**
	 * FreePBX chown hooks
	 */
	public function chownFreepbx() {
		$files = array();

		$files[] = array('type' => 'execdir',
			'path' => __DIR__.'/bin',
			'perms' => 0755);

		return $files;
	}

}
