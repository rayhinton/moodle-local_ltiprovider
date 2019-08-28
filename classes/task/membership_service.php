<?php
namespace local_ltiprovider\task;

class membership_service extends \core\task\scheduled_task {      
    public function get_name() {
		return get_string('task_membership_service', 'local_ltiprovider');
    }
                                                                     
    public function execute() {
		global $DB, $CFG;
		
		//requires
		require_once($CFG->dirroot."/local/ltiprovider/lib.php");
		require_once($CFG->dirroot."/local/ltiprovider/locallib.php");
		
		//exec
		$timenow = time();
		$userphotos = array();
	
		if ($tools = $DB->get_records('local_ltiprovider', array('disabled' => 0, 'syncmembers' => 1))) {
			mtrace('Starting sync of member using the memberships service');
			$consumers = array();
	
			foreach ($tools as $tool) {
				$lastsync = get_config('local_ltiprovider', 'membershipslastsync-' . $tool->id);
				if (!$lastsync) {
					$lastsync = 0;
				}
				if ($lastsync + $tool->syncperiod < $timenow) {
					$ret = local_ltiprovider_membership_service($tool, $timenow, $userphotos, $consumers);
					$userphotos = $ret['userphotos'];
					$consumers = $ret['consumers'];
				} else {
					$last = format_time((time() - $lastsync));
					mtrace("Tool $tool->id synchronized $last ago");
				}
				mtrace('Finished sync of member using the memberships service');
			}
		}
	
		local_ltiprovider_membership_service_update_userphotos($userphotos);
		
    }
}