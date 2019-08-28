<?php
namespace local_ltiprovider\task;

class course_restore extends \core\task\scheduled_task {      
    public function get_name() {
		return get_string('task_course_restore', 'local_ltiprovider');
    }
                                                                     
    public function execute() {
		global $DB, $CFG;
		
		//requires
		require_once($CFG->dirroot."/local/ltiprovider/lib.php");
		require_once($CFG->dirroot."/local/ltiprovider/locallib.php");
		
		//exec
		$timenow = time();
		if ($croncourses = get_config('local_ltiprovider', 'croncourses')) {
			$croncourses = unserialize($croncourses);
			if (is_array($croncourses)) {
				mtrace('Starting restauration of pending courses');
	
				foreach ($croncourses as $key => $course) {
					mtrace('Starting restoration of ' . $key);
	
					// We limit the backups to 1 hour, then retry.
					//if ($course->restorestart and ($timenow < $course->restorestart + 3600)) {
						//mtrace('Skipping restoration in process for: ' . $key);
						//continue;
					//}
	
					$course->restorestart = time();
					$croncourses[$key] = $course;
					$croncoursessafe = serialize($croncourses);
					set_config('croncourses', $croncoursessafe, 'local_ltiprovider');
	
					if ($destinationcourse = $DB->get_record('course', array('id' => $course->destinationid))) {
						// Duplicate course + users.
						local_ltiprovider_duplicate_course($course->id, $destinationcourse, 1,
															$options = array(array('name'   => 'users',
																					'value' => 1)),
															$course->userrestoringid, $course->context);
						mtrace('Restoration for ' .$key. ' finished');
					} else {
						mtrace('Restoration for ' .$key. ' finished (destination course not exists)');
					}
	
					unset($croncourses[$key]);
					$croncoursessafe = serialize($croncourses);
					set_config('croncourses', $croncoursessafe, 'local_ltiprovider');
				}
			}
		}
		
		
    }
}