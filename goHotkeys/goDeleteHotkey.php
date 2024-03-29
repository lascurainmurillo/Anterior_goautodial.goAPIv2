<?php
/**
 * @file        goDeleteHotkey.php
 * @brief       API to delete a specific hotkey/s
 * @copyright   Copyright (C) GOautodial Inc.
 * @author      Noel Umandap  <noelumandap@goautodial.com>
 * @author      Alexander Jim Abenoja  <alex@goautodial.com>
 *
 * @par <b>License</b>:
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

    include_once ("goAPI.php");
 
	### POST or GET Variables
	$campaign_id 										= $astDB->escape($_REQUEST["campaign_id"]);	
    $hotkeys 											= $astDB->escape($_REQUEST["hotkey"]);
    
	// ERROR CHECKING 
	if (empty($goUser) || is_null($goUser)) {
		$apiresults 									= array(
			"result" 										=> "Error: goAPI User Not Defined."
		);
	} elseif (empty($goPass) || is_null($goPass)) {
		$apiresults 									= array(
			"result" 										=> "Error: goAPI Password Not Defined."
		);
	} elseif (empty($log_user) || is_null($log_user)) {
		$apiresults 									= array(
			"result" 										=> "Error: Session User Not Defined."
		);
	} elseif (empty($campaign_id) || is_null($campaign_id)) {
		$apiresults 									= array(
			"result" 										=> "Error: Set a value for Campaign ID."
		);
	} elseif (empty($hotkeys) || is_null($hotkeys)) {
		$err_msg 										= error_handle("40001");
		$apiresults 									= array(
			"code" 											=> "40001", 
			"result" 										=> $err_msg
		);
		//$apiresults = array("result" => "Error: Set a value for hotkey.");
	} else {
		// check if goUser and goPass are valid
		$fresults										= $astDB
			->where("user", $goUser)
			->where("pass_hash", $goPass)
			->getOne("vicidial_users", "user,user_level");
		
		$goapiaccess									= $astDB->getRowCount();
		$userlevel										= $fresults["user_level"];
		
		if ($goapiaccess > 0 && $userlevel > 7) {	
			$cols 										= array(
				"campaign_id", 
				"hotkey"
			);
		
			$astDB->where("campaign_id", $campaign_id);
			$astDB->where("hotkey", $hotkeys);
			$checkPC									= $astDB->get("vicidial_campaign_hotkeys", null, $cols);
			
			if ($checkPC) {
				$astDB->where("campaign_id", $campaign_id);
				$astDB->where("hotkey", $hotkeys);
				$astDB->delete("vicidial_campaign_hotkeys");

				$log_id 								= log_action($goDB, "DELETE", $log_user, $log_ip, "Deleted Hotkey: $hotkeys from Campaign ID $campaign_id", $log_group, $astDB->getLastQuery());
				
				$apiresults 							= array(
					"result" 								=> "success"
				);
			} else {
				$apiresults 							= array(
					"result" 								=> "Error: Hotkey doesn't exist."
				);
			}
		} else {
			$err_msg 									= error_handle("10001");
			$apiresults 								= array(
				"code" 										=> "10001", 
				"result" 									=> $err_msg
			);		
		}
	}
	
?>
