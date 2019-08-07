<?php
namespace local_ltiprovider\task;

class clean_deleted_courses extends \core\task\scheduled_task {      
    public function get_name() {
		return get_string('task_clean_deleted_courses', 'local_ltiprovider');
    }
                                                                     
    public function execute() {
		global $DB, $CFG;
		
		//requires
		require_once($CFG->dirroot."/local/ltiprovider/lib.php");
		
		//exec
		mtrace('Deleting LTI tools assigned to deleted courses');
		if ($tools = $DB->get_records('local_ltiprovider')) {
			foreach ($tools as $tool) {
				local_ltiprovider_check_missing_course($tool);
			}
		}
		
		
    }
}